<?php

require_once __DIR__ . '/ebook_helper.php';

function validateUploadError(array $file, string $label): void
{
    if (!isset($file['error']) || is_array($file['error'])) {
        throw new RuntimeException(
            $label . ' upload is invalid.'
        );
    }

    if ($file['error'] === UPLOAD_ERR_NO_FILE) {
        return;
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException(
            $label . ' upload failed.'
        );
    }
}

function ensureUploadDirectory(string $uploadDir): void
{
    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            throw new RuntimeException(
                'Failed to create upload directory.'
            );
        }
    }

    if (!is_writable($uploadDir)) {
        throw new RuntimeException(
            'Upload directory is not writable.'
        );
    }
}

function getUploadedMimeType(string $tmpPath): string
{
    if (!is_file($tmpPath) || !is_readable($tmpPath)) {
        throw new RuntimeException(
            'Uploaded file is unreadable.'
        );
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($tmpPath);

    if (!is_string($mimeType) || $mimeType === '') {
        throw new RuntimeException(
            'Unable to determine uploaded file type.'
        );
    }

    return strtolower($mimeType);
}

function getUploadedImageDimensions(
    string $tmpPath,
    string $label
): array {
    $imageInfo = @getimagesize($tmpPath);

    if (
        $imageInfo === false ||
        !isset($imageInfo[0], $imageInfo[1])
    ) {
        throw new RuntimeException(
            $label . ' is not a valid image.'
        );
    }

    $width = (int) $imageInfo[0];
    $height = (int) $imageInfo[1];

    if ($width < 1 || $height < 1) {
        throw new RuntimeException(
            $label . ' has invalid dimensions.'
        );
    }

    return [$width, $height];
}

function uploadProductImage(
    array $file,
    string $uploadDir
): string {
    validateUploadError($file, 'Product image');

    if (
        $file['error'] === UPLOAD_ERR_NO_FILE ||
        trim((string) ($file['name'] ?? '')) === ''
    ) {
        return '';
    }

    $reportedSize = filter_var(
        $file['size'] ?? null,
        FILTER_VALIDATE_INT,
        [
            'options' => [
                'min_range' => 1,
                'max_range' => 2 * 1024 * 1024,
            ],
        ]
    );

    if ($reportedSize === false) {
        throw new RuntimeException(
            'Product image must not exceed 2MB.'
        );
    }

    $allowedExtensions = [
        'jpg',
        'jpeg',
        'png',
        'webp',
        'avif',
    ];
    $allowedMimeTypes = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/avif',
    ];

    $extension = strtolower(
        pathinfo(
            (string) $file['name'],
            PATHINFO_EXTENSION
        )
    );
    $mimeType = getUploadedMimeType(
        (string) $file['tmp_name']
    );

    if (!in_array($extension, $allowedExtensions, true)) {
        throw new RuntimeException(
            'Product image must be JPG, JPEG, PNG, WEBP, or AVIF.'
        );
    }

    if (!in_array($mimeType, $allowedMimeTypes, true)) {
        throw new RuntimeException(
            'Invalid product image file type.'
        );
    }

    $actualSize = filesize((string) $file['tmp_name']);

    if (
        $actualSize === false ||
        $actualSize !== (int) $reportedSize
    ) {
        throw new RuntimeException(
            'Product image upload size is inconsistent.'
        );
    }

    [$imageWidth, $imageHeight] = getUploadedImageDimensions(
        (string) $file['tmp_name'],
        'Product image'
    );

    $aspectRatio = $imageWidth / $imageHeight;

    if (
        $imageHeight <= $imageWidth ||
        $aspectRatio < 0.60 ||
        $aspectRatio > 0.75
    ) {
        throw new RuntimeException(
            'Product image must use a portrait cover with an aspect ratio close to 2:3.'
        );
    }

    ensureUploadDirectory($uploadDir);

    $newFileName = 'product_' .
        gmdate('Ymd_His') .
        '_' .
        bin2hex(random_bytes(16)) .
        '.' .
        $extension;
    $targetPath = rtrim($uploadDir, '/\\') .
        DIRECTORY_SEPARATOR .
        $newFileName;

    if (!move_uploaded_file(
        (string) $file['tmp_name'],
        $targetPath
    )) {
        throw new RuntimeException(
            'Failed to save product image.'
        );
    }

    @chmod($targetPath, 0644);

    return $newFileName;
}

function uploadHomepageHeroImage(
    array $file,
    string $uploadDir
): string {
    validateUploadError(
        $file,
        'Homepage hero image'
    );

    if (
        $file['error'] === UPLOAD_ERR_NO_FILE ||
        trim((string) ($file['name'] ?? '')) === ''
    ) {
        return '';
    }

    $reportedSize = filter_var(
        $file['size'] ?? null,
        FILTER_VALIDATE_INT,
        [
            'options' => [
                'min_range' => 1,
                'max_range' => 5 * 1024 * 1024,
            ],
        ]
    );

    if ($reportedSize === false) {
        throw new RuntimeException(
            'Homepage hero image must not exceed 5MB.'
        );
    }

    $allowedExtensions = [
        'jpg',
        'jpeg',
        'png',
        'webp',
        'avif',
    ];
    $allowedMimeTypes = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/avif',
    ];

    $extension = strtolower(
        pathinfo(
            (string) $file['name'],
            PATHINFO_EXTENSION
        )
    );
    $mimeType = getUploadedMimeType(
        (string) $file['tmp_name']
    );

    if (!in_array($extension, $allowedExtensions, true)) {
        throw new RuntimeException(
            'Homepage hero image must be JPG, JPEG, PNG, WEBP, or AVIF.'
        );
    }

    if (!in_array($mimeType, $allowedMimeTypes, true)) {
        throw new RuntimeException(
            'Invalid homepage hero image file type.'
        );
    }

    $actualSize = filesize((string) $file['tmp_name']);

    if (
        $actualSize === false ||
        $actualSize !== (int) $reportedSize
    ) {
        throw new RuntimeException(
            'Homepage hero image upload size is inconsistent.'
        );
    }

    [$imageWidth, $imageHeight] = getUploadedImageDimensions(
        (string) $file['tmp_name'],
        'Homepage hero image'
    );

    $aspectRatio = $imageWidth / $imageHeight;

    if ($imageWidth < 1200 || $imageHeight < 600) {
        throw new RuntimeException(
            'Homepage hero image must be at least 1200 × 600 pixels.'
        );
    }

    if (
        $imageWidth <= $imageHeight ||
        $aspectRatio < 1.50 ||
        $aspectRatio > 2.50
    ) {
        throw new RuntimeException(
            'Homepage hero image must use a landscape aspect ratio between 3:2 and 5:2.'
        );
    }

    ensureUploadDirectory($uploadDir);

    $newFileName = 'hero_' .
        gmdate('Ymd_His') .
        '_' .
        bin2hex(random_bytes(16)) .
        '.' .
        $extension;
    $targetPath = rtrim($uploadDir, '/\\') .
        DIRECTORY_SEPARATOR .
        $newFileName;

    if (!move_uploaded_file(
        (string) $file['tmp_name'],
        $targetPath
    )) {
        throw new RuntimeException(
            'Failed to save homepage hero image.'
        );
    }

    @chmod($targetPath, 0644);

    return $newFileName;
}

/**
 * Backwards-compatible wrapper. New product workflows should use
 * storeValidatedEbookUpload() so that detected metadata is available.
 */
function uploadEbookFile(
    array $file,
    string $uploadDir = ''
): string {
    $metadata = storeValidatedEbookUpload($file);

    return (string) $metadata['file_name'];
}

function deleteUploadedProductImage(
    string $uploadDir,
    string $fileName
): void {
    $fileName = trim($fileName);

    if (
        $fileName === '' ||
        $fileName !== basename($fileName) ||
        preg_match(
            '/\A(?:product|hero)_[A-Za-z0-9_-]+\.(?:jpe?g|png|webp|avif)\z/i',
            $fileName
        ) !== 1
    ) {
        return;
    }

    $path = rtrim($uploadDir, '/\\') .
        DIRECTORY_SEPARATOR .
        $fileName;

    if (is_file($path)) {
        @unlink($path);
    }
}
