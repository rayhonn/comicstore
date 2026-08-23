<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';

require_supplier();

$supplier_id = filter_var(
    $_SESSION['supplier_id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if ($supplier_id === false || $supplier_id === null) {
    header('Location: login.php');
    exit;
}

$supplier_stmt = $pdo->prepare("
    SELECT
        supplier_id,
        supplier_name,
        supplier_status
    FROM suppliers
    WHERE supplier_id = ?
");
$supplier_stmt->execute([$supplier_id]);

$supplier = $supplier_stmt->fetch(PDO::FETCH_ASSOC);

if (!$supplier) {
    destroy_session();

    redirect_to(
        app_path('supplier/login.php')
    );
}

$error = '';
$success = '';

if (isset($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['save_products'])
) {
    csrf_verify();

    $raw_product_ids =
        $_POST['product_ids'] ?? [];

    if (!is_array($raw_product_ids)) {
        $error = 'Invalid product selection.';
    }

    $selected_product_ids = [];

    if ($error === '') {
        foreach ($raw_product_ids as $raw_product_id) {
            $product_id = filter_var(
                $raw_product_id,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]
            );

            if ($product_id === false) {
                $error =
                    'One or more selected products are invalid.';
                break;
            }

            $selected_product_ids[(int) $product_id] =
                true;
        }
    }

    $selected_product_ids =
        array_keys($selected_product_ids);

    if ($error === '') {
        try {
            $pdo->beginTransaction();

            $lock_supplier = $pdo->prepare("
                SELECT
                    supplier_id,
                    supplier_name,
                    supplier_status
                FROM suppliers
                WHERE supplier_id = ?
                FOR UPDATE
            ");
            $lock_supplier->execute([$supplier_id]);

            $locked_supplier =
                $lock_supplier->fetch(PDO::FETCH_ASSOC);

            if (!$locked_supplier) {
                throw new RuntimeException(
                    'Supplier account not found.'
                );
            }

            if (
                $locked_supplier['supplier_status'] !==
                'active'
            ) {
                throw new RuntimeException(
                    'Your supplier account is inactive. Product catalogue changes are currently disabled.'
                );
            }

            if ($selected_product_ids) {
                $placeholders = implode(
                    ',',
                    array_fill(
                        0,
                        count($selected_product_ids),
                        '?'
                    )
                );

                $valid_products_stmt = $pdo->prepare("
                    SELECT product_id
                    FROM products
                    WHERE product_id IN ($placeholders)
                    AND product_type = 'physical'
                    ORDER BY product_id
                    FOR UPDATE
                ");
                $valid_products_stmt->execute(
                    $selected_product_ids
                );

                $valid_product_ids = array_map(
                    'intval',
                    $valid_products_stmt->fetchAll(
                        PDO::FETCH_COLUMN
                    )
                );

                if (
                    count($valid_product_ids) !==
                    count($selected_product_ids)
                ) {
                    throw new RuntimeException(
                        'One or more selected products are no longer valid physical products.'
                    );
                }
            }

            $existing_mappings_stmt = $pdo->prepare("
                SELECT
                    sp.supplier_product_product_id,
                    sp.supplier_product_status,
                    p.product_title
                FROM supplier_products sp
                INNER JOIN products p
                    ON p.product_id =
                        sp.supplier_product_product_id
                WHERE sp.supplier_product_supplier_id = ?
                ORDER BY
                    sp.supplier_product_product_id
                FOR UPDATE
            ");
            $existing_mappings_stmt->execute([
                $supplier_id,
            ]);

            $existing_mappings =
                $existing_mappings_stmt->fetchAll(
                    PDO::FETCH_ASSOC
                );

            $selected_lookup = array_fill_keys(
                $selected_product_ids,
                true
            );

            $open_rfq_stmt = $pdo->prepare("
                SELECT r.rfq_number
                FROM rfq r
                INNER JOIN rfq_suppliers rs
                    ON rs.rfq_supplier_rfq_id =
                        r.rfq_id
                INNER JOIN rfq_items ri
                    ON ri.rfq_item_rfq_id =
                        r.rfq_id
                WHERE rs.rfq_supplier_supplier_id = ?
                AND ri.rfq_item_product_id = ?
                AND r.rfq_status IN (
                    'pending',
                    'quoted'
                )
                ORDER BY r.rfq_id
                LIMIT 1
                FOR UPDATE
            ");

            foreach ($existing_mappings as $mapping) {
                $product_id =
                    (int) $mapping[
                        'supplier_product_product_id'
                    ];

                if (
                    $mapping[
                        'supplier_product_status'
                    ] !== 'active' ||
                    isset($selected_lookup[$product_id])
                ) {
                    continue;
                }

                $open_rfq_stmt->execute([
                    $supplier_id,
                    $product_id,
                ]);

                $open_rfq_number =
                    $open_rfq_stmt->fetchColumn();

                if ($open_rfq_number !== false) {
                    throw new RuntimeException(
                        'Cannot stop supplying ' .
                        $mapping['product_title'] .
                        ' because it is still included in open RFQ ' .
                        $open_rfq_number .
                        '.'
                    );
                }
            }

            $activate_mapping = $pdo->prepare("
                INSERT INTO supplier_products (
                    supplier_product_supplier_id,
                    supplier_product_product_id,
                    supplier_product_status
                )
                VALUES (?, ?, 'active')
                ON DUPLICATE KEY UPDATE
                    supplier_product_status = 'active'
            ");

            foreach (
                $selected_product_ids as $product_id
            ) {
                $activate_mapping->execute([
                    $supplier_id,
                    $product_id,
                ]);
            }

            $deactivate_mapping = $pdo->prepare("
                UPDATE supplier_products
                SET supplier_product_status = 'inactive'
                WHERE supplier_product_supplier_id = ?
                AND supplier_product_product_id = ?
                AND supplier_product_status = 'active'
            ");

            foreach ($existing_mappings as $mapping) {
                $product_id =
                    (int) $mapping[
                        'supplier_product_product_id'
                    ];

                if (
                    $mapping[
                        'supplier_product_status'
                    ] === 'active' &&
                    !isset($selected_lookup[$product_id])
                ) {
                    $deactivate_mapping->execute([
                        $supplier_id,
                        $product_id,
                    ]);
                }
            }

            $pdo->commit();

            $_SESSION['flash_success'] =
                'Your product catalogue was updated successfully.';

            header('Location: products.php');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if ($e instanceof RuntimeException) {
                $error = $e->getMessage();
            } else {
                app_error_log(
                    'Supplier catalogue update failed: ' .
                    $e->getMessage()
                );

                $error =
                    'Unable to update your product catalogue. Please try again.';
            }
        }
    }
}

$products_stmt = $pdo->prepare("
    SELECT
        p.product_id,
        p.product_title,
        p.product_series,
        p.product_volume_number,
        p.product_publisher,
        p.product_cover_image,
        p.product_is_available,
        sp.supplier_product_status,
        sp.supplier_product_created_at,
        sp.supplier_product_updated_at,
        COALESCE(open_rfqs.open_rfq_count, 0)
            AS open_rfq_count,
        open_rfqs.open_rfq_number
    FROM products p
    LEFT JOIN supplier_products sp
        ON sp.supplier_product_product_id =
            p.product_id
        AND sp.supplier_product_supplier_id = ?
    LEFT JOIN (
        SELECT
            ri.rfq_item_product_id AS product_id,
            COUNT(DISTINCT r.rfq_id)
                AS open_rfq_count,
            MIN(r.rfq_number)
                AS open_rfq_number
        FROM rfq r
        INNER JOIN rfq_suppliers rs
            ON rs.rfq_supplier_rfq_id =
                r.rfq_id
        INNER JOIN rfq_items ri
            ON ri.rfq_item_rfq_id =
                r.rfq_id
        WHERE rs.rfq_supplier_supplier_id = ?
        AND r.rfq_status IN (
            'pending',
            'quoted'
        )
        GROUP BY
            ri.rfq_item_product_id
    ) open_rfqs
        ON open_rfqs.product_id =
            p.product_id
    WHERE p.product_type = 'physical'
    ORDER BY
        CASE
            WHEN sp.supplier_product_status = 'active'
            THEN 0
            ELSE 1
        END,
        p.product_is_available DESC,
        p.product_title,
        p.product_volume_number,
        p.product_id
");
$products_stmt->execute([
    $supplier_id,
    $supplier_id,
]);

$products =
    $products_stmt->fetchAll(PDO::FETCH_ASSOC);

$active_product_count = 0;
$inactive_product_count = 0;
$open_rfq_product_count = 0;

foreach ($products as $product) {
    if (
        $product['supplier_product_status'] ===
        'active'
    ) {
        $active_product_count++;
    } else {
        $inactive_product_count++;
    }

    if ((int) $product['open_rfq_count'] > 0) {
        $open_rfq_product_count++;
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >
    <title>My Products - Supplier Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-slate-50 min-h-screen">

    <?php include '../includes/supplier_navbar.php'; ?>

    <div class="max-w-7xl mx-auto px-5 md:px-8 py-8">

        <div
            class="flex flex-col xl:flex-row xl:items-end xl:justify-between gap-5 mb-8"
        >
            <div>
                <div
                    class="inline-flex items-center gap-2 bg-blue-50 text-blue-700 text-xs font-semibold px-3 py-1.5 rounded-full mb-3"
                >
                    <span
                        class="w-2 h-2 bg-blue-500 rounded-full"
                    ></span>
                    Supplier Catalogue
                </div>

                <h1
                    class="text-3xl font-black text-slate-900 tracking-tight"
                >
                    My Products
                </h1>

                <p
                    class="text-sm text-slate-500 mt-2 max-w-2xl"
                >
                    Maintain the physical products your company can supply to MangaVault.
                    Active products are used automatically when MangaVault determines
                    supplier eligibility for new RFQs.
                </p>
            </div>

            <div
                class="flex items-center gap-3 bg-white border border-slate-200 rounded-2xl px-4 py-3 shadow-sm"
            >
                <div
                    class="w-10 h-10 rounded-xl bg-slate-100 flex items-center justify-center"
                >
                    <svg
                        class="w-5 h-5 text-slate-500"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M4 5.5A2.5 2.5 0 016.5 3H20v16H6.5A2.5 2.5 0 004 21.5v-16zM4 5.5v16"
                        ></path>
                    </svg>
                </div>

                <div>
                    <p
                        class="text-[10px] uppercase tracking-wider text-slate-400 font-bold"
                    >
                        Company
                    </p>

                    <p
                        class="text-sm font-bold text-slate-700"
                    >
                        <?= htmlspecialchars(
                            $supplier['supplier_name']
                        ) ?>
                    </p>
                </div>
            </div>
        </div>

        <?php if ($success): ?>
        <div
            class="bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm px-4 py-3 rounded-2xl mb-6"
        >
            ✅ <?= htmlspecialchars($success) ?>
        </div>
        <?php endif; ?>

        <?php if ($error): ?>
        <div
            class="bg-red-50 border border-red-200 text-red-700 text-sm px-4 py-3 rounded-2xl mb-6"
        >
            ❌ <?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>

        <?php if (
            $supplier['supplier_status'] !== 'active'
        ): ?>
        <div
            class="bg-amber-50 border border-amber-200 text-amber-800 text-sm px-4 py-3 rounded-2xl mb-6"
        >
            ⚠️ Your supplier account is currently inactive. Catalogue changes are
            disabled until MangaVault reactivates your supplier account.
        </div>
        <?php endif; ?>

        <div
            class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6"
        >
            <div
                class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm"
            >
                <div
                    class="flex items-center justify-between"
                >
                    <div>
                        <p
                            class="text-xs text-slate-400 uppercase tracking-wide font-bold"
                        >
                            Active Products
                        </p>

                        <p
                            class="text-3xl font-black text-slate-900 mt-2"
                        >
                            <?= $active_product_count ?>
                        </p>
                    </div>

                    <div
                        class="w-11 h-11 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center"
                    >
                        <svg
                            class="w-5 h-5"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M5 13l4 4L19 7"
                            ></path>
                        </svg>
                    </div>
                </div>
            </div>

            <div
                class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm"
            >
                <div
                    class="flex items-center justify-between"
                >
                    <div>
                        <p
                            class="text-xs text-slate-400 uppercase tracking-wide font-bold"
                        >
                            Not Supplied
                        </p>

                        <p
                            class="text-3xl font-black text-slate-900 mt-2"
                        >
                            <?= $inactive_product_count ?>
                        </p>
                    </div>

                    <div
                        class="w-11 h-11 rounded-xl bg-slate-100 text-slate-500 flex items-center justify-center"
                    >
                        <svg
                            class="w-5 h-5"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M6 18L18 6M6 6l12 12"
                            ></path>
                        </svg>
                    </div>
                </div>
            </div>

            <div
                class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm"
            >
                <div
                    class="flex items-center justify-between"
                >
                    <div>
                        <p
                            class="text-xs text-slate-400 uppercase tracking-wide font-bold"
                        >
                            Products in Open RFQs
                        </p>

                        <p
                            class="text-3xl font-black text-slate-900 mt-2"
                        >
                            <?= $open_rfq_product_count ?>
                        </p>
                    </div>

                    <div
                        class="w-11 h-11 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center"
                    >
                        <svg
                            class="w-5 h-5"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M9 12h6M9 16h6M9 8h6M5 4h14v16H5V4z"
                            ></path>
                        </svg>
                    </div>
                </div>
            </div>
        </div>

        <form method="POST">
            <?php csrf_field(); ?>

            <input
                type="hidden"
                name="save_products"
                value="1"
            >

            <div
                class="bg-white border border-slate-200 rounded-3xl shadow-sm overflow-hidden"
            >
                <div
                    class="px-5 md:px-6 py-5 border-b border-slate-100 flex flex-col xl:flex-row xl:items-center xl:justify-between gap-4"
                >
                    <div>
                        <h2
                            class="font-black text-slate-800 text-lg"
                        >
                            Supply Catalogue
                        </h2>

                        <p
                            class="text-xs text-slate-400 mt-1"
                        >
                            Select the products your company currently supplies.
                        </p>
                    </div>

                    <div
                        class="flex flex-col sm:flex-row gap-2"
                    >
                        <div class="relative">
                            <svg
                                class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400"
                                fill="none"
                                stroke="currentColor"
                                viewBox="0 0 24 24"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M21 21l-4.35-4.35m1.35-5.65a7 7 0 11-14 0 7 7 0 0114 0z"
                                ></path>
                            </svg>

                            <input
                                type="text"
                                id="productSearch"
                                placeholder="Search title, series or publisher..."
                                class="w-full sm:w-72 pl-9 pr-4 py-2.5 border border-slate-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-blue-100 focus:border-blue-400"
                            >
                        </div>

                        <select
                            id="statusFilter"
                            class="px-4 py-2.5 border border-slate-200 rounded-xl text-sm text-slate-600 focus:outline-none focus:border-blue-400 bg-white"
                        >
                            <option value="all">
                                All Products
                            </option>

                            <option value="active">
                                Currently Supplied
                            </option>

                            <option value="inactive">
                                Not Supplied
                            </option>

                            <option value="open-rfq">
                                In Open RFQ
                            </option>
                        </select>
                    </div>
                </div>

                <div
                    class="px-5 md:px-6 py-4 bg-slate-50/70 border-b border-slate-100 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3"
                >
                    <p class="text-xs text-slate-500">
                        Catalogue changes affect eligibility for future RFQs immediately.
                    </p>

                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            onclick="selectVisibleProducts()"
                            class="px-3 py-2 bg-white border border-slate-200 hover:border-blue-300 text-slate-600 text-xs font-semibold rounded-xl transition-colors"
                        >
                            Select Visible
                        </button>

                        <button
                            type="button"
                            onclick="clearVisibleProducts()"
                            class="px-3 py-2 bg-white border border-slate-200 hover:border-red-300 text-slate-600 text-xs font-semibold rounded-xl transition-colors"
                        >
                            Clear Visible
                        </button>
                    </div>
                </div>

                <?php if (!$products): ?>
                <div class="text-center py-20">
                    <div
                        class="w-16 h-16 bg-slate-100 rounded-2xl mx-auto flex items-center justify-center mb-4"
                    >
                        <svg
                            class="w-7 h-7 text-slate-400"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M4 5.5A2.5 2.5 0 016.5 3H20v16H6.5A2.5 2.5 0 004 21.5v-16zM4 5.5v16"
                            ></path>
                        </svg>
                    </div>

                    <p
                        class="font-semibold text-slate-600"
                    >
                        No physical products are available.
                    </p>
                </div>

                <?php else: ?>

                <div
                    id="productGrid"
                    class="grid grid-cols-1 xl:grid-cols-2 gap-4 p-5 md:p-6"
                >
                    <?php foreach ($products as $product): ?>
                    <?php
                    $is_mapped =
                        $product[
                            'supplier_product_status'
                        ] === 'active';

                    $has_open_rfq =
                        (int) $product[
                            'open_rfq_count'
                        ] > 0;

                    $search_text = strtolower(
                        implode(
                            ' ',
                            [
                                $product[
                                    'product_title'
                                ] ?? '',
                                $product[
                                    'product_series'
                                ] ?? '',
                                $product[
                                    'product_publisher'
                                ] ?? '',
                            ]
                        )
                    );
                    ?>

                    <label
                        class="product-card group relative flex gap-4 p-4 border rounded-2xl cursor-pointer transition-all
                               <?= $is_mapped
                                   ? 'border-blue-200 bg-blue-50/40'
                                   : 'border-slate-200 hover:border-blue-200 hover:shadow-sm' ?>"
                        data-search="<?= htmlspecialchars(
                            $search_text,
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>"
                        data-status="<?= $is_mapped
                            ? 'active'
                            : 'inactive' ?>"
                        data-open-rfq="<?= $has_open_rfq
                            ? '1'
                            : '0' ?>"
                    >
                        <div class="pt-1">
                            <input
                                type="checkbox"
                                name="product_ids[]"
                                value="<?= (int) $product[
                                    'product_id'
                                ] ?>"
                                class="product-checkbox w-4 h-4 accent-blue-600"
                                <?= $is_mapped
                                    ? 'checked'
                                    : '' ?>
                                <?= $supplier[
                                    'supplier_status'
                                ] !== 'active'
                                    ? 'disabled'
                                    : '' ?>
                            >
                        </div>

                        <div
                            class="w-14 h-20 rounded-xl bg-slate-100 overflow-hidden flex-shrink-0 border border-slate-100"
                        >
                            <?php if (
                                !empty(
                                    $product[
                                        'product_cover_image'
                                    ]
                                )
                            ): ?>
                            <img
                                src="../assets/images/<?= htmlspecialchars(
                                    $product[
                                        'product_cover_image'
                                    ]
                                ) ?>"
                                alt=""
                                class="w-full h-full object-cover"
                                onerror="this.style.display='none'"
                            >
                            <?php endif; ?>
                        </div>

                        <div class="min-w-0 flex-1">
                            <div
                                class="flex items-start justify-between gap-3"
                            >
                                <div class="min-w-0">
                                    <p
                                        class="font-bold text-sm text-slate-800 truncate"
                                    >
                                        <?= htmlspecialchars(
                                            $product[
                                                'product_title'
                                            ]
                                        ) ?>
                                    </p>

                                    <p
                                        class="text-xs text-slate-400 mt-1 truncate"
                                    >
                                        <?= htmlspecialchars(
                                            $product[
                                                'product_series'
                                            ] ?: 'No series'
                                        ) ?>

                                        <?php if (
                                            $product[
                                                'product_volume_number'
                                            ] !== null
                                        ): ?>
                                            · Vol.
                                            <?= (int) $product[
                                                'product_volume_number'
                                            ] ?>
                                        <?php endif; ?>
                                    </p>

                                    <?php if (
                                        !empty(
                                            $product[
                                                'product_publisher'
                                            ]
                                        )
                                    ): ?>
                                    <p
                                        class="text-xs text-slate-400 mt-1 truncate"
                                    >
                                        <?= htmlspecialchars(
                                            $product[
                                                'product_publisher'
                                            ]
                                        ) ?>
                                    </p>
                                    <?php endif; ?>
                                </div>

                                <?php if (
                                    (int) $product[
                                        'product_is_available'
                                    ] === 1
                                ): ?>
                                <span
                                    class="bg-emerald-50 text-emerald-700 border border-emerald-100 text-[10px] px-2 py-1 rounded-full font-bold whitespace-nowrap"
                                >
                                    Catalogue Active
                                </span>
                                <?php else: ?>
                                <span
                                    class="bg-slate-100 text-slate-500 text-[10px] px-2 py-1 rounded-full font-bold whitespace-nowrap"
                                >
                                    MangaVault Inactive
                                </span>
                                <?php endif; ?>
                            </div>

                            <div
                                class="flex flex-wrap items-center gap-2 mt-3"
                            >
                                <?php if ($is_mapped): ?>
                                <span
                                    class="inline-flex items-center gap-1.5 bg-blue-50 text-blue-700 text-[11px] px-2.5 py-1 rounded-full font-semibold"
                                >
                                    <span
                                        class="w-1.5 h-1.5 rounded-full bg-blue-500"
                                    ></span>
                                    Currently supplied
                                </span>
                                <?php else: ?>
                                <span
                                    class="inline-flex items-center gap-1.5 bg-slate-100 text-slate-500 text-[11px] px-2.5 py-1 rounded-full font-semibold"
                                >
                                    Not currently supplied
                                </span>
                                <?php endif; ?>

                                <?php if ($has_open_rfq): ?>
                                <span
                                    class="inline-flex items-center gap-1.5 bg-amber-50 text-amber-700 text-[11px] px-2.5 py-1 rounded-full font-semibold"
                                >
                                    Open RFQ
                                    <?= htmlspecialchars(
                                        $product[
                                            'open_rfq_number'
                                        ] ?? ''
                                    ) ?>

                                    <?php if (
                                        (int) $product[
                                            'open_rfq_count'
                                        ] > 1
                                    ): ?>
                                        +<?= (int) $product[
                                            'open_rfq_count'
                                        ] - 1 ?>
                                    <?php endif; ?>
                                </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </label>
                    <?php endforeach; ?>
                </div>

                <div
                    id="noSearchResults"
                    class="hidden text-center py-16 px-5"
                >
                    <p
                        class="text-slate-400 text-sm"
                    >
                        No products match the current search or filter.
                    </p>
                </div>

                <?php endif; ?>

                <div
                    class="px-5 md:px-6 py-5 bg-slate-50 border-t border-slate-100 flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4"
                >
                    <div
                        class="flex items-start gap-3 max-w-2xl"
                    >
                        <div
                            class="w-9 h-9 rounded-xl bg-amber-100 text-amber-700 flex items-center justify-center flex-shrink-0"
                        >
                            <svg
                                class="w-4 h-4"
                                fill="none"
                                stroke="currentColor"
                                viewBox="0 0 24 24"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M12 9v4m0 4h.01M10.3 3.5L2.8 17a2 2 0 001.75 3h14.9A2 2 0 0021.2 17L13.7 3.5a2 2 0 00-3.4 0z"
                                ></path>
                            </svg>
                        </div>

                        <p
                            class="text-xs text-slate-500 leading-5"
                        >
                            A product cannot be removed while it is still included
                            in an open RFQ assigned to your company. Complete or close
                            the RFQ workflow first to protect procurement integrity.
                        </p>
                    </div>

                    <button
                        type="submit"
                        class="px-6 py-3 bg-blue-600 hover:bg-blue-700 disabled:bg-slate-300 disabled:cursor-not-allowed text-white font-bold rounded-xl text-sm transition-colors shadow-sm"
                        <?= $supplier[
                            'supplier_status'
                        ] !== 'active'
                            ? 'disabled'
                            : '' ?>
                    >
                        Save Catalogue
                    </button>
                </div>
            </div>
        </form>
    </div>

<script>
const productSearch =
    document.getElementById('productSearch');

const statusFilter =
    document.getElementById('statusFilter');

const productCards = Array.from(
    document.querySelectorAll('.product-card')
);

function filterProducts() {
    const query =
        productSearch.value
            .trim()
            .toLowerCase();

    const selectedStatus =
        statusFilter.value;

    let visibleCount = 0;

    productCards.forEach((card) => {
        const searchText =
            card.dataset.search || '';

        const productStatus =
            card.dataset.status || '';

        const hasOpenRfq =
            card.dataset.openRfq === '1';

        const matchesSearch =
            searchText.includes(query);

        let matchesStatus = true;

        if (selectedStatus === 'active') {
            matchesStatus =
                productStatus === 'active';
        }

        if (selectedStatus === 'inactive') {
            matchesStatus =
                productStatus === 'inactive';
        }

        if (selectedStatus === 'open-rfq') {
            matchesStatus =
                hasOpenRfq;
        }

        const isVisible =
            matchesSearch &&
            matchesStatus;

        card.classList.toggle(
            'hidden',
            !isVisible
        );

        if (isVisible) {
            visibleCount++;
        }
    });

    document
        .getElementById('noSearchResults')
        .classList.toggle(
            'hidden',
            visibleCount !== 0
        );
}

function selectVisibleProducts() {
    productCards.forEach((card) => {
        if (!card.classList.contains('hidden')) {
            const checkbox =
                card.querySelector(
                    '.product-checkbox'
                );

            if (!checkbox.disabled) {
                checkbox.checked = true;
            }
        }
    });
}

function clearVisibleProducts() {
    productCards.forEach((card) => {
        if (!card.classList.contains('hidden')) {
            const checkbox =
                card.querySelector(
                    '.product-checkbox'
                );

            if (!checkbox.disabled) {
                checkbox.checked = false;
            }
        }
    });
}

productSearch.addEventListener(
    'input',
    filterProducts
);

statusFilter.addEventListener(
    'change',
    filterProducts
);
</script>

</body>
</html>
