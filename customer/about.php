<?php
session_start();
require_once '../includes/db.php';

// Get about sections
$sections = $pdo->query("SELECT * FROM about_sections")->fetchAll(PDO::FETCH_ASSOC);
$s = [];
foreach ($sections as $sec) {
    $s[$sec['section_key']] = $sec['section_content'];
}

// Get awards
$awards = $pdo->query("SELECT * FROM about_awards ORDER BY award_order ASC")->fetchAll(PDO::FETCH_ASSOC);

// Get team members  
$team = $pdo->query("SELECT * FROM about_team ORDER BY team_order ASC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>About Us - MangaVault</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { opacity: 0; animation: fadeIn 0.4s ease forwards; }
        @keyframes fadeIn { to { opacity: 1; } }
    </style>
</head>
<body class="bg-[#F5F0EB]">
    <?php include '../includes/customer_navbar.php'; ?>

    <!-- Hero -->
    <section class="bg-gradient-to-br from-[#1e2d4a] to-[#2c3e6b] py-20 text-white">
        <div class="max-w-7xl mx-auto px-6 text-center">
            <p class="text-blue-300 text-sm font-semibold tracking-widest uppercase mb-4">Our Story</p>
            <h1 class="text-5xl font-black mb-4">About <span class="text-red-400">MangaVault</span></h1>
            <p class="text-white/60 max-w-xl mx-auto text-lg"><?= htmlspecialchars($s['hero_subtitle'] ?? 'Malaysia\'s ultimate destination for manga lovers.') ?></p>
        </div>
    </section>

    <!-- Our Story -->
    <section class="py-16 bg-white">
        <div class="max-w-7xl mx-auto px-6">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-12 items-center">
                <div>
                    <h2 class="text-3xl font-black text-gray-900 mb-6">Who We Are</h2>
                    <div class="text-gray-600 leading-relaxed">
                        <?= nl2br(htmlspecialchars($s['our_story'] ?? '')) ?>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div class="bg-[#F5F0EB] rounded-2xl p-6 text-center">
                        <p class="text-4xl font-black text-red-600 mb-2"><?= htmlspecialchars($s['stat_titles'] ?? '5K+') ?></p>
                        <p class="text-sm text-gray-600 font-semibold">Titles Available</p>
                    </div>
                    <div class="bg-[#F5F0EB] rounded-2xl p-6 text-center">
                        <p class="text-4xl font-black text-red-600 mb-2"><?= htmlspecialchars($s['stat_customers'] ?? '50K+') ?></p>
                        <p class="text-sm text-gray-600 font-semibold">Happy Customers</p>
                    </div>
                    <div class="bg-[#F5F0EB] rounded-2xl p-6 text-center">
                        <p class="text-4xl font-black text-red-600 mb-2"><?= htmlspecialchars($s['stat_years'] ?? '4+') ?></p>
                        <p class="text-sm text-gray-600 font-semibold">Years in Business</p>
                    </div>
                    <div class="bg-[#F5F0EB] rounded-2xl p-6 text-center">
                        <p class="text-4xl font-black text-red-600 mb-2"><?= htmlspecialchars($s['stat_rating'] ?? '4.9★') ?></p>
                        <p class="text-sm text-gray-600 font-semibold">Average Rating</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Awards -->
    <?php if (!empty($awards)): ?>
    <section class="py-16 bg-[#F5F0EB]">
        <div class="max-w-7xl mx-auto px-6">
            <div class="text-center mb-12">
                <h2 class="text-3xl font-black text-gray-900 mb-3">Awards & Recognition</h2>
                <div class="border-b-2 border-red-600 w-16 mx-auto"></div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <?php foreach ($awards as $award): ?>
                <div class="bg-white rounded-2xl p-6 text-center shadow-sm hover:-translate-y-1 hover:shadow-md transition-all duration-300">
                    <div class="text-5xl mb-4"><?= htmlspecialchars($award['award_emoji']) ?></div>
                    <h3 class="font-black text-gray-800 mb-2"><?= htmlspecialchars($award['award_title']) ?></h3>
                    <p class="text-sm text-gray-500 mb-1"><?= htmlspecialchars($award['award_organization']) ?></p>
                    <p class="text-xs text-red-600 font-semibold"><?= htmlspecialchars($award['award_result']) ?></p>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- Team -->
    <?php if (!empty($team)): ?>
    <section class="py-16 bg-white">
        <div class="max-w-7xl mx-auto px-6">
            <div class="text-center mb-12">
                <h2 class="text-3xl font-black text-gray-900 mb-3">Meet Our Team</h2>
                <div class="border-b-2 border-red-600 w-16 mx-auto"></div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                <?php foreach ($team as $member): ?>
                <div class="text-center">
                    <div class="w-24 h-24 rounded-full flex items-center justify-center text-white text-2xl font-black mx-auto mb-4"
                         style="background: <?= htmlspecialchars($member['team_color']) ?>">
                        <?= htmlspecialchars($member['team_initials']) ?>
                    </div>
                    <h3 class="font-black text-gray-800"><?= htmlspecialchars($member['team_name']) ?></h3>
                    <p class="text-sm text-red-600 font-semibold"><?= htmlspecialchars($member['team_role']) ?></p>
                    <p class="text-xs text-gray-500 mt-2"><?= htmlspecialchars($member['team_bio']) ?></p>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- Mission -->
    <section class="py-16 bg-[#1e2d4a]">
        <div class="max-w-4xl mx-auto px-6 text-center">
            <h2 class="text-3xl font-black text-white mb-6">Our Mission</h2>
            <p class="text-white/70 text-lg leading-relaxed mb-8"><?= htmlspecialchars($s['mission'] ?? '') ?></p>
            <a href="home.php" class="bg-red-600 hover:bg-red-700 text-white font-bold px-8 py-4 rounded-full text-sm tracking-widest uppercase transition-all hover:scale-105 inline-block">
                Start Shopping
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
                    <h4 class="font-bold mb-4 text-sm uppercase tracking-wide">Shop</h4>
                    <ul class="space-y-2 text-sm text-gray-600">
                        <li><a href="home.php" class="hover:text-red-600 transition-colors">All Manga</a></li>
                        <li><a href="home.php?type=physical" class="hover:text-red-600 transition-colors">Physical Books</a></li>
                        <li><a href="home.php?type=ebook" class="hover:text-red-600 transition-colors">E-Books</a></li>
                    </ul>
                </div>
                <div>
                    <h4 class="font-bold mb-4 text-sm uppercase tracking-wide">Help</h4>
                    <ul class="space-y-2 text-sm text-gray-600">
                        <li><a href="orders.php" class="hover:text-red-600 transition-colors">My Orders</a></li>
                        <li><a href="faq.php" class="hover:text-red-600 transition-colors">FAQ</a></li>
                        <li><a href="about.php" class="hover:text-red-600 transition-colors">About Us</a></li>
                    </ul>
                </div>
                <div>
                    <h4 class="font-bold mb-4 text-sm uppercase tracking-wide">Follow Us</h4>
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