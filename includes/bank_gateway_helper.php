<?php

require_once __DIR__ . '/wallet_withdrawal_helper.php';

final class BankGatewayException extends RuntimeException
{
}

function currentBankOperatorId(): int
{
    return (int) ($_SESSION['bank_operator_id'] ?? 0);
}

function currentBankOperatorCode(): string
{
    return (string) ($_SESSION['bank_operator_code'] ?? '');
}

function currentBankOperatorName(): string
{
    return (string) ($_SESSION['bank_operator_name'] ?? '');
}

function requireBankOperator(): void
{
    redirect_if_session_expired();

    if (
        current_role() !== 'bank' ||
        currentBankOperatorId() < 1 ||
        currentBankOperatorCode() === ''
    ) {
        redirect_to(app_path('bank/login.php'));
    }

    global $pdo;

    if (!isset($pdo) || !($pdo instanceof PDO)) {
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
    $statement->execute([currentBankOperatorId()]);
    $operator = $statement->fetch(PDO::FETCH_ASSOC);

    if (
        !$operator ||
        (int) $operator['bank_gateway_operator_is_active'] !== 1 ||
        !hash_equals(
            (string) $operator['bank_gateway_operator_bank_code'],
            currentBankOperatorCode()
        )
    ) {
        destroy_session();
        redirect_to(app_path('bank/login.php?account=inactive'));
    }

    $_SESSION['bank_operator_name'] =
        (string) $operator['bank_gateway_operator_display_name'];
    $_SESSION['bank_operator_bank_name'] =
        (string) $operator['bank_gateway_operator_bank_name'];
}

function assertBankGatewayEnterpriseSchema(PDO $pdo): void
{
    $statement = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'wallet_withdrawal_requests'
          AND COLUMN_NAME IN (
              'wallet_withdrawal_bank_settlement_status',
              'wallet_withdrawal_bank_settlement_started_by',
              'wallet_withdrawal_bank_settlement_started_at',
              'wallet_withdrawal_bank_settled_by',
              'wallet_withdrawal_bank_settled_at',
              'wallet_withdrawal_bank_settlement_note',
              'wallet_withdrawal_bank_settlement_hash',
              'wallet_withdrawal_retry_expires_at_myt'
          )
    ");
    $statement->execute();

    if ((int) $statement->fetchColumn() !== 8) {
        throw new BankGatewayException(
            'Final bank lifecycle schema is not installed. Run database/20260825_wallet_withdrawal_lifecycle_final.sql after the enterprise settlement migration.'
        );
    }
}

function normalizeBankGatewayReference(mixed $value): string
{
    if (!is_string($value)) {
        throw new BankGatewayException('Bank reference is invalid.');
    }

    $value = strtoupper(trim($value));

    if (
        strlen($value) < 8 ||
        strlen($value) > 80 ||
        preg_match('/\A[A-Z0-9][A-Z0-9._\/-]{7,79}\z/', $value) !== 1
    ) {
        throw new BankGatewayException(
            'Bank reference must contain 8 to 80 letters, numbers, dots, slashes, underscores, or hyphens.'
        );
    }

    return $value;
}

function normalizeBankGatewayDecisionNote(mixed $value, bool $required): string
{
    if (!is_string($value)) {
        throw new BankGatewayException('Bank decision note is invalid.');
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
        throw new BankGatewayException('A reason is required for this action.');
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
    $operator = $statement->fetch(PDO::FETCH_ASSOC);

    if (
        !$operator ||
        (int) $operator['bank_gateway_operator_is_active'] !== 1 ||
        !password_verify(
            $password,
            (string) $operator['bank_gateway_operator_password_hash']
        )
    ) {
        throw new BankGatewayException('Current bank operator password is incorrect.');
    }

    return $operator;
}

function assertBankGatewayOperatorBank(array $operator, string $bankCode): void
{
    if (
        !isset($operator['bank_gateway_operator_bank_code']) ||
        !hash_equals(
            (string) $operator['bank_gateway_operator_bank_code'],
            $bankCode
        )
    ) {
        throw new BankGatewayException(
            'This operator is not authorized for the selected institution.'
        );
    }
}

function generateBankGatewayReference(
    string $prefix,
    string $bankCode,
    int $withdrawalId
): string {
    $prefix = strtoupper(preg_replace('/[^A-Z0-9]/', '', $prefix) ?? 'REF');
    $bankCode = strtoupper(preg_replace('/[^A-Z0-9]/', '', $bankCode) ?? 'BANK');

    if ($withdrawalId < 1) {
        throw new BankGatewayException('Cannot generate a reference for this instruction.');
    }

    $reference = sprintf(
        'BLS-%s-%s-%s-%06d-%s',
        $prefix,
        $bankCode,
        gmdate('YmdHis'),
        $withdrawalId,
        strtoupper(bin2hex(random_bytes(3)))
    );

    return normalizeBankGatewayReference($reference);
}

function getBankGatewayMetrics(PDO $pdo, string $bankCode): array
{
    assertBankGatewayEnterpriseSchema($pdo);
    $bank = normalizeWalletWithdrawalBankCode($bankCode);

    $statement = $pdo->prepare("
        SELECT
            SUM(
                wallet_withdrawal_status = 'approved'
                AND wallet_withdrawal_bank_status = 'pending'
            ) AS pending_review,
            SUM(
                wallet_withdrawal_status = 'approved'
                AND wallet_withdrawal_bank_status = 'approved'
                AND wallet_withdrawal_bank_settlement_status = 'ready'
            ) AS ready_settlement,
            SUM(
                wallet_withdrawal_status = 'approved'
                AND wallet_withdrawal_bank_status = 'approved'
                AND wallet_withdrawal_bank_settlement_status = 'processing'
            ) AS processing,
            SUM(
                wallet_withdrawal_status = 'completed'
                AND wallet_withdrawal_bank_settlement_status = 'settled'
                AND DATE(DATE_ADD(wallet_withdrawal_bank_settled_at, INTERVAL 8 HOUR)) =
                    DATE(DATE_ADD(UTC_TIMESTAMP(), INTERVAL 8 HOUR))
            ) AS settled_today,
            SUM(
                wallet_withdrawal_bank_status = 'rejected'
                AND DATE(DATE_ADD(wallet_withdrawal_bank_decided_at, INTERVAL 8 HOUR)) =
                    DATE(DATE_ADD(UTC_TIMESTAMP(), INTERVAL 8 HOUR))
            ) AS rejected_today,
            SUM(
                wallet_withdrawal_status = 'approved'
                AND wallet_withdrawal_bank_status = 'rejected'
            ) AS reconciliation_exceptions,
            SUM(
                CASE
                    WHEN wallet_withdrawal_status = 'completed'
                     AND wallet_withdrawal_bank_settlement_status = 'settled'
                     AND DATE(DATE_ADD(wallet_withdrawal_bank_settled_at, INTERVAL 8 HOUR)) =
                         DATE(DATE_ADD(UTC_TIMESTAMP(), INTERVAL 8 HOUR))
                    THEN wallet_withdrawal_amount
                    ELSE 0
                END
            ) AS settled_value_today,
            SUM(
                wallet_withdrawal_status = 'approved'
                AND wallet_withdrawal_bank_status = 'pending'
                AND TIMESTAMPDIFF(
                    MINUTE,
                    wallet_withdrawal_bank_submitted_at,
                    UTC_TIMESTAMP()
                ) > 30
            ) AS sla_exceptions
        FROM wallet_withdrawal_requests
        WHERE wallet_withdrawal_bank_code = ?
    ");
    $statement->execute([$bank['code']]);
    $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'pending_review' => (int) ($row['pending_review'] ?? 0),
        'ready_settlement' => (int) ($row['ready_settlement'] ?? 0),
        'processing' => (int) ($row['processing'] ?? 0),
        'settled_today' => (int) ($row['settled_today'] ?? 0),
        'rejected_today' => (int) ($row['rejected_today'] ?? 0),
        'reconciliation_exceptions' => (int) ($row['reconciliation_exceptions'] ?? 0),
        'settled_value_today_sen' => moneyDecimalToSen(
            (string) ($row['settled_value_today'] ?? '0.00')
        ),
        'sla_exceptions' => (int) ($row['sla_exceptions'] ?? 0),
    ];
}

function getBankGatewayQueueCounts(PDO $pdo, string $bankCode): array
{
    $metrics = getBankGatewayMetrics($pdo, $bankCode);

    $bank = normalizeWalletWithdrawalBankCode($bankCode);
    $statement = $pdo->prepare("
        SELECT
            SUM(
                wallet_withdrawal_status = 'completed'
                AND wallet_withdrawal_bank_settlement_status = 'settled'
            ) AS settled,
            SUM(
                wallet_withdrawal_status = 'failed'
                AND wallet_withdrawal_bank_status = 'rejected'
            ) AS rejected,
            SUM(
                wallet_withdrawal_status = 'failed'
                AND wallet_withdrawal_bank_settlement_status = 'failed'
            ) AS settlement_failed
        FROM wallet_withdrawal_requests
        WHERE wallet_withdrawal_bank_code = ?
    ");
    $statement->execute([$bank['code']]);
    $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'pending' => $metrics['pending_review'],
        'accepted' => $metrics['ready_settlement'],
        'processing' => $metrics['processing'],
        'settled' => (int) ($row['settled'] ?? 0),
        'rejected' => (int) ($row['rejected'] ?? 0),
        'failed' => (int) ($row['settlement_failed'] ?? 0),
        'exceptions' => $metrics['reconciliation_exceptions'],
    ];
}

function bankGatewayQueueFilterSql(string $status): string
{
    return match ($status) {
        'pending' => "
            AND wr.wallet_withdrawal_status = 'approved'
            AND wr.wallet_withdrawal_bank_status = 'pending'
        ",
        'accepted' => "
            AND wr.wallet_withdrawal_status = 'approved'
            AND wr.wallet_withdrawal_bank_status = 'approved'
            AND wr.wallet_withdrawal_bank_settlement_status = 'ready'
        ",
        'processing' => "
            AND wr.wallet_withdrawal_status = 'approved'
            AND wr.wallet_withdrawal_bank_status = 'approved'
            AND wr.wallet_withdrawal_bank_settlement_status = 'processing'
        ",
        'settled' => "
            AND wr.wallet_withdrawal_status = 'completed'
            AND wr.wallet_withdrawal_bank_settlement_status = 'settled'
        ",
        'rejected' => "
            AND wr.wallet_withdrawal_bank_status = 'rejected'
            AND wr.wallet_withdrawal_status = 'failed'
        ",
        'failed' => "
            AND wr.wallet_withdrawal_status = 'failed'
            AND wr.wallet_withdrawal_bank_settlement_status = 'failed'
        ",
        'exceptions' => "
            AND wr.wallet_withdrawal_bank_status = 'rejected'
            AND wr.wallet_withdrawal_status = 'approved'
        ",
        default => '',
    };
}

function getBankGatewayQueue(
    PDO $pdo,
    string $bankCode,
    string $status,
    string $search = '',
    int $limit = 100
): array {
    assertBankGatewayEnterpriseSchema($pdo);
    $bank = normalizeWalletWithdrawalBankCode($bankCode);
    $allowedStatuses = [
        'pending',
        'accepted',
        'processing',
        'settled',
        'rejected',
        'failed',
        'exceptions',
        'all',
    ];

    if (!in_array($status, $allowedStatuses, true)) {
        $status = 'pending';
    }

    $limit = max(1, min(250, $limit));
    $sql = "
        SELECT
            wr.*,
            customer.user_first_name,
            customer.user_last_name,
            customer.user_gmail,
            decision_operator.bank_gateway_operator_display_name AS decision_operator_name,
            starter.bank_gateway_operator_display_name AS settlement_started_by_name,
            settler.bank_gateway_operator_display_name AS settled_by_name,
            TIMESTAMPDIFF(
                MINUTE,
                wr.wallet_withdrawal_bank_submitted_at,
                UTC_TIMESTAMP()
            ) AS queue_age_minutes
        FROM wallet_withdrawal_requests wr
        INNER JOIN users customer
            ON customer.user_id = wr.wallet_withdrawal_user_id
        LEFT JOIN bank_gateway_operators decision_operator
            ON decision_operator.bank_gateway_operator_id =
                wr.wallet_withdrawal_bank_decided_by
        LEFT JOIN bank_gateway_operators starter
            ON starter.bank_gateway_operator_id =
                wr.wallet_withdrawal_bank_settlement_started_by
        LEFT JOIN bank_gateway_operators settler
            ON settler.bank_gateway_operator_id =
                wr.wallet_withdrawal_bank_settled_by
        WHERE wr.wallet_withdrawal_bank_code = ?
          AND wr.wallet_withdrawal_bank_status IN ('pending', 'approved', 'rejected')
    ";
    $params = [$bank['code']];
    $sql .= bankGatewayQueueFilterSql($status);

    $search = trim($search);
    if ($search !== '') {
        $needle = '%' . $search . '%';
        $sql .= "
            AND (
                CAST(wr.wallet_withdrawal_id AS CHAR) LIKE ?
                OR wr.wallet_withdrawal_bank_submission_id LIKE ?
                OR COALESCE(wr.wallet_withdrawal_bank_decision_reference, '') LIKE ?
                OR COALESCE(wr.wallet_withdrawal_transfer_reference, '') LIKE ?
                OR wr.wallet_withdrawal_account_number_last4 LIKE ?
                OR customer.user_first_name LIKE ?
                OR customer.user_last_name LIKE ?
                OR customer.user_gmail LIKE ?
            )
        ";
        for ($i = 0; $i < 8; $i++) {
            $params[] = $needle;
        }
    }

    $sql .= "
        ORDER BY
            CASE
                WHEN wr.wallet_withdrawal_status = 'approved'
                     AND wr.wallet_withdrawal_bank_status = 'pending' THEN 0
                WHEN wr.wallet_withdrawal_status = 'approved'
                     AND wr.wallet_withdrawal_bank_status = 'approved'
                     AND wr.wallet_withdrawal_bank_settlement_status = 'ready' THEN 1
                WHEN wr.wallet_withdrawal_status = 'approved'
                     AND wr.wallet_withdrawal_bank_status = 'approved'
                     AND wr.wallet_withdrawal_bank_settlement_status = 'processing' THEN 2
                WHEN wr.wallet_withdrawal_status = 'approved'
                     AND wr.wallet_withdrawal_bank_status = 'rejected' THEN 3
                WHEN wr.wallet_withdrawal_status = 'failed' THEN 4
                ELSE 5
            END,
            COALESCE(
                wr.wallet_withdrawal_bank_settlement_started_at,
                wr.wallet_withdrawal_bank_submitted_at,
                wr.wallet_withdrawal_created_at
            ) DESC,
            wr.wallet_withdrawal_id DESC
        LIMIT $limit
    ";

    $statement = $pdo->prepare($sql);
    $statement->execute($params);

    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function loadBankGatewayWithdrawal(
    PDO $pdo,
    int $withdrawalId,
    string $bankCode,
    bool $forUpdate = false
): array {
    if ($withdrawalId < 1) {
        throw new BankGatewayException('Invalid bank gateway withdrawal request.');
    }

    $bank = normalizeWalletWithdrawalBankCode($bankCode);

    if ($forUpdate && !$pdo->inTransaction()) {
        throw new BankGatewayException('Bank gateway transaction is required.');
    }

    $sql = "
        SELECT
            wr.*,
            customer.user_first_name,
            customer.user_last_name,
            customer.user_gmail,
            decision_operator.bank_gateway_operator_display_name AS decision_operator_name,
            starter.bank_gateway_operator_display_name AS settlement_started_by_name,
            settler.bank_gateway_operator_display_name AS settled_by_name
        FROM wallet_withdrawal_requests wr
        INNER JOIN users customer
            ON customer.user_id = wr.wallet_withdrawal_user_id
        LEFT JOIN bank_gateway_operators decision_operator
            ON decision_operator.bank_gateway_operator_id =
                wr.wallet_withdrawal_bank_decided_by
        LEFT JOIN bank_gateway_operators starter
            ON starter.bank_gateway_operator_id =
                wr.wallet_withdrawal_bank_settlement_started_by
        LEFT JOIN bank_gateway_operators settler
            ON settler.bank_gateway_operator_id =
                wr.wallet_withdrawal_bank_settled_by
        WHERE wr.wallet_withdrawal_id = ?
          AND wr.wallet_withdrawal_bank_code = ?
        LIMIT 1
    ";

    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }

    $statement = $pdo->prepare($sql);
    $statement->execute([$withdrawalId, $bank['code']]);
    $request = $statement->fetch(PDO::FETCH_ASSOC);

    if (!$request) {
        throw new BankGatewayException('The bank transfer instruction was not found.');
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
        throw new BankGatewayException('Bank gateway audit transaction is required.');
    }

    if (
        $operatorId < 1 ||
        $withdrawalId < 1 ||
        preg_match('/\A[a-z0-9_]{3,60}\z/', $action) !== 1 ||
        trim($details) === '' ||
        strlen($details) > 1000
    ) {
        throw new BankGatewayException('Invalid bank gateway audit event.');
    }

    $statement = $pdo->prepare("
        INSERT INTO bank_gateway_audit_logs (
            bank_gateway_log_operator_id,
            bank_gateway_log_withdrawal_id,
            bank_gateway_log_action,
            bank_gateway_log_details,
            bank_gateway_log_created_at
        )
        VALUES (
            ?, ?, ?, ?,
            CONVERT_TZ(UTC_TIMESTAMP(), '+00:00', '+08:00')
        )
    ");
    $statement->execute([
        $operatorId,
        $withdrawalId,
        $action,
        $details,
    ]);
}

function getBankGatewayAuditTrail(
    PDO $pdo,
    int $withdrawalId,
    string $bankCode,
    int $limit = 40
): array {
    $request = loadBankGatewayWithdrawal($pdo, $withdrawalId, $bankCode, false);
    $limit = max(1, min(100, $limit));

    $statement = $pdo->prepare("
        SELECT
            logs.bank_gateway_log_id,
            logs.bank_gateway_log_action,
            logs.bank_gateway_log_details,
            logs.bank_gateway_log_created_at,
            operator.bank_gateway_operator_display_name,
            operator.bank_gateway_operator_bank_name
        FROM bank_gateway_audit_logs logs
        INNER JOIN bank_gateway_operators operator
            ON operator.bank_gateway_operator_id = logs.bank_gateway_log_operator_id
        WHERE logs.bank_gateway_log_withdrawal_id = ?
        ORDER BY logs.bank_gateway_log_id DESC
        LIMIT $limit
    ");
    $statement->execute([(int) $request['wallet_withdrawal_id']]);

    return $statement->fetchAll(PDO::FETCH_ASSOC);
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
    assertBankGatewayOperatorBank($operator, $bankCode);

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
            ['approved', 'completed', 'failed'],
            true
        )) {
            throw new BankGatewayException(
                'This transfer instruction is no longer available for protected account access.'
            );
        }

        $accountNumber = decryptWalletWithdrawalAccountNumber(
            (string) $request['wallet_withdrawal_account_number_encrypted']
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
            'account_holder' => (string) $request['wallet_withdrawal_account_holder'],
            'bank_name' => (string) $request['wallet_withdrawal_bank_name'],
            'last4' => (string) $request['wallet_withdrawal_account_number_last4'],
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function bankGatewayReleaseReservedFunds(
    PDO $pdo,
    array $request,
    int $withdrawalId,
    string $failureReason
): int {
    walletRequireTransaction($pdo);

    if ((string) $request['wallet_withdrawal_status'] !== 'approved') {
        throw new BankGatewayException(
            'Only an active approved withdrawal can release reserved funds.'
        );
    }

    if (!empty($request['wallet_withdrawal_release_tx_id'])) {
        throw new BankGatewayException('Reserved funds were already released.');
    }

    if (!empty($request['wallet_withdrawal_debit_tx_id'])) {
        throw new BankGatewayException('This withdrawal was already debited.');
    }

    $userId = (int) $request['wallet_withdrawal_user_id'];
    $wallet = lockWalletAccount($pdo, $userId);
    $amountSen = moneyDecimalToSen(
        (string) $request['wallet_withdrawal_amount']
    );

    if (
        empty($request['wallet_withdrawal_reserved_tx_id']) ||
        (int) $wallet['wallet_reserved_sen'] < $amountSen
    ) {
        throw new BankGatewayException('Withdrawal reserve is inconsistent.');
    }

    $newReservedSen = (int) $wallet['wallet_reserved_sen'] - $amountSen;

    if ($newReservedSen < 0) {
        throw new BankGatewayException('Wallet reserve would become invalid.');
    }

    $updateWallet = $pdo->prepare("
        UPDATE wallet_accounts
        SET wallet_reserved_amount = ?,
            wallet_updated_at = NOW()
        WHERE wallet_id = ?
          AND wallet_user_id = ?
    ");
    $updateWallet->execute([
        moneySenToDecimal($newReservedSen),
        (int) $wallet['wallet_id'],
        $userId,
    ]);

    if ($updateWallet->rowCount() !== 1) {
        throw new BankGatewayException('Wallet reserve could not be released.');
    }

    $releaseTransactionId = insertWalletLedgerEvent(
        $pdo,
        (int) $wallet['wallet_id'],
        $userId,
        'release',
        'withdrawal_release',
        $amountSen,
        (int) $wallet['wallet_balance_sen'],
        $newReservedSen,
        'wallet_withdrawal',
        $withdrawalId,
        'withdrawal:release:' . $withdrawalId,
        'Released bank withdrawal reserve #' . str_pad(
            (string) $withdrawalId,
            4,
            '0',
            STR_PAD_LEFT
        )
    );

    $updateRequest = $pdo->prepare("
        UPDATE wallet_withdrawal_requests
        SET wallet_withdrawal_status = 'failed',
            wallet_withdrawal_failed_by = NULL,
            wallet_withdrawal_failed_at = UTC_TIMESTAMP(),
            wallet_withdrawal_failure_reason = ?,
            wallet_withdrawal_retry_expires_at_myt = DATE_ADD(
                CONVERT_TZ(UTC_TIMESTAMP(), '+00:00', '+08:00'),
                INTERVAL 3 DAY
            ),
            wallet_withdrawal_release_tx_id = ?
        WHERE wallet_withdrawal_id = ?
          AND wallet_withdrawal_status = 'approved'
          AND wallet_withdrawal_release_tx_id IS NULL
          AND wallet_withdrawal_debit_tx_id IS NULL
    ");
    $updateRequest->execute([
        $failureReason,
        $releaseTransactionId,
        $withdrawalId,
    ]);

    if ($updateRequest->rowCount() !== 1) {
        throw new BankGatewayException(
            'Withdrawal failure and wallet release could not be recorded.'
        );
    }

    return $releaseTransactionId;
}

function bankGatewayDebitSettledFunds(
    PDO $pdo,
    array $request,
    int $withdrawalId,
    string $settlementReference
): int {
    walletRequireTransaction($pdo);

    if ((string) $request['wallet_withdrawal_status'] !== 'approved') {
        throw new BankGatewayException(
            'Only an active approved withdrawal can be settled.'
        );
    }

    if (
        !empty($request['wallet_withdrawal_debit_tx_id']) ||
        !empty($request['wallet_withdrawal_release_tx_id'])
    ) {
        throw new BankGatewayException('This withdrawal already has a final ledger event.');
    }

    $userId = (int) $request['wallet_withdrawal_user_id'];
    $wallet = lockWalletAccount($pdo, $userId);
    $amountSen = moneyDecimalToSen(
        (string) $request['wallet_withdrawal_amount']
    );

    if (
        (int) $wallet['wallet_balance_sen'] < $amountSen ||
        (int) $wallet['wallet_reserved_sen'] < $amountSen
    ) {
        throw new BankGatewayException(
            'Wallet balance or reserved amount is inconsistent.'
        );
    }

    $newBalanceSen = (int) $wallet['wallet_balance_sen'] - $amountSen;
    $newReservedSen = (int) $wallet['wallet_reserved_sen'] - $amountSen;

    if ($newReservedSen > $newBalanceSen || $newReservedSen < 0) {
        throw new BankGatewayException('Wallet state would become inconsistent.');
    }

    $duplicateReference = $pdo->prepare("
        SELECT wallet_withdrawal_id
        FROM wallet_withdrawal_requests
        WHERE wallet_withdrawal_transfer_reference = ?
          AND wallet_withdrawal_id <> ?
        LIMIT 1
        FOR UPDATE
    ");
    $duplicateReference->execute([$settlementReference, $withdrawalId]);

    if ($duplicateReference->fetchColumn() !== false) {
        throw new BankGatewayException(
            'Generated settlement reference conflicts with another withdrawal. Please retry.'
        );
    }

    $updateWallet = $pdo->prepare("
        UPDATE wallet_accounts
        SET wallet_balance = ?,
            wallet_reserved_amount = ?,
            wallet_updated_at = NOW()
        WHERE wallet_id = ?
          AND wallet_user_id = ?
    ");
    $updateWallet->execute([
        moneySenToDecimal($newBalanceSen),
        moneySenToDecimal($newReservedSen),
        (int) $wallet['wallet_id'],
        $userId,
    ]);

    if ($updateWallet->rowCount() !== 1) {
        throw new BankGatewayException('Wallet could not record the settled debit.');
    }

    return insertWalletLedgerEvent(
        $pdo,
        (int) $wallet['wallet_id'],
        $userId,
        'debit',
        'withdrawal_complete',
        $amountSen,
        $newBalanceSen,
        $newReservedSen,
        'wallet_withdrawal',
        $withdrawalId,
        'withdrawal:complete:' . $withdrawalId,
        'Completed bank withdrawal #' . str_pad(
            (string) $withdrawalId,
            4,
            '0',
            STR_PAD_LEFT
        )
    );
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
    assertBankGatewayEnterpriseSchema($pdo);

    if (!in_array($decision, ['approve', 'reject'], true)) {
        throw new BankGatewayException('Invalid bank verification decision.');
    }

    $operator = verifyBankGatewayOperatorPassword(
        $pdo,
        $operatorId,
        $currentPassword
    );
    assertBankGatewayOperatorBank($operator, $bankCode);

    $note = normalizeBankGatewayDecisionNote(
        $decisionNote,
        $decision === 'reject'
    );

    $submittedReference = is_string($authorizationReference)
        ? trim($authorizationReference)
        : '';
    $reference = $decision === 'approve'
        ? ($submittedReference !== ''
            ? normalizeBankGatewayReference($submittedReference)
            : generateBankGatewayReference('AUTH', $bankCode, $withdrawalId))
        : generateBankGatewayReference('REJECT', $bankCode, $withdrawalId);

    try {
        $pdo->beginTransaction();
        $request = loadBankGatewayWithdrawal(
            $pdo,
            $withdrawalId,
            $bankCode,
            true
        );

        if (
            (string) $request['wallet_withdrawal_status'] !== 'approved' ||
            (string) $request['wallet_withdrawal_bank_status'] !== 'pending'
        ) {
            throw new BankGatewayException(
                'This verification instruction has already been processed or is no longer active.'
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
            $duplicate->execute([$reference, $withdrawalId]);

            if ($duplicate->fetchColumn() !== false) {
                throw new BankGatewayException(
                    'This bank authorization reference is already assigned to another request.'
                );
            }
        }

        $bankStatus = $decision === 'approve' ? 'approved' : 'rejected';
        $verificationHash = hash_hmac(
            'sha256',
            (string) $request['wallet_withdrawal_bank_submission_id'] . '|' .
                $bankStatus . '|' .
                (string) $reference . '|' .
                $operatorId . '|' .
                (string) $request['wallet_withdrawal_amount'],
            walletWithdrawalEncryptionKey()
        );

        $update = $pdo->prepare("
            UPDATE wallet_withdrawal_requests
            SET wallet_withdrawal_bank_status = ?,
                wallet_withdrawal_bank_decision_reference = ?,
                wallet_withdrawal_bank_decided_by = ?,
                wallet_withdrawal_bank_decided_at = UTC_TIMESTAMP(),
                wallet_withdrawal_bank_decision_note = ?,
                wallet_withdrawal_bank_verification_hash = ?,
                wallet_withdrawal_bank_settlement_status = ?
            WHERE wallet_withdrawal_id = ?
              AND wallet_withdrawal_status = 'approved'
              AND wallet_withdrawal_bank_status = 'pending'
        ");
        $update->execute([
            $bankStatus,
            $reference,
            $operatorId,
            $note !== '' ? $note : null,
            $verificationHash,
            $decision === 'approve' ? 'ready' : 'not_required',
            $withdrawalId,
        ]);

        if ($update->rowCount() !== 1) {
            throw new BankGatewayException('The bank decision could not be recorded.');
        }

        if ($decision === 'reject') {
            bankGatewayReleaseReservedFunds(
                $pdo,
                $request,
                $withdrawalId,
                'Destination bank rejected verification: ' . $note
            );
        }

        insertBankGatewayAuditLog(
            $pdo,
            $operatorId,
            $withdrawalId,
            $decision === 'approve'
                ? 'accept_for_settlement'
                : 'reject_and_release',
            $decision === 'approve'
                ? 'Verification accepted. Instruction released to the settlement queue with authorization reference ' . $reference . '.'
                : 'Verification rejected. Reserved wallet funds were released automatically. Reason: ' . $note
        );

        $pdo->commit();

        $request['wallet_withdrawal_bank_status'] = $bankStatus;
        $request['wallet_withdrawal_bank_decision_reference'] = $reference;
        $request['wallet_withdrawal_bank_decision_note'] = $note;
        $request['wallet_withdrawal_bank_settlement_status'] =
            $decision === 'approve' ? 'ready' : 'not_required';
        if ($decision === 'reject') {
            $request['wallet_withdrawal_status'] = 'failed';
            $request['wallet_withdrawal_failure_reason'] =
                'Destination bank rejected verification: ' . $note;
        }

        return $request;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function startBankGatewaySettlement(
    PDO $pdo,
    int $withdrawalId,
    int $operatorId,
    string $bankCode,
    mixed $currentPassword,
    mixed $settlementNote = ''
): array {
    assertBankGatewayEnterpriseSchema($pdo);
    $operator = verifyBankGatewayOperatorPassword(
        $pdo,
        $operatorId,
        $currentPassword
    );
    assertBankGatewayOperatorBank($operator, $bankCode);
    $note = normalizeBankGatewayDecisionNote($settlementNote, false);

    try {
        $pdo->beginTransaction();
        $request = loadBankGatewayWithdrawal(
            $pdo,
            $withdrawalId,
            $bankCode,
            true
        );

        if (
            (string) $request['wallet_withdrawal_status'] !== 'approved' ||
            (string) $request['wallet_withdrawal_bank_status'] !== 'approved' ||
            (string) ($request['wallet_withdrawal_bank_settlement_status'] ?? '') !== 'ready'
        ) {
            throw new BankGatewayException(
                'Only a bank-accepted instruction that is ready for settlement can be started.'
            );
        }

        $update = $pdo->prepare("
            UPDATE wallet_withdrawal_requests
            SET wallet_withdrawal_bank_settlement_status = 'processing',
                wallet_withdrawal_bank_settlement_started_by = ?,
                wallet_withdrawal_bank_settlement_started_at = UTC_TIMESTAMP(),
                wallet_withdrawal_bank_settlement_note = ?
            WHERE wallet_withdrawal_id = ?
              AND wallet_withdrawal_status = 'approved'
              AND wallet_withdrawal_bank_status = 'approved'
              AND wallet_withdrawal_bank_settlement_status = 'ready'
        ");
        $update->execute([
            $operatorId,
            $note !== '' ? $note : null,
            $withdrawalId,
        ]);

        if ($update->rowCount() !== 1) {
            throw new BankGatewayException('Settlement could not be started.');
        }

        insertBankGatewayAuditLog(
            $pdo,
            $operatorId,
            $withdrawalId,
            'start_settlement',
            'Settlement processing started. Reserved wallet funds remain locked until final settlement.'
        );

        $pdo->commit();
        $request['wallet_withdrawal_bank_settlement_status'] = 'processing';
        return $request;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function settleBankGatewayWithdrawal(
    PDO $pdo,
    int $withdrawalId,
    int $operatorId,
    string $bankCode,
    mixed $currentPassword,
    mixed $settlementNote = ''
): array {
    assertBankGatewayEnterpriseSchema($pdo);
    $operator = verifyBankGatewayOperatorPassword(
        $pdo,
        $operatorId,
        $currentPassword
    );
    assertBankGatewayOperatorBank($operator, $bankCode);
    $note = normalizeBankGatewayDecisionNote($settlementNote, false);
    $settlementReference = generateBankGatewayReference(
        'SETTLE',
        $bankCode,
        $withdrawalId
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
            (string) $request['wallet_withdrawal_status'] !== 'approved' ||
            (string) $request['wallet_withdrawal_bank_status'] !== 'approved' ||
            (string) ($request['wallet_withdrawal_bank_settlement_status'] ?? '') !== 'processing'
        ) {
            throw new BankGatewayException(
                'Only an instruction in settlement processing can be confirmed as settled.'
            );
        }

        $debitTransactionId = bankGatewayDebitSettledFunds(
            $pdo,
            $request,
            $withdrawalId,
            $settlementReference
        );

        $settlementHash = hash_hmac(
            'sha256',
            (string) $request['wallet_withdrawal_bank_submission_id'] . '|' .
                $settlementReference . '|' .
                $operatorId . '|' .
                (string) $request['wallet_withdrawal_amount'] . '|settled',
            walletWithdrawalEncryptionKey()
        );

        $update = $pdo->prepare("
            UPDATE wallet_withdrawal_requests
            SET wallet_withdrawal_status = 'completed',
                wallet_withdrawal_transfer_reference = ?,
                wallet_withdrawal_debit_tx_id = ?,
                wallet_withdrawal_completed_at = UTC_TIMESTAMP(),
                wallet_withdrawal_bank_settlement_status = 'settled',
                wallet_withdrawal_bank_settled_by = ?,
                wallet_withdrawal_bank_settled_at = UTC_TIMESTAMP(),
                wallet_withdrawal_bank_settlement_note = ?,
                wallet_withdrawal_bank_settlement_hash = ?
            WHERE wallet_withdrawal_id = ?
              AND wallet_withdrawal_status = 'approved'
              AND wallet_withdrawal_bank_status = 'approved'
              AND wallet_withdrawal_bank_settlement_status = 'processing'
              AND wallet_withdrawal_debit_tx_id IS NULL
              AND wallet_withdrawal_release_tx_id IS NULL
        ");
        $update->execute([
            $settlementReference,
            $debitTransactionId,
            $operatorId,
            $note !== '' ? $note : null,
            $settlementHash,
            $withdrawalId,
        ]);

        if ($update->rowCount() !== 1) {
            throw new BankGatewayException('Final settlement could not be recorded.');
        }

        insertBankGatewayAuditLog(
            $pdo,
            $operatorId,
            $withdrawalId,
            'confirm_settlement',
            'Settlement confirmed. Wallet reserve was permanently debited and settlement reference ' . $settlementReference . ' was issued.'
        );

        $pdo->commit();

        $request['wallet_withdrawal_status'] = 'completed';
        $request['wallet_withdrawal_transfer_reference'] = $settlementReference;
        $request['wallet_withdrawal_bank_settlement_status'] = 'settled';
        $request['wallet_withdrawal_bank_settlement_hash'] = $settlementHash;
        return $request;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function failBankGatewaySettlement(
    PDO $pdo,
    int $withdrawalId,
    int $operatorId,
    string $bankCode,
    mixed $currentPassword,
    mixed $failureReason
): array {
    assertBankGatewayEnterpriseSchema($pdo);
    $operator = verifyBankGatewayOperatorPassword(
        $pdo,
        $operatorId,
        $currentPassword
    );
    assertBankGatewayOperatorBank($operator, $bankCode);
    $reason = normalizeBankGatewayDecisionNote($failureReason, true);

    try {
        $pdo->beginTransaction();
        $request = loadBankGatewayWithdrawal(
            $pdo,
            $withdrawalId,
            $bankCode,
            true
        );

        if (
            (string) $request['wallet_withdrawal_status'] !== 'approved' ||
            (string) $request['wallet_withdrawal_bank_status'] !== 'approved' ||
            (string) ($request['wallet_withdrawal_bank_settlement_status'] ?? '') !== 'processing'
        ) {
            throw new BankGatewayException(
                'Only an instruction in settlement processing can be marked as failed.'
            );
        }

        bankGatewayReleaseReservedFunds(
            $pdo,
            $request,
            $withdrawalId,
            'Bank settlement failed: ' . $reason
        );

        $update = $pdo->prepare("
            UPDATE wallet_withdrawal_requests
            SET wallet_withdrawal_bank_settlement_status = 'failed',
                wallet_withdrawal_bank_settled_by = ?,
                wallet_withdrawal_bank_settled_at = UTC_TIMESTAMP(),
                wallet_withdrawal_bank_settlement_note = ?
            WHERE wallet_withdrawal_id = ?
              AND wallet_withdrawal_status = 'failed'
              AND wallet_withdrawal_bank_status = 'approved'
              AND wallet_withdrawal_bank_settlement_status = 'processing'
        ");
        $update->execute([$operatorId, $reason, $withdrawalId]);

        if ($update->rowCount() !== 1) {
            throw new BankGatewayException('Settlement failure could not be recorded.');
        }

        insertBankGatewayAuditLog(
            $pdo,
            $operatorId,
            $withdrawalId,
            'fail_settlement',
            'Settlement failed and reserved wallet funds were released automatically. Reason: ' . $reason
        );

        $pdo->commit();
        $request['wallet_withdrawal_status'] = 'failed';
        $request['wallet_withdrawal_bank_settlement_status'] = 'failed';
        $request['wallet_withdrawal_failure_reason'] = 'Bank settlement failed: ' . $reason;
        return $request;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function reconcileRejectedBankGatewayWithdrawal(
    PDO $pdo,
    int $withdrawalId,
    int $operatorId,
    string $bankCode,
    mixed $currentPassword
): array {
    assertBankGatewayEnterpriseSchema($pdo);
    $operator = verifyBankGatewayOperatorPassword(
        $pdo,
        $operatorId,
        $currentPassword
    );
    assertBankGatewayOperatorBank($operator, $bankCode);

    try {
        $pdo->beginTransaction();
        $request = loadBankGatewayWithdrawal(
            $pdo,
            $withdrawalId,
            $bankCode,
            true
        );

        if (
            (string) $request['wallet_withdrawal_bank_status'] !== 'rejected' ||
            (string) $request['wallet_withdrawal_status'] !== 'approved'
        ) {
            throw new BankGatewayException(
                'This instruction does not contain a rejected-bank reconciliation exception.'
            );
        }

        $reason = trim(
            (string) ($request['wallet_withdrawal_bank_decision_note'] ?? '')
        );
        if ($reason === '') {
            $reason = 'Historical bank rejection reconciled by the institution operations desk.';
        }

        bankGatewayReleaseReservedFunds(
            $pdo,
            $request,
            $withdrawalId,
            'Destination bank rejected verification: ' . $reason
        );

        $update = $pdo->prepare("
            UPDATE wallet_withdrawal_requests
            SET wallet_withdrawal_bank_settlement_status = 'not_required'
            WHERE wallet_withdrawal_id = ?
              AND wallet_withdrawal_status = 'failed'
              AND wallet_withdrawal_bank_status = 'rejected'
        ");
        $update->execute([$withdrawalId]);

        insertBankGatewayAuditLog(
            $pdo,
            $operatorId,
            $withdrawalId,
            'reconcile_legacy_rejection',
            'Historical rejected instruction reconciled. Reserved wallet funds were released and merchant withdrawal status was synchronized.'
        );

        $pdo->commit();
        $request['wallet_withdrawal_status'] = 'failed';
        $request['wallet_withdrawal_bank_settlement_status'] = 'not_required';
        return $request;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function bankGatewayInstructionStage(array $request): array
{
    $withdrawalStatus = (string) ($request['wallet_withdrawal_status'] ?? '');
    $bankStatus = (string) ($request['wallet_withdrawal_bank_status'] ?? 'not_submitted');
    $settlementStatus = (string) (
        $request['wallet_withdrawal_bank_settlement_status'] ?? 'not_required'
    );

    if ($withdrawalStatus === 'approved' && $bankStatus === 'pending') {
        return ['pending', 'Pending Review', 'Review'];
    }

    if (
        $withdrawalStatus === 'approved' &&
        $bankStatus === 'approved' &&
        $settlementStatus === 'ready'
    ) {
        return ['accepted', 'Accepted', 'Settlement Ready'];
    }

    if (
        $withdrawalStatus === 'approved' &&
        $bankStatus === 'approved' &&
        $settlementStatus === 'processing'
    ) {
        return ['processing', 'Processing', 'Settlement'];
    }

    if (
        $withdrawalStatus === 'completed' &&
        $settlementStatus === 'settled'
    ) {
        return ['settled', 'Settled', 'Completed'];
    }

    if (
        $withdrawalStatus === 'approved' &&
        $bankStatus === 'rejected'
    ) {
        return ['exception', 'Reconciliation Exception', 'Exception'];
    }

    if ($withdrawalStatus === 'failed' && $bankStatus === 'rejected') {
        return ['rejected', 'Rejected', 'Funds Released'];
    }

    if ($withdrawalStatus === 'failed' && $settlementStatus === 'failed') {
        return ['failed', 'Settlement Failed', 'Funds Released'];
    }

    return ['other', ucfirst($withdrawalStatus ?: 'Unknown'), 'Record'];
}
