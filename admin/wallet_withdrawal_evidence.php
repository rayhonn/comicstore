<?php

require_once __DIR__ . '/../includes/auth.php';
require_senior_admin();

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/wallet_withdrawal_helper.php';

$withdrawal_id = filter_input(
    INPUT_GET,
    'id',
    FILTER_VALIDATE_INT,
    [
        'options' => [
            'min_range' => 1,
        ],
    ]
);

if (
    $withdrawal_id === false ||
    $withdrawal_id === null
) {
    http_response_code(404);
    exit('Bank transfer evidence not found.');
}

try {
    $record = loadWalletWithdrawalReceiptRecord(
        $pdo,
        (int) $withdrawal_id,
        null
    );
    $receipt =
        resolveVerifiedWalletWithdrawalReceipt(
            $record
        );
} catch (Throwable $e) {
    app_error_log(
        'Admin wallet evidence access failed: ' .
        $e->getMessage()
    );

    http_response_code(404);
    exit('Bank transfer evidence not found.');
}

header(
    'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0'
);
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Content-Type: ' . $receipt['mime']);
header('Content-Length: ' . $receipt['size']);
header(
    'Content-Disposition: inline; filename="bank-transfer-evidence-' .
    (int) $withdrawal_id .
    '.' .
    pathinfo(
        $receipt['file_name'],
        PATHINFO_EXTENSION
    ) .
    '"'
);

$handle = fopen(
    $receipt['path'],
    'rb'
);

if ($handle === false) {
    http_response_code(500);
    exit;
}

while (!feof($handle)) {
    $chunk = fread(
        $handle,
        8192
    );

    if ($chunk === false) {
        break;
    }

    echo $chunk;
}

fclose($handle);
exit;
