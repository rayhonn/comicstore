<?php

require_once __DIR__ . '/wallet_withdrawal_helper.php';

final class BankGatewayException extends RuntimeException
{
}

function currentBankOperatorId(): int
{
    return (int) (
        $_SESSION['bank_operator_id'] ?? 0
    );
}

function currentBankOperatorCode(): string
{
    return (string) (
        $_SESSION['bank_operator_code'] ?? ''
    );
}

function currentBankOperatorName(): string
{
    return (string) (
        $_SESSION['bank_operator_name'] ?? ''
    );
}

function requireBankOperator(): void
{
    redirect_if_session_expired();

    if (
        current_role() !== 'bank' ||
        currentBankOperatorId() < 1 ||
        currentBankOperatorCode() === ''
    ) {
        redirect_to(
            app_path('bank/login.php')
        );
    }

    global $pdo;

    if (
        !isset($pdo) ||
        !($pdo instanceof PDO)
    ) {
        require_once __DIR__ . '/db.php';
    }

    $statement = $pdo->prepare("
        SELECT
            bank_gateway_operator_id,
            bank_gateway_operator_bank_code,
            bank_gateway_operator_bank_name,
            bank_gateway_operator_display_name,
            bank_gateway_operator_is_active
        FROM bank_gateway_operators
        WHERE bank_gateway_operator_id = ?
        LIMIT 1
    ");
    $statement->execute([
        currentBankOperatorId(),
    ]);

    $operator = $statement->fetch(
        PDO::FETCH_ASSOC
    );

    if (
        !$operator ||
        (int) $operator[
            'bank_gateway_operator_is_active'
        ] !== 1 ||
        !hash_equals(
            (string) $operator[
                'bank_gateway_operator_bank_code'
            ],
            currentBankOperatorCode()
        )
    ) {
        destroy_session();

        redirect_to(
            app_path(
                'bank/login.php?account=inactive'
            )
        );
    }

    $_SESSION['bank_operator_name'] =
        (string) $operator[
            'bank_gateway_operator_display_name'
        ];
    $_SESSION['bank_operator_bank_name'] =
        (string) $operator[
            'bank_gateway_operator_bank_name'
        ];
}

function normalizeBankGatewayReference(
    mixed $value
): string {
    if (!is_string($value)) {
        throw new BankGatewayException(
            'Bank authorization reference is invalid.'
        );
    }

    $value = strtoupper(trim($value));

    if (
        strlen($value) < 8 ||
        strlen($value) > 80 ||
        preg_match(
            '/\A[A-Z0-9][A-Z0-9._\/-]{7,79}\z/',
            $value
        ) !== 1
    ) {
        throw new BankGatewayException(
            'Authorization reference must contain 8 to 80 letters, numbers, dots, slashes, underscores, or hyphens.'
        );
    }

    return $value;
}

function normalizeBankGatewayDecisionNote(
    mixed $value,
    bool $required
): string {
    if (!is_string($value)) {
        throw new BankGatewayException(
            'Bank decision note is invalid.'
        );
    }

    $value = trim($value);
    $length = function_exists('mb_strlen')
        ? mb_strlen($value, 'UTF-8')
        : strlen($value);

    if ($length > 1000) {
        throw new BankGatewayException(
            'Bank decision note cannot exceed 1000 characters.'
        );
    }

    if ($required && $value === '') {
        throw new BankGatewayException(
            'A bank rejection reason is required.'
        );
    }

    return $value;
}

function verifyBankGatewayOperatorPassword(
    PDO $pdo,
    int $operatorId,
    mixed $password
): array {
    if (
        $operatorId < 1 ||
        !is_string($password) ||
        $password === '' ||
        strlen($password) > 72
    ) {
        throw new BankGatewayException(
            'Current bank operator password verification failed.'
        );
    }

    $statement = $pdo->prepare("
        SELECT
            bank_gateway_operator_id,
            bank_gateway_operator_bank_code,
            bank_gateway_operator_bank_name,
            bank_gateway_operator_display_name,
            bank_gateway_operator_password_hash,
            bank_gateway_operator_is_active
        FROM bank_gateway_operators
        WHERE bank_gateway_operator_id = ?
        LIMIT 1
    ");
    $statement->execute([$operatorId]);
    $operator = $statement->fetch(
        PDO::FETCH_ASSOC
    );

    if (
        !$operator ||
        (int) $operator[
            'bank_gateway_operator_is_active'
        ] !== 1 ||
        !password_verify(
            $password,
            (string) $operator[
                'bank_gateway_operator_password_hash'
            ]
        )
    ) {
        throw new BankGatewayException(
            'Current bank operator password is incorrect.'
        );
    }

    return $operator;
}

function getBankGatewayQueueCounts(
    PDO $pdo,
    string $bankCode
): array {
    $bank = normalizeWalletWithdrawalBankCode(
        $bankCode
    );
    $statement = $pdo->prepare("
        SELECT
            wallet_withdrawal_bank_status,
            COUNT(*) AS total
        FROM wallet_withdrawal_requests
        WHERE wallet_withdrawal_bank_code = ?
        AND wallet_withdrawal_status IN (
            'approved',
            'completed'
        )
        AND wallet_withdrawal_bank_status IN (
            'pending',
            'approved',
            'rejected'
        )
        GROUP BY wallet_withdrawal_bank_status
    ");
    $statement->execute([$bank['code']]);

    $counts = [
        'pending' => 0,
        'approved' => 0,
        'rejected' => 0,
    ];

    foreach (
        $statement->fetchAll(PDO::FETCH_ASSOC)
        as $row
    ) {
        $status = (string) $row[
            'wallet_withdrawal_bank_status'
        ];

        if (array_key_exists($status, $counts)) {
            $counts[$status] =
                (int) $row['total'];
        }
    }

    return $counts;
}

function getBankGatewayQueue(
    PDO $pdo,
    string $bankCode,
    string $status
): array {
    $bank = normalizeWalletWithdrawalBankCode(
        $bankCode
    );
    $allowedStatuses = [
        'pending',
        'approved',
        'rejected',
        'all',
    ];

    if (!in_array($status, $allowedStatuses, true)) {
        $status = 'pending';
    }

    $sql = "
        SELECT
            wr.*,
            customer.user_first_name,
            customer.user_last_name,
            customer.user_gmail,
            operator.bank_gateway_operator_display_name
                AS decision_operator_name
        FROM wallet_withdrawal_requests wr
        INNER JOIN users customer
            ON customer.user_id =
                wr.wallet_withdrawal_user_id
        LEFT JOIN bank_gateway_operators operator
            ON operator.bank_gateway_operator_id =
                wr.wallet_withdrawal_bank_decided_by
        WHERE wr.wallet_withdrawal_bank_code = ?
        AND wr.wallet_withdrawal_status IN (
            'approved',
            'completed'
        )
        AND wr.wallet_withdrawal_bank_status IN (
            'pending',
            'approved',
            'rejected'
        )
    ";
    $params = [$bank['code']];

    if ($status !== 'all') {
        $sql .= '
            AND wr.wallet_withdrawal_bank_status = ?
        ';
        $params[] = $status;
    }

    $sql .= "
        ORDER BY
            CASE wr.wallet_withdrawal_bank_status
                WHEN 'pending' THEN 0
                WHEN 'approved' THEN 1
                ELSE 2
            END,
            wr.wallet_withdrawal_bank_submitted_at ASC,
            wr.wallet_withdrawal_id ASC
    ";

    $statement = $pdo->prepare($sql);
    $statement->execute($params);

    return $statement->fetchAll(
        PDO::FETCH_ASSOC
    );
}

function loadBankGatewayWithdrawal(
    PDO $pdo,
    int $withdrawalId,
    string $bankCode,
    bool $forUpdate = false
): array {
    if ($withdrawalId < 1) {
        throw new BankGatewayException(
            'Invalid bank gateway withdrawal request.'
        );
    }

    $bank = normalizeWalletWithdrawalBankCode(
        $bankCode
    );

    if ($forUpdate && !$pdo->inTransaction()) {
        throw new BankGatewayException(
            'Bank gateway transaction is required.'
        );
    }

    $sql = "
        SELECT
            wr.*,
            customer.user_first_name,
            customer.user_last_name,
            customer.user_gmail
        FROM wallet_withdrawal_requests wr
        INNER JOIN users customer
            ON customer.user_id =
                wr.wallet_withdrawal_user_id
        WHERE wr.wallet_withdrawal_id = ?
        AND wr.wallet_withdrawal_bank_code = ?
        LIMIT 1
    ";

    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }

    $statement = $pdo->prepare($sql);
    $statement->execute([
        $withdrawalId,
        $bank['code'],
    ]);
    $request = $statement->fetch(
        PDO::FETCH_ASSOC
    );

    if (!$request) {
        throw new BankGatewayException(
            'The bank verification request was not found.'
        );
    }

    return $request;
}

function insertBankGatewayAuditLog(
    PDO $pdo,
    int $operatorId,
    int $withdrawalId,
    string $action,
    string $details
): void {
    if (!$pdo->inTransaction()) {
        throw new BankGatewayException(
            'Bank gateway audit transaction is required.'
        );
    }

    if (
        preg_match(
            '/\A[a-z0-9_]{3,60}\z/',
            $action
        ) !== 1 ||
        trim($details) === '' ||
        strlen($details) > 1000
    ) {
        throw new BankGatewayException(
            'Invalid bank gateway audit event.'
        );
    }

    $statement = $pdo->prepare("
        INSERT INTO bank_gateway_audit_logs (
            bank_gateway_log_operator_id,
            bank_gateway_log_withdrawal_id,
            bank_gateway_log_action,
            bank_gateway_log_details
        )
        VALUES (?, ?, ?, ?)
    ");
    $statement->execute([
        $operatorId,
        $withdrawalId,
        $action,
        $details,
    ]);
}

function revealBankGatewayAccountDetails(
    PDO $pdo,
    int $withdrawalId,
    int $operatorId,
    string $bankCode,
    mixed $currentPassword
): array {
    $operator = verifyBankGatewayOperatorPassword(
        $pdo,
        $operatorId,
        $currentPassword
    );

    if (!hash_equals(
        (string) $operator['bank_gateway_operator_bank_code'],
        $bankCode
    )) {
        throw new BankGatewayException(
            'This operator is not authorized for the selected institution.'
        );
    }

    try {
        $pdo->beginTransaction();

        $request = loadBankGatewayWithdrawal(
            $pdo,
            $withdrawalId,
            $bankCode,
            true
        );

        if (!in_array(
            (string) $request['wallet_withdrawal_status'],
            [
                'approved',
                'completed',
            ],
            true
        )) {
            throw new BankGatewayException(
                'This transfer instruction is no longer available for review.'
            );
        }

        $accountNumber = decryptWalletWithdrawalAccountNumber(
            (string) $request[
                'wallet_withdrawal_account_number_encrypted'
            ]
        );

        insertBankGatewayAuditLog(
            $pdo,
            $operatorId,
            $withdrawalId,
            'reveal_account_details',
            'Re-authorized access to protected destination account details.'
        );

        $pdo->commit();

        return [
            'account_number' => $accountNumber,
            'account_holder' => (string) $request[
                'wallet_withdrawal_account_holder'
            ],
            'bank_name' => (string) $request[
                'wallet_withdrawal_bank_name'
            ],
            'last4' => (string) $request[
                'wallet_withdrawal_account_number_last4'
            ],
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }
}

function decideBankGatewayWithdrawal(
    PDO $pdo,
    int $withdrawalId,
    int $operatorId,
    string $bankCode,
    string $decision,
    mixed $authorizationReference,
    mixed $decisionNote,
    mixed $currentPassword
): array {
    if (!in_array(
        $decision,
        [
            'approve',
            'reject',
        ],
        true
    )) {
        throw new BankGatewayException(
            'Invalid bank verification decision.'
        );
    }

    $operator = verifyBankGatewayOperatorPassword(
        $pdo,
        $operatorId,
        $currentPassword
    );

    if (!hash_equals(
        (string) $operator[
            'bank_gateway_operator_bank_code'
        ],
        $bankCode
    )) {
        throw new BankGatewayException(
            'This request belongs to another bank.'
        );
    }

    $reference = $decision === 'approve'
        ? normalizeBankGatewayReference(
            $authorizationReference
        )
        : null;
    $note = normalizeBankGatewayDecisionNote(
        $decisionNote,
        $decision === 'reject'
    );

    try {
        $pdo->beginTransaction();

        $request = loadBankGatewayWithdrawal(
            $pdo,
            $withdrawalId,
            $bankCode,
            true
        );

        if (
            $request[
                'wallet_withdrawal_status'
            ] !== 'approved' ||
            $request[
                'wallet_withdrawal_bank_status'
            ] !== 'pending'
        ) {
            throw new BankGatewayException(
                'This bank verification request has already been processed or is no longer active.'
            );
        }

        if ($reference !== null) {
            $duplicate = $pdo->prepare("
                SELECT wallet_withdrawal_id
                FROM wallet_withdrawal_requests
                WHERE wallet_withdrawal_bank_decision_reference = ?
                AND wallet_withdrawal_id <> ?
                LIMIT 1
                FOR UPDATE
            ");
            $duplicate->execute([
                $reference,
                $withdrawalId,
            ]);

            if ($duplicate->fetchColumn() !== false) {
                throw new BankGatewayException(
                    'This bank authorization reference is already assigned to another request.'
                );
            }
        }

        $bankStatus = $decision === 'approve'
            ? 'approved'
            : 'rejected';
        $verificationHash = hash_hmac(
            'sha256',
            (string) $request[
                'wallet_withdrawal_bank_submission_id'
            ] . '|' .
            $bankStatus . '|' .
            (string) $reference . '|' .
            $operatorId . '|' .
            (string) $request[
                'wallet_withdrawal_amount'
            ],
            walletWithdrawalEncryptionKey()
        );

        $update = $pdo->prepare("
            UPDATE wallet_withdrawal_requests
            SET
                wallet_withdrawal_bank_status = ?,
                wallet_withdrawal_bank_decision_reference = ?,
                wallet_withdrawal_bank_decided_by = ?,
                wallet_withdrawal_bank_decided_at = UTC_TIMESTAMP(),
                wallet_withdrawal_bank_decision_note = ?,
                wallet_withdrawal_bank_verification_hash = ?
            WHERE wallet_withdrawal_id = ?
            AND wallet_withdrawal_status = 'approved'
            AND wallet_withdrawal_bank_status = 'pending'
        ");
        $update->execute([
            $bankStatus,
            $reference,
            $operatorId,
            $note !== ''
                ? $note
                : null,
            $verificationHash,
            $withdrawalId,
        ]);

        if ($update->rowCount() !== 1) {
            throw new BankGatewayException(
                'The bank decision could not be recorded.'
            );
        }

        insertBankGatewayAuditLog(
            $pdo,
            $operatorId,
            $withdrawalId,
            $decision === 'approve'
                ? 'approve_withdrawal'
                : 'reject_withdrawal',
            ($decision === 'approve'
                ? 'Approved bank verification with authorization reference ' .
                    $reference . '.'
                : 'Rejected bank verification. Reason: ' .
                    $note)
        );

        $pdo->commit();

        $request[
            'wallet_withdrawal_bank_status'
        ] = $bankStatus;
        $request[
            'wallet_withdrawal_bank_decision_reference'
        ] = $reference;
        $request[
            'wallet_withdrawal_bank_decision_note'
        ] = $note;

        return $request;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }
}
