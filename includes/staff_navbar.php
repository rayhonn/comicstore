<?php
$staff_current = basename($_SERVER['PHP_SELF']);

$pending_orders = $pdo->query("SELECT COUNT(*) FROM orders WHERE order_status = 'pending' AND order_payment_status = 'confirmed'")->fetchColumn();
$pending_returns = $pdo->query("SELECT COUNT(*) FROM return_requests WHERE return_status = 'pending'")->fetchColumn();
$pending_reviews = $pdo->query("SELECT COUNT(*) FROM product_reviews WHERE review_status = 'pending'")->fetchColumn();
?>
<nav class="bg-[#1e2d4a] text-white sticky top-0 z-50 shadow-lg">
    <div class="max-w-7xl mx-auto px-6 py-3 flex justify-between items-center">
        <div class="flex items-center gap-8">
            <a href="dashboard.php" class="text-lg font-black tracking-wide">
                MANGA<span class="text-red-400">VAULT</span>
                <span class="text-xs text-white/40 font-normal ml-2">Staff</span>
            </a>
            <div class="hidden lg:flex items-center gap-1 text-sm">
                <a href="dashboard.php" class="px-3 py-2 rounded-lg transition-colors <?= $staff_current === 'dashboard.php' ? 'bg-white/20 text-white font-semibold' : 'text-white/70 hover:text-white hover:bg-white/10' ?>">
                    Dashboard
                </a>
                <a href="orders.php" class="relative px-3 py-2 rounded-lg transition-colors <?= $staff_current === 'orders.php' ? 'bg-white/20 text-white font-semibold' : 'text-white/70 hover:text-white hover:bg-white/10' ?>">
                    Orders
                    <?php if ($pending_orders > 0): ?>
                    <span class="absolute -top-1 -right-1 bg-red-500 text-white text-xs w-4 h-4 rounded-full flex items-center justify-center"><?= $pending_orders ?></span>
                    <?php endif; ?>
                </a>
                <a href="returns.php" class="relative px-3 py-2 rounded-lg transition-colors <?= $staff_current === 'returns.php' ? 'bg-white/20 text-white font-semibold' : 'text-white/70 hover:text-white hover:bg-white/10' ?>">
                    Returns
                    <?php if ($pending_returns > 0): ?>
                    <span class="absolute -top-1 -right-1 bg-red-500 text-white text-xs w-4 h-4 rounded-full flex items-center justify-center"><?= $pending_returns ?></span>
                    <?php endif; ?>
                </a>
                <a href="products.php" class="px-3 py-2 rounded-lg transition-colors <?= $staff_current === 'products.php' ? 'bg-white/20 text-white font-semibold' : 'text-white/70 hover:text-white hover:bg-white/10' ?>">
                    Products
                </a>
                <a href="reviews.php" class="px-3 py-2 rounded-lg transition-colors <?= $staff_current === 'reviews.php' ? 'bg-white/20 text-white font-semibold' : 'text-white/70 hover:text-white hover:bg-white/10' ?>">
                    Reviews
                </a>
            </div>
        </div>
        <div class="flex items-center gap-3">
            <span class="text-white/60 text-sm hidden lg:block">
                <?= htmlspecialchars($_SESSION['user_name']) ?>
                <span class="text-white/30 text-xs ml-1">(Staff)</span>
            </span>
            <a href="../logout.php" class="bg-white/10 hover:bg-white/20 text-white/70 hover:text-white text-xs px-3 py-2 rounded-lg transition-colors">
                Logout
            </a>
        </div>
    </div>
</nav>