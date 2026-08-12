<?php
require_once __DIR__ . '/../includes/auth.php';
require_admin();

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/money_helper.php';
require_once __DIR__ . '/../includes/notifications.php';

date_default_timezone_set('Asia/Kuala_Lumpur');

$allowed_statuses = [
    'pending',
    'processing',
    'shipped',
    'delivered',
    'cancelled',
];

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['order_id'], $_POST['status'])
) {
    csrf_verify();

    $order_id = filter_input(
        INPUT_POST,
        'order_id',
        FILTER_VALIDATE_INT,
        [
            'options' => [
                'min_range' => 1,
            ],
        ]
    );

    $status = $_POST['status'] ?? null;
    $raw_tracking = $_POST['tracking_number'] ?? '';

    if (!is_string($raw_tracking)) {
        header('Location: orders.php');
        exit;
    }

    $tracking = trim($raw_tracking);
    $tracking_length = function_exists('mb_strlen')
        ? mb_strlen($tracking, 'UTF-8')
        : strlen($tracking);

    $status_levels = [
        'pending' => 0,
        'processing' => 1,
        'shipped' => 2,
        'delivered' => 3,
    ];

    if (
        $order_id === false ||
        $order_id === null ||
        !is_string($status) ||
        !in_array($status, $allowed_statuses, true) ||
        $tracking_length > 50
    ) {
        header('Location: orders.php');
        exit;
    }

    $order_check = $pdo->prepare(
        "SELECT order_status,
                order_payment_status,
                order_courier,
                order_user_id
         FROM orders
         WHERE order_id = ?"
    );
    $order_check->execute([$order_id]);
    $order_data = $order_check->fetch(PDO::FETCH_ASSOC);

    if (
        !$order_data ||
        $order_data['order_payment_status'] !== 'confirmed' ||
        in_array(
            $order_data['order_status'],
            ['delivered', 'cancelled'],
            true
        )
    ) {
        header('Location: orders.php');
        exit;
    }

    $current_status = $order_data['order_status'];

    if ($status === $current_status) {
        header('Location: orders.php');
        exit;
    }

    if (
        $status !== 'cancelled' &&
        (
            !isset($status_levels[$current_status]) ||
            !isset($status_levels[$status]) ||
            $status_levels[$status] !==
                $status_levels[$current_status] + 1
        )
    ) {
        header('Location: orders.php');
        exit;
    }

    $update_sql = "UPDATE orders SET order_status = ?";
    $update_params = [$status];

    if ($status === 'processing') {
        $update_sql .= ", order_processing_at = NOW()";
    } elseif ($status === 'shipped') {
        $update_sql .= ", order_shipped_at = NOW()";

        if ($tracking !== '') {
            $update_sql .= ", order_tracking_number = ?";
            $update_params[] = $tracking;
        } else {
            $prefixes = [
                'jnt' => 'JT',
                'ninja_van' => 'NV',
                'pos_laju' => 'EF',
                'gdex' => 'GX',
                'dhl' => 'DH',
            ];

            $prefix =
                $prefixes[$order_data['order_courier']] ?? 'MY';

            $auto_tracking =
                $prefix .
                date('Y') .
                strtoupper(substr(md5(uniqid()), 0, 10));

            $update_sql .= ", order_tracking_number = ?";
            $update_params[] = $auto_tracking;
        }
    } elseif ($status === 'delivered') {
        $update_sql .= ", order_delivered_at = NOW()";
    }

    $update_sql .=
        " WHERE order_id = ?
          AND order_status = ?
          AND order_payment_status = 'confirmed'";

    $update_params[] = $order_id;
    $update_params[] = $current_status;

    $update_stmt = $pdo->prepare($update_sql);
    $update_stmt->execute($update_params);

    if ($update_stmt->rowCount() === 0) {
        header('Location: orders.php');
        exit;
    }

    $pdo->prepare(
        "INSERT INTO admin_logs
         (
             log_admin_id,
             log_action,
             log_target_type,
             log_target_id,
             log_details
         )
         VALUES (
             ?,
             'update_order_status',
             'order',
             ?,
             ?
         )"
    )->execute([
        $_SESSION['user_id'],
        $order_id,
        'Status changed to: ' . $status,
    ]);

    $order_num =
        '#' . str_pad((string) $order_id, 4, '0', STR_PAD_LEFT);

    $status_messages = [
        'processing' => [
            'Order Update 📦',
            "Your order $order_num is now being processed.",
        ],
        'shipped' => [
            'Order Shipped 🚚',
            "Your order $order_num has been shipped! It's on the way.",
        ],
        'delivered' => [
            'Order Delivered ✅',
            "Your order $order_num has been delivered. Enjoy your manga!",
        ],
        'cancelled' => [
            'Order Cancelled ❌',
            "Your order $order_num has been cancelled.",
        ],
    ];

    if (isset($status_messages[$status])) {
        sendNotification(
            $pdo,
            (int) $order_data['order_user_id'],
            $status_messages[$status][0],
            $status_messages[$status][1],
            'order'
        );
    }

    header('Location: orders.php?success=1');
    exit;
}

$allowed_filters = [
    'all',
    'pending',
    'processing',
    'shipped',
    'delivered',
    'cancelled',
];

$filter = $_GET['filter'] ?? 'all';

if (
    !is_string($filter) ||
    !in_array($filter, $allowed_filters, true)
) {
    $filter = 'all';
}

$sql = "
    SELECT
        o.*,
        u.user_name,
        u.user_first_name,
        u.user_last_name,
        u.user_gmail,
        o.order_address_recipient_name
            AS address_recipient_name,
        o.order_address_taman
            AS address_taman,
        o.order_address_street
            AS address_street,
        o.order_address_city
            AS address_city,
        o.order_address_state
            AS address_state,
        o.order_address_postal_code
            AS address_postal_code,
        o.order_address_country
            AS address_country,
        o.order_address_phone
            AS address_phone
    FROM orders o
    JOIN users u
        ON o.order_user_id = u.user_id
    WHERE o.order_payment_status = 'confirmed'
";

$params = [];

if ($filter !== 'all') {
    $sql .= " AND o.order_status = ?";
    $params[] = $filter;
}

$sql .= " ORDER BY o.order_created_at DESC";

$order_query = $pdo->prepare($sql);
$order_query->execute($params);
$orders = $order_query->fetchAll(PDO::FETCH_ASSOC);

$counts = [];
foreach (
    [
        'pending',
        'processing',
        'shipped',
        'delivered',
        'cancelled',
    ] as $count_status
) {
    $count_stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM orders
         WHERE order_status = ?
         AND order_payment_status = 'confirmed'"
    );
    $count_stmt->execute([$count_status]);
    $counts[$count_status] = (int) $count_stmt->fetchColumn();
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

        <div class="flex gap-1 bg-white rounded-2xl shadow-sm p-1 mb-6 overflow-x-auto">
            <?php
            $tabs = [
                'all' => 'All',
                'pending' => 'Pending',
                'processing' => 'Processing',
                'shipped' => 'Shipped',
                'delivered' => 'Delivered',
                'cancelled' => 'Cancelled',
            ];
            foreach ($tabs as $key => $label):
            ?>
            <a href="orders.php?filter=<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>"
               class="px-4 py-2 rounded-xl text-sm font-semibold whitespace-nowrap transition-colors flex items-center gap-1.5
               <?= $filter === $key ? 'bg-[#1e2d4a] text-white' : 'text-gray-500 hover:text-gray-700 hover:bg-gray-50' ?>">
                <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
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
            <p class="text-gray-500 font-medium">No <?= $filter !== 'all' ? htmlspecialchars($filter, ENT_QUOTES, 'UTF-8') : '' ?> orders found.</p>
        </div>
        <?php else: ?>
        <div class="space-y-4">
            <?php foreach ($orders as $order):
                $status_colors = [
                    'pending' => 'bg-yellow-100 text-yellow-700',
                    'processing' => 'bg-blue-100 text-blue-700',
                    'shipped' => 'bg-purple-100 text-purple-700',
                    'delivered' => 'bg-green-100 text-green-700',
                    'cancelled' => 'bg-red-100 text-red-700',
                ];
                $color =
                    $status_colors[$order['order_status']]
                    ?? 'bg-gray-100 text-gray-600';

                $payment_colors = [
                    'pending_confirmation' =>
                        'bg-yellow-100 text-yellow-700',
                    'confirmed' =>
                        'bg-green-100 text-green-700',
                    'cancelled' =>
                        'bg-red-100 text-red-700',
                ];
                $pcolor =
                    $payment_colors[$order['order_payment_status']]
                    ?? 'bg-gray-100 text-gray-600';

                $order_total_sen = moneyDecimalToSen(
                    (string) $order['order_total_amount']
                );

                $shipping_fee_sen = moneyDecimalToSen(
                    (string) (
                        $order['order_shipping_fee']
                        ?? '5.00'
                    )
                );
            ?>
            <div class="bg-white rounded-2xl shadow-sm overflow-hidden">

                <div class="px-6 py-4 border-b border-gray-50 flex flex-wrap justify-between items-center gap-3">
                    <div class="flex items-center gap-3 flex-wrap">
                        <div>
                            <p class="font-bold text-gray-800">Order #<?= str_pad((string) ((int) $order['order_id']), 4, '0', STR_PAD_LEFT) ?></p>
                            <p class="text-xs text-gray-400"><?= date('d M Y, h:i A', strtotime($order['order_created_at'])) ?></p>
                        </div>
                        <span class="<?= $color ?> text-xs px-3 py-1 rounded-full font-semibold capitalize"><?= htmlspecialchars($order['order_status'], ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="<?= $pcolor ?> text-xs px-3 py-1 rounded-full font-semibold">
                            <?= $order['order_payment_status'] === 'confirmed'
                                ? '✅ Paid'
                                : htmlspecialchars(
                                    ucfirst(
                                        str_replace(
                                            '_',
                                            ' ',
                                            $order['order_payment_status']
                                        )
                                    ),
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                        </span>
                    </div>
                    <p class="font-black text-red-600 text-lg">RM <?= moneyFormatSen($order_total_sen) ?></p>
                </div>

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
                        <p class="font-semibold text-gray-700 capitalize"><?= htmlspecialchars($order['order_shipping_method'] ?? 'standard') ?></p>
                        <p class="text-xs text-gray-400">RM <?= moneyFormatSen($shipping_fee_sen) ?></p>
                    </div>
                    <?php else: ?>
                    <div>
                        <p class="text-xs text-gray-400 mb-0.5">Type</p>
                        <p class="font-semibold text-gray-700">Digital Only</p>
                    </div>
                    <?php endif; ?>
                </div>

                <?php
                $items = $pdo->prepare("
                    SELECT
                        oi.*,
                        oi.order_item_product_title
                            AS product_title,
                        oi.order_item_product_cover_image
                            AS product_cover_image
                    FROM order_items oi
                    WHERE oi.order_item_order_id = ?
                ");
                $items->execute([(int) $order['order_id']]);
                $items = $items->fetchAll(PDO::FETCH_ASSOC);
                ?>
                <div class="px-6 py-4">
                    <div class="space-y-2">
                        <?php foreach ($items as $item):
                            $quantity =
                                (int) $item['order_item_quantity'];
                            $unit_price_sen =
                                moneyDecimalToSen(
                                    (string) $item[
                                        'order_item_price'
                                    ]
                                );

                            if (
                                $quantity <= 0 ||
                                $unit_price_sen >
                                    intdiv(
                                        9999999999,
                                        $quantity
                                    )
                            ) {
                                throw new RuntimeException(
                                    'An order item has an invalid amount.'
                                );
                            }

                            $line_total_sen =
                                $unit_price_sen * $quantity;
                        ?>
                        <div class="flex items-center gap-3">
                            <?php if (!empty($item['product_cover_image'])): ?>
                            <img src="../assets/images/<?= htmlspecialchars($item['product_cover_image']) ?>"
                                 class="w-9 h-12 object-cover rounded-lg flex-shrink-0">
                            <?php else: ?>
                            <div class="w-9 h-12 bg-gray-100 rounded-lg flex-shrink-0"></div>
                            <?php endif; ?>
                            <div class="flex-1">
                                <p class="text-sm font-medium text-gray-700"><?= htmlspecialchars($item['product_title']) ?></p>
                                <p class="text-xs text-gray-400"><?= $item['order_item_type'] === 'ebook' ? '📱 E-Book' : '📦 Physical' ?> × <?= $quantity ?></p>
                            </div>
                            <p class="text-sm font-semibold text-gray-700">RM <?= moneyFormatSen($line_total_sen) ?></p>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <?php if ($order['order_status'] !== 'cancelled' && $order['order_status'] !== 'delivered'): ?>
                <div class="px-6 py-4 border-t border-gray-50 bg-gray-50">
                    <div class="space-y-4">
                        <?php
                        $status_flow = [
                            'pending' => 0,
                            'processing' => 1,
                            'shipped' => 2,
                            'delivered' => 3,
                            'cancelled' => 99,
                        ];

                        $current_level =
                            $status_flow[$order['order_status']] ?? 0;

                        $next_status = array_search(
                            $current_level + 1,
                            $status_flow,
                            true
                        );

                        $action_labels = [
                            'processing' => 'Mark as Processing',
                            'shipped' => 'Mark as Shipped',
                            'delivered' => 'Mark as Delivered',
                        ];

                        $next_action_label =
                            $action_labels[$next_status] ?? 'Update Status';
                        ?>

                        <div class="flex flex-wrap items-center gap-2">
                            <?php foreach ($status_flow as $option_status => $level): ?>
                                <?php
                                if ($option_status === 'cancelled') {
                                    continue;
                                }

                                $is_current_status =
                                    $order['order_status'] === $option_status;

                                $is_next_status =
                                    $option_status === $next_status;

                                if ($is_current_status) {
                                    $badge_class =
                                        'bg-gray-700 text-white border-gray-700';
                                } elseif ($is_next_status) {
                                    $badge_class =
                                        'bg-blue-50 text-blue-700 border-blue-200';
                                } else {
                                    $badge_class =
                                        'bg-gray-100 text-gray-400 border-gray-200';
                                }
                                ?>
                                <span
                                    class="inline-flex items-center rounded-full border px-3 py-1.5 text-xs font-semibold <?= $badge_class ?>"
                                >
                                    <?= htmlspecialchars(ucfirst($option_status), ENT_QUOTES, 'UTF-8') ?>
                                    <?php if ($is_current_status): ?>
                                        <span class="ml-1 text-[10px] uppercase tracking-wide opacity-90">
                                            Current
                                        </span>
                                    <?php elseif ($is_next_status): ?>
                                        <span class="ml-1 text-[10px] uppercase tracking-wide opacity-90">
                                            Next
                                        </span>
                                    <?php endif; ?>
                                </span>
                            <?php endforeach; ?>
                        </div>

                        <p class="text-xs text-gray-500">
                            Previous statuses are locked. Only the next status can be updated.
                        </p>

                        <div class="flex flex-wrap items-center gap-3">
                            <?php if ($next_status !== false): ?>
                                <form method="POST" class="flex items-center gap-3 flex-wrap">
                                    <?php csrf_field(); ?>
                                    <input
                                        type="hidden"
                                        name="order_id"
                                        value="<?= (int) $order['order_id'] ?>"
                                    >
                                    <input
                                        type="hidden"
                                        name="status"
                                        value="<?= htmlspecialchars($next_status, ENT_QUOTES, 'UTF-8') ?>"
                                    >

                                    <?php if ($order['order_has_physical'] && $next_status === 'shipped'): ?>
                                    <input
                                        type="text"
                                        name="tracking_number"
                                        maxlength="50"
                                        value="<?= htmlspecialchars($order['order_tracking_number'] ?? '') ?>"
                                        placeholder="Tracking number (optional)"
                                        class="px-3 py-2 border border-gray-200 rounded-xl text-sm focus:outline-none focus:border-blue-400 bg-white min-w-[220px]"
                                    >
                                    <?php endif; ?>

                                    <button
                                        type="submit"
                                        class="bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold px-5 py-2 rounded-xl transition-colors"
                                    >
                                        <?= htmlspecialchars($next_action_label, ENT_QUOTES, 'UTF-8') ?>
                                    </button>
                                </form>
                            <?php endif; ?>

                            <form method="POST" onsubmit="return confirm('Are you sure you want to cancel this order?');">
                                <?php csrf_field(); ?>
                                <input
                                    type="hidden"
                                    name="order_id"
                                    value="<?= (int) $order['order_id'] ?>"
                                >
                                <input
                                    type="hidden"
                                    name="status"
                                    value="cancelled"
                                >
                                <button
                                    type="submit"
                                    class="bg-red-50 hover:bg-red-100 text-red-700 border border-red-200 text-sm font-semibold px-5 py-2 rounded-xl transition-colors"
                                >
                                    Cancel Order
                                </button>
                            </form>
                        </div>
                    </div>
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