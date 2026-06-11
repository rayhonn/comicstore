<?php
session_start();
require_once 'includes/db.php';

// Cart count (if logged in)
$cart_count = 0;
if (isset($_SESSION['user_id'])) {
    $stmt = $pdo->prepare("SELECT SUM(cart_item_quantity) FROM cart_items WHERE cart_item_user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $cart_count = $stmt->fetchColumn() ?? 0;
}

// Notification count
$notif_count = 0;
if (isset($_SESSION['user_id'])) {
    $stmt_notif = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE notif_user_id = ? AND notif_is_read = 0");
    $stmt_notif->execute([$_SESSION['user_id']]);
    $notif_count = $stmt_notif->fetchColumn() ?? 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Membership - MangaVault</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        html { scroll-behavior: smooth; }
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
</head>
<body class="bg-white">

    <!-- Navbar -->
    <nav class="bg-white shadow-sm sticky top-0 z-50">
        <div class="max-w-7xl mx-auto px-6 py-4 flex justify-between items-center">
            <a href="index.php" class="text-xl font-black tracking-wide">
                MANGA<span class="text-red-600">VAULT</span>
            </a>
            <div class="hidden md:flex items-center gap-8 text-sm font-medium">
                <a href="index.php" class="text-gray-600 hover:text-red-600 transition">Home</a>
                <a href="customer/home.php" class="text-gray-600 hover:text-red-600 transition">Catalog</a>
                <a href="index.php#rankings" class="text-gray-600 hover:text-red-600 transition">Rankings</a>
                <a href="index.php#new-releases" class="text-gray-600 hover:text-red-600 transition">New Releases</a>
                <a href="membership.php" class="text-red-600 font-semibold">Membership</a>
            </div>
            <div class="flex items-center gap-4 text-sm">
                <?php if (isset($_SESSION['user_id'])): ?>
                    <!-- Notifications -->
                    <a href="customer/notifications.php" class="relative text-gray-600 hover:text-red-600 transition">
                        <svg class="w-6 h-6 <?= $notif_count > 0 ? 'bell-ring' : '' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path>
                        </svg>
                        <?php if ($notif_count > 0): ?>
                            <span class="absolute -top-2 -right-2 bg-red-600 text-white text-xs w-5 h-5 rounded-full flex items-center justify-center"><?= $notif_count ?></span>
                        <?php endif; ?>
                    </a>
                    <!-- Cart -->
                    <a href="customer/cart.php" class="relative text-gray-600 hover:text-red-600 transition">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"></path>
                        </svg>
                        <?php if ($cart_count > 0): ?>
                            <span class="absolute -top-2 -right-2 bg-red-600 text-white text-xs w-5 h-5 rounded-full flex items-center justify-center"><?= $cart_count ?></span>
                        <?php endif; ?>
                    </a>
                    <a href="customer/dashboard.php" class="text-gray-600 hover:text-red-600 transition font-medium">
                        Hi, <?= htmlspecialchars($_SESSION['user_first_name'] ?? $_SESSION['user_name'] ?? 'Guest') ?>!
                    </a>
                <?php else: ?>
                    <a href="login.php" class="text-gray-600 hover:text-red-600 transition">Login</a>
                    <a href="register.php" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg transition font-semibold text-sm">Register</a>
                <?php endif; ?>
            </div>
        </div>
    </nav>

    <!-- Hero -->
    <section class="bg-[#1a3a5c] text-white py-20">
        <div class="max-w-4xl mx-auto px-6 text-center">
            <p class="text-blue-300 text-sm font-semibold tracking-widest uppercase mb-4">Exclusive Benefits</p>
            <h1 class="text-5xl font-black mb-4">Become a <span class="text-blue-300">Member</span></h1>
            <p class="text-blue-100/70 text-lg max-w-xl mx-auto">Join MangaVault membership and unlock exclusive benefits that make every purchase better.</p>
        </div>
    </section>

    <!-- Benefits -->
    <section class="bg-[#f0f7ff] py-16">
        <div class="max-w-6xl mx-auto px-6">
            <h2 class="text-2xl font-black text-center text-gray-800 mb-10">What You Get</h2>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div class="bg-white rounded-2xl p-6 shadow-sm hover:shadow-md transition text-center">
                    <div class="text-5xl mb-4">🚚</div>
                    <h3 class="font-bold text-lg text-gray-800 mb-2">Free Shipping</h3>
                    <p class="text-gray-500 text-sm">Free delivery on all orders above RM50. No minimum required for members!</p>
                </div>
                <div class="bg-white rounded-2xl p-6 shadow-sm hover:shadow-md transition text-center">
                    <div class="text-5xl mb-4">💰</div>
                    <h3 class="font-bold text-lg text-gray-800 mb-2">Exclusive Discounts</h3>
                    <p class="text-gray-500 text-sm">Up to 15% off on all purchases, every single order throughout the year.</p>
                </div>
                <div class="bg-white rounded-2xl p-6 shadow-sm hover:shadow-md transition text-center">
                    <div class="text-5xl mb-4">📚</div>
                    <h3 class="font-bold text-lg text-gray-800 mb-2">Early Access</h3>
                    <p class="text-gray-500 text-sm">Get new releases before everyone else. Be the first to own the latest volumes.</p>
                </div>
                <div class="bg-white rounded-2xl p-6 shadow-sm hover:shadow-md transition text-center">
                    <div class="text-5xl mb-4">🎂</div>
                    <h3 class="font-bold text-lg text-gray-800 mb-2">Birthday Bonus</h3>
                    <p class="text-gray-500 text-sm">Receive a special 20% discount coupon during your birthday month.</p>
                </div>
                <div class="bg-white rounded-2xl p-6 shadow-sm hover:shadow-md transition text-center">
                    <div class="text-5xl mb-4">🎉</div>
                    <h3 class="font-bold text-lg text-gray-800 mb-2">Member-Only Events</h3>
                    <p class="text-gray-500 text-sm">Get invited to exclusive online events, author signings, and special sales.</p>
                </div>
                <div class="bg-white rounded-2xl p-6 shadow-sm hover:shadow-md transition text-center">
                    <div class="text-5xl mb-4">⭐</div>
                    <h3 class="font-bold text-lg text-gray-800 mb-2">Priority Support</h3>
                    <p class="text-gray-500 text-sm">Get faster customer support and priority handling for all your orders.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Pricing -->
    <section class="bg-white py-16">
        <div class="max-w-2xl mx-auto px-6 text-center">
            <h2 class="text-2xl font-black text-gray-800 mb-10">Simple Pricing</h2>
            <div class="bg-[#1a3a5c] text-white rounded-3xl p-10 shadow-xl">
                <p class="text-blue-300 text-sm font-semibold tracking-widest uppercase mb-4">Comic Book Club</p>
                <h3 class="text-xl font-bold mb-2">MangaVault Membership</h3>
                <div class="my-6">
                    <span class="text-6xl font-black text-blue-300">RM50</span>
                    <span class="text-blue-100/60 ml-2">/ year</span>
                </div>
                <p class="text-blue-100/70 text-sm mb-2">The average MangaVault member saves <span class="text-blue-300 font-bold">RM100+</span> annually on shipping and discounts!</p>
                <p class="text-blue-100/70 text-sm mb-8">Membership pays for itself with just a few orders throughout the year.</p>
                <ul class="text-left space-y-3 mb-8">
                    <?php foreach ([
                        'Free shipping on orders above RM50',
                        'Up to 15% discount on all purchases',
                        'Early access to new releases',
                        'Birthday bonus 20% coupon',
                        'Member-only events access',
                        'Priority customer support'
                    ] as $benefit): ?>
                    <li class="flex items-center gap-3 text-sm text-blue-100">
                        <span class="w-5 h-5 bg-blue-400/30 rounded-full flex items-center justify-center text-blue-300 text-xs flex-shrink-0">✓</span>
                        <?= $benefit ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php if (isset($_SESSION['user_id'])): ?>
                    <a href="customer/membership.php" class="block w-full bg-blue-400 hover:bg-blue-300 text-[#1a3a5c] font-black py-4 rounded-2xl text-sm tracking-widest uppercase transition">
                        JOIN NOW — RM50/YEAR
                    </a>
                <?php else: ?>
                    <a href="register.php" class="block w-full bg-blue-400 hover:bg-blue-300 text-[#1a3a5c] font-black py-4 rounded-2xl text-sm tracking-widest uppercase transition">
                        REGISTER TO JOIN
                    </a>
                    <p class="text-blue-100/50 text-xs mt-3">Already have an account? <a href="login.php" class="text-blue-300 hover:underline">Login here</a></p>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- Testimonials -->
    <section class="bg-[#f0f7ff] py-16">
        <div class="max-w-6xl mx-auto px-6">
            <h2 class="text-2xl font-black text-center text-gray-800 mb-10">What Our Members Say</h2>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div class="bg-white rounded-2xl p-6 shadow-sm">
                    <p class="text-gray-600 text-sm italic mb-4">"The free delivery alone has saved me over RM100 this year. Plus, I love getting early access to new releases!"</p>
                    <p class="font-bold text-sm text-gray-800">— Michael S.</p>
                </div>
                <div class="bg-white rounded-2xl p-6 shadow-sm">
                    <p class="text-gray-600 text-sm italic mb-4">"As someone who orders manga weekly, this membership paid for itself within the first two months. Absolutely worth it."</p>
                    <p class="font-bold text-sm text-gray-800">— Jessica T.</p>
                </div>
                <div class="bg-white rounded-2xl p-6 shadow-sm">
                    <p class="text-gray-600 text-sm italic mb-4">"The birthday bonus coupon was a surprise! Got 20% off my favourite series. Best membership ever."</p>
                    <p class="font-bold text-sm text-gray-800">— David L.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="bg-[#F5F0EB] text-gray-800 py-12 border-t border-gray-200">
        <div class="max-w-7xl mx-auto px-6">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-8 mb-10">
                <div class="col-span-2 md:col-span-1">
                    <h3 class="text-lg font-black mb-4">MANGA<span class="text-red-600">VAULT</span></h3>
                    <p class="text-gray-600 text-sm leading-relaxed">Malaysia's ultimate destination for manga and comic book lovers.</p>
                </div>
                <div>
                    <h4 class="font-bold mb-4 text-sm uppercase tracking-wide text-gray-800">Shop</h4>
                    <ul class="space-y-2 text-sm text-gray-600">
                        <li><a href="customer/home.php" class="hover:text-red-600 hover:translate-x-1 transition-all inline-block">All Manga</a></li>
                        <li><a href="customer/home.php?type=physical" class="hover:text-red-600 hover:translate-x-1 transition-all inline-block">Physical Books</a></li>
                        <li><a href="customer/home.php?type=ebook" class="hover:text-red-600 hover:translate-x-1 transition-all inline-block">E-Books</a></li>
                    </ul>
                </div>
                <div>
                    <h4 class="font-bold mb-4 text-sm uppercase tracking-wide text-gray-800">Help</h4>
                    <ul class="space-y-2 text-sm text-gray-600">
                        <li><a href="customer/orders.php" class="hover:text-red-600 hover:translate-x-1 transition-all inline-block">My Orders</a></li>
                        <li><a href="customer/dashboard.php" class="hover:text-red-600 hover:translate-x-1 transition-all inline-block">My Account</a></li>
                        <li><a href="customer/faq.php" class="hover:text-red-600 hover:translate-x-1 transition-all inline-block">FAQ</a></li>
                        <li><a href="customer/about.php" class="hover:text-red-600 hover:translate-x-1 transition-all inline-block">About Us</a></li>
                    </ul>
                </div>
                <div>
                    <h4 class="font-bold mb-4 text-sm uppercase tracking-wide text-gray-800">Follow Us</h4>
                    <div class="flex gap-3">
                        <a href="#" class="w-9 h-9 bg-gray-200 hover:bg-red-600 hover:text-white rounded-full flex items-center justify-center transition-all text-sm font-bold text-gray-600">f</a>
                        <a href="#" class="w-9 h-9 bg-gray-200 hover:bg-red-600 hover:text-white rounded-full flex items-center justify-center transition-all text-sm font-bold text-gray-600">t</a>
                        <a href="#" class="w-9 h-9 bg-gray-200 hover:bg-red-600 hover:text-white rounded-full flex items-center justify-center transition-all text-sm font-bold text-gray-600">in</a>
                    </div>
                </div>
            </div>
            <div class="border-t border-gray-300 pt-6 text-center text-xs text-gray-500">
                © 2026 MangaVault. All rights reserved.
            </div>
        </div>
    </footer>

</body>
</html>