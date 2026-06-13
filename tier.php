<?php
session_start();
require_once 'includes/db.php';

$cart_count = 0;
if (isset($_SESSION['user_id'])) {
    $stmt = $pdo->prepare("SELECT SUM(cart_item_quantity) FROM cart_items WHERE cart_item_user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $cart_count = $stmt->fetchColumn() ?? 0;
}

// Get current user tier if logged in
$user_tier = null;
$user_spending = 0;
if (isset($_SESSION['user_id'])) {
    $stmt = $pdo->prepare("SELECT user_tier, user_lifetime_spending FROM users WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    $user_tier = $u['user_tier'] ?? 'bronze';
    $user_spending = $u['user_lifetime_spending'] ?? 0;
}

// Load tier config from database
$tier_config_rows = $pdo->query("SELECT * FROM tier_config ORDER BY tier_min_spending ASC")->fetchAll(PDO::FETCH_ASSOC);
$tier_config = [];
foreach ($tier_config_rows as $row) {
    $tier_config[$row['tier_name']] = $row;
}

// Load tier benefits from database
$benefit_rows = $pdo->query("SELECT * FROM tier_benefits ORDER BY benefit_tier, benefit_order ASC")->fetchAll(PDO::FETCH_ASSOC);
$benefits = [];
foreach ($benefit_rows as $row) {
    $benefits[$row['benefit_tier']][] = $row['benefit_text'];
}

// Static display config (colours/emojis only)
$tier_display = [
    'bronze'   => ['label' => 'Bronze',   'emoji' => '🥉', 'color' => 'from-orange-700 to-orange-500', 'bg' => 'bg-orange-50',  'border' => 'border-orange-200', 'text' => 'text-orange-700'],
    'silver'   => ['label' => 'Silver',   'emoji' => '🥈', 'color' => 'from-gray-500 to-gray-300',     'bg' => 'bg-gray-50',    'border' => 'border-gray-300',   'text' => 'text-gray-600'],
    'gold'     => ['label' => 'Gold',     'emoji' => '🥇', 'color' => 'from-yellow-600 to-yellow-400', 'bg' => 'bg-yellow-50',  'border' => 'border-yellow-300', 'text' => 'text-yellow-700'],
    'platinum' => ['label' => 'Platinum', 'emoji' => '💎', 'color' => 'from-blue-700 to-cyan-400',     'bg' => 'bg-blue-50',    'border' => 'border-blue-200',   'text' => 'text-blue-700'],
];

// Build next tier info for progress display
$tier_keys = array_keys($tier_config);
$next_tier = null;
$needed = 0;
if ($user_tier && $user_tier !== 'platinum') {
    $current_index = array_search($user_tier, $tier_keys);
    if ($current_index !== false && isset($tier_keys[$current_index + 1])) {
        $next_key = $tier_keys[$current_index + 1];
        $next_tier = $tier_display[$next_key];
        $needed = $tier_config[$next_key]['tier_min_spending'] - $user_spending;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Membership Tiers - MangaVault</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-[#F5F0EB]">

    <!-- Navbar -->
    <nav class="bg-white shadow-sm sticky top-0 z-50">
        <div class="max-w-7xl mx-auto px-6 py-4 flex justify-between items-center">
            <a href="index.php" class="text-xl font-black tracking-wide">MANGA<span class="text-red-600">VAULT</span></a>
            <div class="hidden md:flex items-center gap-8 text-sm font-medium">
                <a href="index.php" class="text-gray-600 hover:text-red-600 transition">Home</a>
                <a href="customer/home.php" class="text-gray-600 hover:text-red-600 transition">Catalog</a>
                <a href="tier.php" class="text-red-600 font-semibold">Membership</a>
            </div>
            <div class="flex items-center gap-4 text-sm">
                <?php if (isset($_SESSION['user_id'])): ?>
                    <a href="customer/cart.php" class="relative text-gray-600 hover:text-red-600 transition">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
                        <?php if ($cart_count > 0): ?>
                            <span class="absolute -top-2 -right-2 bg-red-600 text-white text-xs w-5 h-5 rounded-full flex items-center justify-center"><?= $cart_count ?></span>
                        <?php endif; ?>
                    </a>
                    <a href="customer/profile.php" class="text-gray-600 hover:text-red-600 transition">My Account</a>
                    <a href="logout.php" class="text-gray-600 hover:text-red-600 transition">Logout</a>
                <?php else: ?>
                    <a href="login.php" class="text-gray-600 hover:text-red-600 transition">Login</a>
                    <a href="register.php" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg transition font-semibold text-sm">Register</a>
                <?php endif; ?>
            </div>
        </div>
    </nav>

    <!-- Hero -->
    <section class="bg-[#1a3a5c] text-white py-16">
        <div class="max-w-4xl mx-auto px-6 text-center">
            <p class="text-blue-300 text-sm font-semibold tracking-widest uppercase mb-3">Loyalty Program</p>
            <h1 class="text-5xl font-black mb-4">Membership <span class="text-blue-300">Tiers</span></h1>
            <p class="text-blue-100/70 text-lg max-w-xl mx-auto">Spend more, earn more. Every purchase brings you closer to the next tier and better rewards.</p>

            <?php if ($user_tier): ?>
            <div class="mt-8 inline-block bg-white/10 border border-white/20 rounded-2xl px-8 py-4">
                <p class="text-blue-200 text-sm mb-1">Your Current Tier</p>
                <p class="text-2xl font-black"><?= $tier_display[$user_tier]['emoji'] ?> <?= $tier_display[$user_tier]['label'] ?></p>
                <p class="text-blue-200 text-sm mt-1">Lifetime Spending: <span class="text-white font-bold">RM <?= number_format($user_spending, 2) ?></span></p>

                <?php if ($next_tier && $needed > 0): ?>
                <p class="text-blue-300 text-xs mt-2">Spend <span class="text-white font-bold">RM <?= number_format($needed, 2) ?></span> more to reach <?= $next_tier['emoji'] ?> <?= $next_tier['label'] ?></p>
                <?php elseif ($user_tier === 'platinum'): ?>
                <p class="text-yellow-300 text-xs mt-2">🎉 You've reached the highest tier!</p>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- Tier Cards -->
    <section class="py-16">
        <div class="max-w-6xl mx-auto px-6">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                <?php 
                $tier_config_list = array_values($tier_config);
                $tier_config_keys = array_keys($tier_config);
                foreach ($tier_config as $key => $config):
                    $display = $tier_display[$key];
                    $is_current = ($user_tier === $key);
                    $tier_benefits = $benefits[$key] ?? [];
                    $current_idx = array_search($key, $tier_config_keys);
                    $next_config = $tier_config_list[$current_idx + 1] ?? null;
                    $max_label = $next_config 
                        ? 'RM ' . number_format($next_config['tier_min_spending'] - 0.01, 2)
                        : null;
                ?>
                <div class="<?= $display['bg'] ?> <?= $display['border'] ?> border-2 rounded-2xl overflow-hidden shadow-sm <?= $is_current ? 'ring-4 ring-offset-2 ring-red-400 scale-105' : '' ?> transition-transform">
                    <div class="bg-gradient-to-br <?= $display['color'] ?> p-6 text-white text-center">
                        <div class="text-5xl mb-2"><?= $display['emoji'] ?></div>
                        <h3 class="text-xl font-black tracking-wide"><?= $display['label'] ?></h3>
                        <p class="text-white/70 text-xs mt-1">
                            RM <?= number_format($config['tier_min_spending']) ?>
                            <?= $max_label ? '– ' . $max_label : '+' ?>
                        </p>
                        <?php if ($is_current): ?>
                            <span class="mt-2 inline-block bg-white/20 text-white text-xs font-bold px-3 py-1 rounded-full">✓ Your Tier</span>
                        <?php endif; ?>
                    </div>
                    <div class="p-5">
                        <ul class="space-y-2">
                            <?php foreach ($tier_benefits as $benefit): ?>
                            <li class="flex items-start gap-2 text-sm <?= $display['text'] ?>">
                                <span class="mt-0.5 flex-shrink-0">✓</span>
                                <span><?= htmlspecialchars($benefit) ?></span>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- How it works -->
    <section class="bg-white py-16">
        <div class="max-w-4xl mx-auto px-6">
            <h2 class="text-2xl font-black text-center text-gray-800 mb-10">How It Works</h2>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-8 text-center">
                <div>
                    <div class="text-4xl mb-3">🛒</div>
                    <h3 class="font-bold text-gray-800 mb-2">1. Shop</h3>
                    <p class="text-gray-500 text-sm">Every confirmed purchase adds to your lifetime spending total.</p>
                </div>
                <div>
                    <div class="text-4xl mb-3">📈</div>
                    <h3 class="font-bold text-gray-800 mb-2">2. Level Up</h3>
                    <p class="text-gray-500 text-sm">Hit the spending threshold and your tier upgrades automatically.</p>
                </div>
                <div>
                    <div class="text-4xl mb-3">🎁</div>
                    <h3 class="font-bold text-gray-800 mb-2">3. Enjoy Perks</h3>
                    <p class="text-gray-500 text-sm">Get better points multipliers, vouchers, and exclusive benefits.</p>
                </div>
            </div>
        </div>
    </section>

    <?php if (!isset($_SESSION['user_id'])): ?>
    <section class="bg-[#1e2d4a] py-12">
        <div class="max-w-xl mx-auto px-6 text-center">
            <h2 class="text-2xl font-black text-white mb-3">Start Earning Today</h2>
            <p class="text-gray-300 text-sm mb-6">Create a free account and every purchase counts towards your tier.</p>
            <a href="register.php" class="bg-red-600 hover:bg-red-700 text-white font-black px-10 py-4 rounded-full text-sm tracking-widest uppercase transition">Create Free Account</a>
        </div>
    </section>
    <?php endif; ?>

</body>
</html>