<?php

require_once __DIR__ .
    '/config.php';

require_once __DIR__ .
    '/identity_helper.php';

require_once __DIR__ .
    '/upload_helper.php';

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
            ' image resolution is too low.'
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
): void {
    $fileName = trim(
        $fileName
    );

    if (
        $fileName === '' ||
        $fileName !==
            basename($fileName) ||
        !preg_match(
            '/\A(?:identity|phone)_[A-Za-z0-9_-]+\.(?:jpe?g|png|webp)\z/i',
            $fileName
        )
    ) {
        return;
    }

    $path =
        identityEvidenceStoragePath() .
        DIRECTORY_SEPARATOR .
        $fileName;

    if (is_file($path)) {
        @unlink($path);
    }
}