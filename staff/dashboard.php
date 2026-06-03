<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'staff') {
    header('Location: ../admin/login.php');
    exit;
}
require_once '../includes/db.php';

date_default_timezone_set('Asia/Kuala_Lumpur');

$pending_orders = $pdo->query("SELECT COUNT(*) FROM orders WHERE order_status = 'pending' AND order_payment_status = 'confirmed'")->fetchColumn();
$processing_orders = $pdo->query("SELECT COUNT(*) FROM orders WHERE order_status = 'processing' AND order_payment_status = 'confirmed'")->fetchColumn();
$pending_returns = $pdo->query("SELECT COUNT(*) FROM return_requests WHERE return_status = 'pending'")->fetchColumn();
$pending_reviews = $pdo->query("SELECT COUNT(*) FROM product_reviews WHERE review_status = 'pending'")->fetchColumn();
$low_stock = $pdo->query("SELECT COUNT(*) FROM product_physical WHERE physical_stock_quantity <= physical_low_stock_threshold")->fetchColumn();
$total_products = $pdo->query("SELECT COUNT(*) FROM products WHERE product_is_available = 1")->fetchColumn();

// Recent orders
$recent_orders = $pdo->query("
    SELECT o.*, u.user_first_name, u.user_last_name
    FROM orders o
    JOIN users u ON o.order_user_id = u.user_id
    WHERE o.order_payment_status = 'confirmed'
    ORDER BY o.order_created_at DESC LIMIT 6
")->fetchAll(PDO::FETCH_ASSOC);

// Pending returns
$recent_returns = $pdo->query("
    SELECT rr.*, p.product_title, u.user_first_name, u.user_last_name
    FROM return_requests rr
    JOIN order_items oi ON rr.return_item_id = oi.order_item_id
    JOIN products p ON oi.order_item_product_id = p.product_id
    JOIN users u ON rr.return_user_id = u.user_id
    WHERE rr.return_status = 'pending'
    ORDER BY rr.return_created_at DESC LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

// Low stock products
$low_stock_products = $pdo->query("
    SELECT p.product_title, pp.physical_stock_quantity
    FROM product_physical pp
    JOIN products p ON pp.physical_product_id = p.product_id
    WHERE pp.physical_stock_quantity <= pp.physical_low_stock_threshold
    ORDER BY pp.physical_stock_quantity ASC LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Dashboard - MangaVault</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { opacity: 0; animation: fadeIn 0.4s ease forwards; }
        @keyframes fadeIn { to { opacity: 1; } }
    </style>
</head>
<body class="bg-gray-100 min-h-screen">

    <?php include '../includes/staff_navbar.php'; ?>

    <div class="max-w-7xl mx-auto px-6 py-8">

        <div class="flex justify-between items-center mb-8">
            <div>
                <h1 class="text-2xl font-black text-gray-800">Staff Dashboard</h1>
                <p class="text-sm text-gray-400 mt-0.5">Welcome, <?= htmlspecialchars($_SESSION['user_first_name']) ?>! Here's your task overview.</p>
            </div>
            <p class="text-sm text-gray-400"><?= date('l, d F Y') ?></p>
        </div>

        <!-- Alert Banners -->
        <?php if ($pending_orders > 0 || $low_stock > 0 || $pending_returns > 0): ?>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-6">
            <?php if ($pending_orders > 0): ?>
            <a href="orders.php?filter=pending" class="bg-yellow-50 border border-yellow-200 rounded-xl p-3 flex items-center gap-3 hover:bg-yellow-100 transition-colors">
                <span class="text-2xl">📦</span>
                <div>
                    <p class="font-bold text-yellow-800 text-sm"><?= $pending_orders ?> Pending Orders</p>
                    <p class="text-xs text-yellow-600">Need processing</p>
                </div>
            </a>
            <?php endif; ?>
            <?php if ($pending_returns > 0): ?>
            <a href="returns.php" class="bg-orange-50 border border-orange-200 rounded-xl p-3 flex items-center gap-3 hover:bg-orange-100 transition-colors">
                <span class="text-2xl">↩️</span>
                <div>
                    <p class="font-bold text-orange-800 text-sm"><?= $pending_returns ?> Return Requests</p>
                    <p class="text-xs text-orange-600">Awaiting review</p>
                </div>
            </a>
            <?php endif; ?>
            <?php if ($low_stock > 0): ?>
            <a href="products.php" class="bg-red-50 border border-red-200 rounded-xl p-3 flex items-center gap-3 hover:bg-red-100 transition-colors">
                <span class="text-2xl">⚠️</span>
                <div>
                    <p class="font-bold text-red-800 text-sm"><?= $low_stock ?> Low Stock</p>
                    <p class="text-xs text-red-600">Restock needed</p>
                </div>
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Stats -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
            <div class="bg-white rounded-2xl shadow-sm p-5">
                <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-2">Pending Orders</p>
                <p class="text-3xl font-black text-yellow-500"><?= $pending_orders ?></p>
            </div>
            <div class="bg-white rounded-2xl shadow-sm p-5">
                <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-2">Processing</p>
                <p class="text-3xl font-black text-blue-500"><?= $processing_orders ?></p>
            </div>
            <div class="bg-white rounded-2xl shadow-sm p-5">
                <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-2">Pending Returns</p>
                <p class="text-3xl font-black text-orange-500"><?= $pending_returns ?></p>
            </div>
            <div class="bg-white rounded-2xl shadow-sm p-5">
                <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-2">Active Products</p>
                <p class="text-3xl font-black text-green-500"><?= $total_products ?></p>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">

            <!-- Recent Orders -->
            <div class="lg:col-span-2 bg-white rounded-2xl shadow-sm overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-50 flex justify-between items-center">
                    <h3 class="font-bold text-gray-800">Recent Orders</h3>
                    <a href="orders.php" class="text-xs text-red-600 hover:underline">View All →</a>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                            <tr class="bg-gray-50">
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Order</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Customer</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Status</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_orders as $order):
                                $sc = [
                                    'pending' => 'bg-yellow-100 text-yellow-700',
                                    'processing' => 'bg-blue-100 text-blue-700',
                                    'shipped' => 'bg-purple-100 text-purple-700',
                                    'delivered' => 'bg-green-100 text-green-700',
                                    'cancelled' => 'bg-red-100 text-red-700',
                                ][$order['order_status']] ?? 'bg-gray-100 text-gray-700';
                            ?>
                            <tr class="border-t border-gray-50 hover:bg-gray-50">
                                <td class="px-4 py-3 text-sm font-semibold text-gray-800">#<?= str_pad($order['order_id'], 4, '0', STR_PAD_LEFT) ?></td>
                                <td class="px-4 py-3 text-sm text-gray-600"><?= htmlspecialchars($order['user_first_name'] . ' ' . $order['user_last_name']) ?></td>
                                <td class="px-4 py-3"><span class="<?= $sc ?> text-xs px-2 py-1 rounded-full font-semibold capitalize"><?= $order['order_status'] ?></span></td>
                                <td class="px-4 py-3 text-xs text-gray-400"><?= date('d M Y', strtotime($order['order_created_at'])) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Right Column -->
            <div class="space-y-6">

                <!-- Pending Returns -->
                <?php if (count($recent_returns) > 0): ?>
                <div class="bg-white rounded-2xl shadow-sm overflow-hidden">
                    <div class="px-5 py-4 border-b border-gray-50 flex justify-between items-center">
                        <h3 class="font-bold text-gray-800 text-sm">Pending Returns</h3>
                        <a href="returns.php" class="text-xs text-red-600 hover:underline">View All →</a>
                    </div>
                    <div class="p-4 space-y-3">
                        <?php foreach ($recent_returns as $r): ?>
                        <div class="flex items-start gap-2">
                            <span class="text-lg flex-shrink-0">↩️</span>
                            <div class="min-w-0">
                                <p class="text-xs font-semibold text-gray-800 truncate"><?= htmlspecialchars($r['product_title']) ?></p>
                                <p class="text-xs text-gray-400"><?= htmlspecialchars($r['user_first_name'] . ' ' . $r['user_last_name']) ?></p>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Low Stock -->
                <?php if (count($low_stock_products) > 0): ?>
                <div class="bg-white rounded-2xl shadow-sm overflow-hidden">
                    <div class="px-5 py-4 border-b border-gray-50">
                        <h3 class="font-bold text-gray-800 text-sm">⚠️ Low Stock</h3>
                    </div>
                    <div class="p-4 space-y-2">
                        <?php foreach ($low_stock_products as $p): ?>
                        <div class="flex items-center justify-between">
                            <p class="text-xs text-gray-700 truncate flex-1 mr-2"><?= htmlspecialchars($p['product_title']) ?></p>
                            <span class="text-xs font-black text-red-600 flex-shrink-0"><?= $p['physical_stock_quantity'] ?> left</span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

            </div>
        </div>

        <!-- Quick Actions -->
        <div class="bg-white rounded-2xl shadow-sm p-6">
            <h3 class="font-bold text-gray-800 mb-4">Quick Actions</h3>
            <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
                <a href="orders.php" class="flex flex-col items-center gap-2 p-4 bg-blue-50 hover:bg-blue-100 rounded-xl transition-colors text-center">
                    <span class="text-2xl">📦</span>
                    <span class="text-xs font-semibold text-blue-700">Manage Orders</span>
                </a>
                <a href="returns.php" class="flex flex-col items-center gap-2 p-4 bg-orange-50 hover:bg-orange-100 rounded-xl transition-colors text-center">
                    <span class="text-2xl">↩️</span>
                    <span class="text-xs font-semibold text-orange-700">Returns</span>
                </a>
                <a href="products.php" class="flex flex-col items-center gap-2 p-4 bg-purple-50 hover:bg-purple-100 rounded-xl transition-colors text-center">
                    <span class="text-2xl">📚</span>
                    <span class="text-xs font-semibold text-purple-700">Products</span>
                </a>
                <a href="add_product.php" class="flex flex-col items-center gap-2 p-4 bg-red-50 hover:bg-red-100 rounded-xl transition-colors text-center">
                    <span class="text-2xl">➕</span>
                    <span class="text-xs font-semibold text-red-700">Add Product</span>
                </a>
                <a href="reviews.php" class="flex flex-col items-center gap-2 p-4 bg-yellow-50 hover:bg-yellow-100 rounded-xl transition-colors text-center">
                    <span class="text-2xl">⭐</span>
                    <span class="text-xs font-semibold text-yellow-700">Reviews</span>
                </a>
            </div>
        </div>

    </div>
</body>
</html>