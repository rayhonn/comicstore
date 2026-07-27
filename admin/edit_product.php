<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ .
    '/../includes/upload_helper.php';
require_once __DIR__ .
    '/../includes/product_validation_helper.php';

require_admin_or_staff();

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
    SELECT p.*, pp.physical_stock_quantity, pp.physical_low_stock_threshold, pp.physical_weight, pp.physical_dimensions,
    pe.ebook_file_path, pe.ebook_file_format, pe.ebook_file_size_mb, pe.ebook_download_limit
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
                        physical_low_stock_threshold = ?,
                        physical_weight = ?,
                        physical_dimensions = ?
                    WHERE physical_product_id = ?
                ")->execute([
                    $validated['stock'],
                    $validated['threshold'],
                    $validated['weight'],
                    $validated['dimensions'],
                    $id,
                ]);
            } else {
                $pdo->prepare("
                    INSERT INTO product_physical (
                        physical_product_id,
                        physical_stock_quantity,
                        physical_low_stock_threshold,
                        physical_weight,
                        physical_dimensions
                    )
                    VALUES (?, ?, ?, ?, ?)
                ")->execute([
                    $id,
                    $validated['stock'],
                    $validated['threshold'],
                    $validated['weight'],
                    $validated['dimensions'],
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
    <title>Edit Product - Admin</title>
</head>
<body>
    <h1>Edit Product</h1>
    <a href="products.php">← Back to Products</a>
    <hr>

    <?php if ($error): ?><p style="color:red;"><?= htmlspecialchars($error) ?></p><?php endif; ?>

    <form method="POST" enctype="multipart/form-data" style="max-width:700px;">
        <table cellpadding="8">
            <tr>
                <td><b>Title *</b></td>
                <td><input type="text" name="product_title" style="width:350px;" value="<?= htmlspecialchars($product['product_title']) ?>" required></td>
            </tr>
            <tr>
                <td><b>Series</b></td>
                <td><input type="text" name="product_series" style="width:350px;" value="<?= htmlspecialchars($product['product_series'] ?? '') ?>"></td>
            </tr>
            <tr>
                <td><b>Volume</b></td>
                <td><input type="number" name="product_volume_number" min="1" value="<?= $product['product_volume_number'] ?? '' ?>"></td>
            </tr>
            <tr>
                <td><b>Author</b></td>
                <td><input type="text" name="product_author" style="width:350px;" value="<?= htmlspecialchars($product['product_author'] ?? '') ?>"></td>
            </tr>
            <tr>
                <td><b>Publisher</b></td>
                <td><input type="text" name="product_publisher" style="width:350px;" value="<?= htmlspecialchars($product['product_publisher'] ?? '') ?>"></td>
            </tr>
            <tr>
                <td><b>ISBN</b></td>
                <td><input type="text" name="product_isbn" style="width:200px;" value="<?= htmlspecialchars($product['product_isbn'] ?? '') ?>"></td>
            </tr>
            <tr>
                <td><b>Description</b></td>
                <td><textarea name="product_description" rows="4" style="width:350px;"><?= htmlspecialchars($product['product_description'] ?? '') ?></textarea></td>
            </tr>
            <tr>
                <td><b>Price (RM) *</b></td>
                <td><input type="number" name="product_price" step="0.01" min="0" value="<?= $product['product_price'] ?>" required></td>
            </tr>
            <tr>
                <td><b>Category</b></td>
                <td>
                    <select name="product_category_id">
                        <option value="">-- Select Category --</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat['category_id'] ?>" <?= $product['product_category_id'] == $cat['category_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cat['category_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr>
                <td><b>Genres</b></td>
                <td>
                    <?php foreach ($genres as $genre): ?>
                        <label style="margin-right:10px;">
                            <input type="checkbox" name="genres[]" value="<?= $genre['genre_id'] ?>"
                                <?= in_array($genre['genre_id'], $selected_genre_ids) ? 'checked' : '' ?>>
                            <?= htmlspecialchars($genre['genre_name']) ?>
                        </label>
                    <?php endforeach; ?>
                </td>
            </tr>
            <tr>
                <td><b>Product Type</b></td>
                <td>
                    <input
                        type="hidden"
                        name="product_type"
                        value="<?= htmlspecialchars(
                            (string) $product['product_type']
                        ) ?>"
                    >
                    <strong>
                        <?= $product['product_type'] === 'ebook'
                            ? 'E-Book'
                            : 'Physical' ?>
                    </strong>
                    <br>
                    <small>
                        Product type cannot be changed after creation.
                    </small>
                </td>
            </tr>

            <!-- Physical fields -->
            <tr id="row_stock" <?= $product['product_type'] === 'ebook' ? 'style="display:none;"' : '' ?>>
                <td><b>Stock Quantity</b></td>
                <td><input type="number" name="physical_stock_quantity" min="0" value="<?= $product['physical_stock_quantity'] ?? 0 ?>"></td>
            </tr>
            <tr id="row_threshold" <?= $product['product_type'] === 'ebook' ? 'style="display:none;"' : '' ?>>
                <td><b>Low Stock Threshold</b></td>
                <td><input type="number" name="physical_low_stock_threshold" min="0" value="<?= $product['physical_low_stock_threshold'] ?? 5 ?>"></td>
            </tr>
            <tr id="row_weight" <?= $product['product_type'] === 'ebook' ? 'style="display:none;"' : '' ?>>
                <td><b>Weight (kg)</b></td>
                <td><input type="number" name="physical_weight" step="0.01" min="0" value="<?= $product['physical_weight'] ?? '' ?>"></td>
            </tr>
            <tr id="row_dimensions" <?= $product['product_type'] === 'ebook' ? 'style="display:none;"' : '' ?>>
                <td><b>Dimensions</b></td>
                <td><input type="text" name="physical_dimensions" value="<?= htmlspecialchars($product['physical_dimensions'] ?? '') ?>" style="width:200px;"></td>
            </tr>

            <!-- Ebook fields -->
            <tr id="row_ebook_file" <?= $product['product_type'] === 'physical' ? 'style="display:none;"' : '' ?>>
                <td><b>E-Book File</b></td>
                <td>
                    <input type="file" name="ebook_file" accept=".pdf,.epub">
                    <?php if ($product['ebook_file_path']): ?>
                        <br><small>Current: <?= htmlspecialchars($product['ebook_file_path']) ?></small>
                    <?php endif; ?>
                </td>
            </tr>
            <tr id="row_ebook_format" <?= $product['product_type'] === 'physical' ? 'style="display:none;"' : '' ?>>
                <td><b>File Format</b></td>
                <td>
                    <select name="ebook_file_format">
                        <option value="PDF" <?= ($product['ebook_file_format'] ?? '') === 'PDF' ? 'selected' : '' ?>>PDF</option>
                        <option value="EPUB" <?= ($product['ebook_file_format'] ?? '') === 'EPUB' ? 'selected' : '' ?>>EPUB</option>
                    </select>
                </td>
            </tr>
            <tr id="row_download_limit" <?= $product['product_type'] === 'physical' ? 'style="display:none;"' : '' ?>>
                <td><b>Download Limit</b></td>
                <td><input type="number" name="ebook_download_limit" min="1" value="<?= $product['ebook_download_limit'] ?? 3 ?>"></td>
            </tr>

            <tr>
                <td><b>Cover Image</b></td>
                <td>
                    <input type="file" name="product_cover_image" accept="image/*">
                    <?php if ($product['product_cover_image']): ?>
                        <br><img src="../assets/images/<?= htmlspecialchars($product['product_cover_image']) ?>" style="width:60px; margin-top:5px;">
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <td><b>Available</b></td>
                <td><input type="checkbox" name="product_is_available" <?= $product['product_is_available'] ? 'checked' : '' ?>> Show to customers</td>
            </tr>
            <tr>
                <td></td>
                <td><button type="submit" style="padding:10px 30px; background:#e74c3c; color:white; border:none; border-radius:4px; cursor:pointer; font-size:16px;">Update Product</button></td>
            </tr>
        </table>
    </form>
</body>
</html>