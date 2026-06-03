<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}
require_once '../includes/db.php';

date_default_timezone_set('Asia/Kuala_Lumpur');

// Stats
$total_orders = $pdo->query("SELECT COUNT(*) FROM orders WHERE order_payment_status = 'confirmed'")->fetchColumn();
$total_revenue = $pdo->query("SELECT SUM(order_total_amount) FROM orders WHERE order_payment_status = 'confirmed' AND order_status != 'cancelled'")->fetchColumn();
$total_products = $pdo->query("SELECT COUNT(*) FROM products WHERE product_is_available = 1")->fetchColumn();
$total_customers = $pdo->query("SELECT COUNT(*) FROM users WHERE user_role = 'customer'")->fetchColumn();
$low_stock = $pdo->query("SELECT COUNT(*) FROM product_physical WHERE physical_stock_quantity <= physical_low_stock_threshold")->fetchColumn();
$pending_orders = $pdo->query("SELECT COUNT(*) FROM orders WHERE order_status = 'pending' AND order_payment_status = 'confirmed'")->fetchColumn();
$pending_returns = $pdo->query("SELECT COUNT(*) FROM return_requests WHERE return_status = 'pending'")->fetchColumn();
$pending_reviews = $pdo->query("SELECT COUNT(*) FROM product_reviews WHERE review_status = 'pending'")->fetchColumn();

// Revenue this month
$revenue_month = $pdo->query("SELECT SUM(order_total_amount) FROM orders WHERE order_payment_status = 'confirmed' AND order_status != 'cancelled' AND MONTH(order_created_at) = MONTH(NOW()) AND YEAR(order_created_at) = YEAR(NOW())")->fetchColumn();

// Recent orders
$recent_orders = $pdo->query("
    SELECT o.*, u.user_name, u.user_first_name, u.user_last_name
    FROM orders o
    JOIN users u ON o.order_user_id = u.user_id
    WHERE o.order_payment_status = 'confirmed'
    ORDER BY o.order_created_at DESC LIMIT 8
")->fetchAll(PDO::FETCH_ASSOC);

// Sales by type
$physical_sales = $pdo->query("SELECT SUM(oi.order_item_price * oi.order_item_quantity) FROM order_items oi JOIN orders o ON oi.order_item_order_id = o.order_id WHERE oi.order_item_type = 'physical' AND o.order_payment_status = 'confirmed'")->fetchColumn();
$ebook_sales = $pdo->query("SELECT SUM(oi.order_item_price * oi.order_item_quantity) FROM order_items oi JOIN orders o ON oi.order_item_order_id = o.order_id WHERE oi.order_item_type = 'ebook' AND o.order_payment_status = 'confirmed'")->fetchColumn();

// Low stock products
$low_stock_products = $pdo->query("
    SELECT p.product_title, p.product_id, pp.physical_stock_quantity, pp.physical_low_stock_threshold
    FROM product_physical pp
    JOIN products p ON pp.physical_product_id = p.product_id
    WHERE pp.physical_stock_quantity <= pp.physical_low_stock_threshold
    ORDER BY pp.physical_stock_quantity ASC
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

// Revenue last 7 days
$revenue_7days = [];
$labels_7days = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $label = date('d M', strtotime("-$i days"));
    $rev = $pdo->prepare("SELECT COALESCE(SUM(order_total_amount), 0) FROM orders WHERE DATE(order_created_at) = ? AND order_payment_status = 'confirmed' AND order_status != 'cancelled'");
    $rev->execute([$date]);
    $revenue_7days[] = round($rev->fetchColumn(), 2);
    $labels_7days[] = $label;
}

// Orders by status
$order_statuses = $pdo->query("
    SELECT order_status, COUNT(*) as count FROM orders 
    WHERE order_payment_status = 'confirmed'
    GROUP BY order_status
")->fetchAll(PDO::FETCH_ASSOC);

// New customers last 6 months
$new_customers = [];
$customer_labels = [];
for ($i = 5; $i >= 0; $i--) {
    $month = date('Y-m', strtotime("-$i months"));
    $label = date('M Y', strtotime("-$i months"));
    $count = $pdo->prepare("SELECT COUNT(*) FROM users WHERE user_role = 'customer' AND DATE_FORMAT(user_created_at, '%Y-%m') = ?");
    $count->execute([$month]);
    $new_customers[] = $count->fetchColumn();
    $customer_labels[] = $label;
}

// Top products
$top_products = $pdo->query("
    SELECT p.product_title, p.product_cover_image, SUM(oi.order_item_quantity) as total_sold, SUM(oi.order_item_price * oi.order_item_quantity) as revenue
    FROM order_items oi
    JOIN products p ON oi.order_item_product_id = p.product_id
    JOIN orders o ON oi.order_item_order_id = o.order_id
    WHERE o.order_payment_status = 'confirmed'
    GROUP BY p.product_id
    ORDER BY total_sold DESC
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - MangaVault</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { opacity: 0; animation: fadeIn 0.4s ease forwards; }
        @keyframes fadeIn { to { opacity: 1; } }
    </style>
</head>
<body class="bg-gray-100 min-h-screen">

    <?php include '../includes/admin_navbar.php'; ?>

    <div class="max-w-7xl mx-auto px-6 py-8">

        <!-- Header -->
        <div class="flex justify-between items-center mb-8">
            <div>
                <h1 class="text-2xl font-black text-gray-800">Dashboard</h1>
                <p class="text-sm text-gray-400 mt-0.5">Welcome back, <?= htmlspecialchars($_SESSION['user_first_name'] ?? $_SESSION['user_name']) ?>! Here's what's happening.</p>
            </div>
            <p class="text-sm text-gray-400"><?= date('l, d F Y') ?></p>
        </div>

        <!-- Alert Banners -->
        <?php if ($pending_orders > 0 || $low_stock > 0 || $pending_returns > 0 || $pending_reviews > 0): ?>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
            <?php if ($pending_orders > 0): ?>
            <a href="orders.php?filter=pending" class="bg-yellow-50 border border-yellow-200 rounded-xl p-3 flex items-center gap-3 hover:bg-yellow-100 transition-colors">
                <span class="text-2xl">📦</span>
                <div>
                    <p class="font-bold text-yellow-800 text-sm"><?= $pending_orders ?> Pending Orders</p>
                    <p class="text-xs text-yellow-600">Need processing</p>
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
            <?php if ($pending_returns > 0): ?>
            <a href="returns.php" class="bg-orange-50 border border-orange-200 rounded-xl p-3 flex items-center gap-3 hover:bg-orange-100 transition-colors">
                <span class="text-2xl">↩️</span>
                <div>
                    <p class="font-bold text-orange-800 text-sm"><?= $pending_returns ?> Return Requests</p>
                    <p class="text-xs text-orange-600">Awaiting review</p>
                </div>
            </a>
            <?php endif; ?>
            <?php if ($pending_reviews > 0): ?>
            <a href="reviews.php" class="bg-blue-50 border border-blue-200 rounded-xl p-3 flex items-center gap-3 hover:bg-blue-100 transition-colors">
                <span class="text-2xl">⭐</span>
                <div>
                    <p class="font-bold text-blue-800 text-sm"><?= $pending_reviews ?> Pending Reviews</p>
                    <p class="text-xs text-blue-600">Need approval</p>
                </div>
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Stats Cards -->
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4 mb-8">
            <div class="bg-white rounded-2xl shadow-sm p-5 col-span-2 md:col-span-1">
                <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-2">Total Revenue</p>
                <p class="text-2xl font-black text-green-600">RM <?= number_format($total_revenue ?? 0, 0) ?></p>
                <p class="text-xs text-gray-400 mt-1">This month: RM <?= number_format($revenue_month ?? 0, 0) ?></p>
            </div>
            <div class="bg-white rounded-2xl shadow-sm p-5">
                <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-2">Orders</p>
                <p class="text-2xl font-black text-blue-600"><?= $total_orders ?></p>
                <p class="text-xs text-yellow-500 mt-1"><?= $pending_orders ?> pending</p>
            </div>
            <div class="bg-white rounded-2xl shadow-sm p-5">
                <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-2">Products</p>
                <p class="text-2xl font-black text-purple-600"><?= $total_products ?></p>
                <p class="text-xs text-red-500 mt-1"><?= $low_stock ?> low stock</p>
            </div>
            <div class="bg-white rounded-2xl shadow-sm p-5">
                <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-2">Customers</p>
                <p class="text-2xl font-black text-indigo-600"><?= $total_customers ?></p>
            </div>
            <div class="bg-white rounded-2xl shadow-sm p-5">
                <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-2">Physical Sales</p>
                <p class="text-2xl font-black text-gray-800">RM <?= number_format($physical_sales ?? 0, 0) ?></p>
            </div>
            <div class="bg-white rounded-2xl shadow-sm p-5">
                <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-2">E-Book Sales</p>
                <p class="text-2xl font-black text-gray-800">RM <?= number_format($ebook_sales ?? 0, 0) ?></p>
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
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Total</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Status</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_orders as $order):
                                $status_colors = [
                                    'pending' => 'bg-yellow-100 text-yellow-700',
                                    'processing' => 'bg-blue-100 text-blue-700',
                                    'shipped' => 'bg-purple-100 text-purple-700',
                                    'delivered' => 'bg-green-100 text-green-700',
                                    'cancelled' => 'bg-red-100 text-red-700',
                                ];
                                $sc = $status_colors[$order['order_status']] ?? 'bg-gray-100 text-gray-700';
                            ?>
                            <tr class="border-t border-gray-50 hover:bg-gray-50 transition-colors">
                                <td class="px-4 py-3 text-sm font-semibold text-gray-800">#<?= str_pad($order['order_id'], 4, '0', STR_PAD_LEFT) ?></td>
                                <td class="px-4 py-3 text-sm text-gray-600"><?= htmlspecialchars($order['user_first_name'] . ' ' . $order['user_last_name']) ?></td>
                                <td class="px-4 py-3 text-sm font-semibold text-red-600">RM <?= number_format($order['order_total_amount'], 2) ?></td>
                                <td class="px-4 py-3">
                                    <span class="<?= $sc ?> text-xs px-2 py-1 rounded-full font-semibold capitalize"><?= $order['order_status'] ?></span>
                                </td>
                                <td class="px-4 py-3 text-xs text-gray-400"><?= date('d M Y', strtotime($order['order_created_at'])) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Right Column -->
            <div class="space-y-6">

                <!-- Top Products -->
                <div class="bg-white rounded-2xl shadow-sm overflow-hidden">
                    <div class="px-5 py-4 border-b border-gray-50 flex justify-between items-center">
                        <h3 class="font-bold text-gray-800 text-sm">Top Products</h3>
                        <a href="products.php" class="text-xs text-red-600 hover:underline">View All →</a>
                    </div>
                    <div class="p-4 space-y-3">
                        <?php foreach ($top_products as $i => $p): ?>
                        <div class="flex items-center gap-3">
                            <span class="w-6 h-6 rounded-full flex items-center justify-center text-xs font-black text-white flex-shrink-0 <?= $i === 0 ? 'bg-yellow-500' : ($i === 1 ? 'bg-gray-400' : ($i === 2 ? 'bg-orange-500' : 'bg-gray-300')) ?>">
                                <?= $i + 1 ?>
                            </span>
                            <?php if ($p['product_cover_image']): ?>
                            <img src="../assets/images/<?= htmlspecialchars($p['product_cover_image']) ?>" class="w-8 h-10 object-cover rounded flex-shrink-0">
                            <?php endif; ?>
                            <div class="flex-1 min-w-0">
                                <p class="text-xs font-semibold text-gray-800 truncate"><?= htmlspecialchars($p['product_title']) ?></p>
                                <p class="text-xs text-gray-400"><?= $p['total_sold'] ?> sold</p>
                            </div>
                            <p class="text-xs font-bold text-green-600 flex-shrink-0">RM <?= number_format($p['revenue'], 0) ?></p>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Low Stock -->
                <?php if (count($low_stock_products) > 0): ?>
                <div class="bg-white rounded-2xl shadow-sm overflow-hidden">
                    <div class="px-5 py-4 border-b border-gray-50">
                        <h3 class="font-bold text-gray-800 text-sm">⚠️ Low Stock Alert</h3>
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

        <!-- Analytics Charts -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
            <!-- Revenue Trend (Last 7 days) -->
            <div class="bg-white rounded-2xl shadow-sm p-6">
                <h3 class="font-bold text-gray-800 mb-1">Revenue Trend</h3>
                <p class="text-xs text-gray-400 mb-4">Last 7 days</p>
                <canvas id="revenueChart" height="120"></canvas>
            </div>

            <!-- Orders by Status -->
            <div class="bg-white rounded-2xl shadow-sm p-6">
                <h3 class="font-bold text-gray-800 mb-1">Orders by Status</h3>
                <p class="text-xs text-gray-400 mb-4">All time</p>
                <canvas id="statusChart" height="120"></canvas>
            </div>
        </div>

        <!-- Sales Split + New Customers -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">

            <!-- Physical vs Ebook -->
            <div class="bg-white rounded-2xl shadow-sm p-6">
                <h3 class="font-bold text-gray-800 mb-1">Sales Split</h3>
                <p class="text-xs text-gray-400 mb-4">Physical vs E-Book</p>
                <canvas id="splitChart" height="180"></canvas>
            </div>

            <!-- New customers per month -->
            <div class="lg:col-span-2 bg-white rounded-2xl shadow-sm p-6">
                <h3 class="font-bold text-gray-800 mb-1">New Customers</h3>
                <p class="text-xs text-gray-400 mb-4">Last 6 months</p>
                <canvas id="customersChart" height="120"></canvas>
            </div>
        </div>
        
        <!-- Quick Actions -->
        <div class="bg-white rounded-2xl shadow-sm p-6">
            <h3 class="font-bold text-gray-800 mb-4">Quick Actions</h3>
            <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-3">
                <a href="add_product.php" class="flex flex-col items-center gap-2 p-4 bg-red-50 hover:bg-red-100 rounded-xl transition-colors text-center">
                    <span class="text-2xl">➕</span>
                    <span class="text-xs font-semibold text-red-700">Add Product</span>
                </a>
                <a href="orders.php" class="flex flex-col items-center gap-2 p-4 bg-blue-50 hover:bg-blue-100 rounded-xl transition-colors text-center">
                    <span class="text-2xl">📦</span>
                    <span class="text-xs font-semibold text-blue-700">Manage Orders</span>
                </a>
                <a href="returns.php" class="flex flex-col items-center gap-2 p-4 bg-orange-50 hover:bg-orange-100 rounded-xl transition-colors text-center">
                    <span class="text-2xl">↩️</span>
                    <span class="text-xs font-semibold text-orange-700">Returns</span>
                </a>
                <a href="reviews.php" class="flex flex-col items-center gap-2 p-4 bg-yellow-50 hover:bg-yellow-100 rounded-xl transition-colors text-center">
                    <span class="text-2xl">⭐</span>
                    <span class="text-xs font-semibold text-yellow-700">Reviews</span>
                </a>
                <a href="users.php" class="flex flex-col items-center gap-2 p-4 bg-purple-50 hover:bg-purple-100 rounded-xl transition-colors text-center">
                    <span class="text-2xl">👥</span>
                    <span class="text-xs font-semibold text-purple-700">Users</span>
                </a>
                <a href="faq.php" class="flex flex-col items-center gap-2 p-4 bg-green-50 hover:bg-green-100 rounded-xl transition-colors text-center">
                    <span class="text-2xl">❓</span>
                    <span class="text-xs font-semibold text-green-700">FAQ</span>
                </a>
            </div>
        </div>

    </div>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const chartDefaults = {
    plugins: { legend: { display: false } },
    scales: {
        x: { grid: { display: false }, ticks: { font: { size: 11 } } },
        y: { grid: { color: '#f3f4f6' }, ticks: { font: { size: 11 } } }
    }
};

// Revenue Trend
new Chart(document.getElementById('revenueChart'), {
    type: 'line',
    data: {
        labels: <?= json_encode($labels_7days) ?>,
        datasets: [{
            data: <?= json_encode($revenue_7days) ?>,
            borderColor: '#dc2626',
            backgroundColor: 'rgba(220,38,38,0.08)',
            fill: true,
            tension: 0.4,
            pointBackgroundColor: '#dc2626',
            pointRadius: 4,
            borderWidth: 2,
        }]
    },
    options: {
        ...chartDefaults,
        plugins: { legend: { display: false } },
        scales: {
            x: { grid: { display: false }, ticks: { font: { size: 11 } } },
            y: {
                grid: { color: '#f3f4f6' },
                ticks: {
                    font: { size: 11 },
                    callback: v => 'RM ' + v.toLocaleString()
                }
            }
        }
    }
});

// Orders by Status (doughnut)
<?php
$status_labels = array_column($order_statuses, 'order_status');
$status_counts = array_column($order_statuses, 'count');
$status_colors = [];
foreach ($status_labels as $s) {
    $status_colors[] = match($s) {
        'pending' => '#f59e0b',
        'processing' => '#3b82f6',
        'shipped' => '#8b5cf6',
        'delivered' => '#10b981',
        'cancelled' => '#ef4444',
        default => '#9ca3af'
    };
}
?>
new Chart(document.getElementById('statusChart'), {
    type: 'doughnut',
    data: {
        labels: <?= json_encode(array_map('ucfirst', $status_labels)) ?>,
        datasets: [{
            data: <?= json_encode($status_counts) ?>,
            backgroundColor: <?= json_encode($status_colors) ?>,
            borderWidth: 0,
        }]
    },
    options: {
        plugins: {
            legend: {
                display: true,
                position: 'right',
                labels: { font: { size: 11 }, boxWidth: 12, padding: 10 }
            }
        },
        cutout: '65%',
    }
});

// Sales Split (doughnut)
new Chart(document.getElementById('splitChart'), {
    type: 'doughnut',
    data: {
        labels: ['Physical', 'E-Book'],
        datasets: [{
            data: [<?= round($physical_sales ?? 0, 2) ?>, <?= round($ebook_sales ?? 0, 2) ?>],
            backgroundColor: ['#1e2d4a', '#dc2626'],
            borderWidth: 0,
        }]
    },
    options: {
        plugins: {
            legend: {
                display: true,
                position: 'bottom',
                labels: { font: { size: 11 }, boxWidth: 12 }
            }
        },
        cutout: '60%',
    }
});

// New Customers
new Chart(document.getElementById('customersChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($customer_labels) ?>,
        datasets: [{
            data: <?= json_encode($new_customers) ?>,
            backgroundColor: 'rgba(30,45,74,0.8)',
            borderRadius: 6,
            borderSkipped: false,
        }]
    },
    options: {
        ...chartDefaults,
        plugins: { legend: { display: false } },
        scales: {
            x: { grid: { display: false }, ticks: { font: { size: 11 } } },
            y: {
                grid: { color: '#f3f4f6' },
                ticks: { font: { size: 11 }, stepSize: 1 },
                beginAtZero: true
            }
        }
    }
});
</script>
</body>
</html>