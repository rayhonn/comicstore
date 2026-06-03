<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'customer') {
    header('Location: login.php');
    exit;
}
require_once '../includes/db.php';

$order_id = $_GET['order_id'] ?? null;
if (!$order_id) { header('Location: orders.php'); exit; }

$order = $pdo->prepare("
    SELECT o.*, 
    a.address_recipient_name, a.address_street, a.address_city,
    a.address_postal_code, a.address_country, a.address_phone
    FROM orders o
    LEFT JOIN addresses a ON o.order_address_id = a.address_id
    WHERE o.order_id = ? AND o.order_user_id = ?
");
$order->execute([$order_id, $_SESSION['user_id']]);
$order = $order->fetch(PDO::FETCH_ASSOC);

if (!$order) { header('Location: orders.php'); exit; }

$items = $pdo->prepare("
    SELECT oi.*, p.product_title, p.product_cover_image, p.product_type
    FROM order_items oi
    JOIN products p ON oi.order_item_product_id = p.product_id
    WHERE oi.order_item_order_id = ?
");
$items->execute([$order_id]);
$items = $items->fetchAll(PDO::FETCH_ASSOC);

date_default_timezone_set('Asia/Kuala_Lumpur');
$order_num = '#' . str_pad($order_id, 4, '0', STR_PAD_LEFT);

// Build timeline steps
$steps = [
    [
        'key' => 'ordered',
        'label' => 'Order Placed',
        'desc' => 'Your order has been received',
        'icon' => '🛒',
        'time' => $order['order_created_at'],
        'done' => true,
    ],
    [
        'key' => 'payment',
        'label' => 'Payment Confirmed',
        'desc' => 'Payment has been verified',
        'icon' => '💳',
        'time' => null,
        'done' => $order['order_payment_status'] === 'confirmed',
    ],
    [
        'key' => 'processing',
        'label' => 'Processing',
        'desc' => 'Your order is being prepared',
        'icon' => '📦',
        'time' => $order['order_processing_at'],
        'done' => in_array($order['order_status'], ['processing', 'shipped', 'delivered']),
    ],
    [
        'key' => 'shipped',
        'label' => 'Shipped',
        'desc' => $order['order_tracking_number'] 
            ? 'Tracking: ' . $order['order_tracking_number'] 
            : 'On the way to you',
        'icon' => '🚚',
        'time' => $order['order_shipped_at'],
        'done' => in_array($order['order_status'], ['shipped', 'delivered']),
    ],
    [
        'key' => 'delivered',
        'label' => 'Delivered',
        'desc' => 'Package delivered successfully',
        'icon' => '✅',
        'time' => $order['order_delivered_at'],
        'done' => $order['order_status'] === 'delivered',
    ],
];

// Find current active step
$current_step = 0;
foreach ($steps as $i => $step) {
    if ($step['done']) $current_step = $i;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Track Order <?= $order_num ?> - MangaVault</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { opacity: 0; animation: fadeIn 0.4s ease forwards; }
        @keyframes fadeIn { to { opacity: 1; } }

        @keyframes pulse-dot {
            0%, 100% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.2); opacity: 0.7; }
        }
        .pulse-dot { animation: pulse-dot 1.5s ease-in-out infinite; }

        .timeline-line {
            transition: background 0.5s ease;
        }
    </style>
</head>
<body class="bg-[#F5F0EB] min-h-screen">

    <?php include '../includes/customer_navbar.php'; ?>

    <div class="max-w-3xl mx-auto px-6 py-8">

        <!-- Breadcrumb -->
        <p class="text-sm text-gray-400 mb-6">
            <a href="../index.php" class="hover:text-red-600 transition-colors">Home</a>
            <span class="mx-2">›</span>
            <a href="orders.php" class="hover:text-red-600 transition-colors">My Orders</a>
            <span class="mx-2">›</span>
            <span class="text-gray-600">Track <?= $order_num ?></span>
        </p>

        <?php if ($order['order_status'] === 'cancelled'): ?>
        <!-- Cancelled -->
        <div class="bg-white rounded-2xl shadow-sm p-8 text-center mb-6">
            <div class="text-5xl mb-4">❌</div>
            <h2 class="text-xl font-black text-gray-800 mb-2">Order Cancelled</h2>
            <p class="text-gray-500 text-sm">This order has been cancelled.</p>
        </div>
        <?php else: ?>

        <!-- Header Card -->
        <div class="bg-gradient-to-r from-[#1e2d4a] to-[#2c3e6b] rounded-2xl p-6 mb-6 text-white relative overflow-hidden">
            <div class="absolute top-0 right-0 w-32 h-32 bg-white/5 rounded-full -translate-y-8 translate-x-8"></div>
            <div class="relative z-10 flex justify-between items-start flex-wrap gap-4">
                <div>
                    <p class="text-white/60 text-xs uppercase tracking-wide mb-1">Order Tracking</p>
                    <p class="text-2xl font-black"><?= $order_num ?></p>
                    <p class="text-white/60 text-sm mt-1"><?= date('d F Y', strtotime($order['order_created_at'])) ?></p>
                </div>
                <div class="text-right">
                    <p class="text-white/60 text-xs mb-1">Total</p>
                    <p class="text-2xl font-black">RM <?= number_format($order['order_total_amount'], 2) ?></p>
                    <?php if ($order['order_tracking_number']): ?>
                    <p class="text-white/70 text-xs mt-1">📦 <?= htmlspecialchars($order['order_tracking_number']) ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Timeline -->
        <div class="bg-white rounded-2xl shadow-sm p-6 mb-6">
            <h3 class="font-bold text-gray-800 mb-6">Delivery Status</h3>

            <div class="relative">
                <?php foreach ($steps as $i => $step): ?>
                <?php
                    $is_done = $step['done'];
                    $is_current = ($i === $current_step && $is_done);
                    $is_last = ($i === count($steps) - 1);
                ?>
                <div class="flex gap-4 <?= !$is_last ? 'mb-0' : '' ?>">
                    <!-- Icon + Line -->
                    <div class="flex flex-col items-center">
                        <div class="w-10 h-10 rounded-full flex items-center justify-center text-lg flex-shrink-0
                            <?= $is_done 
                                ? ($is_current && !in_array($order['order_status'], ['delivered']) 
                                    ? 'bg-blue-100 ring-2 ring-blue-400 ring-offset-2' 
                                    : 'bg-green-100') 
                                : 'bg-gray-100' ?>">
                            <?php if ($is_done && $order['order_status'] === 'delivered' && $step['key'] === 'delivered'): ?>
                                <span>✅</span>
                            <?php elseif ($is_current && !in_array($order['order_status'], ['delivered'])): ?>
                                <span class="pulse-dot"><?= $step['icon'] ?></span>
                            <?php elseif ($is_done): ?>
                                <span><?= $step['icon'] ?></span>
                            <?php else: ?>
                                <span class="text-gray-300"><?= $step['icon'] ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if (!$is_last): ?>
                        <div class="w-0.5 flex-1 my-1 min-h-[32px] rounded-full
                            <?= $is_done ? 'bg-green-300' : 'bg-gray-200' ?>">
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Content -->
                    <div class="pb-6 flex-1">
                        <div class="flex items-center gap-2 flex-wrap">
                            <p class="font-bold text-sm <?= $is_done ? 'text-gray-800' : 'text-gray-400' ?>">
                                <?= $step['label'] ?>
                            </p>
                            <?php if ($is_current && !in_array($order['order_status'], ['delivered', 'cancelled'])): ?>
                            <span class="bg-blue-100 text-blue-700 text-xs px-2 py-0.5 rounded-full font-semibold">Current</span>
                            <?php elseif ($is_done): ?>
                            <span class="bg-green-100 text-green-700 text-xs px-2 py-0.5 rounded-full font-semibold">Done</span>
                            <?php endif; ?>
                        </div>
                        <p class="text-xs <?= $is_done ? 'text-gray-500' : 'text-gray-300' ?> mt-0.5">
                            <?= htmlspecialchars($step['desc']) ?>
                        </p>
                        <?php if ($step['time']): ?>
                        <p class="text-xs text-gray-400 mt-1">
                            🕐 <?= date('d M Y, h:i A', strtotime($step['time'])) ?>
                        </p>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Shipping Address -->
        <?php if ($order['order_has_physical'] && $order['address_recipient_name']): ?>
        <div class="bg-white rounded-2xl shadow-sm p-6 mb-6">
            <h3 class="font-bold text-gray-800 mb-4">Delivery Address</h3>
            <div class="flex items-start gap-3">
                <div class="w-10 h-10 bg-red-100 rounded-xl flex items-center justify-center flex-shrink-0">
                    <span>📍</span>
                </div>
                <div>
                    <p class="font-semibold text-sm text-gray-800"><?= htmlspecialchars($order['address_recipient_name']) ?></p>
                    <p class="text-xs text-gray-500 mt-0.5"><?php if (!empty($order['address_taman'])): ?><?= htmlspecialchars($order['address_taman']) ?>, <?php endif; ?><?= htmlspecialchars($order['address_street']) ?></p>
                    <p class="text-xs text-gray-500"><?= htmlspecialchars($order['address_city']) ?>, <?= htmlspecialchars($order['address_postal_code']) ?></p>
                    <p class="text-xs text-gray-500"><?= htmlspecialchars($order['address_country']) ?></p>
                    <p class="text-xs text-gray-500">Tel: <?= htmlspecialchars($order['address_phone']) ?></p>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Order Items -->
        <div class="bg-white rounded-2xl shadow-sm p-6 mb-6">
            <h3 class="font-bold text-gray-800 mb-4">Order Items</h3>
            <div class="space-y-3">
                <?php foreach ($items as $item): ?>
                <div class="flex items-center gap-3">
                    <?php if ($item['product_cover_image']): ?>
                    <img src="../assets/images/<?= htmlspecialchars($item['product_cover_image']) ?>"
                         class="w-12 h-16 object-cover rounded-lg flex-shrink-0">
                    <?php else: ?>
                    <div class="w-12 h-16 bg-gray-100 rounded-lg flex items-center justify-center flex-shrink-0 text-gray-400 text-xs">📖</div>
                    <?php endif; ?>
                    <div class="flex-1 min-w-0">
                        <p class="font-semibold text-sm text-gray-800 truncate"><?= htmlspecialchars($item['product_title']) ?></p>
                        <p class="text-xs text-gray-400"><?= $item['product_type'] === 'ebook' ? '📱 E-Book' : '📦 Physical' ?> × <?= $item['order_item_quantity'] ?></p>
                    </div>
                    <p class="font-bold text-sm text-red-600 flex-shrink-0">RM <?= number_format($item['order_item_price'] * $item['order_item_quantity'], 2) ?></p>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <?php endif; ?>

        <!-- Auto refresh every 30s -->
        <p class="text-center text-xs text-gray-400 mb-4">🔄 Page auto-refreshes every 30 seconds</p>

        <div class="text-center">
            <a href="orders.php" class="text-sm text-gray-400 hover:text-red-600 transition-colors">← Back to Orders</a>
        </div>
    </div>

    <script>
    // Auto refresh every 30 seconds
    setTimeout(() => { window.location.reload(); }, 30000);

    // Countdown display
    let refreshIn = 30;
    const refreshEl = document.querySelector('.text-xs.text-gray-400.mb-4');
    setInterval(() => {
        refreshIn--;
        if (refreshIn <= 0) refreshIn = 30;
        if (refreshEl) refreshEl.textContent = '🔄 Auto-refreshes in ' + refreshIn + 's';
    }, 1000);
    </script>

</body>
</html>