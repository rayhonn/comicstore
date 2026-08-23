<?php

require_once __DIR__ . '/../includes/auth.php';
require_senior_admin();

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/wallet_withdrawal_helper.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$withdrawalId = filter_input(
    INPUT_GET,
    'id',
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
        $request['wallet_withdrawal_bank_status'] ??
        'not_submitted'
    );

    echo json_encode(
        [
            'ok' => true,
            'withdrawal_id' => (int) $withdrawalId,
            'withdrawal_status' => (string) (
                $request['wallet_withdrawal_status'] ?? ''
            ),
            'bank_status' => $bankStatus,
            'bank_reference' => (string) (
                $request[
                    'wallet_withdrawal_bank_decision_reference'
                ] ?? ''
            ),
            'bank_decided_at_myt' =>
                walletWithdrawalMalaysiaDateTime(
                    (string) (
                        $request[
                            'wallet_withdrawal_bank_decided_at'
                        ] ?? ''
                    ),
                    'd M Y, h:i A',
                    ''
                ),
            'proof_url' => $bankStatus === 'approved'
                ? 'wallet_withdrawal_bank_confirmation.php?' .
                    http_build_query([
                        'id' => (int) $withdrawalId,
                        'download' => 1,
                    ])
                : '',
        ],
        JSON_UNESCAPED_SLASHES
    );
} catch (Throwable $exception) {
    app_error_log(
        'Withdrawal bank status check failed: ' .
        $exception->getMessage()
    );

    http_response_code(404);
    echo json_encode([
        'ok' => false,
        'message' => 'Withdrawal status is unavailable.',
    ]);
}
