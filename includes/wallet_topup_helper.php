<?php

require_once __DIR__ . '/stripe_config.php';
require_once __DIR__ . '/wallet_helper.php';
require_once __DIR__ . '/notifications.php';

final class WalletTopupException extends RuntimeException
{
}

function normalizeWalletTopupAmount(
    mixed $value
): int {
    if (!is_string($value)) {
        throw new WalletTopupException(
            'Please enter a valid top-up amount.'
        );
    }

    try {
        $amountSen = moneyDecimalToSen(
            trim($value)
        );
    } catch (MoneyValueException $e) {
        throw new WalletTopupException(
            'Top-up amount must use a valid amount with up to two decimal places.',
            0,
            $e
        );
    }

    if (
        $amountSen < 100 ||
        $amountSen > 500000
    ) {
        throw new WalletTopupException(
            'Top-up amount must be between RM 1.00 and RM 5,000.00.'
        );
    }

    return $amountSen;
}

function createWalletTopup(
    PDO $pdo,
    int $userId,
    int $amountSen
): int {
    if (
        $userId < 1 ||
        $amountSen < 100 ||
        $amountSen > 500000
    ) {
        throw new WalletTopupException(
            'Invalid wallet top-up request.'
        );
    }

    $ownsTransaction =
        !$pdo->inTransaction();

    try {
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        lockWalletAccount(
            $pdo,
            $userId
        );

        $recent = $pdo->prepare("
            SELECT COUNT(*)
            FROM wallet_topups
            WHERE wallet_topup_user_id = ?
            AND wallet_topup_status IN (
                'pending',
                'checkout_open'
            )
            AND wallet_topup_created_at >=
                DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ");
        $recent->execute([$userId]);

        if ((int) $recent->fetchColumn() >= 5) {
            throw new WalletTopupException(
                'Too many unfinished top-up attempts. Please complete or wait for an existing payment session to expire.'
            );
        }

        $insert = $pdo->prepare("
            INSERT INTO wallet_topups (
                wallet_topup_user_id,
                wallet_topup_amount,
                wallet_topup_currency,
                wallet_topup_status
            )
            VALUES (?, ?, 'myr', 'pending')
        ");
        $insert->execute([
            $userId,
            moneySenToDecimal($amountSen),
        ]);

        $topupId =
            (int) $pdo->lastInsertId();

        if ($topupId < 1) {
            throw new WalletTopupException(
                'Wallet top-up could not be created.'
            );
        }

        if ($ownsTransaction) {
            $pdo->commit();
        }

        return $topupId;
    } catch (Throwable $e) {
        if (
            $ownsTransaction &&
            $pdo->inTransaction()
        ) {
            $pdo->rollBack();
        }

        throw $e;
    }
}

function attachWalletTopupStripeSession(
    PDO $pdo,
    int $topupId,
    int $userId,
    string $sessionId,
    string $checkoutUrl,
    int $expiresAt,
    ?string $paymentIntentId
): void {
    $sessionId = trim($sessionId);
    $checkoutUrl = trim($checkoutUrl);
    $paymentIntentId = trim(
        (string) $paymentIntentId
    );

    if (
        $topupId < 1 ||
        $userId < 1 ||
        !preg_match(
            '/\Acs_[A-Za-z0-9_]+\z/',
            $sessionId
        ) ||
        strlen($sessionId) > 255 ||
        !filter_var(
            $checkoutUrl,
            FILTER_VALIDATE_URL
        ) ||
        !str_starts_with(
            strtolower($checkoutUrl),
            'https://'
        ) ||
        $expiresAt <= time() ||
        $expiresAt > time() + 86400 ||
        (
            $paymentIntentId !== '' &&
            !preg_match(
                '/\Api_[A-Za-z0-9_]+\z/',
                $paymentIntentId
            )
        )
    ) {
        throw new WalletTopupException(
            'Invalid Stripe top-up session.'
        );
    }

    $ownsTransaction =
        !$pdo->inTransaction();

    try {
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        $lock = $pdo->prepare("
            SELECT
                wallet_topup_status,
                wallet_topup_stripe_session_id
            FROM wallet_topups
            WHERE wallet_topup_id = ?
            AND wallet_topup_user_id = ?
            FOR UPDATE
        ");
        $lock->execute([
            $topupId,
            $userId,
        ]);

        $topup = $lock->fetch(
            PDO::FETCH_ASSOC
        );

        if (!$topup) {
            throw new WalletTopupException(
                'Wallet top-up was not found.'
            );
        }

        $storedSession = trim(
            (string) (
                $topup[
                    'wallet_topup_stripe_session_id'
                ] ?? ''
            )
        );

        if ($storedSession !== '') {
            if (!hash_equals(
                $storedSession,
                $sessionId
            )) {
                throw new WalletTopupException(
                    'Wallet top-up is already linked to another Stripe session.'
                );
            }

            if ($ownsTransaction) {
                $pdo->commit();
            }

            return;
        }

        if (
            $topup['wallet_topup_status'] !==
            'pending'
        ) {
            throw new WalletTopupException(
                'Wallet top-up can no longer open a payment session.'
            );
        }

        $update = $pdo->prepare("
            UPDATE wallet_topups
            SET wallet_topup_status =
                    'checkout_open',
                wallet_topup_stripe_session_id = ?,
                wallet_topup_stripe_payment_intent_id = ?,
                wallet_topup_stripe_expires_at =
                    FROM_UNIXTIME(?)
            WHERE wallet_topup_id = ?
            AND wallet_topup_user_id = ?
            AND wallet_topup_status = 'pending'
            AND wallet_topup_stripe_session_id IS NULL
        ");
        $update->execute([
            $sessionId,
            $paymentIntentId !== ''
                ? $paymentIntentId
                : null,
            $expiresAt,
            $topupId,
            $userId,
        ]);

        if ($update->rowCount() !== 1) {
            throw new WalletTopupException(
                'Wallet top-up payment session could not be attached.'
            );
        }

        if ($ownsTransaction) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if (
            $ownsTransaction &&
            $pdo->inTransaction()
        ) {
            $pdo->rollBack();
        }

        throw $e;
    }
}

function markWalletTopupFailed(
    PDO $pdo,
    int $topupId,
    int $userId
): void {
    if ($topupId < 1 || $userId < 1) {
        return;
    }

    $statement = $pdo->prepare("
        UPDATE wallet_topups
        SET wallet_topup_status = 'failed'
        WHERE wallet_topup_id = ?
        AND wallet_topup_user_id = ?
        AND wallet_topup_status = 'pending'
        AND wallet_topup_stripe_session_id IS NULL
    ");
    $statement->execute([
        $topupId,
        $userId,
    ]);
}

function fulfillWalletTopupStripeSession(
    PDO $pdo,
    string $sessionId,
    ?int $expectedUserId = null
): array {
    if ($pdo->inTransaction()) {
        throw new WalletTopupException(
            'Wallet top-up finalization cannot start inside another transaction.'
        );
    }

    $sessionId = trim($sessionId);

    if (
        strlen($sessionId) > 255 ||
        !preg_match(
            '/\Acs_[A-Za-z0-9_]+\z/',
            $sessionId
        )
    ) {
        throw new WalletTopupException(
            'The Stripe wallet top-up session is invalid.'
        );
    }

    \Stripe\Stripe::setApiKey(
        STRIPE_SECRET_KEY
    );

    $stripeSession =
        \Stripe\Checkout\Session::retrieve(
            $sessionId
        );

    $paymentStatus = trim(
        (string) (
            $stripeSession->payment_status
            ?? ''
        )
    );
    $sessionStatus = trim(
        (string) (
            $stripeSession->status
            ?? ''
        )
    );
    $checkoutType = trim(
        (string) (
            $stripeSession
                ->metadata
                ->checkout_type
            ?? ''
        )
    );

    if (
        $checkoutType !== 'wallet_topup' ||
        $paymentStatus !== 'paid' ||
        $sessionStatus !== 'complete'
    ) {
        throw new WalletTopupException(
            'The Stripe wallet top-up is not completed.'
        );
    }

    $metadataTopupId = filter_var(
        (string) (
            $stripeSession
                ->metadata
                ->wallet_topup_id
            ?? ''
        ),
        FILTER_VALIDATE_INT,
        [
            'options' => [
                'min_range' => 1,
            ],
        ]
    );
    $metadataUserId = filter_var(
        (string) (
            $stripeSession
                ->metadata
                ->user_id
            ?? ''
        ),
        FILTER_VALIDATE_INT,
        [
            'options' => [
                'min_range' => 1,
            ],
        ]
    );

    if (
        $metadataTopupId === false ||
        $metadataUserId === false
    ) {
        throw new WalletTopupException(
            'Stripe wallet top-up metadata is invalid.'
        );
    }

    $topupId = (int) $metadataTopupId;
    $userId = (int) $metadataUserId;

    if (
        (
            $expectedUserId !== null &&
            $expectedUserId !== $userId
        ) ||
        (string) (
            $stripeSession->client_reference_id
            ?? ''
        ) !== (string) $userId
    ) {
        throw new WalletTopupException(
            'Stripe wallet top-up ownership mismatch.'
        );
    }

    $amountTotal = (int) (
        $stripeSession->amount_total
        ?? -1
    );
    $currency = strtolower(
        trim(
            (string) (
                $stripeSession->currency
                ?? ''
            )
        )
    );

    if (
        $amountTotal < 100 ||
        $amountTotal > 500000 ||
        $currency !== 'myr'
    ) {
        throw new WalletTopupException(
            'Stripe wallet top-up amount is invalid.'
        );
    }

    $paymentIntentId = '';

    if (
        is_string(
            $stripeSession->payment_intent
            ?? null
        )
    ) {
        $paymentIntentId = trim(
            $stripeSession->payment_intent
        );
    } elseif (
        is_object(
            $stripeSession->payment_intent
            ?? null
        ) &&
        is_string(
            $stripeSession
                ->payment_intent
                ->id
            ?? null
        )
    ) {
        $paymentIntentId = trim(
            $stripeSession
                ->payment_intent
                ->id
        );
    }

    if (
        $paymentIntentId !== '' &&
        !preg_match(
            '/\Api_[A-Za-z0-9_]+\z/',
            $paymentIntentId
        )
    ) {
        throw new WalletTopupException(
            'Stripe wallet top-up payment intent is invalid.'
        );
    }

    $created = false;
    $walletCredit = null;

    try {
        $pdo->beginTransaction();

        $topupStatement = $pdo->prepare("
            SELECT
                wallet_topup_user_id,
                wallet_topup_amount,
                wallet_topup_currency,
                wallet_topup_status,
                wallet_topup_stripe_session_id,
                wallet_topup_stripe_payment_intent_id
            FROM wallet_topups
            WHERE wallet_topup_id = ?
            AND wallet_topup_user_id = ?
            FOR UPDATE
        ");
        $topupStatement->execute([
            $topupId,
            $userId,
        ]);

        $topup = $topupStatement->fetch(
            PDO::FETCH_ASSOC
        );

        if (!$topup) {
            throw new WalletTopupException(
                'Wallet top-up record was not found.'
            );
        }

        $storedAmount = moneyDecimalToSen(
            (string) $topup[
                'wallet_topup_amount'
            ]
        );
        $storedCurrency = strtolower(
            (string) $topup[
                'wallet_topup_currency'
            ]
        );
        $storedSession = trim(
            (string) (
                $topup[
                    'wallet_topup_stripe_session_id'
                ] ?? ''
            )
        );
        $storedIntent = trim(
            (string) (
                $topup[
                    'wallet_topup_stripe_payment_intent_id'
                ] ?? ''
            )
        );

        if (
            $storedAmount !== $amountTotal ||
            $storedCurrency !== $currency ||
            (
                $storedSession !== '' &&
                !hash_equals(
                    $storedSession,
                    $sessionId
                )
            ) ||
            (
                $storedIntent !== '' &&
                $paymentIntentId !== '' &&
                !hash_equals(
                    $storedIntent,
                    $paymentIntentId
                )
            )
        ) {
            throw new WalletTopupException(
                'Wallet top-up data does not match Stripe.'
            );
        }

        if (
            $topup['wallet_topup_status'] ===
            'completed'
        ) {
            $existing = walletExistingTransaction(
                $pdo,
                'topup:' . $topupId,
                true
            );

            if (!$existing) {
                throw new WalletTopupException(
                    'Completed wallet top-up is missing its ledger entry.'
                );
            }

            $pdo->commit();

            return [
                'topup_id' => $topupId,
                'user_id' => $userId,
                'amount_sen' => $amountTotal,
                'created' => false,
            ];
        }

        if (
            !in_array(
                $topup['wallet_topup_status'],
                [
                    'pending',
                    'checkout_open',
                    'cancelled',
                    'expired',
                ],
                true
            )
        ) {
            throw new WalletTopupException(
                'Wallet top-up cannot be completed.'
            );
        }

        $update = $pdo->prepare("
            UPDATE wallet_topups
            SET wallet_topup_status = 'completed',
                wallet_topup_stripe_session_id = ?,
                wallet_topup_stripe_payment_intent_id =
                    COALESCE(
                        wallet_topup_stripe_payment_intent_id,
                        ?
                    ),
                wallet_topup_paid_at =
                    COALESCE(
                        wallet_topup_paid_at,
                        NOW()
                    )
            WHERE wallet_topup_id = ?
            AND wallet_topup_user_id = ?
            AND wallet_topup_status != 'completed'
        ");
        $update->execute([
            $sessionId,
            $paymentIntentId !== ''
                ? $paymentIntentId
                : null,
            $topupId,
            $userId,
        ]);

        if ($update->rowCount() !== 1) {
            throw new WalletTopupException(
                'Wallet top-up status could not be completed.'
            );
        }

        $walletCredit = creditWallet(
            $pdo,
            $userId,
            $amountTotal,
            'topup',
            'wallet_topup',
            $topupId,
            'topup:' . $topupId,
            'Wallet top-up via Stripe'
        );

        if (!$walletCredit['created']) {
            throw new WalletTopupException(
                'Wallet top-up ledger was already created before completion.'
            );
        }

        $pdo->commit();
        $created = true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }

    if ($created) {
        try {
            sendNotification(
                $pdo,
                $userId,
                'Wallet Top-Up Successful',
                'RM ' .
                    moneyFormatSen(
                        $amountTotal
                    ) .
                    ' has been added to your MangaVault Wallet.',
                'system'
            );
        } catch (Throwable $e) {
            app_error_log(
                'Wallet top-up notification failed for top-up #' .
                $topupId .
                ': ' .
                $e->getMessage()
            );
        }
    }

    return [
        'topup_id' => $topupId,
        'user_id' => $userId,
        'amount_sen' => $amountTotal,
        'created' => $created,
        'wallet_credit' => $walletCredit,
    ];
}

function expireWalletTopupStripeSession(
    PDO $pdo,
    string $sessionId
): void {
    $sessionId = trim($sessionId);

    if (
        strlen($sessionId) > 255 ||
        !preg_match(
            '/\Acs_[A-Za-z0-9_]+\z/',
            $sessionId
        )
    ) {
        throw new WalletTopupException(
            'Invalid Stripe wallet top-up session.'
        );
    }

    $statement = $pdo->prepare("
        UPDATE wallet_topups
        SET wallet_topup_status = 'expired'
        WHERE wallet_topup_stripe_session_id = ?
        AND wallet_topup_status IN (
            'pending',
            'checkout_open'
        )
    ");
    $statement->execute([$sessionId]);
}
