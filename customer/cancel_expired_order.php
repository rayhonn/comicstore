<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'customer') {
    exit;
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once '../vendor/autoload.php';
require_once '../includes/db.php';
require_once '../includes/config.php';
require_once '../includes/notifications.php';
require_once '../includes/mail_config.php';

$user_id = $_SESSION['user_id'];

// Find expired order
$expired = $pdo->prepare("
    SELECT * FROM orders
    WHERE order_user_id = ?
    AND order_payment_status = 'pending_confirmation'
    AND order_confirm_expires_at < NOW()
    LIMIT 1
");
$expired->execute([$user_id]);
$order = $expired->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    echo json_encode(['success' => false]);
    exit;
}

// Cancel order
$pdo->prepare("UPDATE orders SET order_payment_status = 'cancelled', order_status = 'cancelled' WHERE order_id = ?")
    ->execute([$order['order_id']]);

// Restore stock
$items = $pdo->prepare("SELECT * FROM order_items WHERE order_item_order_id = ?");
$items->execute([$order['order_id']]);
foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $item) {
    if ($item['order_item_type'] === 'physical') {
        $pdo->prepare("UPDATE product_physical SET physical_stock_quantity = physical_stock_quantity + ? WHERE physical_product_id = ?")
            ->execute([$item['order_item_quantity'], $item['order_item_product_id']]);
    }
}

// Restore voucher
if (!empty($order['order_voucher_code'])) {
    $v = $pdo->prepare("SELECT voucher_id FROM vouchers WHERE voucher_code = ?");
    $v->execute([$order['order_voucher_code']]);
    $v = $v->fetch(PDO::FETCH_ASSOC);
    if ($v) {
        $pdo->prepare("DELETE FROM voucher_usage WHERE usage_order_id = ?")
            ->execute([$order['order_id']]);
        $pdo->prepare("UPDATE vouchers SET voucher_used_count = GREATEST(0, voucher_used_count - 1) WHERE voucher_code = ?")
            ->execute([$order['order_voucher_code']]);
        $pdo->prepare("UPDATE user_vouchers SET uv_is_used = 0, uv_status = 'available', uv_pending_at = NULL, uv_used_at = NULL WHERE uv_voucher_id = ? AND uv_user_id = ?")
            ->execute([$v['voucher_id'], $user_id]);
    }
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