<?php

require_once __DIR__ . '/upload_helper.php';

function returnEvidenceStoragePath(): string
{
    return dirname(__DIR__) .
        DIRECTORY_SEPARATOR .
        'private_storage' .
        DIRECTORY_SEPARATOR .
        'return_evidence';
}

function isValidReturnEvidenceFileName(
    string $fileName
): bool {
    $fileName = trim($fileName);

    return
        $fileName !== '' &&
        $fileName === basename($fileName) &&
        preg_match(
            '/\Areturn_[A-Za-z0-9_-]+\.(?:jpe?g|png|webp)\z/i',
            $fileName
        ) === 1;
}

function returnEvidenceFilePath(
    string $fileName
): string {
    if (!isValidReturnEvidenceFileName($fileName)) {
        throw new RuntimeException(
            'Return evidence file name is invalid.'
        );
    }

    return returnEvidenceStoragePath() .
        DIRECTORY_SEPARATOR .
        trim($fileName);
}

function storeReturnEvidenceImage(
    array $file
): array {
    validateUploadError(
        $file,
        'Return evidence image'
    );

    if (
        $file['error'] === UPLOAD_ERR_NO_FILE ||
        trim(
            (string) ($file['name'] ?? '')
        ) === ''
    ) {
        throw new RuntimeException(
            'A supporting return evidence image is required.'
        );
    }

    $reportedSize = filter_var(
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
            'Return evidence image must not exceed 5MB.'
        );
    }

    $extension = strtolower(
        pathinfo(
            (string) $file['name'],
            PATHINFO_EXTENSION
        )
    );

    $mimeType = getUploadedMimeType(
        (string) $file['tmp_name']
    );

    $allowedTypes = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
    ];

    if (
        !isset($allowedTypes[$extension]) ||
        $allowedTypes[$extension] !==
            $mimeType
    ) {
        throw new RuntimeException(
            'Return evidence must be a JPG, JPEG, PNG, or WEBP image.'
        );
    }

    $actualSize = filesize(
        (string) $file['tmp_name']
    );

    if (
        $actualSize === false ||
        $actualSize !==
            (int) $reportedSize
    ) {
        throw new RuntimeException(
            'Return evidence upload size is inconsistent.'
        );
    }

    [
        $imageWidth,
        $imageHeight,
    ] = getUploadedImageDimensions(
        (string) $file['tmp_name'],
        'Return evidence image'
    );

    if (
        $imageWidth < 300 ||
        $imageHeight < 300
    ) {
        throw new RuntimeException(
            'Return evidence image must be at least 300 × 300 pixels.'
        );
    }

    if (
        $imageWidth > 8000 ||
        $imageHeight > 8000
    ) {
        throw new RuntimeException(
            'Return evidence image dimensions are too large.'
        );
    }

    $storagePath =
        returnEvidenceStoragePath();

    ensureUploadDirectory(
        $storagePath
    );

    $fileName =
        'return_' .
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
            (string) $file['tmp_name'],
            $targetPath
        )
    ) {
        throw new RuntimeException(
            'Unable to securely store the return evidence image.'
        );
    }

    @chmod(
        $targetPath,
        0600
    );

    $sha256 = hash_file(
        'sha256',
        $targetPath
    );

    if (
        !is_string($sha256) ||
        strlen($sha256) !== 64
    ) {
        @unlink($targetPath);

        throw new RuntimeException(
            'Unable to verify return evidence integrity.'
        );
    }

    return [
        'file_name' => $fileName,
        'sha256' => $sha256,
        'mime_type' => $mimeType,
        'file_size' => (int) $actualSize,
    ];
}

function deleteReturnEvidenceImage(
    string $fileName
): bool {
    if (!isValidReturnEvidenceFileName($fileName)) {
        return false;
    }

    $filePath =
        returnEvidenceFilePath($fileName);

    if (!is_file($filePath)) {
        return true;
    }

    return @unlink($filePath);
}