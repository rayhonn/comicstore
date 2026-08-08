<?php

require_once __DIR__ .
    '/identity_helper.php';

require_once __DIR__ .
    '/notifications.php';

/**
 * Grant new-member welcome rewards only when the
 * customer has an eligible active phone identity.
 *
 * This function must be called inside a database
 * transaction so the eligibility record, vouchers
 * and notifications remain consistent.
 */
function grantWelcomeRewardsIfEligible(
    PDO $pdo,
    int $userId
): bool {
    if ($userId < 1) {
        throw new RuntimeException(
            'Invalid customer account.'
        );
    }

    if (!$pdo->inTransaction()) {
        throw new RuntimeException(
            'Welcome reward processing requires an active transaction.'
        );
    }

    $customerStatement =
        $pdo->prepare("
            SELECT
                user_phone,
                user_is_active,
                user_welcome_reward_granted_at
            FROM users
            WHERE user_id = ?
            AND user_role = 'customer'
            FOR UPDATE
        ");

    $customerStatement->execute([
        $userId,
    ]);

    $customer =
        $customerStatement->fetch(
            PDO::FETCH_ASSOC
        );

    if (!$customer) {
        throw new RuntimeException(
            'Customer account was not found.'
        );
    }

    if (
        (int) $customer[
            'user_is_active'
        ] !== 1
    ) {
        return false;
    }

    if (
        !empty(
            $customer[
                'user_welcome_reward_granted_at'
            ]
        )
    ) {
        return false;
    }

    $phone =
        normalizeIdentityPhone(
            (string) (
                $customer[
                    'user_phone'
                ] ?? ''
            )
        );

    if ($phone === '') {
        return false;
    }

    $phoneFingerprint =
        customerIdentityFingerprint(
            'phone',
            $phone
        );

    $identityStatement =
        $pdo->prepare("
            SELECT identity_history_id
            FROM customer_identity_history
            WHERE identity_user_id = ?
            AND identity_type = 'phone'
            AND identity_fingerprint = ?
            AND identity_status = 'active'
            AND identity_claim_key = 1
            LIMIT 1
            FOR UPDATE
        ");

    $identityStatement->execute([
        $userId,
        $phoneFingerprint,
    ]);

    if (
        $identityStatement
            ->fetchColumn() === false
    ) {
        throw new IdentityProtectionException(
            'This phone number is not eligible for new-member welcome rewards.'
        );
    }

    $voucherStatement =
        $pdo->prepare("
            SELECT
                voucher_id,
                voucher_code
            FROM vouchers
            WHERE voucher_code IN (
                'WELCOME10',
                'NEWUSER20',
                'NEWMEMBER50'
            )
            AND voucher_is_active = 1
        ");

    $voucherStatement->execute();

    $welcomeVouchers =
        $voucherStatement->fetchAll(
            PDO::FETCH_ASSOC
        );

    if ($welcomeVouchers === []) {
        return false;
    }

    $expiresAt = date(
        'Y-m-d H:i:s',
        strtotime('+1 month')
    );

    $claimVoucher =
        $pdo->prepare("
            INSERT IGNORE INTO user_vouchers (
                uv_user_id,
                uv_voucher_id,
                uv_expires_at
            )
            VALUES (?, ?, ?)
        ");

    $notificationMap = [
        'WELCOME10' => [
            '🎟️ Welcome Gift — 10% OFF!',
            'Use code WELCOME10 for 10% off your first order (min RM20, max RM15 discount). Happy shopping!',
        ],

        'NEWUSER20' => [
            '🎁 New Member Special — 20% OFF!',
            'Exclusive for new members! Use code NEWUSER20 for 20% off (min RM50, max RM20 discount). One-time use only!',
        ],

        'NEWMEMBER50' => [
            '🎊 New Member Gift — 50% OFF!',
            'Welcome gift! Use code NEWMEMBER50 for 50% off your first order (min RM30, max RM25 discount). Valid for 1 month only!',
        ],
    ];

    foreach (
        $welcomeVouchers as $voucher
    ) {
        $voucherId =
            (int) $voucher[
                'voucher_id'
            ];

        $voucherCode =
            (string) $voucher[
                'voucher_code'
            ];

        $claimVoucher->execute([
            $userId,
            $voucherId,
            $expiresAt,
        ]);

        if (
            $claimVoucher->rowCount() === 1 &&
            isset(
                $notificationMap[
                    $voucherCode
                ]
            )
        ) {
            sendNotification(
                $pdo,
                $userId,
                $notificationMap[
                    $voucherCode
                ][0],
                $notificationMap[
                    $voucherCode
                ][1],
                'promo'
            );
        }
    }

    $markGranted =
        $pdo->prepare("
            UPDATE users
            SET
                user_welcome_reward_granted_at =
                    NOW()
            WHERE user_id = ?
            AND user_role = 'customer'
            AND user_welcome_reward_granted_at
                IS NULL
        ");

    $markGranted->execute([
        $userId,
    ]);

    if (
        $markGranted->rowCount() !== 1
    ) {
        throw new RuntimeException(
            'Unable to record welcome reward eligibility.'
        );
    }

    return true;
}