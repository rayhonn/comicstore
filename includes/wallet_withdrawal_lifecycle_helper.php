<?php

require_once __DIR__ . '/wallet_withdrawal_helper.php';

const WALLET_WITHDRAWAL_RETRY_DAYS = 3;

/**
 * Use Malaysia time for MySQL NOW() comparisons on wallet-withdrawal pages.
 * UTC_TIMESTAMP() remains UTC and is unaffected by this session setting.
 */
function walletWithdrawalUseMalaysiaDatabaseTime(PDO $pdo): void
{
    $pdo->exec("SET time_zone = '+08:00'");
}

function assertWalletWithdrawalLifecycleSchema(PDO $pdo): void
{
    $statement = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'wallet_withdrawal_requests'
          AND COLUMN_NAME = 'wallet_withdrawal_retry_expires_at_myt'
    ");
    $statement->execute();

    if ((int) $statement->fetchColumn() !== 1) {
        throw new WalletWithdrawalException(
            'Wallet withdrawal lifecycle schema is not installed. Run database/20260825_wallet_withdrawal_lifecycle_final.sql first.'
        );
    }
}

function walletWithdrawalMalaysiaWallClock(
    mixed $value,
    string $format = 'd M Y, h:i A',
    string $fallback = 'Not available'
): string {
    $value = trim((string) $value);
    if ($value === '') {
        return $fallback;
    }

    try {
        return (new DateTimeImmutable(
            $value,
            new DateTimeZone('Asia/Kuala_Lumpur')
        ))->format($format);
    } catch (Throwable) {
        return $fallback;
    }
}

/**
 * Withdrawal timestamps created before the 24 Aug 2026 02:04 MYT UTC-storage rollout
 * were written by the local XAMPP/MySQL session as Malaysia wall-clock time.
 * Bank decision/settlement timestamps were introduced later and are UTC.
 * This formatter fixes those legacy merchant-side events without mutating
 * historical database evidence.
 */
function walletWithdrawalLifecycleEventMyt(
    array $request,
    string $field,
    string $format = 'd M Y, h:i A',
    string $fallback = 'Not available'
): string {
    $value = trim((string) ($request[$field] ?? ''));
    if ($value === '') {
        return $fallback;
    }

    $created = trim((string) (
        $request['wallet_withdrawal_created_at'] ?? ''
    ));
    $legacyRecord = $created !== '' &&
        strcmp($created, '2026-08-23 18:04:29') < 0;

    $legacyLocalFields = [
        'wallet_withdrawal_created_at',
        'wallet_withdrawal_reviewed_at',
        'wallet_withdrawal_bank_submitted_at',
    ];

    if ($legacyRecord && in_array($field, $legacyLocalFields, true)) {
        return walletWithdrawalMalaysiaWallClock(
            $value,
            $format,
            $fallback
        );
    }

    return walletWithdrawalMalaysiaDateTime(
        $value,
        $format,
        $fallback
    );
}

function walletWithdrawalRetryDeadlineLabel(
    array $request,
    string $format = 'd M Y, h:i A'
): string {
    return walletWithdrawalMalaysiaWallClock(
        $request['wallet_withdrawal_retry_expires_at_myt'] ?? '',
        $format,
        ''
    );
}

function walletWithdrawalRetryIsActive(array $request): bool
{
    $value = trim((string) (
        $request['wallet_withdrawal_retry_expires_at_myt'] ?? ''
    ));
    if ($value === '') {
        return false;
    }

    try {
        $deadline = new DateTimeImmutable(
            $value,
            new DateTimeZone('Asia/Kuala_Lumpur')
        );
        $now = new DateTimeImmutable(
            'now',
            new DateTimeZone('Asia/Kuala_Lumpur')
        );
        return $deadline > $now;
    } catch (Throwable) {
        return false;
    }
}

function loadWalletEligibleRefundCreditsLifecycle(
    PDO $pdo,
    int $userId,
    bool $forUpdate = false
): array {
    assertWalletWithdrawalLifecycleSchema($pdo);

    if ($userId < 1) {
        throw new WalletWithdrawalException('Invalid wallet customer.');
    }
    if ($forUpdate) {
        walletRequireTransaction($pdo);
    }

    $sql = "
        SELECT
            wallet_refund_credit_id,
            wallet_refund_credit_source_type,
            wallet_refund_credit_source_id,
            wallet_refund_credit_amount,
            wallet_refund_credit_credited_at,
            wallet_refund_credit_withdrawal_expires_at,
            CASE
                WHEN wallet_refund_credit_withdrawal_expires_at > NOW()
                THEN 1 ELSE 0
            END AS original_window_active
        FROM wallet_refund_credits
        WHERE wallet_refund_credit_user_id = ?
        ORDER BY wallet_refund_credit_id ASC
    ";
    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }

    $statement = $pdo->prepare($sql);
    $statement->execute([$userId]);
    $credits = $statement->fetchAll(PDO::FETCH_ASSOC);
    if ($credits === []) {
        return [];
    }

    $consumedStatement = $pdo->prepare("
        SELECT
            wa.wallet_withdrawal_allocation_refund_credit_id AS refund_credit_id,
            COALESCE(SUM(wa.wallet_withdrawal_allocation_amount), 0) AS allocated_amount
        FROM wallet_withdrawal_allocations wa
        INNER JOIN wallet_withdrawal_requests wr
            ON wr.wallet_withdrawal_id = wa.wallet_withdrawal_allocation_request_id
        WHERE wr.wallet_withdrawal_user_id = ?
          AND wr.wallet_withdrawal_status IN ('pending', 'approved', 'completed')
        GROUP BY wa.wallet_withdrawal_allocation_refund_credit_id
    ");
    $consumedStatement->execute([$userId]);
    $consumedByCredit = [];
    foreach ($consumedStatement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $consumedByCredit[(int) $row['refund_credit_id']] =
            moneyDecimalToSen((string) $row['allocated_amount']);
    }

    $retryStatement = $pdo->prepare("
        SELECT
            wa.wallet_withdrawal_allocation_refund_credit_id AS refund_credit_id,
            COALESCE(SUM(wa.wallet_withdrawal_allocation_amount), 0) AS retry_amount,
            MIN(wr.wallet_withdrawal_retry_expires_at_myt) AS retry_expires_at_myt
        FROM wallet_withdrawal_allocations wa
        INNER JOIN wallet_withdrawal_requests wr
            ON wr.wallet_withdrawal_id = wa.wallet_withdrawal_allocation_request_id
        WHERE wr.wallet_withdrawal_user_id = ?
          AND wr.wallet_withdrawal_status = 'failed'
          AND wr.wallet_withdrawal_release_tx_id IS NOT NULL
          AND wr.wallet_withdrawal_retry_expires_at_myt > NOW()
          AND (
              wr.wallet_withdrawal_bank_status = 'rejected'
              OR wr.wallet_withdrawal_bank_settlement_status = 'failed'
          )
        GROUP BY wa.wallet_withdrawal_allocation_refund_credit_id
    ");
    $retryStatement->execute([$userId]);
    $retryByCredit = [];
    foreach ($retryStatement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $retryByCredit[(int) $row['refund_credit_id']] = [
            'amount_sen' => moneyDecimalToSen((string) $row['retry_amount']),
            'expires_at_myt' => (string) ($row['retry_expires_at_myt'] ?? ''),
        ];
    }

    $eligible = [];
    foreach ($credits as $credit) {
        $creditId = (int) $credit['wallet_refund_credit_id'];
        $amountSen = moneyDecimalToSen(
            (string) $credit['wallet_refund_credit_amount']
        );
        $consumedSen = $consumedByCredit[$creditId] ?? 0;
        $remainingSen = max(0, $amountSen - $consumedSen);
        if ($remainingSen < 1) {
            continue;
        }

        $originalActive = (int) $credit['original_window_active'] === 1;
        $retry = $retryByCredit[$creditId] ?? null;
        $retrySen = $retry !== null
            ? min($remainingSen, (int) $retry['amount_sen'])
            : 0;

        if (!$originalActive && $retrySen < 1) {
            continue;
        }

        $eligibleSen = $originalActive ? $remainingSen : $retrySen;
        $effectiveExpiry = $originalActive
            ? (string) $credit['wallet_refund_credit_withdrawal_expires_at']
            : (string) ($retry['expires_at_myt'] ?? '');

        $credit['amount_sen'] = $amountSen;
        $credit['allocated_sen'] = $consumedSen;
        $credit['remaining_sen'] = $eligibleSen;
        $credit['eligibility_source'] = $originalActive ? 'original' : 'retry';
        $credit['effective_expires_at_myt'] = $effectiveExpiry;
        $eligible[] = $credit;
    }

    usort($eligible, static function (array $a, array $b): int {
        return strcmp(
            (string) ($a['effective_expires_at_myt'] ?? ''),
            (string) ($b['effective_expires_at_myt'] ?? '')
        );
    });

    return $eligible;
}

function getWalletWithdrawalSummaryLifecycle(PDO $pdo, int $userId): array
{
    walletWithdrawalUseMalaysiaDatabaseTime($pdo);
    $credits = loadWalletEligibleRefundCreditsLifecycle($pdo, $userId, false);

    $eligibleSen = 0;
    $retryEligibleSen = 0;
    $earliestExpiry = null;
    $retryExpiry = null;

    foreach ($credits as $credit) {
        $remaining = (int) $credit['remaining_sen'];
        $eligibleSen += $remaining;
        $expiry = trim((string) ($credit['effective_expires_at_myt'] ?? ''));
        if ($expiry !== '' && ($earliestExpiry === null || strcmp($expiry, $earliestExpiry) < 0)) {
            $earliestExpiry = $expiry;
        }
        if (($credit['eligibility_source'] ?? '') === 'retry') {
            $retryEligibleSen += $remaining;
            if ($expiry !== '' && ($retryExpiry === null || strcmp($expiry, $retryExpiry) < 0)) {
                $retryExpiry = $expiry;
            }
        }
    }

    $activeStatement = $pdo->prepare("
        SELECT *
        FROM wallet_withdrawal_requests
        WHERE wallet_withdrawal_user_id = ?
          AND wallet_withdrawal_status IN ('pending', 'approved')
        ORDER BY wallet_withdrawal_id DESC
        LIMIT 1
    ");
    $activeStatement->execute([$userId]);
    $active = $activeStatement->fetch(PDO::FETCH_ASSOC);

    return [
        'eligible_sen' => $eligibleSen,
        'retry_eligible_sen' => $retryEligibleSen,
        'earliest_expiry' => $earliestExpiry,
        'retry_expires_at_myt' => $retryExpiry,
        'active_request' => $active ?: null,
    ];
}

function getCustomerWalletWithdrawalsLifecycle(
    PDO $pdo,
    int $userId,
    int $limit = 20
): array {
    assertWalletWithdrawalLifecycleSchema($pdo);
    $limit = max(1, min(100, $limit));

    $statement = $pdo->prepare("
        SELECT
            wr.*,
            decision_operator.bank_gateway_operator_display_name AS bank_decision_operator,
            starter.bank_gateway_operator_display_name AS bank_settlement_starter,
            settler.bank_gateway_operator_display_name AS bank_settlement_operator
        FROM wallet_withdrawal_requests wr
        LEFT JOIN bank_gateway_operators decision_operator
            ON decision_operator.bank_gateway_operator_id = wr.wallet_withdrawal_bank_decided_by
        LEFT JOIN bank_gateway_operators starter
            ON starter.bank_gateway_operator_id = wr.wallet_withdrawal_bank_settlement_started_by
        LEFT JOIN bank_gateway_operators settler
            ON settler.bank_gateway_operator_id = wr.wallet_withdrawal_bank_settled_by
        WHERE wr.wallet_withdrawal_user_id = ?
        ORDER BY wr.wallet_withdrawal_id DESC
        LIMIT $limit
    ");
    $statement->execute([$userId]);
    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function createWalletWithdrawalRequestLifecycle(
    PDO $pdo,
    int $userId,
    int $amountSen,
    string $bankCode,
    string $accountHolder,
    string $accountNumber,
    mixed $currentPassword
): int {
    assertWalletWithdrawalLifecycleSchema($pdo);
    walletWithdrawalUseMalaysiaDatabaseTime($pdo);

    if ($userId < 1 || $amountSen < 100) {
        throw new WalletWithdrawalException('Invalid bank withdrawal request.');
    }

    $bank = normalizeWalletWithdrawalBankCode($bankCode);
    $accountHolder = normalizeWalletWithdrawalAccountHolder($accountHolder);
    $accountNumber = normalizeWalletWithdrawalAccountNumber($accountNumber);

    verifyWalletActorPassword(
        $pdo,
        $userId,
        'customer',
        $currentPassword
    );
    assertWalletWithdrawalHolderMatchesCustomer(
        $pdo,
        $userId,
        $accountHolder
    );

    $encryptedAccount = encryptWalletWithdrawalAccountNumber($accountNumber);
    $last4 = substr($accountNumber, -4);
    $fingerprint = walletWithdrawalAccountFingerprint(
        $bank['code'],
        $accountNumber
    );

    try {
        $pdo->beginTransaction();
        $wallet = lockWalletAccount($pdo, $userId);

        $activeStatement = $pdo->prepare("
            SELECT wallet_withdrawal_id
            FROM wallet_withdrawal_requests
            WHERE wallet_withdrawal_user_id = ?
              AND wallet_withdrawal_status IN ('pending', 'approved')
            LIMIT 1
            FOR UPDATE
        ");
        $activeStatement->execute([$userId]);
        if ($activeStatement->fetchColumn() !== false) {
            throw new WalletWithdrawalException(
                'You already have an active bank withdrawal request. Please wait for it to finish before submitting another request.'
            );
        }

        $credits = loadWalletEligibleRefundCreditsLifecycle(
            $pdo,
            $userId,
            true
        );
        $eligibleSen = 0;
        foreach ($credits as $credit) {
            $eligibleSen += (int) $credit['remaining_sen'];
        }

        if (
            $amountSen > $eligibleSen ||
            $amountSen > (int) $wallet['wallet_available_sen']
        ) {
            throw new WalletWithdrawalException(
                'Requested amount exceeds the refund balance currently eligible for bank withdrawal.'
            );
        }

        $insert = $pdo->prepare("
            INSERT INTO wallet_withdrawal_requests (
                wallet_withdrawal_wallet_id,
                wallet_withdrawal_user_id,
                wallet_withdrawal_amount,
                wallet_withdrawal_status,
                wallet_withdrawal_bank_code,
                wallet_withdrawal_bank_name,
                wallet_withdrawal_account_holder,
                wallet_withdrawal_account_number_encrypted,
                wallet_withdrawal_account_number_last4,
                wallet_withdrawal_account_fingerprint,
                wallet_withdrawal_customer_reauthenticated_at,
                wallet_withdrawal_created_at
            ) VALUES (
                ?, ?, ?, 'pending', ?, ?, ?, ?, ?, ?,
                UTC_TIMESTAMP(), UTC_TIMESTAMP()
            )
        ");
        $insert->execute([
            (int) $wallet['wallet_id'],
            $userId,
            moneySenToDecimal($amountSen),
            $bank['code'],
            $bank['name'],
            $accountHolder,
            $encryptedAccount,
            $last4,
            $fingerprint,
        ]);

        $withdrawalId = (int) $pdo->lastInsertId();
        if ($withdrawalId < 1) {
            throw new WalletWithdrawalException(
                'Bank withdrawal request could not be created.'
            );
        }

        $allocationInsert = $pdo->prepare("
            INSERT INTO wallet_withdrawal_allocations (
                wallet_withdrawal_allocation_request_id,
                wallet_withdrawal_allocation_refund_credit_id,
                wallet_withdrawal_allocation_amount
            ) VALUES (?, ?, ?)
        ");

        $remainingToAllocate = $amountSen;
        foreach ($credits as $credit) {
            if ($remainingToAllocate < 1) {
                break;
            }
            $allocationSen = min(
                $remainingToAllocate,
                (int) $credit['remaining_sen']
            );
            if ($allocationSen < 1) {
                continue;
            }
            $allocationInsert->execute([
                $withdrawalId,
                (int) $credit['wallet_refund_credit_id'],
                moneySenToDecimal($allocationSen),
            ]);
            $remainingToAllocate -= $allocationSen;
        }

        if ($remainingToAllocate !== 0) {
            throw new WalletWithdrawalException(
                'Refund allocation could not be completed.'
            );
        }

        $newReservedSen = (int) $wallet['wallet_reserved_sen'] + $amountSen;
        if ($newReservedSen > (int) $wallet['wallet_balance_sen']) {
            throw new WalletWithdrawalException(
                'Wallet reserve would exceed the wallet balance.'
            );
        }

        $updateWallet = $pdo->prepare("
            UPDATE wallet_accounts
            SET wallet_reserved_amount = ?,
                wallet_updated_at = UTC_TIMESTAMP()
            WHERE wallet_id = ?
              AND wallet_user_id = ?
        ");
        $updateWallet->execute([
            moneySenToDecimal($newReservedSen),
            (int) $wallet['wallet_id'],
            $userId,
        ]);
        if ($updateWallet->rowCount() !== 1) {
            throw new WalletWithdrawalException(
                'Wallet reserve could not be updated.'
            );
        }

        $reserveTransactionId = insertWalletLedgerEvent(
            $pdo,
            (int) $wallet['wallet_id'],
            $userId,
            'reserve',
            'withdrawal_reserve',
            $amountSen,
            (int) $wallet['wallet_balance_sen'],
            $newReservedSen,
            'wallet_withdrawal',
            $withdrawalId,
            'withdrawal:reserve:' . $withdrawalId,
            'Reserved for bank withdrawal #' . str_pad(
                (string) $withdrawalId,
                4,
                '0',
                STR_PAD_LEFT
            )
        );

        $updateRequest = $pdo->prepare("
            UPDATE wallet_withdrawal_requests
            SET wallet_withdrawal_reserved_tx_id = ?
            WHERE wallet_withdrawal_id = ?
              AND wallet_withdrawal_user_id = ?
              AND wallet_withdrawal_status = 'pending'
              AND wallet_withdrawal_reserved_tx_id IS NULL
        ");
        $updateRequest->execute([
            $reserveTransactionId,
            $withdrawalId,
            $userId,
        ]);
        if ($updateRequest->rowCount() !== 1) {
            throw new WalletWithdrawalException(
                'Withdrawal reserve could not be linked.'
            );
        }

        $pdo->commit();
        return $withdrawalId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function walletWithdrawalCustomerStage(array $request): array
{
    $status = (string) ($request['wallet_withdrawal_status'] ?? '');
    $bank = (string) ($request['wallet_withdrawal_bank_status'] ?? 'not_submitted');
    $settlement = (string) (
        $request['wallet_withdrawal_bank_settlement_status'] ?? 'not_required'
    );

    if ($status === 'pending') {
        return ['pending', 'Pending Admin Review', 'Your refund amount is reserved.'];
    }
    if ($status === 'approved' && $bank === 'pending') {
        return ['bank_review', 'Bank Verification', 'MangaVault approved the request and the destination bank is reviewing it.'];
    }
    if ($status === 'approved' && $bank === 'approved' && $settlement === 'ready') {
        return ['accepted', 'Bank Accepted', 'The destination bank accepted the instruction for settlement.'];
    }
    if ($status === 'approved' && $bank === 'approved' && $settlement === 'processing') {
        return ['processing', 'Settlement Processing', 'The bank is processing the transfer.'];
    }
    if ($status === 'completed' && $settlement === 'settled') {
        return ['settled', 'Settled', 'The transfer settled successfully and the wallet debit was posted.'];
    }
    if ($status === 'failed' && $bank === 'rejected') {
        return ['rejected', 'Bank Rejected', 'The bank rejected verification and the reserved funds were released automatically.'];
    }
    if ($status === 'failed' && $settlement === 'failed') {
        return ['failed', 'Settlement Failed', 'Settlement failed and the reserved funds were released automatically.'];
    }
    if ($status === 'rejected') {
        return ['merchant_rejected', 'Rejected by MangaVault', 'The request was rejected before bank submission and the reserve was released.'];
    }
    if ($status === 'approved' && $bank === 'rejected') {
        return ['exception', 'Reconciliation Exception', 'This is a legacy record awaiting state synchronization.'];
    }

    return ['other', ucfirst($status !== '' ? $status : 'Record'), 'Withdrawal record'];
}
