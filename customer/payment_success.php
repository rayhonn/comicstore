<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../includes/auth.php';
require_customer();

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/stripe_config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/mail_config.php';

$session_id = trim($_GET['session_id'] ?? '');

if ($session_id === '') {
    redirect_to(app_path('customer/cart.php'));
}

if (
    isset(
        $_SESSION['stripe_processed_session_id'],
        $_SESSION['stripe_processed_order_id']
    ) &&
    hash_equals(
        $_SESSION['stripe_processed_session_id'],
        $session_id
    )
) {
    $processed_order_id =
        (int) $_SESSION['stripe_processed_order_id'];

    redirect_to(
        app_path(
            'customer/payment_waiting.php?order_id=' .
            $processed_order_id
        )
    );
}

if (
    !isset($_SESSION['pending_order']) ||
    !isset($_SESSION['stripe_session_id']) ||
    !hash_equals(
        (string) $_SESSION['stripe_session_id'],
        $session_id
    )
) {
    redirect_to(
        app_path('customer/cart.php')
    );
}

$order = $_SESSION['pending_order'];
$user_id = current_user_id();

if (
    !is_array($order) ||
    (int) ($order['user_id'] ?? 0)
        !== $user_id
) {
    redirect_to(
        app_path('customer/cart.php')
    );
}

\Stripe\Stripe::setApiKey(
    STRIPE_SECRET_KEY
);

try {
    $stripe_session =
        \Stripe\Checkout\Session::retrieve(
            $session_id
        );

    $payment_status = (string) (
        $stripe_session->payment_status
        ?? ''
    );

    $session_status = (string) (
        $stripe_session->status
        ?? ''
    );

    $session_user_id = (string) (
        $stripe_session->client_reference_id
        ?? ''
    );

    $expected_amount = (int) round(
        (float) ($order['total'] ?? 0)
        * 100
    );

    $session_amount = (int) (
        $stripe_session->amount_total
        ?? -1
    );

    $expected_currency = strtolower(
        (string) STRIPE_CURRENCY
    );

    $session_currency = strtolower(
        (string) (
            $stripe_session->currency
            ?? ''
        )
    );

    if (
        $payment_status !== 'paid' ||
        $session_status !== 'complete' ||
        $session_user_id !==
            (string) $user_id ||
        $expected_amount <= 0 ||
        $session_amount !==
            $expected_amount ||
        $session_currency !==
            $expected_currency
    ) {
        error_log(
            'Stripe Checkout Session validation failed: ' .
            $session_id
        );

        redirect_to(
            app_path(
                'customer/payment_cancel.php'
            )
        );
    }
} catch (
    \Stripe\Exception\ApiErrorException $e
) {
    error_log(
        'Stripe session verification failed: ' .
        $e->getMessage()
    );

    redirect_to(
        app_path(
            'customer/payment_cancel.php'
        )
    );
} catch (Throwable $e) {
    error_log(
        'Stripe payment validation failed: ' .
        $e->getMessage()
    );

    redirect_to(
        app_path(
            'customer/payment_cancel.php'
        )
    );
}

date_default_timezone_set(
    'Asia/Kuala_Lumpur'
);

$token = bin2hex(random_bytes(32));
$expires_at = date(
    'Y-m-d H:i:s',
    strtotime('+5 minutes')
);

$voucher_id = $order['voucher_id'] ?? null;
$voucher_code = trim($order['voucher_code'] ?? '');

if (!$voucher_id && $voucher_code !== '') {
    $voucher_stmt = $pdo->prepare("
        SELECT voucher_id
        FROM vouchers
        WHERE voucher_code = ?
    ");
    $voucher_stmt->execute([$voucher_code]);

    $voucher_id = $voucher_stmt->fetchColumn();

    if ($voucher_id === false) {
        $voucher_id = null;
    }
}

try {
    $pdo->beginTransaction();

    $order_insert = $pdo->prepare("
        INSERT INTO orders (
            order_user_id,
            order_total_amount,
            order_has_physical,
            order_address_id,
            order_shipping_method,
            order_shipping_fee,
            order_courier,
            order_delivery_zone,
            order_payment_method,
            order_payment_status,
            order_confirm_token,
            order_confirm_expires_at,
            order_voucher_code,
            order_discount_amount
        )
        VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?,
            'pending_confirmation',
            ?, ?, ?, ?
        )
    ");

    $order_insert->execute([
        $user_id,
        $order['total'],
        !empty($order['has_physical']) ? 1 : 0,
        $order['address_id'],
        $order['shipping_method'],
        $order['shipping_fee'],
        $order['shipping_courier'] ?? null,
        $order['shipping_zone'] ?? 'peninsular',
        'Stripe - ' .
            ($stripe_session->payment_method_types[0]
                ?? 'card'),
        $token,
        $expires_at,
        $voucher_code !== '' ? $voucher_code : null,
        $order['discount_amount'] ?? 0,
    ]);

    $order_id = (int) $pdo->lastInsertId();

    $item_insert = $pdo->prepare("
        INSERT INTO order_items (
            order_item_order_id,
            order_item_product_id,
            order_item_quantity,
            order_item_price,
            order_item_type
        )
        VALUES (?, ?, ?, ?, ?)
    ");

    $reduce_stock = $pdo->prepare("
        UPDATE product_physical
        SET physical_stock_quantity =
            physical_stock_quantity - ?
        WHERE physical_product_id = ?
        AND physical_stock_quantity >= ?
    ");

    foreach ($order['items'] as $item) {
        $product_id =
            (int) $item['cart_item_product_id'];

        $quantity =
            (int) $item['cart_item_quantity'];

        $item_insert->execute([
            $order_id,
            $product_id,
            $quantity,
            $item['product_price'],
            $item['product_type'],
        ]);

        if ($item['product_type'] === 'physical') {
            $reduce_stock->execute([
                $quantity,
                $product_id,
                $quantity,
            ]);

            if ($reduce_stock->rowCount() !== 1) {
                throw new RuntimeException(
                    'Insufficient stock for one or more items.'
                );
            }
        }
    }

    if ($voucher_id) {
        $voucher_usage = $pdo->prepare("
            INSERT INTO voucher_usage (
                usage_voucher_id,
                usage_user_id,
                usage_order_id,
                usage_discount_amount
            )
            VALUES (?, ?, ?, ?)
        ");

        $voucher_usage->execute([
            $voucher_id,
            $user_id,
            $order_id,
            $order['discount_amount'] ?? 0,
        ]);

        $voucher_update = $pdo->prepare("
            UPDATE vouchers
            SET voucher_used_count =
                voucher_used_count + 1
            WHERE voucher_id = ?
        ");
        $voucher_update->execute([$voucher_id]);

        $user_voucher_update = $pdo->prepare("
            UPDATE user_vouchers
            SET uv_is_used = 0,
                uv_status = 'pending',
                uv_pending_at = NOW(),
                uv_used_at = NULL
            WHERE uv_voucher_id = ?
            AND uv_user_id = ?
            AND uv_is_used = 0
        ");

        $user_voucher_update->execute([
            $voucher_id,
            $user_id,
        ]);
    }

    $delete_cart_item = $pdo->prepare("
        DELETE FROM cart_items
        WHERE cart_item_id = ?
        AND cart_item_user_id = ?
    ");

    foreach ($order['items'] as $item) {
        $delete_cart_item->execute([
            $item['cart_item_id'],
            $user_id,
        ]);
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        'Pending order creation failed: ' .
        $e->getMessage()
    );

    http_response_code(500);
    exit('Unable to create the pending order.');
}

$_SESSION['stripe_processed_session_id'] =
    $session_id;

$_SESSION['stripe_processed_order_id'] =
    $order_id;

unset($_SESSION['pending_order']);
unset($_SESSION['payment_lock']);
unset($_SESSION['stripe_session_id']);
unset($_SESSION['stripe_checkout_url']);
unset($_SESSION['stripe_expires_at']);

$order_num =
    '#' . str_pad(
        (string) $order_id,
        4,
        '0',
        STR_PAD_LEFT
    );

$confirm_url =
    rtrim(APP_URL, '/') .
    '/customer/payment_verify.php?token=' .
    urlencode($token);

$user_stmt = $pdo->prepare("
    SELECT
        user_first_name,
        user_last_name,
        user_gmail
    FROM users
    WHERE user_id = ?
");
$user_stmt->execute([$user_id]);

$user_info =
    $user_stmt->fetch(PDO::FETCH_ASSOC);

if ($user_info) {
    $first_name = htmlspecialchars(
        $user_info['user_first_name'],
        ENT_QUOTES,
        'UTF-8'
    );

    $email_body = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
    </head>
    <body style='margin:0;padding:0;background:#F5F0EB;font-family:-apple-system,BlinkMacSystemFont,sans-serif;'>
        <div style='max-width:600px;margin:30px auto;background:white;border-radius:16px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,0.08);'>
            <div style='background:linear-gradient(135deg,#1e2d4a,#2c3e6b);padding:32px;text-align:center;'>
                <h1 style='color:white;font-size:24px;font-weight:900;margin:0 0 4px;'>
                    Manga<span style='color:#ef4444;'>Vault</span>
                </h1>
                <p style='color:rgba(255,255,255,0.6);font-size:13px;margin:0;'>
                    Confirm Your Payment
                </p>
            </div>

            <div style='padding:32px;'>
                <p style='color:#374151;font-size:15px;'>
                    Hi <strong>$first_name</strong>,
                </p>

                <p style='color:#374151;font-size:15px;line-height:1.6;'>
                    Stripe payment for order
                    <strong>$order_num</strong>
                    was successful. Please confirm the payment
                    within 5 minutes to complete your order.
                </p>

                <div style='background:#fff7ed;border:1px solid #fed7aa;border-radius:12px;padding:16px;margin:24px 0;'>
                    <p style='color:#9a3412;font-size:13px;margin:0;'>
                        This confirmation link expires in 5 minutes.
                    </p>
                </div>

                <div style='text-align:center;'>
                    <a href='$confirm_url'
                       style='display:inline-block;background:#C0392B;color:white;font-weight:700;font-size:14px;padding:12px 28px;border-radius:12px;text-decoration:none;'>
                        Confirm Payment
                    </a>
                </div>
            </div>

            <div style='background:#f9fafb;padding:20px 32px;text-align:center;border-top:1px solid #f3f4f6;'>
                <p style='color:#9ca3af;font-size:12px;margin:0;'>
                    MangaVault - Your One-Stop Manga Store
                </p>
            </div>
        </div>
    </body>
    </html>";

    try {
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
            "Confirm Payment $order_num - MangaVault";

        $mail->isHTML(true);
        $mail->Body = $email_body;

        $mail->AltBody =
            "Stripe payment for order $order_num was successful. " .
            "Confirm your payment within 5 minutes: " .
            $confirm_url;

        $mail->send();
    } catch (Exception $e) {
        error_log(
            'Payment confirmation email failed: ' .
            $e->getMessage()
        );
    }
}

try {
    sendNotification(
        $pdo,
        $user_id,
        'Payment Confirmation Required',
        "Stripe payment for order $order_num was successful. Confirm it from your email within 5 minutes.",
        'order'
    );
} catch (Throwable $e) {
    error_log(
        'Payment confirmation notification failed: ' .
        $e->getMessage()
    );
}

redirect_to(
    app_path(
        'customer/payment_waiting.php?order_id=' .
        $order_id
    )
);