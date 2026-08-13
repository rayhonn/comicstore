<?php

use PHPMailer\PHPMailer\PHPMailer;

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/money_helper.php';
require_once __DIR__ . '/payment_draft_helper.php';
require_once __DIR__ . '/ebook_helper.php';
require_once __DIR__ . '/wallet_helper.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/mail_config.php';

final class WalletOrderPaymentException extends RuntimeException
{
}

function verifyWalletOrderPaymentPassword(
    PDO $pdo,
    int $userId,
    mixed $password
): void {
    if (
        $userId < 1 ||
        !is_string($password) ||
        $password === '' ||
        strlen($password) > 72
    ) {
        throw new WalletOrderPaymentException(
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
        AND user_role = 'customer'
        LIMIT 1
    ");
    $statement->execute([$userId]);

    $user = $statement->fetch(PDO::FETCH_ASSOC);

    if (
        !$user ||
        (int) $user['user_is_active'] !== 1 ||
        !empty($user['user_deleted_at']) ||
        !password_verify(
            $password,
            (string) $user['user_password_hash']
        )
    ) {
        throw new WalletOrderPaymentException(
            'Current password is incorrect.'
        );
    }
}

function finalizeWalletPaymentDraft(
    PDO $pdo,
    int $draftId,
    int $userId,
    mixed $currentPassword
): array {
    if ($draftId < 1 || $userId < 1) {
        throw new WalletOrderPaymentException(
            'Invalid wallet payment request.'
        );
    }

    verifyWalletOrderPaymentPassword(
        $pdo,
        $userId,
        $currentPassword
    );

    try {
        $pdo->beginTransaction();

        $draftStatement = $pdo->prepare("
            SELECT
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
                payment_draft_courier,
                payment_draft_delivery_zone,
                payment_draft_voucher_id,
                payment_draft_voucher_code,
                payment_draft_discount_amount,
                payment_draft_stripe_session_id,
                payment_draft_order_id
            FROM payment_drafts
            WHERE payment_draft_id = ?
            AND payment_draft_user_id = ?
            FOR UPDATE
        ");
        $draftStatement->execute([
            $draftId,
            $userId,
        ]);

        $draft = $draftStatement->fetch(
            PDO::FETCH_ASSOC
        );

        if (!$draft) {
            throw new WalletOrderPaymentException(
                'The payment draft was not found.'
            );
        }

        $linkedOrderId = $draft[
            'payment_draft_order_id'
        ];

        if ($linkedOrderId !== null) {
            $existingOrder = $pdo->prepare("
                SELECT
                    order_id,
                    order_user_id,
                    order_payment_method,
                    order_payment_status,
                    order_wallet_transaction_id
                FROM orders
                WHERE order_id = ?
                AND order_user_id = ?
                LIMIT 1
                FOR UPDATE
            ");
            $existingOrder->execute([
                $linkedOrderId,
                $userId,
            ]);

            $order = $existingOrder->fetch(
                PDO::FETCH_ASSOC
            );

            if (
                !$order ||
                $order['order_payment_method'] !== 'wallet' ||
                $order['order_payment_status'] !== 'confirmed' ||
                empty($order['order_wallet_transaction_id'])
            ) {
                throw new WalletOrderPaymentException(
                    'The linked wallet order could not be verified.'
                );
            }

            $pdo->commit();

            return [
                'order_id' => (int) $order['order_id'],
                'user_id' => (int) $order['order_user_id'],
                'payment_status' => 'confirmed',
                'wallet_transaction_id' =>
                    (int) $order[
                        'order_wallet_transaction_id'
                    ],
                'created' => false,
            ];
        }

        $stripeSessionId = trim(
            (string) (
                $draft[
                    'payment_draft_stripe_session_id'
                ] ?? ''
            )
        );

        if (
            $draft['payment_draft_status'] !== 'pending' ||
            $stripeSessionId !== ''
        ) {
            throw new WalletOrderPaymentException(
                'Wallet payment is unavailable after a Stripe payment session has been opened.'
            );
        }

        $currency = strtolower(
            (string) $draft[
                'payment_draft_currency'
            ]
        );

        $amountSen = moneyDecimalToSen(
            (string) $draft[
                'payment_draft_total_amount'
            ]
        );

        if ($currency !== 'myr' || $amountSen < 1) {
            throw new WalletOrderPaymentException(
                'Wallet payment amount or currency is invalid.'
            );
        }

        $itemStatement = $pdo->prepare("
            SELECT
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
            FROM payment_draft_items
            WHERE payment_draft_item_draft_id = ?
            ORDER BY payment_draft_item_id
            FOR UPDATE
        ");
        $itemStatement->execute([$draftId]);

        $items = $itemStatement->fetchAll(
            PDO::FETCH_ASSOC
        );

        if ($items === []) {
            throw new WalletOrderPaymentException(
                'The payment draft contains no items.'
            );
        }

        foreach ($items as $item) {
            $productId = (int) $item[
                'payment_draft_item_product_id'
            ];
            $quantity = (int) $item[
                'payment_draft_item_quantity'
            ];
            $itemType = (string) $item[
                'payment_draft_item_type'
            ];

            if (
                $productId < 1 ||
                $quantity < 1 ||
                !in_array(
                    $itemType,
                    ['physical', 'ebook'],
                    true
                )
            ) {
                throw new WalletOrderPaymentException(
                    'The payment draft contains an invalid item.'
                );
            }

            if ($itemType === 'ebook') {
                if ($quantity !== 1) {
                    throw new WalletOrderPaymentException(
                        'Each e-book must have a quantity of exactly one.'
                    );
                }

                try {
                    assertCustomerCanPurchaseEbook(
                        $pdo,
                        $userId,
                        $productId,
                        true
                    );
                } catch (RuntimeException $e) {
                    throw new WalletOrderPaymentException(
                        $e->getMessage(),
                        0,
                        $e
                    );
                }
            }
        }

        $hasPhysical =
            (int) $draft[
                'payment_draft_has_physical'
            ] === 1;

        $addressRecipientName = null;
        $addressTaman = null;
        $addressStreet = null;
        $addressCity = null;
        $addressState = null;
        $addressPostalCode = null;
        $addressCountry = null;
        $addressPhone = null;

        if ($hasPhysical) {
            $addressRecipientName = trim(
                (string) (
                    $draft[
                        'payment_draft_address_recipient_name'
                    ] ?? ''
                )
            );
            $addressTaman = trim(
                (string) (
                    $draft[
                        'payment_draft_address_taman'
                    ] ?? ''
                )
            );
            $addressTaman = $addressTaman !== ''
                ? $addressTaman
                : null;
            $addressStreet = trim(
                (string) (
                    $draft[
                        'payment_draft_address_street'
                    ] ?? ''
                )
            );
            $addressCity = trim(
                (string) (
                    $draft[
                        'payment_draft_address_city'
                    ] ?? ''
                )
            );
            $addressState = trim(
                (string) (
                    $draft[
                        'payment_draft_address_state'
                    ] ?? ''
                )
            );
            $addressPostalCode = trim(
                (string) (
                    $draft[
                        'payment_draft_address_postal_code'
                    ] ?? ''
                )
            );
            $addressCountry = trim(
                (string) (
                    $draft[
                        'payment_draft_address_country'
                    ] ?? ''
                )
            );
            $addressPhone = trim(
                (string) (
                    $draft[
                        'payment_draft_address_phone'
                    ] ?? ''
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
                throw new WalletOrderPaymentException(
                    'The payment draft shipping address is incomplete.'
                );
            }
        }

        $orderStatus = $hasPhysical
            ? 'processing'
            : 'delivered';

        $orderInsert = $pdo->prepare("
            INSERT INTO orders (
                order_user_id,
                order_total_amount,
                order_has_physical,
                order_address_id,
                order_address_recipient_name,
                order_address_taman,
                order_address_street,
                order_address_city,
                order_address_state,
                order_address_postal_code,
                order_address_country,
                order_address_phone,
                order_shipping_method,
                order_shipping_fee,
                order_courier,
                order_delivery_zone,
                order_payment_method,
                order_stripe_session_id,
                order_wallet_transaction_id,
                order_payment_status,
                order_status,
                order_confirm_token,
                order_confirm_expires_at,
                order_voucher_code,
                order_discount_amount,
                order_processing_at,
                order_delivered_at
            )
            VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                'wallet', NULL, NULL, 'confirmed', ?, NULL, NULL, ?, ?,
                CASE WHEN ? = 1 THEN NOW() ELSE NULL END,
                CASE WHEN ? = 0 THEN NOW() ELSE NULL END
            )
        ");
        $orderInsert->execute([
            $userId,
            $draft['payment_draft_total_amount'],
            $hasPhysical ? 1 : 0,
            $draft['payment_draft_address_id'],
            $addressRecipientName,
            $addressTaman,
            $addressStreet,
            $addressCity,
            $addressState,
            $addressPostalCode,
            $addressCountry,
            $addressPhone,
            $draft['payment_draft_shipping_method'],
            $draft['payment_draft_shipping_fee'],
            $draft['payment_draft_courier'],
            $draft['payment_draft_delivery_zone'],
            $orderStatus,
            $draft['payment_draft_voucher_code'],
            $draft['payment_draft_discount_amount'],
            $hasPhysical ? 1 : 0,
            $hasPhysical ? 1 : 0,
        ]);

        $orderId = (int) $pdo->lastInsertId();

        if ($orderId < 1) {
            throw new WalletOrderPaymentException(
                'Unable to identify the wallet order.'
            );
        }

        $walletDebit = debitWallet(
            $pdo,
            $userId,
            $amountSen,
            'order_payment',
            'order',
            $orderId,
            'order:payment:' . $orderId,
            'Wallet payment for Order #' .
                str_pad(
                    (string) $orderId,
                    4,
                    '0',
                    STR_PAD_LEFT
                )
        );

        $walletTransactionId = (int) $walletDebit[
            'transaction_id'
        ];

        $linkWalletTransaction = $pdo->prepare("
            UPDATE orders
            SET order_wallet_transaction_id = ?
            WHERE order_id = ?
            AND order_user_id = ?
            AND order_payment_method = 'wallet'
            AND order_wallet_transaction_id IS NULL
        ");
        $linkWalletTransaction->execute([
            $walletTransactionId,
            $orderId,
            $userId,
        ]);

        if ($linkWalletTransaction->rowCount() !== 1) {
            throw new WalletOrderPaymentException(
                'Wallet payment could not be linked to the order.'
            );
        }

        $itemInsert = $pdo->prepare("
            INSERT INTO order_items (
                order_item_order_id,
                order_item_product_id,
                order_item_product_title,
                order_item_product_series,
                order_item_product_volume_number,
                order_item_product_author,
                order_item_product_publisher,
                order_item_product_isbn,
                order_item_product_description,
                order_item_product_cover_image,
                order_item_quantity,
                order_item_price,
                order_item_type
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $reduceStock = $pdo->prepare("
            UPDATE product_physical
            SET physical_stock_quantity =
                physical_stock_quantity - ?
            WHERE physical_product_id = ?
            AND physical_stock_quantity >= ?
        ");

        $deleteCartItem = $pdo->prepare("
            DELETE FROM cart_items
            WHERE cart_item_id = ?
            AND cart_item_user_id = ?
        ");

        foreach ($items as $item) {
            $productId = (int) $item[
                'payment_draft_item_product_id'
            ];
            $quantity = (int) $item[
                'payment_draft_item_quantity'
            ];
            $itemType = (string) $item[
                'payment_draft_item_type'
            ];

            $itemInsert->execute([
                $orderId,
                $productId,
                $item[
                    'payment_draft_item_product_title'
                ],
                $item[
                    'payment_draft_item_product_series'
                ],
                $item[
                    'payment_draft_item_product_volume_number'
                ],
                $item[
                    'payment_draft_item_product_author'
                ],
                $item[
                    'payment_draft_item_product_publisher'
                ],
                $item[
                    'payment_draft_item_product_isbn'
                ],
                $item[
                    'payment_draft_item_product_description'
                ],
                $item[
                    'payment_draft_item_product_cover_image'
                ],
                $quantity,
                $item[
                    'payment_draft_item_unit_price'
                ],
                $itemType,
            ]);

            if ($itemType === 'physical') {
                $reduceStock->execute([
                    $quantity,
                    $productId,
                    $quantity,
                ]);

                if ($reduceStock->rowCount() !== 1) {
                    throw new WalletOrderPaymentException(
                        'Insufficient stock for one or more items.'
                    );
                }
            }

            $deleteCartItem->execute([
                $item[
                    'payment_draft_item_cart_item_id'
                ],
                $userId,
            ]);
        }

        $voucherId = $draft[
            'payment_draft_voucher_id'
        ];

        if ($voucherId !== null) {
            $voucherUsage = $pdo->prepare("
                INSERT INTO voucher_usage (
                    usage_voucher_id,
                    usage_user_id,
                    usage_order_id,
                    usage_discount_amount
                )
                VALUES (?, ?, ?, ?)
            ");
            $voucherUsage->execute([
                $voucherId,
                $userId,
                $orderId,
                $draft['payment_draft_discount_amount'],
            ]);

            $voucherUpdate = $pdo->prepare("
                UPDATE vouchers
                SET voucher_used_count =
                    voucher_used_count + 1
                WHERE voucher_id = ?
                AND (
                    voucher_usage_limit IS NULL
                    OR voucher_used_count < voucher_usage_limit
                )
            ");
            $voucherUpdate->execute([$voucherId]);

            if ($voucherUpdate->rowCount() !== 1) {
                throw new WalletOrderPaymentException(
                    'Voucher usage limit has been reached.'
                );
            }

            $userVoucherUpdate = $pdo->prepare("
                UPDATE user_vouchers
                SET uv_is_used = 1,
                    uv_status = 'used',
                    uv_pending_at = NULL,
                    uv_used_at = NOW()
                WHERE uv_voucher_id = ?
                AND uv_user_id = ?
                AND uv_is_used = 0
                AND uv_status IN (
                    'available',
                    'pending'
                )
            ");
            $userVoucherUpdate->execute([
                $voucherId,
                $userId,
            ]);

            if ($userVoucherUpdate->rowCount() !== 1) {
                throw new WalletOrderPaymentException(
                    'Customer voucher state could not be finalized.'
                );
            }
        }

        $ebookStatement = $pdo->prepare("
            SELECT DISTINCT order_item_product_id
            FROM order_items
            WHERE order_item_order_id = ?
            AND order_item_type = 'ebook'
        ");
        $ebookStatement->execute([$orderId]);

        $addCollection = $pdo->prepare("
            INSERT INTO user_collection (
                collection_user_id,
                collection_product_id,
                collection_acquired_date
            )
            VALUES (?, ?, CURDATE())
            ON DUPLICATE KEY UPDATE
                collection_acquired_date =
                    collection_acquired_date
        ");

        foreach (
            $ebookStatement->fetchAll(
                PDO::FETCH_COLUMN
            ) as $productId
        ) {
            $addCollection->execute([
                $userId,
                (int) $productId,
            ]);
        }

        $userStatement = $pdo->prepare("
            SELECT
                user_tier,
                user_lifetime_spending
            FROM users
            WHERE user_id = ?
            FOR UPDATE
        ");
        $userStatement->execute([$userId]);

        $user = $userStatement->fetch(
            PDO::FETCH_ASSOC
        );

        if (!$user) {
            throw new WalletOrderPaymentException(
                'Order customer was not found.'
            );
        }

        $currentTier = $user['user_tier']
            ?? 'bronze';

        $tierStatement = $pdo->prepare("
            SELECT tier_points_multiplier
            FROM tier_config
            WHERE tier_name = ?
        ");
        $tierStatement->execute([$currentTier]);

        $multiplierHundredths = moneyDecimalToSen(
            (string) (
                $tierStatement->fetchColumn()
                ?: '1.0'
            )
        );

        $shippingFeeSen = moneyDecimalToSen(
            (string) (
                $draft[
                    'payment_draft_shipping_fee'
                ] ?? '0.00'
            )
        );

        $eligibleSpendingSen = max(
            0,
            $amountSen - $shippingFeeSen
        );
        $eligibleSpending = moneySenToDecimal(
            $eligibleSpendingSen
        );
        $pointsEarned = intdiv(
            $eligibleSpendingSen *
                $multiplierHundredths,
            10000
        );

        $updateUser = $pdo->prepare("
            UPDATE users
            SET user_points = user_points + ?,
                user_lifetime_spending =
                    user_lifetime_spending + ?
            WHERE user_id = ?
        ");
        $updateUser->execute([
            $pointsEarned,
            $eligibleSpending,
            $userId,
        ]);

        if ($pointsEarned > 0) {
            $pointsLog = $pdo->prepare("
                INSERT INTO points_log (
                    log_user_id,
                    log_points,
                    log_type,
                    log_description,
                    log_order_id
                )
                VALUES (?, ?, 'earn', ?, ?)
            ");
            $pointsLog->execute([
                $userId,
                $pointsEarned,
                'Earned from Order #' .
                    str_pad(
                        (string) $orderId,
                        4,
                        '0',
                        STR_PAD_LEFT
                    ),
                $orderId,
            ]);
        }

        $newSpendingSen = moneyDecimalToSen(
            (string) $user[
                'user_lifetime_spending'
            ]
        ) + $eligibleSpendingSen;

        $allTiers = $pdo->query("
            SELECT
                tier_name,
                tier_min_spending
            FROM tier_config
            ORDER BY tier_min_spending DESC
        ")->fetchAll(PDO::FETCH_ASSOC);

        $newTier = 'bronze';

        foreach ($allTiers as $tier) {
            if (
                $newSpendingSen >=
                moneyDecimalToSen(
                    (string) $tier[
                        'tier_min_spending'
                    ]
                )
            ) {
                $newTier = (string) $tier['tier_name'];
                break;
            }
        }

        if ($newTier !== $currentTier) {
            $updateTier = $pdo->prepare("
                UPDATE users
                SET user_tier = ?
                WHERE user_id = ?
            ");
            $updateTier->execute([
                $newTier,
                $userId,
            ]);
        }

        $completeDraft = $pdo->prepare("
            UPDATE payment_drafts
            SET payment_draft_status = 'completed',
                payment_draft_order_id = ?,
                payment_draft_paid_at = NOW(),
                payment_draft_completed_at = NOW()
            WHERE payment_draft_id = ?
            AND payment_draft_user_id = ?
            AND payment_draft_status = 'pending'
            AND payment_draft_stripe_session_id IS NULL
            AND payment_draft_order_id IS NULL
        ");
        $completeDraft->execute([
            $orderId,
            $draftId,
            $userId,
        ]);

        if ($completeDraft->rowCount() !== 1) {
            throw new WalletOrderPaymentException(
                'Unable to complete the wallet payment draft.'
            );
        }

        $pdo->commit();

        return [
            'order_id' => $orderId,
            'user_id' => $userId,
            'payment_status' => 'confirmed',
            'wallet_transaction_id' =>
                $walletTransactionId,
            'created' => true,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }
}

function sendWalletOrderConfirmationMessages(
    PDO $pdo,
    array $result
): void {
    if (empty($result['created'])) {
        return;
    }

    $orderId = (int) ($result['order_id'] ?? 0);
    $userId = (int) ($result['user_id'] ?? 0);

    if ($orderId < 1 || $userId < 1) {
        return;
    }

    $statement = $pdo->prepare("
        SELECT
            o.order_total_amount,
            o.order_has_physical,
            u.user_first_name,
            u.user_last_name,
            u.user_gmail
        FROM orders o
        INNER JOIN users u
            ON u.user_id = o.order_user_id
        WHERE o.order_id = ?
        AND o.order_user_id = ?
        AND o.order_payment_method = 'wallet'
        AND o.order_payment_status = 'confirmed'
        LIMIT 1
    ");
    $statement->execute([
        $orderId,
        $userId,
    ]);

    $order = $statement->fetch(
        PDO::FETCH_ASSOC
    );

    if (!$order) {
        return;
    }

    $orderNumber = '#' . str_pad(
        (string) $orderId,
        4,
        '0',
        STR_PAD_LEFT
    );
    $amount = moneyFormatSen(
        moneyDecimalToSen(
            (string) $order['order_total_amount']
        )
    );
    $digitalOnly =
        (int) $order['order_has_physical'] === 0;

    try {
        sendNotification(
            $pdo,
            $userId,
            'Wallet Payment Confirmed',
            $digitalOnly
                ? "Wallet payment for order $orderNumber was confirmed. Your e-book is ready to read online."
                : "Wallet payment for order $orderNumber was confirmed. Your order is now being processed.",
            'order'
        );
    } catch (Throwable $e) {
        app_error_log(
            'Wallet order notification failed: ' .
            $e->getMessage()
        );
    }

    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = MAIL_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = MAIL_USERNAME;
        $mail->Password = MAIL_PASSWORD;
        $mail->SMTPSecure = 'tls';
        $mail->Port = MAIL_PORT;
        $mail->CharSet = 'UTF-8';
        $mail->setFrom(
            MAIL_USERNAME,
            MAIL_FROM_NAME
        );
        $mail->addAddress(
            (string) $order['user_gmail'],
            trim(
                (string) $order['user_first_name'] .
                ' ' .
                (string) $order['user_last_name']
            )
        );
        $mail->Subject =
            "Wallet Payment Confirmed $orderNumber - MangaVault";
        $mail->isHTML(true);

        $safeFirstName = htmlspecialchars(
            (string) $order['user_first_name'],
            ENT_QUOTES,
            'UTF-8'
        );
        $safeOrderNumber = htmlspecialchars(
            $orderNumber,
            ENT_QUOTES,
            'UTF-8'
        );
        $safeAmount = htmlspecialchars(
            $amount,
            ENT_QUOTES,
            'UTF-8'
        );

        $mail->Body = "
            <html>
            <body style='font-family:Arial,sans-serif;background:#F5F0EB;padding:30px;'>
                <div style='max-width:600px;margin:auto;background:white;padding:30px;border-radius:16px;'>
                    <h2 style='color:#166534;'>Wallet Payment Confirmed</h2>
                    <p>Hi <strong>{$safeFirstName}</strong>,</p>
                    <p>
                        Your MangaVault Wallet payment for order
                        <strong>{$safeOrderNumber}</strong>
                        has been confirmed successfully.
                    </p>
                    <p style='font-size:18px;font-weight:bold;'>
                        Total Paid: RM {$safeAmount}
                    </p>
                </div>
            </body>
            </html>
        ";
        $mail->AltBody =
            "Wallet payment for order $orderNumber was confirmed. Total paid: RM $amount.";
        $mail->send();
    } catch (Throwable $e) {
        app_error_log(
            'Wallet order confirmation email failed: ' .
            $e->getMessage()
        );
    }
}