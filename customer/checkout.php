<?php
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'customer') {
    header('Location: login.php');
    exit;
}
require_once '../includes/db.php';

$user_id = $_SESSION['user_id'];
// Clear stale payment lock
if (isset($_SESSION['payment_lock']) && $_SESSION['payment_lock']['user_id'] == $user_id) {
    $diff = time() - $_SESSION['payment_lock']['locked_at'];
    if ($diff >= 300) {
        unset($_SESSION['payment_lock']);
    }
}
// Get selected items from cart
$selected_raw = $_GET['selected_items'] ?? $_POST['selected_items'] ?? '';
$selected_ids = array_filter(array_map('intval', explode(',', $selected_raw)));

if (empty($selected_ids)) {
    header('Location: cart.php');
    exit;
}

$placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
$stmt = $pdo->prepare("
    SELECT ci.*, p.product_title, p.product_price, p.product_type,
    pp.physical_stock_quantity, pe.ebook_download_limit,
    p.product_cover_image
    FROM cart_items ci
    JOIN products p ON ci.cart_item_product_id = p.product_id
    LEFT JOIN product_physical pp ON p.product_id = pp.physical_product_id
    LEFT JOIN product_ebook pe ON p.product_id = pe.ebook_product_id
    WHERE ci.cart_item_user_id = ?
    AND ci.cart_item_id IN ($placeholders)
");
$stmt->execute(array_merge([$user_id], $selected_ids));
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (count($items) === 0) {
    header('Location: cart.php');
    exit;
}

$total = 0;
$has_physical = false;
foreach ($items as $item) {
    $total += $item['product_price'] * $item['cart_item_quantity'];
    if ($item['product_type'] === 'physical') $has_physical = true;
}

$addresses = $pdo->prepare("SELECT * FROM addresses WHERE address_user_id = ? ORDER BY address_is_default DESC");
$addresses->execute([$user_id]);
$addresses = $addresses->fetchAll(PDO::FETCH_ASSOC);

$error = '';
$voucher = null;
$discount_amount = 0;
$voucher_code = '';

// Handle voucher check (AJAX)
if (isset($_POST['check_voucher'])) {
    $code = strtoupper(trim($_POST['voucher_code']));
    $cart_total = floatval($_POST['cart_total']);
    
    $v = $pdo->prepare("
        SELECT v.* FROM vouchers v
        WHERE v.voucher_code = ? 
        AND v.voucher_is_active = 1
        AND (v.voucher_start_date IS NULL OR v.voucher_start_date <= NOW())
        AND (v.voucher_end_date IS NULL OR v.voucher_end_date >= NOW())
        AND EXISTS (
            SELECT 1 FROM user_vouchers uv
            WHERE uv.uv_voucher_id = v.voucher_id
            AND uv.uv_user_id = ?
            AND uv.uv_is_used = 0
            AND uv.uv_status = 'available'
            AND (uv.uv_expires_at IS NULL OR uv.uv_expires_at >= NOW())
        )
    ");
    $v->execute([$code, $user_id]);
    $v = $v->fetch(PDO::FETCH_ASSOC);
    
    if (!$v) {
        echo json_encode(['success' => false, 'message' => 'Invalid or expired voucher.']);
    } elseif ($cart_total < $v['voucher_min_order']) {
        echo json_encode(['success' => false, 'message' => 'Minimum order RM ' . number_format($v['voucher_min_order'], 2) . ' required.']);
    } else {
        // Check if user already used this voucher
        $used = $pdo->prepare("SELECT usage_id FROM voucher_usage WHERE usage_voucher_id = ? AND usage_user_id = ?");
        $used->execute([$v['voucher_id'], $user_id]);
        if ($used->rowCount() > 0) {
            echo json_encode(['success' => false, 'message' => 'You have already used this voucher.']);
        } else {
            if ($v['voucher_type'] === 'percentage') {
                $discount = $cart_total * ($v['voucher_value'] / 100);
                if ($v['voucher_max_discount']) {
                    $discount = min($discount, $v['voucher_max_discount']);
                }
            } else {
                $discount = $v['voucher_value'];
            }
            $discount = min($discount, $cart_total);
            echo json_encode([
                'success' => true,
                'message' => 'Voucher applied!',
                'discount' => round($discount, 2),
                'voucher_id' => $v['voucher_id'],
                'voucher_type' => $v['voucher_type'],
                'voucher_value' => $v['voucher_value'],
            ]);
        }
    }
    exit;
}

// Check payment lock (5 minutes)
if (isset($_SESSION['payment_lock']) && $_SESSION['payment_lock']['user_id'] == $user_id) {
    $diff = (time() - $_SESSION['payment_lock']['locked_at']);
    if ($diff < 300) {
        $remaining = 300 - $diff;
        $mins = floor($remaining / 60);
        $secs = $remaining % 60;
        $_SESSION['payment_lock_msg'] = "You have a pending payment. Please wait {$mins}m {$secs}s before placing a new order.";
        header('Location: cart.php');
        exit;
    } else {
        unset($_SESSION['payment_lock']);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $address_id = null;
    $shipping_method = $_POST['shipping_method'] ?? 'standard';
    $couriers_data = [
        'jnt'       => ['peninsular_std' => 4.90,  'peninsular_exp' => 9.90,  'east_std' => 11.90, 'east_exp' => 19.90],
        'ninja_van' => ['peninsular_std' => 5.90,  'peninsular_exp' => 11.90, 'east_std' => 13.90, 'east_exp' => 21.90],
        'pos_laju'  => ['peninsular_std' => 6.90,  'peninsular_exp' => 14.90, 'east_std' => 14.90, 'east_exp' => 24.90],
        'gdex'      => ['peninsular_std' => 8.90,  'peninsular_exp' => 16.90, 'east_std' => 17.90, 'east_exp' => 29.90],
        'dhl'       => ['peninsular_std' => 14.90, 'peninsular_exp' => 24.90, 'east_std' => 24.90, 'east_exp' => 39.90],
    ];
    $shipping_courier = strtolower($_POST['shipping_courier'] ?? 'jnt');
    $shipping_zone = $_POST['shipping_zone'] ?? 'peninsular';
    $shipping_type = str_contains($_POST['shipping_method'] ?? '', 'express') ? 'exp' : 'std';
    $zone_key = $shipping_zone === 'east_malaysia' ? 'east' : 'peninsular';
    $fee_key = $zone_key . '_' . $shipping_type;
    $shipping_fee = $has_physical ? ($couriers_data[$shipping_courier][$fee_key] ?? 4.90) : 0;

    // Apply voucher
    $voucher_code_input = strtoupper(trim($_POST['voucher_code_applied'] ?? ''));
    $discount_amount = 0;
    $applied_voucher = null;

    if ($voucher_code_input) {
    $v = $pdo->prepare("
        SELECT * FROM vouchers 
        WHERE voucher_code = ? 
        AND voucher_is_active = 1
        AND (voucher_start_date IS NULL OR voucher_start_date <= NOW())
        AND (voucher_end_date IS NULL OR voucher_end_date >= NOW())
    ");
    $v->execute([$voucher_code_input]);
    $applied_voucher = $v->fetch(PDO::FETCH_ASSOC);

    if ($applied_voucher && $total >= $applied_voucher['voucher_min_order']) {
        if ($applied_voucher['voucher_type'] === 'percentage') {
            $discount_amount = $total * ($applied_voucher['voucher_value'] / 100);
            if ($applied_voucher['voucher_max_discount']) {
                $discount_amount = min($discount_amount, $applied_voucher['voucher_max_discount']);
            }
        } else {
            $discount_amount = $applied_voucher['voucher_value'];
        }
        $discount_amount = min($discount_amount, $total);
    }
}

$final_total = max(0, $total - $discount_amount + $shipping_fee);

    if ($has_physical) {
        if ($_POST['address_option'] === 'saved' && !empty($_POST['address_id'])) {
            $address_id = $_POST['address_id'];
        } elseif ($_POST['address_option'] === 'new') {
            $recipient = trim($_POST['address_recipient_name']);
            $taman = trim($_POST['address_taman'] ?? '');
            $street = trim($_POST['address_street']);
            $city = trim($_POST['address_city']);
            $postal = trim($_POST['address_postal_code']);
            $country = trim($_POST['address_country']);
            $phone = trim($_POST['address_phone']);

            if (empty($recipient) || empty($street) || empty($city) || empty($phone)) {
                $error = "Please fill in all required shipping fields.";
            } else {
                $pdo->prepare("INSERT INTO addresses (address_user_id, address_recipient_name, address_taman, address_street, address_city, address_postal_code, address_country, address_phone) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$user_id, $recipient, $taman, $street, $city, $postal, $country, $phone]);
                $address_id = $pdo->lastInsertId();
            }
        }
    }

    // Check if user has a pending order within 5 minutes
    $pending_voucher_check = $pdo->prepare("
        SELECT uv_pending_at FROM user_vouchers 
        WHERE uv_user_id = ? 
        AND uv_status = 'pending'
        AND uv_is_used = 0
        AND uv_pending_at IS NOT NULL
        AND uv_pending_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
        LIMIT 1
    ");
    $pending_voucher_check->execute([$user_id]);
    $pending_voucher = $pending_voucher_check->fetch(PDO::FETCH_ASSOC);

    if ($pending_voucher) {
        $pending_at = new DateTime($pending_voucher['uv_pending_at']);
        $expires = $pending_at->modify('+5 minutes');
        $now = new DateTime();
        $diff = $expires->getTimestamp() - $now->getTimestamp();
        $mins = floor($diff / 60);
        $secs = $diff % 60;
        $_SESSION['payment_lock_msg'] = "You have a pending payment. Please wait {$mins}m {$secs}s before placing a new order.";
        header('Location: cart.php');
        exit;
    }

    if (empty($error)) {
        $_SESSION['pending_order'] = [
            'user_id' => $user_id,
            'total' => $final_total,
            'has_physical' => $has_physical,
            'address_id' => $address_id,
            'shipping_method' => $shipping_method,
            'shipping_fee' => $shipping_fee,
            'voucher_code' => $voucher_code_input ?: null,
            'discount_amount' => $discount_amount,
            'voucher_id' => $applied_voucher['voucher_id'] ?? null,
            'items' => $items,
            'shipping_courier' => $shipping_courier,
            'shipping_zone' => $shipping_zone,
        ];

        // Set voucher to pending immediately
        if (!empty($voucher_code_input) && $applied_voucher) {
            $pdo->prepare("UPDATE user_vouchers SET uv_status = 'pending', uv_pending_at = NOW() WHERE uv_voucher_id = ? AND uv_user_id = ? AND uv_is_used = 0")
                ->execute([$applied_voucher['voucher_id'], $user_id]);
        }

        // Set payment session lock
        $_SESSION['payment_lock'] = [
            'user_id' => $user_id,
            'locked_at' => time()
        ];

        header('Location: payment_gateway.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout - MangaVault</title>
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
            <a href="cart.php" class="hover:text-red-600 transition-colors">Cart</a>
            <span class="mx-2">›</span>
            <span class="text-gray-600">Checkout</span>
        </p>

        <?php if ($error): ?>
            <div class="bg-red-50 border border-red-200 text-red-600 text-sm px-4 py-3 rounded-xl mb-5"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" id="checkoutForm">
        <input type="hidden" name="selected_items" value="<?= htmlspecialchars($selected_raw) ?>">
        <div class="flex gap-6 items-start flex-col lg:flex-row">

            <!-- Left -->
            <div class="flex-1 w-full space-y-6">

                <?php if ($has_physical): ?>
                <!-- Shipping -->
                <div class="bg-white rounded-2xl shadow-sm p-6">
                    <h3 class="font-bold text-gray-800 mb-5 flex items-center gap-2">
                        <svg class="w-5 h-5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                        Shipping Details
                    </h3>

                    <?php if (count($addresses) > 0): ?>
                    <div class="space-y-3 mb-4">
                        <label class="flex items-center gap-3 p-3 border border-gray-200 rounded-xl cursor-pointer hover:border-red-300 transition-colors">
                            <input type="radio" name="address_option" value="saved" checked
                                   onchange="toggleAddress('saved')" class="accent-red-600">
                            <span class="text-sm font-medium text-gray-700">Use saved address</span>
                        </label>
                        <div id="saved_address_div" class="pl-4">
                            <select name="address_id" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:outline-none focus:border-red-500 transition-colors">
                                <?php foreach ($addresses as $addr): ?>
                                    <option value="<?= $addr['address_id'] ?>">
                                        <?= htmlspecialchars($addr['address_recipient_name']) ?> —
                                        <?= htmlspecialchars($addr['address_street']) ?>,
                                        <?= htmlspecialchars($addr['address_city']) ?>
                                        <?= $addr['address_is_default'] ? '(Default)' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <label class="flex items-center gap-3 p-3 border border-gray-200 rounded-xl cursor-pointer hover:border-red-300 transition-colors">
                            <input type="radio" name="address_option" value="new"
                                   onchange="toggleAddress('new')" class="accent-red-600">
                            <span class="text-sm font-medium text-gray-700">Use new address</span>
                        </label>
                    </div>
                    <?php else: ?>
                        <input type="hidden" name="address_option" value="new">
                    <?php endif; ?>

                    <div id="new_address_div" class="<?= count($addresses) > 0 ? 'hidden' : '' ?> space-y-4">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-1">Recipient Name *</label>
                                <input type="text" name="address_recipient_name"
                                       class="w-full px-3 py-2.5 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-red-500 transition-colors">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-1">Phone *</label>
                                <input type="text" name="address_phone"
                                       oninput="this.value = this.value.replace(/[^0-9]/g, '')"
                                       maxlength="11"
                                       class="w-full px-3 py-2.5 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-red-500 transition-colors"
                                       placeholder="01234567890">
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-500 mb-1">Taman / Apartment</label>
                            <input type="text" name="address_taman" placeholder="e.g. Taman Desa Jaya"
                                   class="w-full px-3 py-2.5 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-red-500 transition-colors">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-500 mb-1">Street Address *</label>
                            <input type="text" name="address_street" placeholder="e.g. No. 12, Jalan ABC"
                                   class="w-full px-3 py-2.5 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-red-500 transition-colors">
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-1">City *</label>
                                <input type="text" name="address_city"
                                       class="w-full px-3 py-2.5 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-red-500 transition-colors">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-1">Postal Code</label>
                                <input type="text" name="address_postal_code"
                                       class="w-full px-3 py-2.5 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-red-500 transition-colors">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-1">Country</label>
                                <input type="text" name="address_country" value="Malaysia"
                                       class="w-full px-3 py-2.5 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-red-500 transition-colors">
                            </div>
                        </div>
                        <label class="flex items-center gap-2 text-sm text-gray-600">
                            <input type="checkbox" name="save_address" class="accent-red-600">
                            Save this address for future orders
                        </label>
                    </div>
                </div>

                <!-- Delivery Method -->
                <?php
                $couriers = [
                    'jnt'       => ['name' => 'J&T Express',  'logo' => '🟡', 'peninsular_std' => 4.90,  'peninsular_exp' => 9.90,  'east_std' => 11.90, 'east_exp' => 19.90],
                    'ninja_van' => ['name' => 'Ninja Van',     'logo' => '⚫', 'peninsular_std' => 5.90,  'peninsular_exp' => 11.90, 'east_std' => 13.90, 'east_exp' => 21.90],
                    'pos_laju'  => ['name' => 'Pos Laju',      'logo' => '🔴', 'peninsular_std' => 6.90,  'peninsular_exp' => 14.90, 'east_std' => 14.90, 'east_exp' => 24.90],
                    'gdex'      => ['name' => 'GDex',          'logo' => '🟠', 'peninsular_std' => 8.90,  'peninsular_exp' => 16.90, 'east_std' => 17.90, 'east_exp' => 29.90],
                    'dhl'       => ['name' => 'DHL Express',   'logo' => '🔴', 'peninsular_std' => 14.90, 'peninsular_exp' => 24.90, 'east_std' => 24.90, 'east_exp' => 39.90],
                ];
                ?>
                <div class="bg-white rounded-2xl shadow-sm p-6">
                    <h3 class="font-bold text-gray-800 mb-5 flex items-center gap-2">
                        <svg class="w-5 h-5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"></path></svg>
                        Delivery Method
                    </h3>

                    <!-- Step 1: Zone -->
                    <div class="mb-6">
                        <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-3">Step 1 — Select Delivery Zone</p>
                        <div class="grid grid-cols-2 gap-3">
                            <button type="button" onclick="selectZone('peninsular')"
                                    id="zone_peninsular"
                                    class="flex items-center gap-3 p-4 border-2 border-gray-100 rounded-xl hover:border-red-300 transition-colors text-left">
                                <span class="text-2xl">🇲🇾</span>
                                <div>
                                    <p class="font-semibold text-sm text-gray-800">Peninsular</p>
                                    <p class="text-xs text-gray-400">West Malaysia</p>
                                </div>
                            </button>
                            <button type="button" onclick="selectZone('east_malaysia')"
                                    id="zone_east"
                                    class="flex items-center gap-3 p-4 border-2 border-gray-100 rounded-xl hover:border-red-300 transition-colors text-left">
                                <span class="text-2xl">🌴</span>
                                <div>
                                    <p class="font-semibold text-sm text-gray-800">East Malaysia</p>
                                    <p class="text-xs text-gray-400">Sabah & Sarawak</p>
                                </div>
                            </button>
                        </div>
                    </div>

                    <!-- Step 2: Courier (hidden until zone selected) -->
                    <div id="courierSection" class="hidden">
                        <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-3">Step 2 — Select Courier & Speed</p>
                        <div class="space-y-3">
                            <?php foreach ($couriers as $key => $c): ?>
                            <div class="border-2 border-gray-100 rounded-xl overflow-hidden">
                                <!-- Courier header -->
                                <div class="flex items-center gap-3 px-4 py-3 bg-gray-50">
                                    <span class="text-lg"><?= $c['logo'] ?></span>
                                    <p class="font-bold text-sm text-gray-800"><?= $c['name'] ?></p>
                                </div>
                                <!-- Standard + Express options -->
                                <div class="grid grid-cols-2 divide-x divide-gray-100">
                                    <button type="button"
                                            onclick="selectDelivery('<?= $key ?>', 'standard')"
                                            id="delivery_<?= $key ?>_standard"
                                            class="delivery-option p-3 hover:bg-red-50 transition-colors text-left">
                                        <p class="text-xs font-semibold text-gray-600">🚚 Standard</p>
                                        <p class="text-xs text-gray-400 mb-1" id="days_<?= $key ?>_standard">3-5 days</p>
                                        <p class="font-black text-gray-800" id="price_<?= $key ?>_standard">RM <?= number_format($c['peninsular_std'], 2) ?></p>
                                    </button>
                                    <button type="button"
                                            onclick="selectDelivery('<?= $key ?>', 'express')"
                                            id="delivery_<?= $key ?>_express"
                                            class="delivery-option p-3 hover:bg-red-50 transition-colors text-left">
                                        <p class="text-xs font-semibold text-gray-600">⚡ Express</p>
                                        <p class="text-xs text-gray-400 mb-1" id="days_<?= $key ?>_express">1-2 days</p>
                                        <p class="font-black text-gray-800" id="price_<?= $key ?>_express">RM <?= number_format($c['peninsular_exp'], 2) ?></p>
                                    </button>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <p id="courierError" class="text-red-500 text-xs mt-3 hidden">Please select a delivery option.</p>
                    </div>

                    <input type="hidden" name="shipping_method" id="shippingMethodInput" value="">
                    <input type="hidden" name="shipping_courier" id="shippingCourierInput" value="">
                    <input type="hidden" name="shipping_zone" id="shippingZoneInput" value="">
                </div>
                
                <?php else: ?>
                <!-- Ebook only -->
                <div class="bg-white rounded-2xl shadow-sm p-6">
                    <input type="hidden" name="address_option" value="new">
                    <div class="flex items-center gap-3 mb-4">
                        <div class="w-10 h-10 bg-blue-50 rounded-xl flex items-center justify-center">
                            <span class="text-xl">📱</span>
                        </div>
                        <div>
                            <p class="font-semibold text-sm text-gray-800">E-Book Order</p>
                            <p class="text-xs text-gray-400">No shipping required</p>
                        </div>
                    </div>
                    <p class="text-sm text-gray-500">Download links will be available in your orders page after purchase.</p>
                </div>
                <?php endif; ?>

               
            </div>

            <!-- Right: Order Summary -->
            <div class="w-full lg:w-80 flex-shrink-0">
                <div class="bg-white rounded-2xl shadow-sm p-6 sticky top-24">
                    <h3 class="font-bold text-gray-800 mb-4">Order Summary</h3>

                    <!-- Items -->
                    <div class="space-y-3 mb-4">
                        <?php foreach ($items as $item): ?>
                        <div class="flex items-center gap-3">
                            <?php if (!empty($item['product_cover_image'])): ?>
                                <img src="../assets/images/<?= htmlspecialchars($item['product_cover_image']) ?>"
                                    class="w-10 h-14 object-cover rounded-lg flex-shrink-0">
                            <?php else: ?>
                                <div class="w-10 h-14 bg-gray-100 rounded-lg flex-shrink-0 flex items-center justify-center text-xs text-gray-400 font-bold">N/A</div>
                            <?php endif; ?>
                            <div class="flex-1 min-w-0">
                                <p class="text-xs font-medium text-gray-700 truncate"><?= htmlspecialchars($item['product_title']) ?></p>
                                <p class="text-xs text-gray-400">×<?= $item['cart_item_quantity'] ?></p>
                            </div>
                            <p class="text-xs font-bold text-gray-800 flex-shrink-0">RM <?= number_format($item['product_price'] * $item['cart_item_quantity'], 2) ?></p>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="border-t border-gray-100 pt-4 space-y-3">

                        <!-- Subtotal -->
                        <div class="flex justify-between text-sm text-gray-500">
                            <span>Subtotal</span>
                            <span>RM <?= number_format($total, 2) ?></span>
                        </div>

                        <!-- Shipping -->
                        <div class="flex justify-between text-sm text-gray-500">
                            <span>Shipping</span>
                            <span id="shippingFee"><?= $has_physical ? '—' : 'Free' ?></span>
                        </div>

                        <!-- Voucher Dropdown -->
                        <?php
                        $avail_vouchers = $pdo->prepare("
                            SELECT v.* FROM vouchers v
                            JOIN user_vouchers uv ON v.voucher_id = uv.uv_voucher_id
                            WHERE uv.uv_user_id = ?
                            AND uv.uv_is_used = 0
                            AND (uv.uv_status IS NULL OR uv.uv_status = 'available')
                            AND v.voucher_is_active = 1
                            AND (v.voucher_end_date IS NULL OR v.voucher_end_date >= NOW())
                            AND (uv.uv_expires_at IS NULL OR uv.uv_expires_at >= NOW())
                            AND NOT EXISTS (
                                SELECT 1 FROM voucher_usage vu 
                                WHERE vu.usage_voucher_id = v.voucher_id AND vu.usage_user_id = ?
                            )
                            ORDER BY v.voucher_min_order ASC
                        ");
                        $avail_vouchers->execute([$user_id, $user_id]);
                        $avail_vouchers = $avail_vouchers->fetchAll(PDO::FETCH_ASSOC);
                        ?>

                        <div class="border-t border-gray-100 pt-3">
                            <label class="block text-xs font-semibold text-gray-500 mb-2 uppercase tracking-wide">🎟️ Voucher</label>

                            <?php if (!empty($avail_vouchers)): ?>
                            <!-- Custom Dropdown -->
                            <div class="relative mb-2" id="voucherDropdownWrapper">
                                <button type="button" onclick="toggleVoucherDropdown()"
                                        class="w-full flex items-center justify-between px-3 py-2.5 border-2 border-gray-100 rounded-xl text-sm bg-gray-50 hover:border-red-300 transition-colors"
                                        id="voucherDropdownBtn">
                                    <span class="text-gray-400" id="voucherDropdownLabel">— Select a voucher —</span>
                                    <svg class="w-4 h-4 text-gray-400 transition-transform" id="voucherDropdownArrow" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                    </svg>
                                </button>

                                <!-- Dropdown Panel -->
                                <div id="voucherDropdownPanel"
                                    class="hidden absolute top-full left-0 right-0 mt-1 bg-white border border-gray-100 rounded-2xl shadow-xl z-50 overflow-hidden">
                                    <!-- Clear option -->
                                    <button type="button" onclick="clearVoucher()"
                                            class="w-full flex items-center gap-3 px-4 py-3 hover:bg-gray-50 transition-colors border-b border-gray-50 text-left">
                                        <span class="text-lg">❌</span>
                                        <span class="text-sm text-gray-400">No voucher</span>
                                    </button>
                                    <?php foreach ($avail_vouchers as $av):
                                        $discount_label = $av['voucher_type'] === 'percentage'
                                            ? $av['voucher_value'] . '% OFF'
                                            : 'RM ' . number_format($av['voucher_value'], 2) . ' OFF';
                                        $max_label = $av['voucher_max_discount'] ? ' · Max RM' . number_format($av['voucher_max_discount'], 2) : '';
                                        $min_label = $av['voucher_min_order'] > 0 ? 'Min spend RM' . number_format($av['voucher_min_order'], 2) : 'No min spend';
                                    ?>
                                    <button type="button"
                                            onclick="selectVoucherOption('<?= htmlspecialchars($av['voucher_code']) ?>', '<?= htmlspecialchars($discount_label) ?>')"
                                            class="w-full flex items-center gap-3 px-4 py-3 hover:bg-red-50 hover:border-l-4 hover:border-red-500 transition-all text-left group">
                                        <div class="w-10 h-10 bg-red-600 rounded-xl flex items-center justify-center flex-shrink-0">
                                            <span class="text-white text-xs font-black">🎟️</span>
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <p class="font-bold text-sm text-gray-800"><?= $discount_label ?><?= $max_label ?></p>
                                            <p class="text-xs text-gray-400">
                                                <span class="font-mono bg-gray-100 px-1.5 py-0.5 rounded text-gray-600"><?= htmlspecialchars($av['voucher_code']) ?></span>
                                                · <?= $min_label ?>
                                                <?php if ($av['voucher_end_date']): ?>
                                                · Until <?= date('d M', strtotime($av['voucher_end_date'])) ?>
                                                <?php endif; ?>
                                            </p>
                                        </div>
                                        <svg class="w-4 h-4 text-gray-300 group-hover:text-red-500 transition-colors flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                        </svg>
                                    </button>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endif; ?>

                            <!-- Manual input -->
                            <div class="flex gap-2">
                                <input type="text" id="voucherInput" placeholder="Or enter code"
                                        class="flex-1 px-3 py-2 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 bg-gray-50 focus:bg-white uppercase transition-colors">
                                <button type="button" onclick="applyVoucher()"
                                        class="bg-[#1e2d4a] hover:bg-[#162338] text-white font-semibold px-3 py-2 rounded-xl text-sm transition-colors">
                                    Apply
                                </button>
                            </div>
                                <div id="voucherMsg" class="mt-1.5 text-xs hidden"></div>
                                <input type="hidden" name="voucher_code_applied" id="voucherCodeApplied">
                        </div>

                        <!-- Discount row (hidden until applied) -->
                        <div class="flex justify-between text-sm text-green-600 hidden" id="discountRow">
                            <span>Discount</span>
                            <span id="discountAmount">-RM 0.00</span>
                        </div>

                        <!-- Total -->
                        <div class="flex justify-between font-black text-gray-800 text-lg pt-2 border-t border-gray-100">
                            <span>Total</span>
                            <span class="text-red-600" id="totalAmount">RM <?= number_format($total, 2) ?></span>
                        </div>
                    </div>

                    <button type="button" onclick="confirmPlaceOrder()"
                            class="w-full bg-red-600 hover:bg-red-700 text-white font-bold py-3 rounded-xl text-sm transition-colors duration-200 mt-4">
                        Place Order — <span id="btnTotal">RM <?= number_format($total, 2) ?></span>
                    </button>

                    <a href="cart.php" class="block text-center text-sm text-gray-400 hover:text-red-600 transition-colors mt-3">
                        ← Back to Cart
                    </a>
                </div>
            </div>
        </div>
        </form>
    </div>

    <!-- Place Order Confirm Modal -->
    <div id="placeOrderModal" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center px-6">
        <div class="bg-white rounded-3xl p-8 max-w-sm w-full shadow-2xl text-center">
            <div class="w-16 h-16 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-4">
                <span class="text-3xl">🛒</span>
            </div>
            <h3 class="text-xl font-black text-gray-800 mb-2">Confirm Order?</h3>
            <p class="text-sm text-gray-500 mb-1">You are about to place an order for</p>
            <p class="text-2xl font-black text-red-600 mb-2" id="modalTotal">RM 0.00</p>
            <p class="text-xs text-gray-400 mb-6">You will be redirected to the payment page. Please complete payment within <strong>5 minutes</strong>.</p>
            <div class="flex gap-3">
                <button onclick="document.getElementById('placeOrderModal').classList.add('hidden')"
                        class="flex-1 py-3 border-2 border-gray-100 rounded-xl text-sm font-semibold text-gray-600 hover:bg-gray-50 transition-colors">
                    Cancel
                </button>
                <button onclick="document.getElementById('placeOrderModal').classList.add('hidden'); document.getElementById('checkoutForm').submit();"
                        class="flex-1 py-3 bg-red-600 hover:bg-red-700 text-white rounded-xl text-sm font-semibold transition-colors">
                    Confirm Order
                </button>
            </div>
        </div>
    </div>

    <?php if (isset($_SESSION['payment_lock_msg'])): ?>
    <div id="paymentLockModal" class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center px-6">
        <div class="bg-white rounded-3xl p-8 max-w-sm w-full shadow-2xl text-center">
            <div class="w-16 h-16 bg-yellow-100 rounded-full flex items-center justify-center mx-auto mb-4">
                <span class="text-3xl">⏳</span>
            </div>
            <h3 class="text-xl font-black text-gray-800 mb-2">Payment Pending</h3>
            <p class="text-sm text-gray-500 leading-relaxed mb-6">
                <?= htmlspecialchars($_SESSION['payment_lock_msg']) ?>
            </p>
            <button onclick="document.getElementById('paymentLockModal').classList.add('hidden')"
                    class="w-full py-3 bg-red-600 hover:bg-red-700 text-white rounded-xl text-sm font-semibold transition-colors">
                OK
            </button>
        </div>
    </div>
    <?php unset($_SESSION['payment_lock_msg']); endif; ?>

    <!-- Delivery Warning Modal -->
    <div id="deliveryWarningModal" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center px-6">
        <div class="bg-white rounded-3xl p-8 max-w-sm w-full shadow-2xl text-center">
            <div class="w-16 h-16 bg-orange-100 rounded-full flex items-center justify-center mx-auto mb-4">
                <span class="text-3xl">🚚</span>
            </div>
            <h3 class="text-xl font-black text-gray-800 mb-2">Select Delivery Method</h3>
            <p class="text-sm text-gray-500 leading-relaxed mb-6">Please select a delivery zone and courier before placing your order.</p>
            <button onclick="document.getElementById('deliveryWarningModal').classList.add('hidden')"
                    class="w-full py-3 bg-red-600 hover:bg-red-700 text-white rounded-xl text-sm font-semibold transition-colors">
                OK, Let Me Choose
            </button>
        </div>
    </div>

    <script>
    const subtotal = <?= $total ?>;
    const hasPhysical = <?= $has_physical ? 'true' : 'false' ?>;

    function updateShipping(fee) {
        if (!hasPhysical) return;
        const total = subtotal + fee;
        document.getElementById('shippingFee').textContent = 'RM ' + fee.toFixed(2);
        document.getElementById('totalAmount').textContent = 'RM ' + total.toFixed(2);
        document.getElementById('btnTotal').textContent = 'RM ' + total.toFixed(2);

        // Update label styles
        const standardLabel = document.getElementById('standard_label');
        const expressLabel = document.getElementById('express_label');
        if (fee === 5) {
            standardLabel.className = standardLabel.className.replace('border-gray-200 bg-white', 'border-red-300 bg-red-50');
            expressLabel.className = expressLabel.className.replace('border-red-300 bg-red-50', 'border-gray-200 bg-white');
        } else {
            expressLabel.className = expressLabel.className.replace('border-gray-200 bg-white', 'border-red-300 bg-red-50');
            standardLabel.className = standardLabel.className.replace('border-red-300 bg-red-50', 'border-gray-200 bg-white');
        }
    }

    function toggleAddress(option) {
        const savedDiv = document.getElementById('saved_address_div');
        const newDiv = document.getElementById('new_address_div');
        if (savedDiv) savedDiv.style.display = option === 'saved' ? 'block' : 'none';
        if (newDiv) newDiv.style.display = option === 'new' ? 'block' : 'none';
    }

    let appliedDiscount = 0;
    let currentShipping = 0;

    function applyVoucher() {
        const code = document.getElementById('voucherInput').value.trim().toUpperCase();
        const msg = document.getElementById('voucherMsg');

        if (!code) {
            msg.textContent = 'Please enter a voucher code.';
            msg.className = 'mt-2 text-xs text-red-500';
            msg.classList.remove('hidden');
            return;
        }

        fetch('checkout.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'check_voucher=1&voucher_code=' + encodeURIComponent(code) + '&cart_total=' + subtotal
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                appliedDiscount = data.discount;
                document.getElementById('voucherCodeApplied').value = code;
                document.getElementById('discountRow').classList.remove('hidden');
                document.getElementById('discountAmount').textContent = '-RM ' + appliedDiscount.toFixed(2);
                msg.textContent = '✅ ' + data.message + ' (-RM ' + appliedDiscount.toFixed(2) + ')';
                msg.className = 'mt-2 text-xs text-green-600';
                document.getElementById('voucherInput').classList.add('border-green-400');
            } else {
                appliedDiscount = 0;
                document.getElementById('voucherCodeApplied').value = '';
                document.getElementById('discountRow').classList.add('hidden');
                msg.textContent = '❌ ' + data.message;
                msg.className = 'mt-2 text-xs text-red-500';
                document.getElementById('voucherInput').classList.remove('border-green-400');
            }
            msg.classList.remove('hidden');
            updateTotals();
        });
    }

    function updateTotals() {
        const total = Math.max(0, subtotal - appliedDiscount + currentShipping);
        document.getElementById('totalAmount').textContent = 'RM ' + total.toFixed(2);
        document.getElementById('btnTotal').textContent = 'RM ' + total.toFixed(2);
    }

    // Override updateShipping to also account for discount
    const originalUpdateShipping = updateShipping;
    function updateShipping(fee) {
        currentShipping = fee;
        document.getElementById('shippingFee').textContent = fee > 0 ? 'RM ' + fee.toFixed(2) : 'Free';
        updateTotals();

        const standardLabel = document.getElementById('standard_label');
        const expressLabel = document.getElementById('express_label');
        if (standardLabel && expressLabel) {
            if (fee === 5) {
                standardLabel.classList.add('border-red-300', 'bg-red-50');
                standardLabel.classList.remove('border-gray-200');
                expressLabel.classList.remove('border-red-300', 'bg-red-50');
                expressLabel.classList.add('border-gray-200');
            } else {
                expressLabel.classList.add('border-red-300', 'bg-red-50');
                expressLabel.classList.remove('border-gray-200');
                standardLabel.classList.remove('border-red-300', 'bg-red-50');
                standardLabel.classList.add('border-gray-200');
            }
        }
    }

    // Allow pressing Enter on voucher input
    document.getElementById('voucherInput').addEventListener('keypress', function(e) {
        if (e.key === 'Enter') { e.preventDefault(); applyVoucher(); }
    });

    function selectVoucher(code, cartTotal) {
        document.getElementById('voucherInput').value = code;
        // Deselect all voucher buttons
        document.querySelectorAll('[id^="voucher-btn-"]').forEach(btn => {
            btn.classList.remove('border-red-500', 'bg-red-50');
            btn.classList.add('border-gray-100');
        });
        applyVoucher();
    }

    function toggleVoucherDropdown() {
        const panel = document.getElementById('voucherDropdownPanel');
        const arrow = document.getElementById('voucherDropdownArrow');
        const isHidden = panel.classList.contains('hidden');
        panel.classList.toggle('hidden');
        arrow.style.transform = isHidden ? 'rotate(180deg)' : '';
    }

    function selectVoucherOption(code, label) {
        document.getElementById('voucherDropdownLabel').textContent = label + ' — ' + code;
        document.getElementById('voucherDropdownLabel').classList.remove('text-gray-400');
        document.getElementById('voucherDropdownLabel').classList.add('text-gray-800', 'font-semibold');
        document.getElementById('voucherDropdownPanel').classList.add('hidden');
        document.getElementById('voucherDropdownArrow').style.transform = '';
        document.getElementById('voucherInput').value = code;
        applyVoucher();
    }

    function clearVoucher() {
        document.getElementById('voucherDropdownLabel').textContent = '— Select a voucher —';
        document.getElementById('voucherDropdownLabel').classList.add('text-gray-400');
        document.getElementById('voucherDropdownLabel').classList.remove('text-gray-800', 'font-semibold');
        document.getElementById('voucherDropdownPanel').classList.add('hidden');
        document.getElementById('voucherDropdownArrow').style.transform = '';
        appliedDiscount = 0;
        document.getElementById('voucherCodeApplied').value = '';
        document.getElementById('voucherInput').value = '';
        document.getElementById('discountRow').classList.add('hidden');
        document.getElementById('voucherMsg').classList.add('hidden');
        document.getElementById('voucherInput').classList.remove('border-green-400');
        updateTotals();
    }

    // Close dropdown when clicking outside
    document.addEventListener('click', function(e) {
        const wrapper = document.getElementById('voucherDropdownWrapper');
        if (wrapper && !wrapper.contains(e.target)) {
            document.getElementById('voucherDropdownPanel').classList.add('hidden');
            document.getElementById('voucherDropdownArrow').style.transform = '';
        }
    });

    function confirmPlaceOrder() {
        if (hasPhysical && !currentCourier) {
            document.getElementById('deliveryWarningModal').classList.remove('hidden');
            return;
        }
        const total = document.getElementById('btnTotal').textContent;
        document.getElementById('modalTotal').textContent = total;
        document.getElementById('placeOrderModal').classList.remove('hidden');
    }

const couriersData = <?= json_encode($couriers) ?>;
    let currentZone = null;
    let currentCourier = null;
    let currentType = null;

    function selectZone(zone) {
        currentZone = zone;
        currentCourier = null;
        currentType = null;

        document.getElementById('zone_peninsular').className = zone === 'peninsular'
            ? 'flex items-center gap-3 p-4 border-2 border-red-500 bg-red-50 rounded-xl transition-colors text-left'
            : 'flex items-center gap-3 p-4 border-2 border-gray-100 rounded-xl hover:border-red-300 transition-colors text-left';
        document.getElementById('zone_east').className = zone === 'east_malaysia'
            ? 'flex items-center gap-3 p-4 border-2 border-red-500 bg-red-50 rounded-xl transition-colors text-left'
            : 'flex items-center gap-3 p-4 border-2 border-gray-100 rounded-xl hover:border-red-300 transition-colors text-left';

        document.getElementById('shippingZoneInput').value = zone;
        document.getElementById('courierSection').classList.remove('hidden');

        updateAllPrices();

        document.querySelectorAll('.delivery-option').forEach(btn => {
            btn.classList.remove('bg-red-600', 'text-white');
            btn.classList.add('hover:bg-red-50');
            btn.querySelectorAll('p').forEach(p => p.classList.remove('text-white'));
        });

        currentShipping = 0;
        document.getElementById('shippingFee').textContent = '—';
        document.getElementById('shippingMethodInput').value = '';
        document.getElementById('shippingCourierInput').value = '';
        updateTotals();
    }

    function selectDelivery(courier, type) {
        currentCourier = courier;
        currentType = type;

        document.querySelectorAll('.delivery-option').forEach(btn => {
            btn.classList.remove('bg-red-600');
            btn.classList.add('hover:bg-red-50');
            btn.querySelectorAll('p').forEach(p => p.classList.remove('text-white'));
        });

        const selected = document.getElementById(`delivery_${courier}_${type}`);
        if (selected) {
            selected.classList.add('bg-red-600');
            selected.classList.remove('hover:bg-red-50');
            selected.querySelectorAll('p').forEach(p => p.classList.add('text-white'));
        }

        document.getElementById('shippingMethodInput').value = `${courier}_${type}`;
        document.getElementById('shippingCourierInput').value = courier;

        const zonePrefix = currentZone === 'east_malaysia' ? 'east' : 'peninsular';
        const feeKey = `${zonePrefix}_${type === 'express' ? 'exp' : 'std'}`;
        currentShipping = couriersData[courier][feeKey];
        document.getElementById('shippingFee').textContent = 'RM ' + currentShipping.toFixed(2);
        document.getElementById('courierError').classList.add('hidden');
        updateTotals();
    }

    function updateAllPrices() {
        if (!currentZone) return;
        const zonePrefix = currentZone === 'east_malaysia' ? 'east' : 'peninsular';
        const stdDays = currentZone === 'east_malaysia' ? '5-7 days' : '3-5 days';
        const expDays = currentZone === 'east_malaysia' ? '3-4 days' : '1-2 days';

        Object.keys(couriersData).forEach(key => {
            const c = couriersData[key];
            const stdEl = document.getElementById(`price_${key}_standard`);
            const expEl = document.getElementById(`price_${key}_express`);
            const stdDaysEl = document.getElementById(`days_${key}_standard`);
            const expDaysEl = document.getElementById(`days_${key}_express`);
            if (stdEl) stdEl.textContent = 'RM ' + c[`${zonePrefix}_std`].toFixed(2);
            if (expEl) expEl.textContent = 'RM ' + c[`${zonePrefix}_exp`].toFixed(2);
            if (stdDaysEl) stdDaysEl.textContent = stdDays;
            if (expDaysEl) expDaysEl.textContent = expDays;
        });
    }
    </script>

</body>
</html>