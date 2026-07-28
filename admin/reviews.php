<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';

require_admin_or_staff();

$success = '';
$error = '';

if (
    isset($_SESSION['admin_review_success']) &&
    is_string($_SESSION['admin_review_success'])
) {
    $success = $_SESSION['admin_review_success'];
    unset($_SESSION['admin_review_success']);
}

if (
    isset($_SESSION['admin_review_error']) &&
    is_string($_SESSION['admin_review_error'])
) {
    $error = $_SESSION['admin_review_error'];
    unset($_SESSION['admin_review_error']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $action = $_POST['action'] ?? null;
    $review_id = filter_var(
        $_POST['review_id'] ?? null,
        FILTER_VALIDATE_INT,
        [
            'options' => [
                'min_range' => 1,
            ],
        ]
    );

    if (
        $action !== 'delete' ||
        $review_id === false ||
        $review_id === null
    ) {
        $_SESSION['admin_review_error'] =
            'Invalid review action.';

        header('Location: reviews.php');
        exit;
    }

    $delete_review_stmt = $pdo->prepare("
        DELETE FROM product_reviews
        WHERE review_id = ?
    ");
    $delete_review_stmt->execute([
        (int) $review_id,
    ]);

    if ($delete_review_stmt->rowCount() === 1) {
        $_SESSION['admin_review_success'] =
            'Review deleted.';
    } else {
        $_SESSION['admin_review_error'] =
            'Review not found.';
    }

    header('Location: reviews.php');
    exit;
}

$reviews = $pdo->query("
    SELECT
        r.review_id,
        r.review_rating,
        r.review_comment,
        r.review_status,
        r.review_created_at,
        u.user_first_name,
        u.user_last_name,
        u.user_gmail,
        p.product_title,
        p.product_cover_image
    FROM product_reviews r
    JOIN users u
        ON r.review_user_id = u.user_id
    JOIN products p
        ON r.review_product_id = p.product_id
    ORDER BY r.review_created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

$status_styles = [
    'approved' => 'bg-green-100 text-green-700',
    'pending' => 'bg-yellow-100 text-yellow-700',
    'rejected' => 'bg-red-100 text-red-700',
];

$status_labels = [
    'approved' => 'Published',
    'pending' => 'Legacy Pending',
    'rejected' => 'Legacy Rejected',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >
    <title>Manage Reviews - MangaVault Admin</title>
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
    </style>
</head>
<body class="min-h-screen bg-gray-100">

    <?php include '../includes/admin_navbar.php'; ?>

    <div class="mx-auto max-w-6xl px-6 py-8">
        <div class="mb-6 flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-black text-gray-800">
                    Customer Reviews
                </h1>
                <p class="mt-0.5 text-sm text-gray-400">
                    View customer reviews and delete inappropriate content
                </p>
            </div>
        </div>

        <?php if ($success !== ''): ?>
            <div
                class="mb-5 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700"
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
                class="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"
            >
                ❌ <?= htmlspecialchars(
                    $error,
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
            </div>
        <?php endif; ?>

        <div class="mb-6 flex items-center justify-between">
            <p class="text-sm text-gray-500">
                Total <?= count($reviews) ?> review(s)
            </p>
        </div>

        <?php if ($reviews === []): ?>
            <div
                class="rounded-2xl bg-white p-12 text-center shadow-sm"
            >
                <div class="mb-4 text-5xl">⭐</div>
                <p class="font-medium text-gray-500">
                    No reviews found.
                </p>
            </div>
        <?php else: ?>
            <div class="space-y-4">
                <?php foreach ($reviews as $review):
                    $status = (string) $review['review_status'];
                    $status_class =
                        $status_styles[$status]
                        ?? 'bg-gray-100 text-gray-600';
                    $status_label =
                        $status_labels[$status]
                        ?? ucfirst($status);
                ?>
                    <div
                        class="overflow-hidden rounded-2xl bg-white shadow-sm"
                    >
                        <div class="flex items-start gap-4 p-5">
                            <div
                                class="flex w-48 flex-shrink-0 items-center gap-3"
                            >
                                <?php if ($review['product_cover_image']): ?>
                                    <img
                                        src="../assets/images/<?= htmlspecialchars(
                                            $review['product_cover_image'],
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>"
                                        alt=""
                                        class="h-14 w-10 flex-shrink-0 rounded-lg object-cover"
                                    >
                                <?php endif; ?>

                                <div class="min-w-0">
                                    <p
                                        class="line-clamp-2 text-xs font-semibold text-gray-800"
                                    >
                                        <?= htmlspecialchars(
                                            $review['product_title'],
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    </p>
                                </div>
                            </div>

                            <div class="min-w-0 flex-1">
                                <div
                                    class="mb-2 flex flex-wrap items-center gap-3"
                                >
                                    <div class="flex items-center gap-2">
                                        <div
                                            class="flex h-7 w-7 items-center justify-center rounded-full bg-red-600 text-xs font-black text-white"
                                        >
                                            <?= htmlspecialchars(
                                                strtoupper(
                                                    substr(
                                                        (string) $review[
                                                            'user_first_name'
                                                        ],
                                                        0,
                                                        1
                                                    )
                                                ),
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>
                                        </div>

                                        <span
                                            class="text-sm font-semibold text-gray-800"
                                        >
                                            <?= htmlspecialchars(
                                                $review['user_first_name'] .
                                                ' ' .
                                                $review['user_last_name'],
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>
                                        </span>

                                        <span class="text-xs text-gray-400">
                                            <?= htmlspecialchars(
                                                $review['user_gmail'],
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>
                                        </span>
                                    </div>

                                    <div class="flex gap-0.5">
                                        <?php for ($star = 1; $star <= 5; $star++): ?>
                                            <span
                                                class="<?= $star <=
                                                    (int) $review[
                                                        'review_rating'
                                                    ]
                                                        ? 'text-yellow-400'
                                                        : 'text-gray-200' ?>"
                                            >
                                                ★
                                            </span>
                                        <?php endfor; ?>
                                    </div>

                                    <span
                                        class="rounded-full px-2 py-0.5 text-xs font-semibold <?= $status_class ?>"
                                    >
                                        <?= htmlspecialchars(
                                            $status_label,
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    </span>

                                    <span class="text-xs text-gray-400">
                                        <?= htmlspecialchars(
                                            date(
                                                'd M Y, h:i A',
                                                strtotime(
                                                    $review[
                                                        'review_created_at'
                                                    ]
                                                )
                                            ),
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    </span>
                                </div>

                                <p
                                    class="text-sm leading-relaxed text-gray-600"
                                >
                                    <?= nl2br(
                                        htmlspecialchars(
                                            $review['review_comment'],
                                            ENT_QUOTES,
                                            'UTF-8'
                                        )
                                    ) ?>
                                </p>
                            </div>

                            <div class="flex-shrink-0">
                                <form method="POST">
                                    <?php csrf_field(); ?>

                                    <input
                                        type="hidden"
                                        name="action"
                                        value="delete"
                                    >

                                    <input
                                        type="hidden"
                                        name="review_id"
                                        value="<?= (int) $review[
                                            'review_id'
                                        ] ?>"
                                    >

                                    <button
                                        type="submit"
                                        onclick="return confirm('Delete this review?')"
                                        class="rounded-lg border border-red-200 px-4 py-2 text-xs font-semibold text-red-600 transition-colors hover:bg-red-50"
                                    >
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