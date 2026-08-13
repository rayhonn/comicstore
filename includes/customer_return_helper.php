<?php

require_once __DIR__ . '/money_helper.php';
require_once __DIR__ . '/voucher_helper.php';
require_once __DIR__ . '/wallet_helper.php';

function allocateReturnDiscountSen(
    int $discountSen,
    int $grossSen,
    int $subtotalSen
): int {
    if (
        $discountSen <= 0 ||
        $grossSen <= 0 ||
        $subtotalSen <= 0
    ) {
        return 0;
    }

    if ($grossSen >= $subtotalSen) {
        return min($discountSen, $subtotalSen);
    }

    return min(
        $discountSen,
        intdiv(
            $discountSen * $grossSen,
            $subtotalSen
        )
    );
}

function reconcileApprovedCustomerReturn(
    PDO $pdo,
    int $returnId,
    int $orderId,
    int $orderItemId,
    int $userId
): array {
    if (
        !$pdo->inTransaction() ||
        $returnId < 1 ||
        $orderId < 1 ||
        $orderItemId < 1 ||
        $userId < 1
    ) {
        throw new RuntimeException(
            'Invalid customer return reconciliation request.'
        );
    }

    $orderStatement = $pdo->prepare("
        SELECT
            order_discount_amount,
            order_voucher_code,
            order_payment_method,
            order_wallet_transaction_id
        FROM orders
        WHERE order_id = ?
        AND order_user_id = ?
        FOR UPDATE
    ");
    $orderStatement->execute([
        $orderId,
        $userId,
    ]);

    $order = $orderStatement->fetch(
        PDO::FETCH_ASSOC
    );

    if (!$order) {
        throw new RuntimeException(
            'Return order was not found.'
        );
    }

    $userStatement = $pdo->prepare("
        SELECT
            user_points,
            user_lifetime_spending,
            user_tier
        FROM users
        WHERE user_id = ?
        FOR UPDATE
    ");
    $userStatement->execute([$userId]);

    $user = $userStatement->fetch(
        PDO::FETCH_ASSOC
    );

    if (!$user) {
        throw new RuntimeException(
            'Return customer was not found.'
        );
    }

    $itemsStatement = $pdo->prepare("
        SELECT
            order_item_id,
            order_item_type,
            order_item_quantity,
            order_item_price
        FROM order_items
        WHERE order_item_order_id = ?
        ORDER BY order_item_id
        FOR UPDATE
    ");
    $itemsStatement->execute([$orderId]);

    $items = $itemsStatement->fetchAll(
        PDO::FETCH_ASSOC
    );

    if ($items === []) {
        throw new RuntimeException(
            'Return order contains no items.'
        );
    }

    $lineGrossByItemId = [];
    $subtotalSen = 0;

    foreach ($items as $item) {
        $itemId = (int) $item['order_item_id'];
        $quantity = (int) $item[
            'order_item_quantity'
        ];

        $unitPriceSen = moneyDecimalToSen(
            (string) $item['order_item_price']
        );

        if (
            $itemId < 1 ||
            $quantity < 1 ||
            $unitPriceSen < 0
        ) {
            throw new RuntimeException(
                'Return order item is invalid.'
            );
        }

        $lineGrossSen =
            $unitPriceSen * $quantity;

        $lineGrossByItemId[$itemId] = [
            'gross_sen' => $lineGrossSen,
            'type' => (string) $item[
                'order_item_type'
            ],
        ];

        $subtotalSen += $lineGrossSen;
    }

    if (
        !isset(
            $lineGrossByItemId[$orderItemId]
        ) ||
        $lineGrossByItemId[$orderItemId][
            'type'
        ] !== 'physical'
    ) {
        throw new RuntimeException(
            'Returned order item is invalid.'
        );
    }

    if ($subtotalSen <= 0) {
        throw new RuntimeException(
            'Return order subtotal is invalid.'
        );
    }

    $approvedStatement = $pdo->prepare("
        SELECT rr.return_item_id
        FROM return_requests rr
        JOIN order_items oi
            ON oi.order_item_id =
                rr.return_item_id
        WHERE rr.return_order_id = ?
        AND oi.order_item_order_id = ?
        AND rr.return_status = 'approved'
        ORDER BY rr.return_id
        FOR UPDATE
    ");
    $approvedStatement->execute([
        $orderId,
        $orderId,
    ]);

    $approvedItemIds = [];
    $approvedGrossAfterSen = 0;

    foreach (
        $approvedStatement->fetchAll(
            PDO::FETCH_COLUMN
        ) as $approvedItemId
    ) {
        $approvedItemId =
            (int) $approvedItemId;

        if (
            isset(
                $lineGrossByItemId[
                    $approvedItemId
                ]
            ) &&
            !isset(
                $approvedItemIds[
                    $approvedItemId
                ]
            )
        ) {
            $approvedItemIds[
                $approvedItemId
            ] = true;

            $approvedGrossAfterSen +=
                $lineGrossByItemId[
                    $approvedItemId
                ]['gross_sen'];
        }
    }

    if (!isset($approvedItemIds[$orderItemId])) {
        throw new RuntimeException(
            'Approved return item was not found.'
        );
    }

    $currentLineGrossSen =
        $lineGrossByItemId[$orderItemId][
            'gross_sen'
        ];

    $approvedGrossBeforeSen = max(
        0,
        $approvedGrossAfterSen -
            $currentLineGrossSen
    );

    $discountSen = min(
        $subtotalSen,
        max(
            0,
            moneyDecimalToSen(
                (string) (
                    $order[
                        'order_discount_amount'
                    ] ?? '0.00'
                )
            )
        )
    );

    $discountBeforeSen =
        allocateReturnDiscountSen(
            $discountSen,
            $approvedGrossBeforeSen,
            $subtotalSen
        );

    $discountAfterSen =
        allocateReturnDiscountSen(
            $discountSen,
            $approvedGrossAfterSen,
            $subtotalSen
        );

    $netRefundBeforeSen = max(
        0,
        $approvedGrossBeforeSen -
            $discountBeforeSen
    );

    $netRefundAfterSen = max(
        0,
        $approvedGrossAfterSen -
            $discountAfterSen
    );

    $refundAmountSen = max(
        0,
        $netRefundAfterSen -
            $netRefundBeforeSen
    );

    $originalPointsStatement =
        $pdo->prepare("
            SELECT COALESCE(
                SUM(log_points),
                0
            )
            FROM points_log
            WHERE log_user_id = ?
            AND log_order_id = ?
            AND log_type = 'earn'
            AND log_points > 0
        ");
    $originalPointsStatement->execute([
        $userId,
        $orderId,
    ]);

    $originalPoints = max(
        0,
        (int) $originalPointsStatement
            ->fetchColumn()
    );

    $eligibleOrderSpendSen = max(
        0,
        $subtotalSen - $discountSen
    );

    $pointsBefore = 0;
    $pointsAfter = 0;

    if (
        $originalPoints > 0 &&
        $eligibleOrderSpendSen > 0
    ) {
        $pointsBefore = intdiv(
            $originalPoints *
                $netRefundBeforeSen,
            $eligibleOrderSpendSen
        );

        $pointsAfter = intdiv(
            $originalPoints *
                $netRefundAfterSen,
            $eligibleOrderSpendSen
        );
    }

    $pointsToReverse = max(
        0,
        $pointsAfter - $pointsBefore
    );

    $currentLifetimeSpendingSen =
        moneyDecimalToSen(
            (string) (
                $user[
                    'user_lifetime_spending'
                ] ?? '0.00'
            )
        );

    $newLifetimeSpendingSen = max(
        0,
        $currentLifetimeSpendingSen -
            $refundAmountSen
    );

    $tierStatement = $pdo->query("
        SELECT
            tier_name,
            tier_min_spending
        FROM tier_config
        ORDER BY tier_min_spending DESC
    ");

    $newTier = 'bronze';

    foreach (
        $tierStatement->fetchAll(
            PDO::FETCH_ASSOC
        ) as $tier
    ) {
        if (
            $newLifetimeSpendingSen >=
            moneyDecimalToSen(
                (string) $tier[
                    'tier_min_spending'
                ]
            )
        ) {
            $newTier = (string) $tier[
                'tier_name'
            ];
            break;
        }
    }

    $updateUser = $pdo->prepare("
        UPDATE users
        SET user_points = user_points - ?,
            user_lifetime_spending = ?,
            user_tier = ?
        WHERE user_id = ?
    ");
    $updateUser->execute([
        $pointsToReverse,
        moneySenToDecimal(
            $newLifetimeSpendingSen
        ),
        $newTier,
        $userId,
    ]);

    if ($pointsToReverse > 0) {
        $insertPointsLog = $pdo->prepare("
            INSERT INTO points_log (
                log_user_id,
                log_points,
                log_type,
                log_description,
                log_order_id
            )
            VALUES (?, ?, 'redeem', ?, ?)
        ");
        $insertPointsLog->execute([
            $userId,
            -$pointsToReverse,
            'Reversed for Return #' .
                str_pad(
                    (string) $returnId,
                    4,
                    '0',
                    STR_PAD_LEFT
                ),
            $orderId,
        ]);
    }

    $fullOrderReturned =
        count($approvedItemIds) ===
        count($lineGrossByItemId);

    $voucherRestored = false;

    $voucherCode = trim(
        (string) (
            $order['order_voucher_code']
            ?? ''
        )
    );

    if (
        $fullOrderReturned &&
        $voucherCode !== ''
    ) {
        $voucherRestored =
            restoreOrderVoucherUsage(
                $pdo,
                $voucherCode,
                $orderId,
                $userId
            );
    }

    $walletRefundCreated = false;
    $walletRefundCreditId = null;
    $walletWithdrawalExpiresAt = null;

    if (
        (string) (
            $order[
                'order_payment_method'
            ] ?? ''
        ) === 'wallet'
    ) {
        $originalWalletTransactionId =
            (int) (
                $order[
                    'order_wallet_transaction_id'
                ] ?? 0
            );

        if ($originalWalletTransactionId < 1) {
            throw new RuntimeException(
                'Original wallet payment transaction is missing.'
            );
        }

        $originalWalletPaymentStatement =
            $pdo->prepare("
                SELECT
                    wallet_tx_amount
                FROM wallet_transactions
                WHERE wallet_tx_id = ?
                AND wallet_tx_user_id = ?
                AND wallet_tx_effect = 'debit'
                AND wallet_tx_type =
                    'order_payment'
                AND wallet_tx_reference_type =
                    'order'
                AND wallet_tx_reference_id = ?
                LIMIT 1
                FOR UPDATE
            ");

        $originalWalletPaymentStatement
            ->execute([
                $originalWalletTransactionId,
                $userId,
                $orderId,
            ]);

        $originalWalletPaymentAmount =
            $originalWalletPaymentStatement
                ->fetchColumn();

        if ($originalWalletPaymentAmount === false) {
            throw new RuntimeException(
                'Original wallet payment could not be verified.'
            );
        }

        $originalWalletPaymentSen =
            moneyDecimalToSen(
                (string)
                    $originalWalletPaymentAmount
            );

        $priorWalletReturnStatement =
            $pdo->prepare("
                SELECT
                    COALESCE(
                        SUM(
                            wt.wallet_tx_amount
                        ),
                        0
                    )
                FROM wallet_transactions wt
                INNER JOIN return_requests rr
                    ON rr.return_id =
                        wt.wallet_tx_reference_id
                WHERE wt.wallet_tx_user_id = ?
                AND wt.wallet_tx_effect =
                    'credit'
                AND wt.wallet_tx_type =
                    'order_payment_refund'
                AND wt.wallet_tx_reference_type =
                    'return'
                AND rr.return_order_id = ?
                AND rr.return_id <> ?
            ");

        $priorWalletReturnStatement
            ->execute([
                $userId,
                $orderId,
                $returnId,
            ]);

        $priorWalletReturnSen =
            moneyDecimalToSen(
                (string) (
                    $priorWalletReturnStatement
                        ->fetchColumn()
                    ?: '0.00'
                )
            );

        if (
            $priorWalletReturnSen +
                $refundAmountSen >
            $originalWalletPaymentSen
        ) {
            throw new RuntimeException(
                'Wallet return refund would exceed the original wallet payment.'
            );
        }

        if ($refundAmountSen > 0) {
            $walletRefund =
                creditWallet(
                    $pdo,
                    $userId,
                    $refundAmountSen,
                    'order_payment_refund',
                    'return',
                    $returnId,
                    'order:wallet-return-refund:' .
                        $returnId,
                    'Wallet refund for approved Return #' .
                        str_pad(
                            (string) $returnId,
                            4,
                            '0',
                            STR_PAD_LEFT
                        )
                );

            $walletRefundCreated =
                (bool) $walletRefund[
                    'created'
                ];
        }
    } else {
        $walletRefund =
            creditWalletRefund(
                $pdo,
                $userId,
                'return',
                $returnId,
                $refundAmountSen,
                'Refund for approved Return #' .
                    str_pad(
                        (string) $returnId,
                        4,
                        '0',
                        STR_PAD_LEFT
                    )
            );

        $walletRefundCreated =
            (bool) $walletRefund[
                'created'
            ];

        $walletRefundCreditId =
            $walletRefund[
                'refund_credit_id'
            ];

        $walletWithdrawalExpiresAt =
            $walletRefund[
                'withdrawal_expires_at'
            ];
    }

    return [
        'refund_amount_sen' =>
            $refundAmountSen,
        'points_reversed' =>
            $pointsToReverse,
        'voucher_restored' =>
            $voucherRestored,
        'full_order_returned' =>
            $fullOrderReturned,
        'new_tier' => $newTier,
        'wallet_refund_credited' =>
            $walletRefundCreated,
        'wallet_refund_credit_id' =>
            $walletRefundCreditId,
        'wallet_withdrawal_expires_at' =>
            $walletWithdrawalExpiresAt,
    ];
}