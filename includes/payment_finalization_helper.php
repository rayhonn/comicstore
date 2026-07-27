<?php

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/stripe_config.php';
require_once __DIR__ . '/payment_draft_helper.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/mail_config.php';

final class PaymentFinalizationException extends RuntimeException
{
}

/**
 * Fulfil a paid Stripe Checkout Session exactly once.
 */
function fulfillStripeCheckoutSession(
    PDO $pdo,
    string $sessionId,
    ?int $expectedUserId = null
): array {
    $sessionId = trim($sessionId);

    if (
        strlen($sessionId) > 255 ||
        !preg_match(
            '/\Acs_[A-Za-z0-9_]+\z/',
            $sessionId
        )
    ) {
        throw new PaymentFinalizationException(
            'The Stripe Checkout Session ID is invalid.'
        );
    }

    \Stripe\Stripe::setApiKey(
        STRIPE_SECRET_KEY
    );

    $stripeSession =
        \Stripe\Checkout\Session::retrieve(
            $sessionId
        );

    $paymentStatus = (string) (
        $stripeSession->payment_status
        ?? ''
    );

    $sessionStatus = (string) (
        $stripeSession->status
        ?? ''
    );

    if (
        $paymentStatus !== 'paid' ||
        $sessionStatus !== 'complete'
    ) {
        throw new PaymentFinalizationException(
            'The Stripe Checkout Session is not paid.'
        );
    }

    $metadataDraftId = filter_var(
        (string) (
            $stripeSession
                ->metadata
                ->payment_draft_id
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
        $metadataDraftId === false ||
        $metadataUserId === false
    ) {
        throw new PaymentFinalizationException(
            'Stripe Checkout Session metadata is invalid.'
        );
    }

    $draftId = (int) $metadataDraftId;
    $userId = (int) $metadataUserId;

    $clientReferenceId = (string) (
        $stripeSession->client_reference_id
        ?? ''
    );

    if (
        $clientReferenceId !==
            (string) $userId ||
        (
            $expectedUserId !== null &&
            $userId !== $expectedUserId
        )
    ) {
        throw new PaymentFinalizationException(
            'Stripe Checkout Session ownership mismatch.'
        );
    }

    $draft = loadPaymentDraft(
        $pdo,
        $draftId,
        $userId
    );

    if ($draft === null) {
        throw new PaymentFinalizationException(
            'The payment draft was not found.'
        );
    }

    $storedSessionId = trim(
        (string) (
            $draft['stripe_session_id']
            ?? ''
        )
    );

    if (
        $storedSessionId !== '' &&
        !hash_equals(
            $storedSessionId,
            $sessionId
        )
    ) {
        throw new PaymentFinalizationException(
            'The payment draft is linked to a different Stripe Session.'
        );
    }

    $amountTotal = (int) (
        $stripeSession->amount_total
        ?? -1
    );

    $currency = strtolower(
        (string) (
            $stripeSession->currency
            ?? ''
        )
    );

    $expectedAmount = (int) round(
        (float) $draft['total'] * 100
    );

    if (
        $expectedAmount <= 0 ||
        $amountTotal !== $expectedAmount ||
        $currency !==
            strtolower(
                (string) $draft['currency']
            )
    ) {
        throw new PaymentFinalizationException(
            'Stripe Checkout Session payment details mismatch.'
        );
    }

    $paymentIntentId = null;

    if (
        is_string(
            $stripeSession->payment_intent
            ?? null
        )
    ) {
        $paymentIntentId =
            $stripeSession->payment_intent;
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
        $paymentIntentId =
            $stripeSession
                ->payment_intent
                ->id;
    }

    $result = finalizePaidPaymentDraft(
        $pdo,
        $draftId,
        $userId,
        $sessionId,
        $paymentIntentId,
        $amountTotal,
        $currency
    );

    if ($result['created']) {
        sendPaymentConfirmationMessages(
            $pdo,
            $result
        );
    }

    return $result;
}

/**
 * Create the internal order and apply all checkout side effects.
 */
function finalizePaidPaymentDraft(
    PDO $pdo,
    int $draftId,
    int $userId,
    string $sessionId,
    ?string $paymentIntentId,
    int $amountTotal,
    string $currency
): array {
    $paymentIntentId = trim(
        (string) $paymentIntentId
    );

    if (
        $draftId < 1 ||
        $userId < 1 ||
        $amountTotal < 1 ||
        !preg_match(
            '/\Acs_[A-Za-z0-9_]+\z/',
            $sessionId
        ) ||
        !preg_match(
            '/\A[a-z]{3}\z/',
            $currency
        ) ||
        (
            $paymentIntentId !== '' &&
            !preg_match(
                '/\Api_[A-Za-z0-9_]+\z/',
                $paymentIntentId
            )
        )
    ) {
        throw new PaymentFinalizationException(
            'The paid checkout details are invalid.'
        );
    }

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
                payment_draft_stripe_payment_intent_id,
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
            throw new PaymentFinalizationException(
                'The payment draft was not found.'
            );
        }

        $storedSessionId = trim(
            (string) (
                $draft[
                    'payment_draft_stripe_session_id'
                ] ?? ''
            )
        );

        if (
            $storedSessionId !== '' &&
            !hash_equals(
                $storedSessionId,
                $sessionId
            )
        ) {
            throw new PaymentFinalizationException(
                'The payment draft Stripe Session does not match.'
            );
        }

        $storedPaymentIntentId = trim(
            (string) (
                $draft[
                    'payment_draft_stripe_payment_intent_id'
                ] ?? ''
            )
        );

        if (
            $storedPaymentIntentId !== '' &&
            $paymentIntentId !== '' &&
            !hash_equals(
                $storedPaymentIntentId,
                $paymentIntentId
            )
        ) {
            throw new PaymentFinalizationException(
                'The Stripe Payment Intent does not match.'
            );
        }

        $draftAmount = (int) round(
            (float) $draft[
                'payment_draft_total_amount'
            ] * 100
        );

        $draftCurrency = strtolower(
            (string) $draft[
                'payment_draft_currency'
            ]
        );

        if (
            $draftAmount !== $amountTotal ||
            $draftCurrency !== $currency
        ) {
            throw new PaymentFinalizationException(
                'The payment draft amount or currency does not match Stripe.'
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
                    order_payment_status,
                    order_confirm_token
                FROM orders
                WHERE order_id = ?
                AND order_user_id = ?
                AND order_stripe_session_id = ?
                LIMIT 1
            ");

            $existingOrder->execute([
                $linkedOrderId,
                $userId,
                $sessionId,
            ]);

            $order = $existingOrder->fetch(
                PDO::FETCH_ASSOC
            );

            if (!$order) {
                throw new PaymentFinalizationException(
                    'The linked order could not be verified.'
                );
            }

            $pdo->commit();

            return [
                'order_id' =>
                    (int) $order['order_id'],
                'user_id' =>
                    (int) $order['order_user_id'],
                'payment_status' =>
                    $order['order_payment_status'],
                'confirm_token' =>
                    $order['order_confirm_token'],
                'stripe_session_id' =>
                    $sessionId,
                'created' => false,
            ];
        }

        $existingOrder = $pdo->prepare("
            SELECT
                order_id,
                order_user_id,
                order_payment_status,
                order_confirm_token
            FROM orders
            WHERE order_stripe_session_id = ?
            AND order_user_id = ?
            LIMIT 1
            FOR UPDATE
        ");

        $existingOrder->execute([
            $sessionId,
            $userId,
        ]);

        $order = $existingOrder->fetch(
            PDO::FETCH_ASSOC
        );

        if ($order) {
            $attachExistingOrder = $pdo->prepare("
                UPDATE payment_drafts
                SET payment_draft_status =
                        'completed',
                    payment_draft_stripe_session_id = ?,
                    payment_draft_stripe_payment_intent_id =
                        COALESCE(
                            payment_draft_stripe_payment_intent_id,
                            ?
                        ),
                    payment_draft_order_id = ?,
                    payment_draft_paid_at =
                        COALESCE(
                            payment_draft_paid_at,
                            NOW()
                        ),
                    payment_draft_completed_at =
                        COALESCE(
                            payment_draft_completed_at,
                            NOW()
                        )
                WHERE payment_draft_id = ?
                AND payment_draft_user_id = ?
            ");

            $attachExistingOrder->execute([
                $sessionId,
                $paymentIntentId !== ''
                    ? $paymentIntentId
                    : null,
                $order['order_id'],
                $draftId,
                $userId,
            ]);

            $pdo->commit();

            return [
                'order_id' =>
                    (int) $order['order_id'],
                'user_id' =>
                    (int) $order['order_user_id'],
                'payment_status' =>
                    $order['order_payment_status'],
                'confirm_token' =>
                    $order['order_confirm_token'],
                'stripe_session_id' =>
                    $sessionId,
                'created' => false,
            ];
        }

        if (
            !in_array(
                $draft['payment_draft_status'],
                [
                    'pending',
                    'checkout_open',
                    'paid',
                ],
                true
            )
        ) {
            throw new PaymentFinalizationException(
                'The payment draft cannot be finalized.'
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
            throw new PaymentFinalizationException(
                'The payment draft contains no items.'
            );
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

            if ($addressTaman === '') {
                $addressTaman = null;
            }

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
                throw new PaymentFinalizationException(
                    'The payment draft shipping address is incomplete.'
                );
            }
        }

        $confirmToken = bin2hex(
            random_bytes(32)
        );

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
                order_payment_status,
                order_confirm_token,
                order_confirm_expires_at,
                order_voucher_code,
                order_discount_amount
            )
            VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?, ?, ?, ?,
                'pending_confirmation',
                ?,
                DATE_ADD(NOW(), INTERVAL 5 MINUTE),
                ?, ?
            )
        ");

        $orderInsert->execute([
            $userId,
            $draft[
                'payment_draft_total_amount'
            ],
            $hasPhysical ? 1 : 0,
            $draft[
                'payment_draft_address_id'
            ],
            $addressRecipientName,
            $addressTaman,
            $addressStreet,
            $addressCity,
            $addressState,
            $addressPostalCode,
            $addressCountry,
            $addressPhone,
            $draft[
                'payment_draft_shipping_method'
            ],
            $draft[
                'payment_draft_shipping_fee'
            ],
            $draft[
                'payment_draft_courier'
            ],
            $draft[
                'payment_draft_delivery_zone'
            ],
            'stripe_card',
            $sessionId,
            $confirmToken,
            $draft[
                'payment_draft_voucher_code'
            ],
            $draft[
                'payment_draft_discount_amount'
            ],
        ]);

        $orderId = (int) $pdo
            ->lastInsertId();

        if ($orderId < 1) {
            throw new RuntimeException(
                'Unable to identify the new order.'
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
            VALUES (
                ?, ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?
            )
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

            $productTitle = trim(
                (string) (
                    $item[
                        'payment_draft_item_product_title'
                    ] ?? ''
                )
            );

            if (
                $productId < 1 ||
                $quantity < 1 ||
                $productTitle === '' ||
                !in_array(
                    $itemType,
                    [
                        'physical',
                        'ebook',
                    ],
                    true
                )
            ) {
                throw new PaymentFinalizationException(
                    'The payment draft contains an invalid item.'
                );
            }

            $itemInsert->execute([
                $orderId,
                $productId,
                $productTitle,
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
                    throw new PaymentFinalizationException(
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
                $draft[
                    'payment_draft_discount_amount'
                ],
            ]);

            $voucherUpdate = $pdo->prepare("
                UPDATE vouchers
                SET voucher_used_count =
                    voucher_used_count + 1
                WHERE voucher_id = ?
                AND (
                    voucher_usage_limit IS NULL
                    OR voucher_used_count <
                        voucher_usage_limit
                )
            ");

            $voucherUpdate->execute([
                $voucherId,
            ]);

            if ($voucherUpdate->rowCount() !== 1) {
                throw new PaymentFinalizationException(
                    'Voucher usage limit has been reached.'
                );
            }

            $userVoucherUpdate = $pdo->prepare("
                UPDATE user_vouchers
                SET uv_is_used = 0,
                    uv_status = 'pending',
                    uv_pending_at = NOW(),
                    uv_used_at = NULL
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
        }

        $completeDraft = $pdo->prepare("
            UPDATE payment_drafts
            SET payment_draft_status =
                    'completed',
                payment_draft_stripe_session_id = ?,
                payment_draft_stripe_payment_intent_id =
                    COALESCE(
                        payment_draft_stripe_payment_intent_id,
                        ?
                    ),
                payment_draft_order_id = ?,
                payment_draft_paid_at =
                    COALESCE(
                        payment_draft_paid_at,
                        NOW()
                    ),
                payment_draft_completed_at = NOW()
            WHERE payment_draft_id = ?
            AND payment_draft_user_id = ?
        ");

        $completeDraft->execute([
            $sessionId,
            $paymentIntentId !== ''
                ? $paymentIntentId
                : null,
            $orderId,
            $draftId,
            $userId,
        ]);

        if ($completeDraft->rowCount() !== 1) {
            throw new RuntimeException(
                'Unable to complete the payment draft.'
            );
        }

        $pdo->commit();

        return [
            'order_id' => $orderId,
            'user_id' => $userId,
            'payment_status' =>
                'pending_confirmation',
            'confirm_token' =>
                $confirmToken,
            'stripe_session_id' =>
                $sessionId,
            'created' => true,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }
}

/**
 * Send the confirmation email and in-app notification.
 */
function sendPaymentConfirmationMessages(
    PDO $pdo,
    array $result
): void {
    $orderId = (int) (
        $result['order_id'] ?? 0
    );

    $userId = (int) (
        $result['user_id'] ?? 0
    );

    $confirmToken = trim(
        (string) (
            $result['confirm_token']
            ?? ''
        )
    );

    if (
        $orderId < 1 ||
        $userId < 1 ||
        !preg_match(
            '/\A[a-f0-9]{64}\z/',
            $confirmToken
        )
    ) {
        app_error_log(
            'Payment confirmation message data is invalid.'
        );

        return;
    }

    $userStatement = $pdo->prepare("
        SELECT
            user_first_name,
            user_last_name,
            user_gmail
        FROM users
        WHERE user_id = ?
        LIMIT 1
    ");

    $userStatement->execute([$userId]);

    $user = $userStatement->fetch(
        PDO::FETCH_ASSOC
    );

    if (!$user) {
        app_error_log(
            'Payment confirmation customer was not found.'
        );

        return;
    }

    $orderNumber =
        '#' . str_pad(
            (string) $orderId,
            4,
            '0',
            STR_PAD_LEFT
        );

    $confirmUrl =
        rtrim(APP_URL, '/') .
        '/customer/payment_verify.php?token=' .
        urlencode($confirmToken);

    $safeFirstName = htmlspecialchars(
        (string) $user['user_first_name'],
        ENT_QUOTES,
        'UTF-8'
    );

    $safeConfirmUrl = htmlspecialchars(
        $confirmUrl,
        ENT_QUOTES,
        'UTF-8'
    );

    $emailBody = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
    </head>
    <body style='margin:0;padding:0;background:#F5F0EB;font-family:-apple-system,BlinkMacSystemFont,sans-serif;'>
        <div style='max-width:600px;margin:30px auto;background:white;border-radius:16px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,0.08);'>
            <div style='background:linear-gradient(135deg,#1e2d4a,#2c3e6b);padding:32px;text-align:center;'>
                <h1 style='color:white;font-size:24px;font-weight:900;margin:0 0 4px;'>
                    Manga<span style='color:#ef4444;'>Vault</span>
                </h1>
                <p style='color:rgba(255,255,255,0.6);font-size:13px;margin:0;'>
                    Confirm Your Payment
                </p>
            </div>
            <div style='padding:32px;'>
                <p style='color:#374151;font-size:15px;'>
                    Hi <strong>{$safeFirstName}</strong>,
                </p>
                <p style='color:#374151;font-size:15px;line-height:1.6;'>
                    Stripe payment for order
                    <strong>{$orderNumber}</strong>
                    was successful. Please confirm the payment
                    within 5 minutes to complete your order.
                </p>
                <div style='background:#fff7ed;border:1px solid #fed7aa;border-radius:12px;padding:16px;margin:24px 0;'>
                    <p style='color:#9a3412;font-size:13px;margin:0;'>
                        This confirmation link expires in 5 minutes.
                    </p>
                </div>
                <div style='text-align:center;'>
                    <a href='{$safeConfirmUrl}'
                       style='display:inline-block;background:#C0392B;color:white;font-weight:700;font-size:14px;padding:12px 28px;border-radius:12px;text-decoration:none;'>
                        Confirm Payment
                    </a>
                </div>
            </div>
            <div style='background:#f9fafb;padding:20px 32px;text-align:center;border-top:1px solid #f3f4f6;'>
                <p style='color:#9ca3af;font-size:12px;margin:0;'>
                    MangaVault - Your One-Stop Manga Store
                </p>
            </div>
        </div>
    </body>
    </html>";

    try {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(
            true
        );

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
            $user['user_gmail'],
            trim(
                $user['user_first_name'] .
                ' ' .
                $user['user_last_name']
            )
        );

        $mail->Subject =
            "Confirm Payment {$orderNumber} - MangaVault";

        $mail->isHTML(true);
        $mail->Body = $emailBody;

        $mail->AltBody =
            "Stripe payment for order {$orderNumber} was successful. " .
            'Confirm your payment within 5 minutes: ' .
            $confirmUrl;

        $mail->send();
    } catch (Throwable $e) {
        app_error_log(
            'Payment confirmation email failed: ' .
            $e->getMessage()
        );
    }

    try {
        sendNotification(
            $pdo,
            $userId,
            'Payment Confirmation Required',
            "Stripe payment for order {$orderNumber} was successful. Confirm it from your email within 5 minutes.",
            'order'
        );
    } catch (Throwable $e) {
        app_error_log(
            'Payment confirmation notification failed: ' .
            $e->getMessage()
        );
    }
}
