<?php
require_once __DIR__ . '/../includes/auth.php';
require_customer();

require_once __DIR__ . '/../includes/db.php';

$user_id = $_SESSION['user_id'];

// Recent orders
$recent_orders = $pdo->prepare("
    SELECT o.*, COUNT(oi.order_item_id) as item_count
    FROM orders o
    LEFT JOIN order_items oi ON o.order_id = oi.order_item_order_id
    WHERE o.order_user_id = ?
    GROUP BY o.order_id
    ORDER BY o.order_created_at DESC
    LIMIT 3
");
$recent_orders->execute([$user_id]);
$recent_orders = $recent_orders->fetchAll(PDO::FETCH_ASSOC);

// Stats
$total_orders = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE order_user_id = ?");
$total_orders->execute([$user_id]);
$total_orders = $total_orders->fetchColumn();

$total_spent = $pdo->prepare("SELECT COALESCE(SUM(order_total_amount), 0) FROM orders WHERE order_user_id = ?");
$total_spent->execute([$user_id]);
$total_spent = $total_spent->fetchColumn();

$wishlist_count = $pdo->prepare("SELECT COUNT(*) FROM wishlist WHERE wishlist_user_id = ?");
$wishlist_count->execute([$user_id]);
$wishlist_count = $wishlist_count->fetchColumn();

$collection_count = $pdo->prepare("SELECT COUNT(*) FROM user_collection WHERE collection_user_id = ?");
$collection_count->execute([$user_id]);
$collection_count = $collection_count->fetchColumn();

$notif_count = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE notif_user_id = ? AND notif_is_read = 0");
$notif_count->execute([$user_id]);
$notif_count = $notif_count->fetchColumn();

// Recent notifications
$recent_notifs = $pdo->prepare("SELECT * FROM notifications WHERE notif_user_id = ? ORDER BY notif_created_at DESC LIMIT 3");
$recent_notifs->execute([$user_id]);
$recent_notifs = $recent_notifs->fetchAll(PDO::FETCH_ASSOC);

// User info
$user = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
$user->execute([$user_id]);
$user = $user->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - MangaVault</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        html { scroll-behavior: smooth; }
        body { opacity: 0; animation: fadeIn 0.4s ease forwards; }
        @keyframes fadeIn { to { opacity: 1; } }
    </style>
</head>
<body class="bg-[#F5F0EB] min-h-screen">

    <?php include '../includes/customer_navbar.php'; ?>

    <div class="max-w-7xl mx-auto px-6 py-8">
        <p class="text-sm text-gray-400 mb-6">
            <a href="../index.php" class="hover:text-red-600 transition-colors">Home</a>
            <span class="mx-2">›</span>
            <span class="text-gray-600">My Account</span>
        </p>

        <div class="flex gap-8 items-start">
            <?php include '../includes/customer_sidebar.php'; ?>

            <div class="flex-1 min-w-0">

                <!-- Welcome Banner -->
                <div class="bg-gradient-to-r from-[#1e2d4a] to-[#2c3e6b] rounded-2xl p-6 mb-6 text-white relative overflow-hidden">
                    <div class="absolute top-0 right-0 w-40 h-40 bg-white/5 rounded-full -translate-y-10 translate-x-10"></div>
                    <div class="absolute bottom-0 left-0 w-24 h-24 bg-white/5 rounded-full translate-y-8 -translate-x-8"></div>
                    <div class="relative z-10">
                        <p class="text-white/60 text-sm mb-1">Welcome back,</p>
                        <h1 class="text-2xl font-black mb-1"><?= htmlspecialchars($user['user_first_name'] . ' ' . $user['user_last_name']) ?> 👋</h1>
                        <p class="text-white/60 text-xs">Member since <?= date('F Y', strtotime($user['user_created_at'])) ?></p>
                    </div>
                </div>

                <!-- Stats -->
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                    <a href="orders.php" class="bg-white rounded-2xl p-4 shadow-sm hover:shadow-md transition-all duration-200 hover:-translate-y-0.5">
                        <div class="w-10 h-10 bg-blue-50 rounded-xl flex items-center justify-center mb-3">
                            <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path></svg>
                        </div>
                        <p class="text-2xl font-black text-gray-800"><?= $total_orders ?></p>
                        <p class="text-xs text-gray-400 mt-0.5">Total Orders</p>
                    </a>
                    <div class="bg-white rounded-2xl p-4 shadow-sm">
                        <div class="w-10 h-10 bg-green-50 rounded-xl flex items-center justify-center mb-3">
                            <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                        </div>
                        <p class="text-2xl font-black text-gray-800">RM <?= number_format($total_spent, 0) ?></p>
                        <p class="text-xs text-gray-400 mt-0.5">Total Spent</p>
                    </div>
                    <a href="wishlist.php" class="bg-white rounded-2xl p-4 shadow-sm hover:shadow-md transition-all duration-200 hover:-translate-y-0.5">
                        <div class="w-10 h-10 bg-red-50 rounded-xl flex items-center justify-center mb-3">
                            <svg class="w-5 h-5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"></path></svg>
                        </div>
                        <p class="text-2xl font-black text-gray-800"><?= $wishlist_count ?></p>
                        <p class="text-xs text-gray-400 mt-0.5">Wishlist Items</p>
                    </a>
                    <a href="collection.php" class="bg-white rounded-2xl p-4 shadow-sm hover:shadow-md transition-all duration-200 hover:-translate-y-0.5">
                        <div class="w-10 h-10 bg-purple-50 rounded-xl flex items-center justify-center mb-3">
                            <svg class="w-5 h-5 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path></svg>
                        </div>
                        <p class="text-2xl font-black text-gray-800"><?= $collection_count ?></p>
                        <p class="text-xs text-gray-400 mt-0.5">My Collection</p>
                    </a>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

                    <!-- Recent Orders -->
                    <div class="bg-white rounded-2xl shadow-sm p-6">
                        <div class="flex justify-between items-center mb-4">
                            <h3 class="font-bold text-gray-800">Recent Orders</h3>
                            <a href="orders.php" class="text-xs text-red-600 hover:underline">View all →</a>
                        </div>
                        <?php if (count($recent_orders) === 0): ?>
                            <div class="text-center py-6">
                                <div class="text-3xl mb-2">📦</div>
                                <p class="text-gray-400 text-sm">No orders yet</p>
                                <a href="home.php" class="text-xs text-red-600 hover:underline mt-1 inline-block">Start shopping</a>
                            </div>
                        <?php else: ?>
                            <div class="space-y-3">
                                <?php foreach ($recent_orders as $order):
                                    $status_colors = [
                                        'pending' => 'bg-yellow-100 text-yellow-700',
                                        'processing' => 'bg-blue-100 text-blue-700',
                                        'shipped' => 'bg-purple-100 text-purple-700',
                                        'delivered' => 'bg-green-100 text-green-700',
                                        'cancelled' => 'bg-red-100 text-red-700'
                                    ];
                                    $color = $status_colors[$order['order_status']] ?? 'bg-gray-100 text-gray-700';
                                ?>
                                <div class="flex items-center justify-between p-3 bg-gray-50 rounded-xl">
                                    <div>
                                        <p class="font-semibold text-sm text-gray-800">Order #<?= str_pad($order['order_id'], 4, '0', STR_PAD_LEFT) ?></p>
                                        <p class="text-xs text-gray-400"><?= $order['item_count'] ?> item(s) · <?= date('d M Y', strtotime($order['order_created_at'])) ?></p>
                                    </div>
                                    <div class="text-right">
                                        <span class="<?= $color ?> text-xs px-2 py-1 rounded-full font-semibold capitalize block mb-1"><?= $order['order_status'] ?></span>
                                        <p class="text-xs font-bold text-red-600">RM <?= number_format($order['order_total_amount'], 2) ?></p>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Recent Notifications -->
                    <div class="bg-white rounded-2xl shadow-sm p-6">
                        <div class="flex justify-between items-center mb-4">
                            <div class="flex items-center gap-2">
                                <h3 class="font-bold text-gray-800">Notifications</h3>
                                <?php if ($notif_count > 0): ?>
                                    <span class="bg-red-600 text-white text-xs px-2 py-0.5 rounded-full"><?= $notif_count ?></span>
                                <?php endif; ?>
                            </div>
                            <a href="notifications.php" class="text-xs text-red-600 hover:underline">View all →</a>
                        </div>
                        <?php if (count($recent_notifs) === 0): ?>
                            <div class="text-center py-6">
                                <div class="text-3xl mb-2">🔔</div>
                                <p class="text-gray-400 text-sm">No notifications yet</p>
                            </div>
                        <?php else: ?>
                            <div class="space-y-3">
                                <?php foreach ($recent_notifs as $notif): ?>
                                <div class="flex items-start gap-3 p-3 <?= !$notif['notif_is_read'] ? 'bg-red-50 border border-red-100' : 'bg-gray-50' ?> rounded-xl">
                                    <div class="w-8 h-8 rounded-full flex items-center justify-center flex-shrink-0 text-sm
                                        <?= $notif['notif_type'] === 'order' ? 'bg-blue-100' : ($notif['notif_type'] === 'promo' ? 'bg-yellow-100' : 'bg-gray-100') ?>">
                                        <?= $notif['notif_type'] === 'order' ? '📦' : ($notif['notif_type'] === 'promo' ? '🎉' : ($notif['notif_type'] === 'return' ? '↩️' : '🔔')) ?>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <p class="font-semibold text-xs text-gray-800"><?= htmlspecialchars($notif['notif_title']) ?></p>
                                        <p class="text-xs text-gray-500 truncate"><?= htmlspecialchars($notif['notif_message']) ?></p>
                                        <p class="text-xs text-gray-400 mt-0.5"><?= date('d M, h:i A', strtotime($notif['notif_created_at'])) ?></p>
                                    </div>
                                    <?php if (!$notif['notif_is_read']): ?>
                                        <div class="w-2 h-2 bg-red-600 rounded-full flex-shrink-0 mt-1"></div>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Quick Links -->
                    <div class="bg-white rounded-2xl shadow-sm p-6 lg:col-span-2">
                        <h3 class="font-bold text-gray-800 mb-4">Quick Access</h3>
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                            <a href="profile.php" class="flex flex-col items-center gap-2 p-4 bg-gray-50 hover:bg-red-50 rounded-xl transition-colors duration-200 group">
                                <div class="w-10 h-10 bg-white group-hover:bg-red-100 rounded-xl flex items-center justify-center shadow-sm transition-colors">
                                    <svg class="w-5 h-5 text-gray-600 group-hover:text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg>
                                </div>
                                <p class="text-xs font-semibold text-gray-600 group-hover:text-red-600 text-center">My Profile</p>
                            </a>
                            <a href="payment_methods.php" class="flex flex-col items-center gap-2 p-4 bg-gray-50 hover:bg-red-50 rounded-xl transition-colors duration-200 group">
                                <div class="w-10 h-10 bg-white group-hover:bg-red-100 rounded-xl flex items-center justify-center shadow-sm transition-colors">
                                    <svg class="w-5 h-5 text-gray-600 group-hover:text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"></path></svg>
                                </div>
                                <p class="text-xs font-semibold text-gray-600 group-hover:text-red-600 text-center">Payment Methods</p>
                            </a>
                            <a href="returns.php" class="flex flex-col items-center gap-2 p-4 bg-gray-50 hover:bg-red-50 rounded-xl transition-colors duration-200 group">
                                <div class="w-10 h-10 bg-white group-hover:bg-red-100 rounded-xl flex items-center justify-center shadow-sm transition-colors">
                                    <svg class="w-5 h-5 text-gray-600 group-hover:text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6"></path></svg>
                                </div>
                                <p class="text-xs font-semibold text-gray-600 group-hover:text-red-600 text-center">Returns</p>
                            </a>
                            <a href="../tier.php" class="flex flex-col items-center gap-2 p-4 bg-gray-50 hover:bg-red-50 rounded-xl transition-colors duration-200 group">
                                <div class="w-10 h-10 bg-white group-hover:bg-red-100 rounded-xl flex items-center justify-center shadow-sm transition-colors">
                                    <svg class="w-5 h-5 text-gray-600 group-hover:text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"></path></svg>
                                </div>
                                <p class="text-xs font-semibold text-gray-600 group-hover:text-red-600 text-center">Membership</p>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

</body>
</html>