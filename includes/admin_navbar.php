<?php
$admin_current = basename($_SERVER['PHP_SELF']);

// Pending counts
$pending_orders_count = $pdo->query("SELECT COUNT(*) FROM orders WHERE order_status = 'pending' AND order_payment_status = 'confirmed'")->fetchColumn();
$pending_returns_count = $pdo->query("SELECT COUNT(*) FROM return_requests WHERE return_status = 'pending'")->fetchColumn();
$pending_reviews_count = $pdo->query("SELECT COUNT(*) FROM product_reviews WHERE review_status = 'pending'")->fetchColumn();
$low_stock_count = $pdo->query("SELECT COUNT(*) FROM product_physical WHERE physical_stock_quantity <= physical_low_stock_threshold")->fetchColumn();
?>
<nav class="bg-[#1e2d4a] text-white sticky top-0 z-50 shadow-lg">
    <div class="max-w-7xl mx-auto px-6 py-3 flex justify-between items-center">
        <div class="flex items-center gap-8">
            <a href="dashboard.php" class="text-lg font-black tracking-wide">
                MANGA<span class="text-red-400">VAULT</span>
                <span class="text-xs text-white/40 font-normal ml-2">Admin</span>
            </a>
            <div class="hidden lg:flex items-center gap-1 text-sm">
                <a href="dashboard.php" class="px-3 py-2 rounded-lg transition-colors <?= $admin_current === 'dashboard.php' ? 'bg-white/20 text-white font-semibold' : 'text-white/70 hover:text-white hover:bg-white/10' ?>">
                    Dashboard
                </a>
                <a href="products.php" class="px-3 py-2 rounded-lg transition-colors <?= in_array($admin_current, ['products.php','add_product.php','edit_product.php']) ? 'bg-white/20 text-white font-semibold' : 'text-white/70 hover:text-white hover:bg-white/10' ?>">
                    Products
                </a>
                <a href="orders.php" class="relative px-3 py-2 rounded-lg transition-colors <?= $admin_current === 'orders.php' ? 'bg-white/20 text-white font-semibold' : 'text-white/70 hover:text-white hover:bg-white/10' ?>">
                    Orders
                    <?php if ($pending_orders_count > 0): ?>
                    <span class="absolute -top-1 -right-1 bg-red-500 text-white text-xs w-4 h-4 rounded-full flex items-center justify-center"><?= $pending_orders_count ?></span>
                    <?php endif; ?>
                </a>
                <a href="returns.php" class="relative px-3 py-2 rounded-lg transition-colors <?= $admin_current === 'returns.php' ? 'bg-white/20 text-white font-semibold' : 'text-white/70 hover:text-white hover:bg-white/10' ?>">
                    Returns
                    <?php if ($pending_returns_count > 0): ?>
                    <span class="absolute -top-1 -right-1 bg-red-500 text-white text-xs w-4 h-4 rounded-full flex items-center justify-center"><?= $pending_returns_count ?></span>
                    <?php endif; ?>
                </a>
                <a href="reviews.php" class="relative px-3 py-2 rounded-lg transition-colors <?= $admin_current === 'reviews.php' ? 'bg-white/20 text-white font-semibold' : 'text-white/70 hover:text-white hover:bg-white/10' ?>">
                    Reviews
                    <?php if ($pending_reviews_count > 0): ?>
                    <span class="absolute -top-1 -right-1 bg-red-500 text-white text-xs w-4 h-4 rounded-full flex items-center justify-center"><?= $pending_reviews_count ?></span>
                    <?php endif; ?>
                </a>
                <a href="users.php" class="px-3 py-2 rounded-lg transition-colors <?= $admin_current === 'users.php' ? 'bg-white/20 text-white font-semibold' : 'text-white/70 hover:text-white hover:bg-white/10' ?>">
                    Users
                </a>

                <!-- More dropdown -->
                <div class="relative" id="moreDropdown">
                    <button onclick="toggleMoreMenu()" class="px-3 py-2 rounded-lg text-white/70 hover:text-white hover:bg-white/10 transition-colors flex items-center gap-1">
                        More
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                    </button>
                    <div class="absolute top-full left-0 mt-1 bg-white rounded-xl shadow-xl py-2 w-48 hidden z-50" id="moreMenu">
                        <a href="categories.php" class="flex items-center gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 hover:text-red-600 transition-colors">
                            📂 Categories
                        </a>
                        <a href="genres.php" class="flex items-center gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 hover:text-red-600 transition-colors">
                            🏷️ Genres
                        </a>
                        <a href="staff.php" class="flex items-center gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 hover:text-red-600 transition-colors">
                            👥 Staff
                        </a>
                        <a href="vouchers.php" class="flex items-center gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 hover:text-red-600 transition-colors">
                            🎟️ Vouchers
                        </a>
                        <div class="border-t border-gray-100 my-1"></div>
                        <a href="faq.php" class="flex items-center gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 hover:text-red-600 transition-colors">
                            ❓ FAQ
                        </a>
                        <a href="about.php" class="flex items-center gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 hover:text-red-600 transition-colors">
                            ℹ️ About Us
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <div class="flex items-center gap-3">
            <?php if ($low_stock_count > 0): ?>
            <a href="products.php?filter=low_stock" class="bg-red-500/20 text-red-300 text-xs px-3 py-1.5 rounded-lg font-semibold hover:bg-red-500/30 transition-colors">
                ⚠️ <?= $low_stock_count ?> Low Stock
            </a>
            <?php endif; ?>
            <span class="text-white/60 text-sm hidden lg:block">
                <?= htmlspecialchars($_SESSION['user_name']) ?>
                <span class="text-white/30 text-xs ml-1">(<?= ucfirst($_SESSION['role']) ?>)</span>
            </span>
            <a href="../logout.php" class="bg-white/10 hover:bg-white/20 text-white/70 hover:text-white text-xs px-3 py-2 rounded-lg transition-colors">
                Logout
            </a>
        </div>
    </div>
    <script>
    function toggleMoreMenu() {
        const menu = document.getElementById('moreMenu');
        menu.classList.toggle('hidden');
    }
    // Close when clicking outside
    document.addEventListener('click', function(e) {
        const dropdown = document.getElementById('moreDropdown');
        if (dropdown && !dropdown.contains(e.target)) {
            document.getElementById('moreMenu').classList.add('hidden');
        }
    });
</script>
</nav>