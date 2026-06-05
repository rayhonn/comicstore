<?php
session_start();
require_once 'includes/db.php';

$rankings = $pdo->query("
    SELECT p.*, 
    COALESCE(SUM(oi.order_item_quantity), 0) as total_sold,
    pp.physical_stock_quantity
    FROM products p
    LEFT JOIN order_items oi ON p.product_id = oi.order_item_product_id
    LEFT JOIN product_physical pp ON p.product_id = pp.physical_product_id
    WHERE p.product_is_available = 1
    GROUP BY p.product_id
    ORDER BY total_sold DESC
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

$new_releases = $pdo->query("
    SELECT p.*, pp.physical_stock_quantity
    FROM products p
    LEFT JOIN product_physical pp ON p.product_id = pp.physical_product_id
    WHERE p.product_is_available = 1
    ORDER BY p.product_created_at DESC
    LIMIT 4
")->fetchAll(PDO::FETCH_ASSOC);

$genres = $pdo->query("
    SELECT g.*, COUNT(pg.product_genres_product_id) as product_count
    FROM genres g
    LEFT JOIN product_genres pg ON g.genre_id = pg.product_genres_genre_id
    GROUP BY g.genre_id
    ORDER BY product_count DESC
    LIMIT 4
")->fetchAll(PDO::FETCH_ASSOC);

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
    <title>MangaVault - Your Ultimate Manga Destination</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        html { scroll-behavior: smooth; }
        .line-clamp-2 { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }

        /* Page transition */
        body { opacity: 0; animation: fadeIn 0.4s ease forwards; }
        @keyframes fadeIn { to { opacity: 1; } }

        /* Mobile menu slide */
        #mobileMenu {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease;
        }
        #mobileMenu.open {
            max-height: 500px;
        }

        /* Hover underline effect for nav links */
        .nav-link {
            position: relative;
        }
        .nav-link::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            width: 0;
            height: 2px;
            background: #dc2626;
            transition: width 0.2s ease;
        }
        .nav-link:hover::after { width: 100%; }
    </style>
</head>
<body class="bg-[#F5F0EB]">

    <!-- Navbar -->
    <nav class="bg-white shadow-sm sticky top-0 z-50">
        <div class="max-w-7xl mx-auto px-6 py-4 flex justify-between items-center">
            <a href="index.php" class="text-xl font-black tracking-wide text-gray-900">
                MANGA<span class="text-red-600">VAULT</span>
            </a>

            <!-- Desktop Menu -->
            <div class="hidden lg:flex items-center gap-8 text-sm font-medium">
                <a href="index.php" class="nav-link text-red-600 font-semibold">Home</a>
                <a href="customer/home.php" class="nav-link text-gray-600 hover:text-red-600">Catalog</a>
                <a href="#rankings" class="nav-link text-gray-600 hover:text-red-600">Rankings</a>
                <a href="#new-releases" class="nav-link text-gray-600 hover:text-red-600">New Releases</a>
                <a href="membership.php" class="nav-link text-gray-600 hover:text-red-600">Membership</a>
            </div>

            <!-- Right Side -->
            <div class="flex items-center gap-4 text-sm">
                <?php if (isset($_SESSION['user_id'])): ?>
                    <a href="customer/cart.php" class="relative text-gray-600 hover:text-red-600 transition-colors duration-200">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"></path>
                        </svg>
                        <?php if ($cart_count > 0): ?>
                            <span class="absolute -top-2 -right-2 bg-red-600 text-white text-xs w-5 h-5 rounded-full flex items-center justify-center"><?= $cart_count ?></span>
                        <?php endif; ?>
                    </a>
                    <a href="customer/profile.php" class="hidden lg:block text-gray-600 hover:text-red-600 transition-colors duration-200 font-medium">
                        Hi, <?= htmlspecialchars($_SESSION['user_first_name'] ?? $_SESSION['user_name']) ?>!
                    </a>
                    <a href="logout.php" class="hidden lg:block text-gray-400 hover:text-red-600 transition-colors duration-200 text-xs">Logout</a>
                <?php else: ?>
                    <a href="login.php" class="hidden lg:block text-gray-600 hover:text-red-600 transition-colors duration-200 font-medium">Login</a>
                    <a href="register.php" class="hidden lg:block bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg transition-colors duration-200 font-semibold">Register</a>
                <?php endif; ?>

                <!-- Hamburger Button -->
                <button id="menuBtn" class="lg:hidden flex flex-col gap-1.5 p-1 focus:outline-none" aria-label="Menu">
                    <span class="hamburger-line w-6 h-0.5 bg-gray-700 rounded transition-all duration-300"></span>
                    <span class="hamburger-line w-6 h-0.5 bg-gray-700 rounded transition-all duration-300"></span>
                    <span class="hamburger-line w-6 h-0.5 bg-gray-700 rounded transition-all duration-300"></span>
                </button>
            </div>
        </div>

        <!-- Mobile Menu -->
        <div id="mobileMenu" class="lg:hidden bg-white border-t border-gray-100">
            <div class="px-6 py-4 space-y-1">
                <a href="index.php" class="block py-2 text-sm font-semibold text-red-600">Home</a>
                <a href="customer/home.php" class="block py-2 text-sm font-medium text-gray-600 hover:text-red-600 transition-colors">Catalog</a>
                <a href="#rankings" class="block py-2 text-sm font-medium text-gray-600 hover:text-red-600 transition-colors" onclick="closeMenu()">Rankings</a>
                <a href="#new-releases" class="block py-2 text-sm font-medium text-gray-600 hover:text-red-600 transition-colors" onclick="closeMenu()">New Releases</a>
                <a href="membership.php" class="block py-2 text-sm font-medium text-gray-600 hover:text-red-600 transition-colors">Membership</a>
                <div class="border-t border-gray-100 pt-3 mt-3 space-y-1">
                    <?php if (isset($_SESSION['user_id'])): ?>
                        <a href="customer/profile.php" class="block py-2 text-sm font-medium text-gray-600 hover:text-red-600 transition-colors">
                            Hi, <?= htmlspecialchars($_SESSION['user_first_name'] ?? $_SESSION['user_name']) ?>!
                        </a>
                        <a href="customer/cart.php" class="block py-2 text-sm font-medium text-gray-600 hover:text-red-600 transition-colors">Cart <?= $cart_count > 0 ? "($cart_count)" : '' ?></a>
                        <a href="logout.php" class="block py-2 text-sm font-medium text-gray-400 hover:text-red-600 transition-colors">Logout</a>
                    <?php else: ?>
                        <a href="login.php" class="block py-2 text-sm font-medium text-gray-600 hover:text-red-600 transition-colors">Login</a>
                        <a href="register.php" class="block py-2 text-sm font-semibold text-red-600">Register</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </nav>

    <!-- Hero Banner -->
    <section class="bg-[#F5F0EB] py-20">
        <div class="max-w-7xl mx-auto px-6">
            <div class="max-w-2xl">
                <p class="text-red-600 text-sm font-semibold tracking-widest uppercase mb-4 animate-pulse">Malaysia's #1 Manga Store</p>
                <h1 class="text-5xl md:text-7xl font-black leading-none text-gray-900 mb-6">
                    YOUR<br>
                    ULTIMATE<br>
                    <span class="text-red-600">MANGA</span><br>
                    DESTINATION
                </h1>
                <p class="text-gray-500 text-lg mb-10 max-w-lg leading-relaxed">
                    Discover thousands of manga volumes and e-books. Build your collection. Never miss a new release.
                </p>
                <div class="flex flex-wrap gap-4">
                    <a href="customer/home.php"
                       class="bg-red-600 hover:bg-red-700 hover:scale-105 text-white font-bold px-8 py-4 rounded-full text-sm tracking-widest uppercase transition-all duration-200 shadow-lg shadow-red-200">
                        SHOP NOW
                    </a>
                    <a href="membership.php"
                       class="border-2 border-gray-900 hover:bg-gray-900 hover:text-white hover:scale-105 text-gray-900 font-bold px-8 py-4 rounded-full text-sm tracking-widest uppercase transition-all duration-200">
                        BECOME A MEMBER
                    </a>
                </div>
            </div>
        </div>
    </section>

    <!-- Weekly Rankings -->
    <section id="rankings" class="bg-white py-16">
        <div class="max-w-7xl mx-auto px-6">
            <div class="flex items-center gap-3 mb-3">
                <span class="text-2xl">🔥</span>
                <h2 class="text-2xl font-black tracking-widest uppercase text-gray-900">Weekly Manga Rankings</h2>
                <span class="text-2xl">🔥</span>
            </div>
            <div class="border-b-2 border-red-600 w-16 mb-10"></div>

            <?php if (count($rankings) === 0): ?>
                <p class="text-gray-400 text-center py-8">No rankings available yet.</p>
            <?php else: ?>
                <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-5 gap-6">
                    <?php foreach ($rankings as $index => $product): ?>
                    <a href="customer/product_detail.php?id=<?= $product['product_id'] ?>"
                       class="bg-[#F5F0EB] rounded-2xl overflow-hidden hover:-translate-y-2 hover:shadow-xl transition-all duration-300 block group">
                        <div class="relative overflow-hidden">
                            <?php if ($product['product_cover_image']): ?>
                                <img src="assets/images/<?= htmlspecialchars($product['product_cover_image']) ?>"
                                     class="w-full h-56 object-cover group-hover:scale-105 transition-transform duration-300">
                            <?php else: ?>
                                <div class="w-full h-56 bg-gray-200 flex items-center justify-center text-gray-400 text-3xl font-bold">
                                    <?= strtoupper(substr($product['product_title'], 0, 2)) ?>
                                </div>
                            <?php endif; ?>
                            <div class="absolute top-3 left-3 w-8 h-8 <?= $index === 0 ? 'bg-yellow-500' : ($index === 1 ? 'bg-gray-400' : ($index === 2 ? 'bg-orange-500' : 'bg-gray-700')) ?> text-white rounded-full flex items-center justify-center font-black text-sm shadow-lg">
                                <?= $index + 1 ?>
                            </div>
                        </div>
                        <div class="p-3">
                            <h3 class="font-bold text-sm text-gray-800 line-clamp-2 mb-1"><?= htmlspecialchars($product['product_title']) ?></h3>
                            <p class="text-xs text-gray-400 mb-2"><?= htmlspecialchars($product['product_author'] ?? '') ?></p>
                            <p class="text-red-600 font-bold text-sm">RM <?= number_format($product['product_price'], 2) ?></p>
                        </div>
                    </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- Explore Genres -->
    <section id="genres" class="bg-[#F5F0EB] py-16">
        <div class="max-w-7xl mx-auto px-6">
            <h2 class="text-2xl font-black tracking-widest uppercase text-gray-900 mb-3">Explore Manga Genres</h2>
            <div class="border-b-2 border-red-600 w-16 mb-10"></div>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-6">
                <?php if (count($genres) === 0): ?>
                    <p class="text-gray-400 col-span-4 text-center py-8">No genres available yet.</p>
                <?php else: ?>
                    <?php
                    $genre_colors = [
                        'from-red-800 to-red-600',
                        'from-blue-800 to-blue-600',
                        'from-purple-800 to-purple-600',
                        'from-green-800 to-green-600'
                    ];
                    foreach ($genres as $i => $genre): ?>
                    <a href="customer/home.php?genre_id=<?= $genre['genre_id'] ?>"
                       class="group rounded-2xl overflow-hidden shadow-sm hover:shadow-xl hover:-translate-y-1 transition-all duration-300">
                        <div class="h-48 bg-gradient-to-br <?= $genre_colors[$i % 4] ?> flex items-end p-4 relative overflow-hidden">
                            <div class="absolute inset-0 bg-black/0 group-hover:bg-black/20 transition-colors duration-300"></div>
                            <div class="relative z-10">
                                <span class="bg-white/20 backdrop-blur-sm text-white text-xs px-3 py-1 rounded-full font-semibold uppercase tracking-wide">
                                    <?= htmlspecialchars($genre['genre_name']) ?>
                                </span>
                                <p class="text-white/70 text-xs mt-2"><?= $genre['product_count'] ?> Titles</p>
                            </div>
                        </div>
                        <div class="p-4 bg-white">
                            <p class="text-sm text-gray-500 group-hover:text-red-600 transition-colors duration-200">Explore Now →</p>
                        </div>
                    </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- New Releases -->
    <section id="new-releases" class="bg-white py-16">
        <div class="max-w-7xl mx-auto px-6">
            <h2 class="text-2xl font-black tracking-widest uppercase text-gray-900 mb-3">New Releases</h2>
            <div class="border-b-2 border-red-600 w-16 mb-10"></div>

            <?php if (count($new_releases) === 0): ?>
                <p class="text-gray-400 text-center py-8">No products available yet.</p>
            <?php else: ?>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-6">
                    <?php foreach ($new_releases as $product): ?>
                    <div class="bg-[#F5F0EB] rounded-2xl overflow-hidden hover:-translate-y-1 hover:shadow-lg transition-all duration-300 group">
                        <div class="relative overflow-hidden">
                            <?php if ($product['product_cover_image']): ?>
                                <img src="assets/images/<?= htmlspecialchars($product['product_cover_image']) ?>"
                                     class="w-full h-56 object-cover group-hover:scale-105 transition-transform duration-300">
                            <?php else: ?>
                                <div class="w-full h-56 bg-gray-200 flex items-center justify-center text-3xl font-bold text-gray-400">
                                    <?= strtoupper(substr($product['product_title'], 0, 2)) ?>
                                </div>
                            <?php endif; ?>
                            <span class="absolute top-3 left-3 bg-red-600 text-white text-xs px-2 py-1 rounded font-semibold">NEW</span>
                        </div>
                        <div class="p-4">
                            <h3 class="font-bold text-sm text-gray-800 line-clamp-2 mb-1"><?= htmlspecialchars($product['product_title']) ?></h3>
                            <p class="text-xs text-gray-400 mb-1"><?= htmlspecialchars($product['product_author'] ?? '') ?></p>
                            <p class="text-red-600 font-bold mb-3">RM <?= number_format($product['product_price'], 2) ?></p>
                            <a href="customer/product_detail.php?id=<?= $product['product_id'] ?>"
                               class="block text-center bg-red-600 hover:bg-red-700 text-white text-xs font-semibold py-2 rounded-lg transition-colors duration-200">
                                View Details
                            </a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="text-center mt-10">
                    <a href="customer/home.php"
                       class="border-2 border-red-600 text-red-600 hover:bg-red-600 hover:text-white font-bold px-8 py-3 rounded-full text-sm tracking-widest uppercase transition-all duration-200 hover:scale-105 inline-block">
                        VIEW ALL PRODUCTS
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- Membership CTA -->
    <section class="bg-[#1e2d4a] py-16">
        <div class="max-w-4xl mx-auto px-6 text-center">
            <p class="text-blue-300 text-sm font-semibold tracking-widest uppercase mb-4">Exclusive Benefits</p>
            <h2 class="text-4xl font-black text-white mb-4">Become a <span class="text-blue-300">Member</span></h2>
            <p class="text-gray-300 mb-8 max-w-xl mx-auto leading-relaxed">Join MangaVault membership and unlock free shipping, exclusive discounts, early access to new releases and more!</p>
            <a href="membership.php"
               class="bg-blue-400 hover:bg-blue-300 hover:scale-105 text-[#1e2d4a] font-black px-10 py-4 rounded-full text-sm tracking-widest uppercase transition-all duration-200 inline-block shadow-lg shadow-blue-900/30">
                LEARN MORE & JOIN
            </a>
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
                        <li><a href="customer/home.php" class="hover:text-red-600 hover:translate-x-1 transition-all duration-200 inline-block">All Manga</a></li>
                        <li><a href="customer/home.php?type=physical" class="hover:text-red-600 hover:translate-x-1 transition-all duration-200 inline-block">Physical Books</a></li>
                        <li><a href="customer/home.php?type=ebook" class="hover:text-red-600 hover:translate-x-1 transition-all duration-200 inline-block">E-Books</a></li>
                        <li><a href="#new-releases" class="hover:text-red-600 hover:translate-x-1 transition-all duration-200 inline-block">New Releases</a></li>
                    </ul>
                </div>
                <div>
                    <h4 class="font-bold mb-4 text-sm uppercase tracking-wide text-gray-800">Help</h4>
                    <ul class="space-y-2 text-sm text-gray-600">
                        <li><a href="customer/orders.php" class="hover:text-red-600 hover:translate-x-1 transition-all duration-200 inline-block">My Orders</a></li>
                        <li><a href="customer/profile.php" class="hover:text-red-600 hover:translate-x-1 transition-all duration-200 inline-block">My Account</a></li>
                        <li><a href="membership.php" class="hover:text-red-600 hover:translate-x-1 transition-all duration-200 inline-block">Membership</a></li>
                        <li><a href="customer/faq.php" class="hover:text-red-600 hover:translate-x-1 transition-all duration-200 inline-block">FAQ</a></li>
                        <li><a href="customer/about.php" class="hover:text-red-600 hover:translate-x-1 transition-all duration-200 inline-block">About Us</a></li>
                    </ul>
                </div>
                <div>
                    <h4 class="font-bold mb-4 text-sm uppercase tracking-wide text-gray-800">Follow Us</h4>
                    <div class="flex gap-3">
                        <a href="#" class="w-9 h-9 bg-gray-200 hover:bg-red-600 hover:text-white rounded-full flex items-center justify-center transition-all duration-200 text-sm font-bold text-gray-600">f</a>
                        <a href="#" class="w-9 h-9 bg-gray-200 hover:bg-red-600 hover:text-white rounded-full flex items-center justify-center transition-all duration-200 text-sm font-bold text-gray-600">t</a>
                        <a href="#" class="w-9 h-9 bg-gray-200 hover:bg-red-600 hover:text-white rounded-full flex items-center justify-center transition-all duration-200 text-sm font-bold text-gray-600">in</a>
                    </div>
                </div>
            </div>
            <div class="border-t border-gray-300 pt-6 text-center text-xs text-gray-500">
                © 2026 MangaVault. All rights reserved.
            </div>
        </div>
    </footer>

    <script>
        // Hamburger menu
        const menuBtn = document.getElementById('menuBtn');
        const mobileMenu = document.getElementById('mobileMenu');
        const lines = menuBtn.querySelectorAll('.hamburger-line');
        let isOpen = false;

        menuBtn.addEventListener('click', function() {
            isOpen = !isOpen;
            if (isOpen) {
                mobileMenu.classList.add('open');
                lines[0].style.transform = 'translateY(8px) rotate(45deg)';
                lines[1].style.opacity = '0';
                lines[2].style.transform = 'translateY(-8px) rotate(-45deg)';
            } else {
                closeMenu();
            }
        });

        function closeMenu() {
            isOpen = false;
            mobileMenu.classList.remove('open');
            lines[0].style.transform = '';
            lines[1].style.opacity = '';
            lines[2].style.transform = '';
        }

        // Smooth page transition on link click
        document.querySelectorAll('a[href]').forEach(link => {
            const href = link.getAttribute('href');
            if (href && !href.startsWith('#') && !href.startsWith('javascript') && !href.startsWith('mailto')) {
                link.addEventListener('click', function(e) {
                    e.preventDefault();
                    document.body.style.opacity = '0';
                    document.body.style.transition = 'opacity 0.3s ease';
                    setTimeout(() => {
                        window.location.href = href;
                    }, 300);
                });
            }
        });
    </script>

</body>
</html>