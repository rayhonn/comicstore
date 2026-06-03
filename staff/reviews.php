<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'staff') {
    header('Location: ../admin/login.php');
    exit;
}
require_once '../includes/db.php';

$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $pdo->prepare("DELETE FROM product_reviews WHERE review_id = ?")->execute([$_POST['delete_id']]);
    $success = 'Review deleted.';
}

$reviews = $pdo->query("
    SELECT r.*, u.user_first_name, u.user_last_name,
    p.product_title, p.product_cover_image
    FROM product_reviews r
    JOIN users u ON r.review_user_id = u.user_id
    JOIN products p ON r.review_product_id = p.product_id
    ORDER BY r.review_created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reviews - MangaVault Staff</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { opacity: 0; animation: fadeIn 0.4s ease forwards; }
        @keyframes fadeIn { to { opacity: 1; } }
    </style>
</head>
<body class="bg-gray-100 min-h-screen">
    <?php include '../includes/staff_navbar.php'; ?>
    <div class="max-w-6xl mx-auto px-6 py-8">
        <div class="flex justify-between items-center mb-6">
            <div>
                <h1 class="text-2xl font-black text-gray-800">Customer Reviews</h1>
                <p class="text-sm text-gray-400 mt-0.5"><?= count($reviews) ?> reviews</p>
            </div>
        </div>

        <?php if ($success): ?>
        <div class="bg-green-50 border border-green-200 text-green-700 text-sm px-4 py-3 rounded-xl mb-5">✅ <?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <?php if (count($reviews) === 0): ?>
        <div class="bg-white rounded-2xl shadow-sm p-12 text-center">
            <div class="text-5xl mb-4">⭐</div>
            <p class="text-gray-500">No reviews yet.</p>
        </div>
        <?php else: ?>
        <div class="space-y-4">
            <?php foreach ($reviews as $review): ?>
            <div class="bg-white rounded-2xl shadow-sm p-5">
                <div class="flex items-start gap-4">
                    <?php if ($review['product_cover_image']): ?>
                    <img src="../assets/images/<?= htmlspecialchars($review['product_cover_image']) ?>" class="w-10 h-14 object-cover rounded-lg flex-shrink-0">
                    <?php endif; ?>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-3 mb-2 flex-wrap">
                            <p class="font-semibold text-sm text-gray-800"><?= htmlspecialchars($review['product_title']) ?></p>
                            <div class="flex gap-0.5">
                                <?php for ($s = 1; $s <= 5; $s++): ?>
                                <span class="<?= $s <= $review['review_rating'] ? 'text-yellow-400' : 'text-gray-200' ?>">★</span>
                                <?php endfor; ?>
                            </div>
                            <span class="text-xs text-gray-400"><?= htmlspecialchars($review['user_first_name'] . ' ' . $review['user_last_name']) ?></span>
                            <span class="text-xs text-gray-400"><?= date('d M Y', strtotime($review['review_created_at'])) ?></span>
                        </div>
                        <p class="text-sm text-gray-600"><?= nl2br(htmlspecialchars($review['review_comment'])) ?></p>
                    </div>
                    <form method="POST" class="flex-shrink-0">
                        <input type="hidden" name="delete_id" value="<?= $review['review_id'] ?>">
                        <button type="submit" onclick="return confirm('Delete this review?')"
                                class="text-xs px-3 py-1.5 border border-red-200 text-red-600 rounded-lg hover:bg-red-50 transition-colors">
                            🗑️ Delete
                        </button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>