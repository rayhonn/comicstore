<?php

require_once __DIR__ . '/../includes/auth.php';
require_customer();

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/stripe_config.php';
require_once __DIR__ . '/../includes/db.php';

if (!isset($_SESSION['pending_order'])) {
    header('Location: cart.php');
    exit;
}

$order = $_SESSION['pending_order'];
$user_id = $_SESSION['user_id'];
$total = $order['total'];

// Start payment lock if not already started
if (!isset($_SESSION['payment_lock']) || $_SESSION['payment_lock']['user_id'] != $user_id) {
    $_SESSION['payment_lock'] = [
        'user_id'   => $user_id,
        'locked_at' => time()
    ];
    // Set voucher to pending
    if (!empty($order['voucher_id'])) {
        $pdo->prepare("UPDATE user_vouchers SET uv_status = 'pending', uv_pending_at = NOW() WHERE uv_voucher_id = ? AND uv_user_id = ? AND uv_is_used = 0")
            ->execute([$order['voucher_id'], $user_id]);
    }
}

$lock_locked_at = $_SESSION['payment_lock']['locked_at'];
$elapsed = time() - $lock_locked_at;

// If 5 minutes passed, cancel and restore voucher
if ($elapsed >= 300) {
    if (!empty($order['voucher_id'])) {
        $pdo->prepare("UPDATE user_vouchers SET uv_status = 'available', uv_is_used = 0, uv_pending_at = NULL WHERE uv_voucher_id = ? AND uv_user_id = ?")
            ->execute([$order['voucher_id'], $user_id]);
    }
    unset($_SESSION['pending_order']);
    unset($_SESSION['payment_lock']);
    header('Location: cart.php?timeout=1');
    exit;
}

// Handle Pay Now — create Stripe session and redirect
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pay_now'])) {
    \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);
    $app_url = rtrim(APP_URL, '/');

    $line_items = [];
    foreach ($order['items'] as $item) {
        $line_items[] = [
            'price_data' => [
                'currency' => STRIPE_CURRENCY,
                'product_data' => [
                    'name' => $item['product_title'],
                    'description' => ucfirst($item['product_type']) . ' — MangaVault',
                ],
                'unit_amount' => round($item['product_price'] * 100),
            ],
            'quantity' => $item['cart_item_quantity'],
        ];
    }

    if ($order['has_physical'] && $order['shipping_fee'] > 0) {
        $line_items[] = [
            'price_data' => [
                'currency' => STRIPE_CURRENCY,
                'product_data' => ['name' => 'Shipping Fee'],
                'unit_amount' => round($order['shipping_fee'] * 100),
            ],
            'quantity' => 1,
        ];
    }

    if (!empty($order['voucher_code']) && $order['discount_amount'] > 0) {
        $line_items = [[
            'price_data' => [
                'currency' => STRIPE_CURRENCY,
                'product_data' => [
                    'name' => 'MangaVault Order',
                    'description' => 'Includes voucher discount (' . $order['voucher_code'] . ' -RM' . number_format($order['discount_amount'], 2) . ')',
                ],
                'unit_amount' => round($order['total'] * 100),
            ],
            'quantity' => 1,
        ]];
    }

    try {
        $checkout_session = \Stripe\Checkout\Session::create([
            'payment_method_types' => ['card'],
            'line_items' => $line_items,
            'mode' => 'payment',
            'success_url' => $app_url . '/customer/payment_success.php?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $app_url . '/customer/payment_cancel.php',
        ]);
        $_SESSION['stripe_session_id'] = $checkout_session->id;
        header('Location: ' . $checkout_session->url);
        exit;
    } catch (\Stripe\Exception\ApiErrorException $e) {
        $stripe_error = $e->getMessage();
    }
}

$remaining = 300 - $elapsed;
$order_num = '#' . str_pad(time(), 4, '0', STR_PAD_LEFT);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Complete Payment - MangaVault</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { opacity: 0; animation: fadeIn 0.4s ease forwards; }
        @keyframes fadeIn { to { opacity: 1; } }
    </style>
</head>
<body class="bg-[#F5F0EB] min-h-screen">

    <?php include '../includes/customer_navbar.php'; ?>

    <!-- Timer Bar -->
    <div class="bg-yellow-50 border-b border-yellow-200 px-6 py-3">
        <div class="max-w-4xl mx-auto flex items-center justify-between">
            <div class="flex items-center gap-3">
                <span class="text-xl">⏳</span>
                <div>
                    <p class="text-sm font-semibold text-yellow-800">Complete payment within</p>
                    <p class="text-xs text-yellow-600">Your order is reserved for 5 minutes</p>
                </div>
            </div>
            <div class="text-2xl font-black text-yellow-700" id="timerDisplay">05:00</div>
        </div>
        <div class="max-w-4xl mx-auto mt-2">
            <div class="h-1.5 bg-yellow-200 rounded-full overflow-hidden">
                <div id="timerBar" class="h-full bg-yellow-500 rounded-full transition-all duration-1000" style="width:100%"></div>
            </div>
        </div>
    </div>

    <div class="max-w-4xl mx-auto px-6 py-8">

        <div class="text-center mb-6">
            <h1 class="text-2xl font-black text-gray-800">Complete Your Payment</h1>
            <p class="text-gray-400 text-sm mt-1">Review your order before proceeding to payment</p>
        </div>

        <?php if (isset($stripe_error)): ?>
        <div class="bg-red-50 border border-red-200 text-red-600 text-sm px-4 py-3 rounded-xl mb-5 max-w-2xl mx-auto">
            ❌ <?= htmlspecialchars($stripe_error) ?>
        </div>
        <?php endif; ?>

        <div class="flex flex-col lg:flex-row gap-6">

            <!-- Order Summary -->
            <div class="flex-1 bg-white rounded-2xl shadow-sm p-6">
                <h2 class="font-black text-gray-800 mb-5 flex items-center gap-2">
                    <span>🛒</span> Order Summary
                </h2>

                <!-- Items -->
                <div class="space-y-4 mb-6">
                    <?php foreach ($order['items'] as $item): ?>
                    <div class="flex items-center gap-4">
                        <?php if (!empty($item['product_cover_image'])): ?>
                        <img src="../assets/images/<?= htmlspecialchars($item['product_cover_image']) ?>"
                             class="w-12 h-16 object-cover rounded-lg flex-shrink-0">
                        <?php else: ?>
                        <div class="w-12 h-16 bg-gray-100 rounded-lg flex-shrink-0 flex items-center justify-center text-gray-400 text-xs">N/A</div>
                        <?php endif; ?>
                        <div class="flex-1">
                            <p class="font-semibold text-sm text-gray-800"><?= htmlspecialchars($item['product_title']) ?></p>
                            <p class="text-xs text-gray-400">
                                <?= $item['product_type'] === 'ebook' ? '📱 E-Book' : '📦 Physical' ?>
                                · Qty: <?= $item['cart_item_quantity'] ?>
                            </p>
                        </div>
                        <p class="font-bold text-gray-800 text-sm">RM <?= number_format($item['product_price'] * $item['cart_item_quantity'], 2) ?></p>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="border-t border-gray-100 pt-4 space-y-2">
                    <div class="flex justify-between text-sm text-gray-500">
                        <span>Subtotal</span>
                        <span>RM <?= number_format($order['total'] - $order['shipping_fee'] + ($order['discount_amount'] ?? 0), 2) ?></span>
                    </div>
                    <?php if ($order['has_physical']): ?>
                    <div class="flex justify-between text-sm text-gray-500">
                        <span>Shipping</span>
                        <?php if ($order['shipping_fee'] == 0 && isset($order['original_shipping_fee'])): ?>
                        <span>
                            <span class="line-through text-gray-400">RM <?= number_format($order['original_shipping_fee'], 2) ?></span>
                            <span class="text-green-600 font-bold ml-1">RM 0.00</span>
                        </span>
                        <?php elseif (isset($order['original_shipping_fee']) && $order['original_shipping_fee'] > $order['shipping_fee']): ?>
                        <span>
                            <span class="line-through text-gray-400">RM <?= number_format($order['original_shipping_fee'], 2) ?></span>
                            <span class="text-green-600 font-bold ml-1">RM <?= number_format($order['shipping_fee'], 2) ?></span>
                        </span>
                        <?php else: ?>
                        <span><?= $order['shipping_fee'] > 0 ? 'RM ' . number_format($order['shipping_fee'], 2) : 'Free' ?></span>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($order['voucher_code']) && $order['discount_amount'] > 0): ?>
                    <div class="flex justify-between text-sm text-green-600">
                        <span>🎟️ <?= htmlspecialchars($order['voucher_code']) ?></span>
                        <span>-RM <?= number_format($order['discount_amount'], 2) ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="flex justify-between font-black text-gray-800 text-lg pt-3 border-t border-gray-100">
                        <span>Total</span>
                        <span class="text-red-600">RM <?= number_format($total, 2) ?></span>
                    </div>
                </div>

                <?php if ($order['has_physical'] && !empty($order['shipping_courier'])): ?>
                <div class="mt-4 bg-gray-50 rounded-xl p-3 text-xs text-gray-500">
                    🚚 <?= ucfirst(str_replace('_', ' ', $order['shipping_courier'])) ?>
                    · <?= ucfirst(str_replace('_', ' ', $order['shipping_zone'] ?? 'peninsular')) ?>
                    · <?= str_contains($order['shipping_method'] ?? '', 'express') ? 'Express (1-2 days)' : 'Standard (3-5 days)' ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Payment Panel -->
            <div class="w-full lg:w-80 flex-shrink-0">
                <div class="bg-white rounded-2xl shadow-sm p-6 sticky top-24">
                    <h2 class="font-black text-gray-800 mb-4">Payment</h2>

                    <div class="bg-blue-50 border border-blue-100 rounded-xl p-4 mb-5">
                        <div class="flex items-center gap-3 mb-2">
                            <span class="text-2xl">💳</span>
                            <div>
                                <p class="font-bold text-sm text-blue-800">Stripe Secure Payment</p>
                                <p class="text-xs text-blue-600">Visa, Mastercard, Amex</p>
                            </div>
                        </div>
                        <p class="text-xs text-blue-500">You will be redirected to Stripe's secure payment page.</p>
                    </div>

                    <div class="bg-gray-50 rounded-xl p-4 mb-5">
                        <div class="flex justify-between text-sm text-gray-500 mb-1">
                            <span>Amount to Pay</span>
                        </div>
                        <p class="text-2xl font-black text-red-600">RM <?= number_format($total, 2) ?></p>
                    </div>

                    <form method="POST">
                        <input type="hidden" name="pay_now" value="1">
                        <button type="submit"
                                class="w-full bg-red-600 hover:bg-red-700 text-white font-black py-4 rounded-xl text-sm transition-colors flex items-center justify-center gap-2">
                            🔒 Pay with Stripe
                        </button>
                    </form>

                    <a href="cart.php" onclick="cancelPayment(event)"
                       class="block text-center text-sm text-gray-400 hover:text-red-600 transition-colors mt-4">
                        ← Cancel & Back to Cart
                    </a>

                    <div class="flex justify-center gap-4 mt-4">
                        <span class="text-xs text-gray-400">🔒 SSL</span>
                        <span class="text-xs text-gray-400">🛡️ Secure</span>
                        <span class="text-xs text-gray-400">✅ Safe</span>
                    </div>
                </div>
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
            <p class="text-xs text-gray-400 mb-6">Your order has been cancelled. Any voucher used has been restored.</p>
            <a href="cart.php" class="block w-full py-3 bg-red-600 hover:bg-red-700 text-white rounded-xl text-sm font-semibold transition-colors">
                Back to Cart
            </a>
        </div>
    </div>

    <script>
    const lockedAt = <?= $lock_locked_at ?> * 1000;
    const totalMs = 300 * 1000;

    function updateTimer() {
        const elapsed = Date.now() - lockedAt;
        const rem = Math.max(0, Math.floor((totalMs - elapsed) / 1000));
        const mins = Math.floor(rem / 60).toString().padStart(2, '0');
        const secs = (rem % 60).toString().padStart(2, '0');

        document.getElementById('timerDisplay').textContent = mins + ':' + secs;
        const pct = Math.max(0, ((totalMs - elapsed) / totalMs) * 100);
        document.getElementById('timerBar').style.width = pct + '%';

        if (rem <= 60) {
            document.getElementById('timerBar').classList.replace('bg-yellow-500', 'bg-red-500');
            document.getElementById('timerDisplay').classList.replace('text-yellow-700', 'text-red-600');
        }

        if (rem <= 0) {
            clearInterval(timerInterval);
            // Restore voucher via AJAX then show modal
            fetch('cancel_pending_voucher.php', { method: 'POST' })
                .finally(() => {
                    document.getElementById('timeoutModal').classList.remove('hidden');
                });
        }
    }

    updateTimer();
    const timerInterval = setInterval(updateTimer, 1000);

    function cancelPayment(e) {
        e.preventDefault();
        fetch('cancel_pending_voucher.php', { method: 'POST' })
            .finally(() => {
                window.location.href = 'payment_cancel.php';
            });
    }
    </script>

</body>
</html>