<?php

require_once __DIR__ . '/../includes/auth.php';
require_customer();

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';

$user_id = current_user_id();
$success = '';
$error = '';
$active_tab =
    ($_GET['tab'] ?? '') === 'done'
        ? 'done'
        : 'pending';

function requireReviewId(
    mixed $value,
    string $label
): int {
    $id = filter_var(
        $value,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    if ($id === false || $id === null) {
        throw new RuntimeException(
            'Invalid ' . $label . '.'
        );
    }

    return (int) $id;
}

function normalizeReviewComment(mixed $value): string
{
    if (!is_string($value)) {
        throw new RuntimeException(
            'Please write a valid comment.'
        );
    }

    $comment = trim($value);
    $length = function_exists('mb_strlen')
        ? mb_strlen($comment, 'UTF-8')
        : strlen($comment);

    if ($comment === '') {
        throw new RuntimeException(
            'Please write a comment.'
        );
    }

    if ($length > 2000) {
        throw new RuntimeException(
            'Review comment cannot exceed 2000 characters.'
        );
    }

    return $comment;
}

if (
    isset($_SESSION['my_reviews_success']) &&
    is_string($_SESSION['my_reviews_success'])
) {
    $success = $_SESSION['my_reviews_success'];
    unset($_SESSION['my_reviews_success']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    try {
        if (isset($_POST['submit_review'])) {
            $product_id = requireReviewId(
                $_POST['product_id'] ?? null,
                'product'
            );
            $order_id = requireReviewId(
                $_POST['order_id'] ?? null,
                'order'
            );
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

            if ($rating === false || $rating === null) {
                throw new RuntimeException(
                    'Please select a rating.'
                );
            }

            $comment = normalizeReviewComment(
                $_POST['comment'] ?? null
            );

            $pdo->beginTransaction();

            /*
             * Lock the customer row so concurrent submissions from
             * Product Detail and My Reviews cannot create duplicates.
             */
            $lock_user_stmt = $pdo->prepare("
                SELECT user_id
                FROM users
                WHERE user_id = ?
                FOR UPDATE
            ");
            $lock_user_stmt->execute([$user_id]);

            if (!$lock_user_stmt->fetchColumn()) {
                throw new RuntimeException(
                    'Customer account was not found.'
                );
            }

            $existing_review_stmt = $pdo->prepare("
                SELECT review_id
                FROM product_reviews
                WHERE review_user_id = ?
                AND review_product_id = ?
                LIMIT 1
            ");
            $existing_review_stmt->execute([
                $user_id,
                $product_id,
            ]);

            if ($existing_review_stmt->fetchColumn()) {
                throw new RuntimeException(
                    'You have already reviewed this product.'
                );
            }

            $eligible_order_stmt = $pdo->prepare("
                SELECT
                    o.order_id,
                    oi.order_item_type
                FROM order_items oi
                JOIN orders o
                    ON oi.order_item_order_id = o.order_id
                WHERE o.order_user_id = ?
                AND oi.order_item_product_id = ?
                AND o.order_id = ?
                AND o.order_payment_status = 'confirmed'
                AND o.order_status != 'cancelled'
                AND (
                    (
                        oi.order_item_type = 'physical'
                        AND o.order_status = 'delivered'
                    )
                    OR
                    (
                        oi.order_item_type = 'ebook'
                        AND EXISTS (
                            SELECT 1
                            FROM user_collection uc
                            WHERE uc.collection_user_id = ?
                            AND uc.collection_product_id = ?
                        )
                    )
                )
                LIMIT 1
            ");
            $eligible_order_stmt->execute([
                $user_id,
                $product_id,
                $order_id,
                $user_id,
                $product_id,
            ]);
            $eligible_order =
                $eligible_order_stmt->fetch(PDO::FETCH_ASSOC);

            if (!$eligible_order) {
                throw new RuntimeException(
                    'This product is not currently eligible for review.'
                );
            }

            $insert_review_stmt = $pdo->prepare("
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
            $insert_review_stmt->execute([
                $user_id,
                $product_id,
                (int) $eligible_order['order_id'],
                (int) $rating,
                $comment,
            ]);

            $pdo->commit();

            $_SESSION['my_reviews_success'] =
                'Review submitted successfully!';

            header('Location: my_reviews.php?tab=done');
            exit;
        }

        if (isset($_POST['delete_review'])) {
            $review_id = requireReviewId(
                $_POST['review_id'] ?? null,
                'review'
            );

            $delete_review_stmt = $pdo->prepare("
                DELETE FROM product_reviews
                WHERE review_id = ?
                AND review_user_id = ?
            ");
            $delete_review_stmt->execute([
                $review_id,
                $user_id,
            ]);

            if ($delete_review_stmt->rowCount() !== 1) {
                throw new RuntimeException(
                    'Review not found.'
                );
            }

            $_SESSION['my_reviews_success'] =
                'Review deleted.';

            header('Location: my_reviews.php?tab=done');
            exit;
        }

        throw new RuntimeException(
            'Invalid review action.'
        );
    } catch (RuntimeException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $error = $exception->getMessage();
        $active_tab = isset($_POST['delete_review'])
            ? 'done'
            : 'pending';
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log(
            'My Reviews action failed: ' .
            $exception->getMessage()
        );

        $error =
            'Unable to process your review request. Please try again.';
        $active_tab = isset($_POST['delete_review'])
            ? 'done'
            : 'pending';
    }
}

$pending_review_stmt = $pdo->prepare("
    SELECT
        oi.order_item_product_id AS product_id,
        oi.order_item_id,
        oi.order_item_type AS product_type,
        o.order_id,
        o.order_created_at,
        oi.order_item_product_title AS product_title,
        p.product_cover_image,
        p.product_author,
        p.product_series,
        p.product_volume_number
    FROM order_items oi
    JOIN orders o
        ON oi.order_item_order_id = o.order_id
    JOIN products p
        ON oi.order_item_product_id = p.product_id
    WHERE o.order_user_id = ?
    AND o.order_payment_status = 'confirmed'
    AND o.order_status != 'cancelled'
    AND (
        (
            oi.order_item_type = 'physical'
            AND o.order_status = 'delivered'
        )
        OR
        (
            oi.order_item_type = 'ebook'
            AND EXISTS (
                SELECT 1
                FROM user_collection uc
                WHERE uc.collection_user_id = ?
                AND uc.collection_product_id =
                    oi.order_item_product_id
            )
        )
    )
    AND NOT EXISTS (
        SELECT 1
        FROM product_reviews r
        WHERE r.review_user_id = ?
        AND r.review_product_id =
            oi.order_item_product_id
    )
    ORDER BY
        o.order_created_at DESC,
        o.order_id DESC,
        oi.order_item_id DESC
");
$pending_review_stmt->execute([
    $user_id,
    $user_id,
    $user_id,
]);
$pending_review_rows =
    $pending_review_stmt->fetchAll(PDO::FETCH_ASSOC);

/*
 * Keep only the newest eligible order for each product so a second
 * purchase of the same product does not create another review card.
 */
$pending_reviews = [];
$pending_product_ids = [];

foreach ($pending_review_rows as $pending_review) {
    $pending_product_id =
        (int) $pending_review['product_id'];

    if (isset($pending_product_ids[$pending_product_id])) {
        continue;
    }

    $pending_product_ids[$pending_product_id] = true;
    $pending_reviews[] = $pending_review;
}

$my_reviews_stmt = $pdo->prepare("
    SELECT
        r.*,
        p.product_title,
        p.product_cover_image,
        p.product_series,
        p.product_volume_number
    FROM product_reviews r
    JOIN products p
        ON r.review_product_id = p.product_id
    WHERE r.review_user_id = ?
    ORDER BY r.review_created_at DESC
");
$my_reviews_stmt->execute([$user_id]);
$my_reviews =
    $my_reviews_stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >
    <title>My Reviews - MangaVault</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body {
            opacity: 0;
            animation: fadeIn 0.4s ease forwards;
        }

        @keyframes fadeIn {
            to {
                opacity: 1;
            }
        }

        .modal {
            display: none;
        }

        .modal.active {
            display: flex;
        }

        .star-btn {
            cursor: pointer;
            transition: transform 0.1s ease;
        }

        .star-btn:hover {
            transform: scale(1.2);
        }
    </style>
</head>
<body class="bg-[#F5F0EB] min-h-screen">

    <?php include '../includes/customer_navbar.php'; ?>

    <div class="max-w-7xl mx-auto px-6 py-8">
        <p class="text-sm text-gray-400 mb-6">
            <a
                href="../index.php"
                class="hover:text-red-600 transition-colors"
            >
                Home
            </a>
            <span class="mx-2">›</span>
            <a
                href="dashboard.php"
                class="hover:text-red-600 transition-colors"
            >
                My Account
            </a>
            <span class="mx-2">›</span>
            <span class="text-gray-600">My Reviews</span>
        </p>

        <div class="flex gap-8 items-start">
            <?php include '../includes/customer_sidebar.php'; ?>

            <div class="flex-1 min-w-0">
                <?php if ($success !== ''): ?>
                    <div
                        class="bg-green-50 border border-green-200 text-green-700 text-sm px-4 py-3 rounded-xl mb-5"
                    >
                        ✅ <?= htmlspecialchars(
                            $success,
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>
                    </div>
                <?php endif; ?>

                <?php if ($error !== ''): ?>
                    <div
                        class="bg-red-50 border border-red-200 text-red-600 text-sm px-4 py-3 rounded-xl mb-5"
                    >
                        ❌ <?= htmlspecialchars(
                            $error,
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>
                    </div>
                <?php endif; ?>

                <!-- Tabs -->
                <div
                    class="flex gap-1 bg-white rounded-2xl shadow-sm p-1 mb-6 w-fit"
                >
                    <button
                        type="button"
                        id="tab-pending"
                        onclick="switchTab('pending')"
                        class="px-5 py-2 rounded-xl text-sm font-semibold transition-colors"
                    >
                        ✍️ To Review
                        (<?= count($pending_reviews) ?>)
                    </button>

                    <button
                        type="button"
                        id="tab-done"
                        onclick="switchTab('done')"
                        class="px-5 py-2 rounded-xl text-sm font-semibold transition-colors"
                    >
                        ⭐ My Reviews
                        (<?= count($my_reviews) ?>)
                    </button>
                </div>

                <!-- Pending Reviews Tab -->
                <div id="content-pending">
                    <?php if (count($pending_reviews) === 0): ?>
                        <div
                            class="bg-white rounded-2xl shadow-sm p-12 text-center"
                        >
                            <div class="text-5xl mb-4">✅</div>
                            <p
                                class="font-semibold text-gray-600 mb-1"
                            >
                                All caught up!
                            </p>
                            <p class="text-gray-400 text-sm">
                                You've reviewed all your eligible products.
                            </p>
                        </div>
                    <?php else: ?>
                        <div class="space-y-3">
                            <?php foreach ($pending_reviews as $item): ?>
                                <div
                                    class="bg-white rounded-2xl shadow-sm p-5 flex items-center gap-4"
                                >
                                    <?php if ($item['product_cover_image']): ?>
                                        <img
                                            src="../assets/images/<?= htmlspecialchars(
                                                $item['product_cover_image'],
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>"
                                            alt="<?= htmlspecialchars(
                                                $item['product_title'],
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?> cover"
                                            class="w-12 h-16 object-cover rounded-lg flex-shrink-0"
                                        >
                                    <?php else: ?>
                                        <div
                                            class="w-12 h-16 bg-gray-100 rounded-lg flex-shrink-0 flex items-center justify-center text-gray-400 text-xs font-bold"
                                            aria-hidden="true"
                                        >
                                            📖
                                        </div>
                                    <?php endif; ?>

                                    <div class="flex-1 min-w-0">
                                        <div
                                            class="flex items-center gap-2 flex-wrap"
                                        >
                                            <p
                                                class="font-bold text-sm text-gray-800"
                                            >
                                                <?= htmlspecialchars(
                                                    $item['product_title'],
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>
                                            </p>

                                            <span
                                                class="<?= $item['product_type'] === 'ebook'
                                                    ? 'bg-blue-100 text-blue-700'
                                                    : 'bg-green-100 text-green-700' ?> text-[10px] px-2 py-0.5 rounded-full font-semibold"
                                            >
                                                <?= $item['product_type'] === 'ebook'
                                                    ? 'E-Book'
                                                    : 'Physical' ?>
                                            </span>
                                        </div>

                                        <?php if ($item['product_series']): ?>
                                            <p class="text-xs text-gray-400">
                                                <?= htmlspecialchars(
                                                    $item['product_series'],
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?><?= $item['product_volume_number']
                                                    ? ' Vol.' . (int) $item['product_volume_number']
                                                    : '' ?>
                                            </p>
                                        <?php endif; ?>

                                        <p
                                            class="text-xs text-gray-400 mt-0.5"
                                        >
                                            Order #<?= str_pad(
                                                (string) ((int) $item['order_id']),
                                                4,
                                                '0',
                                                STR_PAD_LEFT
                                            ) ?>
                                            ·
                                            <?= date(
                                                'd M Y',
                                                strtotime(
                                                    $item['order_created_at']
                                                )
                                            ) ?>
                                        </p>
                                    </div>

                                    <button
                                        type="button"
                                        class="write-review-button bg-red-600 hover:bg-red-700 text-white font-semibold px-4 py-2 rounded-xl text-sm transition-colors flex-shrink-0"
                                        data-product-id="<?= (int) $item['product_id'] ?>"
                                        data-order-id="<?= (int) $item['order_id'] ?>"
                                        data-product-title="<?= htmlspecialchars(
                                            (string) $item['product_title'],
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>"
                                    >
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
                        <div
                            class="bg-white rounded-2xl shadow-sm p-12 text-center"
                        >
                            <div class="text-5xl mb-4">⭐</div>
                            <p
                                class="font-semibold text-gray-600 mb-1"
                            >
                                No reviews yet
                            </p>
                            <p class="text-gray-400 text-sm">
                                Your reviews will appear here after submission.
                            </p>
                        </div>
                    <?php else: ?>
                        <div class="space-y-4">
                            <?php foreach ($my_reviews as $review): ?>
                                <div
                                    class="bg-white rounded-2xl shadow-sm p-5"
                                >
                                    <div class="flex items-start gap-4">
                                        <?php if ($review['product_cover_image']): ?>
                                            <img
                                                src="../assets/images/<?= htmlspecialchars(
                                                    $review['product_cover_image'],
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>"
                                                alt="<?= htmlspecialchars(
                                                    $review['product_title'],
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?> cover"
                                                class="w-12 h-16 object-cover rounded-lg flex-shrink-0"
                                            >
                                        <?php else: ?>
                                            <div
                                                class="w-12 h-16 bg-gray-100 rounded-lg flex-shrink-0 flex items-center justify-center text-gray-400 text-xs font-bold"
                                                aria-hidden="true"
                                            >
                                                📖
                                            </div>
                                        <?php endif; ?>

                                        <div class="flex-1 min-w-0">
                                            <p
                                                class="font-bold text-sm text-gray-800 mb-1"
                                            >
                                                <?= htmlspecialchars(
                                                    $review['product_title'],
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>
                                            </p>

                                            <?php if ($review['product_series']): ?>
                                                <p
                                                    class="text-xs text-gray-400 mb-2"
                                                >
                                                    <?= htmlspecialchars(
                                                        $review['product_series'],
                                                        ENT_QUOTES,
                                                        'UTF-8'
                                                    ) ?><?= $review['product_volume_number']
                                                        ? ' Vol.' . (int) $review['product_volume_number']
                                                        : '' ?>
                                                </p>
                                            <?php endif; ?>

                                            <div
                                                class="flex gap-0.5 mb-2"
                                                aria-label="<?= (int) $review['review_rating'] ?> out of 5 stars"
                                            >
                                                <?php for ($s = 1; $s <= 5; $s++): ?>
                                                    <span
                                                        class="text-lg <?= $s <= (int) $review['review_rating']
                                                            ? 'text-yellow-400'
                                                            : 'text-gray-200' ?>"
                                                        aria-hidden="true"
                                                    >
                                                        ★
                                                    </span>
                                                <?php endfor; ?>
                                            </div>

                                            <p
                                                class="text-sm text-gray-600 leading-relaxed"
                                            >
                                                <?= nl2br(
                                                    htmlspecialchars(
                                                        $review['review_comment'],
                                                        ENT_QUOTES,
                                                        'UTF-8'
                                                    )
                                                ) ?>
                                            </p>

                                            <p
                                                class="text-xs text-gray-400 mt-2"
                                            >
                                                <?= date(
                                                    'd M Y, h:i A',
                                                    strtotime(
                                                        $review['review_created_at']
                                                    )
                                                ) ?>
                                            </p>
                                        </div>

                                        <form
                                            method="POST"
                                            class="flex-shrink-0"
                                        >
                                            <?php csrf_field(); ?>
                                            <input
                                                type="hidden"
                                                name="delete_review"
                                                value="1"
                                            >
                                            <input
                                                type="hidden"
                                                name="review_id"
                                                value="<?= (int) $review['review_id'] ?>"
                                            >
                                            <button
                                                type="submit"
                                                onclick="return confirm('Delete this review?')"
                                                class="text-xs text-gray-400 hover:text-red-600 transition-colors border border-gray-200 hover:border-red-300 px-3 py-1.5 rounded-lg"
                                            >
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
    <div
        id="reviewModal"
        class="modal fixed inset-0 bg-black/50 z-50 items-center justify-center px-4"
        role="dialog"
        aria-modal="true"
        aria-labelledby="reviewModalTitle"
    >
        <div
            class="bg-white rounded-2xl w-full max-w-md shadow-2xl"
        >
            <div
                class="p-6 border-b border-gray-100 flex justify-between items-center"
            >
                <h3
                    id="reviewModalTitle"
                    class="font-black text-gray-800"
                >
                    Write a Review
                </h3>

                <button
                    type="button"
                    onclick="closeReviewModal()"
                    class="text-gray-400 hover:text-gray-600 text-xl"
                    aria-label="Close review form"
                >
                    ✕
                </button>
            </div>

            <form method="POST" class="p-6 space-y-4">
                <?php csrf_field(); ?>
                <input
                    type="hidden"
                    name="submit_review"
                    value="1"
                >
                <input
                    type="hidden"
                    name="product_id"
                    id="modalProductId"
                >
                <input
                    type="hidden"
                    name="order_id"
                    id="modalOrderId"
                >

                <p
                    class="font-semibold text-sm text-gray-700"
                    id="modalProductTitle"
                ></p>

                <div>
                    <label
                        class="block text-xs font-semibold text-gray-500 mb-2 uppercase tracking-wide"
                    >
                        Rating *
                    </label>

                    <div class="flex gap-1" id="modalStars">
                        <?php for ($s = 1; $s <= 5; $s++): ?>
                            <button
                                type="button"
                                onclick="setModalRating(<?= $s ?>)"
                                class="star-btn text-3xl text-gray-300"
                                id="modal-star-<?= $s ?>"
                                aria-label="<?= $s ?> star<?= $s > 1 ? 's' : '' ?>"
                            >
                                ★
                            </button>
                        <?php endfor; ?>
                    </div>

                    <input
                        type="hidden"
                        name="rating"
                        id="modalRatingInput"
                        value="0"
                    >
                </div>

                <div>
                    <label
                        for="modalReviewComment"
                        class="block text-xs font-semibold text-gray-500 mb-2 uppercase tracking-wide"
                    >
                        Your Review *
                    </label>

                    <textarea
                        id="modalReviewComment"
                        name="comment"
                        rows="4"
                        maxlength="2000"
                        required
                        placeholder="Share your thoughts about this product..."
                        class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 transition-colors bg-gray-50 focus:bg-white resize-none"
                    ></textarea>
                </div>

                <div class="flex gap-3">
                    <button
                        type="button"
                        onclick="closeReviewModal()"
                        class="flex-1 py-3 border-2 border-gray-100 rounded-xl text-sm font-semibold text-gray-600 hover:bg-gray-50 transition-colors"
                    >
                        Cancel
                    </button>

                    <button
                        type="submit"
                        class="flex-1 py-3 bg-red-600 hover:bg-red-700 text-white rounded-xl text-sm font-semibold transition-colors"
                    >
                        Submit Review
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
    const pendingTabButton =
        document.getElementById('tab-pending');
    const doneTabButton =
        document.getElementById('tab-done');
    const pendingTabContent =
        document.getElementById('content-pending');
    const doneTabContent =
        document.getElementById('content-done');
    const reviewModal =
        document.getElementById('reviewModal');
    const modalProductId =
        document.getElementById('modalProductId');
    const modalOrderId =
        document.getElementById('modalOrderId');
    const modalProductTitle =
        document.getElementById('modalProductTitle');
    const modalRatingInput =
        document.getElementById('modalRatingInput');
    const modalReviewComment =
        document.getElementById('modalReviewComment');
    const writeReviewButtons =
        document.querySelectorAll('.write-review-button');

    function switchTab(tab) {
        const showPending = tab === 'pending';

        pendingTabButton.className =
            'px-5 py-2 rounded-xl text-sm font-semibold transition-colors ' +
            (showPending
                ? 'bg-red-600 text-white'
                : 'text-gray-500 hover:text-red-600');

        doneTabButton.className =
            'px-5 py-2 rounded-xl text-sm font-semibold transition-colors ' +
            (!showPending
                ? 'bg-red-600 text-white'
                : 'text-gray-500 hover:text-red-600');

        pendingTabContent.classList.toggle(
            'hidden',
            !showPending
        );
        doneTabContent.classList.toggle(
            'hidden',
            showPending
        );
    }

    let modalRating = 0;

    function updateModalStars(rating) {
        for (let i = 1; i <= 5; i++) {
            const star = document.getElementById(
                'modal-star-' + i
            );

            if (!star) {
                continue;
            }

            star.style.color =
                i <= rating
                    ? '#facc15'
                    : '#d1d5db';
        }
    }

    function openReviewModal(
        productId,
        orderId,
        title
    ) {
        modalProductId.value = productId;
        modalOrderId.value = orderId;
        modalProductTitle.textContent = title;
        modalRating = 0;
        modalRatingInput.value = '0';
        modalReviewComment.value = '';
        updateModalStars(0);
        reviewModal.classList.add('active');
        modalReviewComment.focus();
    }

    function closeReviewModal() {
        reviewModal.classList.remove('active');
    }

    function setModalRating(rating) {
        modalRating = rating;
        modalRatingInput.value = String(rating);
        updateModalStars(rating);
    }

    for (let i = 1; i <= 5; i++) {
        const star = document.getElementById(
            'modal-star-' + i
        );

        if (!star) {
            continue;
        }

        star.addEventListener(
            'mouseover',
            () => updateModalStars(i)
        );

        star.addEventListener(
            'mouseout',
            () => updateModalStars(modalRating)
        );
    }

    writeReviewButtons.forEach(button => {
        button.addEventListener('click', () => {
            const productId = Number.parseInt(
                button.dataset.productId ?? '',
                10
            );
            const orderId = Number.parseInt(
                button.dataset.orderId ?? '',
                10
            );
            const productTitle =
                button.dataset.productTitle ?? '';

            if (
                !Number.isInteger(productId) ||
                productId < 1 ||
                !Number.isInteger(orderId) ||
                orderId < 1
            ) {
                return;
            }

            openReviewModal(
                productId,
                orderId,
                productTitle
            );
        });
    });

    reviewModal.addEventListener(
        'click',
        event => {
            if (event.target === reviewModal) {
                closeReviewModal();
            }
        }
    );

    document.addEventListener(
        'keydown',
        event => {
            if (
                event.key === 'Escape' &&
                reviewModal.classList.contains('active')
            ) {
                closeReviewModal();
            }
        }
    );

    switchTab(<?= json_encode($active_tab) ?>);
    </script>

</body>
</html>