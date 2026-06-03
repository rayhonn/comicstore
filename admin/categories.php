<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'staff'])) {
    header('Location: login.php');
    exit;
}
require_once '../includes/db.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add') {
        $name = trim($_POST['category_name']);
        $desc = trim($_POST['category_description']);
        if (empty($name)) {
            $error = "Category name is required.";
        } else {
            $pdo->prepare("INSERT INTO categories (category_name, category_description) VALUES (?, ?)")
                ->execute([$name, $desc]);
            $success = "Category added successfully!";
        }
    } elseif ($_POST['action'] === 'edit') {
        $pdo->prepare("UPDATE categories SET category_name = ?, category_description = ? WHERE category_id = ?")
            ->execute([trim($_POST['category_name']), trim($_POST['category_description']), $_POST['category_id']]);
        $success = "Category updated!";
    } elseif ($_POST['action'] === 'delete') {
        $pdo->prepare("DELETE FROM categories WHERE category_id = ?")->execute([$_POST['category_id']]);
        $success = "Category deleted.";
    }
}

$categories = $pdo->query("
    SELECT c.*, COUNT(p.product_id) as product_count
    FROM categories c
    LEFT JOIN products p ON c.category_id = p.product_category_id
    GROUP BY c.category_id
    ORDER BY c.category_name ASC
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Categories - MangaVault Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { opacity: 0; animation: fadeIn 0.4s ease forwards; }
        @keyframes fadeIn { to { opacity: 1; } }
        .modal { display: none; }
        .modal.active { display: flex; }
    </style>
</head>
<body class="bg-gray-100 min-h-screen">

    <?php include '../includes/admin_navbar.php'; ?>

    <div class="max-w-4xl mx-auto px-6 py-8">

        <div class="flex justify-between items-center mb-6">
            <div>
                <h1 class="text-2xl font-black text-gray-800">Manage Categories</h1>
                <p class="text-sm text-gray-400 mt-0.5"><?= count($categories) ?> categories</p>
            </div>
            <button onclick="openAddModal()"
                    class="bg-red-600 hover:bg-red-700 text-white font-semibold px-4 py-2 rounded-xl text-sm transition-colors">
                + Add Category
            </button>
        </div>

        <?php if ($error): ?>
        <div class="bg-red-50 border border-red-200 text-red-700 text-sm px-4 py-3 rounded-xl mb-5">❌ <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
        <div class="bg-green-50 border border-green-200 text-green-700 text-sm px-4 py-3 rounded-xl mb-5">✅ <?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <div class="bg-white rounded-2xl shadow-sm overflow-hidden">
            <?php if (empty($categories)): ?>
            <div class="p-12 text-center">
                <div class="text-4xl mb-3">📂</div>
                <p class="text-gray-400">No categories yet.</p>
            </div>
            <?php else: ?>
            <table class="w-full">
                <thead>
                    <tr class="bg-gray-50 border-b border-gray-100">
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Category</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Description</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Products</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($categories as $cat): ?>
                    <tr class="border-t border-gray-50 hover:bg-gray-50 transition-colors">
                        <td class="px-5 py-4">
                            <p class="font-semibold text-sm text-gray-800"><?= htmlspecialchars($cat['category_name']) ?></p>
                        </td>
                        <td class="px-5 py-4 text-sm text-gray-500">
                            <?= htmlspecialchars($cat['category_description'] ?? '—') ?>
                        </td>
                        <td class="px-5 py-4">
                            <span class="bg-blue-100 text-blue-700 text-xs px-2 py-1 rounded-full font-semibold">
                                <?= $cat['product_count'] ?> products
                            </span>
                        </td>
                        <td class="px-5 py-4">
                            <div class="flex gap-2">
                                <button onclick="openEditModal(<?= htmlspecialchars(json_encode($cat)) ?>)"
                                        class="text-xs px-3 py-1.5 border border-blue-200 text-blue-600 rounded-lg hover:bg-blue-50 transition-colors">
                                    ✏️ Edit
                                </button>
                                <form method="POST" class="inline">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="category_id" value="<?= $cat['category_id'] ?>">
                                    <button type="submit" onclick="return confirm('Delete this category? Products will lose their category.')"
                                            class="text-xs px-3 py-1.5 border border-red-200 text-red-600 rounded-lg hover:bg-red-50 transition-colors">
                                        🗑️ Delete
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- Add/Edit Modal -->
    <div id="catModal" class="modal fixed inset-0 bg-black/50 z-50 items-center justify-center px-4">
        <div class="bg-white rounded-2xl w-full max-w-md shadow-2xl">
            <div class="p-5 border-b border-gray-100 flex justify-between items-center">
                <h3 class="font-black text-gray-800" id="modalTitle">Add Category</h3>
                <button onclick="closeModal()" class="text-gray-400 hover:text-gray-600">✕</button>
            </div>
            <form method="POST" class="p-5 space-y-4">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="category_id" id="formId">
                <div>
                    <label class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">Category Name *</label>
                    <input type="text" name="category_name" id="formName" required
                           class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 bg-gray-50 focus:bg-white transition-colors">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">Description</label>
                    <input type="text" name="category_description" id="formDesc"
                           class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 bg-gray-50 focus:bg-white transition-colors">
                </div>
                <div class="flex gap-3">
                    <button type="button" onclick="closeModal()"
                            class="flex-1 py-3 border-2 border-gray-100 rounded-xl text-sm font-semibold text-gray-600 hover:bg-gray-50">Cancel</button>
                    <button type="submit"
                            class="flex-1 py-3 bg-red-600 hover:bg-red-700 text-white rounded-xl text-sm font-semibold transition-colors">Save</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    function openAddModal() {
        document.getElementById('modalTitle').textContent = 'Add Category';
        document.getElementById('formAction').value = 'add';
        document.getElementById('formId').value = '';
        document.getElementById('formName').value = '';
        document.getElementById('formDesc').value = '';
        document.getElementById('catModal').classList.add('active');
    }
    function openEditModal(cat) {
        document.getElementById('modalTitle').textContent = 'Edit Category';
        document.getElementById('formAction').value = 'edit';
        document.getElementById('formId').value = cat.category_id;
        document.getElementById('formName').value = cat.category_name;
        document.getElementById('formDesc').value = cat.category_description || '';
        document.getElementById('catModal').classList.add('active');
    }
    function closeModal() { document.getElementById('catModal').classList.remove('active'); }
    document.getElementById('catModal').addEventListener('click', function(e) { if (e.target === this) closeModal(); });
    </script>
</body>
</html>