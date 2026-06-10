<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'staff'])) {
    header('Location: login.php');
    exit;
}
require_once '../includes/db.php';
require_once '../includes/notifications.php';

date_default_timezone_set('Asia/Kuala_Lumpur');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['order_id'], $_POST['status'])) {
    $order_id = $_POST['order_id'];
    $status = $_POST['status'];
    $tracking = trim($_POST['tracking_number'] ?? '');

    $update_sql = "UPDATE orders SET order_status = ?";
    $update_params = [$status];

    // Update timestamps
    if ($status === 'processing') {
        $update_sql .= ", order_processing_at = NOW()";
    } elseif ($status === 'shipped') {
        $update_sql .= ", order_shipped_at = NOW()";
        if ($tracking) {
            $update_sql .= ", order_tracking_number = ?";
            $update_params[] = $tracking;
        } else {
            $order_courier = $pdo->prepare("SELECT order_courier FROM orders WHERE order_id = ?");
            $order_courier->execute([$order_id]);
            $courier = $order_courier->fetchColumn();
            $prefixes = [
                'jnt' => 'JT', 'ninja_van' => 'NV', 'pos_laju' => 'EF',
                'gdex' => 'GX', 'dhl' => 'DH'
            ];
            $prefix = $prefixes[$courier] ?? 'MY';
            $auto_tracking = $prefix . date('Y') . strtoupper(substr(md5(uniqid()), 0, 10));
            $update_sql .= ", order_tracking_number = ?";
            $update_params[] = $auto_tracking;
        }
    } elseif ($status === 'delivered') {
        $update_sql .= ", order_delivered_at = NOW()";
    }

    $update_sql .= " WHERE order_id = ?";
    $update_params[] = $order_id;
    $pdo->prepare($update_sql)->execute($update_params);

    $pdo->prepare("INSERT INTO admin_logs (log_admin_id, log_action, log_target_type, log_target_id, log_details) VALUES (?, 'update_order_status', 'order', ?, ?)")
        ->execute([$_SESSION['user_id'], $order_id, "Status changed to: " . $status]);

    $order_info = $pdo->prepare("SELECT order_user_id FROM orders WHERE order_id = ?");
    $order_info->execute([$order_id]);
    $order_owner = $order_info->fetchColumn();

    $order_num = '#' . str_pad($order_id, 4, '0', STR_PAD_LEFT);
    $status_messages = [
        'processing' => ['Order Update 📦', "Your order $order_num is now being processed."],
        'shipped'    => ['Order Shipped 🚚', "Your order $order_num has been shipped! It's on the way."],
        'delivered'  => ['Order Delivered ✅', "Your order $order_num has been delivered. Enjoy your manga!"],
        'cancelled'  => ['Order Cancelled ❌', "Your order $order_num has been cancelled."],
    ];

    if (isset($status_messages[$status])) {
        sendNotification($pdo, $order_owner, $status_messages[$status][0], $status_messages[$status][1], 'order');
    }

    header('Location: orders.php?success=1');
    exit;
}

$filter = $_GET['filter'] ?? 'all';
$sql = "
    SELECT o.*, u.user_name, u.user_first_name, u.user_last_name, u.user_gmail,
    a.address_recipient_name, a.address_taman, a.address_street, a.address_city, a.address_state, a.address_postal_code, a.address_country, a.address_phone
    FROM orders o
    JOIN users u ON o.order_user_id = u.user_id
    LEFT JOIN addresses a ON o.order_address_id = a.address_id
    WHERE o.order_payment_status = 'confirmed'
";
if ($filter !== 'all') {
    $sql .= " AND o.order_status = " . $pdo->quote($filter);
}
$sql .= " ORDER BY o.order_created_at DESC";
$orders = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

$counts = [];
foreach (['pending','processing','shipped','delivered','cancelled'] as $s) {
    $counts[$s] = $pdo->query("SELECT COUNT(*) FROM orders WHERE order_status = '$s' AND order_payment_status = 'confirmed'")->fetchColumn();
}
$counts['all'] = array_sum($counts);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Orders - MangaVault Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { opacity: 0; animation: fadeIn 0.4s ease forwards; }
        @keyframes fadeIn { to { opacity: 1; } }
    </style>
</head>
<body class="bg-gray-100 min-h-screen">

    <?php include '../includes/admin_navbar.php'; ?>

    <div class="max-w-7xl mx-auto px-6 py-8">

        <div class="flex justify-between items-center mb-6">
            <div>
                <h1 class="text-2xl font-black text-gray-800">Manage Orders</h1>
                <p class="text-sm text-gray-400 mt-0.5"><?= $counts['all'] ?> confirmed orders total</p>
            </div>
        </div>

        <?php if (isset($_GET['success'])): ?>
        <div class="bg-green-50 border border-green-200 text-green-700 text-sm px-4 py-3 rounded-xl mb-6">
            ✅ Order status updated and customer notified.
        </div>
        <?php endif; ?>

        <!-- Filter Tabs -->
        <div class="flex gap-1 bg-white rounded-2xl shadow-sm p-1 mb-6 overflow-x-auto">
            <?php
            $tabs = ['all' => 'All', 'pending' => 'Pending', 'processing' => 'Processing', 'shipped' => 'Shipped', 'delivered' => 'Delivered', 'cancelled' => 'Cancelled'];
            foreach ($tabs as $key => $label):
            ?>
            <a href="orders.php?filter=<?= $key ?>"
               class="px-4 py-2 rounded-xl text-sm font-semibold whitespace-nowrap transition-colors flex items-center gap-1.5
               <?= $filter === $key ? 'bg-[#1e2d4a] text-white' : 'text-gray-500 hover:text-gray-700 hover:bg-gray-50' ?>">
                <?= $label ?>
                <?php if ($counts[$key] > 0): ?>
                <span class="<?= $filter === $key ? 'bg-white/20 text-white' : 'bg-gray-100 text-gray-600' ?> text-xs px-1.5 py-0.5 rounded-full">
                    <?= $counts[$key] ?>
                </span>
                <?php endif; ?>
            </a>
            <?php endforeach; ?>
        </div>

        <?php if (count($orders) === 0): ?>
        <div class="bg-white rounded-2xl shadow-sm p-12 text-center">
            <div class="text-5xl mb-4">📦</div>
            <p class="text-gray-500 font-medium">No <?= $filter !== 'all' ? $filter : '' ?> orders found.</p>
        </div>
        <?php else: ?>
        <div class="space-y-4">
            <?php foreach ($orders as $order):
                $status_colors = [
                    'pending'    => 'bg-yellow-100 text-yellow-700',
                    'processing' => 'bg-blue-100 text-blue-700',
                    'shipped'    => 'bg-purple-100 text-purple-700',
                    'delivered'  => 'bg-green-100 text-green-700',
                    'cancelled'  => 'bg-red-100 text-red-700',
                ];
                $color = $status_colors[$order['order_status']] ?? 'bg-gray-100 text-gray-600';

                $payment_colors = [
                    'pending_confirmation' => 'bg-yellow-100 text-yellow-700',
                    'confirmed' => 'bg-green-100 text-green-700',
                    'cancelled' => 'bg-red-100 text-red-700',
                ];
                $pcolor = $payment_colors[$order['order_payment_status']] ?? 'bg-gray-100 text-gray-600';
            ?>
            <div class="bg-white rounded-2xl shadow-sm overflow-hidden">

                <!-- Header -->
                <div class="px-6 py-4 border-b border-gray-50 flex flex-wrap justify-between items-center gap-3">
                    <div class="flex items-center gap-3 flex-wrap">
                        <div>
                            <p class="font-bold text-gray-800">Order #<?= str_pad($order['order_id'], 4, '0', STR_PAD_LEFT) ?></p>
                            <p class="text-xs text-gray-400"><?= date('d M Y, h:i A', strtotime($order['order_created_at'])) ?></p>
                        </div>
                        <span class="<?= $color ?> text-xs px-3 py-1 rounded-full font-semibold capitalize"><?= $order['order_status'] ?></span>
                        <span class="<?= $pcolor ?> text-xs px-3 py-1 rounded-full font-semibold">
                            <?= $order['order_payment_status'] === 'confirmed' ? '✅ Paid' : ucfirst(str_replace('_', ' ', $order['order_payment_status'])) ?>
                        </span>
                    </div>
                    <p class="font-black text-red-600 text-lg">RM <?= number_format($order['order_total_amount'], 2) ?></p>
                </div>

                <!-- Customer + Shipping Info -->
                <div class="px-6 py-3 bg-gray-50 border-b border-gray-100 grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                    <div>
                        <p class="text-xs text-gray-400 mb-0.5">Customer</p>
                        <p class="font-semibold text-gray-700"><?= htmlspecialchars($order['user_first_name'] . ' ' . $order['user_last_name']) ?></p>
                        <p class="text-xs text-gray-400"><?= htmlspecialchars($order['user_gmail']) ?></p>
                    </div>
                    <?php if ($order['order_has_physical'] && $order['address_recipient_name']): ?>
                    <div>
                        <p class="text-xs text-gray-400 mb-0.5">Ship To</p>
                        <p class="font-semibold text-gray-700"><?= htmlspecialchars($order['address_recipient_name']) ?></p>
                        <?php if (!empty($order['address_taman'])): ?>
                        <p class="text-xs text-gray-400"><?= htmlspecialchars($order['address_taman']) ?></p>
                        <?php endif; ?>
                        <p class="text-xs text-gray-400"><?= htmlspecialchars($order['address_street']) ?></p>
                        <p class="text-xs text-gray-400"><?= htmlspecialchars($order['address_city']) ?>, <?= htmlspecialchars($order['address_state'] ?? '') ?> <?= htmlspecialchars($order['address_postal_code']) ?></p>
                        <p class="text-xs text-gray-400"><?= htmlspecialchars($order['address_country'] ?? 'Malaysia') ?></p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-400 mb-0.5">Phone</p>
                        <p class="font-semibold text-gray-700"><?= htmlspecialchars($order['address_phone']) ?></p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-400 mb-0.5">Shipping</p>
                        <p class="font-semibold text-gray-700 capitalize"><?= $order['order_shipping_method'] ?? 'standard' ?></p>
                        <p class="text-xs text-gray-400">RM <?= number_format($order['order_shipping_fee'] ?? 5, 2) ?></p>
                    </div>
                    <?php else: ?>
                    <div>
                        <p class="text-xs text-gray-400 mb-0.5">Type</p>
                        <p class="font-semibold text-gray-700">Digital Only</p>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Items -->
                <?php
                $items = $pdo->prepare("
                    SELECT oi.*, p.product_title, p.product_cover_image
                    FROM order_items oi
                    JOIN products p ON oi.order_item_product_id = p.product_id
                    WHERE oi.order_item_order_id = ?
                ");
                $items->execute([$order['order_id']]);
                $items = $items->fetchAll(PDO::FETCH_ASSOC);
                ?>
                <div class="px-6 py-4">
                    <div class="space-y-2">
                        <?php foreach ($items as $item): ?>
                        <div class="flex items-center gap-3">
                            <?php if (!empty($item['product_cover_image'])): ?>
                            <img src="../assets/images/<?= htmlspecialchars($item['product_cover_image']) ?>"
                                 class="w-9 h-12 object-cover rounded-lg flex-shrink-0">
                            <?php else: ?>
                            <div class="w-9 h-12 bg-gray-100 rounded-lg flex-shrink-0"></div>
                            <?php endif; ?>
                            <div class="flex-1">
                                <p class="text-sm font-medium text-gray-700"><?= htmlspecialchars($item['product_title']) ?></p>
                                <p class="text-xs text-gray-400"><?= $item['order_item_type'] === 'ebook' ? '📱 E-Book' : '📦 Physical' ?> × <?= $item['order_item_quantity'] ?></p>
                            </div>
                            <p class="text-sm font-semibold text-gray-700">RM <?= number_format($item['order_item_price'] * $item['order_item_quantity'], 2) ?></p>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Update Status -->
                <?php if ($order['order_status'] !== 'cancelled' && $order['order_status'] !== 'delivered'): ?>
                <div class="px-6 py-4 border-t border-gray-50 bg-gray-50">
                    <form method="POST" class="flex items-center gap-3 flex-wrap">
                        <input type="hidden" name="order_id" value="<?= $order['order_id'] ?>">
                        <?php
                        $status_flow = ['pending' => 0, 'processing' => 1, 'shipped' => 2, 'delivered' => 3, 'cancelled' => 99];
                        $current_level = $status_flow[$order['order_status']] ?? 0;
                        $is_admin = $_SESSION['role'] === 'admin';
                        ?>
                        <select name="status" class="px-3 py-2 border border-gray-200 rounded-xl text-sm focus:outline-none focus:border-red-400 bg-white">
                            <?php foreach (['pending' => 0, 'processing' => 1, 'shipped' => 2, 'delivered' => 3, 'cancelled' => 99] as $s => $level): ?>
                                <?php
                                if ($s === 'cancelled' && !$is_admin) continue;
                                if ($s !== 'cancelled' && $level < $current_level) continue;
                                ?>
                                <option value="<?= $s ?>" <?= $order['order_status'] === $s ? 'selected' : '' ?>>
                                    <?= ucfirst($s) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($order['order_has_physical']): ?>
                        <input type="text" name="tracking_number"
                               value="<?= htmlspecialchars($order['order_tracking_number'] ?? '') ?>"
                               placeholder="Tracking number (optional)"
                               class="px-3 py-2 border border-gray-200 rounded-xl text-sm focus:outline-none focus:border-red-400 bg-white flex-1 min-w-32">
                        <?php endif; ?>
                        <button type="submit"
                                class="bg-[#1e2d4a] hover:bg-[#162338] text-white text-sm font-semibold px-5 py-2 rounded-xl transition-colors">
                            Update Status
                        </button>
                    </form>
                </div>
                <?php else: ?>
                <div class="px-6 py-3 border-t border-gray-50 bg-gray-50">
                    <p class="text-xs text-gray-400">
                        <?= $order['order_status'] === 'delivered' ? '✅ Order completed' : '❌ Order cancelled' ?>
                        <?php if ($order['order_tracking_number']): ?>
                        · Tracking: <span class="font-semibold text-gray-600"><?= htmlspecialchars($order['order_tracking_number']) ?></span>
                        <?php endif; ?>
                    </p>
                </div>
                <?php endif; ?>

            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

</body>
</html>