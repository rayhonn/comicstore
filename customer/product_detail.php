<?php
require_once __DIR__ . '/../includes/auth.php';
require_customer();

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';

$id = $_GET['id'] ?? null;
if (!$id) { header('Location: home.php'); exit; }

$stmt = $pdo->prepare("
    SELECT p.*, c.category_name,
    pp.physical_stock_quantity, pp.physical_low_stock_threshold,
    pe.ebook_file_path, pe.ebook_file_format, pe.ebook_file_size_mb, pe.ebook_download_limit
    FROM products p
    LEFT JOIN categories c ON p.product_category_id = c.category_id
    LEFT JOIN product_physical pp ON p.product_id = pp.physical_product_id
    LEFT JOIN product_ebook pe ON p.product_id = pe.ebook_product_id
    WHERE p.product_id = ? AND p.product_is_available = 1
");
$stmt->execute([$id]);
$product = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$product) { header('Location: home.php'); exit; }

// Get genres
$genres_stmt = $pdo->prepare("
    SELECT g.genre_name FROM product_genres pg
    JOIN genres g ON pg.product_genres_genre_id = g.genre_id
    WHERE pg.product_genres_product_id = ?
");
$genres_stmt->execute([$id]);
$genres = $genres_stmt->fetchAll(PDO::FETCH_COLUMN);

// Check wishlist
$in_wishlist = $pdo->prepare("SELECT wishlist_id FROM wishlist WHERE wishlist_user_id = ? AND wishlist_product_id = ?");
$in_wishlist->execute([$_SESSION['user_id'], $id]);
$in_wishlist = $in_wishlist->rowCount() > 0;

// Get approved reviews
$reviews = $pdo->prepare("
    SELECT r.*, u.user_first_name, u.user_last_name
    FROM product_reviews r
    JOIN users u ON r.review_user_id = u.user_id
    WHERE r.review_product_id = ? AND r.review_status = 'approved'
    ORDER BY r.review_created_at DESC
");
$reviews->execute([$id]);
$reviews = $reviews->fetchAll(PDO::FETCH_ASSOC);

// Average rating
$avg_rating = $pdo->prepare("SELECT AVG(review_rating), COUNT(*) FROM product_reviews WHERE review_product_id = ? AND review_status = 'approved'");
$avg_rating->execute([$id]);
[$avg, $review_count] = $avg_rating->fetch(PDO::FETCH_NUM);
$avg = round($avg ?? 0, 1);

// Check if user can review (has delivered order with this product, and hasn't reviewed yet)
$can_review = false;
$existing_review = null;
$eligible_order = $pdo->prepare("
    SELECT o.order_id FROM order_items oi
    JOIN orders o ON oi.order_item_order_id = o.order_id
    WHERE o.order_user_id = ? AND oi.order_item_product_id = ?
    AND o.order_status = 'delivered' AND o.order_payment_status = 'confirmed'
    LIMIT 1
");
$eligible_order->execute([$_SESSION['user_id'], $id]);
$eligible_order = $eligible_order->fetch(PDO::FETCH_ASSOC);

if ($eligible_order) {
    $existing_review = $pdo->prepare("SELECT * FROM product_reviews WHERE review_user_id = ? AND review_product_id = ?");
    $existing_review->execute([$_SESSION['user_id'], $id]);
    $existing_review = $existing_review->fetch(PDO::FETCH_ASSOC);
    $can_review = !$existing_review;
}

// Handle review submission
$review_success = '';
$review_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_review'])) {
    csrf_verify();
    $rating = intval($_POST['rating'] ?? 0);
    $comment = trim($_POST['comment'] ?? '');

    if ($rating < 1 || $rating > 5) {
        $review_error = 'Please select a rating.';
    } elseif (empty($comment)) {
        $review_error = 'Please write a comment.';
    } elseif (!$eligible_order) {
        $review_error = 'You can only review products you have purchased and received.';
    } elseif ($existing_review) {
        $review_error = 'You have already reviewed this product.';
    } else {
        $pdo->prepare("INSERT INTO product_reviews (review_user_id, review_product_id, review_order_id, review_rating, review_comment, review_status) VALUES (?, ?, ?, ?, ?, 'approved')")
            ->execute([$_SESSION['user_id'], $id, $eligible_order['order_id'], $rating, $comment]);
        $review_success = 'Review submitted successfully!';
        $can_review = false;
        $existing_review = ['review_status' => 'pending', 'review_rating' => $rating, 'review_comment' => $comment];
    }
}

// Related products
$related = $pdo->prepare("
    SELECT p.*, pp.physical_stock_quantity
    FROM products p
    LEFT JOIN product_physical pp ON p.product_id = pp.physical_product_id
    WHERE p.product_series = ? AND p.product_id != ? AND p.product_is_available = 1
    ORDER BY p.product_volume_number ASC
    LIMIT 6
");
$related->execute([$product['product_series'], $id]);
$related = $related->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($product['product_title']) ?> - MangaVault</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { opacity: 0; animation: fadeIn 0.4s ease forwards; }
        @keyframes fadeIn { to { opacity: 1; } }
        .star-btn { transition: transform 0.1s ease; cursor: pointer; }
        .star-btn:hover { transform: scale(1.2); }
    </style>
</head>
<body class="bg-[#F5F0EB] min-h-screen">

    <?php include '../includes/customer_navbar.php'; ?>

    <div class="max-w-7xl mx-auto px-6 py-8">

        <!-- Breadcrumb -->
        <p class="text-sm text-gray-400 mb-6">
            <a href="../index.php" class="hover:text-red-600 transition-colors">Home</a>
            <span class="mx-2">›</span>
            <a href="home.php" class="hover:text-red-600 transition-colors">Catalog</a>
            <span class="mx-2">›</span>
            <span class="text-gray-600"><?= htmlspecialchars($product['product_title']) ?></span>
        </p>

        <!-- Product Detail -->
        <div class="bg-white rounded-2xl shadow-sm overflow-hidden mb-8">
            <div class="flex flex-col lg:flex-row gap-0">

                <!-- Cover Image -->
                <div class="lg:w-80 flex-shrink-0 bg-[#F5F0EB] p-8 flex items-center justify-center">
                    <?php if ($product['product_cover_image']): ?>
                        <img src="../assets/images/<?= htmlspecialchars($product['product_cover_image']) ?>"
                             class="w-52 rounded-xl shadow-lg">
                    <?php else: ?>
                        <div class="w-52 h-72 bg-gray-200 rounded-xl flex items-center justify-center text-gray-400 text-4xl font-black">
                            <?= strtoupper(substr($product['product_title'], 0, 2)) ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Info -->
                <div class="flex-1 p-8">

                    <!-- Badges -->
                    <div class="flex items-center gap-2 mb-3 flex-wrap">
                        <span class="<?= $product['product_type'] === 'ebook' ? 'bg-blue-100 text-blue-700' : 'bg-green-100 text-green-700' ?> text-xs px-3 py-1 rounded-full font-semibold">
                            <?= $product['product_type'] === 'ebook' ? '📱 E-Book' : '📦 Physical' ?>
                        </span>
                        <?php if ($product['category_name']): ?>
                        <span class="bg-gray-100 text-gray-600 text-xs px-3 py-1 rounded-full font-semibold">
                            <?= htmlspecialchars($product['category_name']) ?>
                        </span>
                        <?php endif; ?>
                        <?php foreach ($genres as $genre): ?>
                        <span class="bg-red-50 text-red-600 text-xs px-3 py-1 rounded-full font-semibold">
                            <?= htmlspecialchars($genre) ?>
                        </span>
                        <?php endforeach; ?>
                    </div>

                    <h1 class="text-2xl font-black text-gray-900 mb-1"><?= htmlspecialchars($product['product_title']) ?></h1>

                    <?php if ($product['product_series']): ?>
                    <p class="text-sm text-gray-500 mb-1">
                        <?= htmlspecialchars($product['product_series']) ?>
                        <?= $product['product_volume_number'] ? ' · Vol.' . $product['product_volume_number'] : '' ?>
                    </p>
                    <?php endif; ?>

                    <?php if ($product['product_author']): ?>
                    <p class="text-sm text-gray-400 mb-3">by <?= htmlspecialchars($product['product_author']) ?></p>
                    <?php endif; ?>

                    <!-- Rating -->
                    <?php if ($review_count > 0): ?>
                    <div class="flex items-center gap-2 mb-4">
                        <div class="flex gap-0.5">
                            <?php for ($s = 1; $s <= 5; $s++): ?>
                            <span class="text-lg <?= $s <= round($avg) ? 'text-yellow-400' : 'text-gray-200' ?>">★</span>
                            <?php endfor; ?>
                        </div>
                        <span class="text-sm font-bold text-gray-700"><?= $avg ?></span>
                        <span class="text-xs text-gray-400">(<?= $review_count ?> review<?= $review_count > 1 ? 's' : '' ?>)</span>
                    </div>
                    <?php endif; ?>

                    <!-- Price -->
                    <div class="mb-5">
                        <p class="text-3xl font-black text-red-600">RM <?= number_format($product['product_price'], 2) ?></p>
                        <?php if ($product['product_type'] === 'physical'): ?>
                            <?php if ($product['physical_stock_quantity'] <= 0): ?>
                                <p class="text-sm text-red-500 font-semibold mt-1">Out of Stock</p>
                            <?php elseif ($product['physical_stock_quantity'] <= 5): ?>
                                <p class="text-sm text-orange-500 font-semibold mt-1">⚠️ Only <?= $product['physical_stock_quantity'] ?> left!</p>
                            <?php else: ?>
                                <p class="text-sm text-green-600 font-semibold mt-1">✓ In Stock</p>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>

                    <!-- Product Details -->
                    <div class="grid grid-cols-2 gap-x-6 gap-y-2 mb-6 text-sm">
                        <?php if ($product['product_publisher']): ?>
                        <div><span class="text-gray-400">Publisher</span><br><span class="font-medium text-gray-700"><?= htmlspecialchars($product['product_publisher']) ?></span></div>
                        <?php endif; ?>
                        <?php if ($product['product_isbn']): ?>
                        <div><span class="text-gray-400">ISBN</span><br><span class="font-medium text-gray-700"><?= htmlspecialchars($product['product_isbn']) ?></span></div>
                        <?php endif; ?>
                        <?php if ($product['product_type'] === 'ebook'): ?>
                        <div><span class="text-gray-400">Format</span><br><span class="font-medium text-gray-700"><?= htmlspecialchars($product['ebook_file_format']) ?></span></div>
                        <div><span class="text-gray-400">File Size</span><br><span class="font-medium text-gray-700"><?= $product['ebook_file_size_mb'] ?> MB</span></div>
                        <div><span class="text-gray-400">Download Limit</span><br><span class="font-medium text-gray-700"><?= $product['ebook_download_limit'] ?> downloads</span></div>
                        <?php endif; ?>
                    </div>

                    <!-- Description -->
                    <?php if ($product['product_description']): ?>
                    <div class="bg-gray-50 rounded-xl p-4 mb-6">
                        <p class="text-sm text-gray-600 leading-relaxed"><?= nl2br(htmlspecialchars($product['product_description'])) ?></p>
                    </div>
                    <?php endif; ?>

                    <!-- Actions -->
                    <div class="flex gap-3 flex-wrap">
                        <?php if ($product['product_type'] === 'physical' && $product['physical_stock_quantity'] <= 0): ?>
                            <button disabled class="flex-1 bg-gray-200 text-gray-400 font-bold py-3 px-6 rounded-xl cursor-not-allowed">
                                Out of Stock
                            </button>
                        <?php else: ?>
                            <form method="POST" action="cart_action.php" class="flex gap-3 flex-1">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="action" value="add">
                                <input type="hidden" name="product_id" value="<?= $id ?>">
                                <?php if ($product['product_type'] === 'physical'): ?>
                                    <input type="number" name="quantity" value="1" min="1"
                                           max="<?= $product['physical_stock_quantity'] ?>"
                                           class="w-20 px-3 py-3 border-2 border-gray-100 rounded-xl text-sm text-center focus:outline-none focus:border-red-400 transition-colors bg-gray-50">
                                <?php else: ?>
                                    <input type="hidden" name="quantity" value="1">
                                <?php endif; ?>
                                <button type="submit" class="flex-1 bg-red-600 hover:bg-red-700 text-white font-bold py-3 px-6 rounded-xl transition-colors">
                                    🛒 Add to Cart
                                </button>
                            </form>
                        <?php endif; ?>

                        <!-- Wishlist -->
                        <form method="POST" action="wishlist_action.php">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="product_id" value="<?= $id ?>">
                                <input type="hidden" name="action" value="<?= $in_wishlist ? 'remove' : 'add' ?>">
                                <input type="hidden" name="redirect" value="product_detail.php?id=<?= $id ?>">
                            <button type="submit"
                                    class="py-3 px-4 rounded-xl border-2 transition-colors <?= $in_wishlist ? 'border-red-300 bg-red-50 text-red-600' : 'border-gray-200 text-gray-500 hover:border-red-300 hover:text-red-600' ?>">
                                <?= $in_wishlist ? '♥' : '♡' ?>
                            </button>
                        </form>
                    </div>

                </div>
            </div>
        </div>

        <!-- Related volumes -->
        <?php if (count($related) > 0): ?>
        <div class="bg-white rounded-2xl shadow-sm p-6 mb-8">
            <h3 class="font-bold text-gray-800 mb-4">More from "<?= htmlspecialchars($product['product_series']) ?>"</h3>
            <div class="flex gap-4 overflow-x-auto pb-2">
                <?php foreach ($related as $r): ?>
                <a href="product_detail.php?id=<?= $r['product_id'] ?>"
                   class="flex-shrink-0 w-28 hover:-translate-y-1 transition-all duration-200 group">
                    <?php if ($r['product_cover_image']): ?>
                        <img src="../assets/images/<?= htmlspecialchars($r['product_cover_image']) ?>"
                             class="w-28 h-40 object-cover rounded-xl mb-2 shadow-sm group-hover:shadow-md transition-shadow">
                    <?php else: ?>
                        <div class="w-28 h-40 bg-gray-100 rounded-xl mb-2 flex items-center justify-center text-gray-400 text-xs font-bold">
                            Vol.<?= $r['product_volume_number'] ?>
                        </div>
                    <?php endif; ?>
                    <p class="text-xs font-semibold text-gray-700 truncate">Vol.<?= $r['product_volume_number'] ?></p>
                    <p class="text-xs text-red-600 font-bold">RM <?= number_format($r['product_price'], 2) ?></p>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Reviews Section -->
        <div class="bg-white rounded-2xl shadow-sm p-6 mb-8">
            <div class="flex items-center justify-between mb-6">
                <div>
                    <h3 class="font-bold text-gray-800 text-lg">Customer Reviews</h3>
                    <?php if ($review_count > 0): ?>
                    <div class="flex items-center gap-2 mt-1">
                        <div class="flex gap-0.5">
                            <?php for ($s = 1; $s <= 5; $s++): ?>
                            <span class="text-xl <?= $s <= round($avg) ? 'text-yellow-400' : 'text-gray-200' ?>">★</span>
                            <?php endfor; ?>
                        </div>
                        <span class="font-black text-gray-800"><?= $avg ?></span>
                        <span class="text-sm text-gray-400">out of 5 · <?= $review_count ?> review<?= $review_count > 1 ? 's' : '' ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Review Form -->
            <?php if ($review_success): ?>
            <div class="bg-green-50 border border-green-200 text-green-700 text-sm px-4 py-3 rounded-xl mb-6">
                ✅ <?= htmlspecialchars($review_success) ?>
            </div>
            <?php endif; ?>

            <?php if ($review_error): ?>
            <div class="bg-red-50 border border-red-200 text-red-600 text-sm px-4 py-3 rounded-xl mb-6">
                ❌ <?= htmlspecialchars($review_error) ?>
            </div>
            <?php endif; ?>

            <?php if ($can_review): ?>
            <div class="bg-[#F5F0EB] rounded-2xl p-6 mb-6">
                <h4 class="font-bold text-gray-800 mb-4">Write a Review</h4>
                <form method="POST">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="submit_review" value="1">

                    <!-- Star Rating -->
                    <div class="mb-4">
                        <label class="block text-xs font-semibold text-gray-500 mb-2 uppercase tracking-wide">Your Rating *</label>
                        <div class="flex gap-1" id="starRating">
                            <?php for ($s = 1; $s <= 5; $s++): ?>
                            <button type="button" onclick="setRating(<?= $s ?>)"
                                    class="star-btn text-3xl text-gray-300 hover:text-yellow-400"
                                    id="star-<?= $s ?>">★</button>
                            <?php endfor; ?>
                        </div>
                        <input type="hidden" name="rating" id="ratingInput" value="0">
                    </div>

                    <div class="mb-4">
                        <label class="block text-xs font-semibold text-gray-500 mb-2 uppercase tracking-wide">Your Review *</label>
                        <textarea name="comment" rows="4" required
                                  placeholder="Share your thoughts about this product..."
                                  class="w-full px-4 py-3 border-2 border-gray-200 rounded-xl text-sm focus:outline-none focus:border-red-400 transition-colors bg-white resize-none"></textarea>
                    </div>

                    <button type="submit"
                            class="bg-red-600 hover:bg-red-700 text-white font-bold px-6 py-3 rounded-xl text-sm transition-colors">
                        Submit Review
                    </button>
                </form>
            </div>
            <?php elseif ($existing_review): ?>
            <div class="bg-blue-50 border border-blue-100 rounded-xl p-4 mb-6">
                <p class="text-sm font-semibold text-blue-700 mb-1">
                    <?= $existing_review['review_status'] === 'pending' ? '⏳ Your review is pending approval.' : '✅ Your review has been published.' ?>
                </p>
                <div class="flex gap-0.5 mb-1">
                    <?php for ($s = 1; $s <= 5; $s++): ?>
                    <span class="<?= $s <= $existing_review['review_rating'] ? 'text-yellow-400' : 'text-gray-300' ?>">★</span>
                    <?php endfor; ?>
                </div>
                <p class="text-sm text-gray-600"><?= htmlspecialchars($existing_review['review_comment']) ?></p>
            </div>
            <?php endif; ?>

            <!-- Reviews List -->
            <?php if (count($reviews) === 0): ?>
            <div class="text-center py-8">
                <div class="text-4xl mb-3">💬</div>
                <p class="text-gray-400 text-sm">No reviews yet. Be the first to review!</p>
            </div>
            <?php else: ?>
            <div class="space-y-4">
                <?php foreach ($reviews as $review): ?>
                <div class="border-b border-gray-50 pb-4 last:border-0">
                    <div class="flex items-start justify-between mb-2">
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 bg-red-600 rounded-full flex items-center justify-center text-white text-sm font-black">
                                <?= strtoupper(substr($review['user_first_name'], 0, 1)) ?>
                            </div>
                            <div>
                                <p class="font-semibold text-sm text-gray-800"><?= htmlspecialchars($review['user_first_name'] . ' ' . $review['user_last_name']) ?></p>
                                <div class="flex gap-0.5">
                                    <?php for ($s = 1; $s <= 5; $s++): ?>
                                    <span class="text-sm <?= $s <= $review['review_rating'] ? 'text-yellow-400' : 'text-gray-200' ?>">★</span>
                                    <?php endfor; ?>
                                </div>
                            </div>
                        </div>
                        <p class="text-xs text-gray-400"><?= date('d M Y', strtotime($review['review_created_at'])) ?></p>
                    </div>
                    <p class="text-sm text-gray-600 leading-relaxed ml-12"><?= nl2br(htmlspecialchars($review['review_comment'])) ?></p>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- AI You Might Also Like -->
        <div class="bg-white rounded-2xl shadow-sm p-6 mb-8" id="also-like-section">
            <div class="text-center mb-5">
                <h3 class="font-bold text-gray-800 text-lg">✨ You Might Also Like</h3>
                <p class="text-xs text-gray-400 mt-0.5">Powered by Claude AI</p>
            </div>
            <div id="also-like-grid" class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <?php for ($i = 0; $i < 3; $i++): ?>
                <div class="animate-pulse">
                    <div class="bg-gray-200 rounded-xl h-48 mb-2"></div>
                    <div class="bg-gray-200 rounded h-3 mb-1"></div>
                    <div class="bg-gray-200 rounded h-3 w-2/3"></div>
                </div>
                <?php endfor; ?>
            </div>
        </div>

    </div>

    <script>
    let currentRating = 0;

    function setRating(rating) {
        currentRating = rating;
        document.getElementById('ratingInput').value = rating;
        for (let i = 1; i <= 5; i++) {
            const star = document.getElementById('star-' + i);
            star.className = 'star-btn text-3xl ' + (i <= rating ? 'text-yellow-400' : 'text-gray-300 hover:text-yellow-400');
        }
    }

    // Hover effect
    for (let i = 1; i <= 5; i++) {
        const star = document.getElementById('star-' + i);
        if (!star) continue;
        star.addEventListener('mouseover', function() {
            for (let j = 1; j <= 5; j++) {
                document.getElementById('star-' + j).style.color = j <= i ? '#facc15' : '#d1d5db';
            }
        });
        star.addEventListener('mouseout', function() {
            for (let j = 1; j <= 5; j++) {
                document.getElementById('star-' + j).style.color = j <= currentRating ? '#facc15' : '#d1d5db';
            }
        });
    }

    // Load AI "You Might Also Like"
    const recommendationUrl = <?= json_encode(app_path('customer/get_recommendations.php')) ?>;
    const productDetailUrl = <?= json_encode(app_path('customer/product_detail.php')) ?>;
    const imageBaseUrl = <?= json_encode(app_path('assets/images/')) ?>;

    fetch(recommendationUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'type=product&product_id=<?= $id ?>'
    })
    .then(r => r.json())
    .then(data => {
        const grid = document.getElementById('also-like-grid');
        if (!data.products || data.products.length === 0) {
            document.getElementById('also-like-section').style.display = 'none';
            return;
        }
        grid.innerHTML = data.products.map(p => `
            <a href="${productDetailUrl}?id=${p.product_id}"
                class="group bg-gray-50 rounded-xl overflow-hidden hover:shadow-md transition-all duration-200 hover:-translate-y-1 flex flex-col">
                <div class="relative" style="height:180px; overflow:hidden;">
                    ${p.product_cover_image
                        ? `<img src="${imageBaseUrl}${p.product_cover_image}"
                                style="width:100%; height:100%; object-fit:cover;" class="group-hover:scale-105 transition-transform duration-300">`
                        : `<div style="width:100%; height:100%; background:#f3f4f6; display:flex; align-items:center; justify-content:center; color:#9ca3af; font-size:12px;">No Image</div>`
                    }
                </div>
                <div class="p-3">
                    <p class="font-bold text-xs text-gray-800 truncate mb-1">${p.product_title}</p>
                    <p class="text-xs text-gray-400 truncate mb-1">${p.genres || ''}</p>
                    <p class="font-black text-red-600 text-sm">RM ${parseFloat(p.product_price).toFixed(2)}</p>
                </div>
            </a>`
        ).join('');
    })
    .catch(() => {
        document.getElementById('also-like-section').style.display = 'none';
    });
    </script>
</body>
</html>