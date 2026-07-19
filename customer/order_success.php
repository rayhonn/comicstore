<?php
require_once __DIR__ . '/../includes/auth.php';
require_customer();

require_once __DIR__ . '/../includes/db.php';

$user_id = $_SESSION['user_id'];
$order_id = $_GET['order_id'] ?? null;

if (!$order_id) {
    header('Location: orders.php');
    exit;
}

// Get order details
$stmt = $pdo->prepare("
    SELECT o.*, a.address_recipient_name, a.address_taman, a.address_street, 
    a.address_city, a.address_state, a.address_postal_code, a.address_country, a.address_phone
    FROM orders o
    LEFT JOIN addresses a ON o.order_address_id = a.address_id
    WHERE o.order_id = ? AND o.order_user_id = ?
");
$stmt->execute([$order_id, $user_id]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    header('Location: orders.php');
    exit;
}

// Get order items
$stmt2 = $pdo->prepare("
    SELECT oi.*, p.product_title, p.product_cover_image,
    pe.ebook_file_path, pe.ebook_download_limit
    FROM order_items oi
    JOIN products p ON oi.order_item_product_id = p.product_id
    LEFT JOIN product_ebook pe ON p.product_id = pe.ebook_product_id
    WHERE oi.order_item_order_id = ?
");
$stmt2->execute([$order_id]);
$items = $stmt2->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Confirmed - MangaVault</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.9.2/dist/confetti.browser.min.js"></script>
    <style>
        html { scroll-behavior: smooth; }
        body { opacity: 0; animation: fadeIn 0.4s ease forwards; }
        @keyframes fadeIn { to { opacity: 1; } }

        @keyframes bounceIn {
            0% { transform: scale(0); opacity: 0; }
            60% { transform: scale(1.1); }
            100% { transform: scale(1); opacity: 1; }
        }
        .bounce-in { animation: bounceIn 0.6s ease forwards; }

        @keyframes slideUp {
            from { transform: translateY(30px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        .slide-up { animation: slideUp 0.5s ease forwards; }
        .slide-up-delay-1 { animation: slideUp 0.5s ease 0.2s forwards; opacity: 0; }
        .slide-up-delay-2 { animation: slideUp 0.5s ease 0.4s forwards; opacity: 0; }
        .slide-up-delay-3 { animation: slideUp 0.5s ease 0.6s forwards; opacity: 0; }
    </style>
</head>
<body class="bg-[#F5F0EB] min-h-screen">

    <?php include '../includes/customer_navbar.php'; ?>

    <div class="max-w-2xl mx-auto px-6 py-12">

        <!-- Success Card -->
        <div class="bg-white rounded-3xl shadow-sm p-8 text-center mb-6 slide-up">
            <!-- Checkmark -->
            <div class="w-20 h-20 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-6 bounce-in">
                <svg class="w-10 h-10 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"></path>
                </svg>
            </div>

            <p class="text-sm text-gray-400 font-medium mb-1">Order #<?= str_pad($order_id, 4, '0', STR_PAD_LEFT) ?></p>
            <h1 class="text-3xl font-black text-gray-800 mb-3">Order Confirmed!</h1>
            <p class="text-gray-500 text-sm max-w-sm mx-auto leading-relaxed">
                Thank you for your purchase! We've received your order and will process it shortly.
            </p>
        </div>

        <!-- Order Items -->
        <div class="bg-white rounded-2xl shadow-sm p-6 mb-6 slide-up-delay-1">
            <h3 class="font-bold text-gray-800 mb-4">Items Ordered</h3>
            <div class="space-y-4">
                <?php foreach ($items as $item): ?>
                <div class="flex items-center gap-4">
                    <?php if ($item['product_cover_image']): ?>
                        <img src="../assets/images/<?= htmlspecialchars($item['product_cover_image']) ?>"
                             class="w-12 h-16 object-cover rounded-lg flex-shrink-0">
                    <?php else: ?>
                        <div class="w-12 h-16 bg-gray-100 rounded-lg flex-shrink-0"></div>
                    <?php endif; ?>
                    <div class="flex-1">
                        <p class="font-semibold text-sm text-gray-800"><?= htmlspecialchars($item['product_title']) ?></p>
                        <p class="text-xs text-gray-400"><?= $item['order_item_type'] === 'ebook' ? '📱 E-Book' : '📦 Physical' ?> × <?= $item['order_item_quantity'] ?></p>
                        <p class="text-red-600 font-bold text-sm">RM <?= number_format($item['order_item_price'], 2) ?></p>
                    </div>
                    <?php if ($item['order_item_type'] === 'ebook' && $item['ebook_file_path']): ?>
                        <a href="download.php?item_id=<?= $item['order_item_id'] ?>"
                           class="bg-blue-600 hover:bg-blue-700 text-white text-xs font-semibold px-3 py-2 rounded-lg transition-colors flex-shrink-0">
                            ↓ Download
                        </a>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="border-t border-gray-100 mt-4 pt-4 flex justify-between items-center">
                <span class="font-bold text-gray-700">Total Paid</span>
                <span class="font-black text-xl text-red-600">RM <?= number_format($order['order_total_amount'], 2) ?></span>
            </div>
        </div>

        <!-- Shipping Info -->
        <?php if ($order['order_has_physical'] && $order['address_recipient_name']): ?>
        <div class="bg-white rounded-2xl shadow-sm p-6 mb-6 slide-up-delay-2">
            <h3 class="font-bold text-gray-800 mb-3 flex items-center gap-2">
                <svg class="w-4 h-4 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path></svg>
                Shipping To
            </h3>
            <p class="text-sm font-semibold text-gray-700"><?= htmlspecialchars($order['address_recipient_name']) ?></p>
            <?php if (!empty($order['address_taman'])): ?>
            <p class="text-sm text-gray-500"><?= htmlspecialchars($order['address_taman']) ?></p>
            <?php endif; ?>
            <p class="text-sm text-gray-500"><?= htmlspecialchars($order['address_street']) ?></p>
            <p class="text-sm text-gray-500"><?= htmlspecialchars($order['address_city']) ?>, <?= htmlspecialchars($order['address_state'] ?? '') ?> <?= htmlspecialchars($order['address_postal_code'] ?? '') ?></p>
            <p class="text-sm text-gray-500"><?= htmlspecialchars($order['address_country'] ?? 'Malaysia') ?></p>
            <?php if (!empty($order['address_phone'])): ?>
            <p class="text-xs text-gray-400 mt-1">📞 <?= htmlspecialchars($order['address_phone']) ?></p>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Payment Method -->
        <?php if (!empty($order['order_payment_method'])): ?>
        <div class="bg-white rounded-2xl shadow-sm p-6 mb-6 slide-up-delay-2">
            <h3 class="font-bold text-gray-800 mb-3 flex items-center gap-2">
                <span>💳</span> Payment Method
            </h3>
            <p class="text-sm text-gray-600"><?= htmlspecialchars($order['order_payment_method']) ?></p>
        </div>
        <?php endif; ?>
        
        <!-- Action Buttons -->
        <div class="flex gap-3 slide-up-delay-3 mb-3">
            <a href="invoice.php?order_id=<?= $order_id ?>"
                class="flex-1 text-center bg-[#1e2d4a] hover:bg-[#162338] text-white font-bold py-3 rounded-xl text-sm transition-colors duration-200 flex items-center justify-center gap-2">
                🧾 Download Invoice
            </a>
        </div>
        <div class="flex gap-3 slide-up-delay-3">
            <a href="orders.php"
                class="flex-1 text-center bg-red-600 hover:bg-red-700 text-white font-bold py-3 rounded-xl text-sm transition-colors duration-200">
                View My Orders →
            </a>
            <a href="home.php"
                class="flex-1 text-center bg-white hover:bg-gray-50 text-gray-700 font-bold py-3 rounded-xl text-sm transition-colors duration-200 border border-gray-200">
                Continue Shopping
            </a>
        </div>
    </div>

    <script>
    // Confetti effect!
    window.addEventListener('load', function() {
        setTimeout(function() {
            // First burst
            confetti({
                particleCount: 100,
                spread: 70,
                origin: { y: 0.6 },
                colors: ['#C0392B', '#e74c3c', '#f39c12', '#27ae60', '#2980b9', '#8e44ad']
            });

            // Second burst from left
            setTimeout(function() {
                confetti({
                    particleCount: 50,
                    angle: 60,
                    spread: 55,
                    origin: { x: 0 },
                    colors: ['#C0392B', '#F5F0EB', '#1e2d4a']
                });
            }, 300);

            // Third burst from right
            setTimeout(function() {
                confetti({
                    particleCount: 50,
                    angle: 120,
                    spread: 55,
                    origin: { x: 1 },
                    colors: ['#C0392B', '#F5F0EB', '#1e2d4a']
                });
            }, 600);
        }, 500);
    });
    </script>

</body>
</html>