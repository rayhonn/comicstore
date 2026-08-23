<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ .
    '/../includes/bank_gateway_helper.php';

requireBankOperator();

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ .
    '/../includes/bank_confirmation_document_helper.php';

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
    exit('Bank confirmation proof not found.');
}

try {
    $record = loadBankConfirmationRecord(
        $pdo,
        (int) $withdrawal_id,
        currentBankOperatorCode()
    );

    streamBankConfirmationDocument(
        $record,
        ($_GET['download'] ?? '') === '1'
    );
} catch (Throwable $e) {
    app_error_log(
        'Bank confirmation proof access failed: ' .
        $e->getMessage()
    );

    http_response_code(404);
    exit('Bank confirmation proof not found.');
}
