<?php
require_once __DIR__ . '/../includes/auth.php';
require_admin();

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';

// Toggle availability
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['toggle_id'])
) {
    csrf_verify();

    $toggle_id = filter_input(
        INPUT_POST,
        'toggle_id',
        FILTER_VALIDATE_INT
    );

    if (!$toggle_id) {
        header('Location: products.php');
        exit;
    }

    $stmt = $pdo->prepare(
        "UPDATE products
         SET product_is_available = NOT product_is_available
         WHERE product_id = ?"
    );
    $stmt->execute([$toggle_id]);

    header('Location: products.php?success=1');
    exit;
}

// Delete
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['delete_id'])
) {
    csrf_verify();

    $delete_id = filter_input(
        INPUT_POST,
        'delete_id',
        FILTER_VALIDATE_INT
    );

    if (!$delete_id) {
        header('Location: products.php');
        exit;
    }

    $stmt = $pdo->prepare(
        "DELETE FROM products
         WHERE product_id = ?"
    );
    $stmt->execute([$delete_id]);

    header('Location: products.php?success=1');
    exit;
}

$search = trim($_GET['search'] ?? '');
$type = $_GET['type'] ?? '';
$filter = $_GET['filter'] ?? '';

$sql = "
    SELECT p.*, c.category_name,
    pp.physical_stock_quantity, pp.physical_low_stock_threshold,
    pe.ebook_download_limit,
    COALESCE(AVG(r.review_rating), 0) as avg_rating,
    COUNT(DISTINCT r.review_id) as review_count
    FROM products p
    LEFT JOIN categories c ON p.product_category_id = c.category_id
    LEFT JOIN product_physical pp ON p.product_id = pp.physical_product_id
    LEFT JOIN product_ebook pe ON p.product_id = pe.ebook_product_id
    LEFT JOIN product_reviews r ON p.product_id = r.review_product_id AND r.review_status = 'approved'
    WHERE 1=1
";
$params = [];

if ($search) {
    $sql .= " AND (p.product_title LIKE ? OR p.product_series LIKE ? OR p.product_author LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($type) {
    $sql .= " AND p.product_type = ?";
    $params[] = $type;
}
if ($filter === 'low_stock') {
    $sql .= " AND pp.physical_stock_quantity <= pp.physical_low_stock_threshold";
}
if ($filter === 'inactive') {
    $sql .= " AND p.product_is_available = 0";
}

$sql .= " GROUP BY p.product_id ORDER BY p.product_created_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total_products = $pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
$total_active = $pdo->query("SELECT COUNT(*) FROM products WHERE product_is_available = 1")->fetchColumn();
$total_low_stock = $pdo->query("SELECT COUNT(*) FROM product_physical WHERE physical_stock_quantity <= physical_low_stock_threshold")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Products - MangaVault Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { opacity: 0; animation: fadeIn 0.4s ease forwards; }
        @keyframes fadeIn { to { opacity: 1; } }
    </style>
</head>
<body class="bg-gray-100 min-h-screen">

    <?php include '../includes/admin_navbar.php'; ?>

    <div class="max-w-7xl mx-auto px-6 py-8">

        <div class="flex justify-between items-center mb-6">
            <div>
                <h1 class="text-2xl font-black text-gray-800">Manage Products</h1>
                <p class="text-sm text-gray-400 mt-0.5"><?= $total_products ?> total · <?= $total_active ?> active · <?= $total_low_stock ?> low stock</p>
            </div>
            <a href="add_product.php"
               class="bg-red-600 hover:bg-red-700 text-white font-semibold px-4 py-2 rounded-xl text-sm transition-colors flex items-center gap-2">
                + Add Product
            </a>
        </div>

        <?php if (isset($_GET['success'])): ?>
        <div class="bg-green-50 border border-green-200 text-green-700 text-sm px-4 py-3 rounded-xl mb-5">✅ Action completed.</div>
        <?php endif; ?>

        <!-- Search + Filter -->
        <div class="bg-white rounded-2xl shadow-sm p-4 mb-6">
            <form method="GET" class="flex gap-3 flex-wrap items-center">
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>"
                       placeholder="Search title, series, author..."
                       class="flex-1 min-w-48 px-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:outline-none focus:border-red-400 transition-colors">
                <select name="type" class="px-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:outline-none focus:border-red-400">
                    <option value="">All Types</option>
                    <option value="physical" <?= $type === 'physical' ? 'selected' : '' ?>>Physical</option>
                    <option value="ebook" <?= $type === 'ebook' ? 'selected' : '' ?>>E-Book</option>
                </select>
                <select name="filter" class="px-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:outline-none focus:border-red-400">
                    <option value="">All Status</option>
                    <option value="low_stock" <?= $filter === 'low_stock' ? 'selected' : '' ?>>Low Stock</option>
                    <option value="inactive" <?= $filter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
                <button type="submit" class="bg-[#1e2d4a] hover:bg-[#162338] text-white px-5 py-2.5 rounded-xl text-sm font-semibold transition-colors">
                    Search
                </button>
                <?php if ($search || $type || $filter): ?>
                <a href="products.php" class="text-sm text-gray-400 hover:text-red-600 transition-colors">Clear</a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Products Table -->
        <?php if (count($products) === 0): ?>
        <div class="bg-white rounded-2xl shadow-sm p-12 text-center">
            <div class="text-5xl mb-4">📚</div>
            <p class="text-gray-500 font-medium">No products found.</p>
        </div>
        <?php else: ?>
        <div class="bg-white rounded-2xl shadow-sm overflow-hidden">
            <table class="w-full">
                <thead>
                    <tr class="bg-gray-50 border-b border-gray-100">
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Product</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Series / Vol</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Type</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Price</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Stock</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Rating</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Status</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($products as $p): ?>
                    <tr class="border-t border-gray-50 hover:bg-gray-50 transition-colors <?= !$p['product_is_available'] ? 'opacity-60' : '' ?>">
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-3">
                                <?php if ($p['product_cover_image']): ?>
                                <img src="../assets/images/<?= htmlspecialchars($p['product_cover_image']) ?>"
                                     class="w-9 h-12 object-cover rounded-lg flex-shrink-0">
                                <?php else: ?>
                                <div class="w-9 h-12 bg-gray-100 rounded-lg flex-shrink-0 flex items-center justify-center text-gray-400 text-xs font-bold">
                                    N/A
                                </div>
                                <?php endif; ?>
                                <div class="min-w-0">
                                    <p class="font-semibold text-sm text-gray-800 truncate max-w-[160px]"><?= htmlspecialchars($p['product_title']) ?></p>
                                    <p class="text-xs text-gray-400"><?= htmlspecialchars($p['product_author'] ?? '') ?></p>
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-600">
                            <?php if ($p['product_series']): ?>
                            <p class="truncate max-w-[120px]"><?= htmlspecialchars($p['product_series']) ?></p>
                            <?php if ($p['product_volume_number']): ?>
                            <p class="text-xs text-gray-400">Vol. <?= $p['product_volume_number'] ?></p>
                            <?php endif; ?>
                            <?php else: ?>
                            <span class="text-gray-300">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3">
                            <span class="<?= $p['product_type'] === 'ebook' ? 'bg-blue-100 text-blue-700' : 'bg-green-100 text-green-700' ?> text-xs px-2 py-1 rounded-full font-semibold">
                                <?= $p['product_type'] === 'ebook' ? '📱 E-Book' : '📦 Physical' ?>
                            </span>
                        </td>
                        <td class="px-4 py-3 text-sm font-semibold text-gray-800">
                            RM <?= number_format($p['product_price'], 2) ?>
                        </td>
                        <td class="px-4 py-3 text-sm">
                            <?php if ($p['product_type'] === 'physical'): ?>
                                <?php
                                $stock = $p['physical_stock_quantity'];
                                $threshold = $p['physical_low_stock_threshold'];
                                ?>
                                <span class="font-semibold <?= $stock <= 0 ? 'text-red-600' : ($stock <= $threshold ? 'text-orange-500' : 'text-green-600') ?>">
                                    <?= $stock <= 0 ? 'Out' : $stock ?>
                                </span>
                                <?php if ($stock > 0 && $stock <= $threshold): ?>
                                <span class="text-xs text-orange-400 ml-1">Low</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-gray-300">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-sm">
                            <?php if ($p['review_count'] > 0): ?>
                            <div class="flex items-center gap-1">
                                <span class="text-yellow-400">★</span>
                                <span class="font-semibold text-gray-700"><?= number_format($p['avg_rating'], 1) ?></span>
                                <span class="text-xs text-gray-400">(<?= $p['review_count'] ?>)</span>
                            </div>
                            <?php else: ?>
                            <span class="text-gray-300 text-xs">No reviews</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3">
                            <span class="<?= $p['product_is_available'] ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' ?> text-xs px-2 py-1 rounded-full font-semibold">
                                <?= $p['product_is_available'] ? 'Active' : 'Inactive' ?>
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2">
                                <a href="edit_product.php?id=<?= $p['product_id'] ?>"
                                   class="text-xs px-3 py-1.5 border border-blue-200 text-blue-600 rounded-lg hover:bg-blue-50 transition-colors">
                                    ✏️ Edit
                                </a>
                                <form method="POST" class="inline">
                                    <?php csrf_field(); ?>

                                    <input
                                        type="hidden"
                                        name="toggle_id"
                                        value="<?= (int) $p['product_id'] ?>"
                                    >
                                    <button type="submit"
                                            class="text-xs px-3 py-1.5 border border-gray-200 text-gray-600 rounded-lg hover:bg-gray-50 transition-colors">
                                        <?= $p['product_is_available'] ? '🙈 Hide' : '👁️ Show' ?>
                                    </button>
                                </form>
                                <form method="POST" class="inline">
                                    <?php csrf_field(); ?>

                                    <input
                                        type="hidden"
                                        name="delete_id"
                                        value="<?= (int) $p['product_id'] ?>"
                                    >
                                    <button type="submit" onclick="return confirm('Delete this product permanently?')"
                                            class="text-xs px-3 py-1.5 border border-red-200 text-red-600 rounded-lg hover:bg-red-50 transition-colors">
                                        🗑️
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

</body>
</html>