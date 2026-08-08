<?php

require_once __DIR__ .
    '/config.php';

require_once __DIR__ .
    '/identity_helper.php';

require_once __DIR__ .
    '/upload_helper.php';

require_once __DIR__ .
    '/welcome_reward_helper.php';

/**
 * Return the private directory used for temporary
 * identity verification evidence.
 */
function identityEvidenceStoragePath(): string
{
    if (
        !defined(
            'IDENTITY_EVIDENCE_STORAGE'
        )
    ) {
        throw new RuntimeException(
            'Identity verification storage is not configured.'
        );
    }

    $path = trim(
        (string) IDENTITY_EVIDENCE_STORAGE
    );

    if ($path === '') {
        throw new RuntimeException(
            'Identity verification storage is not configured.'
        );
    }

    return rtrim(
        $path,
        '/\\'
    );
}

/**
 * Normalize a Malaysian MyKad / NRIC number.
 *
 * The plaintext NRIC is never stored in the
 * verification database record.
 */
function normalizeMalaysianNric(
    string $nric
): string {
    $nric = trim($nric);

    $nric = preg_replace(
        '/[\s\-]+/',
        '',
        $nric
    );

    if (
        !is_string($nric) ||
        !preg_match(
            '/^[0-9]{12}$/',
            $nric
        )
    ) {
        throw new RuntimeException(
            'Please enter a valid 12-digit Malaysian MyKad / NRIC number.'
        );
    }

    return $nric;
}

/**
 * Create a non-reversible deterministic fingerprint
 * of the NRIC for identity matching.
 */
function identityNricFingerprint(
    string $nric
): string {
    $normalizedNric =
        normalizeMalaysianNric(
            $nric
        );

    return hash_hmac(
        'sha256',
        'nric:' . $normalizedNric,
        identityHmacKey()
    );
}

/**
 * Store sensitive identity evidence outside the
 * public web root.
 */
function storeIdentityVerificationEvidence(
    array $file,
    string $prefix,
    string $label
): array {
    validateUploadError(
        $file,
        $label
    );

    if (
        ($file['error'] ?? null) ===
            UPLOAD_ERR_NO_FILE ||
        trim(
            (string) (
                $file['name'] ?? ''
            )
        ) === ''
    ) {
        throw new RuntimeException(
            $label . ' is required.'
        );
    }

    $reportedSize =
        filter_var(
            $file['size'] ?? null,
            FILTER_VALIDATE_INT,
            [
                'options' => [
                    'min_range' => 1,
                    'max_range' =>
                        5 * 1024 * 1024,
                ],
            ]
        );

    if ($reportedSize === false) {
        throw new RuntimeException(
            $label .
            ' must not exceed 5MB.'
        );
    }

    $extension =
        strtolower(
            pathinfo(
                (string) $file['name'],
                PATHINFO_EXTENSION
            )
        );

    $mimeType =
        getUploadedMimeType(
            (string) $file[
                'tmp_name'
            ]
        );

    $allowedTypes = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
    ];

    if (
        !isset(
            $allowedTypes[
                $extension
            ]
        ) ||
        $allowedTypes[
            $extension
        ] !== $mimeType
    ) {
        throw new RuntimeException(
            $label .
            ' must be a JPG, JPEG, PNG, or WEBP image.'
        );
    }

    $actualSize =
        filesize(
            (string) $file[
                'tmp_name'
            ]
        );

    if (
        $actualSize === false ||
        $actualSize !==
            (int) $reportedSize
    ) {
        throw new RuntimeException(
            $label .
            ' upload size is inconsistent.'
        );
    }

    [
        $imageWidth,
        $imageHeight,
    ] =
        getUploadedImageDimensions(
            (string) $file[
                'tmp_name'
            ],
            $label
        );

    if (
        $imageWidth < 500 ||
        $imageHeight < 300
    ) {
        throw new RuntimeException(
            $label .
            ' resolution is too low. The image must be at least 500 × 300 pixels.'
        );
    }

    if (
        $imageWidth > 8000 ||
        $imageHeight > 8000
    ) {
        throw new RuntimeException(
            $label .
            ' image dimensions are too large.'
        );
    }

    $storagePath =
        identityEvidenceStoragePath();

    ensureUploadDirectory(
        $storagePath
    );

    $fileName =
        $prefix .
        '_' .
        gmdate('Ymd_His') .
        '_' .
        bin2hex(
            random_bytes(16)
        ) .
        '.' .
        $extension;

    $targetPath =
        $storagePath .
        DIRECTORY_SEPARATOR .
        $fileName;

    if (
        !move_uploaded_file(
            (string) $file[
                'tmp_name'
            ],
            $targetPath
        )
    ) {
        throw new RuntimeException(
            'Unable to securely store ' .
            strtolower($label) .
            '.'
        );
    }

    @chmod(
        $targetPath,
        0600
    );

    $sha256 =
        hash_file(
            'sha256',
            $targetPath
        );

    if (
        !is_string($sha256) ||
        strlen($sha256) !== 64
    ) {
        @unlink($targetPath);

        throw new RuntimeException(
            'Unable to verify uploaded evidence integrity.'
        );
    }

    return [
        'file_name' =>
            $fileName,

        'sha256' =>
            $sha256,
    ];
}

/**
 * Delete temporary verification evidence.
 */
function deleteIdentityVerificationEvidence(
    string $fileName
): bool {
    $fileName =
        trim($fileName);

    if (
        $fileName === '' ||
        $fileName !==
            basename($fileName) ||
        !preg_match(
            '/\A(?:identity|phone)_[A-Za-z0-9_-]+\.(?:jpe?g|png|webp)\z/i',
            $fileName
        )
    ) {
        return false;
    }

    $path =
        identityEvidenceStoragePath() .
        DIRECTORY_SEPARATOR .
        $fileName;

    if (!is_file($path)) {
        return true;
    }

    return @unlink($path);
}

/**
 * Approve or reject a customer phone ownership
 * verification request.
 */
function processIdentityVerificationDecision(
    PDO $pdo,
    int $requestId,
    int $adminId,
    string $decision,
    string $adminNote,
    string $nricConfirmation = ''
): array {
    if (
        $requestId < 1 ||
        $adminId < 1
    ) {
        throw new RuntimeException(
            'Invalid identity verification decision.'
        );
    }

    if (
        !in_array(
            $decision,
            [
                'approved',
                'rejected',
            ],
            true
        )
    ) {
        throw new RuntimeException(
            'Invalid identity verification decision.'
        );
    }

    $adminNote =
        trim($adminNote);

    $adminNoteLength =
        function_exists('mb_strlen')
            ? mb_strlen(
                $adminNote,
                'UTF-8'
            )
            : strlen(
                $adminNote
            );

    if ($adminNote === '') {
        throw new RuntimeException(
            'A review note is required.'
        );
    }

    if ($adminNoteLength > 1000) {
        throw new RuntimeException(
            'Review note cannot exceed 1000 characters.'
        );
    }

    if ($pdo->inTransaction()) {
        throw new RuntimeException(
            'Identity verification decision cannot start inside another transaction.'
        );
    }

    try {
        $pdo->beginTransaction();

        $requestStatement =
            $pdo->prepare("
                SELECT
                    ivr.*,

                    u.user_phone,
                    u.user_is_active,
                    u.user_deleted_at

                FROM
                    identity_verification_requests ivr

                INNER JOIN users u
                    ON u.user_id =
                        ivr.verification_request_user_id

                WHERE
                    ivr.verification_request_id = ?

                LIMIT 1
                FOR UPDATE
            ");

        $requestStatement->execute([
            $requestId,
        ]);

        $request =
            $requestStatement->fetch(
                PDO::FETCH_ASSOC
            );

        if (!$request) {
            throw new RuntimeException(
                'Identity verification request was not found.'
            );
        }

        if (
            $request[
                'verification_request_status'
            ] !== 'pending' ||
            (int) $request[
                'verification_request_open_key'
            ] !== 1
        ) {
            throw new RuntimeException(
                'This identity verification request has already been processed.'
            );
        }

        $customerId =
            (int) $request[
                'verification_request_user_id'
            ];

        $phone =
            normalizeIdentityPhone(
                (string) $request[
                    'verification_request_phone_snapshot'
                ]
            );

        $phoneFingerprint =
            customerIdentityFingerprint(
                'phone',
                $phone
            );

        if (
            !hash_equals(
                (string) $request[
                    'verification_request_phone_fingerprint'
                ],
                $phoneFingerprint
            )
        ) {
            throw new RuntimeException(
                'Phone identity verification data is inconsistent.'
            );
        }

        $welcomeRewardsGranted =
            false;

        if ($decision === 'approved') {
            if (
                (int) $request[
                    'user_is_active'
                ] !== 1 ||
                !empty(
                    $request[
                        'user_deleted_at'
                    ]
                )
            ) {
                throw new RuntimeException(
                    'The requesting customer account is no longer active and cannot receive phone ownership.'
                );
            }

            $confirmedNric =
                normalizeMalaysianNric(
                    $nricConfirmation
                );

            $confirmedNricFingerprint =
                identityNricFingerprint(
                    $confirmedNric
                );

            if (
                !hash_equals(
                    (string) $request[
                        'verification_request_nric_fingerprint'
                    ],
                    $confirmedNricFingerprint
                )
            ) {
                throw new RuntimeException(
                    'The MyKad / NRIC number does not match the identity information submitted by the customer.'
                );
            }

            /*
             * A verified NRIC should not silently
             * approve ownership for multiple customer
             * accounts.
             */
            $existingVerifiedIdentity =
                $pdo->prepare("
                    SELECT
                        verification_request_id,
                        verification_request_user_id
                    FROM
                        identity_verification_requests
                    WHERE
                        verification_request_nric_fingerprint = ?
                    AND
                        verification_request_status =
                            'approved'
                    AND
                        verification_request_user_id != ?
                    LIMIT 1
                    FOR UPDATE
                ");

            $existingVerifiedIdentity
                ->execute([
                    $confirmedNricFingerprint,
                    $customerId,
                ]);

            if (
                $existingVerifiedIdentity
                    ->fetchColumn() !== false
            ) {
                throw new RuntimeException(
                    'This MyKad identity is already associated with another verified MangaVault customer account. Resolve the customer identity conflict before approval.'
                );
            }

            $historicalIdentity =
                $pdo->prepare("
                    SELECT
                        identity_history_id,
                        identity_user_id
                    FROM
                        customer_identity_history
                    WHERE
                        identity_type = 'phone'
                    AND
                        identity_fingerprint = ?
                    AND
                        identity_status =
                            'historical'
                    AND
                        identity_claim_key = 1
                    LIMIT 1
                    FOR UPDATE
                ");

            $historicalIdentity
                ->execute([
                    $phoneFingerprint,
                ]);

            $historical =
                $historicalIdentity->fetch(
                    PDO::FETCH_ASSOC
                );

            if (!$historical) {
                throw new RuntimeException(
                    'The historical phone ownership claim is no longer available for reassignment.'
                );
            }

            if (
                (int) $historical[
                    'identity_user_id'
                ] === $customerId
            ) {
                throw new RuntimeException(
                    'This phone number already belongs to the requesting customer identity history.'
                );
            }

            $currentPhone =
                normalizeIdentityPhone(
                    (string) (
                        $request[
                            'user_phone'
                        ] ?? ''
                    )
                );

            if (
                $currentPhone !== '' &&
                $currentPhone !== $phone
            ) {
                markCustomerIdentityHistorical(
                    $pdo,
                    $customerId,
                    'phone',
                    $currentPhone,
                    'profile_update'
                );
            }

            $releaseReason =
                'Approved identity verification request #' .
                $requestId .
                ': ' .
                $adminNote;

            $releaseReasonLength =
                function_exists('mb_strlen')
                    ? mb_strlen(
                        $releaseReason,
                        'UTF-8'
                    )
                    : strlen(
                        $releaseReason
                    );

            if ($releaseReasonLength > 500) {
                $releaseReason =
                    function_exists('mb_substr')
                        ? mb_substr(
                            $releaseReason,
                            0,
                            500,
                            'UTF-8'
                        )
                        : substr(
                            $releaseReason,
                            0,
                            500
                        );
            }

            releaseHistoricalPhoneIdentity(
                $pdo,
                (int) $historical[
                    'identity_history_id'
                ],
                $phone,
                $adminId,
                $releaseReason,
                $customerId
            );

            $assignPhone =
                $pdo->prepare("
                    UPDATE users
                    SET
                        user_phone = ?
                    WHERE
                        user_id = ?
                    AND
                        user_role = 'customer'
                    AND
                        user_is_active = 1
                    AND
                        user_deleted_at IS NULL
                ");

            $assignPhone->execute([
                $phone,
                $customerId,
            ]);

            claimCustomerIdentity(
                $pdo,
                $customerId,
                'phone',
                $phone,
                'admin_reassignment'
            );

            $welcomeRewardsGranted =
                grantWelcomeRewardsIfEligible(
                    $pdo,
                    $customerId
                );
        }

        $updateRequest =
            $pdo->prepare("
                UPDATE
                    identity_verification_requests
                SET
                    verification_request_status = ?,
                    verification_request_reviewed_by = ?,
                    verification_request_reviewed_at =
                        NOW(),
                    verification_request_admin_note = ?,
                    verification_request_open_key =
                        NULL
                WHERE
                    verification_request_id = ?
                AND
                    verification_request_status =
                        'pending'
                AND
                    verification_request_open_key = 1
            ");

        $updateRequest->execute([
            $decision,
            $adminId,
            $adminNote,
            $requestId,
        ]);

        if (
            $updateRequest->rowCount() !== 1
        ) {
            throw new RuntimeException(
                'Unable to record the identity verification decision.'
            );
        }

        $logAction =
            $decision === 'approved'
                ? 'approve_identity_verification'
                : 'reject_identity_verification';

        $log =
            $pdo->prepare("
                INSERT INTO admin_logs (
                    log_admin_id,
                    log_action,
                    log_target_type,
                    log_target_id,
                    log_details
                )
                VALUES (?, ?, 'user', ?, ?)
            ");

        $log->execute([
            $adminId,
            $logAction,
            $customerId,
            'Identity verification request #' .
            $requestId .
            ' for phone ' .
            $phone .
            ' was ' .
            $decision .
            '. Review note: ' .
            $adminNote,
        ]);

        $pdo->commit();

        return [
            'request_id' =>
                $requestId,

            'customer_id' =>
                $customerId,

            'phone' =>
                $phone,

            'decision' =>
                $decision,

            'identity_file' =>
                (string) $request[
                    'verification_request_identity_file'
                ],

            'phone_evidence_file' =>
                (string) $request[
                    'verification_request_phone_evidence_file'
                ],

            'welcome_rewards_granted' =>
                $welcomeRewardsGranted,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }
}