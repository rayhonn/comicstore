<?php

require_once __DIR__ .
    '/config.php';

final class IdentityProtectionException
    extends RuntimeException
{
}

/**
 * Return the private key used to create deterministic
 * customer identity fingerprints.
 */
function identityHmacKey(): string
{
    if (
        !defined('IDENTITY_HMAC_KEY')
    ) {
        throw new IdentityProtectionException(
            'Identity protection is temporarily unavailable.'
        );
    }

    $key = trim(
        (string) IDENTITY_HMAC_KEY
    );

    if (strlen($key) < 32) {
        throw new IdentityProtectionException(
            'Identity protection is temporarily unavailable.'
        );
    }

    return $key;
}

/**
 * Normalize an email address before fingerprinting.
 */
function normalizeIdentityEmail(
    string $email
): string {
    $email = strtolower(
        trim($email)
    );

    if (
        !filter_var(
            $email,
            FILTER_VALIDATE_EMAIL
        )
    ) {
        throw new IdentityProtectionException(
            'Please enter a valid email address.'
        );
    }

    return $email;
}

/**
 * Normalize a Malaysian mobile number before
 * fingerprinting.
 */
function normalizeIdentityPhone(
    string $phone
): string {
    $phone = trim($phone);

    if ($phone === '') {
        return '';
    }

    $phone = preg_replace(
        '/[\s\-\(\)]+/',
        '',
        $phone
    );

    if (!is_string($phone)) {
        throw new IdentityProtectionException(
            'Please enter a valid Malaysian phone number.'
        );
    }

    if (
        str_starts_with(
            $phone,
            '+60'
        )
    ) {
        $phone =
            '0' .
            substr(
                $phone,
                3
            );
    } elseif (
        str_starts_with(
            $phone,
            '60'
        )
    ) {
        $phone =
            '0' .
            substr(
                $phone,
                2
            );
    }

    if (
        !preg_match(
            '/^01[0-9]{8,9}$/',
            $phone
        )
    ) {
        throw new IdentityProtectionException(
            'Please enter a valid Malaysian phone number.'
        );
    }

    return $phone;
}

/**
 * Generate a deterministic fingerprint without
 * storing another plaintext copy of the identifier.
 */
function customerIdentityFingerprint(
    string $type,
    string $value
): string {
    if (
        !in_array(
            $type,
            [
                'email',
                'phone',
            ],
            true
        )
    ) {
        throw new IdentityProtectionException(
            'Invalid customer identity type.'
        );
    }

    if ($type === 'email') {
        $normalized =
            normalizeIdentityEmail(
                $value
            );
    } else {
        $normalized =
            normalizeIdentityPhone(
                $value
            );

        if ($normalized === '') {
            throw new IdentityProtectionException(
                'Phone number is empty.'
            );
        }
    }

    return hash_hmac(
        'sha256',
        $type . ':' . $normalized,
        identityHmacKey()
    );
}

/**
 * Ensure an identifier is not owned or historically
 * claimed by another MangaVault customer.
 */
function assertCustomerIdentityAvailable(
    PDO $pdo,
    string $type,
    string $value,
    ?int $excludeUserId = null
): void {
    if (
        $type === 'phone' &&
        trim($value) === ''
    ) {
        return;
    }

    if ($type === 'email') {
        $normalizedValue =
            normalizeIdentityEmail(
                $value
            );

        $existingUserSql = "
            SELECT user_id
            FROM users
            WHERE user_role = 'customer'
            AND LOWER(
                TRIM(user_gmail)
            ) = ?
        ";
    } else {
        $normalizedValue =
            normalizeIdentityPhone(
                $value
            );

        $existingUserSql = "
            SELECT user_id
            FROM users
            WHERE user_role = 'customer'
            AND user_phone = ?
        ";
    }

    $params = [
        $normalizedValue,
    ];

    if (
        $excludeUserId !== null
    ) {
        $existingUserSql .= "
            AND user_id != ?
        ";

        $params[] =
            $excludeUserId;
    }

    $existingUserSql .= "
        LIMIT 1
    ";

    $existingUser =
        $pdo->prepare(
            $existingUserSql
        );

    $existingUser->execute(
        $params
    );

    if (
        $existingUser
            ->fetchColumn() !== false
    ) {
        throw new IdentityProtectionException(
            'This email address or phone number is associated with an existing or previous MangaVault account. Please contact an administrator for assistance.'
        );
    }

    $fingerprint =
        customerIdentityFingerprint(
            $type,
            $normalizedValue
        );

    $historySql = "
        SELECT identity_user_id
        FROM customer_identity_history
        WHERE identity_type = ?
        AND identity_fingerprint = ?
        AND identity_claim_key = 1
    ";

    $historyParams = [
        $type,
        $fingerprint,
    ];

    if (
        $excludeUserId !== null
    ) {
        $historySql .= "
            AND identity_user_id != ?
        ";

        $historyParams[] =
            $excludeUserId;
    }

    $historySql .= "
        LIMIT 1
    ";

    $history =
        $pdo->prepare(
            $historySql
        );

    $history->execute(
        $historyParams
    );

    if (
        $history->fetchColumn() !==
        false
    ) {
        throw new IdentityProtectionException(
            'This email address or phone number is associated with an existing or previous MangaVault account. Please contact an administrator for assistance.'
        );
    }
}

/**
 * Claim an identifier for a customer.
 */
function claimCustomerIdentity(
    PDO $pdo,
    int $userId,
    string $type,
    string $value,
    string $source
): void {
    if (
        $userId < 1 ||
        (
            $type === 'phone' &&
            trim($value) === ''
        )
    ) {
        return;
    }

    $allowedSources = [
        'registration',
        'profile_update',
        'account_deletion',
        'admin_restore',
        'admin_reassignment',
        'legacy_sync',
    ];

    if (
        !in_array(
            $source,
            $allowedSources,
            true
        )
    ) {
        throw new IdentityProtectionException(
            'Invalid customer identity source.'
        );
    }

    $fingerprint =
        customerIdentityFingerprint(
            $type,
            $value
        );

    $existing =
        $pdo->prepare("
            SELECT
                identity_history_id,
                identity_user_id
            FROM customer_identity_history
            WHERE identity_type = ?
            AND identity_fingerprint = ?
            AND identity_claim_key = 1
            LIMIT 1
            FOR UPDATE
        ");

    $existing->execute([
        $type,
        $fingerprint,
    ]);

    $claim =
        $existing->fetch(
            PDO::FETCH_ASSOC
        );

    if ($claim) {
        if (
            (int) $claim[
                'identity_user_id'
            ] !== $userId
        ) {
            throw new IdentityProtectionException(
                'This identity is already associated with another MangaVault account.'
            );
        }

        $reactivate =
            $pdo->prepare("
                UPDATE
                    customer_identity_history
                SET
                    identity_status =
                        'active',
                    identity_updated_at =
                        NOW()
                WHERE
                    identity_history_id = ?
            ");

        $reactivate->execute([
            (int) $claim[
                'identity_history_id'
            ],
        ]);

        return;
    }

    try {
        $insert =
            $pdo->prepare("
                INSERT INTO
                    customer_identity_history (
                        identity_user_id,
                        identity_type,
                        identity_fingerprint,
                        identity_status,
                        identity_claim_key,
                        identity_source
                    )
                VALUES (
                    ?,
                    ?,
                    ?,
                    'active',
                    1,
                    ?
                )
            ");

        $insert->execute([
            $userId,
            $type,
            $fingerprint,
            $source,
        ]);
    } catch (PDOException $e) {
        if (
            $e->getCode() ===
            '23000'
        ) {
            throw new IdentityProtectionException(
                'This identity is already associated with another MangaVault account.'
            );
        }

        throw $e;
    }
}

/**
 * Preserve a previous identifier after the customer
 * changes it.
 */
function markCustomerIdentityHistorical(
    PDO $pdo,
    int $userId,
    string $type,
    string $value,
    string $source =
        'profile_update'
): void {
    if (
        $userId < 1 ||
        (
            $type === 'phone' &&
            trim($value) === ''
        )
    ) {
        return;
    }

    claimCustomerIdentity(
        $pdo,
        $userId,
        $type,
        $value,
        $source
    );

    $fingerprint =
        customerIdentityFingerprint(
            $type,
            $value
        );

    $update =
        $pdo->prepare("
            UPDATE
                customer_identity_history
            SET
                identity_status =
                    'historical',
                identity_updated_at =
                    NOW()
            WHERE
                identity_user_id = ?
            AND
                identity_type = ?
            AND
                identity_fingerprint = ?
            AND
                identity_claim_key = 1
        ");

    $update->execute([
        $userId,
        $type,
        $fingerprint,
    ]);
}

/**
 * Convert all currently claimed identifiers belonging
 * to a closed account into retained historical claims.
 */
function markCustomerIdentitiesHistorical(
    PDO $pdo,
    int $userId
): void {
    if ($userId < 1) {
        return;
    }

    $update =
        $pdo->prepare("
            UPDATE
                customer_identity_history
            SET
                identity_status =
                    'historical',
                identity_updated_at =
                    NOW()
            WHERE
                identity_user_id = ?
            AND
                identity_claim_key = 1
        ");

    $update->execute([
        $userId,
    ]);
}