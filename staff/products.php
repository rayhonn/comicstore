<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'staff') {
    header('Location: ../admin/login.php');
    exit;
}
require_once '../includes/db.php';

// Toggle availability only (no delete for staff)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_id'])) {
    $pdo->prepare("UPDATE products SET product_is_available = NOT product_is_available WHERE product_id = ?")
        ->execute([$_POST['toggle_id']]);
    header('Location: products.php?success=1');
    exit;
}

$search = trim($_GET['search'] ?? '');
$type = $_GET['type'] ?? '';
$filter = $_GET['filter'] ?? '';

$sql = "
    SELECT p.*, c.category_name,
    pp.physical_stock_quantity, pp.physical_low_stock_threshold
    FROM products p
    LEFT JOIN categories c ON p.product_category_id = c.category_id
    LEFT JOIN product_physical pp ON p.product_id = pp.physical_product_id
    WHERE 1=1
";
$params = [];
if ($search) {
    $sql .= " AND (p.product_title LIKE ? OR p.product_series LIKE ? OR p.product_author LIKE ?)";
    $params = array_fill(0, 3, "%$search%");
}
if ($type) { $sql .= " AND p.product_type = ?"; $params[] = $type; }
if ($filter === 'low_stock') { $sql .= " AND pp.physical_stock_quantity <= pp.physical_low_stock_threshold"; }
$sql .= " ORDER BY p.product_created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Products - MangaVault Staff</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { opacity: 0; animation: fadeIn 0.4s ease forwards; }
        @keyframes fadeIn { to { opacity: 1; } }
    </style>
</head>
<body class="bg-gray-100 min-h-screen">
    <?php include '../includes/staff_navbar.php'; ?>
    <div class="max-w-7xl mx-auto px-6 py-8">
        <div class="flex justify-between items-center mb-6">
            <div>
                <h1 class="text-2xl font-black text-gray-800">Products</h1>
                <p class="text-sm text-gray-400 mt-0.5"><?= count($products) ?> products found</p>
            </div>
            <a href="add_product.php" class="bg-red-600 hover:bg-red-700 text-white font-semibold px-4 py-2 rounded-xl text-sm transition-colors">
                + Add Product
            </a>
        </div>

        <?php if (isset($_GET['success'])): ?>
        <div class="bg-green-50 border border-green-200 text-green-700 text-sm px-4 py-3 rounded-xl mb-5">✅ Done.</div>
        <?php endif; ?>

        <div class="bg-white rounded-2xl shadow-sm p-4 mb-6">
            <form method="GET" class="flex gap-3 flex-wrap">
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search..."
                       class="flex-1 min-w-48 px-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:outline-none focus:border-red-400">
                <select name="type" class="px-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:outline-none focus:border-red-400">
                    <option value="">All Types</option>
                    <option value="physical" <?= $type === 'physical' ? 'selected' : '' ?>>Physical</option>
                    <option value="ebook" <?= $type === 'ebook' ? 'selected' : '' ?>>E-Book</option>
                </select>
                <select name="filter" class="px-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:outline-none focus:border-red-400">
                    <option value="">All</option>
                    <option value="low_stock" <?= $filter === 'low_stock' ? 'selected' : '' ?>>Low Stock</option>
                </select>
                <button type="submit" class="bg-[#1e2d4a] text-white px-5 py-2.5 rounded-xl text-sm font-semibold">Search</button>
                <?php if ($search || $type || $filter): ?>
                <a href="products.php" class="text-sm text-gray-400 hover:text-red-600 flex items-center">Clear</a>
                <?php endif; ?>
            </form>
        </div>

        <?php if (count($products) === 0): ?>
        <div class="bg-white rounded-2xl shadow-sm p-12 text-center">
            <div class="text-5xl mb-4">📚</div>
            <p class="text-gray-500">No products found.</p>
        </div>
        <?php else: ?>
        <div class="bg-white rounded-2xl shadow-sm overflow-hidden">
            <table class="w-full">
                <thead>
                    <tr class="bg-gray-50 border-b border-gray-100">
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Product</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Series</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Type</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Price</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Stock</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Status</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($products as $p): ?>
                    <tr class="border-t border-gray-50 hover:bg-gray-50 transition-colors <?= !$p['product_is_available'] ? 'opacity-60' : '' ?>">
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-3">
                                <?php if ($p['product_cover_image']): ?>
                                <img src="../assets/images/<?= htmlspecialchars($p['product_cover_image']) ?>" class="w-9 h-12 object-cover rounded-lg flex-shrink-0">
                                <?php else: ?>
                                <div class="w-9 h-12 bg-gray-100 rounded-lg flex-shrink-0"></div>
                                <?php endif; ?>
                                <div>
                                    <p class="font-semibold text-sm text-gray-800 truncate max-w-[160px]"><?= htmlspecialchars($p['product_title']) ?></p>
                                    <p class="text-xs text-gray-400"><?= htmlspecialchars($p['product_author'] ?? '') ?></p>
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-600">
                            <?= $p['product_series'] ? htmlspecialchars($p['product_series']) . ($p['product_volume_number'] ? ' Vol.'.$p['product_volume_number'] : '') : '—' ?>
                        </td>
                        <td class="px-4 py-3">
                            <span class="<?= $p['product_type'] === 'ebook' ? 'bg-blue-100 text-blue-700' : 'bg-green-100 text-green-700' ?> text-xs px-2 py-1 rounded-full font-semibold">
                                <?= $p['product_type'] === 'ebook' ? '📱 E-Book' : '📦 Physical' ?>
                            </span>
                        </td>
                        <td class="px-4 py-3 text-sm font-semibold text-gray-800">RM <?= number_format($p['product_price'], 2) ?></td>
                        <td class="px-4 py-3 text-sm">
                            <?php if ($p['product_type'] === 'physical'): ?>
                            <span class="font-semibold <?= $p['physical_stock_quantity'] <= 0 ? 'text-red-600' : ($p['physical_stock_quantity'] <= $p['physical_low_stock_threshold'] ? 'text-orange-500' : 'text-green-600') ?>">
                                <?= $p['physical_stock_quantity'] <= 0 ? 'Out' : $p['physical_stock_quantity'] ?>
                            </span>
                            <?php else: ?><span class="text-gray-300">—</span><?php endif; ?>
                        </td>
                        <td class="px-4 py-3">
                            <span class="<?= $p['product_is_available'] ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' ?> text-xs px-2 py-1 rounded-full font-semibold">
                                <?= $p['product_is_available'] ? 'Active' : 'Inactive' ?>
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex gap-2">
                                <a href="edit_product.php?id=<?= $p['product_id'] ?>"
                                   class="text-xs px-3 py-1.5 border border-blue-200 text-blue-600 rounded-lg hover:bg-blue-50 transition-colors">✏️ Edit</a>
                                <form method="POST" class="inline">
                                    <input type="hidden" name="toggle_id" value="<?= $p['product_id'] ?>">
                                    <button type="submit" class="text-xs px-3 py-1.5 border border-gray-200 text-gray-600 rounded-lg hover:bg-gray-50 transition-colors">
                                        <?= $p['product_is_available'] ? '🙈 Hide' : '👁️ Show' ?>
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