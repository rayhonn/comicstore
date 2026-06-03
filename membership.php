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
                    <a href="customer/cart.php" class="relative text-gray-600 hover:text-red-600 transition">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"></path>
                        </svg>
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
                    <a href="customer/membership.php"
                       class="block w-full bg-blue-400 hover:bg-blue-300 text-[#1a3a5c] font-black py-4 rounded-2xl text-sm tracking-widest uppercase transition">
                        JOIN NOW — RM50/YEAR
                    </a>
                <?php else: ?>
                    <a href="register.php"
                       class="block w-full bg-blue-400 hover:bg-blue-300 text-[#1a3a5c] font-black py-4 rounded-2xl text-sm tracking-widest uppercase transition">
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
    <footer class="bg-[#1e2d4a] text-white py-12">
        <div class="max-w-7xl mx-auto px-6">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-8 mb-8">
                <div>
                    <h3 class="text-lg font-black mb-3">MANGA<span class="text-red-600">VAULT</span></h3>
                    <p class="text-gray-400 text-sm">Malaysia's ultimate destination for manga and comic book lovers.</p>
                </div>
                <div>
                    <h4 class="font-bold mb-3 text-sm uppercase tracking-wide">Shop</h4>
                    <ul class="space-y-2 text-sm text-gray-400">
                        <li><a href="customer/home.php" class="hover:text-red-500 transition">All Manga</a></li>
                        <li><a href="customer/home.php?type=physical" class="hover:text-red-500 transition">Physical Books</a></li>
                        <li><a href="customer/home.php?type=ebook" class="hover:text-red-500 transition">E-Books</a></li>
                    </ul>
                </div>
                <div>
                    <h4 class="font-bold mb-3 text-sm uppercase tracking-wide">Help</h4>
                    <ul class="space-y-2 text-sm text-gray-400">
                        <li><a href="customer/orders.php" class="hover:text-red-500 transition">My Orders</a></li>
                        <li><a href="customer/profile.php" class="hover:text-red-500 transition">My Account</a></li>
                    </ul>
                </div>
                <div>
                    <h4 class="font-bold mb-3 text-sm uppercase tracking-wide">Follow Us</h4>
                    <div class="flex gap-3">
                        <a href="#" class="w-9 h-9 bg-white/10 rounded-full flex items-center justify-center hover:bg-red-600 transition text-sm">f</a>
                        <a href="#" class="w-9 h-9 bg-white/10 rounded-full flex items-center justify-center hover:bg-red-600 transition text-sm">t</a>
                        <a href="#" class="w-9 h-9 bg-white/10 rounded-full flex items-center justify-center hover:bg-red-600 transition text-sm">in</a>
                    </div>
                </div>
            </div>
            <div class="border-t border-white/10 pt-6 text-center text-xs text-gray-500">
                © 2026 MangaVault. All rights reserved.
            </div>
        </div>
    </footer>

</body>
</html>