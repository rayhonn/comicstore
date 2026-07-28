<?php

require_once __DIR__ . '/../includes/auth.php';
require_customer();

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';

$user_id = current_user_id();

$wishlist_stmt = $pdo->prepare("
    SELECT
        w.wishlist_id,
        w.wishlist_added_at,
        p.product_id,
        p.product_title,
        p.product_price,
        p.product_cover_image,
        p.product_type,
        p.product_author,
        p.product_series,
        p.product_volume_number,
        p.product_is_available,
        pp.physical_stock_quantity
    FROM wishlist w
    JOIN products p
        ON w.wishlist_product_id = p.product_id
    LEFT JOIN product_physical pp
        ON p.product_id = pp.physical_product_id
    WHERE w.wishlist_user_id = ?
    ORDER BY w.wishlist_added_at DESC
");
$wishlist_stmt->execute([
    $user_id,
]);
$items = $wishlist_stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >
    <title>My Wishlist - MangaVault</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        html {
            scroll-behavior: smooth;
        }

        body {
            opacity: 0;
            animation: fadeIn 0.4s ease forwards;
        }

        @keyframes fadeIn {
            to {
                opacity: 1;
            }
        }

        .line-clamp-2 {
            display: -webkit-box;
            overflow: hidden;
            -webkit-box-orient: vertical;
            -webkit-line-clamp: 2;
        }
    </style>
</head>
<body class="min-h-screen bg-[#F5F0EB]">

    <?php include '../includes/customer_navbar.php'; ?>

    <div class="mx-auto max-w-7xl px-6 py-8">
        <p class="mb-6 text-sm text-gray-400">
            <a
                href="../index.php"
                class="transition-colors hover:text-red-600"
            >
                Home
            </a>
            <span class="mx-2">›</span>
            <span class="text-gray-600">My Wishlist</span>
        </p>

        <div class="flex items-start gap-8">
            <?php include '../includes/customer_sidebar.php'; ?>

            <div class="min-w-0 flex-1">
                <div class="mb-6 flex items-center justify-between">
                    <h1 class="text-xl font-black text-gray-800">
                        My Wishlist

                        <?php if ($items !== []): ?>
                            <span
                                class="ml-2 text-sm font-normal text-gray-400"
                            >
                                <?= count($items) ?> items
                            </span>
                        <?php endif; ?>
                    </h1>
                </div>

                <?php if ($items === []): ?>
                    <div
                        class="rounded-2xl bg-white p-12 text-center shadow-sm"
                    >
                        <div class="mb-4 text-6xl">♡</div>
                        <p class="mb-2 font-medium text-gray-500">
                            Your wishlist is empty
                        </p>
                        <p class="mb-6 text-sm text-gray-400">
                            Save items you love to buy later.
                        </p>
                        <a
                            href="home.php"
                            class="inline-block rounded-xl bg-red-600 px-6 py-2.5 text-sm font-semibold text-white transition-colors duration-200 hover:bg-red-700"
                        >
                            Browse Catalog
                        </a>
                    </div>
                <?php else: ?>
                    <div
                        class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4"
                    >
                        <?php foreach ($items as $item):
                            $is_available =
                                (int) $item['product_is_available'] === 1;
                            $is_physical =
                                $item['product_type'] === 'physical';
                            $stock_quantity =
                                (int) (
                                    $item['physical_stock_quantity']
                                    ?? 0
                                );
                            $is_out_of_stock =
                                $is_physical && $stock_quantity <= 0;
                            $can_add_to_cart =
                                $is_available && !$is_out_of_stock;
                        ?>
                            <div
                                class="group overflow-hidden rounded-2xl bg-white shadow-sm transition-all duration-300 hover:-translate-y-1 hover:shadow-md"
                            >
                                <div class="relative overflow-hidden">
                                    <?php if ($is_available): ?>
                                        <a
                                            href="product_detail.php?id=<?= (int) $item[
                                                'product_id'
                                            ] ?>"
                                        >
                                    <?php endif; ?>

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
                                            class="h-48 w-full object-cover transition-transform duration-300 group-hover:scale-105"
                                        >
                                    <?php else: ?>
                                        <div
                                            class="flex h-48 w-full items-center justify-center bg-gray-100 text-2xl font-bold text-gray-400"
                                        >
                                            <?= htmlspecialchars(
                                                strtoupper(
                                                    substr(
                                                        (string) $item[
                                                            'product_title'
                                                        ],
                                                        0,
                                                        2
                                                    )
                                                ),
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($is_available): ?>
                                        </a>
                                    <?php else: ?>
                                        <div
                                            class="absolute inset-0 bg-gray-900/20"
                                            aria-hidden="true"
                                        ></div>
                                        <span
                                            class="absolute bottom-2 left-2 rounded-full bg-gray-900/90 px-3 py-1 text-[10px] font-bold uppercase tracking-wide text-white"
                                        >
                                            Unavailable
                                        </span>
                                    <?php endif; ?>

                                    <form
                                        method="POST"
                                        action="wishlist_action.php"
                                        class="absolute right-2 top-2"
                                    >
                                        <?php csrf_field(); ?>

                                        <input
                                            type="hidden"
                                            name="product_id"
                                            value="<?= (int) $item[
                                                'product_id'
                                            ] ?>"
                                        >
                                        <input
                                            type="hidden"
                                            name="action"
                                            value="remove"
                                        >
                                        <input
                                            type="hidden"
                                            name="redirect"
                                            value="wishlist.php"
                                        >

                                        <button
                                            type="submit"
                                            aria-label="Remove from wishlist"
                                            class="flex h-8 w-8 items-center justify-center rounded-full bg-white/90 text-sm text-red-600 shadow-sm transition-all duration-200 hover:bg-red-600 hover:text-white"
                                        >
                                            ♥
                                        </button>
                                    </form>
                                </div>

                                <div class="p-3">
                                    <p
                                        class="mb-1 text-xs uppercase tracking-wide text-gray-400"
                                    >
                                        <?= $item['product_type'] === 'ebook'
                                            ? '📱 E-Book'
                                            : '📦 Physical' ?>
                                    </p>

                                    <h3
                                        class="line-clamp-2 mb-1 text-sm font-semibold text-gray-800"
                                    >
                                        <?= htmlspecialchars(
                                            $item['product_title'],
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    </h3>

                                    <p class="mb-1 text-xs text-gray-400">
                                        <?= htmlspecialchars(
                                            $item['product_series'] ?? '',
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                        <?= $item['product_volume_number']
                                            ? ' Vol.' .
                                                htmlspecialchars(
                                                    (string) $item[
                                                        'product_volume_number'
                                                    ],
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                )
                                            : '' ?>
                                    </p>

                                    <?php if (!$is_available): ?>
                                        <p
                                            class="mb-2 text-xs font-semibold text-gray-500"
                                        >
                                            This product is no longer available.
                                        </p>
                                    <?php elseif ($is_physical): ?>
                                        <p
                                            class="mb-2 text-xs <?= $stock_quantity <= 0
                                                ? 'text-red-500'
                                                : ($stock_quantity <= 5
                                                    ? 'text-orange-500'
                                                    : 'text-green-600') ?>"
                                        >
                                            <?= $stock_quantity <= 0
                                                ? 'Out of Stock'
                                                : ($stock_quantity <= 5
                                                    ? 'Low Stock'
                                                    : 'In Stock') ?>
                                        </p>
                                    <?php endif; ?>

                                    <p
                                        class="mb-3 text-sm font-bold text-red-600"
                                    >
                                        RM <?= number_format(
                                            (float) $item['product_price'],
                                            2
                                        ) ?>
                                    </p>

                                    <?php if (!$can_add_to_cart): ?>
                                        <button
                                            type="button"
                                            disabled
                                            class="w-full cursor-not-allowed rounded-lg bg-gray-100 py-2 text-xs font-semibold text-gray-400"
                                        >
                                            <?= !$is_available
                                                ? 'Unavailable'
                                                : 'Out of Stock' ?>
                                        </button>
                                    <?php else: ?>
                                        <form
                                            method="POST"
                                            action="cart_action.php"
                                        >
                                            <?php csrf_field(); ?>

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="add"
                                            >
                                            <input
                                                type="hidden"
                                                name="product_id"
                                                value="<?= (int) $item[
                                                    'product_id'
                                                ] ?>"
                                            >
                                            <input
                                                type="hidden"
                                                name="quantity"
                                                value="1"
                                            >

                                            <button
                                                type="submit"
                                                class="w-full rounded-lg bg-red-600 py-2 text-xs font-semibold text-white transition-colors duration-200 hover:bg-red-700"
                                            >
                                                Add to Cart
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

</body>
</html>