<?php

require_once __DIR__ . '/money_helper.php';

final class WalletException extends RuntimeException
{
}

function walletRequireTransaction(PDO $pdo): void
{
    if (!$pdo->inTransaction()) {
        throw new WalletException(
            'Wallet operation requires an active database transaction.'
        );
    }
}

function ensureWalletAccount(
    PDO $pdo,
    int $userId
): int {
    if ($userId < 1) {
        throw new WalletException(
            'Invalid wallet customer.'
        );
    }

    $insert = $pdo->prepare("
        INSERT INTO wallet_accounts (
            wallet_user_id
        )
        SELECT ?
        WHERE EXISTS (
            SELECT 1
            FROM users
            WHERE user_id = ?
            AND user_role = 'customer'
        )
        ON DUPLICATE KEY UPDATE
            wallet_user_id = VALUES(wallet_user_id)
    ");

    $insert->execute([
        $userId,
        $userId,
    ]);

    $select = $pdo->prepare("
        SELECT wallet_id
        FROM wallet_accounts
        WHERE wallet_user_id = ?
        LIMIT 1
    ");
    $select->execute([$userId]);

    $walletId = $select->fetchColumn();

    if ($walletId === false) {
        throw new WalletException(
            'Wallet account could not be created.'
        );
    }

    return (int) $walletId;
}

function lockWalletAccount(
    PDO $pdo,
    int $userId
): array {
    walletRequireTransaction($pdo);

    $walletId = ensureWalletAccount(
        $pdo,
        $userId
    );

    $statement = $pdo->prepare("
        SELECT
            wallet_id,
            wallet_user_id,
            wallet_balance,
            wallet_reserved_amount
        FROM wallet_accounts
        WHERE wallet_id = ?
        AND wallet_user_id = ?
        FOR UPDATE
    ");
    $statement->execute([
        $walletId,
        $userId,
    ]);

    $wallet = $statement->fetch(
        PDO::FETCH_ASSOC
    );

    if (!$wallet) {
        throw new WalletException(
            'Wallet account could not be locked.'
        );
    }

    $balanceSen = moneyDecimalToSen(
        (string) $wallet['wallet_balance']
    );
    $reservedSen = moneyDecimalToSen(
        (string) $wallet['wallet_reserved_amount']
    );

    if ($reservedSen > $balanceSen) {
        throw new WalletException(
            'Wallet balance is inconsistent.'
        );
    }

    $wallet['wallet_balance_sen'] =
        $balanceSen;
    $wallet['wallet_reserved_sen'] =
        $reservedSen;
    $wallet['wallet_available_sen'] =
        $balanceSen - $reservedSen;

    return $wallet;
}

function normalizeWalletIdempotencyKey(
    string $key
): string {
    $key = trim($key);

    if (
        strlen($key) < 3 ||
        strlen($key) > 191 ||
        !preg_match(
            '/\A[A-Za-z0-9:_-]+\z/',
            $key
        )
    ) {
        throw new WalletException(
            'Invalid wallet idempotency key.'
        );
    }

    return $key;
}

function normalizeWalletDescription(
    string $description
): string {
    $description = trim($description);
    $length = function_exists('mb_strlen')
        ? mb_strlen($description, 'UTF-8')
        : strlen($description);

    if ($description === '' || $length > 255) {
        throw new WalletException(
            'Invalid wallet transaction description.'
        );
    }

    return $description;
}

function walletExistingTransaction(
    PDO $pdo,
    string $idempotencyKey,
    bool $forUpdate = false
): ?array {
    $idempotencyKey =
        normalizeWalletIdempotencyKey(
            $idempotencyKey
        );

    $sql = "
        SELECT *
        FROM wallet_transactions
        WHERE wallet_tx_idempotency_key = ?
        LIMIT 1
    ";

    if ($forUpdate) {
        walletRequireTransaction($pdo);
        $sql .= ' FOR UPDATE';
    }

    $statement = $pdo->prepare($sql);
    $statement->execute([
        $idempotencyKey,
    ]);

    $row = $statement->fetch(
        PDO::FETCH_ASSOC
    );

    return $row ?: null;
}

function insertWalletLedgerEvent(
    PDO $pdo,
    int $walletId,
    int $userId,
    string $effect,
    string $type,
    int $amountSen,
    int $balanceAfterSen,
    int $reservedAfterSen,
    ?string $referenceType,
    ?int $referenceId,
    string $idempotencyKey,
    string $description
): int {
    walletRequireTransaction($pdo);

    $allowedEffects = [
        'credit',
        'debit',
        'reserve',
        'release',
    ];

    $allowedTypes = [
        'topup',
        'return_refund',
        'cancellation_refund',
        'payment_timeout_refund',
        'order_payment',
        'order_payment_refund',
        'withdrawal_reserve',
        'withdrawal_release',
        'withdrawal_complete',
    ];

    if (
        $walletId < 1 ||
        $userId < 1 ||
        !in_array($effect, $allowedEffects, true) ||
        !in_array($type, $allowedTypes, true) ||
        $amountSen < 1 ||
        $balanceAfterSen < 0 ||
        $reservedAfterSen < 0 ||
        $reservedAfterSen > $balanceAfterSen ||
        (
            $referenceId !== null &&
            $referenceId < 1
        )
    ) {
        throw new WalletException(
            'Invalid wallet ledger event.'
        );
    }

    if ($referenceType !== null) {
        $referenceType = trim($referenceType);

        if (
            $referenceType === '' ||
            strlen($referenceType) > 40 ||
            !preg_match(
                '/\A[a-z0-9_]+\z/',
                $referenceType
            )
        ) {
            throw new WalletException(
                'Invalid wallet reference type.'
            );
        }
    }

    $idempotencyKey =
        normalizeWalletIdempotencyKey(
            $idempotencyKey
        );
    $description =
        normalizeWalletDescription(
            $description
        );

    $insert = $pdo->prepare("
        INSERT INTO wallet_transactions (
            wallet_tx_wallet_id,
            wallet_tx_user_id,
            wallet_tx_effect,
            wallet_tx_type,
            wallet_tx_amount,
            wallet_tx_balance_after,
            wallet_tx_reserved_after,
            wallet_tx_reference_type,
            wallet_tx_reference_id,
            wallet_tx_idempotency_key,
            wallet_tx_description
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $insert->execute([
        $walletId,
        $userId,
        $effect,
        $type,
        moneySenToDecimal($amountSen),
        moneySenToDecimal($balanceAfterSen),
        moneySenToDecimal($reservedAfterSen),
        $referenceType,
        $referenceId,
        $idempotencyKey,
        $description,
    ]);

    $transactionId =
        (int) $pdo->lastInsertId();

    if ($transactionId < 1) {
        throw new WalletException(
            'Wallet ledger entry could not be created.'
        );
    }

    return $transactionId;
}

function creditWallet(
    PDO $pdo,
    int $userId,
    int $amountSen,
    string $type,
    ?string $referenceType,
    ?int $referenceId,
    string $idempotencyKey,
    string $description
): array {
    walletRequireTransaction($pdo);

    if ($amountSen < 1) {
        throw new WalletException(
            'Wallet credit amount must be greater than zero.'
        );
    }

    $idempotencyKey =
        normalizeWalletIdempotencyKey(
            $idempotencyKey
        );

    $wallet = lockWalletAccount(
        $pdo,
        $userId
    );

    $existing = walletExistingTransaction(
        $pdo,
        $idempotencyKey,
        true
    );

    if ($existing) {
        $existingAmountSen = moneyDecimalToSen(
            (string) $existing['wallet_tx_amount']
        );

        if (
            (int) $existing['wallet_tx_user_id'] !==
                $userId ||
            $existing['wallet_tx_effect'] !==
                'credit' ||
            $existing['wallet_tx_type'] !==
                $type ||
            $existingAmountSen !== $amountSen
        ) {
            throw new WalletException(
                'Wallet idempotency conflict detected.'
            );
        }

        return [
            'created' => false,
            'transaction_id' =>
                (int) $existing['wallet_tx_id'],
            'balance_after_sen' =>
                moneyDecimalToSen(
                    (string) $existing[
                        'wallet_tx_balance_after'
                    ]
                ),
            'reserved_after_sen' =>
                moneyDecimalToSen(
                    (string) $existing[
                        'wallet_tx_reserved_after'
                    ]
                ),
        ];
    }

    $newBalanceSen =
        (int) $wallet['wallet_balance_sen'] +
        $amountSen;

    if ($newBalanceSen > 9999999999) {
        throw new WalletException(
            'Wallet balance would exceed the supported limit.'
        );
    }

    $update = $pdo->prepare("
        UPDATE wallet_accounts
        SET wallet_balance = ?,
            wallet_updated_at = NOW()
        WHERE wallet_id = ?
        AND wallet_user_id = ?
    ");
    $update->execute([
        moneySenToDecimal($newBalanceSen),
        (int) $wallet['wallet_id'],
        $userId,
    ]);

    if ($update->rowCount() !== 1) {
        throw new WalletException(
            'Wallet balance could not be updated.'
        );
    }

    $transactionId = insertWalletLedgerEvent(
        $pdo,
        (int) $wallet['wallet_id'],
        $userId,
        'credit',
        $type,
        $amountSen,
        $newBalanceSen,
        (int) $wallet['wallet_reserved_sen'],
        $referenceType,
        $referenceId,
        $idempotencyKey,
        $description
    );

    return [
        'created' => true,
        'transaction_id' => $transactionId,
        'balance_after_sen' =>
            $newBalanceSen,
        'reserved_after_sen' =>
            (int) $wallet['wallet_reserved_sen'],
    ];
}

function debitWallet(
    PDO $pdo,
    int $userId,
    int $amountSen,
    string $type,
    ?string $referenceType,
    ?int $referenceId,
    string $idempotencyKey,
    string $description
): array {
    walletRequireTransaction($pdo);

    if ($amountSen < 1) {
        throw new WalletException(
            'Wallet debit amount must be greater than zero.'
        );
    }

    $idempotencyKey =
        normalizeWalletIdempotencyKey(
            $idempotencyKey
        );

    $wallet = lockWalletAccount(
        $pdo,
        $userId
    );

    $existing = walletExistingTransaction(
        $pdo,
        $idempotencyKey,
        true
    );

    if ($existing) {
        $existingAmountSen =
            moneyDecimalToSen(
                (string) $existing[
                    'wallet_tx_amount'
                ]
            );

        if (
            (int) $existing[
                'wallet_tx_user_id'
            ] !== $userId ||
            $existing[
                'wallet_tx_effect'
            ] !== 'debit' ||
            $existing[
                'wallet_tx_type'
            ] !== $type ||
            $existingAmountSen !== $amountSen
        ) {
            throw new WalletException(
                'Wallet idempotency conflict detected.'
            );
        }

        return [
            'created' => false,
            'transaction_id' =>
                (int) $existing[
                    'wallet_tx_id'
                ],
            'balance_after_sen' =>
                moneyDecimalToSen(
                    (string) $existing[
                        'wallet_tx_balance_after'
                    ]
                ),
            'reserved_after_sen' =>
                moneyDecimalToSen(
                    (string) $existing[
                        'wallet_tx_reserved_after'
                    ]
                ),
        ];
    }

    if (
        $amountSen >
        (int) $wallet[
            'wallet_available_sen'
        ]
    ) {
        throw new WalletException(
            'Insufficient available wallet balance.'
        );
    }

    $newBalanceSen =
        (int) $wallet[
            'wallet_balance_sen'
        ] -
        $amountSen;

    if (
        $newBalanceSen < 0 ||
        (int) $wallet[
            'wallet_reserved_sen'
        ] >
            $newBalanceSen
    ) {
        throw new WalletException(
            'Wallet balance would become inconsistent.'
        );
    }

    $update = $pdo->prepare("
        UPDATE wallet_accounts
        SET wallet_balance = ?,
            wallet_updated_at = NOW()
        WHERE wallet_id = ?
        AND wallet_user_id = ?
    ");

    $update->execute([
        moneySenToDecimal(
            $newBalanceSen
        ),
        (int) $wallet['wallet_id'],
        $userId,
    ]);

    if ($update->rowCount() !== 1) {
        throw new WalletException(
            'Wallet balance could not be updated.'
        );
    }

    $transactionId =
        insertWalletLedgerEvent(
            $pdo,
            (int) $wallet['wallet_id'],
            $userId,
            'debit',
            $type,
            $amountSen,
            $newBalanceSen,
            (int) $wallet[
                'wallet_reserved_sen'
            ],
            $referenceType,
            $referenceId,
            $idempotencyKey,
            $description
        );

    return [
        'created' => true,
        'transaction_id' =>
            $transactionId,
        'balance_after_sen' =>
            $newBalanceSen,
        'reserved_after_sen' =>
            (int) $wallet[
                'wallet_reserved_sen'
            ],
    ];
}

function refundWalletOrderPayment(
    PDO $pdo,
    int $userId,
    int $orderId,
    int $originalTransactionId,
    int $amountSen,
    string $description
): array {
    walletRequireTransaction($pdo);

    if (
        $userId < 1 ||
        $orderId < 1 ||
        $originalTransactionId < 1 ||
        $amountSen < 1
    ) {
        throw new WalletException(
            'Invalid wallet order payment refund.'
        );
    }

    lockWalletAccount(
        $pdo,
        $userId
    );

    $originalStatement =
        $pdo->prepare("
            SELECT
                wallet_tx_id,
                wallet_tx_user_id,
                wallet_tx_effect,
                wallet_tx_type,
                wallet_tx_amount,
                wallet_tx_reference_type,
                wallet_tx_reference_id
            FROM wallet_transactions
            WHERE wallet_tx_id = ?
            AND wallet_tx_user_id = ?
            LIMIT 1
            FOR UPDATE
        ");

    $originalStatement->execute([
        $originalTransactionId,
        $userId,
    ]);

    $original =
        $originalStatement->fetch(
            PDO::FETCH_ASSOC
        );

    if (
        !$original ||
        $original[
            'wallet_tx_effect'
        ] !== 'debit' ||
        $original[
            'wallet_tx_type'
        ] !== 'order_payment' ||
        $original[
            'wallet_tx_reference_type'
        ] !== 'order' ||
        (int) $original[
            'wallet_tx_reference_id'
        ] !== $orderId ||
        moneyDecimalToSen(
            (string) $original[
                'wallet_tx_amount'
            ]
        ) !== $amountSen
    ) {
        throw new WalletException(
            'Original wallet order payment could not be verified.'
        );
    }

    return creditWallet(
        $pdo,
        $userId,
        $amountSen,
        'order_payment_refund',
        'order',
        $orderId,
        'order:payment-refund:' .
            $orderId,
        $description
    );
}

function creditWalletRefund(
    PDO $pdo,
    int $userId,
    string $sourceType,
    int $sourceId,
    int $amountSen,
    string $description
): array {
    walletRequireTransaction($pdo);

    $typeMap = [
        'return' => 'return_refund',
        'customer_cancellation' =>
            'cancellation_refund',
        'payment_timeout' =>
            'payment_timeout_refund',
    ];

    if (
        $userId < 1 ||
        $sourceId < 1 ||
        !isset($typeMap[$sourceType]) ||
        $amountSen < 0
    ) {
        throw new WalletException(
            'Invalid wallet refund request.'
        );
    }

    if ($amountSen === 0) {
        return [
            'created' => false,
            'refund_credit_id' => null,
            'transaction_id' => null,
            'amount_sen' => 0,
            'withdrawal_expires_at' => null,
        ];
    }

    $wallet = lockWalletAccount(
        $pdo,
        $userId
    );

    $existingStatement = $pdo->prepare("
        SELECT
            wallet_refund_credit_id,
            wallet_refund_credit_user_id,
            wallet_refund_credit_amount,
            wallet_refund_credit_transaction_id,
            wallet_refund_credit_withdrawal_expires_at
        FROM wallet_refund_credits
        WHERE wallet_refund_credit_source_type = ?
        AND wallet_refund_credit_source_id = ?
        LIMIT 1
        FOR UPDATE
    ");
    $existingStatement->execute([
        $sourceType,
        $sourceId,
    ]);

    $existing = $existingStatement->fetch(
        PDO::FETCH_ASSOC
    );

    if ($existing) {
        if (
            (int) $existing[
                'wallet_refund_credit_user_id'
            ] !== $userId ||
            moneyDecimalToSen(
                (string) $existing[
                    'wallet_refund_credit_amount'
                ]
            ) !== $amountSen
        ) {
            throw new WalletException(
                'Wallet refund source conflict detected.'
            );
        }

        return [
            'created' => false,
            'refund_credit_id' =>
                (int) $existing[
                    'wallet_refund_credit_id'
                ],
            'transaction_id' =>
                (int) $existing[
                    'wallet_refund_credit_transaction_id'
                ],
            'amount_sen' => $amountSen,
            'withdrawal_expires_at' =>
                $existing[
                    'wallet_refund_credit_withdrawal_expires_at'
                ],
        ];
    }

    $idempotencyKey =
        'refund:' .
        $sourceType .
        ':' .
        $sourceId;

    $credit = creditWallet(
        $pdo,
        $userId,
        $amountSen,
        $typeMap[$sourceType],
        $sourceType,
        $sourceId,
        $idempotencyKey,
        $description
    );

    if (!$credit['created']) {
        throw new WalletException(
            'Wallet refund ledger exists without its refund record.'
        );
    }

    $insert = $pdo->prepare("
        INSERT INTO wallet_refund_credits (
            wallet_refund_credit_wallet_id,
            wallet_refund_credit_user_id,
            wallet_refund_credit_source_type,
            wallet_refund_credit_source_id,
            wallet_refund_credit_amount,
            wallet_refund_credit_transaction_id,
            wallet_refund_credit_credited_at,
            wallet_refund_credit_withdrawal_expires_at
        )
        VALUES (
            ?, ?, ?, ?, ?, ?, NOW(),
            DATE_ADD(NOW(), INTERVAL 7 DAY)
        )
    ");
    $insert->execute([
        (int) $wallet['wallet_id'],
        $userId,
        $sourceType,
        $sourceId,
        moneySenToDecimal($amountSen),
        (int) $credit['transaction_id'],
    ]);

    $refundCreditId =
        (int) $pdo->lastInsertId();

    if ($refundCreditId < 1) {
        throw new WalletException(
            'Wallet refund record could not be created.'
        );
    }

    $deadlineStatement = $pdo->prepare("
        SELECT
            wallet_refund_credit_withdrawal_expires_at
        FROM wallet_refund_credits
        WHERE wallet_refund_credit_id = ?
        LIMIT 1
    ");
    $deadlineStatement->execute([
        $refundCreditId,
    ]);

    $deadline =
        $deadlineStatement->fetchColumn();

    return [
        'created' => true,
        'refund_credit_id' =>
            $refundCreditId,
        'transaction_id' =>
            (int) $credit['transaction_id'],
        'amount_sen' => $amountSen,
        'withdrawal_expires_at' =>
            is_string($deadline)
                ? $deadline
                : null,
    ];
}

function getWalletSummary(
    PDO $pdo,
    int $userId
): array {
    $walletId = ensureWalletAccount(
        $pdo,
        $userId
    );

    $statement = $pdo->prepare("
        SELECT
            wallet_id,
            wallet_balance,
            wallet_reserved_amount,
            wallet_created_at,
            wallet_updated_at
        FROM wallet_accounts
        WHERE wallet_id = ?
        AND wallet_user_id = ?
        LIMIT 1
    ");
    $statement->execute([
        $walletId,
        $userId,
    ]);

    $wallet = $statement->fetch(
        PDO::FETCH_ASSOC
    );

    if (!$wallet) {
        throw new WalletException(
            'Wallet account was not found.'
        );
    }

    $balanceSen = moneyDecimalToSen(
        (string) $wallet['wallet_balance']
    );
    $reservedSen = moneyDecimalToSen(
        (string) $wallet['wallet_reserved_amount']
    );

    if ($reservedSen > $balanceSen) {
        throw new WalletException(
            'Wallet balance is inconsistent.'
        );
    }

    return [
        'wallet_id' => $walletId,
        'balance_sen' => $balanceSen,
        'reserved_sen' => $reservedSen,
        'available_sen' =>
            $balanceSen - $reservedSen,
        'created_at' =>
            $wallet['wallet_created_at'],
        'updated_at' =>
            $wallet['wallet_updated_at'],
    ];
}

function getWalletTransactions(
    PDO $pdo,
    int $userId,
    int $limit = 20
): array {
    if ($userId < 1) {
        throw new WalletException(
            'Invalid wallet customer.'
        );
    }

    $limit = max(
        1,
        min(100, $limit)
    );

    $statement = $pdo->prepare("
        SELECT
            wallet_tx_id,
            wallet_tx_effect,
            wallet_tx_type,
            wallet_tx_amount,
            wallet_tx_balance_after,
            wallet_tx_reserved_after,
            wallet_tx_reference_type,
            wallet_tx_reference_id,
            wallet_tx_description,
            wallet_tx_created_at
        FROM wallet_transactions
        WHERE wallet_tx_user_id = ?
        ORDER BY wallet_tx_id DESC
        LIMIT $limit
    ");
    $statement->execute([$userId]);

    return $statement->fetchAll(
        PDO::FETCH_ASSOC
    );
}
