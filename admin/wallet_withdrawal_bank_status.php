<?php

require_once __DIR__ . '/../includes/auth.php';
require_senior_admin();

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/wallet_withdrawal_helper.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$withdrawalId = filter_input(
    INPUT_GET,
    'id',
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if ($withdrawalId === false || $withdrawalId === null) {
    http_response_code(422);
    echo json_encode([
        'ok' => false,
        'message' => 'Invalid withdrawal request.',
    ]);
    exit;
}

try {
    $request = walletWithdrawalLoadForAdmin(
        $pdo,
        (int) $withdrawalId
    );

    $bankStatus = (string) (
        $request['wallet_withdrawal_bank_status'] ?? 'not_submitted'
    );
    $settlementStatus = (string) (
        $request['wallet_withdrawal_bank_settlement_status'] ?? 'not_required'
    );
    $withdrawalStatus = (string) (
        $request['wallet_withdrawal_status'] ?? ''
    );

    echo json_encode([
        'ok' => true,
        'withdrawal_id' => (int) $withdrawalId,
        'withdrawal_status' => $withdrawalStatus,
        'bank_status' => $bankStatus,
        'settlement_status' => $settlementStatus,
        'bank_reference' => (string) (
            $request['wallet_withdrawal_bank_decision_reference'] ?? ''
        ),
        'settlement_reference' => (string) (
            $request['wallet_withdrawal_transfer_reference'] ?? ''
        ),
        'bank_decided_at_myt' => walletWithdrawalMalaysiaDateTime(
            (string) ($request['wallet_withdrawal_bank_decided_at'] ?? ''),
            'd M Y, h:i A',
            ''
        ),
        'settlement_started_at_myt' => walletWithdrawalMalaysiaDateTime(
            (string) ($request['wallet_withdrawal_bank_settlement_started_at'] ?? ''),
            'd M Y, h:i A',
            ''
        ),
        'settled_at_myt' => walletWithdrawalMalaysiaDateTime(
            (string) ($request['wallet_withdrawal_bank_settled_at'] ?? ''),
            'd M Y, h:i A',
            ''
        ),
        'is_final' => in_array(
            $withdrawalStatus,
            ['completed', 'failed', 'rejected'],
            true
        ),
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    app_error_log(
        'Withdrawal bank lifecycle status check failed: ' .
        $exception->getMessage()
    );

    http_response_code(404);
    echo json_encode([
        'ok' => false,
        'message' => 'Withdrawal status is unavailable.',
    ]);
}