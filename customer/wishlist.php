<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'customer') {
    header('Location: login.php');
    exit;
}
require_once '../includes/db.php';

$user_id = $_SESSION['user_id'];

$stmt = $pdo->prepare("
    SELECT w.*, p.product_title, p.product_price, p.product_cover_image,
    p.product_type, p.product_id, p.product_author, p.product_series, p.product_volume_number,
    pp.physical_stock_quantity
    FROM wishlist w
    JOIN products p ON w.wishlist_product_id = p.product_id
    LEFT JOIN product_physical pp ON p.product_id = pp.physical_product_id
    WHERE w.wishlist_user_id = ?
    ORDER BY w.wishlist_added_at DESC
");
$stmt->execute([$user_id]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Wishlist - MangaVault</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        html { scroll-behavior: smooth; }
        body { opacity: 0; animation: fadeIn 0.4s ease forwards; }
        @keyframes fadeIn { to { opacity: 1; } }
        .line-clamp-2 { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
    </style>
</head>
<body class="bg-[#F5F0EB] min-h-screen">

    <?php include '../includes/customer_navbar.php'; ?>

    <div class="max-w-7xl mx-auto px-6 py-8">
        <p class="text-sm text-gray-400 mb-6">
            <a href="../index.php" class="hover:text-red-600 transition-colors">Home</a>
            <span class="mx-2">›</span>
            <span class="text-gray-600">My Wishlist</span>
        </p>

        <div class="flex gap-8 items-start">
            <?php include '../includes/customer_sidebar.php'; ?>

            <div class="flex-1 min-w-0">
                <div class="flex justify-between items-center mb-6">
                    <h1 class="text-xl font-black text-gray-800">
                        My Wishlist
                        <?php if (count($items) > 0): ?>
                            <span class="text-sm font-normal text-gray-400 ml-2"><?= count($items) ?> items</span>
                        <?php endif; ?>
                    </h1>
                </div>

                <?php if (count($items) === 0): ?>
                    <div class="bg-white rounded-2xl shadow-sm p-12 text-center">
                        <div class="text-6xl mb-4">♡</div>
                        <p class="text-gray-500 font-medium mb-2">Your wishlist is empty</p>
                        <p class="text-gray-400 text-sm mb-6">Save items you love to buy later.</p>
                        <a href="home.php" class="bg-red-600 hover:bg-red-700 text-white font-semibold px-6 py-2.5 rounded-xl text-sm transition-colors duration-200 inline-block">
                            Browse Catalog
                        </a>
                    </div>
                <?php else: ?>
                    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4">
                        <?php foreach ($items as $item): ?>
                        <div class="bg-white rounded-2xl shadow-sm overflow-hidden hover:-translate-y-1 hover:shadow-md transition-all duration-300 group">
                            <div class="relative overflow-hidden">
                                <a href="product_detail.php?id=<?= $item['product_id'] ?>">
                                    <?php if ($item['product_cover_image']): ?>
                                        <img src="../assets/images/<?= htmlspecialchars($item['product_cover_image']) ?>"
                                             class="w-full h-48 object-cover group-hover:scale-105 transition-transform duration-300">
                                    <?php else: ?>
                                        <div class="w-full h-48 bg-gray-100 flex items-center justify-center text-gray-400 text-2xl font-bold">
                                            <?= strtoupper(substr($item['product_title'], 0, 2)) ?>
                                        </div>
                                    <?php endif; ?>
                                </a>
                                <!-- Remove from wishlist -->
                                <form method="POST" action="wishlist_action.php" class="absolute top-2 right-2">
                                    <input type="hidden" name="product_id" value="<?= $item['product_id'] ?>">
                                    <input type="hidden" name="action" value="remove">
                                    <input type="hidden" name="redirect" value="wishlist.php">
                                    <button type="submit"
                                            class="w-8 h-8 bg-white/90 hover:bg-red-600 hover:text-white text-red-600 rounded-full flex items-center justify-center shadow-sm transition-all duration-200 text-sm">
                                        ♥
                                    </button>
                                </form>
                            </div>
                            <div class="p-3">
                                <p class="text-xs text-gray-400 uppercase tracking-wide mb-1">
                                    <?= $item['product_type'] === 'ebook' ? '📱 E-Book' : '📦 Physical' ?>
                                </p>
                                <h3 class="font-semibold text-sm text-gray-800 line-clamp-2 mb-1">
                                    <?= htmlspecialchars($item['product_title']) ?>
                                </h3>
                                <p class="text-xs text-gray-400 mb-1">
                                    <?= htmlspecialchars($item['product_series'] ?? '') ?>
                                    <?= $item['product_volume_number'] ? 'Vol.' . $item['product_volume_number'] : '' ?>
                                </p>
                                <?php if ($item['product_type'] === 'physical'): ?>
                                    <p class="text-xs <?= ($item['physical_stock_quantity'] ?? 0) <= 0 ? 'text-red-500' : (($item['physical_stock_quantity'] ?? 0) <= 5 ? 'text-orange-500' : 'text-green-600') ?> mb-2">
                                        <?= ($item['physical_stock_quantity'] ?? 0) <= 0 ? 'Out of Stock' : (($item['physical_stock_quantity'] ?? 0) <= 5 ? 'Low Stock' : 'In Stock') ?>
                                    </p>
                                <?php endif; ?>
                                <p class="text-red-600 font-bold text-sm mb-3">RM <?= number_format($item['product_price'], 2) ?></p>

                                <?php if ($item['product_type'] === 'physical' && ($item['physical_stock_quantity'] ?? 0) <= 0): ?>
                                    <button disabled class="w-full bg-gray-100 text-gray-400 text-xs font-semibold py-2 rounded-lg cursor-not-allowed">
                                        Out of Stock
                                    </button>
                                <?php else: ?>
                                    <form method="POST" action="cart_action.php">
                                        <input type="hidden" name="action" value="add">
                                        <input type="hidden" name="product_id" value="<?= $item['product_id'] ?>">
                                        <input type="hidden" name="quantity" value="1">
                                        <button type="submit"
                                                class="w-full bg-red-600 hover:bg-red-700 text-white text-xs font-semibold py-2 rounded-lg transition-colors duration-200">
                                            Add to Cart
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

</body>
</html>