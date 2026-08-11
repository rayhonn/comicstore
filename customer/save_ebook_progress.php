<?php

require_once __DIR__ . '/../includes/auth.php';
require_customer();

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';

header('Content-Type: application/json; charset=UTF-8');
header(
    'Cache-Control: no-store, no-cache, must-revalidate, max-age=0'
);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');

    echo json_encode([
        'success' => false,
        'message' => 'Unsupported request method.',
    ]);

    exit;
}

csrf_verify();

$userId = current_user_id();

$itemId = filter_var(
    $_POST['item_id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

$percentRaw = $_POST['percent'] ?? null;

if (
    $itemId === false ||
    $itemId === null ||
    is_array($percentRaw) ||
    !is_numeric((string) $percentRaw)
) {
    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'Invalid reading progress.',
    ]);

    exit;
}

$percent = (float) $percentRaw;

if (
    !is_finite($percent) ||
    $percent < 0 ||
    $percent > 100
) {
    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'Invalid reading progress.',
    ]);

    exit;
}

$entitlementStatement = $pdo->prepare("
    SELECT
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

$entitlementStatement->execute([
    (int) $itemId,
    $userId,
]);

$fileFormat =
    $entitlementStatement->fetchColumn();

if ($fileFormat === false) {
    http_response_code(404);

    echo json_encode([
        'success' => false,
        'message' => 'E-book entitlement was not found.',
    ]);

    exit;
}

$fileFormat = strtoupper(
    trim((string) $fileFormat)
);

$page = null;
$locator = null;

if ($fileFormat === 'PDF') {
    $page = filter_var(
        $_POST['page'] ?? null,
        FILTER_VALIDATE_INT,
        [
            'options' => [
                'min_range' => 1,
                'max_range' => 10000000,
            ],
        ]
    );

    if (
        $page === false ||
        $page === null
    ) {
        http_response_code(400);

        echo json_encode([
            'success' => false,
            'message' => 'Invalid PDF reading position.',
        ]);

        exit;
    }

    $page = (int) $page;
} elseif ($fileFormat === 'EPUB') {
    $submittedLocator =
        $_POST['locator'] ?? '';

    if (is_array($submittedLocator)) {
        $submittedLocator = '';
    }

    $locator = trim(
        (string) $submittedLocator
    );

    if (
        $locator === '' ||
        strlen($locator) > 1024 ||
        preg_match(
            '/[\x00-\x1F\x7F]/',
            $locator
        ) ||
        !str_starts_with(
            $locator,
            'epubcfi('
        )
    ) {
        http_response_code(400);

        echo json_encode([
            'success' => false,
            'message' => 'Invalid EPUB reading position.',
        ]);

        exit;
    }
} else {
    http_response_code(415);

    echo json_encode([
        'success' => false,
        'message' => 'Unsupported e-book format.',
    ]);

    exit;
}

$saveStatement = $pdo->prepare("
    INSERT INTO ebook_reading_progress (
        ebook_progress_user_id,
        ebook_progress_order_item_id,
        ebook_progress_page,
        ebook_progress_locator,
        ebook_progress_percent
    )
    VALUES (?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        ebook_progress_page =
            VALUES(ebook_progress_page),
        ebook_progress_locator =
            VALUES(ebook_progress_locator),
        ebook_progress_percent =
            VALUES(ebook_progress_percent),
        ebook_progress_updated_at =
            CURRENT_TIMESTAMP
");

$saveStatement->execute([
    $userId,
    (int) $itemId,
    $page,
    $locator,
    round($percent, 2),
]);

echo json_encode([
    'success' => true,
]);

exit;