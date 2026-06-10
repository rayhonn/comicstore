<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require_once '../vendor/autoload.php';

session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'customer') {
    header('Location: ../login.php');
    exit;
}
require_once '../includes/db.php';

$user_id = $_SESSION['user_id'];
// Auto-restore vouchers from expired orders
$expired_orders = $pdo->prepare("
    SELECT o.order_id, o.order_voucher_code 
    FROM orders o
    WHERE o.order_user_id = ?
    AND o.order_payment_status = 'pending_confirmation'
    AND o.order_confirm_expires_at < NOW()
");
$expired_orders->execute([$user_id]);
foreach ($expired_orders->fetchAll(PDO::FETCH_ASSOC) as $exp) {
    $pdo->prepare("UPDATE orders SET order_payment_status = 'cancelled', order_status = 'cancelled' WHERE order_id = ?")
        ->execute([$exp['order_id']]);
    $exp_items = $pdo->prepare("SELECT * FROM order_items WHERE order_item_order_id = ?");
    $exp_items->execute([$exp['order_id']]);
    foreach ($exp_items->fetchAll(PDO::FETCH_ASSOC) as $item) {
        if ($item['order_item_type'] === 'physical') {
            $pdo->prepare("UPDATE product_physical SET physical_stock_quantity = physical_stock_quantity + ? WHERE physical_product_id = ?")
                ->execute([$item['order_item_quantity'], $item['order_item_product_id']]);
        }
    }
    if (!empty($exp['order_voucher_code'])) {
        $pdo->prepare("DELETE FROM voucher_usage WHERE usage_order_id = ?")
            ->execute([$exp['order_id']]);
        $pdo->prepare("UPDATE vouchers SET voucher_used_count = GREATEST(0, voucher_used_count - 1) WHERE voucher_code = ?")
            ->execute([$exp['order_voucher_code']]);
        $vv = $pdo->prepare("SELECT voucher_id FROM vouchers WHERE voucher_code = ?");
        $vv->execute([$exp['order_voucher_code']]);
        $vv = $vv->fetch(PDO::FETCH_ASSOC);
        if ($vv) {
            $pdo->prepare("UPDATE user_vouchers SET uv_status = 'available', uv_is_used = 0, uv_used_at = NULL WHERE uv_voucher_id = ? AND uv_user_id = ?")
                ->execute([$vv['voucher_id'], $user_id]);
        }
    }
}
// Get vouchers that are pending for more than 5 minutes
$stuck_vouchers = $pdo->prepare("
    SELECT uv.*, v.voucher_code FROM user_vouchers uv
    JOIN vouchers v ON uv.uv_voucher_id = v.voucher_id
    WHERE uv.uv_user_id = ?
    AND uv.uv_status = 'pending'
    AND uv.uv_is_used = 0
    AND uv.uv_pending_at IS NOT NULL
    AND uv.uv_pending_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)
");
$stuck_vouchers->execute([$user_id]);
$stuck_vouchers = $stuck_vouchers->fetchAll(PDO::FETCH_ASSOC);

if (!empty($stuck_vouchers)) {
    // Restore vouchers
    $pdo->prepare("
        UPDATE user_vouchers 
        SET uv_status = 'available', uv_pending_at = NULL
        WHERE uv_user_id = ?
        AND uv_status = 'pending'
        AND uv_is_used = 0
        AND uv_pending_at IS NOT NULL
        AND uv_pending_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)
    ")->execute([$user_id]);

    // Send notification
    require_once '../includes/notifications.php';
    sendNotification($pdo, $user_id,
        '⏰ Payment Timeout',
        'Your recent order has been cancelled due to payment timeout. Your voucher has been restored and you can place a new order now.',
        'order'
    );

    // Send email
    require_once '../includes/mail_config.php';
    $user_info_mail = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
    $user_info_mail->execute([$user_id]);
    $user_info_mail = $user_info_mail->fetch(PDO::FETCH_ASSOC);

    if ($user_info_mail) {
        $first_name = htmlspecialchars($user_info_mail['user_first_name']);
        $cancel_email_body = "
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
                    <p style='color:#374151; font-size:15px; margin:0 0 24px 0;'>Hi <strong>$first_name</strong>, your order has been cancelled because payment was not completed within 5 minutes.</p>
                    <div style='background:#f9fafb; border-radius:12px; padding:16px; margin-bottom:24px;'>
                        <p style='color:#6b7280; font-size:13px; margin:0 0 8px 0;'>✅ Stock has been restored</p>
                        <p style='color:#6b7280; font-size:13px; margin:0 0 8px 0;'>✅ Your voucher has been restored</p>
                        <p style='color:#6b7280; font-size:13px; margin:0;'>✅ You can place a new order now</p>
                    </div>
                    <div style='text-align:center;'>
                        <a href='http://localhost/comicstore/customer/home.php'
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
            $timeout_mail = new PHPMailer(true);
            $timeout_mail->isSMTP();
            $timeout_mail->Host = MAIL_HOST;
            $timeout_mail->SMTPAuth = true;
            $timeout_mail->Username = MAIL_USERNAME;
            $timeout_mail->Password = MAIL_PASSWORD;
            $timeout_mail->SMTPSecure = 'tls';
            $timeout_mail->Port = MAIL_PORT;
            $timeout_mail->setFrom(MAIL_USERNAME, MAIL_FROM_NAME);
            $timeout_mail->CharSet = 'UTF-8';
            $timeout_mail->addAddress($user_info_mail['user_gmail'], $user_info_mail['user_first_name'] . ' ' . $user_info_mail['user_last_name']);
            $timeout_mail->Subject = "Order Cancelled - Payment Timeout - MangaVault";
            $timeout_mail->isHTML(true);
            $timeout_mail->Body = $cancel_email_body;
            $timeout_mail->send();
        } catch (Exception $e) {
            // Silent fail
        }
    }
}

// Get user points
$user = $pdo->prepare("SELECT user_points, user_first_name FROM users WHERE user_id = ?");
$user->execute([$user_id]);
$user = $user->fetch(PDO::FETCH_ASSOC);
$user_points = $user['user_points'] ?? 0;

$success = '';
$error = '';

// Handle redeem points voucher
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['redeem_voucher'])) {
    $voucher_id = intval($_POST['voucher_id']);

    $v = $pdo->prepare("SELECT * FROM vouchers WHERE voucher_id = ? AND voucher_is_active = 1 AND voucher_is_points_redeem = 1");
    $v->execute([$voucher_id]);
    $v = $v->fetch(PDO::FETCH_ASSOC);

    if (!$v) {
        $error = 'Voucher not found.';
    } elseif ($user_points < $v['voucher_points_required']) {
        $error = 'Insufficient points.';
    } else {
        // Check already claimed
        $claimed = $pdo->prepare("SELECT uv_id FROM user_vouchers WHERE uv_user_id = ? AND uv_voucher_id = ?");
        $claimed->execute([$user_id, $voucher_id]);
        if ($claimed->fetch()) {
            $error = 'You have already claimed this voucher.';
        } else {
            // Deduct points
            $pdo->prepare("UPDATE users SET user_points = user_points - ? WHERE user_id = ?")->execute([$v['voucher_points_required'], $user_id]);

            // Add to user vouchers
            $pdo->prepare("INSERT INTO user_vouchers (uv_user_id, uv_voucher_id) VALUES (?, ?)")->execute([$user_id, $voucher_id]);

            // Log points
            $pdo->prepare("INSERT INTO points_log (log_user_id, log_points, log_type, log_description) VALUES (?, ?, 'redeem', ?)")
                ->execute([$user_id, -$v['voucher_points_required'], "Redeemed voucher: {$v['voucher_code']}"]);

            $user_points -= $v['voucher_points_required'];
            $success = "Voucher {$v['voucher_code']} claimed! Use it at checkout.";
        }
    }
}

// Get available points vouchers
$points_vouchers = $pdo->query("
    SELECT v.*, 
    (SELECT uv_id FROM user_vouchers WHERE uv_user_id = $user_id AND uv_voucher_id = v.voucher_id) as already_claimed
    FROM vouchers v
    WHERE v.voucher_is_active = 1 
    AND v.voucher_is_points_redeem = 1
    AND (v.voucher_end_date IS NULL OR v.voucher_end_date >= NOW())
    ORDER BY v.voucher_points_required ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Get my vouchers — points redeemed + used promo vouchers
$my_vouchers = $pdo->prepare("
    SELECT v.*, uv.uv_claimed_at, uv.uv_is_used, uv.uv_used_at, uv.uv_expires_at, uv.uv_status
    FROM user_vouchers uv
    JOIN vouchers v ON uv.uv_voucher_id = v.voucher_id
    WHERE uv.uv_user_id = ?

    UNION

    SELECT v.*, vu.usage_created_at as uv_claimed_at, 1 as uv_is_used, vu.usage_created_at as uv_used_at, NULL as uv_expires_at, 'used' as uv_status
    FROM voucher_usage vu
    JOIN vouchers v ON vu.usage_voucher_id = v.voucher_id
    WHERE vu.usage_user_id = ?
    AND NOT EXISTS (
        SELECT 1 FROM user_vouchers uv2 
        WHERE uv2.uv_user_id = ? AND uv2.uv_voucher_id = v.voucher_id
    )

    ORDER BY uv_claimed_at DESC
");
$my_vouchers->execute([$user_id, $user_id, $user_id]);
$my_vouchers = $my_vouchers->fetchAll(PDO::FETCH_ASSOC);

// // Get available promo vouchers (non-points, not yet used by user)
// $promo_vouchers = $pdo->query("
//     SELECT v.*
//     FROM vouchers v
//     WHERE v.voucher_is_active = 1
//     AND v.voucher_is_points_redeem = 0
//     AND (v.voucher_end_date IS NULL OR v.voucher_end_date >= NOW())
//     AND (v.voucher_usage_limit IS NULL OR v.voucher_used_count < v.voucher_usage_limit)
//     AND NOT EXISTS (
//         SELECT 1 FROM voucher_usage vu 
//         WHERE vu.usage_voucher_id = v.voucher_id AND vu.usage_user_id = $user_id
//     )
//     ORDER BY v.voucher_created_at DESC
// ")->fetchAll(PDO::FETCH_ASSOC);

// Points history
$points_history = $pdo->prepare("
    SELECT * FROM points_log WHERE log_user_id = ? ORDER BY log_created_at DESC LIMIT 20
");
$points_history->execute([$user_id]);
$points_history = $points_history->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vouchers & Points - MangaVault</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { opacity: 0; animation: fadeIn 0.4s ease forwards; }
        @keyframes fadeIn { to { opacity: 1; } }

        .voucher-card {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            position: relative;
        }
        .voucher-card::before {
            content: '';
            position: absolute;
            left: -12px;
            top: 50%;
            transform: translateY(-50%);
            width: 24px;
            height: 24px;
            background: #F5F0EB;
            border-radius: 50%;
        }
        .voucher-card::after {
            content: '';
            position: absolute;
            right: -12px;
            top: 50%;
            transform: translateY(-50%);
            width: 24px;
            height: 24px;
            background: #F5F0EB;
            border-radius: 50%;
        }
        .voucher-divider {
            border-left: 2px dashed #e5e7eb;
        }
    </style>
</head>
<body class="bg-[#F5F0EB] min-h-screen">

    <?php include '../includes/customer_navbar.php'; ?>

    <div class="max-w-7xl mx-auto px-6 py-8">
        <p class="text-sm text-gray-400 mb-6">
            <a href="../index.php" class="hover:text-red-600 transition-colors">Home</a>
            <span class="mx-2">›</span>
            <a href="dashboard.php" class="hover:text-red-600 transition-colors">My Account</a>
            <span class="mx-2">›</span>
            <span class="text-gray-600">Vouchers & Points</span>
        </p>

        <div class="flex gap-8 items-start">
            <?php include '../includes/customer_sidebar.php'; ?>

            <div class="flex-1 min-w-0 space-y-6">

                <?php if ($success): ?>
                <div class="bg-green-50 border border-green-200 text-green-700 text-sm px-4 py-3 rounded-xl">✅ <?= htmlspecialchars($success) ?></div>
                <?php endif; ?>
                <?php if ($error): ?>
                <div class="bg-red-50 border border-red-200 text-red-600 text-sm px-4 py-3 rounded-xl">❌ <?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <!-- Points Card -->
                <div class="bg-gradient-to-r from-[#1e2d4a] to-[#2c3e6b] rounded-2xl p-6 text-white">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-white/60 text-sm mb-1">Your Points Balance</p>
                            <p class="text-5xl font-black"><?= number_format($user_points) ?></p>
                            <p class="text-white/50 text-xs mt-1">RM1 spent = 1 point</p>
                        </div>
                        <div class="text-right">
                            <div class="text-6xl">⭐</div>
                            <p class="text-white/50 text-xs mt-2">Earn points with every purchase</p>
                        </div>
                    </div>

                    <?php if (!empty($points_history)): ?>
                    <div class="mt-5 pt-5 border-t border-white/20">
                        <div class="flex justify-between items-center mb-3">
                            <p class="text-white/60 text-xs font-semibold uppercase tracking-wide">Recent Points Activity</p>
                            <?php if (count($points_history) > 3): ?>
                            <button onclick="togglePointsHistory()" id="pointsToggleBtn"
                                    class="text-white/50 hover:text-white text-xs transition-colors">
                                View All ↓
                            </button>
                            <?php endif; ?>
                        </div>
                        <div class="space-y-2" id="pointsHistoryShort">
                            <?php foreach (array_slice($points_history, 0, 3) as $log): ?>
                            <div class="flex justify-between items-center">
                                <p class="text-white/80 text-xs"><?= htmlspecialchars($log['log_description']) ?></p>
                                    <span class="text-xs font-bold <?= $log['log_points'] > 0 ? 'text-green-400' : 'text-red-400' ?>">
                                        <?= $log['log_points'] > 0 ? '+' : '' ?><?= $log['log_points'] ?> pts
                                    </span>
                            </div>
                            <?php endforeach; ?>
                        </div>

                        <?php if (count($points_history) > 3): ?>
                        <div class="space-y-2 hidden" id="pointsHistoryFull">
                            <?php foreach ($points_history as $log): ?>
                            <div class="flex justify-between items-center">
                                <div class="flex-1 min-w-0 mr-3">
                                    <p class="text-white/80 text-xs truncate"><?= htmlspecialchars($log['log_description']) ?></p>
                                    <p class="text-white/40 text-xs"><?= date('d M Y', strtotime($log['log_created_at'])) ?></p>
                                </div>
                                <span class="text-xs font-bold flex-shrink-0 <?= $log['log_points'] > 0 ? 'text-green-400' : 'text-red-400' ?>">
                                    <?= $log['log_points'] > 0 ? '+' : '' ?><?= $log['log_points'] ?> pts
                                </span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <?php
                $available_count = count(array_filter($my_vouchers, function($v) {
                    $uv_expires = !empty($v['uv_expires_at']) ? strtotime($v['uv_expires_at']) : null;
                    $is_expired = ($v['voucher_end_date'] && strtotime($v['voucher_end_date']) < time())
                        || ($uv_expires && $uv_expires < time());
                    $is_pending = ($v['uv_status'] ?? 'available') === 'pending';
                    return !$v['uv_is_used'] && !$is_expired && !$is_pending;
                }));
                ?>
                <div class="flex gap-1 bg-white rounded-2xl shadow-sm p-1 w-fit">
                    <button onclick="switchTab('points')" id="tab-points"
                            class="px-5 py-2 rounded-xl text-sm font-semibold transition-colors bg-red-600 text-white">
                        ⭐ Redeem Points
                    </button>
                    <button onclick="switchTab('myvouchers')" id="tab-myvouchers"
                            class="px-5 py-2 rounded-xl text-sm font-semibold transition-colors text-gray-500 hover:text-red-600">
                        💼 My Vouchers <?= $available_count > 0 ? "($available_count)" : '' ?>
                    </button>
                </div>

                <!-- Points Redeem Tab -->
                <div id="content-points">
                    <div class="bg-blue-50 border border-blue-200 rounded-xl p-4 mb-5 flex items-center gap-3">
                        <span class="text-2xl">⭐</span>
                        <div>
                            <p class="font-semibold text-blue-800 text-sm">You have <span class="text-blue-600 font-black"><?= number_format($user_points) ?> points</span></p>
                            <p class="text-xs text-blue-600">Redeem your points for exclusive vouchers!</p>
                        </div>
                    </div>

                    <?php if (empty($points_vouchers)): ?>
                    <div class="bg-white rounded-2xl shadow-sm p-12 text-center">
                        <div class="text-5xl mb-4">⭐</div>
                        <p class="text-gray-500">No points vouchers available</p>
                    </div>
                    <?php else: ?>
                    <div class="space-y-4">
                        <?php foreach ($points_vouchers as $v):
                            $can_redeem = $user_points >= $v['voucher_points_required'] && !$v['already_claimed'];
                            $already_claimed = $v['already_claimed'];
                        ?>
                        <div class="voucher-card shadow-sm mx-3 <?= !$can_redeem && !$already_claimed ? 'opacity-60' : '' ?>">
                            <div class="flex">
                                <div class="w-3 <?= $already_claimed ? 'bg-green-500' : ($can_redeem ? 'bg-yellow-500' : 'bg-gray-300') ?> flex-shrink-0"></div>
                                <div class="flex-1 flex items-stretch">
                                    <div class="flex-1 p-5">
                                        <div class="flex items-start gap-3 mb-2">
                                            <span class="text-2xl">⭐</span>
                                            <div>
                                                <span class="<?= $already_claimed ? 'bg-green-100 text-green-700' : ($can_redeem ? 'bg-yellow-100 text-yellow-700' : 'bg-gray-100 text-gray-500') ?> text-xs px-2 py-0.5 rounded-full font-semibold mb-1 inline-block">
                                                    <?= $already_claimed ? '✅ CLAIMED' : ($can_redeem ? '✨ REDEEMABLE' : '🔒 LOCKED') ?>
                                                </span>
                                                <h3 class="font-black text-gray-800 text-lg">
                                                    <?= $v['voucher_type'] === 'percentage' ? $v['voucher_value'] . '% OFF' : 'RM ' . number_format($v['voucher_value'], 2) . ' OFF' ?>
                                                </h3>
                                                <?php if ($v['voucher_max_discount']): ?>
                                                <p class="text-xs text-gray-400">Max discount: RM <?= number_format($v['voucher_max_discount'], 2) ?></p>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="flex items-center gap-4 text-xs text-gray-400 mt-2">
                                            <?php if ($v['voucher_min_order'] > 0): ?>
                                            <span>Min spend: RM <?= number_format($v['voucher_min_order'], 2) ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <?php if (!$can_redeem && !$already_claimed): ?>
                                        <div class="mt-2">
                                            <div class="bg-gray-200 rounded-full h-1.5 w-full">
                                                <div class="bg-yellow-500 h-1.5 rounded-full transition-all"
                                                     style="width: <?= min(100, ($user_points / $v['voucher_points_required']) * 100) ?>%"></div>
                                            </div>
                                            <p class="text-xs text-gray-400 mt-1"><?= number_format($user_points) ?> / <?= number_format($v['voucher_points_required']) ?> pts needed</p>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="voucher-divider w-px my-4"></div>
                                    <div class="w-40 flex flex-col items-center justify-center p-4 gap-2">
                                        <p class="text-xs text-gray-400 font-semibold uppercase tracking-wide">Required</p>
                                        <p class="font-black text-yellow-600 text-xl"><?= number_format($v['voucher_points_required']) ?></p>
                                        <p class="text-xs text-gray-400">points</p>
                                        <?php if ($already_claimed): ?>
                                        <span class="bg-green-100 text-green-700 text-xs font-semibold px-3 py-1.5 rounded-xl">Claimed ✓</span>
                                        <?php elseif ($can_redeem): ?>
                                        <form method="POST">
                                            <input type="hidden" name="redeem_voucher" value="1">
                                            <input type="hidden" name="voucher_id" value="<?= $v['voucher_id'] ?>">
                                            <button type="submit"
                                                    class="bg-yellow-500 hover:bg-yellow-600 text-white text-xs font-semibold px-4 py-2 rounded-xl transition-colors">
                                                Redeem ⭐
                                            </button>
                                        </form>
                                        <?php else: ?>
                                        <span class="bg-gray-100 text-gray-400 text-xs font-semibold px-3 py-1.5 rounded-xl">🔒 Locked</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- My Vouchers Tab -->
                <div id="content-myvouchers" class="hidden">
                    <?php if (empty($my_vouchers)): ?>
                    <div class="bg-white rounded-2xl shadow-sm p-12 text-center">
                        <div class="text-5xl mb-4">💼</div>
                        <p class="text-gray-500 font-medium">No vouchers yet</p>
                        <p class="text-gray-400 text-sm mt-1">Redeem points or use promo codes to get vouchers!</p>
                    </div>
                    <?php else: ?>
                    <div class="space-y-4">
                        <?php foreach ($my_vouchers as $v):
                            $uv_expires = !empty($v['uv_expires_at']) ? strtotime($v['uv_expires_at']) : null;
                            $is_expired = ($v['voucher_end_date'] && strtotime($v['voucher_end_date']) < time()) 
                                        || ($uv_expires && $uv_expires < time());
                            $is_used = $v['uv_is_used'];
                            $is_pending = ($v['uv_status'] ?? 'available') === 'pending';
                        ?>
                        <div class="voucher-card shadow-sm mx-3 <?= ($is_expired || $is_used || $is_pending) ? 'opacity-60' : '' ?>">
                            <div class="flex">
                                <div class="w-3 <?= $is_used ? 'bg-gray-400' : ($is_pending ? 'bg-yellow-400' : ($is_expired ? 'bg-gray-300' : 'bg-green-500')) ?> flex-shrink-0"></div>
                                <div class="flex-1 flex items-stretch">
                                    <div class="flex-1 p-5">
                                        <div class="flex items-start gap-2 mb-2">
                                            <div>
                                                <span class="<?= $is_used ? 'bg-gray-100 text-gray-500' : ($is_pending ? 'bg-yellow-100 text-yellow-700' : ($is_expired ? 'bg-red-100 text-red-500' : 'bg-green-100 text-green-700')) ?> text-xs px-2 py-0.5 rounded-full font-semibold mb-1 inline-block">
                                                    <?= $is_used ? 'USED' : ($is_pending ? '⏳ PENDING' : ($is_expired ? 'EXPIRED' : 'AVAILABLE')) ?>
                                                </span>
                                                <h3 class="font-black text-gray-800 text-lg">
                                                    <?= $v['voucher_type'] === 'percentage' ? $v['voucher_value'] . '% OFF' : 'RM ' . number_format($v['voucher_value'], 2) . ' OFF' ?>
                                                </h3>
                                                <?php if ($v['voucher_max_discount']): ?>
                                                <p class="text-xs text-gray-400">Max discount: RM <?= number_format($v['voucher_max_discount'], 2) ?></p>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="flex items-center gap-4 text-xs text-gray-400 mt-2 flex-wrap">
                                            <?php if ($v['voucher_min_order'] > 0): ?>
                                            <span>Min spend: RM <?= number_format($v['voucher_min_order'], 2) ?></span>
                                            <?php endif; ?>
                                            <span>Claimed: <?= date('d M Y', strtotime($v['uv_claimed_at'])) ?></span>
                                            <?php
                                            $expiry = null;
                                            if ($v['uv_expires_at'] && $v['voucher_end_date']) {
                                                $expiry = min(strtotime($v['uv_expires_at']), strtotime($v['voucher_end_date']));
                                            } elseif ($v['uv_expires_at']) {
                                                $expiry = strtotime($v['uv_expires_at']);
                                            } elseif ($v['voucher_end_date']) {
                                                $expiry = strtotime($v['voucher_end_date']);
                                            }
                                            ?>
                                            <?php if ($expiry): ?>
                                            <span class="<?= $expiry < time() ? 'text-red-500' : '' ?>">
                                                Valid until: <?= date('d M Y', $expiry) ?>
                                            </span>
                                            <?php else: ?>
                                            <span class="text-gray-400 text-xs">No expiry</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="voucher-divider w-px my-4"></div>
                                    <div class="w-40 flex flex-col items-center justify-center p-4 gap-2">
                                        <p class="text-xs text-gray-400 font-semibold uppercase tracking-wide">Code</p>
                                        <p class="font-mono font-black text-gray-800 text-sm bg-gray-50 px-3 py-1.5 rounded-lg border border-dashed border-gray-300">
                                            <?= htmlspecialchars($v['voucher_code']) ?>
                                        </p>
                                        <?php if (!$is_used && !$is_expired && !$is_pending): ?>
                                        <a href="checkout.php" class="bg-red-600 hover:bg-red-700 text-white text-xs font-semibold px-4 py-2 rounded-xl transition-colors">
                                            Use Now →
                                        </a>
                                        <?php elseif ($is_pending): ?>
                                        <span class="text-xs text-yellow-600 font-semibold text-center">⏳ In Use</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>

            </div>
        </div>
    </div>

    <script>
    function switchTab(tab) {
        ['points', 'myvouchers'].forEach(t => {
            document.getElementById('tab-' + t).className = 'px-5 py-2 rounded-xl text-sm font-semibold transition-colors ' +
                (t === tab ? 'bg-red-600 text-white' : 'text-gray-500 hover:text-red-600');
            document.getElementById('content-' + t).classList.toggle('hidden', t !== tab);
        });
    }

    function togglePointsHistory() {
        const short = document.getElementById('pointsHistoryShort');
        const full = document.getElementById('pointsHistoryFull');
        const btn = document.getElementById('pointsToggleBtn');
        const isExpanded = !full.classList.contains('hidden');
        if (isExpanded) {
            full.classList.add('hidden');
            short.classList.remove('hidden');
            btn.textContent = 'View All ↓';
        } else {
            full.classList.remove('hidden');
            short.classList.add('hidden');
            btn.textContent = 'Show Less ↑';
        }
    }
    </script>

</body>
</html>