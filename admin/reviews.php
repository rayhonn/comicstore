<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'staff'])) {
    header('Location: login.php');
    exit;
}
require_once '../includes/db.php';

$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $review_id = $_POST['review_id'] ?? null;

    if ($action === 'approve') {
        $pdo->prepare("UPDATE product_reviews SET review_status = 'approved' WHERE review_id = ?")
            ->execute([$review_id]);
        $success = 'Review approved!';
    } elseif ($action === 'reject') {
        $pdo->prepare("UPDATE product_reviews SET review_status = 'rejected' WHERE review_id = ?")
            ->execute([$review_id]);
        $success = 'Review rejected.';
    } elseif ($action === 'delete') {
        $pdo->prepare("DELETE FROM product_reviews WHERE review_id = ?")
            ->execute([$review_id]);
        $success = 'Review deleted.';
    }
}

$reviews = $pdo->query("
    SELECT r.*, u.user_first_name, u.user_last_name, u.user_gmail,
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
    <title>Manage Reviews - MangaVault Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { opacity: 0; animation: fadeIn 0.4s ease forwards; }
        @keyframes fadeIn { to { opacity: 1; } }
    </style>
</head>
<body class="bg-gray-100 min-h-screen">

    <?php include '../includes/admin_navbar.php'; ?>

    <div class="max-w-6xl mx-auto px-6 py-8">

        <div class="flex justify-between items-center mb-6">
            <div>
                <h1 class="text-2xl font-black text-gray-800">Customer Reviews</h1>
                <p class="text-sm text-gray-400 mt-0.5">Approve or reject customer reviews</p>
            </div>
        </div>

        <?php if ($success): ?>
        <div class="bg-green-50 border border-green-200 text-green-700 text-sm px-4 py-3 rounded-xl mb-5">
            ✅ <?= htmlspecialchars($success) ?>
        </div>
        <?php endif; ?>

        <!-- Filter Tabs -->
        <div class="flex items-center justify-between mb-6">
            <p class="text-sm text-gray-500">Total <?= count($reviews) ?> review(s)</p>
        </div>

        <?php if (count($reviews) === 0): ?>
        <div class="bg-white rounded-2xl shadow-sm p-12 text-center">
            <div class="text-5xl mb-4">⭐</div>
            <p class="text-gray-500 font-medium">No <?= $filter ?> reviews</p>
        </div>
        <?php else: ?>
        <div class="space-y-4">
            <?php foreach ($reviews as $review): ?>
            <div class="bg-white rounded-2xl shadow-sm overflow-hidden">
                <div class="flex items-start gap-4 p-5">

                    <!-- Product -->
                    <div class="flex items-center gap-3 w-48 flex-shrink-0">
                        <?php if ($review['product_cover_image']): ?>
                        <img src="../assets/images/<?= htmlspecialchars($review['product_cover_image']) ?>"
                             class="w-10 h-14 object-cover rounded-lg flex-shrink-0">
                        <?php endif; ?>
                        <div class="min-w-0">
                            <p class="text-xs font-semibold text-gray-800 line-clamp-2"><?= htmlspecialchars($review['product_title']) ?></p>
                        </div>
                    </div>

                    <!-- Review Content -->
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-3 mb-2 flex-wrap">
                            <div class="flex items-center gap-2">
                                <div class="w-7 h-7 bg-red-600 rounded-full flex items-center justify-center text-white text-xs font-black">
                                    <?= strtoupper(substr($review['user_first_name'], 0, 1)) ?>
                                </div>
                                <span class="text-sm font-semibold text-gray-800">
                                    <?= htmlspecialchars($review['user_first_name'] . ' ' . $review['user_last_name']) ?>
                                </span>
                                <span class="text-xs text-gray-400"><?= htmlspecialchars($review['user_gmail']) ?></span>
                            </div>
                            <div class="flex gap-0.5">
                                <?php for ($s = 1; $s <= 5; $s++): ?>
                                <span class="<?= $s <= $review['review_rating'] ? 'text-yellow-400' : 'text-gray-200' ?>">★</span>
                                <?php endfor; ?>
                            </div>
                            <?php
                            $status_styles = [
                                'pending' => 'bg-yellow-100 text-yellow-700',
                                'approved' => 'bg-green-100 text-green-700',
                                'rejected' => 'bg-red-100 text-red-700',
                            ];
                            ?>
                            <span class="<?= $status_styles[$review['review_status']] ?> text-xs px-2 py-0.5 rounded-full font-semibold capitalize">
                                <?= $review['review_status'] ?>
                            </span>
                            <span class="text-xs text-gray-400"><?= date('d M Y, h:i A', strtotime($review['review_created_at'])) ?></span>
                        </div>
                        <p class="text-sm text-gray-600 leading-relaxed"><?= nl2br(htmlspecialchars($review['review_comment'])) ?></p>
                    </div>

                    <!-- Actions -->
                    <div class="flex flex-col gap-2 flex-shrink-0">
                        <form method="POST" class="inline">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="review_id" value="<?= $review['review_id'] ?>">
                            <button type="submit" onclick="return confirm('Delete this review?')"
                                    class="border border-red-200 text-red-600 hover:bg-red-50 text-xs font-semibold px-4 py-2 rounded-lg transition-colors">
                                🗑️ Delete
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

</body>
</html>