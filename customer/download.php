<?php

require_once __DIR__ . '/../includes/auth.php';
require_customer();

require_once __DIR__ . '/../includes/db.php';

$user_id = current_user_id();

$item_id = filter_input(
    INPUT_GET,
    'item_id',
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if ($item_id === false || $item_id === null) {
    header('Location: orders.php');
    exit;
}

$item_id = (int) $item_id;

$stmt = $pdo->prepare("
    SELECT
        oi.order_item_id,
        oi.order_item_download_count,
        pe.ebook_file_path,
        pe.ebook_download_limit,
        oi.order_item_product_title AS product_title
    FROM order_items oi
    JOIN orders o
        ON oi.order_item_order_id = o.order_id
    JOIN product_ebook pe
        ON oi.order_item_product_id =
            pe.ebook_product_id
    WHERE oi.order_item_id = ?
    AND o.order_user_id = ?
    AND oi.order_item_type = 'ebook'
    AND o.order_payment_status = 'confirmed'
    LIMIT 1
");
$stmt->execute([
    $item_id,
    $user_id,
]);
$item = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$item) {
    header('Location: orders.php');
    exit;
}

$download_limit =
    (int) $item['ebook_download_limit'];
$download_count =
    (int) $item['order_item_download_count'];

if (
    $download_limit < 1 ||
    $download_count >= $download_limit
) {
    http_response_code(403);
    exit(
        'Download limit reached for this item. ' .
        'Please contact support.'
    );
}

$file_name =
    basename((string) $item['ebook_file_path']);

if (
    $file_name === '' ||
    $file_name !== $item['ebook_file_path']
) {
    http_response_code(404);
    exit(
        'File not available. Please contact support.'
    );
}

$file_path =
    __DIR__ .
    '/../assets/ebooks/' .
    $file_name;

if (!is_file($file_path) || !is_readable($file_path)) {
    http_response_code(404);
    exit(
        'File not available. Please contact support.'
    );
}

$increment = $pdo->prepare("
    UPDATE order_items
    SET order_item_download_count =
        order_item_download_count + 1
    WHERE order_item_id = ?
    AND order_item_download_count < ?
");
$increment->execute([
    $item_id,
    $download_limit,
]);

if ($increment->rowCount() !== 1) {
    http_response_code(403);
    exit(
        'Download limit reached for this item. ' .
        'Please contact support.'
    );
}

$file_size = filesize($file_path);

if ($file_size === false) {
    http_response_code(500);
    exit('Unable to prepare the download.');
}

header('Content-Type: application/octet-stream');
header(
    'Content-Disposition: attachment; filename="' .
    addcslashes($file_name, '"\\') .
    '"'
);
header('Content-Length: ' . $file_size);
header('X-Content-Type-Options: nosniff');

readfile($file_path);
exit;