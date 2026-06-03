<?php
session_start();
require_once '../includes/db.php';

// Get FAQs grouped by category
$faqs_stmt = $pdo->query("SELECT * FROM faqs WHERE faq_is_active = 1 ORDER BY faq_category, faq_order ASC");
$faqs_all = $faqs_stmt->fetchAll(PDO::FETCH_ASSOC);

// Group by category
$faqs_grouped = [];
foreach ($faqs_all as $faq) {
    $faqs_grouped[$faq['faq_category']][] = $faq;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FAQ - MangaVault</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { opacity: 0; animation: fadeIn 0.4s ease forwards; }
        @keyframes fadeIn { to { opacity: 1; } }
        .faq-answer { max-height: 0; overflow: hidden; transition: max-height 0.4s cubic-bezier(0.16, 1, 0.3, 1), opacity 0.3s ease; opacity: 0; }
        .faq-answer.open { max-height: 500px; opacity: 1; }
        .faq-icon { transition: transform 0.3s ease; display: inline-block; }
        .faq-icon.open { transform: rotate(45deg); }
    </style>
</head>
<body class="bg-[#F5F0EB]">
    <?php include '../includes/customer_navbar.php'; ?>

    <!-- Hero -->
    <section class="bg-gradient-to-br from-[#1e2d4a] to-[#2c3e6b] py-20 text-white">
        <div class="max-w-7xl mx-auto px-6 text-center">
            <p class="text-blue-300 text-sm font-semibold tracking-widest uppercase mb-4">Got Questions?</p>
            <h1 class="text-5xl font-black mb-4">Frequently Asked <span class="text-red-400">Questions</span></h1>
            <p class="text-white/60 max-w-xl mx-auto">Find answers to the most common questions about MangaVault.</p>
        </div>
    </section>

    <section class="py-16">
        <div class="max-w-3xl mx-auto px-6">

            <?php if (empty($faqs_grouped)): ?>
            <div class="bg-white rounded-2xl p-12 text-center">
                <div class="text-5xl mb-4">❓</div>
                <p class="text-gray-500">No FAQs available yet.</p>
            </div>
            <?php else: ?>

            <?php foreach ($faqs_grouped as $category => $items): ?>
            <div class="mb-10">
                <h2 class="text-lg font-black text-gray-800 mb-4"><?= htmlspecialchars($category) ?></h2>
                <div class="space-y-3">
                    <?php foreach ($items as $faq): ?>
                    <?php $id = 'faq-' . $faq['faq_id']; ?>
                    <div class="bg-white rounded-2xl shadow-sm overflow-hidden">
                        <button onclick="toggleFaq('<?= $id ?>')"
                                class="w-full flex items-center justify-between p-5 text-left hover:bg-gray-50 transition-colors">
                            <span class="font-semibold text-sm text-gray-800 pr-4"><?= htmlspecialchars($faq['faq_question']) ?></span>
                            <span class="faq-icon text-gray-400 text-xl flex-shrink-0 font-light" id="icon-<?= $id ?>">+</span>
                        </button>
                        <div class="faq-answer" id="<?= $id ?>">
                            <p class="px-5 pb-5 text-sm text-gray-500 leading-relaxed"><?= nl2br(htmlspecialchars($faq['faq_answer'])) ?></p>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>

            <?php endif; ?>

            <!-- Still have questions -->
            <div class="bg-gradient-to-r from-[#1e2d4a] to-[#2c3e6b] rounded-2xl p-8 text-center text-white">
                <h3 class="font-black text-xl mb-2">Still have questions?</h3>
                <p class="text-white/60 text-sm mb-6">Can't find what you're looking for? Our support team is here to help.</p>
                <a href="mailto:mangavault@gmail.com"
                   class="bg-red-600 hover:bg-red-700 text-white font-bold px-6 py-3 rounded-xl text-sm transition-colors inline-block">
                    📧 Contact Support
                </a>
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

    <script>
    function toggleFaq(id) {
        const answer = document.getElementById(id);
        const icon = document.getElementById('icon-' + id);
        const isOpen = answer.classList.contains('open');
        document.querySelectorAll('.faq-answer').forEach(a => a.classList.remove('open'));
        document.querySelectorAll('.faq-icon').forEach(i => i.classList.remove('open'));
        if (!isOpen) {
            answer.classList.add('open');
            icon.classList.add('open');
        }
    }
    </script>
</body>
</html>