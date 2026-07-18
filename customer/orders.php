<?php
require_once __DIR__ . '/../includes/auth.php';
require_customer();

require_once __DIR__ . '/../includes/db.php';

$user_id = $_SESSION['user_id'];

// Auto cancel expired pending orders for this user
$expired_orders = $pdo->prepare("
    SELECT o.order_id FROM orders o
    WHERE o.order_user_id = ? 
    AND o.order_payment_status = 'pending_confirmation'
    AND o.order_confirm_expires_at < NOW()
");
$expired_orders->execute([$user_id]);
$expired_orders = $expired_orders->fetchAll(PDO::FETCH_ASSOC);

foreach ($expired_orders as $exp) {
    $pdo->prepare("UPDATE orders SET order_payment_status = 'cancelled', order_status = 'cancelled' WHERE order_id = ?")
        ->execute([$exp['order_id']]);
    $exp_items = $pdo->prepare("SELECT * FROM order_items WHERE order_item_order_id = ?");
    $exp_items->execute([$exp['order_id']]);
    $exp_items = $exp_items->fetchAll(PDO::FETCH_ASSOC);
    foreach ($exp_items as $ei) {
        if ($ei['order_item_type'] === 'physical') {
            $pdo->prepare("UPDATE product_physical SET physical_stock_quantity = physical_stock_quantity + ? WHERE physical_product_id = ?")
                ->execute([$ei['order_item_quantity'], $ei['order_item_product_id']]);
        }
    }
}

$stmt = $pdo->prepare("
    SELECT o.*, a.address_recipient_name, a.address_street, a.address_city, a.address_taman
    FROM orders o
    LEFT JOIN addresses a ON o.order_address_id = a.address_id
    WHERE o.order_user_id = ?
    ORDER BY o.order_created_at DESC
");
$stmt->execute([$user_id]);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

$filter = $_GET['filter'] ?? 'all';
if ($filter !== 'all') {
    $orders = array_filter($orders, fn($o) => $o['order_status'] === $filter);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Orders - MangaVault</title>
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
            <span class="text-gray-600">My Orders</span>
        </p>

        <div class="flex gap-8 items-start">
            <?php include '../includes/customer_sidebar.php'; ?>

            <div class="flex-1 min-w-0">

                <?php if (isset($_GET['success'])): ?>
                    <div class="bg-green-50 border border-green-200 text-green-600 text-sm px-4 py-3 rounded-xl mb-5">
                        Order placed successfully!
                    </div>
                <?php endif; ?>

                <?php
                $filters = [
                    'all' => 'All Orders',
                    'pending' => 'Pending',
                    'processing' => 'Processing',
                    'shipped' => 'Shipped',
                    'delivered' => 'Delivered',
                    'cancelled' => 'Cancelled'
                ];
                ?>

                <!-- Filter Tabs - Desktop -->
                <div class="hidden lg:flex bg-white rounded-2xl shadow-sm p-1 mb-6 gap-1">
                    <?php foreach ($filters as $key => $label): ?>
                    <a href="orders.php?filter=<?= $key ?>"
                        class="px-4 py-2 rounded-xl text-sm font-medium transition-colors duration-200 <?= $filter === $key ? 'bg-red-600 text-white' : 'text-gray-500 hover:text-red-600 hover:bg-gray-50' ?>">
                        <?= $label ?>
                    </a>
                    <?php endforeach; ?>
                </div>

                <!-- Filter Dropdown - Mobile -->
                <div class="lg:hidden mb-6">
                    <select onchange="window.location.href='orders.php?filter='+this.value"
                            class="w-full bg-white border border-gray-200 rounded-xl px-4 py-3 text-sm font-medium text-gray-700 focus:outline-none focus:border-red-500 shadow-sm">
                        <?php foreach ($filters as $key => $label): ?>
                            <option value="<?= $key ?>" <?= $filter === $key ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php if (count($orders) === 0): ?>
                    <div class="bg-white rounded-2xl shadow-sm p-12 text-center">
                        <div class="text-6xl mb-4">📦</div>
                        <p class="text-gray-500 font-medium mb-2">No orders found</p>
                        <p class="text-gray-400 text-sm mb-6">You haven't placed any orders yet.</p>
                        <a href="home.php" class="bg-red-600 hover:bg-red-700 text-white font-semibold px-6 py-2.5 rounded-xl text-sm transition-colors duration-200 inline-block">
                            Start Shopping
                        </a>
                    </div>
                <?php else: ?>
                    <div class="space-y-4">
                        <?php foreach ($orders as $order): ?>
                        <div class="bg-white rounded-2xl shadow-sm overflow-hidden hover:shadow-md transition-shadow duration-200">

                            <!-- Order Header -->
                            <div class="px-6 py-4 border-b border-gray-50 flex justify-between items-center flex-wrap gap-3">
                                <div class="flex items-center gap-4">
                                    <div>
                                        <p class="font-bold text-gray-800 text-sm">Order #<?= str_pad($order['order_id'], 4, '0', STR_PAD_LEFT) ?></p>
                                        <p class="text-xs text-gray-400"><?= date('d M Y, h:i A', strtotime($order['order_created_at'])) ?></p>
                                    </div>
                                    <?php
                                    $status_colors = [
                                        'pending'    => 'bg-yellow-100 text-yellow-700',
                                        'processing' => 'bg-blue-100 text-blue-700',
                                        'shipped'    => 'bg-purple-100 text-purple-700',
                                        'delivered'  => 'bg-green-100 text-green-700',
                                        'cancelled'  => 'bg-red-100 text-red-700'
                                    ];
                                    $color = $status_colors[$order['order_status']] ?? 'bg-gray-100 text-gray-700';
                                    $payment_status_colors = [
                                        'pending_confirmation' => 'bg-yellow-100 text-yellow-700',
                                        'confirmed'            => 'bg-green-100 text-green-700',
                                        'cancelled'            => 'bg-red-100 text-red-700',
                                    ];
                                    $payment_color = $payment_status_colors[$order['order_payment_status']] ?? 'bg-gray-100 text-gray-700';
                                    $payment_labels = [
                                        'pending_confirmation' => '⏳ Awaiting Payment Confirmation',
                                        'confirmed'            => '✅ Payment Confirmed',
                                        'cancelled'            => '❌ Payment Cancelled',
                                    ];
                                    $payment_label = $payment_labels[$order['order_payment_status']] ?? $order['order_payment_status'];
                                    ?>
                                    <span class="<?= $color ?> text-xs px-3 py-1 rounded-full font-semibold capitalize">
                                        <?= $order['order_status'] ?>
                                    </span>
                                    <span class="<?= $payment_color ?> text-xs px-3 py-1 rounded-full font-semibold">
                                        <?= $payment_label ?>
                                    </span>
                                    <?php if ($order['order_payment_status'] === 'pending_confirmation'): ?>
                                    <a href="payment_waiting.php?order_id=<?= $order['order_id'] ?>"
                                        class="text-xs text-red-600 hover:underline font-semibold">
                                        Confirm Now →
                                    </a>
                                    <?php endif; ?>
                                </div>
                                <p class="font-bold text-red-600">RM <?= number_format($order['order_total_amount'], 2) ?></p>
                            </div>

                            <!-- Order Items -->
                            <?php
                            $stmt2 = $pdo->prepare("
                                SELECT oi.*, p.product_title, p.product_cover_image,
                                pe.ebook_file_path, pe.ebook_download_limit
                                FROM order_items oi
                                JOIN products p ON oi.order_item_product_id = p.product_id
                                LEFT JOIN product_ebook pe ON p.product_id = pe.ebook_product_id
                                WHERE oi.order_item_order_id = ?
                            ");
                            $stmt2->execute([$order['order_id']]);
                            $items = $stmt2->fetchAll(PDO::FETCH_ASSOC);
                            ?>
                            <div class="px-6 py-4">
                                <?php foreach ($items as $item): ?>
                                <div class="flex items-center gap-4 py-3 border-b border-gray-50 last:border-0">
                                    <?php if ($item['product_cover_image']): ?>
                                        <img src="../assets/images/<?= htmlspecialchars($item['product_cover_image']) ?>"
                                             class="w-12 h-16 object-cover rounded-lg flex-shrink-0">
                                    <?php else: ?>
                                        <div class="w-12 h-16 bg-gray-100 rounded-lg flex items-center justify-center text-gray-400 text-xs font-bold flex-shrink-0">N/A</div>
                                    <?php endif; ?>
                                    <div class="flex-1 min-w-0">
                                        <p class="font-semibold text-sm text-gray-800 truncate"><?= htmlspecialchars($item['product_title']) ?></p>
                                        <p class="text-xs text-gray-400"><?= $item['order_item_type'] === 'ebook' ? '📱 E-Book' : '📦 Physical' ?> × <?= $item['order_item_quantity'] ?></p>
                                        <p class="text-sm font-bold text-red-600 mt-1">RM <?= number_format($item['order_item_price'], 2) ?></p>
                                    </div>
                                    <div class="flex-shrink-0">
                                        <?php if ($item['order_item_type'] === 'ebook' && $item['ebook_file_path']): ?>
                                            <?php if ($item['order_item_download_count'] < $item['ebook_download_limit']): ?>
                                                <a href="download.php?item_id=<?= $item['order_item_id'] ?>"
                                                   class="bg-blue-600 hover:bg-blue-700 text-white text-xs font-semibold px-3 py-1.5 rounded-lg transition-colors duration-200 inline-block">
                                                    ↓ Download (<?= $item['ebook_download_limit'] - $item['order_item_download_count'] ?> left)
                                                </a>
                                            <?php else: ?>
                                                <span class="text-xs text-red-500 font-medium">Limit reached</span>
                                            <?php endif; ?>
                                        <?php elseif ($item['order_item_type'] === 'physical' && $order['order_status'] === 'delivered'): ?>
                                            <?php
                                            $return_check = $pdo->prepare("SELECT return_id, return_status FROM return_requests WHERE return_item_id = ?");
                                            $return_check->execute([$item['order_item_id']]);
                                            $return_req = $return_check->fetch();
                                            ?>
                                            <?php
                                            $days_since_delivery = 999;
                                            if (!empty($order['order_delivered_at'])) {
                                                $delivered = new DateTime($order['order_delivered_at']);
                                                $now = new DateTime();
                                                $days_since_delivery = $now->diff($delivered)->days;
                                            }
                                            ?>
                                            <?php if (!$return_req && $days_since_delivery <= 7): ?>
                                                <a href="return_request.php?order_id=<?= $order['order_id'] ?>&item_id=<?= $item['order_item_id'] ?>"
                                                class="bg-gray-100 hover:bg-gray-200 text-gray-700 text-xs font-semibold px-3 py-1.5 rounded-lg transition-colors duration-200 inline-block">
                                                    ↩ Return
                                                </a>
                                            <?php elseif (!$return_req && $days_since_delivery > 7): ?>
                                                <span class="text-xs text-gray-400">Return expired</span>
                                            <?php else: ?>
                                                <span class="text-xs <?= $return_req['return_status'] === 'approved' ? 'text-green-600' : ($return_req['return_status'] === 'rejected' ? 'text-red-500' : 'text-orange-500') ?> font-medium capitalize">
                                                    Return <?= $return_req['return_status'] ?>
                                                </span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-xs text-gray-300">—</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>

                            <!-- Invoice + Actions Footer -->
                            <div class="px-6 py-3 border-t border-gray-50 flex items-center justify-between">
                                <div class="flex items-center gap-4">
                                    <a href="invoice.php?order_id=<?= $order['order_id'] ?>"
                                        class="flex items-center gap-1.5 text-xs text-gray-500 hover:text-red-600 transition-colors font-medium">
                                        🧾 View Invoice
                                    </a>
                                    <?php if ($order['order_has_physical'] && $order['order_status'] !== 'pending'): ?>
                                    <a href="order_tracking.php?order_id=<?= $order['order_id'] ?>"
                                        class="flex items-center gap-1.5 text-xs text-gray-500 hover:text-red-600 transition-colors font-medium">
                                        🚚 Track Order
                                    </a>
                                    <?php endif; ?>
                                </div>
                                <p class="text-xs text-gray-400">
                                    <?= count($items) ?> item(s) · 
                                    <?= $order['order_has_physical'] ? ucfirst($order['order_shipping_method'] ?? 'standard') . ' shipping' : 'Digital only' ?>
                                </p>
                            </div>

                            <!-- Mini Timeline + Tracking -->
                            <?php if ($order['order_has_physical'] && $order['order_payment_status'] === 'confirmed'): ?>
                            <div class="px-6 py-4 bg-gray-50 border-t border-gray-100">

                                <?php if ($order['address_recipient_name']): ?>
                                <p class="text-xs text-gray-500 mb-4">
                                    📦 Ship to: <span class="font-medium text-gray-700"><?= htmlspecialchars($order['address_recipient_name']) ?><?php if (!empty($order['address_taman'])): ?>, <?= htmlspecialchars($order['address_taman']) ?><?php endif; ?>, <?= htmlspecialchars($order['address_street']) ?>, <?= htmlspecialchars($order['address_city']) ?></span>
                                </p>
                                <?php endif; ?>

                                <?php if ($order['order_status'] !== 'cancelled'):
                                    $mini_steps = [
                                        ['icon' => '🛒', 'label' => 'Placed',     'done' => true],
                                        ['icon' => '✅', 'label' => 'Paid',        'done' => true],
                                        ['icon' => '📦', 'label' => 'Processing',  'done' => in_array($order['order_status'], ['processing','shipped','delivered'])],
                                        ['icon' => '🚚', 'label' => 'Shipped',     'done' => in_array($order['order_status'], ['shipped','delivered'])],
                                        ['icon' => '🎉', 'label' => 'Delivered',   'done' => $order['order_status'] === 'delivered'],
                                    ];
                                    $done_count = count(array_filter($mini_steps, fn($s) => $s['done']));
                                    $progress = $done_count > 1 ? (($done_count - 1) / (count($mini_steps) - 1)) * 100 : 0;
                                ?>
                                <!-- Horizontal Timeline -->
                                <div class="relative flex items-start justify-between mb-4">
                                    <!-- Background line -->
                                    <div class="absolute top-4 left-4 right-4 h-0.5 bg-gray-200 z-0"></div>
                                    <!-- Progress line -->
                                    <div class="absolute top-4 left-4 h-0.5 bg-red-500 z-0 transition-all" style="width: calc(<?= $progress ?>% - 8px)"></div>

                                    <?php foreach ($mini_steps as $step): ?>
                                    <div class="flex flex-col items-center z-10 flex-1">
                                        <div class="w-8 h-8 rounded-full flex items-center justify-center text-sm
                                            <?= $step['done'] ? 'bg-red-600 text-white' : 'bg-gray-200' ?>">
                                            <?= $step['done'] ? $step['icon'] : '' ?>
                                        </div>
                                        <p class="text-xs mt-1 font-semibold <?= $step['done'] ? 'text-gray-700' : 'text-gray-300' ?> text-center leading-tight"><?= $step['label'] ?></p>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>

                                <!-- Tracking Number -->
                                <?php if ($order['order_tracking_number']):
                                    $courier_names = [
                                        'jnt' => 'J&T Express', 'ninja_van' => 'Ninja Van',
                                        'pos_laju' => 'Pos Laju', 'gdex' => 'GDex', 'dhl' => 'DHL Express'
                                    ];
                                    $courier_key = $order['order_courier'] ?? null;
                                    $courier_name = $courier_names[$courier_key] ?? 'Courier';
                                ?>
                                <div class="flex items-center gap-3 bg-white rounded-xl p-3 border border-gray-100">
                                    <span class="text-lg">🚚</span>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-xs text-gray-400"><?= $courier_name ?></p>
                                        <p class="font-mono font-bold text-sm text-gray-800"><?= htmlspecialchars($order['order_tracking_number']) ?></p>
                                    </div>
                                    <a href="order_tracking.php?order_id=<?= $order['order_id'] ?>"
                                       class="bg-red-600 hover:bg-red-700 text-white text-xs font-semibold px-3 py-2 rounded-lg transition-colors flex-shrink-0">
                                        Track →
                                    </a>
                                </div>
                                <?php endif; ?>

                            </div>
                            <?php endif; ?>

                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

</body>
</html>