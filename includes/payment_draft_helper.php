<?php

require_once __DIR__ . '/voucher_helper.php';
require_once __DIR__ . '/money_helper.php';

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
            payment_draft_address_recipient_name,
            payment_draft_address_taman,
            payment_draft_address_street,
            payment_draft_address_city,
            payment_draft_address_state,
            payment_draft_address_postal_code,
            payment_draft_address_country,
            payment_draft_address_phone,
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
            pdi.payment_draft_item_product_series,
            pdi.payment_draft_item_product_volume_number,
            pdi.payment_draft_item_product_author,
            pdi.payment_draft_item_product_publisher,
            pdi.payment_draft_item_product_isbn,
            pdi.payment_draft_item_product_description,
            pdi.payment_draft_item_product_cover_image,
            pdi.payment_draft_item_quantity,
            pdi.payment_draft_item_unit_price,
            pdi.payment_draft_item_type
        FROM payment_draft_items pdi
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

            'product_series' =>
                $item[
                    'payment_draft_item_product_series'
                ],

            'product_volume_number' =>
                $item[
                    'payment_draft_item_product_volume_number'
                ] === null
                    ? null
                    : (int) $item[
                        'payment_draft_item_product_volume_number'
                    ],

            'product_author' =>
                $item[
                    'payment_draft_item_product_author'
                ],

            'product_publisher' =>
                $item[
                    'payment_draft_item_product_publisher'
                ],

            'product_isbn' =>
                $item[
                    'payment_draft_item_product_isbn'
                ],

            'product_description' =>
                $item[
                    'payment_draft_item_product_description'
                ],

            'product_cover_image' =>
                $item[
                    'payment_draft_item_product_cover_image'
                ],

            'cart_item_quantity' =>
                (int) $item[
                    'payment_draft_item_quantity'
                ],

            'product_price' =>
                moneyNormalizeDecimal(
                    (string) $item[
                        'payment_draft_item_unit_price'
                    ]
                ),

            'product_type' =>
                $item[
                    'payment_draft_item_type'
                ],
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
            moneyNormalizeDecimal(
                (string) $row[
                    'payment_draft_total_amount'
                ]
            ),

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

        'address_snapshot' =>
            (int) $row[
                'payment_draft_has_physical'
            ] === 1
                ? [
                    'recipient_name' =>
                        $row[
                            'payment_draft_address_recipient_name'
                        ],
                    'taman' =>
                        $row[
                            'payment_draft_address_taman'
                        ],
                    'street' =>
                        $row[
                            'payment_draft_address_street'
                        ],
                    'city' =>
                        $row[
                            'payment_draft_address_city'
                        ],
                    'state' =>
                        $row[
                            'payment_draft_address_state'
                        ],
                    'postal_code' =>
                        $row[
                            'payment_draft_address_postal_code'
                        ],
                    'country' =>
                        $row[
                            'payment_draft_address_country'
                        ],
                    'phone' =>
                        $row[
                            'payment_draft_address_phone'
                        ],
                ]
                : null,

        'shipping_method' =>
            $row[
                'payment_draft_shipping_method'
            ],

        'shipping_fee' =>
            moneyNormalizeDecimal(
                (string) $row[
                    'payment_draft_shipping_fee'
                ]
            ),

        'original_shipping_fee' =>
            moneyNormalizeDecimal(
                (string) $row[
                    'payment_draft_original_shipping_fee'
                ]
            ),

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
            moneyNormalizeDecimal(
                (string) $row[
                    'payment_draft_discount_amount'
                ]
            ),

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

    $hasPhysical = !empty(
        $draft['has_physical']
    );

    $addressId = filter_var(
        $draft['address_id'] ?? null,
        FILTER_VALIDATE_INT,
        [
            'options' => [
                'min_range' => 1,
            ],
        ]
    );

    if ($addressId === false) {
        $addressId = null;
    }

    $addressSnapshot =
        $draft['address_snapshot'] ?? null;

    $addressRecipientName = null;
    $addressTaman = null;
    $addressStreet = null;
    $addressCity = null;
    $addressState = null;
    $addressPostalCode = null;
    $addressCountry = null;
    $addressPhone = null;

    if ($hasPhysical) {
        if (
            $addressId === null ||
            !is_array($addressSnapshot)
        ) {
            throw new PaymentDraftException(
                'The shipping address is invalid.'
            );
        }

        $addressRecipientName = trim(
            (string) (
                $addressSnapshot[
                    'recipient_name'
                ] ?? ''
            )
        );

        $addressTaman = trim(
            (string) (
                $addressSnapshot['taman'] ?? ''
            )
        );

        if ($addressTaman === '') {
            $addressTaman = null;
        }

        $addressStreet = trim(
            (string) (
                $addressSnapshot['street'] ?? ''
            )
        );

        $addressCity = trim(
            (string) (
                $addressSnapshot['city'] ?? ''
            )
        );

        $addressState = trim(
            (string) (
                $addressSnapshot['state'] ?? ''
            )
        );

        $addressPostalCode = trim(
            (string) (
                $addressSnapshot[
                    'postal_code'
                ] ?? ''
            )
        );

        $addressCountry = trim(
            (string) (
                $addressSnapshot['country'] ?? ''
            )
        );

        $addressPhone = trim(
            (string) (
                $addressSnapshot['phone'] ?? ''
            )
        );

        if (
            $addressRecipientName === '' ||
            $addressStreet === '' ||
            $addressCity === '' ||
            $addressState === '' ||
            $addressPostalCode === '' ||
            $addressCountry === '' ||
            $addressPhone === ''
        ) {
            throw new PaymentDraftException(
                'The shipping address is incomplete.'
            );
        }
    } else {
        $addressId = null;
    }

    try {
        $totalAmountSen =
            moneyDecimalToSen(
                $draft['total'] ?? null
            );

        $shippingFeeSen =
            moneyDecimalToSen(
                $draft[
                    'shipping_fee'
                ] ?? null
            );

        $originalShippingFeeSen =
            moneyDecimalToSen(
                $draft[
                    'original_shipping_fee'
                ] ?? null
            );

        $discountAmountSen =
            moneyDecimalToSen(
                $draft[
                    'discount_amount'
                ] ?? null
            );

        $itemSubtotalSen = 0;

        foreach ($items as $item) {
            $quantity = (int) (
                $item[
                    'cart_item_quantity'
                ] ?? 0
            );

            if ($quantity < 1) {
                throw new PaymentDraftException(
                    'The payment draft contains an invalid quantity.'
                );
            }

            $unitPriceSen =
                moneyDecimalToSen(
                    $item[
                        'product_price'
                    ] ?? null
                );

            if (
                $unitPriceSen > 0 &&
                $quantity > intdiv(
                    9999999999 -
                        $itemSubtotalSen,
                    $unitPriceSen
                )
            ) {
                throw new PaymentDraftException(
                    'The payment draft subtotal exceeds the supported amount.'
                );
            }

            $itemSubtotalSen +=
                $unitPriceSen * $quantity;
        }
    } catch (MoneyValueException $e) {
        throw new PaymentDraftException(
            'The checkout contains an invalid monetary amount.',
            0,
            $e
        );
    }

    if ($totalAmountSen < 1) {
        throw new PaymentDraftException(
            'The order total must be at least RM 0.01.'
        );
    }

    if (
        !$hasPhysical &&
        (
            $shippingFeeSen !== 0 ||
            $originalShippingFeeSen !== 0
        )
    ) {
        throw new PaymentDraftException(
            'An e-book-only order cannot include shipping charges.'
        );
    }

    if (
        $originalShippingFeeSen <
        $shippingFeeSen
    ) {
        throw new PaymentDraftException(
            'The shipping amount is invalid.'
        );
    }

    if (
        $discountAmountSen >
        $itemSubtotalSen
    ) {
        throw new PaymentDraftException(
            'The discount amount is invalid.'
        );
    }

    $discountedSubtotalSen =
        $itemSubtotalSen -
        $discountAmountSen;

    if (
        $shippingFeeSen >
        9999999999 -
            $discountedSubtotalSen
    ) {
        throw new PaymentDraftException(
            'The order total exceeds the supported amount.'
        );
    }

    $expectedTotalSen =
        $discountedSubtotalSen +
        $shippingFeeSen;

    if (
        $totalAmountSen !==
        $expectedTotalSen
    ) {
        throw new PaymentDraftException(
            'The payment draft total does not match its items.'
        );
    }

    $normalizedTotalAmount =
        moneySenToDecimal(
            $totalAmountSen
        );

    $normalizedShippingFee =
        moneySenToDecimal(
            $shippingFeeSen
        );

    $normalizedOriginalShippingFee =
        moneySenToDecimal(
            $originalShippingFeeSen
        );

    $normalizedDiscountAmount =
        moneySenToDecimal(
            $discountAmountSen
        );

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
                payment_draft_address_recipient_name,
                payment_draft_address_taman,
                payment_draft_address_street,
                payment_draft_address_city,
                payment_draft_address_state,
                payment_draft_address_postal_code,
                payment_draft_address_country,
                payment_draft_address_phone,
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
                ?, ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?, ?
            )
        ");

        $insertDraft->execute([
            $userId,
            $currency,
            $normalizedTotalAmount,
            $hasPhysical ? 1 : 0,
            $addressId,
            $addressRecipientName,
            $addressTaman,
            $addressStreet,
            $addressCity,
            $addressState,
            $addressPostalCode,
            $addressCountry,
            $addressPhone,
            $draft['shipping_method'],
            $normalizedShippingFee,
            $normalizedOriginalShippingFee,
            $draft[
                'shipping_courier'
            ] ?: null,
            $draft['shipping_zone'],
            $voucherId,
            $draft['voucher_code'] ?: null,
            $normalizedDiscountAmount,
        ]);

        $draftId = (int) $pdo
            ->lastInsertId();

        if ($draftId < 1) {
            throw new RuntimeException(
                'Unable to identify the new payment draft.'
            );
        }

        $productSnapshotStatement = $pdo->prepare("
            SELECT
                product_title,
                product_series,
                product_volume_number,
                product_author,
                product_publisher,
                product_isbn,
                product_description,
                product_cover_image
            FROM products
            WHERE product_id = ?
            AND product_type = ?
            LIMIT 1
        ");

        $insertItem = $pdo->prepare("
            INSERT INTO payment_draft_items (
                payment_draft_item_draft_id,
                payment_draft_item_cart_item_id,
                payment_draft_item_product_id,
                payment_draft_item_product_title,
                payment_draft_item_product_series,
                payment_draft_item_product_volume_number,
                payment_draft_item_product_author,
                payment_draft_item_product_publisher,
                payment_draft_item_product_isbn,
                payment_draft_item_product_description,
                payment_draft_item_product_cover_image,
                payment_draft_item_quantity,
                payment_draft_item_unit_price,
                payment_draft_item_type
            )
            VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?
            )
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

            $unitPrice =
                moneyNormalizeDecimal(
                    $item[
                        'product_price'
                    ] ?? null
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

            $productSnapshotStatement->execute([
                $productId,
                $type,
            ]);

            $productSnapshot =
                $productSnapshotStatement->fetch(
                    PDO::FETCH_ASSOC
                );

            if (!$productSnapshot) {
                throw new PaymentDraftException(
                    'One or more products are no longer available.'
                );
            }

            $productTitle = trim(
                (string) (
                    $productSnapshot[
                        'product_title'
                    ] ?? ''
                )
            );

            if ($productTitle === '') {
                throw new RuntimeException(
                    'The payment draft contains an invalid product snapshot.'
                );
            }

            $insertItem->execute([
                $draftId,
                $cartItemId,
                $productId,
                $productTitle,
                $productSnapshot[
                    'product_series'
                ],
                $productSnapshot[
                    'product_volume_number'
                ],
                $productSnapshot[
                    'product_author'
                ],
                $productSnapshot[
                    'product_publisher'
                ],
                $productSnapshot[
                    'product_isbn'
                ],
                $productSnapshot[
                    'product_description'
                ],
                $productSnapshot[
                    'product_cover_image'
                ],
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