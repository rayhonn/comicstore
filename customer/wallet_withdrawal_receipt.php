<?php

require_once __DIR__ . '/../includes/auth.php';
require_customer();

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ .
    '/../includes/wallet_withdrawal_document_helper.php';

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
    exit('Official withdrawal document not found.');
}

try {
    $record = loadWalletWithdrawalDocumentRecord(
        $pdo,
        (int) $withdrawal_id,
        current_user_id()
    );

    streamWalletWithdrawalDocument(
        $record,
        ($_GET['download'] ?? '') === '1'
    );
} catch (Throwable $e) {
    app_error_log(
        'Customer wallet document access failed: ' .
        $e->getMessage()
    );

    http_response_code(404);
    exit('Official withdrawal document not found.');
}
