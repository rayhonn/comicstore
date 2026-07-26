<?php

require_once __DIR__ . '/voucher_helper.php';

final class PaymentDraftException extends RuntimeException
{
}

/**
 * Find the customer's latest active payment draft.
 */
function findActivePaymentDraftId(
    PDO $pdo,
    int $userId
): ?int {
    if ($userId < 1) {
        return null;
    }

    $statement = $pdo->prepare("
        SELECT payment_draft_id
        FROM payment_drafts
        WHERE payment_draft_user_id = ?
        AND payment_draft_status IN (
            'pending',
            'checkout_open'
        )
        ORDER BY payment_draft_id DESC
        LIMIT 1
    ");

    $statement->execute([$userId]);

    $draftId = $statement->fetchColumn();

    return $draftId === false
        ? null
        : (int) $draftId;
}

/**
 * Load a payment draft and its item snapshots.
 */
function loadPaymentDraft(
    PDO $pdo,
    int $draftId,
    int $userId
): ?array {
    if ($draftId < 1 || $userId < 1) {
        return null;
    }

    $statement = $pdo->prepare("
        SELECT
            payment_draft_id,
            payment_draft_user_id,
            payment_draft_status,
            payment_draft_currency,
            payment_draft_total_amount,
            payment_draft_has_physical,
            payment_draft_address_id,
            payment_draft_shipping_method,
            payment_draft_shipping_fee,
            payment_draft_original_shipping_fee,
            payment_draft_courier,
            payment_draft_delivery_zone,
            payment_draft_voucher_id,
            payment_draft_voucher_code,
            payment_draft_discount_amount,
            payment_draft_stripe_session_id,
            payment_draft_stripe_payment_intent_id,
            payment_draft_stripe_checkout_url,
            UNIX_TIMESTAMP(
                payment_draft_stripe_expires_at
            ) AS stripe_expires_timestamp,
            payment_draft_order_id,
            UNIX_TIMESTAMP(
                payment_draft_created_at
            ) AS created_timestamp
        FROM payment_drafts
        WHERE payment_draft_id = ?
        AND payment_draft_user_id = ?
        LIMIT 1
    ");

    $statement->execute([
        $draftId,
        $userId,
    ]);

    $row = $statement->fetch(
        PDO::FETCH_ASSOC
    );

    if (!$row) {
        return null;
    }

    $itemStatement = $pdo->prepare("
        SELECT
            pdi.payment_draft_item_cart_item_id,
            pdi.payment_draft_item_product_id,
            pdi.payment_draft_item_product_title,
            pdi.payment_draft_item_quantity,
            pdi.payment_draft_item_unit_price,
            pdi.payment_draft_item_type,
            p.product_cover_image
        FROM payment_draft_items pdi
        LEFT JOIN products p
            ON p.product_id =
                pdi.payment_draft_item_product_id
        WHERE pdi.payment_draft_item_draft_id = ?
        ORDER BY pdi.payment_draft_item_id
    ");

    $itemStatement->execute([$draftId]);

    $items = [];

    foreach (
        $itemStatement->fetchAll(
            PDO::FETCH_ASSOC
        ) as $item
    ) {
        $items[] = [
            'cart_item_id' =>
                (int) $item[
                    'payment_draft_item_cart_item_id'
                ],

            'cart_item_product_id' =>
                (int) $item[
                    'payment_draft_item_product_id'
                ],

            'product_title' =>
                $item[
                    'payment_draft_item_product_title'
                ],

            'cart_item_quantity' =>
                (int) $item[
                    'payment_draft_item_quantity'
                ],

            'product_price' =>
                (float) $item[
                    'payment_draft_item_unit_price'
                ],

            'product_type' =>
                $item[
                    'payment_draft_item_type'
                ],

            'product_cover_image' =>
                $item['product_cover_image'],
        ];
    }

    if ($items === []) {
        return null;
    }

    return [
        'payment_draft_id' =>
            (int) $row['payment_draft_id'],

        'user_id' =>
            (int) $row['payment_draft_user_id'],

        'status' =>
            $row['payment_draft_status'],

        'currency' =>
            strtolower(
                $row['payment_draft_currency']
            ),

        'total' =>
            (float) $row[
                'payment_draft_total_amount'
            ],

        'has_physical' =>
            (int) $row[
                'payment_draft_has_physical'
            ] === 1,

        'address_id' =>
            $row[
                'payment_draft_address_id'
            ] === null
                ? null
                : (int) $row[
                    'payment_draft_address_id'
                ],

        'shipping_method' =>
            $row[
                'payment_draft_shipping_method'
            ],

        'shipping_fee' =>
            (float) $row[
                'payment_draft_shipping_fee'
            ],

        'original_shipping_fee' =>
            (float) $row[
                'payment_draft_original_shipping_fee'
            ],

        'shipping_courier' =>
            $row[
                'payment_draft_courier'
            ],

        'shipping_zone' =>
            $row[
                'payment_draft_delivery_zone'
            ],

        'voucher_id' =>
            $row[
                'payment_draft_voucher_id'
            ] === null
                ? null
                : (int) $row[
                    'payment_draft_voucher_id'
                ],

        'voucher_code' =>
            $row[
                'payment_draft_voucher_code'
            ],

        'discount_amount' =>
            (float) $row[
                'payment_draft_discount_amount'
            ],

        'stripe_session_id' =>
            $row[
                'payment_draft_stripe_session_id'
            ],

        'stripe_payment_intent_id' =>
            $row[
                'payment_draft_stripe_payment_intent_id'
            ],

        'stripe_checkout_url' =>
            $row[
                'payment_draft_stripe_checkout_url'
            ],

        'stripe_expires_at' =>
            $row[
                'stripe_expires_timestamp'
            ] === null
                ? null
                : (int) $row[
                    'stripe_expires_timestamp'
                ],

        'order_id' =>
            $row[
                'payment_draft_order_id'
            ] === null
                ? null
                : (int) $row[
                    'payment_draft_order_id'
                ],

        'created_at' =>
            (int) $row[
                'created_timestamp'
            ],

        'items' =>
            $items,
    ];
}

/**
 * Attach a Stripe Checkout Session to a payment draft.
 */
function attachStripeCheckoutSession(
    PDO $pdo,
    int $draftId,
    int $userId,
    string $sessionId,
    string $checkoutUrl,
    int $expiresAt,
    ?string $paymentIntentId = null
): void {
    $sessionId = trim($sessionId);
    $checkoutUrl = trim($checkoutUrl);

    $paymentIntentId = trim(
        (string) $paymentIntentId
    );

    if (
        $draftId < 1 ||
        $userId < 1 ||
        !preg_match(
            '/\Acs_[A-Za-z0-9_]+\z/',
            $sessionId
        ) ||
        !str_starts_with(
            $checkoutUrl,
            'https://checkout.stripe.com/'
        ) ||
        $expiresAt <= time()
    ) {
        throw new RuntimeException(
            'The Stripe Checkout Session details are invalid.'
        );
    }

    if (
        $paymentIntentId !== '' &&
        !preg_match(
            '/\Api_[A-Za-z0-9_]+\z/',
            $paymentIntentId
        )
    ) {
        throw new RuntimeException(
            'The Stripe Payment Intent ID is invalid.'
        );
    }

    try {
        $pdo->beginTransaction();

        $lockDraft = $pdo->prepare("
            SELECT
                payment_draft_status,
                payment_draft_stripe_session_id
            FROM payment_drafts
            WHERE payment_draft_id = ?
            AND payment_draft_user_id = ?
            FOR UPDATE
        ");

        $lockDraft->execute([
            $draftId,
            $userId,
        ]);

        $draft = $lockDraft->fetch(
            PDO::FETCH_ASSOC
        );

        if (!$draft) {
            throw new RuntimeException(
                'The payment draft was not found.'
            );
        }

        if (
            !in_array(
                $draft[
                    'payment_draft_status'
                ],
                [
                    'pending',
                    'checkout_open',
                ],
                true
            )
        ) {
            throw new RuntimeException(
                'The payment draft can no longer open Stripe Checkout.'
            );
        }

        $existingSessionId = trim(
            (string) (
                $draft[
                    'payment_draft_stripe_session_id'
                ] ?? ''
            )
        );

        if (
            $existingSessionId !== '' &&
            !hash_equals(
                $existingSessionId,
                $sessionId
            )
        ) {
            throw new RuntimeException(
                'The payment draft is linked to a different Stripe Session.'
            );
        }

        $update = $pdo->prepare("
            UPDATE payment_drafts
            SET payment_draft_status =
                    'checkout_open',
                payment_draft_stripe_session_id = ?,
                payment_draft_stripe_payment_intent_id = ?,
                payment_draft_stripe_checkout_url = ?,
                payment_draft_stripe_expires_at =
                    FROM_UNIXTIME(?)
            WHERE payment_draft_id = ?
            AND payment_draft_user_id = ?
        ");

        $update->execute([
            $sessionId,
            $paymentIntentId !== ''
                ? $paymentIntentId
                : null,
            $checkoutUrl,
            $expiresAt,
            $draftId,
            $userId,
        ]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }
}

/**
 * Expire an unpaid payment draft and restore its voucher.
 */
function expirePaymentDraft(
    PDO $pdo,
    int $draftId,
    int $userId
): void {
    try {
        $pdo->beginTransaction();

        $lockDraft = $pdo->prepare("
            SELECT
                payment_draft_status,
                payment_draft_voucher_id
            FROM payment_drafts
            WHERE payment_draft_id = ?
            AND payment_draft_user_id = ?
            FOR UPDATE
        ");

        $lockDraft->execute([
            $draftId,
            $userId,
        ]);

        $draft = $lockDraft->fetch(
            PDO::FETCH_ASSOC
        );

        if (!$draft) {
            throw new RuntimeException(
                'The payment draft was not found.'
            );
        }

        if (
            !in_array(
                $draft[
                    'payment_draft_status'
                ],
                [
                    'pending',
                    'checkout_open',
                ],
                true
            )
        ) {
            $pdo->commit();
            return;
        }

        $update = $pdo->prepare("
            UPDATE payment_drafts
            SET payment_draft_status =
                    'expired'
            WHERE payment_draft_id = ?
            AND payment_draft_user_id = ?
        ");

        $update->execute([
            $draftId,
            $userId,
        ]);

        restorePendingUserVoucher(
            $pdo,
            $draft[
                'payment_draft_voucher_id'
            ] ?? null,
            $userId
        );

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }
}

/**
 * Cancel an unpaid payment draft and restore its voucher.
 */
function cancelPaymentDraft(
    PDO $pdo,
    int $draftId,
    int $userId
): void {
    try {
        $pdo->beginTransaction();

        $lockDraft = $pdo->prepare("
            SELECT
                payment_draft_status,
                payment_draft_voucher_id,
                payment_draft_order_id
            FROM payment_drafts
            WHERE payment_draft_id = ?
            AND payment_draft_user_id = ?
            FOR UPDATE
        ");

        $lockDraft->execute([
            $draftId,
            $userId,
        ]);

        $draft = $lockDraft->fetch(
            PDO::FETCH_ASSOC
        );

        if (!$draft) {
            throw new RuntimeException(
                'The payment draft was not found.'
            );
        }

        $status = (string) $draft[
            'payment_draft_status'
        ];

        if (
            in_array(
                $status,
                [
                    'cancelled',
                    'expired',
                ],
                true
            )
        ) {
            $pdo->commit();
            return;
        }

        if (
            in_array(
                $status,
                [
                    'paid',
                    'completed',
                ],
                true
            ) ||
            $draft[
                'payment_draft_order_id'
            ] !== null
        ) {
            throw new PaymentDraftException(
                'Payment has already been completed.'
            );
        }

        if (
            !in_array(
                $status,
                [
                    'pending',
                    'checkout_open',
                ],
                true
            )
        ) {
            throw new RuntimeException(
                'The payment draft cannot be cancelled.'
            );
        }

        $update = $pdo->prepare("
            UPDATE payment_drafts
            SET payment_draft_status =
                    'cancelled',
                payment_draft_cancelled_at =
                    NOW()
            WHERE payment_draft_id = ?
            AND payment_draft_user_id = ?
        ");

        $update->execute([
            $draftId,
            $userId,
        ]);

        restorePendingUserVoucher(
            $pdo,
            $draft[
                'payment_draft_voucher_id'
            ] ?? null,
            $userId
        );

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }
}

/**
 * Persist a validated checkout as a database-backed payment draft.
 */
function createPaymentDraft(
    PDO $pdo,
    int $userId,
    array $draft,
    array $items
): int {
    if ($userId < 1 || $items === []) {
        throw new PaymentDraftException(
            'The checkout details are invalid.'
        );
    }

    $currency = strtolower(
        trim(
            (string) (
                defined('STRIPE_CURRENCY')
                    ? STRIPE_CURRENCY
                    : 'myr'
            )
        )
    );

    if (
        !preg_match(
            '/\A[a-z]{3}\z/',
            $currency
        )
    ) {
        throw new RuntimeException(
            'The configured payment currency is invalid.'
        );
    }

    try {
        $pdo->beginTransaction();

        /*
         * Serialize payment draft creation for the same
         * customer.
         */
        $lockUser = $pdo->prepare("
            SELECT user_id
            FROM users
            WHERE user_id = ?
            FOR UPDATE
        ");

        $lockUser->execute([$userId]);

        if (!$lockUser->fetchColumn()) {
            throw new RuntimeException(
                'The checkout customer was not found.'
            );
        }

        $activeDraft = $pdo->prepare("
            SELECT payment_draft_id
            FROM payment_drafts
            WHERE payment_draft_user_id = ?
            AND payment_draft_status IN (
                'pending',
                'checkout_open',
                'paid'
            )
            LIMIT 1
            FOR UPDATE
        ");

        $activeDraft->execute([$userId]);

        if (
            $activeDraft->fetchColumn()
            !== false
        ) {
            throw new PaymentDraftException(
                'You already have a payment in progress.'
            );
        }

        $voucherId = filter_var(
            $draft['voucher_id'] ?? null,
            FILTER_VALIDATE_INT,
            [
                'options' => [
                    'min_range' => 1,
                ],
            ]
        );

        if ($voucherId === false) {
            $voucherId = null;
        }

        if ($voucherId !== null) {
            $reserveVoucher = $pdo->prepare("
                UPDATE user_vouchers
                SET uv_status = 'pending',
                    uv_pending_at = NOW()
                WHERE uv_voucher_id = ?
                AND uv_user_id = ?
                AND uv_is_used = 0
                AND uv_status = 'available'
                AND (
                    uv_expires_at IS NULL
                    OR uv_expires_at >= NOW()
                )
            ");

            $reserveVoucher->execute([
                $voucherId,
                $userId,
            ]);

            if (
                $reserveVoucher->rowCount()
                !== 1
            ) {
                throw new PaymentDraftException(
                    'The selected voucher is no longer available.'
                );
            }
        }

        $insertDraft = $pdo->prepare("
            INSERT INTO payment_drafts (
                payment_draft_user_id,
                payment_draft_currency,
                payment_draft_total_amount,
                payment_draft_has_physical,
                payment_draft_address_id,
                payment_draft_shipping_method,
                payment_draft_shipping_fee,
                payment_draft_original_shipping_fee,
                payment_draft_courier,
                payment_draft_delivery_zone,
                payment_draft_voucher_id,
                payment_draft_voucher_code,
                payment_draft_discount_amount
            )
            VALUES (
                ?, ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?
            )
        ");

        $insertDraft->execute([
            $userId,
            $currency,
            round(
                (float) $draft['total'],
                2
            ),
            !empty(
                $draft['has_physical']
            )
                ? 1
                : 0,
            $draft['address_id'] ?: null,
            $draft['shipping_method'],
            round(
                (float) $draft[
                    'shipping_fee'
                ],
                2
            ),
            round(
                (float) $draft[
                    'original_shipping_fee'
                ],
                2
            ),
            $draft[
                'shipping_courier'
            ] ?: null,
            $draft['shipping_zone'],
            $voucherId,
            $draft['voucher_code'] ?: null,
            round(
                (float) $draft[
                    'discount_amount'
                ],
                2
            ),
        ]);

        $draftId = (int) $pdo
            ->lastInsertId();

        if ($draftId < 1) {
            throw new RuntimeException(
                'Unable to identify the new payment draft.'
            );
        }

        $insertItem = $pdo->prepare("
            INSERT INTO payment_draft_items (
                payment_draft_item_draft_id,
                payment_draft_item_cart_item_id,
                payment_draft_item_product_id,
                payment_draft_item_product_title,
                payment_draft_item_quantity,
                payment_draft_item_unit_price,
                payment_draft_item_type
            )
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($items as $item) {
            $cartItemId = (int) (
                $item[
                    'cart_item_id'
                ] ?? 0
            );

            $productId = (int) (
                $item[
                    'cart_item_product_id'
                ] ?? 0
            );

            $quantity = (int) (
                $item[
                    'cart_item_quantity'
                ] ?? 0
            );

            $title = trim(
                (string) (
                    $item[
                        'product_title'
                    ] ?? ''
                )
            );

            $unitPrice = round(
                (float) (
                    $item[
                        'product_price'
                    ] ?? -1
                ),
                2
            );

            $type = (string) (
                $item[
                    'product_type'
                ] ?? ''
            );

            if (
                $cartItemId < 1 ||
                $productId < 1 ||
                $quantity < 1 ||
                $title === '' ||
                $unitPrice < 0 ||
                !in_array(
                    $type,
                    [
                        'physical',
                        'ebook',
                    ],
                    true
                )
            ) {
                throw new RuntimeException(
                    'The payment draft contains an invalid item.'
                );
            }

            $insertItem->execute([
                $draftId,
                $cartItemId,
                $productId,
                $title,
                $quantity,
                $unitPrice,
                $type,
            ]);
        }

        $pdo->commit();

        return $draftId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }
}