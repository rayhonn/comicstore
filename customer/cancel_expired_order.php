<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../includes/auth.php';
require_customer();

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/voucher_helper.php';
require_once __DIR__ . '/../includes/stock_helper.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/mail_config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);

    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed.',
    ]);
    exit;
}

csrf_verify();

$user_id = current_user_id();

$order_id = filter_input(
    INPUT_POST,
    'order_id',
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if (!$order_id) {
    http_response_code(422);

    echo json_encode([
        'success' => false,
        'message' => 'Invalid order.',
    ]);
    exit;
}

// Cancel expired order, restore stock and restore voucher atomically
try {
    $pdo->beginTransaction();

    $expired = $pdo->prepare("
        SELECT *
        FROM orders
        WHERE order_id = ?
        AND order_user_id = ?
        AND order_payment_status = 'pending_confirmation'
        AND order_confirm_expires_at < NOW()
        FOR UPDATE
    ");
    $expired->execute([
        $order_id,
        $user_id,
    ]);

    $order = $expired->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        throw new RuntimeException(
            'Order is not eligible for cancellation.'
        );
    }

    // Cancel the order only if it is still pending confirmation
    $cancel = $pdo->prepare("
        UPDATE orders
        SET order_payment_status = 'cancelled',
            order_status = 'cancelled'
        WHERE order_id = ?
        AND order_user_id = ?
        AND order_payment_status = 'pending_confirmation'
    ");
    $cancel->execute([
        $order_id,
        $user_id,
    ]);

    if ($cancel->rowCount() !== 1) {
        throw new RuntimeException(
            'Order has already been processed.'
        );
    }

    // Restore physical stock
    restoreOrderPhysicalStock(
        $pdo,
        $order_id
    );

    // Restore voucher usage and customer voucher
    restoreOrderVoucherUsage(
        $pdo,
        $order['order_voucher_code'] ?? null,
        $order_id,
        $user_id
    );

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    if ($e instanceof RuntimeException) {
        http_response_code(409);

        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
        ]);
    } else {
        error_log(
            'Expired order cancellation failed: ' .
            $e->getMessage()
        );

        http_response_code(500);

        echo json_encode([
            'success' => false,
            'message' => 'Unable to cancel the order.',
        ]);
    }

    exit;
}

// Send notification
$order_num = '#' . str_pad($order['order_id'], 4, '0', STR_PAD_LEFT);
sendNotification($pdo, $user_id,
    '⏰ Payment Timeout',
    "Your order $order_num has been cancelled due to payment timeout. Stock and vouchers have been restored.",
    'order'
);

// Get user info for email
$user_info = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
$user_info->execute([$user_id]);
$user_info = $user_info->fetch(PDO::FETCH_ASSOC);

$first_name = htmlspecialchars($user_info['user_first_name']);

$email_body = "
<!DOCTYPE html>
<html><head><meta charset='UTF-8'></head>
<body style='margin:0; padding:0; background:#F5F0EB; font-family:-apple-system, BlinkMacSystemFont, sans-serif;'>
    <div style='max-width:600px; margin:30px auto; background:white; border-radius:16px; overflow:hidden; box-shadow:0 4px 20px rgba(0,0,0,0.08);'>
        <div style='background:linear-gradient(135deg, #1e2d4a, #2c3e6b); padding:32px; text-align:center;'>
            <h1 style='color:white; font-size:24px; font-weight:900; margin:0 0 4px 0;'>Manga<span style='color:#ef4444;'>Vault</span></h1>
            <p style='color:rgba(255,255,255,0.6); font-size:13px; margin:0;'>Payment Timeout</p>
        </div>
        <div style='padding:32px;'>
            <table style='background:#fef2f2; border:1px solid #fecaca; border-radius:12px; width:100%; margin-bottom:24px;' cellpadding='0' cellspacing='0'>
                <tr>
                    <td style='padding:16px 8px 16px 16px; width:40px; vertical-align:middle; font-size:24px;'>⏰</td>
                    <td style='padding:16px 16px 16px 4px; vertical-align:middle;'>
                        <p style='font-weight:700; color:#991b1b; margin:0 0 4px 0; font-size:15px;'>Payment Timeout</p>
                        <p style='color:#dc2626; font-size:13px; margin:0;'>Your order has been cancelled due to payment timeout.</p>
                    </td>
                </tr>
            </table>
            <p style='color:#374151; font-size:15px; margin:0 0 24px 0;'>Hi <strong>$first_name</strong>, your order <strong>$order_num</strong> has been cancelled because payment was not completed within 5 minutes.</p>
            <div style='background:#f9fafb; border-radius:12px; padding:16px; margin-bottom:24px;'>
                <p style='color:#6b7280; font-size:13px; margin:0 0 8px 0;'>✅ Stock has been restored</p>
                <p style='color:#6b7280; font-size:13px; margin:0 0 8px 0;'>✅ Your voucher has been restored (if any)</p>
                <p style='color:#6b7280; font-size:13px; margin:0;'>✅ You can place a new order now</p>
            </div>
            <div style='text-align:center;'>
                <a href='" . APP_URL . "/customer/home.php'
                   style='display:inline-block; background:#C0392B; color:white; font-weight:700; font-size:14px; padding:12px 28px; border-radius:12px; text-decoration:none;'>
                    Continue Shopping
                </a>
            </div>
        </div>
        <div style='background:#f9fafb; padding:20px 32px; text-align:center; border-top:1px solid #f3f4f6;'>
            <p style='color:#9ca3af; font-size:12px; margin:0;'>MangaVault — Your One-Stop Manga Store</p>
        </div>
    </div>
</body></html>";

try {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = MAIL_HOST;
    $mail->SMTPAuth = true;
    $mail->Username = MAIL_USERNAME;
    $mail->Password = MAIL_PASSWORD;
    $mail->SMTPSecure = 'tls';
    $mail->Port = MAIL_PORT;
    $mail->setFrom(MAIL_USERNAME, MAIL_FROM_NAME);
    $mail->CharSet = 'UTF-8';
    $mail->addAddress($user_info['user_gmail'], $user_info['user_first_name'] . ' ' . $user_info['user_last_name']);
    $mail->Subject = "Order Cancelled $order_num - MangaVault";
    $mail->isHTML(true);
    $mail->Body = $email_body;
    $mail->send();
} catch (Exception $e) {
    error_log("Mail error: " . $e->getMessage());
}

// Clear payment lock
unset($_SESSION['payment_lock']);

echo json_encode(['success' => true]);