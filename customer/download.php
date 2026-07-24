<?php
require_once __DIR__ . '/../includes/auth.php';
require_customer();

require_once __DIR__ . '/../includes/db.php';

$user_id = $_SESSION['user_id'];
$item_id = $_GET['item_id'] ?? null;

if (!$item_id) {
    header('Location: orders.php');
    exit;
}

// Verify item belongs to user and is ebook
$stmt = $pdo->prepare("
    SELECT oi.*, pe.ebook_file_path, pe.ebook_download_limit, p.product_title
    FROM order_items oi
    JOIN orders o ON oi.order_item_order_id = o.order_id
    JOIN products p ON oi.order_item_product_id = p.product_id
    LEFT JOIN product_ebook pe ON p.product_id = pe.ebook_product_id
    WHERE oi.order_item_id = ? AND o.order_user_id = ? AND oi.order_item_type = 'ebook'
");
$stmt->execute([$item_id, $user_id]);
$item = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$item) {
    header('Location: orders.php');
    exit;
}

// Check download limit
if ($item['order_item_download_count'] >= $item['ebook_download_limit']) {
    die("Download limit reached for this item. Please contact support.");
}

// Check file exists
$file_path = '../assets/ebooks/' . $item['ebook_file_path'];
if (!$item['ebook_file_path'] || !file_exists($file_path)) {
    die("File not available. Please contact support.");
}

// Increment download count only when the limit is not reached
$increment_download = $pdo->prepare("
    UPDATE order_items oi
    JOIN orders o
        ON oi.order_item_order_id = o.order_id
    JOIN product_ebook pe
        ON oi.order_item_product_id =
            pe.ebook_product_id
    SET oi.order_item_download_count =
        oi.order_item_download_count + 1
    WHERE oi.order_item_id = ?
    AND o.order_user_id = ?
    AND oi.order_item_type = 'ebook'
    AND oi.order_item_download_count <
        pe.ebook_download_limit
");

$increment_download->execute([
    $item_id,
    $user_id,
]);

if ($increment_download->rowCount() !== 1) {
    die(
        'Download limit reached for this item. ' .
        'Please contact support.'
    );
}

// Force download
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . basename($item['ebook_file_path']) . '"');
header('Content-Length: ' . filesize($file_path));
readfile($file_path);
exit;
?>