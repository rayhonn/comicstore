<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ .
    '/../includes/upload_helper.php';
require_once __DIR__ .
    '/../includes/product_validation_helper.php';

require_once __DIR__ .
    '/../includes/csrf.php';

require_admin();

$id = filter_var(
    $_GET['id'] ?? null,
    FILTER_VALIDATE_INT,
    [
        'options' => [
            'min_range' => 1,
        ],
    ]
);

if ($id === false) {
    header('Location: products.php');
    exit;
}

$id = (int) $id;

$stmt = $pdo->prepare("
    SELECT
        p.*,
        pp.physical_stock_quantity,
        pp.physical_low_stock_threshold,
        pe.ebook_file_path,
        pe.ebook_file_format,
        pe.ebook_file_size_mb,
        pe.ebook_download_limit
    FROM products p
    LEFT JOIN product_physical pp ON p.product_id = pp.physical_product_id
    LEFT JOIN product_ebook pe ON p.product_id = pe.ebook_product_id
    WHERE p.product_id = ?
");
$stmt->execute([$id]);
$product = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$product) { header('Location: products.php'); exit; }

$categories = $pdo->query("SELECT * FROM categories ORDER BY category_name")->fetchAll(PDO::FETCH_ASSOC);
$genres = $pdo->query("SELECT * FROM genres ORDER BY genre_name")->fetchAll(PDO::FETCH_ASSOC);

// Get selected genres
$selected_genres = $pdo->prepare("SELECT product_genres_genre_id FROM product_genres WHERE product_genres_product_id = ?");
$selected_genres->execute([$id]);
$selected_genre_ids = $selected_genres->fetchAll(PDO::FETCH_COLUMN);

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    try {
        $existing_type = (string) (
            $product['product_type'] ?? ''
        );

        if (
            !in_array(
                $existing_type,
                [
                    'physical',
                    'ebook',
                ],
                true
            )
        ) {
            throw new ProductInputValidationException(
                'Stored product type is invalid.'
            );
        }

        $validation_input = $_POST;
        $validation_input['product_type'] =
            $existing_type;

        $validated = validateProductFormInput(
            $validation_input,
            $categories,
            $genres
        );

        $title = $validated['title'];
        $series = $validated['series'];
        $volume = $validated['volume'];
        $author = $validated['author'];
        $publisher = $validated['publisher'];
        $isbn = $validated['isbn'];
        $description = $validated['description'];
        $price = $validated['price'];
        $category_id = $validated['category_id'];
        $type = $validated['type'];
        $new_genres =
            $validated['selected_genres'];
        $is_available = isset(
            $_POST['product_is_available']
        ) ? 1 : 0;

        $cover_image = (string) (
            $product['product_cover_image'] ?? ''
        );

        $cover_upload =
            $_FILES['product_cover_image'] ?? null;

        if ($cover_upload !== null) {
            if (!is_array($cover_upload)) {
                throw new ProductInputValidationException(
                    'Product image upload is invalid.'
                );
            }

            $new_cover_image = uploadProductImage(
                $cover_upload,
                '../assets/images/'
            );

            if ($new_cover_image !== '') {
                $cover_image = $new_cover_image;
            }
        }

        $ebook_file = trim(
            (string) (
                $product['ebook_file_path'] ?? ''
            )
        );

        $ebook_file_size =
            $product['ebook_file_size_mb'] ?? 0;

        if ($type === 'ebook') {
            $ebook_upload =
                $_FILES['ebook_file'] ?? null;
            $has_new_ebook_file = false;

            if ($ebook_upload !== null) {
                if (!is_array($ebook_upload)) {
                    throw new ProductInputValidationException(
                        'E-book file upload is invalid.'
                    );
                }

                validateUploadError(
                    $ebook_upload,
                    'E-book file'
                );

                $has_new_ebook_file =
                    $ebook_upload['error'] !==
                        UPLOAD_ERR_NO_FILE &&
                    trim(
                        (string) (
                            $ebook_upload['name'] ?? ''
                        )
                    ) !== '';
            }

            if ($has_new_ebook_file) {
                $submitted_format = strtoupper(
                    pathinfo(
                        (string) $ebook_upload['name'],
                        PATHINFO_EXTENSION
                    )
                );

                if (
                    $submitted_format !==
                    $validated['file_format']
                ) {
                    throw new ProductInputValidationException(
                        'The selected e-book format does not match the uploaded file.'
                    );
                }

                $ebook_file = uploadEbookFile(
                    $ebook_upload,
                    '../assets/ebooks/'
                );

                $ebook_path =
                    '../assets/ebooks/' .
                    $ebook_file;

                $ebook_file_size =
                    file_exists($ebook_path)
                        ? round(
                            filesize($ebook_path) /
                                1048576,
                            2
                        )
                        : 0;
            } else {
                if ($ebook_file === '') {
                    throw new ProductInputValidationException(
                        'An e-book file is required.'
                    );
                }

                $existing_format = strtoupper(
                    pathinfo(
                        $ebook_file,
                        PATHINFO_EXTENSION
                    )
                );

                if (
                    $existing_format !==
                    $validated['file_format']
                ) {
                    throw new ProductInputValidationException(
                        'Upload a matching e-book file before changing its format.'
                    );
                }
            }
        }

        $pdo->beginTransaction();

        $update_product = $pdo->prepare("
            UPDATE products
            SET product_title = ?,
                product_series = ?,
                product_volume_number = ?,
                product_author = ?,
                product_publisher = ?,
                product_isbn = ?,
                product_description = ?,
                product_price = ?,
                product_cover_image = ?,
                product_category_id = ?,
                product_type = ?,
                product_is_available = ?
            WHERE product_id = ?
        ");

        $update_product->execute([
            $title,
            $series,
            $volume,
            $author,
            $publisher,
            $isbn,
            $description,
            $price,
            $cover_image,
            $category_id,
            $type,
            $is_available,
            $id,
        ]);

        if ($type === 'physical') {
            $pdo->prepare("
                DELETE FROM product_ebook
                WHERE ebook_product_id = ?
            ")->execute([$id]);

            $physical_check = $pdo->prepare("
                SELECT physical_product_id
                FROM product_physical
                WHERE physical_product_id = ?
                FOR UPDATE
            ");
            $physical_check->execute([$id]);

            if (
                $physical_check->fetchColumn()
                !== false
            ) {
                $pdo->prepare("
                    UPDATE product_physical
                    SET physical_stock_quantity = ?,
                        physical_low_stock_threshold = ?
                    WHERE physical_product_id = ?
                ")->execute([
                    $validated['stock'],
                    $validated['threshold'],
                    $id,
                ]);
            } else {
                $pdo->prepare("
                    INSERT INTO product_physical (
                        physical_product_id,
                        physical_stock_quantity,
                        physical_low_stock_threshold
                    )
                    VALUES (?, ?, ?, ?, ?)
                ")->execute([
                    $id,
                    $validated['stock'],
                    $validated['threshold'],
                ]);
            }
        } else {
            $pdo->prepare("
                DELETE FROM product_physical
                WHERE physical_product_id = ?
            ")->execute([$id]);

            $ebook_check = $pdo->prepare("
                SELECT ebook_product_id
                FROM product_ebook
                WHERE ebook_product_id = ?
                FOR UPDATE
            ");
            $ebook_check->execute([$id]);

            if (
                $ebook_check->fetchColumn()
                !== false
            ) {
                $pdo->prepare("
                    UPDATE product_ebook
                    SET ebook_file_path = ?,
                        ebook_file_format = ?,
                        ebook_file_size_mb = ?,
                        ebook_download_limit = ?
                    WHERE ebook_product_id = ?
                ")->execute([
                    $ebook_file,
                    $validated['file_format'],
                    $ebook_file_size,
                    $validated['download_limit'],
                    $id,
                ]);
            } else {
                $pdo->prepare("
                    INSERT INTO product_ebook (
                        ebook_product_id,
                        ebook_file_path,
                        ebook_file_format,
                        ebook_file_size_mb,
                        ebook_download_limit
                    )
                    VALUES (?, ?, ?, ?, ?)
                ")->execute([
                    $id,
                    $ebook_file,
                    $validated['file_format'],
                    $ebook_file_size,
                    $validated['download_limit'],
                ]);
            }
        }

        $pdo->prepare("
            DELETE FROM product_genres
            WHERE product_genres_product_id = ?
        ")->execute([$id]);

        $insert_genre = $pdo->prepare("
            INSERT INTO product_genres (
                product_genres_product_id,
                product_genres_genre_id
            )
            VALUES (?, ?)
        ");

        foreach ($new_genres as $genre_id) {
            $insert_genre->execute([
                $id,
                $genre_id,
            ]);
        }

        $pdo->prepare("
            INSERT INTO admin_logs (
                log_admin_id,
                log_action,
                log_target_type,
                log_target_id,
                log_details
            )
            VALUES (
                ?,
                'edit_product',
                'product',
                ?,
                ?
            )
        ")->execute([
            $_SESSION['user_id'],
            $id,
            "Edited product: $title",
        ]);

        $pdo->commit();

        header(
            'Location: products.php?success=1'
        );
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $error = $e->getMessage();
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
        Edit Product - MangaVault Admin
    </title>

    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gray-50 min-h-screen">

    <?php
    include __DIR__ .
        '/../includes/admin_navbar.php';
    ?>

    <main class="max-w-6xl mx-auto px-6 py-8">

        <div class="flex items-center gap-4 mb-6">
            <a
                href="products.php"
                class="w-10 h-10 bg-white rounded-xl shadow-sm flex items-center justify-center text-gray-500 hover:text-red-600 transition-colors"
                aria-label="Back to products"
            >
                ←
            </a>

            <div>
                <h1 class="text-2xl font-black text-gray-800">
                    Edit Product
                </h1>

                <p class="text-sm text-gray-400">
                    Update the product information and availability
                </p>
            </div>
        </div>

        <?php if ($error !== ''): ?>
        <div
            class="bg-red-50 border border-red-200 text-red-700 text-sm px-4 py-3 rounded-xl mb-6"
            role="alert"
        >
            ❌
            <?= htmlspecialchars(
                $error,
                ENT_QUOTES,
                'UTF-8'
            ) ?>
        </div>
        <?php endif; ?>

        <form
            method="POST"
            enctype="multipart/form-data"
            id="editProductForm"
        >
            <?php csrf_field(); ?>

            <div
                class="grid grid-cols-1 lg:grid-cols-3 gap-6"
            >
                <div
                    class="lg:col-span-2 space-y-5"
                >
                    <section
                        class="bg-white rounded-2xl shadow-sm p-6"
                    >
                        <h2
                            class="font-bold text-gray-800 mb-5 flex items-center gap-2"
                        >
                            <span
                                class="w-6 h-6 bg-red-100 rounded-lg flex items-center justify-center text-red-600 text-xs font-black"
                            >
                                1
                            </span>

                            Basic Information
                        </h2>

                        <div class="space-y-4">
                            <div>
                                <label
                                    class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide"
                                >
                                    Title *
                                </label>

                                <input
                                    type="text"
                                    name="product_title"
                                    maxlength="255"
                                    required
                                    value="<?= htmlspecialchars(
                                        (string) $product[
                                            'product_title'
                                        ],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>"
                                    class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 bg-gray-50 focus:bg-white"
                                >
                            </div>

                            <div
                                class="grid grid-cols-1 md:grid-cols-2 gap-4"
                            >
                                <div>
                                    <label
                                        class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide"
                                    >
                                        Series
                                    </label>

                                    <input
                                        type="text"
                                        name="product_series"
                                        maxlength="255"
                                        value="<?= htmlspecialchars(
                                            (string) (
                                                $product[
                                                    'product_series'
                                                ] ?? ''
                                            ),
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>"
                                        class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 bg-gray-50 focus:bg-white"
                                    >
                                </div>

                                <div>
                                    <label
                                        class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide"
                                    >
                                        Volume No.
                                    </label>

                                    <input
                                        type="number"
                                        name="product_volume_number"
                                        min="1"
                                        max="1000000"
                                        value="<?= htmlspecialchars(
                                            (string) (
                                                $product[
                                                    'product_volume_number'
                                                ] ?? ''
                                            ),
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>"
                                        class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 bg-gray-50 focus:bg-white"
                                    >
                                </div>
                            </div>

                            <div
                                class="grid grid-cols-1 md:grid-cols-2 gap-4"
                            >
                                <div>
                                    <label
                                        class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide"
                                    >
                                        Author
                                    </label>

                                    <input
                                        type="text"
                                        name="product_author"
                                        maxlength="255"
                                        value="<?= htmlspecialchars(
                                            (string) (
                                                $product[
                                                    'product_author'
                                                ] ?? ''
                                            ),
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>"
                                        class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 bg-gray-50 focus:bg-white"
                                    >
                                </div>

                                <div>
                                    <label
                                        class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide"
                                    >
                                        Publisher
                                    </label>

                                    <input
                                        type="text"
                                        name="product_publisher"
                                        maxlength="255"
                                        value="<?= htmlspecialchars(
                                            (string) (
                                                $product[
                                                    'product_publisher'
                                                ] ?? ''
                                            ),
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>"
                                        class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 bg-gray-50 focus:bg-white"
                                    >
                                </div>
                            </div>

                            <div
                                class="grid grid-cols-1 md:grid-cols-2 gap-4"
                            >
                                <div>
                                    <label
                                        class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide"
                                    >
                                        ISBN
                                    </label>

                                    <input
                                        type="text"
                                        name="product_isbn"
                                        maxlength="20"
                                        value="<?= htmlspecialchars(
                                            (string) (
                                                $product[
                                                    'product_isbn'
                                                ] ?? ''
                                            ),
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>"
                                        class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 bg-gray-50 focus:bg-white"
                                    >
                                </div>

                                <div>
                                    <label
                                        class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide"
                                    >
                                        Price (RM) *
                                    </label>

                                    <input
                                        type="number"
                                        name="product_price"
                                        step="0.01"
                                        min="0"
                                        required
                                        value="<?= htmlspecialchars(
                                            (string) $product[
                                                'product_price'
                                            ],
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>"
                                        class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 bg-gray-50 focus:bg-white"
                                    >
                                </div>
                            </div>

                            <div>
                                <label
                                    class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide"
                                >
                                    Description
                                </label>

                                <textarea
                                    name="product_description"
                                    rows="4"
                                    maxlength="5000"
                                    class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 bg-gray-50 focus:bg-white resize-none"
                                ><?= htmlspecialchars(
                                    (string) (
                                        $product[
                                            'product_description'
                                        ] ?? ''
                                    ),
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?></textarea>
                            </div>
                        </div>
                    </section>

                    <section
                        class="bg-white rounded-2xl shadow-sm p-6"
                    >
                        <h2
                            class="font-bold text-gray-800 mb-5 flex items-center gap-2"
                        >
                            <span
                                class="w-6 h-6 bg-red-100 rounded-lg flex items-center justify-center text-red-600 text-xs font-black"
                            >
                                2
                            </span>

                            Category & Genres
                        </h2>

                        <div class="space-y-4">
                            <div>
                                <label
                                    class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide"
                                >
                                    Category
                                </label>

                                <select
                                    name="product_category_id"
                                    class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 bg-gray-50 focus:bg-white"
                                >
                                    <option value="">
                                        -- Select Category --
                                    </option>

                                    <?php foreach ($categories as $cat): ?>
                                    <option
                                        value="<?= (int) $cat[
                                            'category_id'
                                        ] ?>"
                                        <?= (int) (
                                            $product[
                                                'product_category_id'
                                            ] ?? 0
                                        ) ===
                                        (int) $cat[
                                            'category_id'
                                        ]
                                            ? 'selected'
                                            : '' ?>
                                    >
                                        <?= htmlspecialchars(
                                            (string) $cat[
                                                'category_name'
                                            ],
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div>
                                <label
                                    class="block text-xs font-semibold text-gray-500 mb-2 uppercase tracking-wide"
                                >
                                    Genres
                                </label>

                                <div class="flex flex-wrap gap-2">
                                    <?php foreach ($genres as $genre): ?>
                                    <label
                                        class="flex items-center gap-1.5 px-3 py-1.5 border-2 border-gray-100 rounded-xl text-xs font-medium text-gray-600 cursor-pointer hover:border-red-300 hover:bg-red-50 transition-colors has-[:checked]:border-red-500 has-[:checked]:bg-red-50 has-[:checked]:text-red-600"
                                    >
                                        <input
                                            type="checkbox"
                                            name="genres[]"
                                            value="<?= (int) $genre[
                                                'genre_id'
                                            ] ?>"
                                            <?= in_array(
                                                $genre['genre_id'],
                                                $selected_genre_ids
                                            )
                                                ? 'checked'
                                                : '' ?>
                                            class="accent-red-600 w-3 h-3"
                                        >

                                        <?= htmlspecialchars(
                                            (string) $genre[
                                                'genre_name'
                                            ],
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section
                        class="bg-white rounded-2xl shadow-sm p-6"
                    >
                        <h2
                            class="font-bold text-gray-800 mb-5 flex items-center gap-2"
                        >
                            <span
                                class="w-6 h-6 bg-red-100 rounded-lg flex items-center justify-center text-red-600 text-xs font-black"
                            >
                                3
                            </span>

                            Product Details
                        </h2>

                        <input
                            type="hidden"
                            name="product_type"
                            value="<?= htmlspecialchars(
                                (string) $product[
                                    'product_type'
                                ],
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>"
                        >

                        <div
                            class="bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 mb-5"
                        >
                            <p
                                class="text-xs uppercase tracking-wide font-semibold text-gray-400"
                            >
                                Product Type
                            </p>

                            <p
                                class="font-bold text-gray-800 mt-1"
                            >
                                <?= $product[
                                    'product_type'
                                ] === 'ebook'
                                    ? '📱 E-Book'
                                    : '📦 Physical' ?>
                            </p>

                            <p class="text-xs text-gray-400 mt-1">
                                Product type cannot be changed after creation.
                            </p>
                        </div>

                        <?php if (
                            $product['product_type'] ===
                            'physical'
                        ): ?>
                        <div
                            class="grid grid-cols-1 md:grid-cols-2 gap-4"
                        >
                            <div>
                                <label
                                    class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide"
                                >
                                    Stock Quantity
                                </label>

                                <input
                                    type="number"
                                    name="physical_stock_quantity"
                                    min="0"
                                    max="1000000"
                                    required
                                    value="<?= (int) (
                                        $product[
                                            'physical_stock_quantity'
                                        ] ?? 0
                                    ) ?>"
                                    class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 bg-gray-50 focus:bg-white"
                                >
                            </div>

                            <div>
                                <label
                                    class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide"
                                >
                                    Low Stock Alert
                                </label>

                                <input
                                    type="number"
                                    name="physical_low_stock_threshold"
                                    min="0"
                                    max="1000000"
                                    required
                                    value="<?= (int) (
                                        $product[
                                            'physical_low_stock_threshold'
                                        ] ?? 5
                                    ) ?>"
                                    class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 bg-gray-50 focus:bg-white"
                                >
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="space-y-4">
                            <div>
                                <label
                                    class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide"
                                >
                                    Replace E-Book File
                                </label>

                                <input
                                    type="file"
                                    name="ebook_file"
                                    accept=".pdf,.epub"
                                    class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm bg-gray-50 file:mr-3 file:py-1 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-red-50 file:text-red-600"
                                >

                                <?php if (
                                    !empty(
                                        $product[
                                            'ebook_file_path'
                                        ]
                                    )
                                ): ?>
                                <p class="text-xs text-gray-400 mt-2">
                                    Current:
                                    <?= htmlspecialchars(
                                        (string) $product[
                                            'ebook_file_path'
                                        ],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </p>
                                <?php endif; ?>
                            </div>

                            <div
                                class="grid grid-cols-1 md:grid-cols-2 gap-4"
                            >
                                <div>
                                    <label
                                        class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide"
                                    >
                                        File Format
                                    </label>

                                    <select
                                        name="ebook_file_format"
                                        class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 bg-gray-50 focus:bg-white"
                                    >
                                        <option
                                            value="PDF"
                                            <?= (
                                                $product[
                                                    'ebook_file_format'
                                                ] ?? ''
                                            ) === 'PDF'
                                                ? 'selected'
                                                : '' ?>
                                        >
                                            PDF
                                        </option>

                                        <option
                                            value="EPUB"
                                            <?= (
                                                $product[
                                                    'ebook_file_format'
                                                ] ?? ''
                                            ) === 'EPUB'
                                                ? 'selected'
                                                : '' ?>
                                        >
                                            EPUB
                                        </option>
                                    </select>
                                </div>

                                <div>
                                    <label
                                        class="block text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide"
                                    >
                                        Download Limit
                                    </label>

                                    <input
                                        type="number"
                                        name="ebook_download_limit"
                                        min="1"
                                        max="1000"
                                        required
                                        value="<?= (int) (
                                            $product[
                                                'ebook_download_limit'
                                            ] ?? 3
                                        ) ?>"
                                        class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl text-sm focus:outline-none focus:border-red-400 bg-gray-50 focus:bg-white"
                                    >
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </section>
                </div>

                <aside class="space-y-5">
                    <section
                        class="bg-white rounded-2xl shadow-sm p-6"
                    >
                        <h2
                            class="font-bold text-gray-800 mb-4 flex items-center gap-2"
                        >
                            <span
                                class="w-6 h-6 bg-red-100 rounded-lg flex items-center justify-center text-red-600 text-xs font-black"
                            >
                                4
                            </span>

                            Cover Image
                        </h2>

                        <div
                            class="w-full h-72 bg-gray-50 border-2 border-dashed border-gray-200 rounded-xl flex items-center justify-center mb-3 overflow-hidden"
                        >
                            <img
                                id="coverPreviewImg"
                                src="<?= !empty(
                                    $product[
                                        'product_cover_image'
                                    ]
                                )
                                    ? '../assets/images/' .
                                        htmlspecialchars(
                                            (string) $product[
                                                'product_cover_image'
                                            ],
                                            ENT_QUOTES,
                                            'UTF-8'
                                        )
                                    : '' ?>"
                                alt="Product cover preview"
                                class="<?= empty(
                                    $product[
                                        'product_cover_image'
                                    ]
                                )
                                    ? 'hidden '
                                    : '' ?>w-full h-full object-contain p-3"
                            >

                            <div
                                id="coverPlaceholder"
                                class="<?= !empty(
                                    $product[
                                        'product_cover_image'
                                    ]
                                )
                                    ? 'hidden '
                                    : '' ?>text-center"
                            >
                                <div class="text-3xl mb-2">
                                    🖼️
                                </div>

                                <p class="text-xs text-gray-400">
                                    No cover image
                                </p>
                            </div>
                        </div>

                        <input
                            type="file"
                            name="product_cover_image"
                            accept="image/*"
                            onchange="previewCover(this)"
                            class="w-full text-xs text-gray-500 file:mr-3 file:py-2 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-red-50 file:text-red-600 hover:file:bg-red-100"
                        >

                        <p class="text-xs text-gray-400 mt-2">
                            Leave empty to keep the current image.
                        </p>
                    </section>

                    <section
                        class="bg-white rounded-2xl shadow-sm p-6"
                    >
                        <h2
                            class="font-bold text-gray-800 mb-4 flex items-center gap-2"
                        >
                            <span
                                class="w-6 h-6 bg-red-100 rounded-lg flex items-center justify-center text-red-600 text-xs font-black"
                            >
                                5
                            </span>

                            Visibility
                        </h2>

                        <label
                            class="flex items-center gap-3 cursor-pointer"
                        >
                            <div class="relative">
                                <input
                                    type="checkbox"
                                    name="product_is_available"
                                    id="availableToggle"
                                    <?= (int) $product[
                                        'product_is_available'
                                    ] === 1
                                        ? 'checked'
                                        : '' ?>
                                    class="sr-only"
                                >

                                <div
                                    class="toggle-bg w-11 h-6 bg-gray-200 rounded-full transition-colors duration-200"
                                ></div>

                                <div
                                    class="toggle-dot absolute top-0.5 left-0.5 w-5 h-5 bg-white rounded-full shadow transition-transform duration-200"
                                ></div>
                            </div>

                            <div>
                                <p
                                    class="text-sm font-semibold text-gray-700"
                                >
                                    Show to customers
                                </p>

                                <p class="text-xs text-gray-400">
                                    Product will be visible in the store
                                </p>
                            </div>
                        </label>
                    </section>

                    <button
                        type="submit"
                        class="w-full bg-red-600 hover:bg-red-700 text-white font-black py-4 rounded-2xl text-sm transition-all shadow-lg shadow-red-100"
                    >
                        Update Product
                    </button>

                    <a
                        href="products.php"
                        class="block text-center text-sm text-gray-400 hover:text-red-600 transition-colors"
                    >
                        Cancel
                    </a>
                </aside>
            </div>
        </form>
    </main>

    <style>
        #availableToggle:checked ~ .toggle-bg {
            background: #c0392b;
        }

        #availableToggle:checked ~ .toggle-dot {
            transform: translateX(20px);
        }
    </style>

    <script>
        function previewCover(input) {
            if (
                !input.files ||
                !input.files[0]
            ) {
                return;
            }

            const reader = new FileReader();

            reader.onload = event => {
                const preview =
                    document.getElementById(
                        'coverPreviewImg'
                    );

                const placeholder =
                    document.getElementById(
                        'coverPlaceholder'
                    );

                preview.src =
                    event.target.result;

                preview.classList.remove(
                    'hidden'
                );

                placeholder.classList.add(
                    'hidden'
                );
            };

            reader.readAsDataURL(
                input.files[0]
            );
        }
    </script>

</body>
</html>