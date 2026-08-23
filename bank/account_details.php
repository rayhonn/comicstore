<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/bank_gateway_helper.php';

requireBankOperator();

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode([
        'ok' => false,
        'message' => 'POST is required.',
    ]);
    exit;
}

$submittedToken = $_POST['csrf_token'] ?? null;
$sessionToken = $_SESSION['csrf_token'] ?? null;

if (
    !is_string($submittedToken) ||
    !is_string($sessionToken) ||
    $submittedToken === '' ||
    !hash_equals($sessionToken, $submittedToken)
) {
    http_response_code(403);
    echo json_encode([
        'ok' => false,
        'message' => 'The security token is invalid. Refresh the page and try again.',
    ]);
    exit;
}

$withdrawalId = filter_input(
    INPUT_POST,
    'withdrawal_id',
    FILTER_VALIDATE_INT,
    [
        'options' => [
            'min_range' => 1,
        ],
    ]
);

if ($withdrawalId === false || $withdrawalId === null) {
    http_response_code(422);
    echo json_encode([
        'ok' => false,
        'message' => 'The transfer instruction is invalid.',
    ]);
    exit;
}

try {
    $details = revealBankGatewayAccountDetails(
        $pdo,
        (int) $withdrawalId,
        currentBankOperatorId(),
        currentBankOperatorCode(),
        $_POST['current_password'] ?? null
    );

    echo json_encode([
        'ok' => true,
        'account_number' => $details['account_number'],
        'account_holder' => $details['account_holder'],
        'bank_name' => $details['bank_name'],
        'last4' => $details['last4'],
    ]);
} catch (BankGatewayException $e) {
    http_response_code(422);
    echo json_encode([
        'ok' => false,
        'message' => $e->getMessage(),
    ]);
} catch (Throwable $e) {
    app_error_log(
        'Protected bank account access failed: ' .
        $e->getMessage()
    );

    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => 'Protected account details could not be loaded.',
    ]);
}
