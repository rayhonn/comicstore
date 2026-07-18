<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require_once '../vendor/autoload.php';
session_start();
require_once '../includes/db.php';
require_once '../includes/config.php';

$token = $_GET['token'] ?? '';

if (empty($token)) {
    header('Location: ../index.php');
    exit;
}

// Find order by token
$order = $pdo->prepare("
    SELECT * FROM orders 
    WHERE order_confirm_token = ? 
    AND order_payment_status = 'pending_confirmation'
");
$order->execute([$token]);
$order = $order->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    // Token invalid or already used
    $error = 'invalid';
} elseif (new DateTime() > new DateTime($order['order_confirm_expires_at'])) {
    // Expired - cancel order and restore stock
    $pdo->prepare("UPDATE orders SET order_payment_status = 'cancelled', order_status = 'cancelled' WHERE order_id = ?")
        ->execute([$order['order_id']]);

    // Restore voucher if used
    if (!empty($order['order_voucher_code'])) {
        $pdo->prepare("DELETE FROM voucher_usage WHERE usage_order_id = ?")
            ->execute([$order['order_id']]);
        $pdo->prepare("UPDATE vouchers SET voucher_used_count = GREATEST(0, voucher_used_count - 1) WHERE voucher_code = ?")
            ->execute([$order['order_voucher_code']]);
        // Restore user_vouchers status back to available
        $v = $pdo->prepare("SELECT voucher_id FROM vouchers WHERE voucher_code = ?");
        $v->execute([$order['order_voucher_code']]);
        $v = $v->fetch(PDO::FETCH_ASSOC);
        if ($v) {
            $pdo->prepare("UPDATE user_vouchers SET uv_is_used = 0, uv_status = 'available', uv_used_at = NULL WHERE uv_voucher_id = ? AND uv_user_id = ?")
                ->execute([$v['voucher_id'], $order['order_user_id']]);
        }
    }

    // Restore stock
    $items = $pdo->prepare("SELECT * FROM order_items WHERE order_item_order_id = ?");
    $items->execute([$order['order_id']]);
    $items = $items->fetchAll(PDO::FETCH_ASSOC);
    foreach ($items as $item) {
        if ($item['order_item_type'] === 'physical') {
            $pdo->prepare("UPDATE product_physical SET physical_stock_quantity = physical_stock_quantity + ? WHERE physical_product_id = ?")
                ->execute([$item['order_item_quantity'], $item['order_item_product_id']]);
        }
    }

    // Send notification + email for cancelled payment
    require_once '../includes/notifications.php';
    sendNotification($pdo, $order['order_user_id'],
        '❌ Payment Cancelled',
        'Your order #' . str_pad($order['order_id'], 4, '0', STR_PAD_LEFT) . ' has been cancelled due to payment timeout. Any voucher used has been restored.',
        'order'
    );

    // Send cancellation email
    require_once '../includes/mail_config.php';
    $user_info = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
    $user_info->execute([$order['order_user_id']]);
    $user_info = $user_info->fetch(PDO::FETCH_ASSOC);

    $order_num = '#' . str_pad($order['order_id'], 4, '0', STR_PAD_LEFT);
    $first_name = htmlspecialchars($user_info['user_first_name']);

    $cancel_email_body = "
    <!DOCTYPE html>
    <html>
    <head><meta charset='UTF-8'></head>
    <body style='margin:0; padding:0; background:#F5F0EB; font-family:-apple-system, BlinkMacSystemFont, sans-serif;'>
        <div style='max-width:600px; margin:30px auto; background:white; border-radius:16px; overflow:hidden; box-shadow:0 4px 20px rgba(0,0,0,0.08);'>
            <div style='background:linear-gradient(135deg, #1e2d4a, #2c3e6b); padding:32px; text-align:center;'>
                <h1 style='color:white; font-size:24px; font-weight:900; margin:0 0 4px 0;'>Manga<span style='color:#ef4444;'>Vault</span></h1>
                <p style='color:rgba(255,255,255,0.6); font-size:13px; margin:0;'>Order Cancelled</p>
            </div>
            <div style='padding:32px;'>
                <table style='background:#fef2f2; border:1px solid #fecaca; border-radius:12px; width:100%; margin-bottom:24px;' cellpadding='0' cellspacing='0'>
                    <tr>
                        <td style='padding:16px 8px 16px 16px; width:40px; vertical-align:middle; font-size:24px;'>❌</td>
                        <td style='padding:16px 16px 16px 4px; vertical-align:middle;'>
                            <p style='font-weight:700; color:#991b1b; margin:0 0 4px 0; font-size:15px;'>Order Cancelled</p>
                            <p style='color:#dc2626; font-size:13px; margin:0;'>Your payment confirmation has expired (5 minutes).</p>
                        </td>
                    </tr>
                </table>
                <p style='color:#374151; font-size:15px; margin:0 0 24px 0;'>Hi <strong>$first_name</strong>, your order <strong>$order_num</strong> has been cancelled because the payment was not confirmed within 5 minutes.</p>
                <div style='background:#f9fafb; border-radius:12px; padding:16px; margin-bottom:24px;'>
                    <p style='color:#6b7280; font-size:13px; margin:0 0 8px 0;'>✅ Stock has been restored</p>
                    <p style='color:#6b7280; font-size:13px; margin:0;'>✅ Any voucher used has been restored to your account</p>
                </div>
                <p style='color:#6b7280; font-size:13px; margin:0 0 24px 0;'>You can place a new order anytime. We hope to see you again!</p>
                <div style='text-align:center;'>
                    <a href='" . APP_URL . "/customer/home.php'
                        style='display:inline-block; background:#C0392B; color:white; font-weight:700; font-size:14px; padding:12px 28px; border-radius:12px; text-decoration:none;'>
                        Continue Shopping
                    </a>
                </div>
            </div>
            <div style='background:#f9fafb; padding:20px 32px; text-align:center; border-top:1px solid #f3f4f6;'>
                <p style='color:#9ca3af; font-size:12px; margin:0 0 4px 0;'>MangaVault — Your One-Stop Manga Store</p>
                <p style='color:#d1d5db; font-size:11px; margin:0;'>This is an automated email. Please do not reply.</p>
            </div>
        </div>
    </body>
    </html>";

try {
    $cancel_mail = new PHPMailer(true);
    $cancel_mail->isSMTP();
    $cancel_mail->Host = MAIL_HOST;
    $cancel_mail->SMTPAuth = true;
    $cancel_mail->Username = MAIL_USERNAME;
    $cancel_mail->Password = MAIL_PASSWORD;
    $cancel_mail->SMTPSecure = 'tls';
    $cancel_mail->Port = MAIL_PORT;
    $cancel_mail->setFrom(MAIL_USERNAME, MAIL_FROM_NAME);
    $cancel_mail->CharSet = 'UTF-8';
    $cancel_mail->addAddress($user_info['user_gmail'], $user_info['user_first_name'] . ' ' . $user_info['user_last_name']);
    $cancel_mail->Subject = "Order Cancelled $order_num - MangaVault";
    $cancel_mail->isHTML(true);
    $cancel_mail->Body = $cancel_email_body;
    $cancel_mail->AltBody = "Hi $first_name! Your order $order_num has been cancelled due to payment timeout. Stock and vouchers have been restored.";
    $cancel_mail->send();
    } catch (Exception $e) {
        // Silent fail
    }

    $error = 'expired';
} else {
    // Valid - confirm the order
    $pdo->prepare("UPDATE orders SET order_payment_status = 'confirmed', order_confirm_token = NULL WHERE order_id = ?")
        ->execute([$order['order_id']]);

    // Mark user voucher as used
    if (!empty($order['order_voucher_code'])) {
        $v = $pdo->prepare("SELECT voucher_id FROM vouchers WHERE voucher_code = ?");
        $v->execute([$order['order_voucher_code']]);
        $v = $v->fetch(PDO::FETCH_ASSOC);
        if ($v) {
            $existing = $pdo->prepare("SELECT uv_id FROM user_vouchers WHERE uv_voucher_id = ? AND uv_user_id = ?");
            $existing->execute([$v['voucher_id'], $order['order_user_id']]);
            if ($existing->fetch()) {
                $pdo->prepare("UPDATE user_vouchers SET uv_is_used = 1, uv_status = 'used', uv_used_at = NOW() WHERE uv_voucher_id = ? AND uv_user_id = ?")
                    ->execute([$v['voucher_id'], $order['order_user_id']]);
            } else {
                $pdo->prepare("INSERT INTO user_vouchers (uv_user_id, uv_voucher_id, uv_is_used, uv_status, uv_used_at) VALUES (?, ?, 1, 'used', NOW())")
                    ->execute([$order['order_user_id'], $v['voucher_id']]);
            }
        }
    }

    // Get current tier & multiplier from database
    $user_tier_data = $pdo->prepare("SELECT user_tier, user_lifetime_spending FROM users WHERE user_id = ?");
    $user_tier_data->execute([$order['order_user_id']]);
    $user_tier_row = $user_tier_data->fetch(PDO::FETCH_ASSOC);
    $current_tier = $user_tier_row['user_tier'] ?? 'bronze';

    $tier_cfg = $pdo->prepare("SELECT tier_points_multiplier FROM tier_config WHERE tier_name = ?");
    $tier_cfg->execute([$current_tier]);
    $multiplier = $tier_cfg->fetchColumn() ?? 1;

    // Award points with tier multiplier
    $points_earned = floor($order['order_total_amount'] * $multiplier);
    $pdo->prepare("UPDATE users SET user_points = user_points + ? WHERE user_id = ?")
        ->execute([$points_earned, $order['order_user_id']]);
    $pdo->prepare("INSERT INTO points_log (log_user_id, log_points, log_type, log_description, log_order_id) VALUES (?, ?, 'earn', ?, ?)")
        ->execute([$order['order_user_id'], $points_earned, "Earned from Order #" . str_pad($order['order_id'], 4, '0', STR_PAD_LEFT), $order['order_id']]);

    // Update lifetime spending
    $pdo->prepare("UPDATE users SET user_lifetime_spending = user_lifetime_spending + ? WHERE user_id = ?")
        ->execute([$order['order_total_amount'], $order['order_user_id']]);

    // Get new lifetime spending
    $new_spending = $pdo->prepare("SELECT user_lifetime_spending FROM users WHERE user_id = ?");
    $new_spending->execute([$order['order_user_id']]);
    $new_spending = $new_spending->fetchColumn();

    // Get tier thresholds from database
    $all_tiers = $pdo->query("SELECT tier_name, tier_min_spending FROM tier_config ORDER BY tier_min_spending DESC")->fetchAll(PDO::FETCH_ASSOC);

    $new_tier = 'bronze';
    foreach ($all_tiers as $t) {
        if ($new_spending >= $t['tier_min_spending']) {
            $new_tier = $t['tier_name'];
            break;
        }
    }

    // Upgrade tier if changed
    if ($new_tier !== $current_tier) {
        $pdo->prepare("UPDATE users SET user_tier = ? WHERE user_id = ?")
            ->execute([$new_tier, $order['order_user_id']]);

        $tier_labels = ['silver' => 'Silver 🥈', 'gold' => 'Gold 🥇', 'platinum' => 'Platinum 💎'];
        $tier_label = $tier_labels[$new_tier] ?? $new_tier;

        sendNotification($pdo, $order['order_user_id'],
            'Tier Upgraded! 🎉',
            'Congratulations! You\'ve been upgraded to ' . $tier_label . ' tier. Enjoy your new benefits!',
            'order'
        );
    }
    require_once '../includes/notifications.php';
    sendNotification($pdo, $order['order_user_id'],
            'Payment Confirmed! 🎉', 
            'Your payment for order #' . str_pad($order['order_id'], 4, '0', STR_PAD_LEFT) . ' has been confirmed. We are now processing your order.',
            'order'
    );
    unset($_SESSION['payment_lock']);
    $confirmed = true;
    $order_id = $order['order_id'];

    // Send order confirmed email
    require_once '../includes/mail_config.php';

    $user_info = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
    $user_info->execute([$order['order_user_id']]);
    $user_info = $user_info->fetch(PDO::FETCH_ASSOC);

    $email_items = $pdo->prepare("
        SELECT oi.*, p.product_title, p.product_type
        FROM order_items oi
        JOIN products p ON oi.order_item_product_id = p.product_id
        WHERE oi.order_item_order_id = ?
    ");
    $email_items->execute([$order_id]);
    $email_items = $email_items->fetchAll(PDO::FETCH_ASSOC);

    $order_num = '#' . str_pad($order_id, 4, '0', STR_PAD_LEFT);
    $items_html = '';
    foreach ($email_items as $ei) {
        $type_badge = $ei['product_type'] === 'ebook' ? '📱 E-Book' : '📦 Physical';
        $items_html .= "
        <tr>
            <td style='padding:10px 12px; border-bottom:1px solid #f3f4f6; font-size:13px; color:#374151;'>{$ei['product_title']}</td>
            <td style='padding:10px 12px; border-bottom:1px solid #f3f4f6; font-size:13px; color:#6b7280; text-align:center;'>$type_badge</td>
            <td style='padding:10px 12px; border-bottom:1px solid #f3f4f6; font-size:13px; color:#6b7280; text-align:center;'>{$ei['order_item_quantity']}</td>
            <td style='padding:10px 12px; border-bottom:1px solid #f3f4f6; font-size:13px; color:#374151; text-align:right;'>RM " . number_format($ei['order_item_price'] * $ei['order_item_quantity'], 2) . "</td>
        </tr>";
    }

    date_default_timezone_set('Asia/Kuala_Lumpur');
    $shipping_fee = number_format($order['order_shipping_fee'], 2);
    $subtotal = number_format($order['order_total_amount'] - $order['order_shipping_fee'], 2);
    $total_fmt = number_format($order['order_total_amount'], 2);
    $order_date = date('d F Y, h:i A');
    $first_name = htmlspecialchars($user_info['user_first_name']);

    $email_body = "
    <!DOCTYPE html>
    <html>
    <head><meta charset='UTF-8'></head>
    <body style='margin:0; padding:0; background:#F5F0EB; font-family:-apple-system, BlinkMacSystemFont, sans-serif;'>
        <div style='max-width:600px; margin:30px auto; background:white; border-radius:16px; overflow:hidden; box-shadow:0 4px 20px rgba(0,0,0,0.08);'>
            <div style='background:linear-gradient(135deg, #1e2d4a, #2c3e6b); padding:32px; text-align:center;'>
                <h1 style='color:white; font-size:24px; font-weight:900; margin:0 0 4px 0;'>Manga<span style='color:#ef4444;'>Vault</span></h1>
                <p style='color:rgba(255,255,255,0.6); font-size:13px; margin:0;'>Order Confirmed</p>
            </div>
            <div style='padding:32px;'>
                <table style='background:#f0fdf4; border:1px solid #bbf7d0; border-radius:12px; width:100%; margin-bottom:24px;' cellpadding='0' cellspacing='0'>
                    <tr>
                        <td style='padding:16px 8px 16px 16px; width:40px; vertical-align:middle; font-size:24px;'>✅</td>
                        <td style='padding:16px 16px 16px 4px; vertical-align:middle;'>
                            <p style='font-weight:700; color:#166534; margin:0 0 4px 0; font-size:15px;'>Payment Successful!</p>
                            <p style='color:#16a34a; font-size:13px; margin:0;'>Your order has been confirmed and is being processed.</p>
                        </td>
                    </tr>
                </table>
                <p style='color:#374151; font-size:15px; margin:0 0 24px 0;'>Hi <strong>$first_name</strong>, thank you! Here's your order summary:</p>
                <table style='background:#f9fafb; border-radius:12px; width:100%; margin-bottom:24px;' cellpadding='0' cellspacing='0'>
                    <tr><td style='padding:12px 16px; border-bottom:1px solid #f3f4f6;'>
                        <table width='100%' cellpadding='0' cellspacing='0'><tr>
                            <td style='color:#6b7280; font-size:13px;'>Order Number</td>
                            <td style='font-weight:700; color:#111827; font-size:13px; text-align:right;'>$order_num</td>
                        </tr></table>
                    </td></tr>
                    <tr><td style='padding:12px 16px;'>
                        <table width='100%' cellpadding='0' cellspacing='0'><tr>
                            <td style='color:#6b7280; font-size:13px;'>Order Date</td>
                            <td style='font-weight:600; color:#111827; font-size:13px; text-align:right;'>$order_date</td>
                        </tr></table>
                    </td></tr>
                </table>
                <h3 style='font-size:14px; font-weight:700; color:#111827; margin:0 0 12px 0; text-transform:uppercase; letter-spacing:0.05em;'>Order Items</h3>
                <table style='width:100%; border-collapse:collapse; margin-bottom:24px;'>
                    <thead><tr style='background:#f9fafb;'>
                        <th style='padding:10px 12px; text-align:left; font-size:12px; color:#6b7280; font-weight:600; text-transform:uppercase;'>Item</th>
                        <th style='padding:10px 12px; text-align:center; font-size:12px; color:#6b7280; font-weight:600; text-transform:uppercase;'>Type</th>
                        <th style='padding:10px 12px; text-align:center; font-size:12px; color:#6b7280; font-weight:600; text-transform:uppercase;'>Qty</th>
                        <th style='padding:10px 12px; text-align:right; font-size:12px; color:#6b7280; font-weight:600; text-transform:uppercase;'>Price</th>
                    </tr></thead>
                    <tbody>$items_html</tbody>
                </table>
                <table style='border-top:2px solid #f3f4f6; width:100%; margin-bottom:24px;' cellpadding='0' cellspacing='0'>
                    <tr><td style='padding:12px 0 4px;'>
                        <table width='100%' cellpadding='0' cellspacing='0'><tr>
                            <td style='color:#6b7280; font-size:13px;'>Subtotal</td>
                            <td style='color:#374151; font-size:13px; text-align:right;'>RM $subtotal</td>
                        </tr></table>
                    </td></tr>
                    <tr><td style='padding:4px 0 12px;'>
                        <table width='100%' cellpadding='0' cellspacing='0'><tr>
                            <td style='color:#6b7280; font-size:13px;'>Shipping</td>
                            <td style='color:#374151; font-size:13px; text-align:right;'>RM $shipping_fee</td>
                        </tr></table>
                    </td></tr>
                    <tr><td style='background:#fef2f2; padding:12px; border-radius:8px;'>
                        <table width='100%' cellpadding='0' cellspacing='0'><tr>
                            <td style='font-weight:900; color:#111827; font-size:15px;'>Total Paid</td>
                            <td style='font-weight:900; color:#C0392B; font-size:15px; text-align:right;'>RM $total_fmt</td>
                        </tr></table>
                    </td></tr>
                </table>
                <div style='text-align:center;'>
                    <a href='" . APP_URL . "/customer/orders.php'
                       style='display:inline-block; background:#C0392B; color:white; font-weight:700; font-size:14px; padding:12px 28px; border-radius:12px; text-decoration:none;'>
                        View My Orders
                    </a>
                </div>
            </div>
            <div style='background:#f9fafb; padding:20px 32px; text-align:center; border-top:1px solid #f3f4f6;'>
                <p style='color:#9ca3af; font-size:12px; margin:0 0 4px 0;'>MangaVault — Your One-Stop Manga Store</p>
                <p style='color:#d1d5db; font-size:11px; margin:0;'>This is an automated email. Please do not reply.</p>
            </div>
        </div>
    </body>
    </html>";

    try {
        $confirm_mail = new PHPMailer(true);
        $confirm_mail->isSMTP();
        $confirm_mail->Host = MAIL_HOST;
        $confirm_mail->SMTPAuth = true;
        $confirm_mail->Username = MAIL_USERNAME;
        $confirm_mail->Password = MAIL_PASSWORD;
        $confirm_mail->SMTPSecure = 'tls';
        $confirm_mail->Port = MAIL_PORT;
        $confirm_mail->setFrom(MAIL_USERNAME, MAIL_FROM_NAME);
        $confirm_mail->CharSet = 'UTF-8';
        $confirm_mail->addAddress($user_info['user_gmail'], $user_info['user_first_name'] . ' ' . $user_info['user_last_name']);
        $confirm_mail->Subject = "Order Confirmed $order_num - MangaVault 🎉";
        $confirm_mail->isHTML(true);
        $confirm_mail->Body = $email_body;
        $confirm_mail->AltBody = "Hi $first_name! Your order $order_num (RM $total_fmt) has been confirmed. View orders: " . APP_URL . "/customer/orders.php";
        $confirm_mail->send();
    } catch (Exception $e) {
        // Silent fail
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Verification - MangaVault</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { opacity: 0; animation: fadeIn 0.4s ease forwards; }
        @keyframes fadeIn { to { opacity: 1; } }
    </style>
</head>
<body class="bg-[#F5F0EB] min-h-screen flex items-center justify-center px-6">
    <div class="max-w-md w-full">

        <?php if (isset($error) && $error === 'expired'): ?>
        <!-- Expired -->
        <div class="bg-white rounded-2xl shadow-sm p-8 text-center">
            <div class="w-16 h-16 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-4">
                <span class="text-3xl">⏰</span>
            </div>
            <h2 class="text-xl font-black text-gray-800 mb-2">Confirmation Expired</h2>
            <p class="text-gray-500 text-sm mb-2">Your payment confirmation link has expired (5 minutes).</p>
            <p class="text-gray-400 text-xs mb-6">Your order has been cancelled and stock has been restored.</p>
            <a href="home.php" class="block w-full bg-red-600 hover:bg-red-700 text-white font-semibold py-3 rounded-xl text-sm transition-colors text-center">
                Back to Shop
            </a>
        </div>

        <?php elseif (isset($error) && $error === 'invalid'): ?>
        <!-- Invalid -->
        <div class="bg-white rounded-2xl shadow-sm p-8 text-center">
            <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
                <span class="text-3xl">❌</span>
            </div>
            <h2 class="text-xl font-black text-gray-800 mb-2">Invalid Link</h2>
            <p class="text-gray-500 text-sm mb-6">This confirmation link is invalid or has already been used.</p>
            <a href="orders.php" class="block w-full bg-red-600 hover:bg-red-700 text-white font-semibold py-3 rounded-xl text-sm transition-colors text-center">
                View My Orders
            </a>
        </div>

        <?php else: ?>
        <!-- Success - show processing -->
        <div class="bg-white rounded-2xl shadow-sm p-8 text-center">
            <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
                <svg class="w-8 h-8 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                </svg>
            </div>
            <h2 class="text-xl font-black text-gray-800 mb-2">Payment Confirmed! 🎉</h2>
            <p class="text-gray-500 text-sm mb-6">Your payment has been verified. Redirecting to your order...</p>
            <div class="flex items-center justify-center gap-2">
                <span class="w-2 h-2 bg-red-600 rounded-full animate-bounce" style="animation-delay: 0s"></span>
                <span class="w-2 h-2 bg-red-600 rounded-full animate-bounce" style="animation-delay: 0.15s"></span>
                <span class="w-2 h-2 bg-red-600 rounded-full animate-bounce" style="animation-delay: 0.3s"></span>
            </div>
        </div>
        <script>
            setTimeout(() => {
                window.location.href = 'order_success.php?order_id=<?= $order_id ?>';
            }, 2000);
        </script>
        <?php endif; ?>

    </div>
</body>
</html>