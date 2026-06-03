<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'customer') {
    header('Location: login.php');
    exit;
}
require_once '../includes/db.php';

$search = trim($_GET['search'] ?? '');
$category_id = $_GET['category_id'] ?? '';
$genre_id = $_GET['genre_id'] ?? '';
$type = $_GET['type'] ?? '';

$sql = "
    SELECT DISTINCT p.*, c.category_name,
    pp.physical_stock_quantity,
    pe.ebook_download_limit
    FROM products p
    LEFT JOIN categories c ON p.product_category_id = c.category_id
    LEFT JOIN product_physical pp ON p.product_id = pp.physical_product_id
    LEFT JOIN product_ebook pe ON p.product_id = pe.ebook_product_id
    LEFT JOIN product_genres pg ON p.product_id = pg.product_genres_product_id
    WHERE p.product_is_available = 1
";
$params = [];

if ($search) {
    $sql .= " AND (p.product_title LIKE ? OR p.product_series LIKE ? OR p.product_author LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($category_id) {
    $sql .= " AND p.product_category_id = ?";
    $params[] = $category_id;
}
if ($genre_id) {
    $sql .= " AND pg.product_genres_genre_id = ?";
    $params[] = $genre_id;
}
if ($type) {
    $sql .= " AND p.product_type = ?";
    $params[] = $type;
}
$sql .= " ORDER BY p.product_created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

$categories = $pdo->query("SELECT * FROM categories ORDER BY category_name")->fetchAll(PDO::FETCH_ASSOC);
$genres = $pdo->query("SELECT * FROM genres ORDER BY genre_name")->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Catalog - MangaVault</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-[#F5F0EB] min-h-screen">

    <!-- Navbar -->
    <?php include '../includes/customer_navbar.php'; ?>

    <div class="max-w-7xl mx-auto px-6 py-8">

        <!-- Search & Filter -->
        <div class="bg-white rounded-xl shadow-sm p-4 mb-6">
            <form method="GET" class="flex flex-wrap gap-3 items-center">
                <input type="text" name="search" placeholder="Search title, series, author..."
                       value="<?= htmlspecialchars($search) ?>"
                       class="flex-1 min-w-48 px-4 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-red-500">
                <select name="category_id" class="px-4 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-red-500">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat['category_id'] ?>" <?= $category_id == $cat['category_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cat['category_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <select name="genre_id" class="px-4 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-red-500">
                    <option value="">All Genres</option>
                    <?php foreach ($genres as $genre): ?>
                        <option value="<?= $genre['genre_id'] ?>" <?= $genre_id == $genre['genre_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($genre['genre_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <select name="type" class="px-4 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-red-500">
                    <option value="">All Types</option>
                    <option value="physical" <?= $type === 'physical' ? 'selected' : '' ?>>Physical</option>
                    <option value="ebook" <?= $type === 'ebook' ? 'selected' : '' ?>>E-Book</option>
                </select>
                <button type="submit" class="bg-red-600 hover:bg-red-700 text-white px-5 py-2 rounded-lg text-sm font-medium transition">Search</button>
                <?php if ($search || $category_id || $genre_id || $type): ?>
                    <a href="home.php" class="text-sm text-gray-500 hover:text-red-600">Clear</a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Results count -->
        <p class="text-sm text-gray-500 mb-4">Showing <?= count($products) ?> product<?= count($products) !== 1 ? 's' : '' ?></p>

        <!-- Product Grid -->
        <?php if (count($products) === 0): ?>
            <div class="text-center py-16">
                <div class="text-gray-300 text-6xl mb-4">📚</div>
                <p class="text-gray-500">No products found.</p>
                <a href="home.php" class="text-red-600 text-sm hover:underline mt-2 inline-block">Browse all products</a>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-5">
                <?php foreach ($products as $p): ?>
                <div class="bg-white rounded-xl shadow-sm overflow-hidden hover:-translate-y-1 hover:shadow-md transition-all duration-200">
                    <a href="product_detail.php?id=<?= $p['product_id'] ?>">
                        <?php if ($p['product_cover_image']): ?>
                            <img src="../assets/images/<?= htmlspecialchars($p['product_cover_image']) ?>"
                                 class="w-full h-48 object-cover">
                        <?php else: ?>
                            <div class="w-full h-48 bg-gray-100 flex items-center justify-center text-gray-400 text-3xl font-bold">
                                <?= strtoupper(substr($p['product_title'], 0, 2)) ?>
                            </div>
                        <?php endif; ?>
                    </a>
                    <div class="p-3">
                        <p class="text-xs text-gray-400 uppercase tracking-wide mb-1">
                            <?= $p['product_type'] === 'ebook' ? 'E-Book' : 'Physical' ?>
                        </p>
                        <h3 class="text-sm font-semibold text-gray-800 leading-tight mb-1 line-clamp-2">
                            <?= htmlspecialchars($p['product_title']) ?>
                        </h3>
                        <p class="text-xs text-gray-400 mb-2">
                            <?= htmlspecialchars($p['product_series'] ?? '') ?>
                            <?= $p['product_volume_number'] ? 'Vol.' . $p['product_volume_number'] : '' ?>
                        </p>
                        <?php if ($p['product_type'] === 'physical'): ?>
                            <p class="text-xs <?= $p['physical_stock_quantity'] <= 5 ? 'text-red-500' : 'text-green-600' ?> mb-2">
                                <?= $p['physical_stock_quantity'] <= 0 ? 'Out of Stock' : ($p['physical_stock_quantity'] <= 5 ? 'Low Stock' : 'In Stock') ?>
                            </p>
                        <?php endif; ?>
                        <p class="text-red-600 font-bold text-sm mb-3">RM <?= number_format($p['product_price'], 2) ?></p>
                        <a href="product_detail.php?id=<?= $p['product_id'] ?>"
                           class="block text-center bg-red-600 hover:bg-red-700 text-white text-xs font-medium py-2 rounded-lg transition">
                            View Details
                        </a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

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
                        <li><a href="home.php" class="hover:text-red-600 hover:translate-x-1 transition-all inline-block">All Manga</a></li>
                        <li><a href="home.php?type=physical" class="hover:text-red-600 hover:translate-x-1 transition-all inline-block">Physical Books</a></li>
                        <li><a href="home.php?type=ebook" class="hover:text-red-600 hover:translate-x-1 transition-all inline-block">E-Books</a></li>
                    </ul>
                </div>
                <div>
                    <h4 class="font-bold mb-4 text-sm uppercase tracking-wide text-gray-800">Help</h4>
                    <ul class="space-y-2 text-sm text-gray-600">
                        <li><a href="orders.php" class="hover:text-red-600 hover:translate-x-1 transition-all inline-block">My Orders</a></li>
                        <li><a href="profile.php" class="hover:text-red-600 hover:translate-x-1 transition-all inline-block">My Account</a></li>
                        <li><a href="faq.php" class="hover:text-red-600 hover:translate-x-1 transition-all inline-block">FAQ</a></li>
                        <li><a href="about.php" class="hover:text-red-600 hover:translate-x-1 transition-all inline-block">About Us</a></li>
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