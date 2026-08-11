<?php

require_once __DIR__ . '/../includes/auth.php';
require_customer();

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/ebook_helper.php';

$userId = current_user_id();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit('Unsupported e-book content request method.');
}

$itemId = filter_input(
    INPUT_GET,
    'item_id',
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

$readerToken = trim(
    (string) ($_GET['token'] ?? '')
);

if (
    $itemId === false ||
    $itemId === null ||
    preg_match(
        '/\A[a-f0-9]{64}\z/',
        $readerToken
    ) !== 1
) {
    http_response_code(400);
    exit('Invalid e-book content request.');
}

$readerTokens =
    $_SESSION['ebook_reader_tokens'] ?? [];

$tokenData =
    is_array($readerTokens)
        ? ($readerTokens[$readerToken] ?? null)
        : null;

if (
    !is_array($tokenData) ||
    (int) ($tokenData['user_id'] ?? 0) !== $userId ||
    (int) ($tokenData['item_id'] ?? 0) !== (int) $itemId ||
    (int) ($tokenData['expires_at'] ?? 0) < time()
) {
    unset(
        $_SESSION['ebook_reader_tokens'][$readerToken]
    );

    http_response_code(403);
    exit('E-book reader access has expired.');
}

$statement = $pdo->prepare("
    SELECT
        oi.order_item_id,
        oi.order_item_product_title AS product_title,
        pe.ebook_file_path,
        pe.ebook_file_format
    FROM order_items oi
    JOIN orders o
        ON o.order_id = oi.order_item_order_id
    JOIN product_ebook pe
        ON pe.ebook_product_id =
            oi.order_item_product_id
    WHERE oi.order_item_id = ?
    AND o.order_user_id = ?
    AND oi.order_item_type = 'ebook'
    AND o.order_payment_status = 'confirmed'
    LIMIT 1
");

$statement->execute([
    (int) $itemId,
    $userId,
]);

$item = $statement->fetch(PDO::FETCH_ASSOC);

if (!$item) {
    http_response_code(404);
    exit('E-book entitlement was not found.');
}

try {
    $file = resolveStoredEbookFile(
        (string) $item['ebook_file_path'],
        (string) $item['ebook_file_format'],
        false
    );
} catch (Throwable $exception) {
    app_error_log(
        'E-book reader content failed for customer ' .
        $userId .
        ', order item ' .
        (int) $itemId .
        ': ' .
        $exception->getMessage()
    );

    http_response_code(404);
    exit('E-book content is unavailable.');
}

$filePath = (string) $file['absolute_path'];
$fileSize = (int) $file['size_bytes'];
$contentType = (string) $file['mime_type'];

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: ' . $contentType);
header('Content-Length: ' . $fileSize);
header(
    'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0'
);
header('Pragma: no-cache');
header('Expires: 0');
header('X-Content-Type-Options: nosniff');
header('Cross-Origin-Resource-Policy: same-origin');
header('Referrer-Policy: no-referrer');

$handle = fopen($filePath, 'rb');

if ($handle === false) {
    http_response_code(500);
    exit('Unable to open the e-book content.');
}

set_time_limit(0);

while (!feof($handle)) {
    $chunk = fread(
        $handle,
        1024 * 1024
    );

    if ($chunk === false) {
        fclose($handle);
        exit;
    }

    echo $chunk;
    flush();

    if (connection_aborted()) {
        break;
    }
}

fclose($handle);
exit;