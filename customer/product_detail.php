<?php

require_once __DIR__ . '/../includes/auth.php';
require_customer();

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';

$user_id = current_user_id();

$id = filter_input(
    INPUT_GET,
    'id',
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if ($id === false || $id === null) {
    header('Location: home.php');
    exit;
}

$id = (int) $id;

$stmt = $pdo->prepare("
    SELECT
        p.*,
        c.category_name,
        pp.physical_stock_quantity,
        pp.physical_low_stock_threshold,
        pe.ebook_file_path,
        pe.ebook_file_format,
        pe.ebook_file_size_mb,
        pe.ebook_download_limit
    FROM products p
    LEFT JOIN categories c
        ON p.product_category_id = c.category_id
    LEFT JOIN product_physical pp
        ON p.product_id = pp.physical_product_id
    LEFT JOIN product_ebook pe
        ON p.product_id = pe.ebook_product_id
    WHERE p.product_id = ?
    AND p.product_is_available = 1
");
$stmt->execute([$id]);
$product = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$product) {
    header('Location: home.php');
    exit;
}

$genres_stmt = $pdo->prepare("
    SELECT g.genre_name
    FROM product_genres pg
    JOIN genres g
        ON pg.product_genres_genre_id = g.genre_id
    WHERE pg.product_genres_product_id = ?
");
$genres_stmt->execute([$id]);
$genres = $genres_stmt->fetchAll(PDO::FETCH_COLUMN);

$in_wishlist = $pdo->prepare("
    SELECT wishlist_id
    FROM wishlist
    WHERE wishlist_user_id = ?
    AND wishlist_product_id = ?
    LIMIT 1
");
$in_wishlist->execute([
    $user_id,
    $id,
]);
$in_wishlist =
    $in_wishlist->fetchColumn() !== false;

$reviews = $pdo->prepare("
    SELECT
        r.*,
        u.user_first_name,
        u.user_last_name
    FROM product_reviews r
    JOIN users u
        ON r.review_user_id = u.user_id
    WHERE r.review_product_id = ?
    AND r.review_status = 'approved'
    ORDER BY r.review_created_at DESC
");
$reviews->execute([$id]);
$reviews = $reviews->fetchAll(PDO::FETCH_ASSOC);

$avg_rating = $pdo->prepare("
    SELECT
        AVG(review_rating),
        COUNT(*)
    FROM product_reviews
    WHERE review_product_id = ?
    AND review_status = 'approved'
");
$avg_rating->execute([$id]);
[$avg, $review_count] =
    $avg_rating->fetch(PDO::FETCH_NUM);
$avg = round($avg ?? 0, 1);
$review_count = (int) $review_count;

$can_review = false;
$existing_review = null;

$eligible_order = $pdo->prepare("
    SELECT o.order_id
    FROM order_items oi
    JOIN orders o
        ON oi.order_item_order_id = o.order_id
    WHERE o.order_user_id = ?
    AND oi.order_item_product_id = ?
    AND o.order_status = 'delivered'
    AND o.order_payment_status = 'confirmed'
    LIMIT 1
");
$eligible_order->execute([
    $user_id,
    $id,
]);
$eligible_order =
    $eligible_order->fetch(PDO::FETCH_ASSOC);

if ($eligible_order) {
    $existing_stmt = $pdo->prepare("
        SELECT *
        FROM product_reviews
        WHERE review_user_id = ?
        AND review_product_id = ?
        LIMIT 1
    ");
    $existing_stmt->execute([
        $user_id,
        $id,
    ]);
    $existing_review =
        $existing_stmt->fetch(PDO::FETCH_ASSOC);
    $can_review = !$existing_review;
}

$review_success = '';
$review_error = '';

if (
    isset($_SESSION['product_review_success']) &&
    is_string($_SESSION['product_review_success'])
) {
    $review_success =
        $_SESSION['product_review_success'];

    unset(
        $_SESSION['product_review_success']
    );
}

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['submit_review'])
) {
    csrf_verify();

    $rating = filter_var(
        $_POST['rating'] ?? null,
        FILTER_VALIDATE_INT,
        [
            'options' => [
                'min_range' => 1,
                'max_range' => 5,
            ],
        ]
    );
    $comment_raw = $_POST['comment'] ?? null;

    if ($rating === false || $rating === null) {
        $review_error = 'Please select a rating.';
    } elseif (!is_string($comment_raw)) {
        $review_error = 'Please write a valid comment.';
    } else {
        $comment = trim($comment_raw);
        $comment_length = function_exists('mb_strlen')
            ? mb_strlen($comment, 'UTF-8')
            : strlen($comment);

        if ($comment === '') {
            $review_error = 'Please write a comment.';
        } elseif ($comment_length > 2000) {
            $review_error =
                'Review comment cannot exceed 2000 characters.';
        } elseif (!$eligible_order) {
            $review_error =
                'You can only review products you have purchased and received.';
        } elseif ($existing_review) {
            $review_error =
                'You have already reviewed this product.';
        } else {
            $insert_review = $pdo->prepare("
                INSERT INTO product_reviews (
                    review_user_id,
                    review_product_id,
                    review_order_id,
                    review_rating,
                    review_comment,
                    review_status
                )
                VALUES (?, ?, ?, ?, ?, 'approved')
            ");
            $insert_review->execute([
                $user_id,
                $id,
                (int) $eligible_order['order_id'],
                (int) $rating,
                $comment,
            ]);

            $_SESSION['product_review_success'] =
                'Review submitted successfully!';

            header(
                'Location: product_detail.php?id=' .
                $id .
                '#customer-reviews'
            );
            exit;
        }
    }
}

$related = $pdo->prepare("
    SELECT
        p.*,
        pp.physical_stock_quantity
    FROM products p
    LEFT JOIN product_physical pp
        ON p.product_id = pp.physical_product_id
    WHERE p.product_series = ?
    AND p.product_id != ?
    AND p.product_is_available = 1
    ORDER BY p.product_volume_number ASC
    LIMIT 6
");
$related->execute([
    $product['product_series'],
    $id,
]);
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

        .product-wishlist-button {
            display: flex;
            width: 52px;
            height: 52px;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            background: #ffffff;
            color: #6b7280;
            font-size: 21px;
            cursor: pointer;
            transition:
                border-color 0.2s ease,
                background-color 0.2s ease,
                color 0.2s ease,
                transform 0.2s ease,
                opacity 0.2s ease;
        }

        .product-wishlist-button:hover {
            border-color: #fca5a5;
            color: #dc2626;
            transform: translateY(-2px);
        }

        .product-wishlist-button.is-active {
            border-color: #fca5a5;
            background: #fef2f2;
            color: #dc2626;
        }

        .product-wishlist-button.is-loading {
            opacity: 0.6;
            cursor: wait;
            transform: none;
        }

        .product-wishlist-button.is-success {
            animation:
                wishlistButtonPulse
                0.35s
                ease;
        }

        .product-actions-row {
            position: relative;
        }

        .product-wishlist-toast {
            position: absolute;
            right: 0;
            bottom: calc(100% + 12px);
            z-index: 30;
            display: flex;
            width: max-content;
            max-width: min(
                280px,
                calc(100vw - 48px)
            );
            align-items: center;
            gap: 8px;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            background: #ffffff;
            padding: 10px 12px;
            color: #374151;
            font-size: 12px;
            font-weight: 700;
            line-height: 1.4;
            box-shadow:
                0 12px 30px
                rgba(15, 23, 42, 0.14);
            opacity: 0;
            pointer-events: none;
            transform:
                translateY(6px)
                scale(0.98);
            transform-origin: right bottom;
            transition:
                opacity 0.18s ease,
                transform 0.18s ease;
        }

        .product-wishlist-toast::before {
            content: '✓';
            display: flex;
            width: 20px;
            height: 20px;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            border-radius: 50%;
            background: #dcfce7;
            color: #15803d;
            font-size: 11px;
            font-weight: 900;
        }

        .product-wishlist-toast::after {
            content: '';
            position: absolute;
            right: 20px;
            bottom: -6px;
            width: 11px;
            height: 11px;
            border-right: 1px solid #e5e7eb;
            border-bottom: 1px solid #e5e7eb;
            background: #ffffff;
            transform: rotate(45deg);
        }

        .product-wishlist-toast.is-visible {
            opacity: 1;
            transform:
                translateY(0)
                scale(1);
        }

        .product-wishlist-toast.is-error {
            border-color: #fecaca;
            color: #991b1b;
        }

        .product-wishlist-toast.is-error::before {
            content: '!';
            background: #fee2e2;
            color: #b91c1c;
        }

        .product-wishlist-toast.is-error::after {
            border-color: #fecaca;
        }

        @keyframes wishlistButtonPulse {
            50% {
                transform: scale(1.08);
            }
        }
    </style>

    <link
        rel="stylesheet"
        href="../assets/css/product_detail_refinement.css?v=2"
    >
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
        <div class="product-detail-main-card bg-white rounded-2xl shadow-sm overflow-hidden mb-8">
            <div class="product-detail-main-row flex flex-col lg:flex-row gap-0">

                <!-- Cover Image -->
                <div
                    class="product-detail-cover-column lg:w-[420px] flex-shrink-0 bg-[#F5F0EB] p-8 flex items-center justify-center"
                >
                    <div
                        class="product-detail-cover-frame relative w-full max-w-[320px] h-[420px] lg:h-[460px] bg-white rounded-xl shadow-lg overflow-hidden flex items-center justify-center"
                    >
                        <?php if ($product['product_cover_image']): ?>
                            <img
                                src="../assets/images/<?= htmlspecialchars(
                                    $product['product_cover_image'],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>"
                                alt=""
                                aria-hidden="true"
                                class="absolute inset-0 w-full h-full object-cover blur-xl scale-110 opacity-25"
                            >

                            <img
                                src="../assets/images/<?= htmlspecialchars(
                                    $product['product_cover_image'],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>"
                                alt="<?= htmlspecialchars(
                                    $product['product_title'],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?> cover"
                                class="relative z-10 w-full h-full object-contain p-3"
                            >
                        <?php else: ?>
                            <div
                                class="w-full h-full bg-gray-200 flex items-center justify-center text-gray-400 text-4xl font-black"
                            >
                                <?= htmlspecialchars(
                                    strtoupper(
                                        substr(
                                            $product['product_title'],
                                            0,
                                            2
                                        )
                                    ),
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </div>
                        <?php endif; ?>
                    </div>
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
                    <div class="product-actions-row flex gap-3 flex-wrap">
                        <?php if ($product['product_type'] === 'physical' && $product['physical_stock_quantity'] <= 0): ?>
                            <button disabled class="flex-1 bg-gray-200 text-gray-400 font-bold py-3 px-6 rounded-xl cursor-not-allowed">
                                Out of Stock
                            </button>
                        <?php else: ?>
                            <form method="POST" action="cart_action.php" class="flex gap-3 flex-1">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="action" value="add">
                                <input type="hidden" name="product_id" value="<?= (int) $id ?>">
                                <?php if ($product['product_type'] === 'physical'): ?>
                                                                        <div
                                        class="product-quantity-stepper"
                                        aria-label="Product quantity"
                                    >
                                        <button
                                            type="button"
                                            id="productQuantityDecrease"
                                            class="product-quantity-stepper-button"
                                            aria-label="Decrease quantity"
                                        >
                                            −
                                        </button>

                                        <input
                                            type="number"
                                            id="productQuantityInput"
                                            name="quantity"
                                            value="1"
                                            min="1"
                                            max="<?= $product['physical_stock_quantity'] ?>"
                                            class="product-detail-quantity product-quantity-stepper-input"
                                            aria-label="Quantity"
                                        >

                                        <button
                                            type="button"
                                            id="productQuantityIncrease"
                                            class="product-quantity-stepper-button"
                                            aria-label="Increase quantity"
                                        >
                                            +
                                        </button>
                                    </div>
                                <?php else: ?>
                                    <input type="hidden" name="quantity" value="1">
                                <?php endif; ?>
                                <button type="submit" class="flex-1 bg-red-600 hover:bg-red-700 text-white font-bold py-3 px-6 rounded-xl transition-colors">
                                    🛒 Add to Cart
                                </button>
                            </form>
                        <?php endif; ?>

                        <!-- Wishlist -->
                        <form
                            id="productWishlistForm"
                            method="POST"
                            action="wishlist_action.php"
                            data-ajax-url="wishlist_toggle_ajax.php"
                        >
                            <?php csrf_field(); ?>

                            <input
                                type="hidden"
                                name="product_id"
                                value="<?= (int) $id ?>"
                            >

                            <input
                                type="hidden"
                                id="productWishlistAction"
                                name="action"
                                value="<?= $in_wishlist
                                    ? 'remove'
                                    : 'add' ?>"
                            >

                            <input
                                type="hidden"
                                name="redirect"
                                value="product_detail.php?id=<?= (int) $id ?>"
                            >

                            <button
                                type="submit"
                                id="productWishlistButton"
                                class="product-wishlist-button <?= $in_wishlist
                                    ? 'is-active'
                                    : '' ?>"
                                aria-pressed="<?= $in_wishlist
                                    ? 'true'
                                    : 'false' ?>"
                                aria-label="<?= $in_wishlist
                                    ? 'Remove from wishlist'
                                    : 'Add to wishlist' ?>"
                            >
                                <span
                                    id="productWishlistIcon"
                                    aria-hidden="true"
                                >
                                    <?= $in_wishlist
                                        ? '♥'
                                        : '♡' ?>
                                </span>

                                <span
                                    id="productWishlistLabel"
                                    class="sr-only"
                                >
                                    <?= $in_wishlist
                                        ? 'Remove from wishlist'
                                        : 'Add to wishlist' ?>
                                </span>
                            </button>
                        </form>

                        <div
                            id="productWishlistToast"
                            class="product-wishlist-toast"
                            role="status"
                            aria-live="polite"
                        ></div>
                    </div>

                </div>
            </div>
        </div>

        <!-- Related volumes -->
        <?php if (count($related) > 0): ?>
        <div class="related-volumes-section bg-white rounded-2xl shadow-sm p-6 mb-8">
            <h3 class="font-bold text-gray-800 mb-4">More from "<?= htmlspecialchars($product['product_series']) ?>"</h3>
            <div class="related-volumes-track flex gap-4 overflow-x-auto pb-2">
                <?php foreach ($related as $r): ?>
                <a
                    href="product_detail.php?id=<?= (int) $r['product_id'] ?>"
                    class="related-volume-card group w-28 flex-shrink-0 transition-all duration-200"
                >
                    <span
                        class="related-volume-format-badge <?= $r['product_type'] === 'ebook'
                            ? 'is-ebook'
                            : 'is-physical' ?>"
                    >
                        <?= $r['product_type'] === 'ebook'
                            ? 'E-Book'
                            : 'Physical' ?>
                    </span>

                    <?php if ($r['product_cover_image']): ?>
                        <img src="../assets/images/<?= htmlspecialchars($r['product_cover_image']) ?>"
                             class="related-volume-cover w-28 h-40 object-cover rounded-xl mb-2 shadow-sm group-hover:shadow-md transition-shadow">
                    <?php else: ?>
                        <div class="related-volume-cover w-28 h-40 bg-gray-100 rounded-xl mb-2 flex items-center justify-center text-gray-400 text-xs font-bold">
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
        <div
            id="customer-reviews"
            class="bg-white rounded-2xl shadow-sm p-6 mb-8 scroll-mt-28"
        >
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
                        <textarea name="comment" rows="4" maxlength="2000" required
                                  placeholder="Share your thoughts about this product..."
                                  class="w-full px-4 py-3 border-2 border-gray-200 rounded-xl text-sm focus:outline-none focus:border-red-400 transition-colors bg-white resize-none"></textarea>
                    </div>

                    <button type="submit"
                            class="bg-red-600 hover:bg-red-700 text-white font-bold px-6 py-3 rounded-xl text-sm transition-colors">
                        Submit Review
                    </button>
                </form>
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
                <!-- <p class="text-xs text-gray-400 mt-0.5">Powered by Claude AI</p> -->
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

    <!-- Footer -->
    <footer
        class="border-t border-gray-200 bg-[#F5F0EB] py-12 text-gray-800"
    >
        <div class="mx-auto max-w-7xl px-6">
            <div
                class="mb-10 grid grid-cols-2 gap-8 md:grid-cols-4"
            >
                <div class="col-span-2 md:col-span-1">
                    <h3 class="mb-4 text-lg font-black">
                        MANGA<span class="text-red-600">VAULT</span>
                    </h3>

                    <p
                        class="text-sm leading-relaxed text-gray-600"
                    >
                        Malaysia's ultimate destination for manga
                        and comic book lovers.
                    </p>
                </div>

                <div>
                    <h4
                        class="mb-4 text-sm font-bold uppercase tracking-wide text-gray-800"
                    >
                        Shop
                    </h4>

                    <ul class="space-y-2 text-sm text-gray-600">
                        <li>
                            <a
                                href="home.php"
                                class="inline-block transition-all hover:translate-x-1 hover:text-red-600"
                            >
                                All Manga
                            </a>
                        </li>

                        <li>
                            <a
                                href="home.php?type=physical"
                                class="inline-block transition-all hover:translate-x-1 hover:text-red-600"
                            >
                                Physical Books
                            </a>
                        </li>

                        <li>
                            <a
                                href="home.php?type=ebook"
                                class="inline-block transition-all hover:translate-x-1 hover:text-red-600"
                            >
                                E-Books
                            </a>
                        </li>
                    </ul>
                </div>

                <div>
                    <h4
                        class="mb-4 text-sm font-bold uppercase tracking-wide text-gray-800"
                    >
                        Help
                    </h4>

                    <ul class="space-y-2 text-sm text-gray-600">
                        <li>
                            <a
                                href="orders.php"
                                class="inline-block transition-all hover:translate-x-1 hover:text-red-600"
                            >
                                My Orders
                            </a>
                        </li>

                        <li>
                            <a
                                href="profile.php"
                                class="inline-block transition-all hover:translate-x-1 hover:text-red-600"
                            >
                                My Account
                            </a>
                        </li>

                        <li>
                            <a
                                href="faq.php"
                                class="inline-block transition-all hover:translate-x-1 hover:text-red-600"
                            >
                                FAQ
                            </a>
                        </li>

                        <li>
                            <a
                                href="about.php"
                                class="inline-block transition-all hover:translate-x-1 hover:text-red-600"
                            >
                                About Us
                            </a>
                        </li>
                    </ul>
                </div>

                <div>
                    <h4
                        class="mb-4 text-sm font-bold uppercase tracking-wide text-gray-800"
                    >
                        Follow Us
                    </h4>

                    <div class="flex gap-3">
                        <a
                            href="#"
                            class="flex h-9 w-9 items-center justify-center rounded-full bg-gray-200 text-sm font-bold text-gray-600 transition-all hover:bg-red-600 hover:text-white"
                            aria-label="Facebook"
                        >
                            f
                        </a>

                        <a
                            href="#"
                            class="flex h-9 w-9 items-center justify-center rounded-full bg-gray-200 text-sm font-bold text-gray-600 transition-all hover:bg-red-600 hover:text-white"
                            aria-label="Twitter"
                        >
                            t
                        </a>

                        <a
                            href="#"
                            class="flex h-9 w-9 items-center justify-center rounded-full bg-gray-200 text-sm font-bold text-gray-600 transition-all hover:bg-red-600 hover:text-white"
                            aria-label="LinkedIn"
                        >
                            in
                        </a>
                    </div>
                </div>
            </div>

            <div
                class="border-t border-gray-300 pt-6 text-center text-xs text-gray-500"
            >
                © 2026 MangaVault. All rights reserved.
            </div>
        </div>
    </footer>

    <script>
    const wishlistForm =
        document.getElementById(
            'productWishlistForm'
        );
    
    const wishlistAjaxUrl =
        wishlistForm?.dataset.ajaxUrl ?? '';

    const wishlistButton =
        document.getElementById(
            'productWishlistButton'
        );

    const wishlistAction =
        document.getElementById(
            'productWishlistAction'
        );

    const wishlistIcon =
        document.getElementById(
            'productWishlistIcon'
        );

    const wishlistLabel =
        document.getElementById(
            'productWishlistLabel'
        );

    const wishlistToast =
        document.getElementById(
            'productWishlistToast'
        );

    let wishlistToastTimer = null;

    function showWishlistToast(
        message,
        isError = false
    ) {
        if (!wishlistToast) {
            return;
        }

        wishlistToast.textContent =
            message;

        wishlistToast.classList.toggle(
            'is-error',
            isError
        );

        wishlistToast.classList.add(
            'is-visible'
        );

        window.clearTimeout(
            wishlistToastTimer
        );

        wishlistToastTimer =
            window.setTimeout(
                () => {
                    wishlistToast.classList.remove(
                        'is-visible'
                    );
                },
                2200
            );
    }

    if (
        wishlistForm &&
        wishlistAjaxUrl !== '' &&
        wishlistButton &&
        wishlistAction &&
        wishlistIcon &&
        wishlistLabel
    ) {
        wishlistForm.addEventListener(
            'submit',
            async event => {
                event.preventDefault();

                if (wishlistButton.disabled) {
                    return;
                }

                const previousIcon =
                    wishlistIcon.textContent;

                wishlistButton.disabled = true;

                wishlistButton.classList.add(
                    'is-loading'
                );

                wishlistIcon.textContent = '…';

                try {
                    const response = await fetch(
                        wishlistAjaxUrl,
                        {
                            method: 'POST',
                            body: new FormData(
                                wishlistForm
                            ),
                            headers: {
                                Accept:
                                    'application/json',
                                'X-Requested-With':
                                    'XMLHttpRequest',
                            },
                            credentials:
                                'same-origin',
                        }
                    );

                    const data =
                        await response
                            .json()
                            .catch(
                                () => null
                            );

                    if (
                        !response.ok ||
                        !data ||
                        data.success !== true
                    ) {
                        throw new Error(
                            data?.message ||
                            'Unable to update your wishlist.'
                        );
                    }

                    const isActive =
                        data.in_wishlist === true;

                    wishlistAction.value =
                        data.next_action;

                    wishlistButton.classList.toggle(
                        'is-active',
                        isActive
                    );

                    wishlistButton.setAttribute(
                        'aria-pressed',
                        isActive
                            ? 'true'
                            : 'false'
                    );

                    wishlistButton.setAttribute(
                        'aria-label',
                        isActive
                            ? 'Remove from wishlist'
                            : 'Add to wishlist'
                    );

                    wishlistIcon.textContent =
                        isActive
                            ? '♥'
                            : '♡';

                    wishlistLabel.textContent =
                        isActive
                            ? 'Remove from wishlist'
                            : 'Add to wishlist';

                    wishlistButton.classList.add(
                        'is-success'
                    );

                    window.setTimeout(
                        () => {
                            wishlistButton.classList.remove(
                                'is-success'
                            );
                        },
                        350
                    );

                    showWishlistToast(
                        data.message
                    );
                } catch (error) {
                    wishlistIcon.textContent =
                        previousIcon;

                    showWishlistToast(
                        error instanceof Error
                            ? error.message
                            : 'Unable to update your wishlist.',
                        true
                    );
                } finally {
                    wishlistButton.disabled =
                        false;

                    wishlistButton.classList.remove(
                        'is-loading'
                    );
                }
            }
        );
    }

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
        body: 'type=product&product_id=<?= (int) $id ?>'
    })
    .then(r => r.json())
    .then(data => {
        const grid = document.getElementById('also-like-grid');
        if (!data.products || data.products.length === 0) {
            document.getElementById('also-like-section').style.display = 'none';
            return;
        }
        grid.innerHTML = data.products.map(p => `
            <a
                href="${productDetailUrl}?id=${p.product_id}"
                class="group bg-white border border-gray-100 rounded-xl overflow-hidden hover:shadow-md transition-all duration-200 hover:-translate-y-1 flex flex-col"
            >
                <div
                    class="relative h-72 bg-[#F5F0EB] flex items-center justify-center overflow-hidden"
                >
                    ${p.product_cover_image
                        ? `
                            <img
                                src="${imageBaseUrl}${p.product_cover_image}"
                                alt=""
                                aria-hidden="true"
                                class="absolute inset-0 w-full h-full object-cover blur-xl scale-110 opacity-25"
                            >

                            <img
                                src="${imageBaseUrl}${p.product_cover_image}"
                                alt="Recommended product cover"
                                class="relative z-10 w-full h-full object-contain p-4 group-hover:scale-105 transition-transform duration-300"
                            >
                        `
                        : `
                            <div
                                class="w-full h-full flex items-center justify-center text-gray-400 text-xs"
                            >
                                No Image
                            </div>
                        `
                    }
                </div>

                <div
                    class="p-4 border-t border-gray-100 bg-white mt-auto"
                >
                    <p
                        class="font-bold text-sm text-gray-800 truncate mb-1"
                    >
                        ${p.product_title}
                    </p>

                    <p
                        class="text-xs text-gray-400 truncate mb-2"
                    >
                        ${p.genres || ''}
                    </p>

                    <p
                        class="font-black text-red-600 text-base"
                    >
                        RM ${parseFloat(
                            p.product_price
                        ).toFixed(2)}
                    </p>
                </div>
            </a>`
        ).join('');
    })
    .catch(() => {
        document.getElementById('also-like-section').style.display = 'none';
    });
    </script>

    <script
        src="../assets/js/product_detail_quantity.js?v=1"
        defer
    ></script>
</body>
</html>