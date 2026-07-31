<?php

require_once __DIR__ . '/../includes/auth.php';
require_customer();

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/ebook_helper.php';

$userId = current_user_id();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $confirmationItemId = filter_input(
        INPUT_GET,
        'item_id',
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    if (
        $confirmationItemId === false ||
        $confirmationItemId === null
    ) {
        header('Location: collection.php');
        exit;
    }

    $confirmationStatement = $pdo->prepare("
        SELECT oi.order_item_product_title
        FROM order_items oi
        JOIN orders o
            ON o.order_id = oi.order_item_order_id
        WHERE oi.order_item_id = ?
        AND o.order_user_id = ?
        AND oi.order_item_type = 'ebook'
        AND o.order_payment_status = 'confirmed'
        LIMIT 1
    ");
    $confirmationStatement->execute([
        $confirmationItemId,
        $userId,
    ]);
    $confirmationTitle =
        $confirmationStatement->fetchColumn();

    if ($confirmationTitle === false) {
        header('Location: collection.php');
        exit;
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
        <title>Confirm Download - MangaVault</title>
        <script src="https://cdn.tailwindcss.com"></script>
    </head>
    <body class="min-h-screen bg-[#F5F0EB] flex items-center justify-center px-6">
        <main class="w-full max-w-md rounded-2xl bg-white p-8 text-center shadow-sm">
            <div class="mb-4 text-5xl">📱</div>
            <h1 class="mb-2 text-xl font-black text-gray-800">
                Confirm E-Book Download
            </h1>
            <p class="mb-6 text-sm leading-relaxed text-gray-500">
                Downloading
                <strong><?= htmlspecialchars(
                    (string) $confirmationTitle,
                    ENT_QUOTES,
                    'UTF-8'
                ) ?></strong>
                will use one download from your allowance.
            </p>
            <form method="POST" class="space-y-3">
                <?php csrf_field(); ?>
                <input
                    type="hidden"
                    name="item_id"
                    value="<?= (int) $confirmationItemId ?>"
                >
                <button
                    type="submit"
                    class="w-full rounded-xl bg-blue-600 px-5 py-3 text-sm font-bold text-white hover:bg-blue-700"
                >
                    Confirm Download
                </button>
                <a
                    href="collection.php"
                    class="block w-full rounded-xl border border-gray-200 px-5 py-3 text-sm font-semibold text-gray-500 hover:bg-gray-50"
                >
                    Cancel
                </a>
            </form>
        </main>
    </body>
    </html>
    <?php
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: GET, POST');
    exit('Unsupported download request method.');
}

csrf_verify();

$itemId = filter_var(
    $_POST['item_id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if ($itemId === false || $itemId === null) {
    header('Location: collection.php');
    exit;
}

$itemId = (int) $itemId;

try {
    $pdo->beginTransaction();

    $statement = $pdo->prepare("
        SELECT
            oi.order_item_id,
            oi.order_item_product_id,
            oi.order_item_download_count,
            oi.order_item_product_title AS product_title,
            pe.ebook_file_path,
            pe.ebook_file_format,
            pe.ebook_download_limit
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
        FOR UPDATE
    ");
    $statement->execute([
        $itemId,
        $userId,
    ]);
    $item = $statement->fetch(PDO::FETCH_ASSOC);

    if (!$item) {
        throw new RuntimeException(
            'The e-book entitlement was not found.'
        );
    }

    $downloadLimit = (int) $item['ebook_download_limit'];
    $downloadCount = (int) $item['order_item_download_count'];

    if (
        $downloadLimit < 1 ||
        $downloadCount >= $downloadLimit
    ) {
        throw new RuntimeException(
            'Download limit reached for this item. Please contact support.'
        );
    }

    $file = resolveStoredEbookFile(
        (string) $item['ebook_file_path'],
        (string) $item['ebook_file_format']
    );

    $increment = $pdo->prepare("
        UPDATE order_items
        SET order_item_download_count =
            order_item_download_count + 1
        WHERE order_item_id = ?
        AND order_item_download_count = ?
        AND order_item_download_count < ?
    ");
    $increment->execute([
        $itemId,
        $downloadCount,
        $downloadLimit,
    ]);

    if ($increment->rowCount() !== 1) {
        throw new RuntimeException(
            'Download limit reached for this item. Please contact support.'
        );
    }

    $pdo->commit();
} catch (RuntimeException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $message = $exception->getMessage();

    if (str_starts_with($message, 'Download limit')) {
        http_response_code(403);
    } else {
        http_response_code(404);
        $message =
            'File not available. Please contact support.';
    }

    exit($message);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    app_error_log(
        'E-book download failed for customer ' .
        $userId .
        ', order item ' .
        $itemId .
        ': ' .
        $exception->getMessage()
    );

    http_response_code(500);
    exit('Unable to prepare the download.');
}

$filePath = (string) $file['absolute_path'];
$fileSize = (int) $file['size_bytes'];
$extension = (string) $file['extension'];
$contentType = (string) $file['mime_type'];
$productTitle = trim((string) $item['product_title']);
$safeBaseName = preg_replace(
    '/[^A-Za-z0-9._-]+/',
    '_',
    $productTitle
);
$safeBaseName = trim((string) $safeBaseName, '._-');

if ($safeBaseName === '') {
    $safeBaseName = 'MangaVault_Ebook';
}

$downloadName = substr($safeBaseName, 0, 120) .
    '.' .
    $extension;

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: ' . $contentType);
header(
    'Content-Disposition: attachment; filename="' .
    addcslashes($downloadName, '"\\') .
    '"'
);
header('Content-Length: ' . $fileSize);
header(
    'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0'
);
header('Pragma: no-cache');
header('Expires: 0');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'none'; sandbox");

$handle = fopen($filePath, 'rb');

if ($handle === false) {
    http_response_code(500);
    exit('Unable to open the download.');
}

set_time_limit(0);

while (!feof($handle)) {
    $chunk = fread($handle, 1024 * 1024);

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
