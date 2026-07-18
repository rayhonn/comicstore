<?php
require_once '../vendor/autoload.php';
require_once '../includes/stripe_config.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'customer') {
    header('Location: login.php');
    exit;
}
require_once '../includes/db.php';
require_once '../includes/config.php';
require_once '../includes/notifications.php';

$session_id = $_GET['session_id'] ?? null;
if (!$session_id || !isset($_SESSION['pending_order'])) {
    header('Location: cart.php');
    exit;
}

// Verify with Stripe
\Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);
try {
    $stripe_session = \Stripe\Checkout\Session::retrieve($session_id);
    if ($stripe_session->payment_status !== 'paid') {
        header('Location: payment_cancel.php');
        exit;
    }
} catch (\Stripe\Exception\ApiErrorException $e) {
    header('Location: payment_cancel.php');
    exit;
}

$order = $_SESSION['pending_order'];
$user_id = $_SESSION['user_id'];

date_default_timezone_set('Asia/Kuala_Lumpur');

// Create order in database
$pdo->prepare("INSERT INTO orders (order_user_id, order_total_amount, order_has_physical, order_address_id, order_shipping_method, order_shipping_fee, order_courier, order_delivery_zone, order_payment_method, order_payment_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'confirmed')")
    ->execute([
        $order['user_id'],
        $order['total'],
        $order['has_physical'] ? 1 : 0,
        $order['address_id'],
        $order['shipping_method'],
        $order['shipping_fee'],
        $order['shipping_courier'] ?? null,
        $order['shipping_zone'] ?? 'peninsular',
        'Stripe — ' . $stripe_session->payment_method_types[0],
    ]);
$order_id = $pdo->lastInsertId();

// Insert order items
foreach ($order['items'] as $item) {
    $pdo->prepare("INSERT INTO order_items (order_item_order_id, order_item_product_id, order_item_quantity, order_item_price, order_item_type) VALUES (?, ?, ?, ?, ?)")
        ->execute([$order_id, $item['cart_item_product_id'], $item['cart_item_quantity'], $item['product_price'], $item['product_type']]);
    if ($item['product_type'] === 'physical') {
        $pdo->prepare("UPDATE product_physical SET physical_stock_quantity = physical_stock_quantity - ? WHERE physical_product_id = ?")
            ->execute([$item['cart_item_quantity'], $item['cart_item_product_id']]);
    }
    if ($item['product_type'] === 'ebook') {
        $exists = $pdo->prepare("SELECT collection_id FROM user_collection WHERE collection_user_id = ? AND collection_product_id = ?");
        $exists->execute([$order['user_id'], $item['cart_item_product_id']]);
        if (!$exists->fetch()) {
            $pdo->prepare("INSERT INTO user_collection (collection_user_id, collection_product_id) VALUES (?, ?)")
                ->execute([$order['user_id'], $item['cart_item_product_id']]);
        }
    }
}

// Handle voucher
if (!empty($order['voucher_id'])) {
    $pdo->prepare("INSERT INTO voucher_usage (usage_voucher_id, usage_user_id, usage_order_id, usage_discount_amount) VALUES (?, ?, ?, ?)")
        ->execute([$order['voucher_id'], $user_id, $order_id, $order['discount_amount']]);
    $pdo->prepare("UPDATE vouchers SET voucher_used_count = voucher_used_count + 1 WHERE voucher_id = ?")
        ->execute([$order['voucher_id']]);
    $pdo->prepare("UPDATE user_vouchers SET uv_is_used = 1, uv_status = 'used', uv_used_at = NOW() WHERE uv_voucher_id = ? AND uv_user_id = ?")
        ->execute([$order['voucher_id'], $user_id]);
}

if (!empty($order['voucher_code'])) {
    $pdo->prepare("UPDATE orders SET order_voucher_code = ?, order_discount_amount = ? WHERE order_id = ?")
        ->execute([$order['voucher_code'], $order['discount_amount'], $order_id]);
}

// Update order timestamps
$pdo->prepare("UPDATE orders SET order_processing_at = NOW() WHERE order_id = ?")
    ->execute([$order_id]);

// Delete cart items
foreach ($order['items'] as $item) {
    $pdo->prepare("DELETE FROM cart_items WHERE cart_item_id = ?")
        ->execute([$item['cart_item_id']]);
}

// Award points with tier multiplier
$user_tier_data = $pdo->prepare("SELECT user_tier FROM users WHERE user_id = ?");
$user_tier_data->execute([$user_id]);
$current_tier = $user_tier_data->fetchColumn() ?? 'bronze';

$tier_cfg = $pdo->prepare("SELECT tier_points_multiplier FROM tier_config WHERE tier_name = ?");
$tier_cfg->execute([$current_tier]);
$multiplier = $tier_cfg->fetchColumn() ?? 1;

$points_earned = floor($order['total'] * $multiplier);
$pdo->prepare("UPDATE users SET user_points = user_points + ? WHERE user_id = ?")
    ->execute([$points_earned, $user_id]);
$pdo->prepare("INSERT INTO points_log (log_user_id, log_points, log_type, log_description, log_order_id) VALUES (?, ?, 'earn', ?, ?)")
    ->execute([$user_id, $points_earned, "Earned from Order #" . str_pad($order_id, 4, '0', STR_PAD_LEFT), $order_id]);

// Update lifetime spending & tier
$pdo->prepare("UPDATE users SET user_lifetime_spending = user_lifetime_spending + ? WHERE user_id = ?")
    ->execute([$order['total'], $user_id]);

$new_spending = $pdo->prepare("SELECT user_lifetime_spending FROM users WHERE user_id = ?");
$new_spending->execute([$user_id]);
$new_spending = $new_spending->fetchColumn();

$all_tiers = $pdo->query("SELECT tier_name, tier_min_spending FROM tier_config ORDER BY tier_min_spending DESC")->fetchAll(PDO::FETCH_ASSOC);
$new_tier = 'bronze';
foreach ($all_tiers as $t) {
    if ($new_spending >= $t['tier_min_spending']) {
        $new_tier = $t['tier_name'];
        break;
    }
}

if ($new_tier !== $current_tier) {
    $pdo->prepare("UPDATE users SET user_tier = ? WHERE user_id = ?")
        ->execute([$new_tier, $user_id]);
    $tier_labels = ['silver' => 'Silver 🥈', 'gold' => 'Gold 🥇', 'platinum' => 'Platinum 💎'];
    sendNotification($pdo, $user_id, 'Tier Upgraded! 🎉',
        'Congratulations! You\'ve been upgraded to ' . ($tier_labels[$new_tier] ?? $new_tier) . ' tier. Enjoy your new benefits!', 'order');
}

// Send notification
$order_num = '#' . str_pad($order_id, 4, '0', STR_PAD_LEFT);
sendNotification($pdo, $user_id, 'Payment Confirmed! 🎉',
    "Your payment for order $order_num has been confirmed via Stripe. We are now processing your order.", 'order');

// Send confirmation email
require_once '../includes/mail_config.php';
$user_info = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
$user_info->execute([$user_id]);
$user_info = $user_info->fetch(PDO::FETCH_ASSOC);

$email_items = $pdo->prepare("
    SELECT oi.*, p.product_title, p.product_type
    FROM order_items oi
    JOIN products p ON oi.order_item_product_id = p.product_id
    WHERE oi.order_item_order_id = ?
");
$email_items->execute([$order_id]);
$email_items = $email_items->fetchAll(PDO::FETCH_ASSOC);

$items_html = '';
foreach ($email_items as $ei) {
    $type_badge = $ei['product_type'] === 'ebook' ? '📱 E-Book' : '📦 Physical';
    $items_html .= "<tr>
        <td style='padding:10px 12px; border-bottom:1px solid #f3f4f6; font-size:13px; color:#374151;'>{$ei['product_title']}</td>
        <td style='padding:10px 12px; border-bottom:1px solid #f3f4f6; font-size:13px; color:#6b7280; text-align:center;'>$type_badge</td>
        <td style='padding:10px 12px; border-bottom:1px solid #f3f4f6; font-size:13px; color:#6b7280; text-align:center;'>{$ei['order_item_quantity']}</td>
        <td style='padding:10px 12px; border-bottom:1px solid #f3f4f6; font-size:13px; color:#374151; text-align:right;'>RM " . number_format($ei['order_item_price'] * $ei['order_item_quantity'], 2) . "</td>
    </tr>";
}

$shipping_fee = number_format($order['shipping_fee'], 2);
$subtotal = number_format($order['total'] - $order['shipping_fee'] + ($order['discount_amount'] ?? 0), 2);
$total_fmt = number_format($order['total'], 2);
$order_date = date('d F Y, h:i A');
$first_name = htmlspecialchars($user_info['user_first_name']);

$email_body = "
<!DOCTYPE html><html><head><meta charset='UTF-8'></head>
<body style='margin:0; padding:0; background:#F5F0EB; font-family:-apple-system, BlinkMacSystemFont, sans-serif;'>
    <div style='max-width:600px; margin:30px auto; background:white; border-radius:16px; overflow:hidden; box-shadow:0 4px 20px rgba(0,0,0,0.08);'>
        <div style='background:linear-gradient(135deg, #1e2d4a, #2c3e6b); padding:32px; text-align:center;'>
            <h1 style='color:white; font-size:24px; font-weight:900; margin:0 0 4px 0;'>Manga<span style='color:#ef4444;'>Vault</span></h1>
            <p style='color:rgba(255,255,255,0.6); font-size:13px; margin:0;'>Order Confirmed</p>
        </div>
        <div style='padding:32px;'>
            <table style='background:#f0fdf4; border:1px solid #bbf7d0; border-radius:12px; width:100%; margin-bottom:24px;' cellpadding='0' cellspacing='0'>
                <tr><td style='padding:16px;'>
                    <p style='font-weight:700; color:#166534; margin:0 0 4px 0; font-size:15px;'>✅ Payment Successful via Stripe!</p>
                    <p style='color:#16a34a; font-size:13px; margin:0;'>Your order has been confirmed and is being processed.</p>
                </td></tr>
            </table>
            <p style='color:#374151; font-size:15px; margin:0 0 24px 0;'>Hi <strong>$first_name</strong>, thank you for your order!</p>
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
                <tr><td style='padding:8px 0;'>
                    <table width='100%' cellpadding='0' cellspacing='0'><tr>
                        <td style='color:#6b7280; font-size:13px;'>Subtotal</td>
                        <td style='color:#374151; font-size:13px; text-align:right;'>RM $subtotal</td>
                    </tr></table>
                </td></tr>
                <tr><td style='padding:8px 0;'>
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
                <a href='" . APP_URL . "/customer/orders.php' style='display:inline-block; background:#C0392B; color:white; font-weight:700; font-size:14px; padding:12px 28px; border-radius:12px; text-decoration:none;'>View My Orders</a>
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
    $mail->Subject = "Order Confirmed $order_num - MangaVault 🎉";
    $mail->isHTML(true);
    $mail->Body = $email_body;
    $mail->send();
} catch (Exception $e) {
    // Silent fail
}

// Clear session
unset($_SESSION['pending_order']);
unset($_SESSION['payment_lock']);
unset($_SESSION['stripe_session_id']);

$order_id_final = $order_id;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Successful - MangaVault</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { opacity: 0; animation: fadeIn 0.4s ease forwards; }
        @keyframes fadeIn { to { opacity: 1; } }
    </style>
</head>
<body class="bg-[#F5F0EB] min-h-screen flex items-center justify-center px-6">
    <div class="max-w-md w-full">
        <div class="bg-white rounded-2xl shadow-sm p-10 text-center">
            <div class="w-20 h-20 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-5">
                <svg class="w-10 h-10 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                </svg>
            </div>
            <h2 class="text-2xl font-black text-gray-800 mb-2">Payment Successful! 🎉</h2>
            <p class="text-gray-500 text-sm mb-2">Your payment has been processed via Stripe.</p>
            <p class="text-gray-400 text-xs mb-6">Order <strong class="text-gray-600"><?= $order_num ?></strong> · RM <?= number_format($order['total'], 2) ?></p>

            <div class="bg-green-50 border border-green-100 rounded-xl p-4 mb-6 text-left">
                <p class="text-xs text-green-700 font-semibold mb-1">✅ What's next?</p>
                <p class="text-xs text-green-600">You'll receive a confirmation email shortly. Your order is now being processed.</p>
            </div>

            <div class="flex gap-3">
                <a href="orders.php" class="flex-1 bg-red-600 hover:bg-red-700 text-white font-bold py-3 rounded-xl text-sm transition-colors text-center">
                    View Orders
                </a>
                <a href="home.php" class="flex-1 border-2 border-gray-100 hover:bg-gray-50 text-gray-600 font-semibold py-3 rounded-xl text-sm transition-colors text-center">
                    Continue Shopping
                </a>
            </div>
        </div>
    </div>
    <script>
        setTimeout(() => {
            window.location.href = 'order_success.php?order_id=<?= $order_id_final ?>';
        }, 3000);
    </script>
</body>
</html>