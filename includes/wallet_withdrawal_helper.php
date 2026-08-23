<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/wallet_helper.php';

final class WalletWithdrawalException extends RuntimeException
{
}

function walletWithdrawalSupportedBanks(): array
{
    return [
        'MBB' => 'Maybank',
        'CIMB' => 'CIMB Bank',
        'PBB' => 'Public Bank',
        'RHB' => 'RHB Bank',
        'HLB' => 'Hong Leong Bank',
        'AMB' => 'AmBank',
        'BIMB' => 'Bank Islam',
        'BSN' => 'Bank Simpanan Nasional',
        'AFFIN' => 'Affin Bank',
        'ALLIANCE' => 'Alliance Bank',
        'BKR' => 'Bank Rakyat',
        'OCBC' => 'OCBC Bank Malaysia',
        'UOB' => 'UOB Malaysia',
        'HSBC' => 'HSBC Malaysia',
        'SCB' => 'Standard Chartered Malaysia',
    ];
}

function walletWithdrawalBusinessDayDeadline(
    string $approvedAt,
    int $businessDays = 14
): ?DateTimeImmutable {
    $approvedAt = trim($approvedAt);

    if (
        $approvedAt === '' ||
        $businessDays < 1 ||
        $businessDays > 60
    ) {
        return null;
    }

    try {
        $deadline = new DateTimeImmutable(
            $approvedAt,
            new DateTimeZone(
                'Asia/Kuala_Lumpur'
            )
        );
    } catch (Throwable) {
        return null;
    }

    $remaining = $businessDays;

    while ($remaining > 0) {
        $deadline = $deadline->modify('+1 day');
        $weekday = (int) $deadline->format('N');

        if ($weekday <= 5) {
            $remaining--;
        }
    }

    return $deadline;
}

function walletWithdrawalBusinessDayDeadlineLabel(
    string $approvedAt,
    int $businessDays = 14
): string {
    $deadline = walletWithdrawalBusinessDayDeadline(
        $approvedAt,
        $businessDays
    );

    return $deadline instanceof DateTimeImmutable
        ? $deadline->format('d M Y')
        : '';
}

function normalizeWalletWithdrawalBankCode(
    mixed $value
): array {
    if (!is_string($value)) {
        throw new WalletWithdrawalException(
            'Please select a valid bank.'
        );
    }

    $code = strtoupper(trim($value));
    $banks = walletWithdrawalSupportedBanks();

    if (!isset($banks[$code])) {
        throw new WalletWithdrawalException(
            'Please select a supported Malaysian bank.'
        );
    }

    return [
        'code' => $code,
        'name' => $banks[$code],
    ];
}

function normalizeWalletWithdrawalAccountHolder(
    mixed $value
): string {
    if (!is_string($value)) {
        throw new WalletWithdrawalException(
            'Bank account holder name is invalid.'
        );
    }

    $value = preg_replace(
        '/\s+/u',
        ' ',
        trim($value)
    );

    if (!is_string($value)) {
        throw new WalletWithdrawalException(
            'Bank account holder name is invalid.'
        );
    }

    $length = function_exists('mb_strlen')
        ? mb_strlen($value, 'UTF-8')
        : strlen($value);

    if (
        $length < 2 ||
        $length > 120 ||
        preg_match(
            "/\A[\p{L}\p{M}][\p{L}\p{M}\p{Zs}'\-.]*\z/u",
            $value
        ) !== 1
    ) {
        throw new WalletWithdrawalException(
            'Bank account holder name contains invalid characters.'
        );
    }

    return $value;
}

function walletWithdrawalCanonicalName(
    string $value
): string {
    $value = trim($value);

    if (function_exists('mb_strtolower')) {
        $value = mb_strtolower(
            $value,
            'UTF-8'
        );
    } else {
        $value = strtolower($value);
    }

    $value = preg_replace(
        "/[^\p{L}\p{M}0-9]+/u",
        '',
        $value
    );

    return is_string($value)
        ? $value
        : '';
}

function assertWalletWithdrawalHolderMatchesCustomer(
    PDO $pdo,
    int $userId,
    string $holder
): void {
    $statement = $pdo->prepare("
        SELECT
            user_first_name,
            user_last_name
        FROM users
        WHERE user_id = ?
        AND user_role = 'customer'
        AND user_is_active = 1
        AND user_deleted_at IS NULL
        LIMIT 1
    ");
    $statement->execute([$userId]);

    $user = $statement->fetch(
        PDO::FETCH_ASSOC
    );

    if (!$user) {
        throw new WalletWithdrawalException(
            'Customer account could not be verified.'
        );
    }

    $first = trim(
        (string) $user['user_first_name']
    );
    $last = trim(
        (string) $user['user_last_name']
    );

    $holderCanonical =
        walletWithdrawalCanonicalName(
            $holder
        );

    $allowed = [
        walletWithdrawalCanonicalName(
            trim($first . ' ' . $last)
        ),
        walletWithdrawalCanonicalName(
            trim($last . ' ' . $first)
        ),
    ];

    $allowed = array_values(
        array_unique(
            array_filter(
                $allowed,
                static fn(string $name): bool =>
                    $name !== ''
            )
        )
    );

    if (
        $holderCanonical === '' ||
        !in_array(
            $holderCanonical,
            $allowed,
            true
        )
    ) {
        throw new WalletWithdrawalException(
            'Bank account holder must match the customer name on the MangaVault account.'
        );
    }
}

function normalizeWalletWithdrawalAccountNumber(
    mixed $value
): string {
    if (!is_string($value)) {
        throw new WalletWithdrawalException(
            'Bank account number is invalid.'
        );
    }

    $normalized = preg_replace(
        '/[\s-]+/',
        '',
        trim($value)
    );

    if (!is_string($normalized)) {
        throw new WalletWithdrawalException(
            'Bank account number is invalid.'
        );
    }

    if (
        preg_match('/\A\d{8,20}\z/', $normalized) !== 1
    ) {
        throw new WalletWithdrawalException(
            'Bank account number must contain 8 to 20 digits.'
        );
    }

    if (
        preg_match(
            '/\A(\d)\1+\z/',
            $normalized
        ) === 1
    ) {
        throw new WalletWithdrawalException(
            'Bank account number is not valid.'
        );
    }

    return $normalized;
}

function normalizeWalletWithdrawalAmount(
    mixed $value
): int {
    if (!is_string($value)) {
        throw new WalletWithdrawalException(
            'Please enter a valid withdrawal amount.'
        );
    }

    try {
        $amountSen = moneyDecimalToSen(
            trim($value)
        );
    } catch (MoneyValueException $e) {
        throw new WalletWithdrawalException(
            'Withdrawal amount must use a valid amount with up to two decimal places.',
            0,
            $e
        );
    }

    if ($amountSen < 100) {
        throw new WalletWithdrawalException(
            'Minimum bank withdrawal is RM 1.00.'
        );
    }

    return $amountSen;
}

function normalizeWalletWithdrawalAdminNote(
    mixed $value,
    bool $required = false
): string {
    if (!is_string($value)) {
        throw new WalletWithdrawalException(
            'Administrator note is invalid.'
        );
    }

    $value = trim($value);
    $length = function_exists('mb_strlen')
        ? mb_strlen($value, 'UTF-8')
        : strlen($value);

    if ($length > 1000) {
        throw new WalletWithdrawalException(
            'Administrator note cannot exceed 1000 characters.'
        );
    }

    if ($required && $value === '') {
        throw new WalletWithdrawalException(
            'A rejection reason is required.'
        );
    }

    return $value;
}

function normalizeWalletWithdrawalTransferReference(
    mixed $value
): string {
    if (!is_string($value)) {
        throw new WalletWithdrawalException(
            'Bank transfer reference is invalid.'
        );
    }

    $value = strtoupper(trim($value));

    if (
        strlen($value) < 6 ||
        strlen($value) > 100 ||
        preg_match(
            '/\A[A-Z0-9][A-Z0-9._\/-]{5,99}\z/',
            $value
        ) !== 1
    ) {
        throw new WalletWithdrawalException(
            'Transfer reference must contain 6 to 100 letters, numbers, dots, slashes, underscores, or hyphens.'
        );
    }

    return $value;
}

function walletWithdrawalEncryptionKey(): string
{
    if (!defined('WALLET_BANK_ENCRYPTION_KEY')) {
        throw new WalletWithdrawalException(
            'Wallet bank encryption is not configured.'
        );
    }

    $encoded = trim(
        (string) WALLET_BANK_ENCRYPTION_KEY
    );

    $key = base64_decode(
        $encoded,
        true
    );

    if (
        !is_string($key) ||
        strlen($key) !== 32
    ) {
        throw new WalletWithdrawalException(
            'Wallet bank encryption configuration is invalid.'
        );
    }

    return $key;
}

function encryptWalletWithdrawalAccountNumber(
    string $accountNumber
): string {
    $accountNumber =
        normalizeWalletWithdrawalAccountNumber(
            $accountNumber
        );

    $key = walletWithdrawalEncryptionKey();
    $iv = random_bytes(12);
    $tag = '';

    $ciphertext = openssl_encrypt(
        $accountNumber,
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $iv,
        $tag,
        'MangaVault-Wallet-Bank-v1',
        16
    );

    if (
        !is_string($ciphertext) ||
        strlen($tag) !== 16
    ) {
        throw new WalletWithdrawalException(
            'Bank account number could not be encrypted.'
        );
    }

    return 'v1:' . base64_encode(
        $iv . $tag . $ciphertext
    );
}

function decryptWalletWithdrawalAccountNumber(
    string $payload
): string {
    $payload = trim($payload);

    if (!str_starts_with($payload, 'v1:')) {
        throw new WalletWithdrawalException(
            'Encrypted bank account data is invalid.'
        );
    }

    $decoded = base64_decode(
        substr($payload, 3),
        true
    );

    if (
        !is_string($decoded) ||
        strlen($decoded) < 29
    ) {
        throw new WalletWithdrawalException(
            'Encrypted bank account data is invalid.'
        );
    }

    $iv = substr($decoded, 0, 12);
    $tag = substr($decoded, 12, 16);
    $ciphertext = substr($decoded, 28);

    $plaintext = openssl_decrypt(
        $ciphertext,
        'aes-256-gcm',
        walletWithdrawalEncryptionKey(),
        OPENSSL_RAW_DATA,
        $iv,
        $tag,
        'MangaVault-Wallet-Bank-v1'
    );

    if (!is_string($plaintext)) {
        throw new WalletWithdrawalException(
            'Bank account number could not be decrypted.'
        );
    }

    return normalizeWalletWithdrawalAccountNumber(
        $plaintext
    );
}

function walletWithdrawalAccountFingerprint(
    string $bankCode,
    string $accountNumber
): string {
    $bank = normalizeWalletWithdrawalBankCode(
        $bankCode
    );
    $accountNumber =
        normalizeWalletWithdrawalAccountNumber(
            $accountNumber
        );

    return hash_hmac(
        'sha256',
        'bank:' .
            $bank['code'] .
            ':account:' .
            $accountNumber,
        walletWithdrawalEncryptionKey()
    );
}

function verifyWalletActorPassword(
    PDO $pdo,
    int $userId,
    string $role,
    mixed $password
): void {
    if (
        $userId < 1 ||
        !in_array(
            $role,
            ['customer', 'admin'],
            true
        ) ||
        !is_string($password) ||
        $password === '' ||
        strlen($password) > 72
    ) {
        throw new WalletWithdrawalException(
            'Current password verification failed.'
        );
    }

    $statement = $pdo->prepare("
        SELECT
            user_password_hash,
            user_is_active,
            user_deleted_at
        FROM users
        WHERE user_id = ?
        AND user_role = ?
        LIMIT 1
    ");
    $statement->execute([
        $userId,
        $role,
    ]);

    $user = $statement->fetch(
        PDO::FETCH_ASSOC
    );

    if (
        !$user ||
        (int) $user['user_is_active'] !== 1 ||
        !empty($user['user_deleted_at']) ||
        !password_verify(
            $password,
            (string) $user[
                'user_password_hash'
            ]
        )
    ) {
        throw new WalletWithdrawalException(
            'Current password is incorrect.'
        );
    }
}

function loadWalletEligibleRefundCredits(
    PDO $pdo,
    int $userId,
    bool $forUpdate = false
): array {
    if ($userId < 1) {
        throw new WalletWithdrawalException(
            'Invalid wallet customer.'
        );
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
            wallet_refund_credit_withdrawal_expires_at
        FROM wallet_refund_credits
        WHERE wallet_refund_credit_user_id = ?
        AND wallet_refund_credit_withdrawal_expires_at > NOW()
        ORDER BY
            wallet_refund_credit_withdrawal_expires_at ASC,
            wallet_refund_credit_id ASC
    ";

    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }

    $statement = $pdo->prepare($sql);
    $statement->execute([$userId]);

    $credits = $statement->fetchAll(
        PDO::FETCH_ASSOC
    );

    if ($credits === []) {
        return [];
    }

    $allocationStatement = $pdo->prepare("
        SELECT
            wa.wallet_withdrawal_allocation_refund_credit_id
                AS refund_credit_id,
            COALESCE(
                SUM(
                    wa.wallet_withdrawal_allocation_amount
                ),
                0
            ) AS allocated_amount
        FROM wallet_withdrawal_allocations wa
        INNER JOIN wallet_withdrawal_requests wr
            ON wr.wallet_withdrawal_id =
                wa.wallet_withdrawal_allocation_request_id
        WHERE wr.wallet_withdrawal_user_id = ?
        AND wr.wallet_withdrawal_status IN (
            'pending',
            'approved',
            'completed'
        )
        GROUP BY
            wa.wallet_withdrawal_allocation_refund_credit_id
    ");
    $allocationStatement->execute([
        $userId,
    ]);

    $allocatedByCredit = [];

    foreach (
        $allocationStatement->fetchAll(
            PDO::FETCH_ASSOC
        ) as $allocation
    ) {
        $allocatedByCredit[
            (int) $allocation['refund_credit_id']
        ] = moneyDecimalToSen(
            (string) $allocation[
                'allocated_amount'
            ]
        );
    }

    $eligible = [];

    foreach ($credits as $credit) {
        $creditId = (int) $credit[
            'wallet_refund_credit_id'
        ];
        $amountSen = moneyDecimalToSen(
            (string) $credit[
                'wallet_refund_credit_amount'
            ]
        );
        $allocatedSen =
            $allocatedByCredit[$creditId] ?? 0;
        $remainingSen = max(
            0,
            $amountSen - $allocatedSen
        );

        if ($remainingSen < 1) {
            continue;
        }

        $credit['amount_sen'] = $amountSen;
        $credit['allocated_sen'] =
            $allocatedSen;
        $credit['remaining_sen'] =
            $remainingSen;

        $eligible[] = $credit;
    }

    return $eligible;
}

function getWalletWithdrawalSummary(
    PDO $pdo,
    int $userId
): array {
    $credits = loadWalletEligibleRefundCredits(
        $pdo,
        $userId,
        false
    );

    $eligibleSen = 0;
    $earliestExpiry = null;

    foreach ($credits as $credit) {
        $eligibleSen +=
            (int) $credit['remaining_sen'];

        $expiry = (string) $credit[
            'wallet_refund_credit_withdrawal_expires_at'
        ];

        if (
            $earliestExpiry === null ||
            strtotime($expiry) <
                strtotime($earliestExpiry)
        ) {
            $earliestExpiry = $expiry;
        }
    }

    $activeStatement = $pdo->prepare("
        SELECT
            wallet_withdrawal_id,
            wallet_withdrawal_amount,
            wallet_withdrawal_status,
            wallet_withdrawal_reviewed_at,
            wallet_withdrawal_created_at
        FROM wallet_withdrawal_requests
        WHERE wallet_withdrawal_user_id = ?
        AND wallet_withdrawal_status IN (
            'pending',
            'approved'
        )
        ORDER BY wallet_withdrawal_id DESC
        LIMIT 1
    ");
    $activeStatement->execute([
        $userId,
    ]);

    $active = $activeStatement->fetch(
        PDO::FETCH_ASSOC
    );

    return [
        'eligible_sen' => $eligibleSen,
        'earliest_expiry' => $earliestExpiry,
        'active_request' => $active ?: null,
    ];
}

function getCustomerWalletWithdrawals(
    PDO $pdo,
    int $userId,
    int $limit = 20
): array {
    $limit = max(1, min(100, $limit));

    $statement = $pdo->prepare("
        SELECT
            wallet_withdrawal_id,
            wallet_withdrawal_amount,
            wallet_withdrawal_status,
            wallet_withdrawal_bank_name,
            wallet_withdrawal_account_holder,
            wallet_withdrawal_account_number_last4,
            wallet_withdrawal_transfer_reference,
            wallet_withdrawal_admin_note,
            wallet_withdrawal_reviewed_at,
            wallet_withdrawal_reviewed_by,
            wallet_withdrawal_failed_at,
            wallet_withdrawal_failure_reason,
            wallet_withdrawal_completed_at,
            wallet_withdrawal_receipt_file,
            wallet_withdrawal_receipt_uploaded_by,
            wallet_withdrawal_created_at
        FROM wallet_withdrawal_requests
        WHERE wallet_withdrawal_user_id = ?
        ORDER BY wallet_withdrawal_id DESC
        LIMIT $limit
    ");
    $statement->execute([
        $userId,
    ]);

    return $statement->fetchAll(
        PDO::FETCH_ASSOC
    );
}

function createWalletWithdrawalRequest(
    PDO $pdo,
    int $userId,
    int $amountSen,
    string $bankCode,
    string $accountHolder,
    string $accountNumber,
    mixed $currentPassword
): int {
    if (
        $userId < 1 ||
        $amountSen < 100
    ) {
        throw new WalletWithdrawalException(
            'Invalid bank withdrawal request.'
        );
    }

    $bank = normalizeWalletWithdrawalBankCode(
        $bankCode
    );
    $accountHolder =
        normalizeWalletWithdrawalAccountHolder(
            $accountHolder
        );
    $accountNumber =
        normalizeWalletWithdrawalAccountNumber(
            $accountNumber
        );

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

    $encryptedAccount =
        encryptWalletWithdrawalAccountNumber(
            $accountNumber
        );
    $last4 = substr($accountNumber, -4);
    $fingerprint =
        walletWithdrawalAccountFingerprint(
            $bank['code'],
            $accountNumber
        );

    try {
        $pdo->beginTransaction();

        $wallet = lockWalletAccount(
            $pdo,
            $userId
        );

        $activeStatement = $pdo->prepare("
            SELECT wallet_withdrawal_id
            FROM wallet_withdrawal_requests
            WHERE wallet_withdrawal_user_id = ?
            AND wallet_withdrawal_status IN (
                'pending',
                'approved'
            )
            LIMIT 1
            FOR UPDATE
        ");
        $activeStatement->execute([
            $userId,
        ]);

        if (
            $activeStatement->fetchColumn() !== false
        ) {
            throw new WalletWithdrawalException(
                'You already have an active bank withdrawal request. Please wait for it to be processed before submitting another request.'
            );
        }

        $credits = loadWalletEligibleRefundCredits(
            $pdo,
            $userId,
            true
        );

        $eligibleSen = 0;

        foreach ($credits as $credit) {
            $eligibleSen +=
                (int) $credit['remaining_sen'];
        }

        if (
            $amountSen > $eligibleSen ||
            $amountSen >
                (int) $wallet['wallet_available_sen']
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
                wallet_withdrawal_customer_reauthenticated_at
            )
            VALUES (
                ?, ?, ?, 'pending', ?, ?, ?, ?, ?, ?, NOW()
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

        $withdrawalId =
            (int) $pdo->lastInsertId();

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
            )
            VALUES (?, ?, ?)
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
                (int) $credit[
                    'wallet_refund_credit_id'
                ],
                moneySenToDecimal(
                    $allocationSen
                ),
            ]);

            $remainingToAllocate -=
                $allocationSen;
        }

        if ($remainingToAllocate !== 0) {
            throw new WalletWithdrawalException(
                'Refund allocation could not be completed.'
            );
        }

        $newReservedSen =
            (int) $wallet['wallet_reserved_sen'] +
            $amountSen;

        if (
            $newReservedSen >
            (int) $wallet['wallet_balance_sen']
        ) {
            throw new WalletWithdrawalException(
                'Wallet reserve would exceed the wallet balance.'
            );
        }

        $updateWallet = $pdo->prepare("
            UPDATE wallet_accounts
            SET wallet_reserved_amount = ?,
                wallet_updated_at = NOW()
            WHERE wallet_id = ?
            AND wallet_user_id = ?
        ");
        $updateWallet->execute([
            moneySenToDecimal(
                $newReservedSen
            ),
            (int) $wallet['wallet_id'],
            $userId,
        ]);

        if ($updateWallet->rowCount() !== 1) {
            throw new WalletWithdrawalException(
                'Wallet reserve could not be updated.'
            );
        }

        $reserveTransactionId =
            insertWalletLedgerEvent(
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
                'withdrawal:reserve:' .
                    $withdrawalId,
                'Reserved for bank withdrawal #' .
                    str_pad(
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

function walletWithdrawalLoadForAdmin(
    PDO $pdo,
    int $withdrawalId,
    bool $forUpdate = false
): array {
    if ($withdrawalId < 1) {
        throw new WalletWithdrawalException(
            'Invalid bank withdrawal request.'
        );
    }

    if ($forUpdate) {
        walletRequireTransaction($pdo);
    }

    $sql = "
        SELECT
            wr.*,
            u.user_first_name,
            u.user_last_name,
            u.user_gmail
        FROM wallet_withdrawal_requests wr
        INNER JOIN users u
            ON u.user_id =
                wr.wallet_withdrawal_user_id
        WHERE wr.wallet_withdrawal_id = ?
        LIMIT 1
    ";

    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }

    $statement = $pdo->prepare($sql);
    $statement->execute([
        $withdrawalId,
    ]);

    $request = $statement->fetch(
        PDO::FETCH_ASSOC
    );

    if (!$request) {
        throw new WalletWithdrawalException(
            'Bank withdrawal request was not found.'
        );
    }

    return $request;
}

function walletWithdrawalInsertAdminLog(
    PDO $pdo,
    int $adminId,
    string $action,
    int $withdrawalId,
    string $details
): void {
    walletRequireTransaction($pdo);

    if (
        $adminId < 1 ||
        $withdrawalId < 1 ||
        preg_match(
            '/\A[a-z0-9_]{3,80}\z/',
            $action
        ) !== 1 ||
        trim($details) === '' ||
        strlen($details) > 1000
    ) {
        throw new WalletWithdrawalException(
            'Invalid bank withdrawal audit event.'
        );
    }

    $statement = $pdo->prepare("
        INSERT INTO admin_logs (
            log_admin_id,
            log_action,
            log_target_type,
            log_target_id,
            log_details
        )
        VALUES (?, ?, 'wallet_withdrawal', ?, ?)
    ");
    $statement->execute([
        $adminId,
        $action,
        $withdrawalId,
        $details,
    ]);
}

function approveWalletWithdrawalRequest(
    PDO $pdo,
    int $withdrawalId,
    int $adminId,
    string $adminNote
): array {
    $adminNote = normalizeWalletWithdrawalAdminNote(
        $adminNote,
        false
    );

    try {
        $pdo->beginTransaction();

        $request = walletWithdrawalLoadForAdmin(
            $pdo,
            $withdrawalId,
            true
        );

        if (
            $request['wallet_withdrawal_status'] !==
            'pending'
        ) {
            throw new WalletWithdrawalException(
                'Only a pending withdrawal can be approved.'
            );
        }

        $wallet = lockWalletAccount(
            $pdo,
            (int) $request[
                'wallet_withdrawal_user_id'
            ]
        );
        $amountSen = moneyDecimalToSen(
            (string) $request[
                'wallet_withdrawal_amount'
            ]
        );

        if (
            empty(
                $request[
                    'wallet_withdrawal_reserved_tx_id'
                ]
            ) ||
            (int) $wallet['wallet_reserved_sen'] <
                $amountSen
        ) {
            throw new WalletWithdrawalException(
                'Withdrawal reserve is inconsistent.'
            );
        }

        $update = $pdo->prepare("
            UPDATE wallet_withdrawal_requests
            SET wallet_withdrawal_status = 'approved',
                wallet_withdrawal_reviewed_by = ?,
                wallet_withdrawal_reviewed_at = NOW(),
                wallet_withdrawal_admin_note = ?
            WHERE wallet_withdrawal_id = ?
            AND wallet_withdrawal_status = 'pending'
        ");
        $update->execute([
            $adminId,
            $adminNote !== ''
                ? $adminNote
                : null,
            $withdrawalId,
        ]);

        if ($update->rowCount() !== 1) {
            throw new WalletWithdrawalException(
                'Withdrawal has already been processed.'
            );
        }

        walletWithdrawalInsertAdminLog(
            $pdo,
            $adminId,
            'approve_wallet_withdrawal',
            $withdrawalId,
            'Approved bank withdrawal request. Funds remain reserved pending bank transfer completion.'
        );

        $pdo->commit();

        $request['wallet_withdrawal_status'] =
            'approved';

        return $request;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }
}

function rejectWalletWithdrawalRequest(
    PDO $pdo,
    int $withdrawalId,
    int $adminId,
    string $adminNote
): array {
    $adminNote = normalizeWalletWithdrawalAdminNote(
        $adminNote,
        true
    );

    try {
        $pdo->beginTransaction();

        $request = walletWithdrawalLoadForAdmin(
            $pdo,
            $withdrawalId,
            true
        );

        if (
            $request['wallet_withdrawal_status'] !==
            'pending'
        ) {
            throw new WalletWithdrawalException(
                'Only a pending withdrawal can be rejected.'
            );
        }

        $userId = (int) $request[
            'wallet_withdrawal_user_id'
        ];
        $wallet = lockWalletAccount(
            $pdo,
            $userId
        );
        $amountSen = moneyDecimalToSen(
            (string) $request[
                'wallet_withdrawal_amount'
            ]
        );

        if (
            empty(
                $request[
                    'wallet_withdrawal_reserved_tx_id'
                ]
            ) ||
            (int) $wallet['wallet_reserved_sen'] <
                $amountSen
        ) {
            throw new WalletWithdrawalException(
                'Withdrawal reserve is inconsistent.'
            );
        }

        $newReservedSen =
            (int) $wallet['wallet_reserved_sen'] -
            $amountSen;

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
            throw new WalletWithdrawalException(
                'Wallet reserve could not be released.'
            );
        }

        $releaseTransactionId =
            insertWalletLedgerEvent(
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
                'withdrawal:release:' .
                    $withdrawalId,
                'Released bank withdrawal reserve #' .
                    str_pad(
                        (string) $withdrawalId,
                        4,
                        '0',
                        STR_PAD_LEFT
                    )
            );

        $update = $pdo->prepare("
            UPDATE wallet_withdrawal_requests
            SET wallet_withdrawal_status = 'rejected',
                wallet_withdrawal_reviewed_by = ?,
                wallet_withdrawal_reviewed_at = NOW(),
                wallet_withdrawal_admin_note = ?,
                wallet_withdrawal_release_tx_id = ?
            WHERE wallet_withdrawal_id = ?
            AND wallet_withdrawal_status = 'pending'
            AND wallet_withdrawal_release_tx_id IS NULL
        ");
        $update->execute([
            $adminId,
            $adminNote,
            $releaseTransactionId,
            $withdrawalId,
        ]);

        if ($update->rowCount() !== 1) {
            throw new WalletWithdrawalException(
                'Withdrawal has already been processed.'
            );
        }

        walletWithdrawalInsertAdminLog(
            $pdo,
            $adminId,
            'reject_wallet_withdrawal',
            $withdrawalId,
            'Rejected bank withdrawal request and released reserved funds.'
        );

        $pdo->commit();

        $request['wallet_withdrawal_status'] =
            'rejected';
        $request['wallet_withdrawal_admin_note'] =
            $adminNote;

        return $request;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }
}

function failApprovedWalletWithdrawalRequest(
    PDO $pdo,
    int $withdrawalId,
    int $adminId,
    string $failureReason
): array {
    $failureReason =
        normalizeWalletWithdrawalAdminNote(
            $failureReason,
            true
        );

    try {
        $pdo->beginTransaction();

        $request =
            walletWithdrawalLoadForAdmin(
                $pdo,
                $withdrawalId,
                true
            );

        if (
            $request[
                'wallet_withdrawal_status'
            ] !== 'approved'
        ) {
            throw new WalletWithdrawalException(
                'Only an approved withdrawal can be marked as a failed bank transfer.'
            );
        }

        if (
            !empty(
                $request[
                    'wallet_withdrawal_debit_tx_id'
                ]
            ) ||
            !empty(
                $request[
                    'wallet_withdrawal_release_tx_id'
                ]
            ) ||
            !empty(
                $request[
                    'wallet_withdrawal_receipt_file'
                ]
            ) ||
            !empty(
                $request[
                    'wallet_withdrawal_transfer_reference'
                ]
            )
        ) {
            throw new WalletWithdrawalException(
                'This withdrawal already contains final transfer or release data.'
            );
        }

        $userId =
            (int) $request[
                'wallet_withdrawal_user_id'
            ];

        $wallet =
            lockWalletAccount(
                $pdo,
                $userId
            );

        $amountSen =
            moneyDecimalToSen(
                (string) $request[
                    'wallet_withdrawal_amount'
                ]
            );

        if (
            empty(
                $request[
                    'wallet_withdrawal_reserved_tx_id'
                ]
            ) ||
            (int) $wallet[
                'wallet_reserved_sen'
            ] < $amountSen
        ) {
            throw new WalletWithdrawalException(
                'Withdrawal reserve is inconsistent.'
            );
        }

        $newReservedSen =
            (int) $wallet[
                'wallet_reserved_sen'
            ] -
            $amountSen;

        if ($newReservedSen < 0) {
            throw new WalletWithdrawalException(
                'Wallet reserve would become invalid.'
            );
        }

        $updateWallet =
            $pdo->prepare("
                UPDATE wallet_accounts
                SET wallet_reserved_amount = ?,
                    wallet_updated_at = NOW()
                WHERE wallet_id = ?
                AND wallet_user_id = ?
            ");

        $updateWallet->execute([
            moneySenToDecimal(
                $newReservedSen
            ),
            (int) $wallet[
                'wallet_id'
            ],
            $userId,
        ]);

        if (
            $updateWallet->rowCount() !== 1
        ) {
            throw new WalletWithdrawalException(
                'Wallet reserve could not be released.'
            );
        }

        $releaseTransactionId =
            insertWalletLedgerEvent(
                $pdo,
                (int) $wallet[
                    'wallet_id'
                ],
                $userId,
                'release',
                'withdrawal_release',
                $amountSen,
                (int) $wallet[
                    'wallet_balance_sen'
                ],
                $newReservedSen,
                'wallet_withdrawal',
                $withdrawalId,
                'withdrawal:release:' .
                    $withdrawalId,
                'Released failed bank withdrawal reserve #' .
                    str_pad(
                        (string) $withdrawalId,
                        4,
                        '0',
                        STR_PAD_LEFT
                    )
            );

        $updateRequest =
            $pdo->prepare("
                UPDATE
                    wallet_withdrawal_requests
                SET
                    wallet_withdrawal_status =
                        'failed',
                    wallet_withdrawal_failed_by = ?,
                    wallet_withdrawal_failed_at =
                        NOW(),
                    wallet_withdrawal_failure_reason = ?,
                    wallet_withdrawal_release_tx_id = ?
                WHERE
                    wallet_withdrawal_id = ?
                AND
                    wallet_withdrawal_status =
                        'approved'
                AND
                    wallet_withdrawal_release_tx_id
                        IS NULL
                AND
                    wallet_withdrawal_debit_tx_id
                        IS NULL
                AND
                    wallet_withdrawal_receipt_file
                        IS NULL
                AND
                    wallet_withdrawal_transfer_reference
                        IS NULL
            ");

        $updateRequest->execute([
            $adminId,
            $failureReason,
            $releaseTransactionId,
            $withdrawalId,
        ]);

        if (
            $updateRequest->rowCount() !== 1
        ) {
            throw new WalletWithdrawalException(
                'Withdrawal transfer failure could not be recorded.'
            );
        }

        walletWithdrawalInsertAdminLog(
            $pdo,
            $adminId,
            'fail_wallet_withdrawal',
            $withdrawalId,
            'Marked approved bank withdrawal as transfer failed and released the reserved wallet funds.'
        );

        $pdo->commit();

        $request[
            'wallet_withdrawal_status'
        ] = 'failed';

        $request[
            'wallet_withdrawal_failure_reason'
        ] = $failureReason;

        return $request;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }
}


function walletWithdrawalReceiptRoot(): string
{
    return dirname(__DIR__) .
        DIRECTORY_SEPARATOR .
        'private_storage' .
        DIRECTORY_SEPARATOR .
        'wallet_withdrawal_receipts';
}

function ensureWalletWithdrawalReceiptStorage(): string
{
    $privateRoot = dirname(__DIR__) .
        DIRECTORY_SEPARATOR .
        'private_storage';
    $denyFile = $privateRoot .
        DIRECTORY_SEPARATOR .
        '.htaccess';

    if (
        !is_file($denyFile) ||
        !is_readable($denyFile)
    ) {
        throw new WalletWithdrawalException(
            'Private receipt storage protection is missing.'
        );
    }

    $denyContent = file_get_contents(
        $denyFile
    );

    if (
        !is_string($denyContent) ||
        !str_contains(
            strtolower($denyContent),
            'require all denied'
        )
    ) {
        throw new WalletWithdrawalException(
            'Private receipt storage protection is invalid.'
        );
    }

    $directory = walletWithdrawalReceiptRoot();

    if (!is_dir($directory)) {
        if (
            !mkdir(
                $directory,
                0700,
                true
            ) &&
            !is_dir($directory)
        ) {
            throw new WalletWithdrawalException(
                'Private receipt storage could not be created.'
            );
        }
    }

    if (!is_writable($directory)) {
        throw new WalletWithdrawalException(
            'Private receipt storage is not writable.'
        );
    }

    return $directory;
}

function storeWalletWithdrawalReceipt(
    array $file
): array {
    if (
        !isset($file['error']) ||
        is_array($file['error']) ||
        $file['error'] !== UPLOAD_ERR_OK
    ) {
        throw new WalletWithdrawalException(
            'Bank transfer receipt is required.'
        );
    }

    $reportedSize = filter_var(
        $file['size'] ?? null,
        FILTER_VALIDATE_INT,
        [
            'options' => [
                'min_range' => 1,
                'max_range' => 5 * 1024 * 1024,
            ],
        ]
    );

    if ($reportedSize === false) {
        throw new WalletWithdrawalException(
            'Bank transfer receipt must not exceed 5MB.'
        );
    }

    $name = trim(
        (string) ($file['name'] ?? '')
    );
    $tmpPath = (string) (
        $file['tmp_name'] ?? ''
    );

    if (
        $name === '' ||
        !is_uploaded_file($tmpPath)
    ) {
        throw new WalletWithdrawalException(
            'Bank transfer receipt upload is invalid.'
        );
    }

    $extension = strtolower(
        pathinfo(
            $name,
            PATHINFO_EXTENSION
        )
    );

    $mimeMap = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'pdf' => 'application/pdf',
    ];

    if (!isset($mimeMap[$extension])) {
        throw new WalletWithdrawalException(
            'Receipt must be JPG, JPEG, PNG, WEBP, or PDF.'
        );
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmpPath);

    if (
        !is_string($mime) ||
        strtolower($mime) !==
            $mimeMap[$extension]
    ) {
        throw new WalletWithdrawalException(
            'Receipt file content does not match its file type.'
        );
    }

    $actualSize = filesize($tmpPath);

    if (
        $actualSize === false ||
        $actualSize !== (int) $reportedSize
    ) {
        throw new WalletWithdrawalException(
            'Receipt upload size is inconsistent.'
        );
    }

    if (str_starts_with($mime, 'image/')) {
        $image = @getimagesize($tmpPath);

        if (
            $image === false ||
            !isset($image[0], $image[1]) ||
            (int) $image[0] < 50 ||
            (int) $image[1] < 50 ||
            (int) $image[0] > 12000 ||
            (int) $image[1] > 12000
        ) {
            throw new WalletWithdrawalException(
                'Receipt image dimensions are invalid.'
            );
        }
    } else {
        $handle = fopen($tmpPath, 'rb');

        if ($handle === false) {
            throw new WalletWithdrawalException(
                'Receipt PDF could not be inspected.'
            );
        }

        $header = fread($handle, 5);
        fclose($handle);

        if ($header !== '%PDF-') {
            throw new WalletWithdrawalException(
                'Receipt PDF content is invalid.'
            );
        }

        $tailLength = min(
            4096,
            (int) $actualSize
        );
        $tail = file_get_contents(
            $tmpPath,
            false,
            null,
            max(
                0,
                (int) $actualSize -
                    $tailLength
            ),
            $tailLength
        );

        if (
            !is_string($tail) ||
            !str_contains($tail, '%%EOF')
        ) {
            throw new WalletWithdrawalException(
                'Receipt PDF appears incomplete.'
            );
        }
    }

    $directory =
        ensureWalletWithdrawalReceiptStorage();
    $fileName =
        'withdrawal_' .
        bin2hex(random_bytes(24)) .
        '.' .
        $extension;
    $targetPath =
        $directory .
        DIRECTORY_SEPARATOR .
        $fileName;

    if (!move_uploaded_file(
        $tmpPath,
        $targetPath
    )) {
        throw new WalletWithdrawalException(
            'Bank transfer receipt could not be stored.'
        );
    }

    @chmod($targetPath, 0600);

    $sha256 = hash_file(
        'sha256',
        $targetPath
    );

    if (
        !is_string($sha256) ||
        preg_match(
            '/\A[a-f0-9]{64}\z/',
            $sha256
        ) !== 1
    ) {
        @unlink($targetPath);

        throw new WalletWithdrawalException(
            'Receipt integrity hash could not be created.'
        );
    }

    return [
        'file_name' => $fileName,
        'mime' => $mime,
        'size' => (int) $actualSize,
        'sha256' => $sha256,
        'path' => $targetPath,
    ];
}

function deleteWalletWithdrawalReceipt(
    ?string $fileName
): void {
    $fileName = trim(
        (string) $fileName
    );

    if (
        $fileName === '' ||
        $fileName !== basename($fileName) ||
        preg_match(
            '/\Awithdrawal_[a-f0-9]{48}\.(?:jpe?g|png|webp|pdf)\z/',
            $fileName
        ) !== 1
    ) {
        return;
    }

    $path = walletWithdrawalReceiptRoot() .
        DIRECTORY_SEPARATOR .
        $fileName;

    if (is_file($path)) {
        @unlink($path);
    }
}

function completeWalletWithdrawalRequest(
    PDO $pdo,
    int $withdrawalId,
    int $adminId,
    string $transferReference,
    array $receipt
): array {
    $transferReference =
        normalizeWalletWithdrawalTransferReference(
            $transferReference
        );

    foreach (
        [
            'file_name',
            'mime',
            'size',
            'sha256',
        ] as $requiredKey
    ) {
        if (!array_key_exists(
            $requiredKey,
            $receipt
        )) {
            throw new WalletWithdrawalException(
                'Bank transfer receipt metadata is incomplete.'
            );
        }
    }

    $fileName = trim(
        (string) $receipt['file_name']
    );
    $mime = trim(
        (string) $receipt['mime']
    );
    $size = (int) $receipt['size'];
    $sha256 = strtolower(trim(
        (string) $receipt['sha256']
    ));

    if (
        $fileName !== basename($fileName) ||
        preg_match(
            '/\Awithdrawal_[a-f0-9]{48}\.(?:jpe?g|png|webp|pdf)\z/',
            $fileName
        ) !== 1 ||
        !in_array(
            $mime,
            [
                'image/jpeg',
                'image/png',
                'image/webp',
                'application/pdf',
            ],
            true
        ) ||
        $size < 1 ||
        $size > 5 * 1024 * 1024 ||
        preg_match(
            '/\A[a-f0-9]{64}\z/',
            $sha256
        ) !== 1
    ) {
        throw new WalletWithdrawalException(
            'Bank transfer receipt metadata is invalid.'
        );
    }

    $stagedPath = walletWithdrawalReceiptRoot() .
        DIRECTORY_SEPARATOR .
        $fileName;
    $stagedSize = is_file($stagedPath)
        ? filesize($stagedPath)
        : false;
    $stagedHash = is_file($stagedPath)
        ? hash_file('sha256', $stagedPath)
        : false;

    if (
        $stagedSize === false ||
        (int) $stagedSize !== $size ||
        !is_string($stagedHash) ||
        !hash_equals(
            $sha256,
            strtolower($stagedHash)
        )
    ) {
        throw new WalletWithdrawalException(
            'Bank transfer receipt integrity verification failed before completion.'
        );
    }

    try {
        $pdo->beginTransaction();

        $request = walletWithdrawalLoadForAdmin(
            $pdo,
            $withdrawalId,
            true
        );

        if (
            $request['wallet_withdrawal_status'] !==
            'approved'
        ) {
            throw new WalletWithdrawalException(
                'Only an approved withdrawal can be completed.'
            );
        }

        if (
            !empty(
                $request[
                    'wallet_withdrawal_debit_tx_id'
                ]
            ) ||
            !empty(
                $request[
                    'wallet_withdrawal_receipt_file'
                ]
            )
        ) {
            throw new WalletWithdrawalException(
                'This withdrawal has already been completed.'
            );
        }

        $duplicateReference =
            $pdo->prepare("
                SELECT wallet_withdrawal_id
                FROM wallet_withdrawal_requests
                WHERE wallet_withdrawal_transfer_reference = ?
                AND wallet_withdrawal_id <> ?
                LIMIT 1
                FOR UPDATE
            ");
        $duplicateReference->execute([
            $transferReference,
            $withdrawalId,
        ]);

        if (
            $duplicateReference->fetchColumn() !==
            false
        ) {
            throw new WalletWithdrawalException(
                'This bank transfer reference is already used by another withdrawal.'
            );
        }

        $userId = (int) $request[
            'wallet_withdrawal_user_id'
        ];
        $wallet = lockWalletAccount(
            $pdo,
            $userId
        );
        $amountSen = moneyDecimalToSen(
            (string) $request[
                'wallet_withdrawal_amount'
            ]
        );

        if (
            (int) $wallet['wallet_balance_sen'] <
                $amountSen ||
            (int) $wallet['wallet_reserved_sen'] <
                $amountSen
        ) {
            throw new WalletWithdrawalException(
                'Wallet balance or reserved amount is inconsistent.'
            );
        }

        $newBalanceSen =
            (int) $wallet['wallet_balance_sen'] -
            $amountSen;
        $newReservedSen =
            (int) $wallet['wallet_reserved_sen'] -
            $amountSen;

        if ($newReservedSen > $newBalanceSen) {
            throw new WalletWithdrawalException(
                'Wallet state would become inconsistent.'
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
            moneySenToDecimal(
                $newBalanceSen
            ),
            moneySenToDecimal(
                $newReservedSen
            ),
            (int) $wallet['wallet_id'],
            $userId,
        ]);

        if ($updateWallet->rowCount() !== 1) {
            throw new WalletWithdrawalException(
                'Wallet could not complete the bank transfer debit.'
            );
        }

        $debitTransactionId =
            insertWalletLedgerEvent(
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
                'withdrawal:complete:' .
                    $withdrawalId,
                'Completed bank withdrawal #' .
                    str_pad(
                        (string) $withdrawalId,
                        4,
                        '0',
                        STR_PAD_LEFT
                    )
            );

        $update = $pdo->prepare("
            UPDATE wallet_withdrawal_requests
            SET wallet_withdrawal_status = 'completed',
                wallet_withdrawal_transfer_reference = ?,
                wallet_withdrawal_receipt_file = ?,
                wallet_withdrawal_receipt_mime = ?,
                wallet_withdrawal_receipt_size = ?,
                wallet_withdrawal_receipt_sha256 = ?,
                wallet_withdrawal_receipt_uploaded_by = ?,
                wallet_withdrawal_receipt_uploaded_at = NOW(),
                wallet_withdrawal_debit_tx_id = ?,
                wallet_withdrawal_completed_at = NOW()
            WHERE wallet_withdrawal_id = ?
            AND wallet_withdrawal_status = 'approved'
            AND wallet_withdrawal_debit_tx_id IS NULL
            AND wallet_withdrawal_receipt_file IS NULL
        ");
        $update->execute([
            $transferReference,
            $fileName,
            $mime,
            $size,
            $sha256,
            $adminId,
            $debitTransactionId,
            $withdrawalId,
        ]);

        if ($update->rowCount() !== 1) {
            throw new WalletWithdrawalException(
                'Withdrawal completion could not be recorded.'
            );
        }

        walletWithdrawalInsertAdminLog(
            $pdo,
            $adminId,
            'complete_wallet_withdrawal',
            $withdrawalId,
            'Completed bank withdrawal with transfer reference ' .
                $transferReference .
                ' and integrity-checked transfer receipt.'
        );

        $pdo->commit();

        $request['wallet_withdrawal_status'] =
            'completed';
        $request[
            'wallet_withdrawal_transfer_reference'
        ] = $transferReference;

        return $request;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }
}

function loadWalletWithdrawalReceiptRecord(
    PDO $pdo,
    int $withdrawalId,
    ?int $customerId = null
): array {
    if ($withdrawalId < 1) {
        throw new WalletWithdrawalException(
            'Invalid bank withdrawal receipt.'
        );
    }

    $sql = "
        SELECT
            wallet_withdrawal_id,
            wallet_withdrawal_user_id,
            wallet_withdrawal_status,
            wallet_withdrawal_receipt_file,
            wallet_withdrawal_receipt_mime,
            wallet_withdrawal_receipt_size,
            wallet_withdrawal_receipt_sha256
        FROM wallet_withdrawal_requests
        WHERE wallet_withdrawal_id = ?
        AND wallet_withdrawal_status = 'completed'
    ";
    $params = [$withdrawalId];

    if ($customerId !== null) {
        $sql .= ' AND wallet_withdrawal_user_id = ?';
        $params[] = $customerId;
    }

    $sql .= ' LIMIT 1';

    $statement = $pdo->prepare($sql);
    $statement->execute($params);

    $record = $statement->fetch(
        PDO::FETCH_ASSOC
    );

    if (!$record) {
        throw new WalletWithdrawalException(
            'Bank withdrawal receipt was not found.'
        );
    }

    return $record;
}

function resolveVerifiedWalletWithdrawalReceipt(
    array $record
): array {
    $fileName = trim(
        (string) (
            $record[
                'wallet_withdrawal_receipt_file'
            ] ?? ''
        )
    );
    $storedMime = trim(
        (string) (
            $record[
                'wallet_withdrawal_receipt_mime'
            ] ?? ''
        )
    );
    $storedSize = (int) (
        $record[
            'wallet_withdrawal_receipt_size'
        ] ?? 0
    );
    $storedHash = strtolower(trim(
        (string) (
            $record[
                'wallet_withdrawal_receipt_sha256'
            ] ?? ''
        )
    ));

    if (
        $fileName === '' ||
        $fileName !== basename($fileName) ||
        preg_match(
            '/\Awithdrawal_[a-f0-9]{48}\.(?:jpe?g|png|webp|pdf)\z/',
            $fileName
        ) !== 1 ||
        !in_array(
            $storedMime,
            [
                'image/jpeg',
                'image/png',
                'image/webp',
                'application/pdf',
            ],
            true
        ) ||
        $storedSize < 1 ||
        $storedSize > 5 * 1024 * 1024 ||
        preg_match(
            '/\A[a-f0-9]{64}\z/',
            $storedHash
        ) !== 1
    ) {
        throw new WalletWithdrawalException(
            'Bank withdrawal receipt metadata is invalid.'
        );
    }

    $path = walletWithdrawalReceiptRoot() .
        DIRECTORY_SEPARATOR .
        $fileName;

    if (
        !is_file($path) ||
        !is_readable($path)
    ) {
        throw new WalletWithdrawalException(
            'Bank withdrawal receipt file is unavailable.'
        );
    }

    $actualSize = filesize($path);
    $actualHash = hash_file(
        'sha256',
        $path
    );
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $actualMime = $finfo->file($path);

    if (
        $actualSize === false ||
        (int) $actualSize !== $storedSize ||
        !is_string($actualHash) ||
        !hash_equals(
            $storedHash,
            strtolower($actualHash)
        ) ||
        !is_string($actualMime) ||
        strtolower($actualMime) !==
            $storedMime
    ) {
        throw new WalletWithdrawalException(
            'Bank withdrawal receipt integrity verification failed.'
        );
    }

    return [
        'path' => $path,
        'file_name' => $fileName,
        'mime' => $storedMime,
        'size' => $storedSize,
    ];
}
