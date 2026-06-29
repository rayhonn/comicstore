<?php

function validateUploadError(array $file, string $label): void
{
    if (!isset($file['error']) || is_array($file['error'])) {
        throw new Exception($label . ' upload is invalid.');
    }

    if ($file['error'] === UPLOAD_ERR_NO_FILE) {
        return;
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception($label . ' upload failed.');
    }
}

function ensureUploadDirectory(string $uploadDir): void
{
    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true)) {
            throw new Exception('Failed to create upload directory.');
        }
    }

    if (!is_writable($uploadDir)) {
        throw new Exception('Upload directory is not writable.');
    }
}

function getUploadedMimeType(string $tmpPath): string
{
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    return $finfo->file($tmpPath);
}

function uploadProductImage(array $file, string $uploadDir): string
{
    validateUploadError($file, 'Product image');

    if ($file['error'] === UPLOAD_ERR_NO_FILE || empty($file['name'])) {
        return '';
    }

    if ($file['size'] > 2 * 1024 * 1024) {
        throw new Exception('Product image must not exceed 2MB.');
    }

    $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp', 'avif'];
    $allowedMimeTypes = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/avif'
    ];

    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $mimeType = getUploadedMimeType($file['tmp_name']);

    if (!in_array($extension, $allowedExtensions, true)) {
        throw new Exception('Product image must be JPG, JPEG, PNG, WEBP, or AVIF.');
    }

    if (!in_array($mimeType, $allowedMimeTypes, true)) {
        throw new Exception('Invalid product image file type.');
    }

    ensureUploadDirectory($uploadDir);

    $newFileName = 'product_' . date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
    $targetPath = rtrim($uploadDir, '/\\') . DIRECTORY_SEPARATOR . $newFileName;

    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        throw new Exception('Failed to save product image.');
    }

    return $newFileName;
}

function uploadEbookFile(array $file, string $uploadDir): string
{
    validateUploadError($file, 'E-book file');

    if ($file['error'] === UPLOAD_ERR_NO_FILE || empty($file['name'])) {
        return '';
    }

    if ($file['size'] > 20 * 1024 * 1024) {
        throw new Exception('E-book file must not exceed 20MB.');
    }

    $allowedExtensions = ['pdf', 'epub'];
    $allowedMimeTypes = [
        'application/pdf',
        'application/epub+zip',
        'application/octet-stream'
    ];

    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $mimeType = getUploadedMimeType($file['tmp_name']);

    if (!in_array($extension, $allowedExtensions, true)) {
        throw new Exception('E-book file must be PDF or EPUB.');
    }

    if (!in_array($mimeType, $allowedMimeTypes, true)) {
        throw new Exception('Invalid e-book file type.');
    }

    ensureUploadDirectory($uploadDir);

    $newFileName = 'ebook_' . date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
    $targetPath = rtrim($uploadDir, '/\\') . DIRECTORY_SEPARATOR . $newFileName;

    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        throw new Exception('Failed to save e-book file.');
    }

    return $newFileName;
}