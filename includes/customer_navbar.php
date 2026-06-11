<?php
// Auto-check expired pending_confirmation orders
if (isset($_SESSION['user_id'])) {
    $expired_orders = $pdo->prepare("
        SELECT order_id, order_voucher_code, order_user_id
        FROM orders
        WHERE order_user_id = ?
        AND order_payment_status = 'pending_confirmation'
        AND order_confirm_expires_at < NOW()
        LIMIT 1
    ");
    $expired_orders->execute([$_SESSION['user_id']]);
    $expired_order = $expired_orders->fetch(PDO::FETCH_ASSOC);

    if ($expired_order) {
        // Cancel order
        $pdo->prepare("UPDATE orders SET order_payment_status = 'cancelled', order_status = 'cancelled' WHERE order_id = ?")
            ->execute([$expired_order['order_id']]);

        // Restore stock
        $items = $pdo->prepare("SELECT * FROM order_items WHERE order_item_order_id = ?");
        $items->execute([$expired_order['order_id']]);
        foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $item) {
            if ($item['order_item_type'] === 'physical') {
                $pdo->prepare("UPDATE product_physical SET physical_stock_quantity = physical_stock_quantity + ? WHERE physical_product_id = ?")
                    ->execute([$item['order_item_quantity'], $item['order_item_product_id']]);
            }
        }

        // Restore voucher
        if (!empty($expired_order['order_voucher_code'])) {
            $v = $pdo->prepare("SELECT voucher_id FROM vouchers WHERE voucher_code = ?");
            $v->execute([$expired_order['order_voucher_code']]);
            $v = $v->fetch(PDO::FETCH_ASSOC);
            if ($v) {
                $pdo->prepare("DELETE FROM voucher_usage WHERE usage_order_id = ?")
                    ->execute([$expired_order['order_id']]);
                $pdo->prepare("UPDATE vouchers SET voucher_used_count = GREATEST(0, voucher_used_count - 1) WHERE voucher_code = ?")
                    ->execute([$expired_order['order_voucher_code']]);
                $pdo->prepare("UPDATE user_vouchers SET uv_is_used = 0, uv_status = 'available', uv_pending_at = NULL, uv_used_at = NULL WHERE uv_voucher_id = ? AND uv_user_id = ?")
                    ->execute([$v['voucher_id'], $_SESSION['user_id']]);
            }
        }

        // Send notification
        require_once '../includes/notifications.php';
        $order_num = '#' . str_pad($expired_order['order_id'], 4, '0', STR_PAD_LEFT);
        sendNotification($pdo, $_SESSION['user_id'],
            '⏰ Payment Timeout',
            "Your order $order_num has been cancelled due to payment timeout. Stock and vouchers have been restored.",
            'order'
        );
    }
}
$cart_count = 0;
if (isset($_SESSION['user_id'])) {
    $stmt = $pdo->prepare("SELECT SUM(cart_item_quantity) FROM cart_items WHERE cart_item_user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $cart_count = $stmt->fetchColumn() ?? 0;
}

// Notification count
$notif_count = 0;
if (isset($_SESSION['user_id'])) {
    $nstmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE notif_user_id = ? AND notif_is_read = 0");
    $nstmt->execute([$_SESSION['user_id']]);
    $notif_count = $nstmt->fetchColumn() ?? 0;
}
?>
<style>
@keyframes bellRing {
    0%   { transform: rotate(0deg); }
    10%  { transform: rotate(15deg); }
    20%  { transform: rotate(-13deg); }
    30%  { transform: rotate(11deg); }
    40%  { transform: rotate(-9deg); }
    50%  { transform: rotate(7deg); }
    60%  { transform: rotate(-5deg); }
    70%  { transform: rotate(3deg); }
    80%  { transform: rotate(-1deg); }
    90%  { transform: rotate(1deg); }
    100% { transform: rotate(0deg); }
}
.bell-ring {
    animation: bellRing 1.2s ease infinite;
    transform-origin: top center;
    display: inline-block;
}
</style>

<nav class="bg-white shadow-sm sticky top-0 z-50">
    <div class="max-w-7xl mx-auto px-6 py-4 flex justify-between items-center">
        <a href="../index.php" class="text-xl font-black tracking-wide text-gray-900">
            MANGA<span class="text-red-600">VAULT</span>
        </a>
        <div class="hidden lg:flex items-center gap-8 text-sm font-medium">
            <a href="../index.php" class="text-gray-600 hover:text-red-600 transition-colors">Home</a>
            <a href="home.php" class="text-gray-600 hover:text-red-600 transition-colors">Catalog</a>
            <a href="../membership.php" class="text-gray-600 hover:text-red-600 transition-colors">Membership</a>
            <a href="about.php" class="text-gray-600 hover:text-red-600 transition-colors">About Us</a>
            <a href="faq.php" class="text-gray-600 hover:text-red-600 transition-colors">FAQ</a>
        </div>
        <div class="flex items-center gap-4 text-sm">
            <!-- Notifications -->
            <a href="notifications.php" class="relative text-gray-600 hover:text-red-600 transition-colors">
                <svg class="w-6 h-6 <?= $notif_count > 0 ? 'bell-ring' : '' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path>
                </svg>
                <?php if ($notif_count > 0): ?>
                    <span class="absolute -top-2 -right-2 bg-red-600 text-white text-xs w-5 h-5 rounded-full flex items-center justify-center"><?= $notif_count ?></span>
                <?php endif; ?>
            </a>
            <!-- Cart -->
            <a href="cart.php" class="relative text-gray-600 hover:text-red-600 transition-colors">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"></path>
                </svg>
                <?php if ($cart_count > 0): ?>
                    <span class="absolute -top-2 -right-2 bg-red-600 text-white text-xs w-5 h-5 rounded-full flex items-center justify-center"><?= $cart_count ?></span>
                <?php endif; ?>
            </a>
            <a href="dashboard.php" class="hidden lg:block text-gray-600 hover:text-red-600 transition-colors font-medium">
                Hi, <?= htmlspecialchars($_SESSION['user_first_name'] ?? $_SESSION['user_name'] ?? 'Guest') ?>!
            </a>
            

            <!-- Hamburger -->
            <button id="navMenuBtn" class="lg:hidden flex flex-col gap-1.5 p-1 focus:outline-none">
                <span class="w-6 h-0.5 bg-gray-700 rounded transition-all duration-300"></span>
                <span class="w-6 h-0.5 bg-gray-700 rounded transition-all duration-300"></span>
                <span class="w-6 h-0.5 bg-gray-700 rounded transition-all duration-300"></span>
            </button>
        </div>
    </div>

    <!-- Mobile Menu -->
    <div id="navMobileMenu" style="max-height:0; overflow:hidden; transition:max-height 0.3s ease;" class="lg:hidden bg-white border-t border-gray-100">
        <div class="px-6 py-4 space-y-2">
            <a href="../index.php" class="block py-2 text-sm text-gray-600 hover:text-red-600">Home</a>
            <a href="home.php" class="block py-2 text-sm text-gray-600 hover:text-red-600">Catalog</a>
            <a href="../membership.php" class="block py-2 text-sm text-gray-600 hover:text-red-600">Membership</a>
            <a href="about.php" class="block py-2 text-sm text-gray-600 hover:text-red-600">About Us</a>
            <a href="faq.php" class="block py-2 text-sm text-gray-600 hover:text-red-600">FAQ</a>
            <a href="dashboard.php" class="block py-2 text-sm text-gray-600 hover:text-red-600">My Account</a>
            <a href="notifications.php" class="block py-2 text-sm text-gray-600 hover:text-red-600">
                Notifications <?= $notif_count > 0 ? "($notif_count)" : '' ?>
            </a>
            
        </div>
    </div>

    <!-- Overlay -->
    <div id="navOverlay" class="fixed inset-0 bg-black/30 z-40 hidden lg:hidden" onclick="closeNavMenu()"></div>
</nav>

<script>
const navMenuBtn = document.getElementById('navMenuBtn');
const navMobileMenu = document.getElementById('navMobileMenu');
const navOverlay = document.getElementById('navOverlay');

function closeNavMenu() {
    navMobileMenu.style.maxHeight = '0px';
    navOverlay.classList.add('hidden');
}

if (navMenuBtn) {
    navMenuBtn.addEventListener('click', function() {
        if (navMobileMenu.style.maxHeight === '0px' || navMobileMenu.style.maxHeight === '') {
            navMobileMenu.style.maxHeight = '400px';
            navOverlay.classList.remove('hidden');
        } else {
            closeNavMenu();
        }
    });
}

function confirmLogout() {
    document.getElementById('logoutModal').classList.remove('hidden');
}


document.getElementById('logoutModal').addEventListener('click', function(e) {
    if (e.target === this) closeLogoutModal();
});

// Smooth page transition
document.querySelectorAll('a[href]').forEach(link => {
    const href = link.getAttribute('href');
    if (href && !href.startsWith('#') && !href.startsWith('javascript') && !href.startsWith('mailto') && !href.includes('logout')) {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            document.body.style.opacity = '0';
            document.body.style.transition = 'opacity 0.3s ease';
            setTimeout(() => { window.location.href = href; }, 300);
        });
    }
});
</script>