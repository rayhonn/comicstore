<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'staff'])) {
    header('Location: login.php');
    exit;
}
require_once '../includes/db.php';
require_once '../includes/upload_helper.php';

$id = $_GET['id'] ?? null;
if (!$id) { header('Location: products.php'); exit; }

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
    $title = trim($_POST['product_title']);
    $series = trim($_POST['product_series']);
    $volume = $_POST['product_volume_number'] ?: null;
    $author = trim($_POST['product_author']);
    $publisher = trim($_POST['product_publisher']);
    $isbn = trim($_POST['product_isbn']);
    $description = trim($_POST['product_description']);
    $price = $_POST['product_price'];
    $category_id = $_POST['product_category_id'] ?: null;
    $type = $_POST['product_type'];
    $is_available = isset($_POST['product_is_available']) ? 1 : 0;
    $new_genres = $_POST['genres'] ?? [];

    if (empty($title) || empty($price)) {
        $error = "Title and price are required.";
    } else {
        // Handle cover image
        $cover_image = $product['product_cover_image'];

        if (isset($_FILES['product_cover_image']) && $_FILES['product_cover_image']['error'] !== UPLOAD_ERR_NO_FILE) {
            $upload_dir = '../assets/images/';
            $new_cover_image = uploadProductImage($_FILES['product_cover_image'], $upload_dir);

            if ($new_cover_image !== '') {
                $cover_image = $new_cover_image;
            }
        }

        // Update products table
        $pdo->prepare("UPDATE products SET product_title=?, product_series=?, product_volume_number=?, product_author=?, product_publisher=?, product_isbn=?, product_description=?, product_price=?, product_cover_image=?, product_category_id=?, product_is_available=? WHERE product_id=?")
            ->execute([$title, $series, $volume, $author, $publisher, $isbn, $description, $price, $cover_image, $category_id, $is_available, $id]);

        // Update physical or ebook
        if ($type === 'physical') {
            $stock = (int)$_POST['physical_stock_quantity'];
            $threshold = (int)$_POST['physical_low_stock_threshold'];
            $weight = $_POST['physical_weight'] ?: null;
            $dimensions = trim($_POST['physical_dimensions']);

            $check = $pdo->prepare("SELECT physical_product_id FROM product_physical WHERE physical_product_id = ?");
            $check->execute([$id]);
            if ($check->rowCount() > 0) {
                $pdo->prepare("UPDATE product_physical SET physical_stock_quantity=?, physical_low_stock_threshold=?, physical_weight=?, physical_dimensions=? WHERE physical_product_id=?")
                    ->execute([$stock, $threshold, $weight, $dimensions, $id]);
            } else {
                $pdo->prepare("INSERT INTO product_physical (physical_product_id, physical_stock_quantity, physical_low_stock_threshold, physical_weight, physical_dimensions) VALUES (?, ?, ?, ?, ?)")
                    ->execute([$id, $stock, $threshold, $weight, $dimensions]);
            }
        } else {
            $download_limit = (int)$_POST['ebook_download_limit'];
            $file_format = $_POST['ebook_file_format'];
            $ebook_file = $product['ebook_file_path'];

            if (isset($_FILES['ebook_file']) && $_FILES['ebook_file']['error'] !== UPLOAD_ERR_NO_FILE) {
                $ebook_dir = '../assets/ebooks/';
                $new_ebook_file = uploadEbookFile($_FILES['ebook_file'], $ebook_dir);

                if ($new_ebook_file !== '') {
                    $ebook_file = $new_ebook_file;
                }
            }

            $check = $pdo->prepare("SELECT ebook_product_id FROM product_ebook WHERE ebook_product_id = ?");
            $check->execute([$id]);
            if ($check->rowCount() > 0) {
                $pdo->prepare("UPDATE product_ebook SET ebook_file_path=?, ebook_file_format=?, ebook_download_limit=? WHERE ebook_product_id=?")
                    ->execute([$ebook_file, $file_format, $download_limit, $id]);
            } else {
                $pdo->prepare("INSERT INTO product_ebook (ebook_product_id, ebook_file_path, ebook_file_format, ebook_download_limit) VALUES (?, ?, ?, ?)")
                    ->execute([$id, $ebook_file, $file_format, $download_limit]);
            }
        }

        // Update genres
        $pdo->prepare("DELETE FROM product_genres WHERE product_genres_product_id = ?")->execute([$id]);
        foreach ($new_genres as $genre_id) {
            $pdo->prepare("INSERT INTO product_genres (product_genres_product_id, product_genres_genre_id) VALUES (?, ?)")
                ->execute([$id, $genre_id]);
        }

        // Log admin action
        $pdo->prepare("INSERT INTO admin_logs (log_admin_id, log_action, log_target_type, log_target_id, log_details) VALUES (?, 'edit_product', 'product', ?, ?)")
            ->execute([$_SESSION['user_id'], $id, "Edited product: $title"]);

        header('Location: products.php?success=1');
        exit;
    }
    } catch (Exception $e) {
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
                    <select name="product_type" id="product_type" onchange="toggleType(this.value)">
                        <option value="physical" <?= $product['product_type'] === 'physical' ? 'selected' : '' ?>>Physical</option>
                        <option value="ebook" <?= $product['product_type'] === 'ebook' ? 'selected' : '' ?>>E-Book</option>
                    </select>
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

    <script>
    function toggleType(type) {
        const physical = ['row_stock', 'row_threshold', 'row_weight', 'row_dimensions'];
        const ebook = ['row_ebook_file', 'row_ebook_format', 'row_download_limit'];
        physical.forEach(id => document.getElementById(id).style.display = type === 'physical' ? '' : 'none');
        ebook.forEach(id => document.getElementById(id).style.display = type === 'ebook' ? '' : 'none');
    }
    </script>
</body>
</html>