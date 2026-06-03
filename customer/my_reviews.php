<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'customer') {
    header('Location: login.php');
    exit;
}
require_once '../includes/db.php';

$user_id = $_SESSION['user_id'];
$success = '';
$error = '';

// Handle review submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_review'])) {
    $product_id = intval($_POST['product_id']);
    $order_id = intval($_POST['order_id']);
    $rating = intval($_POST['rating'] ?? 0);
    $comment = trim($_POST['comment'] ?? '');

    if ($rating < 1 || $rating > 5) {
        $error = 'Please select a rating.';
    } elseif (empty($comment)) {
        $error = 'Please write a comment.';
    } else {
        // Verify eligible
        $check = $pdo->prepare("
            SELECT o.order_id FROM order_items oi
            JOIN orders o ON oi.order_item_order_id = o.order_id
            WHERE o.order_user_id = ? AND oi.order_item_product_id = ? AND o.order_id = ?
            AND o.order_status = 'delivered' AND o.order_payment_status = 'confirmed'
        ");
        $check->execute([$user_id, $product_id, $order_id]);
        if ($check->fetch()) {
            // Check not already reviewed
            $existing = $pdo->prepare("SELECT review_id FROM product_reviews WHERE review_user_id = ? AND review_product_id = ? AND review_order_id = ?");
            $existing->execute([$user_id, $product_id, $order_id]);
            if ($existing->fetch()) {
                $error = 'You have already reviewed this product.';
            } else {
                $pdo->prepare("INSERT INTO product_reviews (review_user_id, review_product_id, review_order_id, review_rating, review_comment, review_status) VALUES (?, ?, ?, ?, ?, 'approved')")
                    ->execute([$user_id, $product_id, $order_id, $rating, $comment]);
                $success = 'Review submitted successfully!';
            }
        } else {
            $error = 'Invalid review request.';
        }
    }
}

// Handle delete review
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_review'])) {
    $review_id = intval($_POST['review_id']);
    $pdo->prepare("DELETE FROM product_reviews WHERE review_id = ? AND review_user_id = ?")
        ->execute([$review_id, $user_id]);
    $success = 'Review deleted.';
}

// Get all delivered order items (can review)
$pending_reviews = $pdo->prepare("
    SELECT DISTINCT oi.order_item_product_id as product_id, oi.order_item_id,
    o.order_id, o.order_created_at,
    p.product_title, p.product_cover_image, p.product_type,
    p.product_author, p.product_series, p.product_volume_number
    FROM order_items oi
    JOIN orders o ON oi.order_item_order_id = o.order_id
    JOIN products p ON oi.order_item_product_id = p.product_id
    WHERE o.order_user_id = ?
    AND o.order_status = 'delivered'
    AND o.order_payment_status = 'confirmed'
    AND NOT EXISTS (
        SELECT 1 FROM product_reviews r
        WHERE r.review_user_id = ? AND r.review_product_id = oi.order_item_product_id
        AND r.review_order_id = o.order_id
    )
    ORDER BY o.order_created_at DESC
");
$pending_reviews->execute([$user_id, $user_id]);
$pending_reviews = $pending_reviews->fetchAll(PDO::FETCH_ASSOC);

// Get my existing reviews
$my_reviews = $pdo->prepare("
    SELECT r.*, p.product_title, p.product_cover_image, p.product_series, p.product_volume_number
    FROM product_reviews r
    JOIN products p ON r.review_product_id = p.product_id
    WHERE r.review_user_id = ?
    ORDER BY r.review_created_at DESC
");
$my_reviews->execute([$user_id]);
$my_reviews = $my_reviews->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Reviews - MangaVault</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { opacity: 0; animation: fadeIn 0.4s ease forwards; }
        @keyframes fadeIn { to { opacity: 1; } }
        .modal { display: none; }
        .modal.active { display: flex; }
        .star-btn { transition: transform 0.1s ease; cursor: pointer; }
        .star-btn:hover { transform: scale(1.2); }
    </style>
</head>
<body class="bg-[#F5F0EB] min-h-screen">

    <?php include '../includes/customer_navbar.php'; ?>

    <div class="max-w-7xl mx-auto px-6 py-8">
        <p class="text-sm text-gray-400 mb-6">
            <a href="../index.php" class="hover:text-red-600 transition-colors">Home</a>
            <span class="mx-2">›</span>
            <a href="dashboard.php" class="hover:text-red-600 transition-colors">My Account</a>
            <span class="mx-2">›</span>
            <span class="text-gray-600">My Reviews</span>
        </p>

        <div class="flex gap-8 items-start">
            <?php include '../includes/customer_sidebar.php'; ?>

            <div class="flex-1 min-w-0">

                <?php if ($success): ?>
                <div class="bg-green-50 border border-green-200 text-green-700 text-sm px-4 py-3 rounded-xl mb-5">✅ <?= htmlspecialchars($success) ?></div>
                <?php endif; ?>
                <?php if ($error): ?>
                <div class="bg-red-50 border border-red-200 text-red-600 text-sm px-4 py-3 rounded-xl mb-5">❌ <?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <!-- Tabs -->
                <div class="flex gap-1 bg-white rounded-2xl shadow-sm p-1 mb-6 w-fit">
                    <button onclick="switchTab('pending')" id="tab-pending"
                            class="px-5 py-2 rounded-xl text-sm font-semibold transition-colors bg-red-600 text-white">
                        ✍️ To Review (<?= count($pending_reviews) ?>)
                    </button>
                    <button onclick="switchTab('done')" id="tab-done"
                            class="px-5 py-2 rounded-xl text-sm font-semibold transition-colors text-gray-500 hover:text-red-600">
                        ⭐ My Reviews (<?= count($my_reviews) ?>)
                    </button>
                </div>

                <!-- Pending Reviews Tab -->
                <div id="content-pending">
                    <?php if (count($pending_reviews) === 0): ?>
                    <div class="bg-white rounded-2xl shadow-sm p-12 text-center">
                        <div class="text-5xl mb-4">✅</div>
                        <p class="font-semibold text-gray-600 mb-1">All caught up!</p>
                        <p class="text-gray-400 text-sm">You've reviewed all your delivered products.</p>
                    </div>
                    <?php else: ?>
                    <div class="space-y-3">
                        <?php foreach ($pending_reviews as $item): ?>
                        <div class="bg-white rounded-2xl shadow-sm p-5 flex items-center gap-4">
                            <?php if ($item['product_cover_image']): ?>
                            <img src="../assets/images/<?= htmlspecialchars($item['product_cover_image']) ?>"
                                 class="w-12 h-16 object-cover rounded-lg flex-shrink-0">
                            <?php else: ?>
                            <div class="w-12 h-16 bg-gray-100 rounded-lg flex-shrink-0 flex items-center justify-center text-gray-400 text-xs font-bold">📖</div>
                            <?php endif; ?>
                            <div class="flex-1 min-w-0">
                                <p class="font-bold text-sm text-gray-800"><?= htmlspecialchars($item['product_title']) ?></p>
                                <?php if ($item['product_series']): ?>
                                <p class="text-xs text-gray-400"><?= htmlspecialchars($item['product_series']) ?><?= $item['product_volume_number'] ? ' Vol.' . $item['product_volume_number'] : '' ?></p>
                                <?php endif; ?>
                                <p class="text-xs text-gray-400 mt-0.5">Order #<?= str_pad($item['order_id'], 4, '0', STR_PAD_LEFT) ?> · <?= date('d M Y', strtotime($item['order_created_at'])) ?></p>
                            </div>
                            <button onclick="openReviewModal(<?= $item['product_id'] ?>, <?= $item['order_id'] ?>, '<?= htmlspecialchars(addslashes($item['product_title'])) ?>')"
                                    class="bg-red-600 hover:bg-red-700 text-white font-semibold px-4 py-2 rounded-xl text-sm transition-colors flex-shrink-0">
                                ✍️ Write Review
                            </button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- My Reviews Tab -->
                <div id="content-done" class="hidden">
                    <?php if (count($my_reviews) === 0): ?>
                    <div class="bg-white rounded-2xl shadow-sm p-12 text-center">
                        <div class="text-5xl mb-4">⭐</div>
                        <p class="font-semibold text-gray-600 mb-1">No reviews yet</p>
                        <p class="text-gray-400 text-sm">Your reviews will appear here after submission.</p>
                    </div>
                    <?php else: ?>
                    <div class="space-y-4">
                        <?php foreach ($my_reviews as $review): ?>
                        <div class="bg-white rounded-2xl shadow-sm p-5">
                            <div class="flex items-start gap-4">
                                <?php if ($review['product_cover_image']): ?>
                                <img src="../assets/images/<?= htmlspecialchars($review['product_cover_image']) ?>"
                                     class="w-12 h-16 object-cover rounded-lg flex-shrink-0">
                                <?php else: ?>
                                <div class="w-12 h-16 bg-gray-100 rounded-lg flex-shrink-0 flex items-center justify-center text-gray-400 text-xs font-bold">📖</div>
                                <?php endif; ?>
                                <div class="flex-1 min-w-0">
                                    <p class="font-bold text-sm text-gray-800 mb-1"><?= htmlspecialchars($review['product_title']) ?></p>
                                    <?php if ($review['product_series']): ?>
                                    <p class="text-xs text-gray-400 mb-2"><?= htmlspecialchars($review['product_series']) ?><?= $review['product_volume_number'] ? ' Vol.' . $review['product_volume_number'] : '' ?></p>
                                    <?php endif; ?>
                                    <div class="flex gap-0.5 mb-2">
                                        <?php for ($s = 1; $s <= 5; $s++): ?>
                                        <span class="text-lg <?= $s <= $review['review_rating'] ? 'text-yellow-400' : 'text-gray-200' ?>">★</span>
                                        <?php endfor; ?>
                                    </div>
                                    <p class="text-sm text-gray-600 leading-relaxed"><?= nl2br(htmlspecialchars($review['review_comment'])) ?></p>
                                    <p class="text-xs text-gray-400 mt-2"><?= date('d M Y, h:i A', strtotime($review['review_created_at'])) ?></p>
                                </div>
                                <form method="POST" class="flex-shrink-0">
                                    <input type="hidden" name="delete_review" value="1">
                                    <input type="hidden" name="review_id" value="<?= $review['review_id'] ?>">
                                    <button type="submit" onclick="return confirm('Delete this review?')"
                                            class="text-xs text-gray-400 hover:text-red-600 transition-colors border border-gray-200 hover:border-red-300 px-3 py-1.5 rounded-lg">
                                        🗑️ Delete
                                    </button>
                                </form>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>

            </div>
        </div>
    </div>

    <!-- Review Modal -->
    <div id="reviewModal" class="modal fixed inset-0 bg-black/50 z-50 items-center justify-center px-4">
        <div class="bg-white rounded-2xl w-full max-w-md shadow-2xl">
            <div class="p-6 border-b border-gray-100 flex justify-between items-center">
                <h3 class="font-black text-gray-800">Write a Review</h3>
                <button onclick="closeReviewModal()" class="text-gray-400 hover:text-gray-600 text-xl">✕</button>
            </div>
            <form method="POST" class="p-6 space-y-4">
                <input type="hidden" name="submit_review" value="1">
                <input type="hidden" name="product_id" id="modalProductId">
                <input type="hidden" name="order_id" id="modalOrderId">

                <p class="font-semibold text-sm text-gray-700" id="modalProductTitle"></p>

                <!-- Stars -->
                <div>
                    <label class="block text-xs font-semibold text-gray-500 mb-2 uppercase tracking-wide">Rating *</label>
                    <div class="flex gap-1" id="modalStars">
                        <?php for ($s = 1; $s <= 5; $s++): ?>
                        <button type="button" onclick="setModalRating(<?= $s ?>)"
                                class="star-btn text-3xl text-gray-300" id="modal-star-<?= $s ?>">★</button>
                        <?php endfor; ?>
                    </div>
                    <input type="hidden" name="rating" id="modalRatingInput" value="0">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-gray-500 mb-2 uppercase tracking-wide">Your Review *</label>
                    <textarea name="comment" rows="4" required
                              placeholder="Share your thoughts about this product..."
                              class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 transition-colors bg-gray-50 focus:bg-white resize-none"></textarea>
                </div>

                <div class="flex gap-3">
                    <button type="button" onclick="closeReviewModal()"
                            class="flex-1 py-3 border-2 border-gray-100 rounded-xl text-sm font-semibold text-gray-600 hover:bg-gray-50 transition-colors">
                        Cancel
                    </button>
                    <button type="submit"
                            class="flex-1 py-3 bg-red-600 hover:bg-red-700 text-white rounded-xl text-sm font-semibold transition-colors">
                        Submit Review
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
    function switchTab(tab) {
        document.getElementById('tab-pending').className = 'px-5 py-2 rounded-xl text-sm font-semibold transition-colors ' +
            (tab === 'pending' ? 'bg-red-600 text-white' : 'text-gray-500 hover:text-red-600');
        document.getElementById('tab-done').className = 'px-5 py-2 rounded-xl text-sm font-semibold transition-colors ' +
            (tab === 'done' ? 'bg-red-600 text-white' : 'text-gray-500 hover:text-red-600');
        document.getElementById('content-pending').classList.toggle('hidden', tab !== 'pending');
        document.getElementById('content-done').classList.toggle('hidden', tab !== 'done');
    }

    let modalRating = 0;

    function openReviewModal(productId, orderId, title) {
        document.getElementById('modalProductId').value = productId;
        document.getElementById('modalOrderId').value = orderId;
        document.getElementById('modalProductTitle').textContent = title;
        modalRating = 0;
        document.getElementById('modalRatingInput').value = 0;
        for (let i = 1; i <= 5; i++) {
            document.getElementById('modal-star-' + i).style.color = '#d1d5db';
        }
        document.getElementById('reviewModal').classList.add('active');
    }

    function closeReviewModal() {
        document.getElementById('reviewModal').classList.remove('active');
    }

    function setModalRating(rating) {
        modalRating = rating;
        document.getElementById('modalRatingInput').value = rating;
        for (let i = 1; i <= 5; i++) {
            document.getElementById('modal-star-' + i).style.color = i <= rating ? '#facc15' : '#d1d5db';
        }
    }

    // Hover effect for modal stars
    for (let i = 1; i <= 5; i++) {
        const star = document.getElementById('modal-star-' + i);
        if (!star) continue;
        star.addEventListener('mouseover', () => {
            for (let j = 1; j <= 5; j++) {
                document.getElementById('modal-star-' + j).style.color = j <= i ? '#facc15' : '#d1d5db';
            }
        });
        star.addEventListener('mouseout', () => {
            for (let j = 1; j <= 5; j++) {
                document.getElementById('modal-star-' + j).style.color = j <= modalRating ? '#facc15' : '#d1d5db';
            }
        });
    }

    document.getElementById('reviewModal').addEventListener('click', function(e) {
        if (e.target === this) closeReviewModal();
    });
    </script>

</body>
</html>