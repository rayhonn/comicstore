<?php

require_once __DIR__ . '/../includes/auth.php';
require_customer();

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/bank_confirmation_document_helper.php';

$withdrawalId = filter_input(
    INPUT_GET,
    'id',
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if ($withdrawalId === false || $withdrawalId === null) {
    http_response_code(404);
    exit('Bank decision PDF not found.');
}

try {
    $record = loadBankConfirmationRecord(
        $pdo,
        (int) $withdrawalId,
        null,
        current_user_id()
    );
    streamBankConfirmationDocument(
        $record,
        ($_GET['download'] ?? '') === '1'
    );
} catch (Throwable $e) {
    app_error_log(
        'Customer bank decision PDF access failed: ' . $e->getMessage()
    );
    http_response_code(404);
    exit('Bank decision PDF not found.');
}
