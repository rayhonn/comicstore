<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/voucher_helper.php';
require_once __DIR__ . '/../includes/stock_helper.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/mail_config.php';

date_default_timezone_set('Asia/Kuala_Lumpur');

$token = strtolower(
    trim($_GET['token'] ?? '')
);

$error = null;
$confirmed = false;
$order_id = null;
$order = null;
$tier_upgraded = false;
$new_tier = null;

if (
    $token === '' ||
    !preg_match('/\A[a-f0-9]{64}\z/', $token)
) {
    $error = 'invalid';
} else {
    try {
        $pdo->beginTransaction();

        $order_stmt = $pdo->prepare("
            SELECT *
            FROM orders
            WHERE order_confirm_token = ?
            AND order_payment_status =
                'pending_confirmation'
            FOR UPDATE
        ");
        $order_stmt->execute([$token]);

        $order = $order_stmt->fetch(
            PDO::FETCH_ASSOC
        );

        if (!$order) {
            $pdo->rollBack();
            $error = 'invalid';
        } else {
            $order_id = (int) $order['order_id'];
            $order_user_id =
                (int) $order['order_user_id'];

            $is_expired =
                empty($order['order_confirm_expires_at']) ||
                strtotime(
                    $order['order_confirm_expires_at']
                ) < time();

            if ($is_expired) {
                $cancel_order = $pdo->prepare("
                    UPDATE orders
                    SET order_payment_status = 'cancelled',
                        order_status = 'cancelled',
                        order_confirm_token = NULL
                    WHERE order_id = ?
                    AND order_payment_status =
                        'pending_confirmation'
                    AND order_confirm_token = ?
                ");
                $cancel_order->execute([
                    $order_id,
                    $token,
                ]);

                if ($cancel_order->rowCount() !== 1) {
                    throw new RuntimeException(
                        'Order has already been processed.'
                    );
                }

                restoreOrderPhysicalStock(
                    $pdo,
                    $order_id
                );

                restoreOrderVoucherUsage(
                    $pdo,
                    $order['order_voucher_code'] ?? null,
                    $order_id,
                    $order_user_id
                );

                $pdo->commit();
                $error = 'expired';
            } else {
                $confirm_order = $pdo->prepare("
                    UPDATE orders
                    SET order_payment_status = 'confirmed',
                        order_confirm_token = NULL,
                        order_confirm_expires_at = NULL,
                        order_processing_at = NOW()
                    WHERE order_id = ?
                    AND order_payment_status =
                        'pending_confirmation'
                    AND order_confirm_token = ?
                ");
                $confirm_order->execute([
                    $order_id,
                    $token,
                ]);

                if (
                    $confirm_order->rowCount() !== 1
                ) {
                    throw new RuntimeException(
                        'Order has already been processed.'
                    );
                }

                if (
                    !empty(
                        $order['order_voucher_code']
                    )
                ) {
                    $voucher_stmt = $pdo->prepare("
                        SELECT voucher_id
                        FROM vouchers
                        WHERE voucher_code = ?
                        FOR UPDATE
                    ");
                    $voucher_stmt->execute([
                        $order[
                            'order_voucher_code'
                        ],
                    ]);

                    $voucher_id =
                        $voucher_stmt->fetchColumn();

                    if ($voucher_id !== false) {
                        $mark_voucher =
                            $pdo->prepare("
                                UPDATE user_vouchers
                                SET uv_is_used = 1,
                                    uv_status = 'used',
                                    uv_pending_at = NULL,
                                    uv_used_at = NOW()
                                WHERE uv_voucher_id = ?
                                AND uv_user_id = ?
                            ");
                        $mark_voucher->execute([
                            $voucher_id,
                            $order_user_id,
                        ]);
                    }
                }

                $ebook_stmt = $pdo->prepare("
                    SELECT DISTINCT
                        order_item_product_id
                    FROM order_items
                    WHERE order_item_order_id = ?
                    AND order_item_type = 'ebook'
                ");
                $ebook_stmt->execute([$order_id]);

                $add_collection = $pdo->prepare("
                    INSERT INTO user_collection (
                        collection_user_id,
                        collection_product_id,
                        collection_acquired_date
                    )
                    SELECT ?, ?, CURDATE()
                    WHERE NOT EXISTS (
                        SELECT 1
                        FROM user_collection
                        WHERE collection_user_id = ?
                        AND collection_product_id = ?
                    )
                ");

                foreach (
                    $ebook_stmt->fetchAll(
                        PDO::FETCH_COLUMN
                    ) as $product_id
                ) {
                    $product_id =
                        (int) $product_id;

                    $add_collection->execute([
                        $order_user_id,
                        $product_id,
                        $order_user_id,
                        $product_id,
                    ]);
                }

                $user_stmt = $pdo->prepare("
                    SELECT
                        user_tier,
                        user_lifetime_spending
                    FROM users
                    WHERE user_id = ?
                    FOR UPDATE
                ");
                $user_stmt->execute([
                    $order_user_id,
                ]);

                $user_row = $user_stmt->fetch(
                    PDO::FETCH_ASSOC
                );

                if (!$user_row) {
                    throw new RuntimeException(
                        'Order customer was not found.'
                    );
                }

                $current_tier =
                    $user_row['user_tier']
                    ?? 'bronze';

                $tier_stmt = $pdo->prepare("
                    SELECT tier_points_multiplier
                    FROM tier_config
                    WHERE tier_name = ?
                ");
                $tier_stmt->execute([
                    $current_tier,
                ]);

                $multiplier =
                    (float) (
                        $tier_stmt->fetchColumn()
                        ?: 1
                    );

                $points_earned = (int) floor(
                    (float) $order[
                        'order_total_amount'
                    ] * $multiplier
                );

                $update_user = $pdo->prepare("
                    UPDATE users
                    SET user_points =
                            user_points + ?,
                        user_lifetime_spending =
                            user_lifetime_spending + ?
                    WHERE user_id = ?
                ");
                $update_user->execute([
                    $points_earned,
                    $order[
                        'order_total_amount'
                    ],
                    $order_user_id,
                ]);

                if ($points_earned > 0) {
                    $points_log = $pdo->prepare("
                        INSERT INTO points_log (
                            log_user_id,
                            log_points,
                            log_type,
                            log_description,
                            log_order_id
                        )
                        VALUES (
                            ?,
                            ?,
                            'earn',
                            ?,
                            ?
                        )
                    ");
                    $points_log->execute([
                        $order_user_id,
                        $points_earned,
                        'Earned from Order #' .
                            str_pad(
                                (string) $order_id,
                                4,
                                '0',
                                STR_PAD_LEFT
                            ),
                        $order_id,
                    ]);
                }

                $new_spending =
                    (float) $user_row[
                        'user_lifetime_spending'
                    ] +
                    (float) $order[
                        'order_total_amount'
                    ];

                $all_tiers = $pdo->query("
                    SELECT
                        tier_name,
                        tier_min_spending
                    FROM tier_config
                    ORDER BY tier_min_spending DESC
                ")->fetchAll(PDO::FETCH_ASSOC);

                $new_tier = 'bronze';

                foreach ($all_tiers as $tier) {
                    if (
                        $new_spending >=
                        (float) $tier[
                            'tier_min_spending'
                        ]
                    ) {
                        $new_tier =
                            $tier['tier_name'];
                        break;
                    }
                }

                if ($new_tier !== $current_tier) {
                    $update_tier =
                        $pdo->prepare("
                            UPDATE users
                            SET user_tier = ?
                            WHERE user_id = ?
                        ");
                    $update_tier->execute([
                        $new_tier,
                        $order_user_id,
                    ]);

                    $tier_upgraded = true;
                }

                $pdo->commit();
                $confirmed = true;
            }
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log(
            'Payment verification failed: ' .
            $e->getMessage()
        );

        $error = 'system';
        $confirmed = false;
    }
}

if ($order && $error === 'expired') {
    $order_num =
        '#' . str_pad(
            (string) $order['order_id'],
            4,
            '0',
            STR_PAD_LEFT
        );

    try {
        sendNotification(
            $pdo,
            (int) $order['order_user_id'],
            'Payment Cancelled',
            "Order $order_num was cancelled because the confirmation link expired. Stock and vouchers were restored.",
            'order'
        );
    } catch (Throwable $e) {
        error_log(
            'Cancellation notification failed: ' .
            $e->getMessage()
        );
    }

    try {
        $user_stmt = $pdo->prepare("
            SELECT
                user_first_name,
                user_last_name,
                user_gmail
            FROM users
            WHERE user_id = ?
        ");
        $user_stmt->execute([
            $order['order_user_id'],
        ]);

        $user_info = $user_stmt->fetch(
            PDO::FETCH_ASSOC
        );

        if ($user_info) {
            $first_name = htmlspecialchars(
                $user_info['user_first_name'],
                ENT_QUOTES,
                'UTF-8'
            );

            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = MAIL_HOST;
            $mail->SMTPAuth = true;
            $mail->Username = MAIL_USERNAME;
            $mail->Password = MAIL_PASSWORD;
            $mail->SMTPSecure = 'tls';
            $mail->Port = MAIL_PORT;
            $mail->CharSet = 'UTF-8';

            $mail->setFrom(
                MAIL_USERNAME,
                MAIL_FROM_NAME
            );

            $mail->addAddress(
                $user_info['user_gmail'],
                $user_info['user_first_name'] .
                ' ' .
                $user_info['user_last_name']
            );

            $mail->Subject =
                "Order Cancelled $order_num - MangaVault";

            $mail->isHTML(true);

            $mail->Body = "
                <html>
                <body style='font-family:Arial,sans-serif;background:#F5F0EB;padding:30px;'>
                    <div style='max-width:600px;margin:auto;background:white;padding:30px;border-radius:16px;'>
                        <h2 style='color:#C0392B;'>Order Cancelled</h2>
                        <p>Hi <strong>$first_name</strong>,</p>
                        <p>
                            Your confirmation link for order
                            <strong>$order_num</strong>
                            expired after 5 minutes.
                        </p>
                        <p>
                            The order was cancelled. Physical stock
                            and any voucher used were restored.
                        </p>
                    </div>
                </body>
                </html>
            ";

            $mail->AltBody =
                "Order $order_num was cancelled because the confirmation link expired.";

            $mail->send();
        }
    } catch (Exception $e) {
        error_log(
            'Cancellation email failed: ' .
            $e->getMessage()
        );
    }
}

if ($order && $confirmed) {
    $order_user_id =
        (int) $order['order_user_id'];

    $order_num =
        '#' . str_pad(
            (string) $order_id,
            4,
            '0',
            STR_PAD_LEFT
        );

    try {
        sendNotification(
            $pdo,
            $order_user_id,
            'Payment Confirmed!',
            "Payment for order $order_num has been confirmed. The order will now be processed.",
            'order'
        );

        if ($tier_upgraded && $new_tier) {
            $tier_labels = [
                'silver' => 'Silver',
                'gold' => 'Gold',
                'platinum' => 'Platinum',
            ];

            sendNotification(
                $pdo,
                $order_user_id,
                'Tier Upgraded!',
                'Congratulations! You have been upgraded to ' .
                    (
                        $tier_labels[$new_tier]
                        ?? ucfirst($new_tier)
                    ) .
                    ' tier.',
                'order'
            );
        }
    } catch (Throwable $e) {
        error_log(
            'Confirmation notification failed: ' .
            $e->getMessage()
        );
    }

    try {
        $user_stmt = $pdo->prepare("
            SELECT
                user_first_name,
                user_last_name,
                user_gmail
            FROM users
            WHERE user_id = ?
        ");
        $user_stmt->execute([
            $order_user_id,
        ]);

        $user_info = $user_stmt->fetch(
            PDO::FETCH_ASSOC
        );

        $items_stmt = $pdo->prepare("
            SELECT
                oi.order_item_quantity,
                oi.order_item_price,
                oi.order_item_type,
                p.product_title
            FROM order_items oi
            JOIN products p
                ON p.product_id =
                    oi.order_item_product_id
            WHERE oi.order_item_order_id = ?
        ");
        $items_stmt->execute([$order_id]);

        $email_items = $items_stmt->fetchAll(
            PDO::FETCH_ASSOC
        );

        if ($user_info) {
            $first_name = htmlspecialchars(
                $user_info['user_first_name'],
                ENT_QUOTES,
                'UTF-8'
            );

            $items_html = '';

            foreach ($email_items as $item) {
                $title = htmlspecialchars(
                    $item['product_title'],
                    ENT_QUOTES,
                    'UTF-8'
                );

                $type = $item[
                    'order_item_type'
                ] === 'ebook'
                    ? 'E-Book'
                    : 'Physical';

                $quantity =
                    (int) $item[
                        'order_item_quantity'
                    ];

                $price = number_format(
                    (float) $item[
                        'order_item_price'
                    ] * $quantity,
                    2
                );

                $items_html .= "
                    <tr>
                        <td style='padding:10px;border-bottom:1px solid #eeeeee;'>$title</td>
                        <td style='padding:10px;border-bottom:1px solid #eeeeee;text-align:center;'>$type</td>
                        <td style='padding:10px;border-bottom:1px solid #eeeeee;text-align:center;'>$quantity</td>
                        <td style='padding:10px;border-bottom:1px solid #eeeeee;text-align:right;'>RM $price</td>
                    </tr>
                ";
            }

            $total = number_format(
                (float) $order[
                    'order_total_amount'
                ],
                2
            );

            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = MAIL_HOST;
            $mail->SMTPAuth = true;
            $mail->Username = MAIL_USERNAME;
            $mail->Password = MAIL_PASSWORD;
            $mail->SMTPSecure = 'tls';
            $mail->Port = MAIL_PORT;
            $mail->CharSet = 'UTF-8';

            $mail->setFrom(
                MAIL_USERNAME,
                MAIL_FROM_NAME
            );

            $mail->addAddress(
                $user_info['user_gmail'],
                $user_info['user_first_name'] .
                ' ' .
                $user_info['user_last_name']
            );

            $mail->Subject =
                "Order Confirmed $order_num - MangaVault";

            $mail->isHTML(true);

            $mail->Body = "
                <html>
                <body style='font-family:Arial,sans-serif;background:#F5F0EB;padding:30px;'>
                    <div style='max-width:600px;margin:auto;background:white;padding:30px;border-radius:16px;'>
                        <h2 style='color:#166534;'>Payment Confirmed</h2>
                        <p>
                            Hi <strong>$first_name</strong>,
                            your order
                            <strong>$order_num</strong>
                            has been confirmed.
                        </p>

                        <table style='width:100%;border-collapse:collapse;margin-top:20px;'>
                            <thead>
                                <tr>
                                    <th style='padding:10px;text-align:left;'>Item</th>
                                    <th style='padding:10px;text-align:center;'>Type</th>
                                    <th style='padding:10px;text-align:center;'>Qty</th>
                                    <th style='padding:10px;text-align:right;'>Price</th>
                                </tr>
                            </thead>
                            <tbody>
                                $items_html
                            </tbody>
                        </table>

                        <p style='font-size:18px;font-weight:bold;text-align:right;'>
                            Total: RM $total
                        </p>
                    </div>
                </body>
                </html>
            ";

            $mail->AltBody =
                "Order $order_num has been confirmed. Total: RM $total.";

            $mail->send();
        }
    } catch (Exception $e) {
        error_log(
            'Confirmation email failed: ' .
            $e->getMessage()
        );
    }

    if (
        isset($_SESSION['user_id']) &&
        (int) $_SESSION['user_id']
            === $order_user_id
    ) {
        unset($_SESSION['payment_lock']);
        unset(
            $_SESSION[
                'stripe_processed_session_id'
            ]
        );
        unset(
            $_SESSION[
                'stripe_processed_order_id'
            ]
        );
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >
    <title>Payment Verification - MangaVault</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body {
            opacity: 0;
            animation: fadeIn 0.4s ease forwards;
        }

        @keyframes fadeIn {
            to {
                opacity: 1;
            }
        }
    </style>
</head>
<body
    class="bg-[#F5F0EB] min-h-screen flex items-center justify-center px-6"
>
    <div class="max-w-md w-full">
        <?php if ($error === 'expired'): ?>
            <div
                class="bg-white rounded-2xl shadow-sm p-8 text-center"
            >
                <div
                    class="w-16 h-16 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-4"
                >
                    <span class="text-3xl">⏰</span>
                </div>

                <h2
                    class="text-xl font-black text-gray-800 mb-2"
                >
                    Confirmation Expired
                </h2>

                <p class="text-gray-500 text-sm mb-2">
                    Your payment confirmation link has expired.
                </p>

                <p class="text-gray-400 text-xs mb-6">
                    The order was cancelled and its stock and
                    voucher were restored.
                </p>

                <a
                    href="<?= htmlspecialchars(app_path('customer/home.php')) ?>"
                    class="block w-full bg-red-600 hover:bg-red-700 text-white font-semibold py-3 rounded-xl text-sm"
                >
                    Back to Shop
                </a>
            </div>

        <?php elseif ($error === 'invalid'): ?>
            <div
                class="bg-white rounded-2xl shadow-sm p-8 text-center"
            >
                <div
                    class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4"
                >
                    <span class="text-3xl">❌</span>
                </div>

                <h2
                    class="text-xl font-black text-gray-800 mb-2"
                >
                    Invalid Link
                </h2>

                <p class="text-gray-500 text-sm mb-6">
                    This confirmation link is invalid or has
                    already been used.
                </p>

                <a
                    href="<?= htmlspecialchars(app_path('customer/orders.php')) ?>"
                    class="block w-full bg-red-600 hover:bg-red-700 text-white font-semibold py-3 rounded-xl text-sm"
                >
                    View My Orders
                </a>
            </div>

        <?php elseif ($error === 'system'): ?>
            <div
                class="bg-white rounded-2xl shadow-sm p-8 text-center"
            >
                <div
                    class="w-16 h-16 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-4"
                >
                    <span class="text-3xl">⚠️</span>
                </div>

                <h2
                    class="text-xl font-black text-gray-800 mb-2"
                >
                    Verification Failed
                </h2>

                <p class="text-gray-500 text-sm mb-6">
                    The payment could not be verified. Please
                    check your order again.
                </p>

                <a
                    href="<?= htmlspecialchars(app_path('customer/orders.php')) ?>"
                    class="block w-full bg-red-600 hover:bg-red-700 text-white font-semibold py-3 rounded-xl text-sm"
                >
                    View My Orders
                </a>
            </div>

        <?php else: ?>
            <div
                class="bg-white rounded-2xl shadow-sm p-8 text-center"
            >
                <div
                    class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4"
                >
                    <svg
                        class="w-8 h-8 text-green-600"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M5 13l4 4L19 7"
                        ></path>
                    </svg>
                </div>

                <h2
                    class="text-xl font-black text-gray-800 mb-2"
                >
                    Payment Confirmed!
                </h2>

                <p class="text-gray-500 text-sm mb-6">
                    Your payment has been verified. Redirecting
                    to your order...
                </p>

                <div
                    class="flex items-center justify-center gap-2"
                >
                    <span
                        class="w-2 h-2 bg-red-600 rounded-full animate-bounce"
                    ></span>
                    <span
                        class="w-2 h-2 bg-red-600 rounded-full animate-bounce"
                        style="animation-delay:0.15s"
                    ></span>
                    <span
                        class="w-2 h-2 bg-red-600 rounded-full animate-bounce"
                        style="animation-delay:0.3s"
                    ></span>
                </div>
            </div>

            <script>
                setTimeout(() => {
                    window.location.href =
                        <?= json_encode(
                            app_path(
                                'customer/order_success.php?order_id=' .
                                $order_id
                            )
                        ) ?>;
                }, 2000);
            </script>
        <?php endif; ?>
    </div>
</body>
</html>