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
 * Return a customer-facing identity conflict message.
 */
function customerIdentityConflictMessage(
    string $type
): string {
    if ($type === 'phone') {
        return
            'This phone number is associated with an existing or previous MangaVault account. ' .
            'If this number was reassigned to you by your mobile provider, ' .
            'please contact a Super Admin for an ownership review.';
    }

    return
        'This email address is associated with an existing or previous MangaVault account. ' .
        'Please contact an administrator for assistance.';
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
            customerIdentityConflictMessage(
                $type
            )
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
            customerIdentityConflictMessage(
                $type
            )
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
                customerIdentityConflictMessage(
                    $type
                )
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
                customerIdentityConflictMessage(
                    $type
                )
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

/**
 * Find the current or most recent MangaVault
 * ownership record for a phone number.
 */
function findCustomerPhoneIdentity(
    PDO $pdo,
    string $phone
): ?array {
    $phone =
        normalizeIdentityPhone(
            $phone
        );

    if ($phone === '') {
        throw new IdentityProtectionException(
            'Please enter a phone number.'
        );
    }

    $fingerprint =
        customerIdentityFingerprint(
            'phone',
            $phone
        );

    $statement =
        $pdo->prepare("
            SELECT
                cih.identity_history_id,
                cih.identity_user_id,
                cih.identity_status,
                cih.identity_claim_key,
                cih.identity_source,
                cih.identity_created_at,
                cih.identity_updated_at,
                cih.identity_released_at,
                cih.identity_released_by,
                cih.identity_release_reason,

                u.user_name,
                u.user_first_name,
                u.user_last_name,
                u.user_gmail,
                u.user_phone,
                u.user_is_active,
                u.user_deleted_at

            FROM customer_identity_history cih

            INNER JOIN users u
                ON u.user_id =
                    cih.identity_user_id

            WHERE
                cih.identity_type =
                    'phone'
            AND
                cih.identity_fingerprint = ?

            ORDER BY
                CASE
                    WHEN
                        cih.identity_claim_key = 1
                    THEN 0
                    ELSE 1
                END,
                cih.identity_history_id DESC

            LIMIT 1
        ");

    $statement->execute([
        $fingerprint,
    ]);

    $result =
        $statement->fetch(
            PDO::FETCH_ASSOC
        );

    return $result ?: null;
}

/**
 * Release a historical phone identity after a
 * Super Admin verifies that the mobile number has
 * been reassigned to a new owner.
 */
function releaseHistoricalPhoneIdentity(
    PDO $pdo,
    int $identityHistoryId,
    string $phone,
    int $adminId,
    string $reason
): array {
    if (
        $identityHistoryId < 1 ||
        $adminId < 1
    ) {
        throw new IdentityProtectionException(
            'Invalid phone ownership review.'
        );
    }

    $phone =
        normalizeIdentityPhone(
            $phone
        );

    if ($phone === '') {
        throw new IdentityProtectionException(
            'Please enter a valid phone number.'
        );
    }

    $reason = trim($reason);

    $reasonLength =
        function_exists('mb_strlen')
            ? mb_strlen(
                $reason,
                'UTF-8'
            )
            : strlen($reason);

    if ($reason === '') {
        throw new IdentityProtectionException(
            'A release reason is required.'
        );
    }

    if ($reasonLength > 500) {
        throw new IdentityProtectionException(
            'Release reason cannot exceed 500 characters.'
        );
    }

    $fingerprint =
        customerIdentityFingerprint(
            'phone',
            $phone
        );

    try {
        $pdo->beginTransaction();

        $identityLock =
            $pdo->prepare("
                SELECT
                    cih.identity_history_id,
                    cih.identity_user_id,
                    cih.identity_type,
                    cih.identity_fingerprint,
                    cih.identity_status,
                    cih.identity_claim_key,

                    u.user_name,
                    u.user_first_name,
                    u.user_last_name,
                    u.user_is_active,
                    u.user_deleted_at

                FROM customer_identity_history cih

                INNER JOIN users u
                    ON u.user_id =
                        cih.identity_user_id

                WHERE
                    cih.identity_history_id = ?

                FOR UPDATE
            ");

        $identityLock->execute([
            $identityHistoryId,
        ]);

        $identity =
            $identityLock->fetch(
                PDO::FETCH_ASSOC
            );

        if (!$identity) {
            throw new IdentityProtectionException(
                'Phone identity record was not found.'
            );
        }

        if (
            $identity[
                'identity_type'
            ] !== 'phone'
        ) {
            throw new IdentityProtectionException(
                'Only phone identity records can be released through this review.'
            );
        }

        if (
            !hash_equals(
                (string) $identity[
                    'identity_fingerprint'
                ],
                $fingerprint
            )
        ) {
            throw new IdentityProtectionException(
                'The phone number does not match this identity record.'
            );
        }

        if (
            $identity[
                'identity_status'
            ] !== 'historical' ||
            (int) $identity[
                'identity_claim_key'
            ] !== 1
        ) {
            throw new IdentityProtectionException(
                'Only an unreleased historical phone identity can be reassigned.'
            );
        }

        /*
         * Lock current customer phone values before
         * releasing the historical claim.
         */
        $currentPhones =
            $pdo->query("
                SELECT
                    user_id,
                    user_phone,
                    user_is_active,
                    user_deleted_at
                FROM users
                WHERE user_role = 'customer'
                AND user_phone IS NOT NULL
                AND TRIM(user_phone) != ''
                FOR UPDATE
            ")->fetchAll(
                PDO::FETCH_ASSOC
            );

        $historicalOwnerId =
            (int) $identity[
                'identity_user_id'
            ];

        foreach (
            $currentPhones as
            $currentCustomer
        ) {
            try {
                $currentPhone =
                    normalizeIdentityPhone(
                        (string) $currentCustomer[
                            'user_phone'
                        ]
                    );
            } catch (
                IdentityProtectionException $e
            ) {
                continue;
            }

            if (
                $currentPhone !==
                $phone
            ) {
                continue;
            }

            $currentUserId =
                (int) $currentCustomer[
                    'user_id'
                ];

            /*
             * A closed account can release its old
             * plaintext phone value because the
             * historical fingerprint remains for
             * audit purposes.
             */
            if (
                $currentUserId ===
                    $historicalOwnerId &&
                !empty(
                    $currentCustomer[
                        'user_deleted_at'
                    ]
                ) &&
                (int) $currentCustomer[
                    'user_is_active'
                ] !== 1
            ) {
                $clearOldPhone =
                    $pdo->prepare("
                        UPDATE users
                        SET user_phone = NULL
                        WHERE user_id = ?
                        AND user_role = 'customer'
                    ");

                $clearOldPhone->execute([
                    $currentUserId,
                ]);

                continue;
            }

            /*
             * The number is still a current phone
             * on an active or temporarily inactive
             * MangaVault account, so it cannot be
             * reassigned here.
             */
            throw new IdentityProtectionException(
                'This phone number is still assigned as the current phone number of a MangaVault customer account and cannot be released.'
            );
        }

        $release =
            $pdo->prepare("
                UPDATE
                    customer_identity_history
                SET
                    identity_status =
                        'released',
                    identity_claim_key =
                        NULL,
                    identity_released_at =
                        NOW(),
                    identity_released_by =
                        ?,
                    identity_release_reason =
                        ?,
                    identity_updated_at =
                        NOW()
                WHERE
                    identity_history_id = ?
                AND
                    identity_type =
                        'phone'
                AND
                    identity_status =
                        'historical'
                AND
                    identity_claim_key = 1
            ");

        $release->execute([
            $adminId,
            $reason,
            $identityHistoryId,
        ]);

        if (
            $release->rowCount() !== 1
        ) {
            throw new IdentityProtectionException(
                'Unable to release the historical phone identity.'
            );
        }

        $log =
            $pdo->prepare("
                INSERT INTO admin_logs (
                    log_admin_id,
                    log_action,
                    log_target_type,
                    log_target_id,
                    log_details
                )
                VALUES (
                    ?,
                    'release_historical_phone_identity',
                    'user',
                    ?,
                    ?
                )
            ");

        $log->execute([
            $adminId,
            $historicalOwnerId,
            'Historical phone identity claim #' .
            $identityHistoryId .
            ' released after Super Admin ownership review. Reason: ' .
            $reason,
        ]);

        $pdo->commit();

        return [
            'identity_history_id' =>
                $identityHistoryId,

            'previous_user_id' =>
                $historicalOwnerId,

            'previous_user_name' =>
                (string) $identity[
                    'user_name'
                ],
        ];
    } catch (Throwable $e) {
        if (
            $pdo->inTransaction()
        ) {
            $pdo->rollBack();
        }

        throw $e;
    }
}