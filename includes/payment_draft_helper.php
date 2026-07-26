<?php

final class PaymentDraftException extends RuntimeException
{
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

    if (!preg_match('/\A[a-z]{3}\z/', $currency)) {
        throw new RuntimeException(
            'The configured payment currency is invalid.'
        );
    }

    try {
        $pdo->beginTransaction();

        /*
         * Serialize payment draft creation for the same customer.
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

            if ($reserveVoucher->rowCount() !== 1) {
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
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
            )
        ");

        $insertDraft->execute([
            $userId,
            $currency,
            round(
                (float) $draft['total'],
                2
            ),
            !empty($draft['has_physical'])
                ? 1
                : 0,
            $draft['address_id'] ?: null,
            $draft['shipping_method'],
            round(
                (float) $draft['shipping_fee'],
                2
            ),
            round(
                (float) $draft[
                    'original_shipping_fee'
                ],
                2
            ),
            $draft['shipping_courier'] ?: null,
            $draft['shipping_zone'],
            $voucherId,
            $draft['voucher_code'] ?: null,
            round(
                (float) $draft['discount_amount'],
                2
            ),
        ]);

        $draftId = (int) $pdo->lastInsertId();

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
                $item['cart_item_id'] ?? 0
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
                    $item['product_title'] ?? ''
                )
            );

            $unitPrice = round(
                (float) (
                    $item['product_price'] ?? -1
                ),
                2
            );

            $type = (string) (
                $item['product_type'] ?? ''
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