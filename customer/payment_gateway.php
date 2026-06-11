<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require_once '../vendor/autoload.php';
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'customer') {
    header('Location: login.php');
    exit;
}
require_once '../includes/db.php';

if (!isset($_SESSION['pending_order'])) {
    header('Location: cart.php');
    exit;
}

$order = $_SESSION['pending_order'];
$lock_locked_at = $_SESSION['payment_lock']['locked_at'] ?? 0;
$total = $order['total'];
$user_id = $_SESSION['user_id'];

$saved_methods = $pdo->prepare("SELECT * FROM payment_methods WHERE pm_user_id = ? ORDER BY pm_is_default DESC, pm_created_at DESC");
$saved_methods->execute([$user_id]);
$saved_methods = $saved_methods->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_payment'])) {
    date_default_timezone_set('Asia/Kuala_Lumpur');

    $pending_check = $pdo->prepare("
        SELECT order_id, order_confirm_expires_at FROM orders 
        WHERE order_user_id = ? AND order_payment_status = 'pending_confirmation'
        AND order_confirm_expires_at > NOW()
        ORDER BY order_created_at DESC LIMIT 1
    ");
    $pending_check->execute([$order['user_id']]);
    $pending = $pending_check->fetch(PDO::FETCH_ASSOC);

    if ($pending) {
        $expires = new DateTime($pending['order_confirm_expires_at']);
        $now = new DateTime();
        $diff = $expires->getTimestamp() - $now->getTimestamp();
        $_SESSION['payment_pending'] = [
            'order_id' => $pending['order_id'],
            'expires_in' => $diff
        ];
        header('Location: payment_pending.php');
        exit;
    }

    // Create order
    $payment_method = $_POST['payment_method'] ?? 'unknown';
    $pdo->prepare("INSERT INTO orders (order_user_id, order_total_amount, order_has_physical, order_address_id, order_shipping_method, order_shipping_fee, order_courier, order_delivery_zone, order_payment_method, order_payment_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending_confirmation')")
        ->execute([
            $order['user_id'],
            $order['total'],
            $order['has_physical'] ? 1 : 0,
            $order['address_id'],
            $order['shipping_method'],
            $order['shipping_fee'],
            $order['shipping_courier'] ?? null,
            $order['shipping_zone'] ?? 'peninsular',
            $payment_method,
        ]);
    $order_id = $pdo->lastInsertId();

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

    // Token
    $token = bin2hex(random_bytes(32));
    $expires_at = date('Y-m-d H:i:s', strtotime('+5 minutes'));
    $pdo->prepare("UPDATE orders SET order_confirm_token = ?, order_confirm_expires_at = ? WHERE order_id = ?")
        ->execute([$token, $expires_at, $order_id]);

    // If voucher_id is missing but voucher_code exists, look up the ID
    if (empty($order['voucher_id']) && !empty($order['voucher_code'])) {
        $vv = $pdo->prepare("SELECT voucher_id FROM vouchers WHERE voucher_code = ?");
        $vv->execute([$order['voucher_code']]);
        $vv = $vv->fetch(PDO::FETCH_ASSOC);
        if ($vv) {
            $order['voucher_id'] = $vv['voucher_id'];
        }
    }

    // Record voucher usage
    if (!empty($order['voucher_id'])) {
        $pdo->prepare("INSERT INTO voucher_usage (usage_voucher_id, usage_user_id, usage_order_id, usage_discount_amount) VALUES (?, ?, ?, ?)")
            ->execute([$order['voucher_id'], $user_id, $order_id, $order['discount_amount']]);
        $pdo->prepare("UPDATE vouchers SET voucher_used_count = voucher_used_count + 1 WHERE voucher_id = ?")
            ->execute([$order['voucher_id']]);
        // Set voucher to pending
        $pdo->prepare("UPDATE user_vouchers SET uv_status = 'pending', uv_is_used = 0 WHERE uv_voucher_id = ? AND uv_user_id = ?")
            ->execute([$order['voucher_id'], $user_id]);
        // If not in user_vouchers yet, insert as pending
        $exists_uv = $pdo->prepare("SELECT uv_id FROM user_vouchers WHERE uv_voucher_id = ? AND uv_user_id = ?");
        $exists_uv->execute([$order['voucher_id'], $user_id]);
        if (!$exists_uv->fetch()) {
            $pdo->prepare("INSERT INTO user_vouchers (uv_user_id, uv_voucher_id, uv_status, uv_is_used) VALUES (?, ?, 'pending', 0)")
                ->execute([$user_id, $order['voucher_id']]);
        }
    }

    // Update order with voucher info
    if (!empty($order['voucher_code'])) {
        $pdo->prepare("UPDATE orders SET order_voucher_code = ?, order_discount_amount = ? WHERE order_id = ?")
            ->execute([$order['voucher_code'], $order['discount_amount'], $order_id]);
    }

    foreach ($order['items'] as $item) {
        $pdo->prepare("DELETE FROM cart_items WHERE cart_item_id = ?")
            ->execute([$item['cart_item_id']]);
    }
    unset($_SESSION['pending_order']);

    // Email
    require_once '../includes/mail_config.php';
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

    $user_info = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
    $user_info->execute([$order['user_id']]);
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

    $host = $_SERVER['HTTP_HOST'];
    $confirm_url = "http://{$host}/comicstore/customer/payment_verify.php?token=$token";
    $discount_amount = $order['discount_amount'] ?? 0;
    $voucher_code_used = $order['voucher_code'] ?? null;
    $discount_fmt = number_format($discount_amount, 2);
    $shipping_fee = number_format($order['shipping_fee'], 2);
    $subtotal = number_format($order['total'] - $order['shipping_fee'] + $discount_amount, 2);
    $total_fmt = number_format($order['total'], 2);
    $order_date = date('d F Y, h:i A');
    $first_name = htmlspecialchars($user_info['user_first_name']);

    $voucher_row = $voucher_code_used ? "
    <tr><td style='padding:4px 0 4px;'>
        <table width='100%' cellpadding='0' cellspacing='0'><tr>
            <td style='color:#16a34a; font-size:13px;'>🎟️ Voucher (<span style='font-family:monospace; background:#f0fdf4; padding:1px 6px; border-radius:4px;'>$voucher_code_used</span>)</td>
            <td style='color:#16a34a; font-size:13px; text-align:right; font-weight:600;'>-RM $discount_fmt</td>
        </tr></table>
    </td></tr>" : "";

    $email_body = "
    <!DOCTYPE html>
    <html>
    <head><meta charset='UTF-8'></head>
    <body style='margin:0; padding:0; background:#F5F0EB; font-family:-apple-system, BlinkMacSystemFont, sans-serif;'>
        <div style='max-width:600px; margin:30px auto; background:white; border-radius:16px; overflow:hidden; box-shadow:0 4px 20px rgba(0,0,0,0.08);'>
            <div style='background:linear-gradient(135deg, #1e2d4a, #2c3e6b); padding:32px; text-align:center;'>
                <h1 style='color:white; font-size:24px; font-weight:900; margin:0 0 4px 0;'>Manga<span style='color:#ef4444;'>Vault</span></h1>
                <p style='color:rgba(255,255,255,0.6); font-size:13px; margin:0;'>Payment Confirmation Required</p>
            </div>
            <div style='padding:32px;'>
                <table style='background:#fefce8; border:1px solid #fde68a; border-radius:12px; width:100%; margin-bottom:24px;' cellpadding='0' cellspacing='0'>
                    <tr>
                        <td style='padding:16px 8px 16px 16px; width:40px; vertical-align:middle; font-size:24px;'>⚠️</td>
                        <td style='padding:16px 16px 16px 4px; vertical-align:middle;'>
                            <p style='font-weight:700; color:#92400e; margin:0 0 4px 0; font-size:15px;'>Action Required!</p>
                            <p style='color:#b45309; font-size:13px; margin:0;'>Please confirm your payment within <strong>5 minutes</strong> or your order will be cancelled.</p>
                        </td>
                    </tr>
                </table>
                <p style='color:#374151; font-size:15px; margin:0 0 24px 0;'>Hi <strong>$first_name</strong>, we received a payment request for your order. Please verify it's you by clicking the button below.</p>
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
                    <tr><td style='padding:4px 0 4px;'>
                        <table width='100%' cellpadding='0' cellspacing='0'><tr>
                            <td style='color:#6b7280; font-size:13px;'>Shipping</td>
                            <td style='color:#374151; font-size:13px; text-align:right;'>RM $shipping_fee</td>
                        </tr></table>
                    </td></tr>
                    $voucher_row
                    <tr><td style='background:#fef2f2; padding:12px; border-radius:8px;'>
                        <table width='100%' cellpadding='0' cellspacing='0'><tr>
                            <td style='font-weight:900; color:#111827; font-size:15px;'>Total</td>
                            <td style='font-weight:900; color:#C0392B; font-size:15px; text-align:right;'>RM $total_fmt</td>
                        </tr></table>
                    </td></tr>
                </table>
                <div style='text-align:center; margin-bottom:16px;'>
                    <a href='$confirm_url' style='display:inline-block; background:#16a34a; color:white; font-weight:900; font-size:16px; padding:16px 40px; border-radius:14px; text-decoration:none; letter-spacing:0.02em;'>
                        ✅ Confirm My Payment
                    </a>
                </div>
                <p style='text-align:center; color:#9ca3af; font-size:12px; margin:0 0 24px 0;'>This link expires in <strong>5 minutes</strong>. If you did not make this purchase, ignore this email.</p>
                <table style='background:#fef2f2; border:1px solid #fecaca; border-radius:12px; width:100%;' cellpadding='0' cellspacing='0'>
                    <tr><td style='padding:12px 16px;'>
                        <p style='font-size:12px; color:#991b1b; margin:0;'>🔒 <strong>Security Notice:</strong> MangaVault will never ask for your password or payment details via email. If you did not initiate this transaction, please contact us immediately.</p>
                    </td></tr>
                </table>
            </div>
            <div style='background:#f9fafb; padding:20px 32px; text-align:center; border-top:1px solid #f3f4f6;'>
                <p style='color:#9ca3af; font-size:12px; margin:0 0 4px 0;'>MangaVault — Your One-Stop Manga Store</p>
                <p style='color:#d1d5db; font-size:11px; margin:0;'>This is an automated email. Please do not reply.</p>
            </div>
        </div>
    </body>
    </html>";

    try {
        $mail->clearAddresses();
        $mail->addAddress($user_info['user_gmail'], $user_info['user_first_name'] . ' ' . $user_info['user_last_name']);
        $mail->Subject = "⚠️ Confirm Your Payment $order_num - MangaVault";
        $mail->isHTML(true);
        $mail->Body = $email_body;
        $mail->AltBody = "Hi $first_name! Please confirm your payment for order $order_num (RM $total_fmt) by clicking: $confirm_url — This link expires in 5 minutes.";
        $mail->send();
    } catch (Exception $e) {
        // Silent fail
    }

    header('Location: payment_waiting.php?order_id=' . $order_id);
    exit;
}

$ewallets = [
    ['name' => 'Touch n Go', 'emoji' => '💙', 'gradient' => 'linear-gradient(135deg, #3b82f6, #1d4ed8)'],
    ['name' => 'GrabPay', 'emoji' => '💚', 'gradient' => 'linear-gradient(135deg, #4ade80, #16a34a)'],
    ['name' => 'ShopeePay', 'emoji' => '🧡', 'gradient' => 'linear-gradient(135deg, #fb923c, #ef4444)'],
    ['name' => 'Boost', 'emoji' => '❤️', 'gradient' => 'linear-gradient(135deg, #f87171, #dc2626)'],
];

$bank_data = [
    ['name' => 'Maybank2u', 'gradient' => 'linear-gradient(135deg, #f59e0b, #d97706)', 'short' => 'MAY'],
    ['name' => 'CIMB Clicks', 'gradient' => 'linear-gradient(135deg, #dc2626, #b91c1c)', 'short' => 'CIMB'],
    ['name' => 'Public Bank', 'gradient' => 'linear-gradient(135deg, #1d4ed8, #1e40af)', 'short' => 'PBB'],
    ['name' => 'RHB Now', 'gradient' => 'linear-gradient(135deg, #9333ea, #7e22ce)', 'short' => 'RHB'],
    ['name' => 'Hong Leong', 'gradient' => 'linear-gradient(135deg, #16a34a, #15803d)', 'short' => 'HLB'],
    ['name' => 'AmOnline', 'gradient' => 'linear-gradient(135deg, #f97316, #ea580c)', 'short' => 'AMB'],
    ['name' => 'Bank Islam', 'gradient' => 'linear-gradient(135deg, #0d9488, #0f766e)', 'short' => 'BIMB'],
    ['name' => 'BSN MyBSN', 'gradient' => 'linear-gradient(135deg, #4f46e5, #4338ca)', 'short' => 'BSN'],
];

$ewallet_gradients = [
    'Touch n Go' => 'linear-gradient(135deg, #3b82f6, #1d4ed8)',
    'GrabPay' => 'linear-gradient(135deg, #4ade80, #16a34a)',
    'ShopeePay' => 'linear-gradient(135deg, #fb923c, #ef4444)',
    'Boost' => 'linear-gradient(135deg, #f87171, #dc2626)',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment - MangaVault</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        html { scroll-behavior: smooth; }
        body { opacity: 0; animation: fadeIn 0.4s ease forwards; }
        @keyframes fadeIn { to { opacity: 1; } }
        .payment-section { display: none; opacity: 0; }
        .payment-section.active {
            display: block;
            animation: sectionIn 0.35s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }
        @keyframes sectionIn {
            from { opacity: 0; transform: translateY(16px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .method-btn { transition: all 0.2s ease; border: 2px solid #f3f4f6; }
        .method-btn:hover { transform: translateY(-2px); box-shadow: 0 6px 16px rgba(0,0,0,0.08); border-color: #fca5a5; }
        .method-btn.selected { border-color: #C0392B; background: #fef2f2; }
        .saved-method-btn { transition: all 0.2s ease; border: 2px solid #f3f4f6; }
        .saved-method-btn:hover { transform: translateY(-2px); box-shadow: 0 6px 16px rgba(0,0,0,0.1); border-color: #fca5a5; }
        .saved-method-btn.selected { border-color: #C0392B; background: #fef2f2; }
        .wallet-btn { transition: all 0.2s ease; border: 2px solid #f3f4f6; }
        .wallet-btn:hover { transform: translateY(-2px); box-shadow: 0 6px 16px rgba(0,0,0,0.1); border-color: #fca5a5; }
        .bank-item { transition: all 0.15s ease; }
        .bank-item:hover { transform: translateY(-1px); }
        .pin-input { transition: all 0.15s ease; }
        .pin-input:focus { transform: scale(1.1); }
        .pin-input.filled { border-color: #16a34a; background: #f0fdf4; }
    </style>
</head>
<body class="bg-[#F5F0EB] min-h-screen">

    <?php include '../includes/customer_navbar.php'; ?>

    <?php if ($lock_locked_at): ?>
    <div id="paymentTimerBar" class="bg-yellow-50 border-b border-yellow-200 px-6 py-3">
        <div class="max-w-6xl mx-auto flex items-center justify-between">
            <div class="flex items-center gap-3">
                <span class="text-xl">⏳</span>
                <div>
                    <p class="text-sm font-semibold text-yellow-800">Complete payment within</p>
                    <p class="text-xs text-yellow-600">Your order is reserved for 5 minutes</p>
                </div>
            </div>
            <div class="text-2xl font-black text-yellow-700" id="gatewayCountdown">05:00</div>
        </div>
        <div class="max-w-6xl mx-auto mt-2">
            <div class="h-1.5 bg-yellow-200 rounded-full overflow-hidden">
                <div id="timerProgress" class="h-full bg-yellow-500 rounded-full transition-all duration-1000" style="width:100%"></div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="max-w-6xl mx-auto px-6 py-8">
        <div class="text-center mb-6">
            <h1 class="text-2xl font-black text-gray-800">Complete Payment</h1>
            <p class="text-gray-400 text-sm mt-1">Total: <span class="font-bold text-red-600 text-lg">RM <?= number_format($total, 2) ?></span></p>
        </div>

        <div class="bg-yellow-50 border border-yellow-200 rounded-2xl px-4 py-3 mb-6 flex items-center gap-3 max-w-2xl mx-auto">
            <span class="text-lg flex-shrink-0">⚠️</span>
            <p class="text-xs text-yellow-700">This is a <strong>simulated payment</strong> for demo purposes only.</p>
        </div>

        <form method="POST" id="paymentForm">
            <input type="hidden" name="confirm_payment" value="1">
            <input type="hidden" name="payment_method" id="paymentMethodInput">

            <div class="flex flex-col lg:flex-row gap-6 items-start">

                <!-- LEFT -->
                <div class="w-full lg:w-72 flex-shrink-0">
                    <div class="bg-white rounded-2xl shadow-sm p-6 lg:sticky lg:top-24">

                        <?php if (count($saved_methods) > 0): ?>
                        <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-3">Saved Methods</p>
                        <div class="space-y-2 mb-5">
                            <?php foreach ($saved_methods as $sm): ?>
                            <button type="button"
                                    onclick="selectSavedMethod(this, <?= $sm['pm_id'] ?>, '<?= $sm['pm_type'] ?>', '<?= htmlspecialchars(addslashes($sm['pm_label'])) ?>', '<?= htmlspecialchars(addslashes($sm['pm_ewallet_name'] ?? '')) ?>')"
                                    class="saved-method-btn w-full flex items-center gap-3 p-3 rounded-xl text-left border-2 border-gray-100"
                                    id="saved-btn-<?= $sm['pm_id'] ?>">
                                <div class="w-10 h-10 <?= $sm['pm_type'] === 'card' ? 'bg-gradient-to-br from-blue-500 to-purple-600' : 'bg-gradient-to-br from-orange-400 to-pink-500' ?> rounded-xl flex items-center justify-center text-xl flex-shrink-0">
                                    <?= $sm['pm_type'] === 'card' ? '💳' : '📱' ?>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-1.5 flex-wrap">
                                        <p class="font-semibold text-xs text-gray-800 truncate"><?= htmlspecialchars($sm['pm_label']) ?></p>
                                        <?php if ($sm['pm_is_default']): ?>
                                        <span class="bg-red-600 text-white text-[10px] px-1.5 py-0.5 rounded-full font-semibold flex-shrink-0">Default</span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($sm['pm_type'] === 'card'): ?>
                                    <p class="text-xs text-gray-400">•••• <?= htmlspecialchars($sm['pm_last_four']) ?> · <?= htmlspecialchars($sm['pm_expiry']) ?></p>
                                    <?php else: ?>
                                    <p class="text-xs text-gray-400"><?= htmlspecialchars($sm['pm_ewallet_name']) ?></p>
                                    <?php endif; ?>
                                </div>
                                <svg class="w-3 h-3 text-gray-300 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                            </button>
                            <?php endforeach; ?>
                        </div>
                        <div class="border-t border-gray-100 pt-4 mb-3">
                            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-3">New Payment</p>
                        </div>
                        <?php else: ?>
                        <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-4">Payment Method</p>
                        <?php endif; ?>

                        <div class="space-y-2">
                            <button type="button" onclick="selectMethod('card')"
                                    class="method-btn w-full flex items-center gap-3 p-3 rounded-xl text-left" id="btn-card">
                                <div class="w-9 h-9 bg-gradient-to-br from-blue-500 to-purple-600 rounded-lg flex items-center justify-center text-lg flex-shrink-0">💳</div>
                                <div class="flex-1">
                                    <p class="font-semibold text-xs text-gray-800">Credit / Debit Card</p>
                                    <p class="text-xs text-gray-400">Visa, Mastercard, Amex</p>
                                </div>
                                <svg class="w-3 h-3 text-gray-300 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                            </button>
                            <button type="button" onclick="selectMethod('banking')"
                                    class="method-btn w-full flex items-center gap-3 p-3 rounded-xl text-left" id="btn-banking">
                                <div class="w-9 h-9 bg-gradient-to-br from-green-500 to-teal-600 rounded-lg flex items-center justify-center text-lg flex-shrink-0">🏦</div>
                                <div class="flex-1">
                                    <p class="font-semibold text-xs text-gray-800">Online Banking</p>
                                    <p class="text-xs text-gray-400">Maybank, CIMB & more</p>
                                </div>
                                <svg class="w-3 h-3 text-gray-300 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                            </button>
                            <button type="button" onclick="selectMethod('ewallet')"
                                    class="method-btn w-full flex items-center gap-3 p-3 rounded-xl text-left" id="btn-ewallet">
                                <div class="w-9 h-9 bg-gradient-to-br from-orange-400 to-pink-500 rounded-lg flex items-center justify-center text-lg flex-shrink-0">📱</div>
                                <div class="flex-1">
                                    <p class="font-semibold text-xs text-gray-800">E-Wallet</p>
                                    <p class="text-xs text-gray-400">TNG, GrabPay & more</p>
                                </div>
                                <svg class="w-3 h-3 text-gray-300 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                            </button>
                        </div>

                        <!-- Order Summary -->
                        <div class="mt-6 pt-5 border-t border-gray-100">
                            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-3">Order Summary</p>
                            <div class="flex justify-between text-sm text-gray-500 mb-1">
                                <span>Subtotal</span>
                                <span>RM <?= number_format($order['total'] - $order['shipping_fee'] + ($order['discount_amount'] ?? 0), 2) ?></span>
                            </div>
                            <div class="flex justify-between text-sm text-gray-500 mb-1">
                                <span>Shipping</span>
                                <span><?= $order['shipping_fee'] > 0 ? 'RM ' . number_format($order['shipping_fee'], 2) : 'Free' ?></span>
                            </div>
                            <?php if (!empty($order['voucher_code'])): ?>
                            <div class="flex justify-between text-sm text-green-600 mb-1">
                                <span>🎟️ <?= htmlspecialchars($order['voucher_code']) ?></span>
                                <span>-RM <?= number_format($order['discount_amount'], 2) ?></span>
                            </div>
                            <?php endif; ?>
                            <div class="flex justify-between font-black text-gray-800 text-base mt-3 pt-3 border-t border-gray-100">
                                <span>Total</span>
                                <span class="text-red-600">RM <?= number_format($total, 2) ?></span>
                            </div>
                        </div>

                        <div class="mt-4 flex justify-center gap-4">
                            <span class="text-xs text-gray-400">🔒 SSL</span>
                            <span class="text-xs text-gray-400">🛡️ Secure</span>
                            <span class="text-xs text-gray-400">✅ Safe</span>
                        </div>
                    </div>
                </div>

                <!-- RIGHT: Payment Details -->
                <div class="flex-1 w-full min-w-0">

                    <div id="defaultState" class="bg-white rounded-2xl shadow-sm p-16 text-center">
                        <div class="text-6xl mb-4">💳</div>
                        <p class="font-semibold text-gray-600 mb-1">Select a payment method</p>
                        <p class="text-gray-400 text-sm">Choose from the options on the left to continue</p>
                    </div>

                    <!-- SAVED METHOD -->
                    <div id="section-saved" class="payment-section">
                        <div id="savedMethodCard" class="rounded-2xl p-5 mb-4 text-white relative overflow-hidden shadow-lg">
                            <div class="absolute top-0 right-0 w-24 h-24 bg-white/10 rounded-full -translate-y-6 translate-x-6"></div>
                            <div class="flex items-center justify-between relative z-10">
                                <div class="flex items-center gap-3">
                                    <div class="w-12 h-12 bg-white/20 rounded-xl flex items-center justify-center text-2xl" id="savedMethodIcon">💳</div>
                                    <div>
                                        <p class="font-black text-lg" id="savedMethodLabel">My Card</p>
                                        <p class="text-white/60 text-xs" id="savedMethodSub">Saved Payment</p>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <p class="text-white/60 text-xs mb-0.5">Amount</p>
                                    <p class="text-2xl font-black">RM <?= number_format($total, 2) ?></p>
                                </div>
                            </div>
                        </div>
                        <div class="bg-white rounded-2xl shadow-sm p-6 mb-4">
                            <div class="flex items-center justify-between mb-5">
                                <div>
                                    <p class="font-bold text-gray-800">Confirm with Saved Method</p>
                                    <p class="text-xs text-gray-400">Verify your identity to proceed</p>
                                </div>
                            </div>
                            <div id="savedCardFields" class="hidden space-y-4">
                                <div class="bg-gray-50 rounded-xl p-4 flex items-center gap-3 mb-2">
                                    <span class="text-2xl">💳</span>
                                    <div>
                                        <p class="font-semibold text-sm text-gray-800" id="savedCardDisplay">•••• •••• •••• ----</p>
                                        <p class="text-xs text-gray-400" id="savedCardHolder">Card Holder</p>
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">Enter CVV to Confirm</label>
                                    <input type="password" id="savedCvv" placeholder="•••" maxlength="3"
                                           oninput="this.value = this.value.replace(/[^0-9]/g, '')"
                                           class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm font-mono focus:outline-none focus:border-red-400 transition-colors bg-gray-50 focus:bg-white">
                                </div>
                            </div>
                            <div id="savedEwalletFields" class="hidden space-y-4">
                                <div id="savedEwalletBanner" class="rounded-xl p-4 text-white flex items-center gap-3 mb-2">
                                    <span class="text-2xl" id="savedEwalletEmoji">📱</span>
                                    <div>
                                        <p class="font-bold text-sm" id="savedEwalletName">E-Wallet</p>
                                        <p class="text-white/70 text-xs" id="savedEwalletPhone">+60 ---</p>
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-gray-500 mb-3 uppercase tracking-wide">Enter 6-Digit PIN</label>
                                    <div class="flex gap-2 justify-center">
                                        <?php for ($p = 0; $p < 6; $p++): ?>
                                        <input type="password" maxlength="1"
                                               oninput="movePIN(this, <?= $p ?>, 'saved')"
                                               onkeydown="backspacePIN(event, <?= $p ?>, 'saved')"
                                               class="saved-pin pin-input w-12 h-12 text-center text-xl font-black border-2 border-gray-200 rounded-xl focus:outline-none transition-all bg-gray-50">
                                        <?php endfor; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="bg-blue-50 border border-blue-100 rounded-xl p-3 flex items-start gap-2 mt-4">
                                <span class="text-blue-500 text-sm flex-shrink-0">🔒</span>
                                <p class="text-xs text-blue-600">Your credentials are encrypted and secured.</p>
                            </div>
                        </div>
                        <button type="button" onclick="validateSaved()" id="savedPayBtn"
                                class="w-full bg-red-600 hover:bg-red-700 text-white font-black py-4 rounded-2xl text-sm transition-all duration-200 hover:scale-[1.01] shadow-lg shadow-red-100">
                            🔒 Pay RM <?= number_format($total, 2) ?>
                        </button>
                    </div>

                    <!-- CARD -->
                    <div id="section-card" class="payment-section">
                        <div class="bg-white rounded-2xl shadow-sm p-6 mb-4">
                            <h3 class="font-bold text-gray-800 mb-5">Card Details</h3>
                            <div class="bg-gradient-to-r from-[#1e2d4a] to-[#2c3e6b] rounded-2xl p-5 mb-6 text-white relative overflow-hidden">
                                <div class="absolute top-0 right-0 w-32 h-32 bg-white/5 rounded-full -translate-y-8 translate-x-8"></div>
                                <div class="absolute bottom-0 left-0 w-24 h-24 bg-white/5 rounded-full translate-y-8 -translate-x-8"></div>
                                <p class="text-xs text-white/50 mb-3 tracking-widest">CREDIT / DEBIT CARD</p>
                                <p class="font-mono text-xl tracking-widest mb-5" id="cardPreview">•••• •••• •••• ••••</p>
                                <div class="flex justify-between items-end">
                                    <div>
                                        <p class="text-xs text-white/50 mb-0.5">CARD HOLDER</p>
                                        <p class="font-semibold text-sm" id="namePreview">YOUR NAME</p>
                                    </div>
                                    <div>
                                        <p class="text-xs text-white/50 mb-0.5">EXPIRES</p>
                                        <p class="font-semibold text-sm" id="expiryPreview">MM/YY</p>
                                    </div>
                                    <p class="text-2xl font-black text-white/70">VISA</p>
                                </div>
                            </div>
                            <div class="space-y-4">
                                <div>
                                    <label class="block text-xs font-medium text-gray-500 mb-1.5">Card Number</label>
                                    <input type="text" id="cardNumber" placeholder="1234 5678 9012 3456" maxlength="19"
                                           oninput="formatCard(this)"
                                           class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm font-mono focus:outline-none focus:border-red-400 transition-colors bg-gray-50 focus:bg-white">
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-500 mb-1.5">Card Holder Name</label>
                                    <input type="text" id="cardName" placeholder="JOHN DOE"
                                           oninput="document.getElementById('namePreview').textContent = this.value.toUpperCase() || 'YOUR NAME'"
                                           class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 transition-colors bg-gray-50 focus:bg-white uppercase">
                                </div>
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <label class="block text-xs font-medium text-gray-500 mb-1.5">Expiry Date</label>
                                        <input type="text" id="expiry" placeholder="MM/YY" maxlength="5"
                                               oninput="formatExpiry(this)"
                                               class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm font-mono focus:outline-none focus:border-red-400 transition-colors bg-gray-50 focus:bg-white">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-gray-500 mb-1.5">CVV</label>
                                        <input type="password" id="cvvInput" placeholder="•••" maxlength="3"
                                               oninput="this.value = this.value.replace(/[^0-9]/g, '')"
                                               class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm font-mono focus:outline-none focus:border-red-400 transition-colors bg-gray-50 focus:bg-white">
                                    </div>
                                </div>
                            </div>
                        </div>
                        <button type="button" onclick="validateCard()"
                                class="w-full bg-red-600 hover:bg-red-700 text-white font-black py-4 rounded-2xl text-sm transition-all duration-200 hover:scale-[1.01] shadow-lg shadow-red-100">
                            🔒 Pay RM <?= number_format($total, 2) ?>
                        </button>
                    </div>

                    <!-- BANKING -->
                    <div id="section-banking" class="payment-section">
                        <div id="bankListDiv" class="bg-white rounded-2xl shadow-sm p-6 mb-4">
                            <h3 class="font-bold text-gray-800 mb-1">Select Your Bank</h3>
                            <p class="text-xs text-gray-400 mb-5">Choose your preferred bank to continue</p>
                            <div class="grid grid-cols-2 gap-3">
                                <?php foreach ($bank_data as $bank): ?>
                                <button type="button"
                                        onclick="selectBank(this, '<?= $bank['name'] ?>', '<?= addslashes($bank['gradient']) ?>', '<?= $bank['short'] ?>')"
                                        class="bank-item group flex items-center gap-3 p-3 border-2 border-gray-100 rounded-xl transition-all duration-200 text-left bg-gray-50 hover:bg-white hover:border-red-200 hover:shadow-md">
                                    <div class="w-11 h-11 rounded-xl flex items-center justify-center text-white font-black text-xs flex-shrink-0 shadow-sm"
                                         style="background: <?= $bank['gradient'] ?>">
                                        <?= $bank['short'] ?>
                                    </div>
                                    <span class="text-xs font-semibold text-gray-700 group-hover:text-gray-900 leading-tight"><?= $bank['name'] ?></span>
                                </button>
                                <?php endforeach; ?>
                            </div>
                            <p id="bankError" class="text-red-500 text-xs mt-3 hidden">Please select a bank to continue.</p>
                        </div>
                        <div id="bankLoginDiv" class="hidden mb-4">
                            <div id="bankBanner" class="rounded-2xl p-5 mb-4 text-white relative overflow-hidden shadow-lg">
                                <div class="absolute top-0 right-0 w-24 h-24 bg-white/10 rounded-full -translate-y-6 translate-x-6"></div>
                                <div class="flex items-center justify-between relative z-10">
                                    <div class="flex items-center gap-3">
                                        <div class="w-12 h-12 bg-white/20 rounded-xl flex items-center justify-center font-black text-sm" id="bankBannerShort">MAY</div>
                                        <div>
                                            <p class="font-black text-lg" id="bankLoginName">Maybank2u</p>
                                            <p class="text-white/60 text-xs">Secure Online Banking</p>
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <p class="text-white/60 text-xs mb-0.5">Amount</p>
                                        <p class="text-2xl font-black">RM <?= number_format($total, 2) ?></p>
                                    </div>
                                </div>
                            </div>
                            <div class="bg-white rounded-2xl shadow-sm p-6">
                                <div class="flex items-center justify-between mb-5">
                                    <div>
                                        <p class="font-bold text-gray-800">Login to Your Bank</p>
                                        <p class="text-xs text-gray-400">Enter your online banking credentials</p>
                                    </div>
                                    <button type="button" onclick="resetBankSelection()"
                                            class="text-xs text-gray-400 hover:text-red-600 transition-colors">← Change bank</button>
                                </div>
                                <div class="space-y-4">
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">Username / Account No.</label>
                                        <input type="text" id="bankUsername" placeholder="Enter your username"
                                               class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 transition-colors bg-gray-50 focus:bg-white">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">Password</label>
                                        <input type="password" id="bankPassword" placeholder="Enter your password"
                                               class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 transition-colors bg-gray-50 focus:bg-white">
                                    </div>
                                    <div class="bg-blue-50 border border-blue-100 rounded-xl p-3 flex items-start gap-2">
                                        <span class="text-blue-500 text-sm flex-shrink-0 mt-0.5">🔒</span>
                                        <p class="text-xs text-blue-600 leading-relaxed">Your banking credentials are encrypted and will never be stored or shared.</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <button type="button" onclick="validateBanking()"
                                class="w-full bg-red-600 hover:bg-red-700 text-white font-black py-4 rounded-2xl text-sm transition-all duration-200 hover:scale-[1.01] shadow-lg shadow-red-100">
                            🔒 Confirm Payment — RM <?= number_format($total, 2) ?>
                        </button>
                    </div>

                    <!-- E-WALLET -->
                    <div id="section-ewallet" class="payment-section">
                        <div id="walletSelectDiv" class="bg-white rounded-2xl shadow-sm p-6 mb-4">
                            <h3 class="font-bold text-gray-800 mb-5">Select E-Wallet</h3>
                            <div class="space-y-2">
                                <?php foreach ($ewallets as $wallet): ?>
                                <button type="button"
                                        onclick="selectWallet(this, '<?= $wallet['name'] ?>', '<?= addslashes($wallet['gradient']) ?>', '<?= $wallet['emoji'] ?>')"
                                        class="wallet-btn w-full flex items-center gap-4 p-4 rounded-2xl text-left">
                                    <div class="w-14 h-14 rounded-2xl flex items-center justify-center text-3xl flex-shrink-0 shadow-sm"
                                         style="background: <?= $wallet['gradient'] ?>">
                                        <?= $wallet['emoji'] ?>
                                    </div>
                                    <div class="flex-1">
                                        <p class="font-bold text-sm text-gray-800"><?= $wallet['name'] ?></p>
                                        <p class="text-xs text-gray-400">Pay RM <?= number_format($total, 2) ?> securely</p>
                                    </div>
                                    <svg class="w-4 h-4 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                    </svg>
                                </button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div id="walletPayDiv" class="hidden">
                            <div id="walletBanner" class="rounded-2xl p-5 mb-4 text-white relative overflow-hidden shadow-lg">
                                <div class="absolute top-0 right-0 w-24 h-24 bg-white/10 rounded-full -translate-y-6 translate-x-6"></div>
                                <div class="flex items-center justify-between relative z-10">
                                    <div class="flex items-center gap-3">
                                        <span class="text-3xl" id="walletBannerEmoji">💙</span>
                                        <div>
                                            <p class="font-black text-lg" id="walletBannerName">Touch n Go</p>
                                            <p class="text-white/60 text-xs">Secure Payment</p>
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <p class="text-white/60 text-xs mb-0.5">Amount</p>
                                        <p class="text-2xl font-black">RM <?= number_format($total, 2) ?></p>
                                    </div>
                                </div>
                            </div>
                            <div class="bg-white rounded-2xl shadow-sm p-6 mb-4">
                                <div class="flex items-center justify-between mb-5">
                                    <div>
                                        <p class="font-bold text-gray-800">Confirm Payment</p>
                                        <p class="text-xs text-gray-400">Enter your credentials to proceed</p>
                                    </div>
                                    <button type="button" onclick="backToWalletSelect()"
                                            class="text-xs text-gray-400 hover:text-red-600 transition-colors">← Change wallet</button>
                                </div>
                                <div class="space-y-5">
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">Phone Number</label>
                                        <div class="flex gap-2">
                                            <div class="flex items-center gap-1 bg-gray-50 border-2 border-gray-100 px-3 py-3 rounded-xl text-sm text-gray-600 font-semibold flex-shrink-0">🇲🇾 +60</div>
                                            <input type="text" id="ewalletPhone" placeholder="12 3456789" maxlength="10"
                                                   oninput="this.value = this.value.replace(/[^0-9]/g, '')"
                                                   class="flex-1 px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 transition-colors bg-gray-50 focus:bg-white">
                                        </div>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-500 mb-3 uppercase tracking-wide">6-Digit Security PIN</label>
                                        <div class="flex gap-2 justify-center mb-2">
                                            <?php for ($p = 0; $p < 6; $p++): ?>
                                            <input type="password" maxlength="1"
                                                   oninput="movePIN(this, <?= $p ?>, 'new')"
                                                   onkeydown="backspacePIN(event, <?= $p ?>, 'new')"
                                                   class="new-pin pin-input w-12 h-12 text-center text-xl font-black border-2 border-gray-200 rounded-xl focus:outline-none transition-all bg-gray-50">
                                            <?php endfor; ?>
                                        </div>
                                        <p class="text-xs text-gray-400 text-center">Enter the 6-digit PIN linked to your account</p>
                                    </div>
                                    <div class="bg-blue-50 border border-blue-100 rounded-xl p-3 flex items-start gap-2">
                                        <span class="text-blue-500 text-sm flex-shrink-0 mt-0.5">🔒</span>
                                        <p class="text-xs text-blue-600 leading-relaxed">Your credentials are encrypted and will never be stored or shared.</p>
                                    </div>
                                </div>
                            </div>
                            <button type="button" onclick="validateEwallet()" id="walletPayBtn"
                                    class="w-full text-white font-black py-4 rounded-2xl text-sm transition-all duration-200 hover:opacity-90 hover:scale-[1.01] shadow-lg">
                                ✅ Confirm Payment — RM <?= number_format($total, 2) ?>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </form>

        <button type="button" onclick="cancelAndGoBack()"
                class="block w-full text-center text-sm text-gray-400 hover:text-red-600 transition-colors mt-6 cursor-pointer bg-transparent border-none">
            ← Back to Checkout
        </button>
    </div>

    <!-- Security Modal -->
    <div id="securityModal" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center px-6">
        <div class="bg-white rounded-3xl p-8 max-w-sm w-full shadow-2xl text-center">
            <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
                <svg class="w-8 h-8 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>
                </svg>
            </div>
            <h3 class="text-xl font-black text-gray-800 mb-2">Confirm Payment 🔒</h3>
            <p class="text-sm text-gray-500 leading-relaxed mb-2">
                You are about to pay <strong class="text-red-600">RM <?= number_format($total, 2) ?></strong> to MangaVault.
            </p>
            <p class="text-xs text-gray-400 leading-relaxed mb-6">
                MangaVault uses <strong>256-bit SSL encryption</strong>. We <strong>never store</strong> your payment details and will <strong>never share</strong> your information.
            </p>
            <div class="flex gap-3">
                <button id="cancelPayBtn" class="flex-1 py-3 border-2 border-gray-100 rounded-xl text-sm font-semibold text-gray-600 hover:bg-gray-50 transition-colors">Cancel</button>
                <button id="confirmPayBtn" class="flex-1 py-3 bg-red-600 hover:bg-red-700 text-white rounded-xl text-sm font-semibold transition-all hover:scale-[1.02]">Confirm Payment</button>
            </div>
        </div>
    </div>

    <!-- Processing Overlay -->
    <div id="processingOverlay" class="hidden fixed inset-0 bg-white z-50 flex flex-col items-center justify-center">
        <div class="text-center">
            <div class="w-20 h-20 mx-auto mb-6 relative">
                <div class="w-20 h-20 border-4 border-red-100 rounded-full"></div>
                <div class="w-20 h-20 border-4 border-red-600 border-t-transparent rounded-full animate-spin absolute top-0 left-0"></div>
            </div>
            <p class="text-2xl font-black text-gray-800 mb-2">Processing Payment</p>
            <p class="text-gray-400 text-sm" id="processingMsg" style="transition: opacity 0.3s ease;">Verifying your payment...</p>
            <div class="flex items-center justify-center gap-2 mt-4">
                <span class="w-2 h-2 bg-red-600 rounded-full animate-bounce" style="animation-delay: 0s"></span>
                <span class="w-2 h-2 bg-red-600 rounded-full animate-bounce" style="animation-delay: 0.15s"></span>
                <span class="w-2 h-2 bg-red-600 rounded-full animate-bounce" style="animation-delay: 0.3s"></span>
            </div>
        </div>
    </div>

    <!-- Leave Warning Modal -->
    <div id="leaveWarningModal" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center px-6">
        <div class="bg-white rounded-3xl p-8 max-w-sm w-full shadow-2xl text-center">
            <div class="w-16 h-16 bg-yellow-100 rounded-full flex items-center justify-center mx-auto mb-4">
                <span class="text-3xl">⚠️</span>
            </div>
            <h3 class="text-xl font-black text-gray-800 mb-2">Leave Payment Page?</h3>
            <p class="text-sm text-gray-500 leading-relaxed mb-2">You are currently on the payment page.</p>
            <p class="text-xs text-gray-400 leading-relaxed mb-6">If you leave without completing payment, you will need to <strong>wait 5 minutes</strong> before placing another order. Your voucher will be restored automatically after 5 minutes.</p>
            <div class="flex gap-3">
                <button onclick="document.getElementById('leaveWarningModal').classList.add('hidden')"
                        class="flex-1 py-3 bg-red-600 hover:bg-red-700 text-white rounded-xl text-sm font-semibold transition-colors">
                    Stay & Pay
                </button>
                <button onclick="proceedLeave()"
                        class="flex-1 py-3 border-2 border-gray-100 rounded-xl text-sm font-semibold text-gray-600 hover:bg-gray-50 transition-colors">
                    Leave Anyway
                </button>
            </div>
        </div>
    </div>

    <!-- Timeout Modal -->
    <div id="timeoutModal" class="hidden fixed inset-0 bg-black/60 z-50 flex items-center justify-center px-6">
        <div class="bg-white rounded-3xl p-8 max-w-sm w-full shadow-2xl text-center">
            <div class="w-16 h-16 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-4">
                <span class="text-3xl">⏰</span>
            </div>
            <h3 class="text-xl font-black text-gray-800 mb-2">Payment Time Expired</h3>
            <p class="text-sm text-gray-500 mb-2">Your 5-minute payment window has ended.</p>
            <p class="text-xs text-gray-400 mb-6">Your order has been cancelled and your voucher (if any) will be restored automatically.</p>
            <a href="home.php" onclick="paymentSubmitted=true;"
                class="block w-full py-3 bg-red-600 hover:bg-red-700 text-white rounded-xl text-sm font-semibold transition-colors">
                Back to Home
            </a>
        </div>
    </div>

    <script>
    const gatewayLockedAt = <?= $lock_locked_at ?> * 1000;
    const gatewayTotalMs = 300 * 1000;
    let gatewayTimer = null;

    function startGatewayTimer() {
        if (!gatewayLockedAt) return;

        function update() {
            const elapsed = Date.now() - gatewayLockedAt;
            const rem = Math.max(0, Math.floor((gatewayTotalMs - elapsed) / 1000));
            const mins = Math.floor(rem / 60).toString().padStart(2, '0');
            const secs = (rem % 60).toString().padStart(2, '0');

            const countdownEl = document.getElementById('gatewayCountdown');
            const progressEl = document.getElementById('timerProgress');

            if (countdownEl) countdownEl.textContent = mins + ':' + secs;
            if (progressEl) {
                const pct = Math.max(0, ((gatewayTotalMs - elapsed) / gatewayTotalMs) * 100);
                progressEl.style.width = pct + '%';
                if (rem <= 60) {
                    progressEl.classList.replace('bg-yellow-500', 'bg-red-500');
                    if (countdownEl) {
                        countdownEl.classList.replace('text-yellow-700', 'text-red-600');
                    }
                }
            }

            if (rem <= 0) {
                clearInterval(gatewayTimer);
                paymentSubmitted = true;
                // Call backend to cancel order + send notification
                fetch('cancel_expired_order.php', { method: 'POST' })
                    .finally(() => {
                        document.getElementById('timeoutModal').classList.remove('hidden');
                    });
            }
        }

        update();
        gatewayTimer = setInterval(update, 1000);
    }

    startGatewayTimer();
    const savedMethodsData = <?= json_encode(array_map(function($sm) use ($ewallet_gradients) {
        return [
            'id' => $sm['pm_id'],
            'type' => $sm['pm_type'],
            'label' => $sm['pm_label'],
            'last_four' => $sm['pm_last_four'] ?? '',
            'expiry' => $sm['pm_expiry'] ?? '',
            'holder_name' => $sm['pm_holder_name'] ?? '',
            'ewallet_name' => $sm['pm_ewallet_name'] ?? '',
            'phone' => $sm['pm_phone'] ?? '',
            'is_default' => $sm['pm_is_default'],
            'gradient' => $sm['pm_type'] === 'ewallet' ? ($ewallet_gradients[$sm['pm_ewallet_name']] ?? 'linear-gradient(135deg, #f97316, #ef4444)') : 'linear-gradient(135deg, #1e2d4a, #2c3e6b)',
        ];
    }, $saved_methods)) ?>;

    let currentMethod = null;
    let currentSavedId = null;

    function selectSavedMethod(btn, pmId, pmType, pmLabel, ewalletName) {
        pmId = parseInt(pmId);
        document.querySelectorAll('.saved-method-btn').forEach(b => b.classList.remove('selected'));
        ['card','banking','ewallet'].forEach(m => document.getElementById('btn-' + m).classList.remove('selected'));
        btn.classList.add('selected');
        currentSavedId = pmId;
        document.getElementById('paymentMethodInput').value = 'saved_' + pmId;
        document.getElementById('defaultState').style.display = 'none';

        const data = savedMethodsData.find(s => String(s.id) === String(pmId));
        if (!data) return;

        const card = document.getElementById('savedMethodCard');
        card.style.background = data.gradient;
        document.getElementById('savedMethodLabel').textContent = data.label;

        if (data.type === 'card') {
            document.getElementById('savedMethodIcon').textContent = '💳';
            document.getElementById('savedMethodSub').textContent = '•••• •••• •••• ' + data.last_four + ' · Exp ' + data.expiry;
            document.getElementById('savedCardFields').classList.remove('hidden');
            document.getElementById('savedEwalletFields').classList.add('hidden');
            document.getElementById('savedCardDisplay').textContent = '•••• •••• •••• ' + data.last_four;
            document.getElementById('savedCardHolder').textContent = data.holder_name;
        } else {
            document.getElementById('savedMethodIcon').textContent = '📱';
            document.getElementById('savedMethodSub').textContent = data.ewallet_name + ' · +60' + data.phone;
            document.getElementById('savedCardFields').classList.add('hidden');
            document.getElementById('savedEwalletFields').classList.remove('hidden');
            document.getElementById('savedEwalletName').textContent = data.ewallet_name;
            document.getElementById('savedEwalletPhone').textContent = '+60 ' + data.phone;
            document.getElementById('savedEwalletBanner').style.background = data.gradient;
            document.querySelectorAll('.saved-pin').forEach(p => { p.value = ''; p.classList.remove('filled'); });
        }

        const savedCvv = document.getElementById('savedCvv');
        if (savedCvv) savedCvv.value = '';

        const prev = document.querySelector('.payment-section.active');
        if (prev) {
            prev.style.transition = 'opacity 0.2s ease, transform 0.2s ease';
            prev.style.opacity = '0';
            prev.style.transform = 'translateY(-8px)';
            setTimeout(() => {
                prev.style.cssText = '';
                prev.classList.remove('active');
                document.getElementById('section-saved').classList.add('active');
            }, 200);
        } else {
            document.getElementById('section-saved').classList.add('active');
        }
        currentMethod = 'saved';
        setTimeout(() => {
            document.getElementById('section-saved').scrollIntoView({ behavior: 'smooth', block: 'start' });
        }, 350);
    }

    function selectMethod(method) {
        document.querySelectorAll('.saved-method-btn').forEach(b => b.classList.remove('selected'));
        ['card','banking','ewallet'].forEach(m => document.getElementById('btn-' + m).classList.remove('selected'));
        document.getElementById('btn-' + method).classList.add('selected');
        document.getElementById('paymentMethodInput').value = method;
        document.getElementById('defaultState').style.display = 'none';
        currentSavedId = null;

        const prev = document.querySelector('.payment-section.active');
        if (prev && currentMethod !== method) {
            prev.style.transition = 'opacity 0.2s ease, transform 0.2s ease';
            prev.style.opacity = '0';
            prev.style.transform = 'translateY(-8px)';
            setTimeout(() => {
                prev.style.cssText = '';
                prev.classList.remove('active');
                showSection(method);
            }, 200);
        } else if (!prev) {
            showSection(method);
        } else {
            showSection(method);
        }
        currentMethod = method;
    }

    function showSection(method) {
        const section = document.getElementById('section-' + method);
        section.classList.add('active');
        setTimeout(() => { section.scrollIntoView({ behavior: 'smooth', block: 'start' }); }, 100);
    }

    function formatCard(input) {
        let val = input.value.replace(/[^0-9]/g, '');
        val = val.match(/.{1,4}/g)?.join(' ') || val;
        input.value = val;
        const display = val.replace(/\s/g, '').padEnd(16, '•');
        document.getElementById('cardPreview').textContent = display.match(/.{1,4}/g)?.join(' ') || '•••• •••• •••• ••••';
    }

    function formatExpiry(input) {
        let val = input.value.replace(/[^0-9]/g, '');
        if (val.length >= 2) val = val.substring(0,2) + '/' + val.substring(2);
        input.value = val;
        document.getElementById('expiryPreview').textContent = val || 'MM/YY';
    }

    function selectBank(btn, bankName, gradient, shortName) {
        document.getElementById('bankError').classList.add('hidden');
        document.getElementById('bankBannerShort').textContent = shortName;
        document.getElementById('bankBanner').style.background = gradient;
        document.getElementById('bankLoginName').textContent = bankName;

        const bankListDiv = document.getElementById('bankListDiv');
        const loginDiv = document.getElementById('bankLoginDiv');
        bankListDiv.style.transition = 'opacity 0.2s ease, transform 0.2s ease';
        bankListDiv.style.opacity = '0';
        bankListDiv.style.transform = 'translateX(-15px)';
        setTimeout(() => {
            bankListDiv.style.display = 'none';
            loginDiv.classList.remove('hidden');
            loginDiv.style.opacity = '0';
            loginDiv.style.transform = 'translateX(15px)';
            loginDiv.style.transition = 'opacity 0.3s cubic-bezier(0.16, 1, 0.3, 1), transform 0.3s cubic-bezier(0.16, 1, 0.3, 1)';
            requestAnimationFrame(() => requestAnimationFrame(() => {
                loginDiv.style.opacity = '1';
                loginDiv.style.transform = 'translateX(0)';
            }));
        }, 200);
    }

    function resetBankSelection() {
        const loginDiv = document.getElementById('bankLoginDiv');
        const bankListDiv = document.getElementById('bankListDiv');
        loginDiv.style.transition = 'opacity 0.2s ease, transform 0.2s ease';
        loginDiv.style.opacity = '0';
        loginDiv.style.transform = 'translateX(15px)';
        setTimeout(() => {
            loginDiv.classList.add('hidden');
            loginDiv.style.cssText = '';
            document.getElementById('bankUsername').value = '';
            document.getElementById('bankPassword').value = '';
            bankListDiv.style.display = '';
            bankListDiv.style.opacity = '0';
            bankListDiv.style.transform = 'translateX(-15px)';
            bankListDiv.style.transition = 'opacity 0.3s cubic-bezier(0.16, 1, 0.3, 1), transform 0.3s cubic-bezier(0.16, 1, 0.3, 1)';
            requestAnimationFrame(() => requestAnimationFrame(() => {
                bankListDiv.style.opacity = '1';
                bankListDiv.style.transform = 'translateX(0)';
            }));
        }, 200);
    }

    function selectWallet(btn, walletName, gradient, emoji) {
        document.getElementById('ewalletPhone').value = '';
        document.querySelectorAll('.new-pin').forEach(p => { p.value = ''; p.classList.remove('filled'); });

        const selectDiv = document.getElementById('walletSelectDiv');
        const payDiv = document.getElementById('walletPayDiv');
        selectDiv.style.transition = 'opacity 0.2s ease, transform 0.2s ease';
        selectDiv.style.opacity = '0';
        selectDiv.style.transform = 'translateX(-15px)';
        setTimeout(() => {
            selectDiv.style.display = 'none';
            document.getElementById('walletBannerName').textContent = walletName;
            document.getElementById('walletBannerEmoji').textContent = emoji;
            document.getElementById('walletBanner').style.background = gradient;
            document.getElementById('walletPayBtn').style.background = gradient;
            payDiv.classList.remove('hidden');
            payDiv.style.opacity = '0';
            payDiv.style.transform = 'translateX(15px)';
            payDiv.style.transition = 'opacity 0.3s cubic-bezier(0.16, 1, 0.3, 1), transform 0.3s cubic-bezier(0.16, 1, 0.3, 1)';
            requestAnimationFrame(() => requestAnimationFrame(() => {
                payDiv.style.opacity = '1';
                payDiv.style.transform = 'translateX(0)';
            }));
        }, 200);
    }

    function backToWalletSelect() {
        const selectDiv = document.getElementById('walletSelectDiv');
        const payDiv = document.getElementById('walletPayDiv');
        payDiv.style.transition = 'opacity 0.2s ease, transform 0.2s ease';
        payDiv.style.opacity = '0';
        payDiv.style.transform = 'translateX(15px)';
        setTimeout(() => {
            payDiv.classList.add('hidden');
            payDiv.style.cssText = '';
            document.getElementById('ewalletPhone').value = '';
            document.querySelectorAll('.new-pin').forEach(p => { p.value = ''; p.classList.remove('filled'); });
            selectDiv.style.display = '';
            selectDiv.style.opacity = '0';
            selectDiv.style.transform = 'translateX(-15px)';
            selectDiv.style.transition = 'opacity 0.3s cubic-bezier(0.16, 1, 0.3, 1), transform 0.3s cubic-bezier(0.16, 1, 0.3, 1)';
            requestAnimationFrame(() => requestAnimationFrame(() => {
                selectDiv.style.opacity = '1';
                selectDiv.style.transform = 'translateX(0)';
            }));
        }, 200);
    }

    function showFieldError(fieldId, message) {
        const field = document.getElementById(fieldId);
        if (!field) return;
        field.classList.add('border-red-400');
        field.focus();
        const existingErr = field.parentNode.querySelector('.field-error');
        if (existingErr) existingErr.remove();
        const err = document.createElement('p');
        err.className = 'field-error text-red-500 text-xs mt-1';
        err.textContent = message;
        field.parentNode.appendChild(err);
        field.addEventListener('input', function() {
            field.classList.remove('border-red-400');
            const e = field.parentNode.querySelector('.field-error');
            if (e) e.remove();
        }, { once: true });
    }

    function validateSaved() {
        const data = savedMethodsData.find(s => String(s.id) === String(currentSavedId));
        if (!data) return;
        if (data.type === 'card') {
            const cvv = document.getElementById('savedCvv').value.trim();
            if (cvv.length !== 3) { showFieldError('savedCvv', 'Please enter your 3-digit CVV.'); return; }
        } else {
            const pins = document.querySelectorAll('.saved-pin');
            let pin = '';
            pins.forEach(p => pin += p.value);
            if (pin.length !== 6) { alert('Please enter your complete 6-digit PIN.'); return; }
        }
        showSecurityModal();
    }

    function validateCard() {
        const cardNum = document.getElementById('cardNumber').value.replace(/\s/g, '');
        const cardName = document.getElementById('cardName').value.trim();
        const expiry = document.getElementById('expiry').value.trim();
        const cvv = document.getElementById('cvvInput').value.trim();
        if (cardNum.length !== 16) { showFieldError('cardNumber', 'Card number must be exactly 16 digits.'); return; }
        if (cardName.length < 3) { showFieldError('cardName', 'Please enter your full name as shown on card.'); return; }
        if (!/^\d{2}\/\d{2}$/.test(expiry)) { showFieldError('expiry', 'Invalid format. Use MM/YY.'); return; }
        const [month, year] = expiry.split('/');
        if (parseInt(month) < 1 || parseInt(month) > 12) { showFieldError('expiry', 'Invalid month.'); return; }
        if (new Date(2000 + parseInt(year), parseInt(month) - 1) < new Date()) { showFieldError('expiry', 'This card has expired.'); return; }
        if (cvv.length !== 3) { showFieldError('cvvInput', 'CVV must be 3 digits.'); return; }
        showSecurityModal();
    }

    function validateBanking() {
        if (document.getElementById('bankLoginDiv').classList.contains('hidden')) {
            document.getElementById('bankError').classList.remove('hidden');
            return;
        }
        const username = document.getElementById('bankUsername').value.trim();
        const password = document.getElementById('bankPassword').value.trim();
        if (!username) { showFieldError('bankUsername', 'Please enter your username.'); return; }
        if (!password) { showFieldError('bankPassword', 'Please enter your password.'); return; }
        showSecurityModal();
    }

    function validateEwallet() {
        const phone = document.getElementById('ewalletPhone').value.trim();
        if (phone.length < 9) { showFieldError('ewalletPhone', 'Please enter a valid phone number.'); return; }
        const pins = document.querySelectorAll('.new-pin');
        let pin = '';
        pins.forEach(p => pin += p.value);
        if (pin.length !== 6) { alert('Please enter your complete 6-digit PIN.'); return; }
        showSecurityModal();
    }

    function showSecurityModal() {
        document.getElementById('securityModal').classList.remove('hidden');
        document.getElementById('confirmPayBtn').onclick = function() {
            document.getElementById('securityModal').classList.add('hidden');
            showProcessing();
        };
        document.getElementById('cancelPayBtn').onclick = function() {
            document.getElementById('securityModal').classList.add('hidden');
        };
    }

    function getPaymentMethodLabel() {
        const method = document.getElementById('paymentMethodInput').value;
        if (method === 'card') return 'Credit / Debit Card';
        if (method === 'banking') {
            const bankName = document.getElementById('bankLoginName')?.textContent;
            return bankName ? 'Online Banking — ' + bankName : 'Online Banking';
        }
        if (method === 'ewallet') {
            const walletName = document.getElementById('walletBannerName')?.textContent;
            return walletName ? 'E-Wallet — ' + walletName : 'E-Wallet';
        }
        if (method.startsWith('saved_')) {
            const label = document.getElementById('savedMethodLabel')?.textContent;
            return label || 'Saved Payment Method';
        }
        return method;
    }

    function showProcessing() {
        document.getElementById('processingOverlay').classList.remove('hidden');
        const messages = ['Verifying your payment...', 'Connecting to payment server...', 'Confirming transaction...', 'Almost done...'];
        let i = 0;
        const msgEl = document.getElementById('processingMsg');
        const interval = setInterval(() => {
            i++;
            if (i < messages.length) {
                msgEl.style.opacity = '0';
                setTimeout(() => { msgEl.textContent = messages[i]; msgEl.style.opacity = '1'; }, 200);
            }
        }, 800);
        setTimeout(() => {
            clearInterval(interval);
            // Set detailed payment method before submit
            document.getElementById('paymentMethodInput').value = getPaymentMethodLabel();
            document.getElementById('paymentForm').submit();
        }, 3500);
    }

    function movePIN(input, index, type) {
        input.value = input.value.replace(/[^0-9]/g, '');
        if (input.value) {
            input.classList.add('filled');
            const selector = type === 'saved' ? '.saved-pin' : '.new-pin';
            const inputs = document.querySelectorAll(selector);
            if (index < 5) inputs[index + 1].focus();
        } else {
            input.classList.remove('filled');
        }
    }

    function backspacePIN(event, index, type) {
        if (event.key === 'Backspace') {
            const selector = type === 'saved' ? '.saved-pin' : '.new-pin';
            const inputs = document.querySelectorAll(selector);
            inputs[index].classList.remove('filled');
            if (inputs[index].value === '' && index > 0) {
                inputs[index - 1].focus();
                inputs[index - 1].value = '';
                inputs[index - 1].classList.remove('filled');
            }
        }
    }
    function cancelAndGoBack() {
        fetch('cancel_pending_voucher.php', { method: 'POST' })
            .finally(() => { window.location.href = 'checkout.php'; });
    }

    // Warn before leaving payment page
    let paymentSubmitted = false;

    document.getElementById('paymentForm').addEventListener('submit', function() {
        paymentSubmitted = true;
    });

    // Intercept all links and back button
    document.addEventListener('click', function(e) {
        const link = e.target.closest('a');
        if (link && !paymentSubmitted) {
            e.preventDefault();
            document.getElementById('leaveWarningModal').classList.remove('hidden');
            document.getElementById('leaveWarningModal').dataset.href = link.href;
        }
    });

    window.addEventListener('beforeunload', function(e) {
        if (!paymentSubmitted) {
            e.preventDefault();
            e.returnValue = '';
        }
    });

    function proceedLeave() {
        paymentSubmitted = true;
        const href = document.getElementById('leaveWarningModal').dataset.href;
        fetch('cancel_pending_voucher.php', { method: 'POST' })
            .finally(() => {
                // Keep payment lock active — don't clear it
                if (href) {
                    window.location.href = href;
                } else {
                    window.location.href = 'checkout.php';
                }
            });
    }
    </script>

</body>
</html>