<?php

require_once __DIR__ . '/../includes/auth.php';
require_admin();

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';

$supplier_id = filter_input(
    INPUT_GET,
    'id',
    FILTER_VALIDATE_INT,
    [
        'options' => [
            'min_range' => 1,
        ],
    ]
);

if ($supplier_id === false || $supplier_id === null) {
    header('Location: suppliers.php');
    exit;
}

$supplier_stmt = $pdo->prepare(
    "SELECT
        supplier_id,
        supplier_name,
        supplier_status
     FROM suppliers
     WHERE supplier_id = ?"
);
$supplier_stmt->execute([
    $supplier_id,
]);

$supplier = $supplier_stmt->fetch(PDO::FETCH_ASSOC);

if (!$supplier) {
    header('Location: suppliers.php');
    exit;
}

$error = '';
$success = '';

if (isset($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_supplier_products'])) {
    csrf_verify();

    $raw_product_ids = $_POST['product_ids'] ?? [];

    if (!is_array($raw_product_ids)) {
        $error = 'Invalid product selection.';
    }

    $selected_product_ids = [];

    if ($error === '') {
        foreach ($raw_product_ids as $raw_product_id) {
            $product_id = filter_var(
                $raw_product_id,
                FILTER_VALIDATE_INT,
                [
                    'options' => [
                        'min_range' => 1,
                    ],
                ]
            );

            if ($product_id === false) {
                $error = 'One or more selected products are invalid.';
                break;
            }

            $selected_product_ids[(int) $product_id] = true;
        }
    }

    $selected_product_ids = array_keys(
        $selected_product_ids
    );

    if ($error === '') {
        try {
            $pdo->beginTransaction();

            $lock_supplier = $pdo->prepare(
                "SELECT
                    supplier_id,
                    supplier_name,
                    supplier_status
                 FROM suppliers
                 WHERE supplier_id = ?
                 FOR UPDATE"
            );
            $lock_supplier->execute([
                $supplier_id,
            ]);

            $locked_supplier = $lock_supplier->fetch(
                PDO::FETCH_ASSOC
            );

            if (!$locked_supplier) {
                throw new RuntimeException(
                    'Supplier not found.'
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

                $valid_products_stmt = $pdo->prepare(
                    "SELECT product_id
                     FROM products
                     WHERE product_id IN ($placeholders)
                     AND product_type = 'physical'
                     ORDER BY product_id
                     FOR UPDATE"
                );
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

            $existing_mappings_stmt = $pdo->prepare(
                "SELECT
                    sp.supplier_product_product_id,
                    sp.supplier_product_status,
                    p.product_title
                 FROM supplier_products sp
                 INNER JOIN products p
                    ON p.product_id =
                        sp.supplier_product_product_id
                 WHERE sp.supplier_product_supplier_id = ?
                 ORDER BY sp.supplier_product_product_id
                 FOR UPDATE"
            );
            $existing_mappings_stmt->execute([
                $supplier_id,
            ]);

            $existing_mappings = $existing_mappings_stmt->fetchAll(
                PDO::FETCH_ASSOC
            );

            $selected_lookup = array_fill_keys(
                $selected_product_ids,
                true
            );

            $open_rfq_stmt = $pdo->prepare(
                "SELECT r.rfq_number
                 FROM rfq r
                 INNER JOIN rfq_suppliers rs
                    ON rs.rfq_supplier_rfq_id = r.rfq_id
                 INNER JOIN rfq_items ri
                    ON ri.rfq_item_rfq_id = r.rfq_id
                 WHERE rs.rfq_supplier_supplier_id = ?
                 AND ri.rfq_item_product_id = ?
                 AND r.rfq_status IN ('pending', 'quoted')
                 ORDER BY r.rfq_id
                 LIMIT 1
                 FOR UPDATE"
            );

            foreach ($existing_mappings as $mapping) {
                $product_id = (int) $mapping[
                    'supplier_product_product_id'
                ];

                if (
                    $mapping['supplier_product_status'] !== 'active' ||
                    isset($selected_lookup[$product_id])
                ) {
                    continue;
                }

                $open_rfq_stmt->execute([
                    $supplier_id,
                    $product_id,
                ]);

                $open_rfq_number = $open_rfq_stmt->fetchColumn();

                if ($open_rfq_number !== false) {
                    throw new RuntimeException(
                        'Cannot remove ' .
                        $mapping['product_title'] .
                        ' because it is still used in open RFQ ' .
                        $open_rfq_number .
                        ' for this supplier.'
                    );
                }
            }

            $activate_mapping = $pdo->prepare(
                "INSERT INTO supplier_products (
                    supplier_product_supplier_id,
                    supplier_product_product_id,
                    supplier_product_status
                 )
                 VALUES (?, ?, 'active')
                 ON DUPLICATE KEY UPDATE
                    supplier_product_status = 'active'"
            );

            foreach ($selected_product_ids as $product_id) {
                $activate_mapping->execute([
                    $supplier_id,
                    $product_id,
                ]);
            }

            $deactivate_mapping = $pdo->prepare(
                "UPDATE supplier_products
                 SET supplier_product_status = 'inactive'
                 WHERE supplier_product_supplier_id = ?
                 AND supplier_product_product_id = ?
                 AND supplier_product_status = 'active'"
            );

            foreach ($existing_mappings as $mapping) {
                $product_id = (int) $mapping[
                    'supplier_product_product_id'
                ];

                if (
                    $mapping['supplier_product_status'] === 'active' &&
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
                'Supplier product mapping updated successfully.';

            header(
                'Location: supplier_products.php?id=' .
                $supplier_id
            );
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if ($e instanceof RuntimeException) {
                $error = $e->getMessage();
            } else {
                app_error_log(
                    'Supplier product mapping update failed: ' .
                    $e->getMessage()
                );

                $error =
                    'Unable to update the supplier product mapping. Please try again.';
            }
        }
    }
}

$products_stmt = $pdo->prepare(
    "SELECT
        p.product_id,
        p.product_title,
        p.product_series,
        p.product_volume_number,
        p.product_publisher,
        p.product_cover_image,
        p.product_is_available,
        sp.supplier_product_status,
        sp.supplier_product_created_at,
        sp.supplier_product_updated_at
     FROM products p
     LEFT JOIN supplier_products sp
        ON sp.supplier_product_product_id = p.product_id
        AND sp.supplier_product_supplier_id = ?
     WHERE p.product_type = 'physical'
     ORDER BY
        p.product_is_available DESC,
        p.product_title,
        p.product_volume_number,
        p.product_id"
);
$products_stmt->execute([
    $supplier_id,
]);

$products = $products_stmt->fetchAll(PDO::FETCH_ASSOC);

$active_product_count = 0;

foreach ($products as $product) {
    if ($product['supplier_product_status'] === 'active') {
        $active_product_count++;
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
    <title>
        Supplier Products - MangaVault Admin
    </title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 min-h-screen">

    <?php include '../includes/admin_navbar.php'; ?>

    <div class="max-w-6xl mx-auto px-6 py-8">

        <p class="text-sm text-gray-400 mb-6">
            <a
                href="suppliers.php"
                class="hover:text-red-600 transition-colors"
            >
                Suppliers
            </a>
            <span class="mx-2">›</span>
            <span class="text-gray-600">
                <?= htmlspecialchars($supplier['supplier_name']) ?>
            </span>
        </p>

        <div
            class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-8"
        >
            <div>
                <div class="flex items-center gap-3">
                    <h1 class="text-2xl font-black text-gray-800">
                        📚 Supplier Product Mapping
                    </h1>

                    <span
                        class="<?= $supplier['supplier_status'] === 'active'
                            ? 'bg-green-100 text-green-700'
                            : 'bg-gray-100 text-gray-500' ?>
                               text-xs px-3 py-1 rounded-full font-semibold capitalize"
                    >
                        <?= htmlspecialchars($supplier['supplier_status']) ?>
                    </span>
                </div>

                <p class="text-gray-500 text-sm mt-1">
                    Manage the physical products supplied by
                    <span class="font-semibold text-gray-700">
                        <?= htmlspecialchars($supplier['supplier_name']) ?>
                    </span>.
                </p>
            </div>

            <div class="bg-white rounded-xl shadow-sm px-5 py-3">
                <p class="text-xs text-gray-400 uppercase font-semibold">
                    Active Products
                </p>
                <p class="text-xl font-black text-gray-800">
                    <?= $active_product_count ?>
                </p>
            </div>
        </div>

        <?php if ($success): ?>
        <div
            class="bg-green-50 border border-green-200 text-green-700 text-sm px-4 py-3 rounded-xl mb-6"
        >
            ✅ <?= htmlspecialchars($success) ?>
        </div>
        <?php endif; ?>

        <?php if ($error): ?>
        <div
            class="bg-red-50 border border-red-200 text-red-600 text-sm px-4 py-3 rounded-xl mb-6"
        >
            ❌ <?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>

        <?php if ($supplier['supplier_status'] !== 'active'): ?>
        <div
            class="bg-yellow-50 border border-yellow-200 text-yellow-700 text-sm px-4 py-3 rounded-xl mb-6"
        >
            ⚠️ This supplier is currently inactive. Product mappings can still
            be maintained, but the supplier will not be eligible for new RFQs
            until the supplier account is reactivated.
        </div>
        <?php endif; ?>

        <form method="POST">
            <?php csrf_field(); ?>

            <input
                type="hidden"
                name="save_supplier_products"
                value="1"
            >

            <div class="bg-white rounded-2xl shadow-sm overflow-hidden">

                <div
                    class="p-5 border-b border-gray-100 flex flex-col lg:flex-row lg:items-center lg:justify-between gap-3"
                >
                    <div>
                        <h2 class="font-bold text-gray-800">
                            Supplied Products
                        </h2>
                        <p class="text-xs text-gray-400 mt-1">
                            Only active mappings will be eligible during RFQ
                            supplier selection.
                        </p>
                    </div>

                    <div
                        class="flex flex-col sm:flex-row sm:items-center gap-2"
                    >
                        <input
                            type="text"
                            id="productSearch"
                            placeholder="Search products..."
                            class="w-full sm:w-64 px-4 py-2 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 transition-colors"
                        >

                        <button
                            type="button"
                            onclick="selectVisibleProducts()"
                            class="whitespace-nowrap px-3 py-2 bg-gray-100 hover:bg-gray-200 text-gray-600 rounded-xl text-xs font-semibold transition-colors"
                        >
                            Select Visible
                        </button>

                        <button
                            type="button"
                            onclick="clearVisibleProducts()"
                            class="whitespace-nowrap px-3 py-2 bg-gray-100 hover:bg-gray-200 text-gray-600 rounded-xl text-xs font-semibold transition-colors"
                        >
                            Clear Visible
                        </button>
                    </div>
                </div>

                <?php if (!$products): ?>
                <div class="text-center py-16">
                    <div class="text-5xl mb-4">📚</div>
                    <p class="text-gray-400">
                        No physical products are available.
                    </p>
                </div>
                <?php else: ?>

                <div
                    id="productGrid"
                    class="grid grid-cols-1 md:grid-cols-2 gap-3 p-5"
                >
                    <?php foreach ($products as $product): ?>
                    <?php
                    $is_mapped =
                        $product['supplier_product_status'] === 'active';

                    $search_text = strtolower(
                        implode(
                            ' ',
                            [
                                $product['product_title'] ?? '',
                                $product['product_series'] ?? '',
                                $product['product_publisher'] ?? '',
                            ]
                        )
                    );
                    ?>

                    <label
                        class="product-card flex gap-4 p-4 border-2 rounded-xl cursor-pointer transition-colors
                               <?= $is_mapped
                                   ? 'border-red-200 bg-red-50/40'
                                   : 'border-gray-100 hover:border-red-200' ?>"
                        data-search="<?= htmlspecialchars(
                            $search_text,
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>"
                    >
                        <div class="pt-1">
                            <input
                                type="checkbox"
                                name="product_ids[]"
                                value="<?= (int) $product['product_id'] ?>"
                                class="product-checkbox accent-red-600 w-4 h-4"
                                <?= $is_mapped ? 'checked' : '' ?>
                            >
                        </div>

                        <div
                            class="w-12 h-16 bg-gray-100 rounded-lg overflow-hidden flex-shrink-0"
                        >
                            <?php if (!empty($product['product_cover_image'])): ?>
                            <img
                                src="../assets/images/<?= htmlspecialchars(
                                    $product['product_cover_image']
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
                                        class="font-semibold text-sm text-gray-800 truncate"
                                    >
                                        <?= htmlspecialchars(
                                            $product['product_title']
                                        ) ?>
                                    </p>

                                    <p
                                        class="text-xs text-gray-400 mt-0.5 truncate"
                                    >
                                        <?= htmlspecialchars(
                                            $product['product_series'] ?: 'No series'
                                        ) ?>

                                        <?php if (
                                            $product['product_volume_number'] !== null
                                        ): ?>
                                            · Vol.
                                            <?= (int) $product[
                                                'product_volume_number'
                                            ] ?>
                                        <?php endif; ?>
                                    </p>

                                    <?php if (
                                        !empty($product['product_publisher'])
                                    ): ?>
                                    <p
                                        class="text-xs text-gray-400 mt-0.5 truncate"
                                    >
                                        <?= htmlspecialchars(
                                            $product['product_publisher']
                                        ) ?>
                                    </p>
                                    <?php endif; ?>
                                </div>

                                <?php if (
                                    (int) $product['product_is_available'] === 1
                                ): ?>
                                <span
                                    class="bg-green-100 text-green-700 text-[10px] px-2 py-1 rounded-full font-semibold whitespace-nowrap"
                                >
                                    Available
                                </span>
                                <?php else: ?>
                                <span
                                    class="bg-gray-100 text-gray-500 text-[10px] px-2 py-1 rounded-full font-semibold whitespace-nowrap"
                                >
                                    Unavailable
                                </span>
                                <?php endif; ?>
                            </div>

                            <?php if ($is_mapped): ?>
                            <p
                                class="text-xs text-red-600 font-semibold mt-2"
                            >
                                Currently supplied
                            </p>
                            <?php else: ?>
                            <p class="text-xs text-gray-300 mt-2">
                                Not currently supplied
                            </p>
                            <?php endif; ?>
                        </div>
                    </label>
                    <?php endforeach; ?>
                </div>

                <div
                    id="noSearchResults"
                    class="hidden text-center py-12 border-t border-gray-100"
                >
                    <p class="text-gray-400 text-sm">
                        No products match your search.
                    </p>
                </div>

                <?php endif; ?>

                <div
                    class="px-5 py-4 bg-gray-50 border-t border-gray-100 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3"
                >
                    <p class="text-xs text-gray-400">
                        Removing a product from an open RFQ supplier mapping
                        will be blocked to protect procurement integrity.
                    </p>

                    <div class="flex gap-2">
                        <a
                            href="suppliers.php"
                            class="px-5 py-2.5 border-2 border-gray-200 hover:bg-white text-gray-600 font-semibold rounded-xl text-sm transition-colors"
                        >
                            Back
                        </a>

                        <button
                            type="submit"
                            class="px-5 py-2.5 bg-red-600 hover:bg-red-700 text-white font-bold rounded-xl text-sm transition-colors"
                        >
                            Save Product Mapping
                        </button>
                    </div>
                </div>

            </div>
        </form>

    </div>

    <script>
    const productSearch =
        document.getElementById('productSearch');

    const productCards =
        Array.from(
            document.querySelectorAll('.product-card')
        );

    function filterProducts() {
        const query =
            productSearch.value
                .trim()
                .toLowerCase();

        let visibleCount = 0;

        productCards.forEach((card) => {
            const searchText =
                card.dataset.search || '';

            const isVisible =
                searchText.includes(query);

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

                checkbox.checked = true;
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

                checkbox.checked = false;
            }
        });
    }

    productSearch.addEventListener(
        'input',
        filterProducts
    );
    </script>

</body>
</html>