<?php

const EBOOK_MAX_UPLOAD_BYTES = 20 * 1024 * 1024;
const EBOOK_MAX_EPUB_ENTRIES = 5000;
const EBOOK_MAX_EPUB_UNCOMPRESSED_BYTES = 100 * 1024 * 1024;
const EBOOK_MAX_EPUB_COMPRESSION_RATIO = 100;

function ebookPrivateStorageDirectory(): string
{
    $configured = trim((string) getenv('EBOOK_STORAGE_PATH'));

    if ($configured !== '') {
        return rtrim($configured, '/\\');
    }

    $documentRoot = trim((string) (
        $_SERVER['DOCUMENT_ROOT'] ?? ''
    ));

    if ($documentRoot !== '') {
        return dirname($documentRoot) .
            DIRECTORY_SEPARATOR .
            'mangavault_private' .
            DIRECTORY_SEPARATOR .
            'ebooks';
    }

    return dirname(__DIR__, 3) .
        DIRECTORY_SEPARATOR .
        'mangavault_private' .
        DIRECTORY_SEPARATOR .
        'ebooks';
}

function ebookLegacyStorageDirectory(): string
{
    return dirname(__DIR__) .
        DIRECTORY_SEPARATOR .
        'assets' .
        DIRECTORY_SEPARATOR .
        'ebooks';
}

function ensureEbookPrivateStorageDirectory(): string
{
    $directory = ebookPrivateStorageDirectory();

    if (!is_dir($directory)) {
        if (!mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException(
                'Unable to create the private e-book storage directory.'
            );
        }
    }

    if (!is_writable($directory)) {
        throw new RuntimeException(
            'The private e-book storage directory is not writable.'
        );
    }

    return $directory;
}

function isSafeEbookFileName(string $fileName): bool
{
    return $fileName !== '' &&
        $fileName === basename($fileName) &&
        strlen($fileName) <= 255 &&
        preg_match(
            '/\Aebook_[A-Za-z0-9_-]+\.(pdf|epub)\z/i',
            $fileName
        ) === 1;
}

function ebookFileMimeType(string $filePath): string
{
    if (!is_file($filePath) || !is_readable($filePath)) {
        throw new RuntimeException(
            'The e-book file cannot be read.'
        );
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($filePath);

    if (!is_string($mimeType) || $mimeType === '') {
        throw new RuntimeException(
            'Unable to determine the e-book file type.'
        );
    }

    return strtolower($mimeType);
}

function ebookFileSize(string $filePath): int
{
    $fileSize = filesize($filePath);

    if ($fileSize === false || $fileSize < 1) {
        throw new RuntimeException(
            'The e-book file is empty or unreadable.'
        );
    }

    if ($fileSize > EBOOK_MAX_UPLOAD_BYTES) {
        throw new RuntimeException(
            'E-book file must not exceed 20MB.'
        );
    }

    return $fileSize;
}

function validatePdfEbookFile(string $filePath): array
{
    $fileSize = ebookFileSize($filePath);
    $mimeType = ebookFileMimeType($filePath);

    if ($mimeType !== 'application/pdf') {
        throw new RuntimeException(
            'The uploaded file is not a valid PDF document.'
        );
    }

    $handle = fopen($filePath, 'rb');

    if ($handle === false) {
        throw new RuntimeException(
            'Unable to inspect the PDF document.'
        );
    }

    try {
        $header = fread($handle, 8);

        if (!is_string($header) || !str_starts_with($header, '%PDF-')) {
            throw new RuntimeException(
                'The PDF document has an invalid file signature.'
            );
        }

        $tailLength = min($fileSize, 4096);

        if (fseek($handle, -$tailLength, SEEK_END) !== 0) {
            throw new RuntimeException(
                'Unable to inspect the end of the PDF document.'
            );
        }

        $tail = fread($handle, $tailLength);

        if (!is_string($tail) || strpos($tail, '%%EOF') === false) {
            throw new RuntimeException(
                'The PDF document is incomplete or corrupted.'
            );
        }
    } finally {
        fclose($handle);
    }

    $contents = file_get_contents($filePath);

    if ($contents === false) {
        throw new RuntimeException(
            'Unable to inspect the PDF document.'
        );
    }

    $blockedTokens = [
        '/JavaScript',
        '/JS',
        '/Launch',
        '/EmbeddedFile',
        '/OpenAction',
        '/AA',
    ];

    foreach ($blockedTokens as $blockedToken) {
        if (stripos($contents, $blockedToken) !== false) {
            throw new RuntimeException(
                'The PDF contains an unsupported active or embedded feature.'
            );
        }
    }

    return [
        'format' => 'PDF',
        'extension' => 'pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => $fileSize,
        'size_mb' => round($fileSize / 1048576, 2),
        'sha256' => hash_file('sha256', $filePath),
    ];
}

function isSafeEpubEntryName(string $entryName): bool
{
    if (
        $entryName === '' ||
        str_contains($entryName, "\0") ||
        str_contains($entryName, '\\') ||
        str_starts_with($entryName, '/') ||
        preg_match('/\A[A-Za-z]:\//', $entryName) === 1
    ) {
        return false;
    }

    foreach (explode('/', $entryName) as $segment) {
        if ($segment === '..') {
            return false;
        }
    }

    return true;
}

function validateEpubEbookFile(string $filePath): array
{
    $fileSize = ebookFileSize($filePath);
    $mimeType = ebookFileMimeType($filePath);

    if (!in_array(
        $mimeType,
        [
            'application/epub+zip',
            'application/zip',
            'application/x-zip',
            'application/x-zip-compressed',
        ],
        true
    )) {
        throw new RuntimeException(
            'The uploaded file is not a valid EPUB container.'
        );
    }

    if (!class_exists('ZipArchive')) {
        throw new RuntimeException(
            'EPUB validation requires the PHP ZIP extension to be enabled.'
        );
    }

    $zip = new ZipArchive();
    $openResult = $zip->open($filePath, ZipArchive::RDONLY);

    if ($openResult !== true) {
        throw new RuntimeException(
            'The EPUB file is corrupted or cannot be opened.'
        );
    }

    try {
        if (
            $zip->numFiles < 3 ||
            $zip->numFiles > EBOOK_MAX_EPUB_ENTRIES
        ) {
            throw new RuntimeException(
                'The EPUB contains an invalid number of files.'
            );
        }

        $totalUncompressedBytes = 0;

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entry = $zip->statIndex($index);

            if (!is_array($entry)) {
                throw new RuntimeException(
                    'The EPUB contains an unreadable entry.'
                );
            }

            $entryName = (string) ($entry['name'] ?? '');

            if (!isSafeEpubEntryName($entryName)) {
                throw new RuntimeException(
                    'The EPUB contains an unsafe file path.'
                );
            }

            $uncompressedSize = max(
                0,
                (int) ($entry['size'] ?? 0)
            );
            $compressedSize = max(
                0,
                (int) ($entry['comp_size'] ?? 0)
            );

            $totalUncompressedBytes += $uncompressedSize;

            if (
                $totalUncompressedBytes >
                    EBOOK_MAX_EPUB_UNCOMPRESSED_BYTES
            ) {
                throw new RuntimeException(
                    'The EPUB expands beyond the allowed size.'
                );
            }

            if (
                $compressedSize > 0 &&
                $uncompressedSize >
                    $compressedSize *
                    EBOOK_MAX_EPUB_COMPRESSION_RATIO
            ) {
                throw new RuntimeException(
                    'The EPUB contains a suspicious compression ratio.'
                );
            }
        }

        $mimetypeIndex = $zip->locateName(
            'mimetype',
            ZipArchive::FL_NOCASE
        );
        $mimetype = $zip->getFromName('mimetype');
        $mimetypeEntry = $mimetypeIndex === false
            ? false
            : $zip->statIndex($mimetypeIndex);

        if (
            $mimetypeIndex !== 0 ||
            !is_array($mimetypeEntry) ||
            (int) ($mimetypeEntry['comp_method'] ?? -1) !==
                ZipArchive::CM_STORE ||
            !is_string($mimetype) ||
            trim($mimetype) !== 'application/epub+zip'
        ) {
            throw new RuntimeException(
                'The EPUB mimetype declaration must be the first uncompressed entry.'
            );
        }

        if ($zip->locateName('META-INF/encryption.xml') !== false) {
            throw new RuntimeException(
                'Encrypted EPUB files are not supported.'
            );
        }

        $containerXml = $zip->getFromName(
            'META-INF/container.xml'
        );

        if (!is_string($containerXml) || trim($containerXml) === '') {
            throw new RuntimeException(
                'The EPUB container metadata is missing.'
            );
        }

        $previousErrors = libxml_use_internal_errors(true);
        $container = simplexml_load_string(
            $containerXml,
            SimpleXMLElement::class,
            LIBXML_NONET | LIBXML_NOCDATA
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrors);

        if ($container === false) {
            throw new RuntimeException(
                'The EPUB container metadata is invalid.'
            );
        }

        $container->registerXPathNamespace(
            'c',
            'urn:oasis:names:tc:opendocument:xmlns:container'
        );
        $rootFiles = $container->xpath(
            '//c:rootfile/@full-path'
        );

        if (!is_array($rootFiles) || $rootFiles === []) {
            throw new RuntimeException(
                'The EPUB package document is missing.'
            );
        }

        $packagePath = trim((string) $rootFiles[0]);

        if (
            !isSafeEpubEntryName($packagePath) ||
            $zip->locateName($packagePath) === false
        ) {
            throw new RuntimeException(
                'The EPUB package document is invalid.'
            );
        }

        $packageXml = $zip->getFromName($packagePath);

        if (!is_string($packageXml) || trim($packageXml) === '') {
            throw new RuntimeException(
                'The EPUB package document is unreadable.'
            );
        }

        $previousErrors = libxml_use_internal_errors(true);
        $package = simplexml_load_string(
            $packageXml,
            SimpleXMLElement::class,
            LIBXML_NONET | LIBXML_NOCDATA
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrors);

        if ($package === false) {
            throw new RuntimeException(
                'The EPUB package document is malformed.'
            );
        }

        $package->registerXPathNamespace(
            'opf',
            'http://www.idpf.org/2007/opf'
        );
        $manifestNodes = $package->xpath('//opf:manifest');
        $spineNodes = $package->xpath('//opf:spine');

        if (
            !is_array($manifestNodes) ||
            $manifestNodes === [] ||
            !is_array($spineNodes) ||
            $spineNodes === []
        ) {
            throw new RuntimeException(
                'The EPUB package is missing its manifest or reading order.'
            );
        }

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entry = $zip->statIndex($index);

            if (!is_array($entry)) {
                continue;
            }

            $entryName = strtolower(
                (string) ($entry['name'] ?? '')
            );

            if (str_ends_with($entryName, '.js')) {
                throw new RuntimeException(
                    'EPUB JavaScript files are not supported.'
                );
            }

            if (!preg_match(
                '/\.(?:xhtml|html|htm|svg|xml)\z/i',
                $entryName
            )) {
                continue;
            }

            if ((int) ($entry['size'] ?? 0) > 2 * 1024 * 1024) {
                throw new RuntimeException(
                    'An EPUB markup entry is unexpectedly large.'
                );
            }

            $entryContent = $zip->getFromIndex($index);

            if (!is_string($entryContent)) {
                throw new RuntimeException(
                    'An EPUB markup entry is unreadable.'
                );
            }

            if (preg_match(
                '/<script\b|javascript\s*:|\son(?:load|error|click|mouseover)\s*=/i',
                $entryContent
            ) === 1) {
                throw new RuntimeException(
                    'The EPUB contains unsupported active content.'
                );
            }
        }
    } finally {
        $zip->close();
    }

    return [
        'format' => 'EPUB',
        'extension' => 'epub',
        'mime_type' => 'application/epub+zip',
        'size_bytes' => $fileSize,
        'size_mb' => round($fileSize / 1048576, 2),
        'sha256' => hash_file('sha256', $filePath),
    ];
}

function inspectEbookFile(
    string $filePath,
    ?string $expectedFormat = null
): array {
    if (!is_file($filePath) || !is_readable($filePath)) {
        throw new RuntimeException(
            'The e-book file is missing or unreadable.'
        );
    }

    $extension = strtolower(
        pathinfo($filePath, PATHINFO_EXTENSION)
    );

    if ($extension === 'pdf') {
        $metadata = validatePdfEbookFile($filePath);
    } elseif ($extension === 'epub') {
        $metadata = validateEpubEbookFile($filePath);
    } else {
        throw new RuntimeException(
            'E-book file must use the PDF or EPUB extension.'
        );
    }

    $normalizedExpectedFormat = strtoupper(
        trim((string) $expectedFormat)
    );

    if (
        $normalizedExpectedFormat !== '' &&
        $metadata['format'] !== $normalizedExpectedFormat
    ) {
        throw new RuntimeException(
            'The detected e-book format does not match the selected format.'
        );
    }

    return $metadata;
}

function storeValidatedEbookUpload(array $file): array
{
    if (!isset($file['error']) || is_array($file['error'])) {
        throw new RuntimeException(
            'E-book file upload is invalid.'
        );
    }

    if ($file['error'] === UPLOAD_ERR_NO_FILE) {
        throw new RuntimeException(
            'An e-book file is required.'
        );
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException(
            'E-book file upload failed.'
        );
    }

    $temporaryPath = (string) ($file['tmp_name'] ?? '');
    $originalName = trim((string) ($file['name'] ?? ''));
    $reportedSize = filter_var(
        $file['size'] ?? null,
        FILTER_VALIDATE_INT,
        [
            'options' => [
                'min_range' => 1,
                'max_range' => EBOOK_MAX_UPLOAD_BYTES,
            ],
        ]
    );

    if (
        $temporaryPath === '' ||
        $originalName === '' ||
        $reportedSize === false ||
        !is_uploaded_file($temporaryPath)
    ) {
        throw new RuntimeException(
            'E-book file upload is invalid.'
        );
    }

    $originalExtension = strtolower(
        pathinfo($originalName, PATHINFO_EXTENSION)
    );

    if (!in_array($originalExtension, ['pdf', 'epub'], true)) {
        throw new RuntimeException(
            'E-book file must be PDF or EPUB.'
        );
    }

    $metadata = inspectEbookFile($temporaryPath);

    if ($metadata['extension'] !== $originalExtension) {
        throw new RuntimeException(
            'The e-book filename extension does not match its content.'
        );
    }

    if ($metadata['size_bytes'] !== (int) $reportedSize) {
        throw new RuntimeException(
            'The e-book upload size is inconsistent.'
        );
    }

    $directory = ensureEbookPrivateStorageDirectory();
    $fileName = 'ebook_' .
        gmdate('Ymd_His') .
        '_' .
        bin2hex(random_bytes(16)) .
        '.' .
        $metadata['extension'];
    $targetPath = $directory .
        DIRECTORY_SEPARATOR .
        $fileName;

    if (!move_uploaded_file($temporaryPath, $targetPath)) {
        throw new RuntimeException(
            'Failed to save the e-book file.'
        );
    }

    @chmod($targetPath, 0640);

    try {
        $savedMetadata = inspectEbookFile(
            $targetPath,
            $metadata['format']
        );
    } catch (Throwable $exception) {
        @unlink($targetPath);
        throw $exception;
    }

    return array_merge(
        $savedMetadata,
        [
            'file_name' => $fileName,
            'absolute_path' => $targetPath,
        ]
    );
}

function resolveStoredEbookFile(
    string $storedFileName,
    ?string $expectedFormat = null,
    bool $deepValidate = true
): array {
    $storedFileName = trim($storedFileName);

    if (!isSafeEbookFileName($storedFileName)) {
        throw new RuntimeException(
            'The stored e-book filename is invalid.'
        );
    }

    $candidatePaths = [
        ebookPrivateStorageDirectory() .
            DIRECTORY_SEPARATOR .
            $storedFileName,
        ebookLegacyStorageDirectory() .
            DIRECTORY_SEPARATOR .
            $storedFileName,
    ];

    $resolvedPath = null;

    foreach ($candidatePaths as $candidatePath) {
        if (is_file($candidatePath) && is_readable($candidatePath)) {
            $resolvedPath = $candidatePath;
            break;
        }
    }

    if ($resolvedPath === null) {
        throw new RuntimeException(
            'The e-book file is missing or unreadable.'
        );
    }

    if ($deepValidate) {
        $metadata = inspectEbookFile(
            $resolvedPath,
            $expectedFormat
        );
    } else {
        $extension = strtolower(
            pathinfo($resolvedPath, PATHINFO_EXTENSION)
        );
        $metadata = [
            'format' => strtoupper($extension),
            'extension' => $extension,
            'mime_type' => $extension === 'pdf'
                ? 'application/pdf'
                : 'application/epub+zip',
            'size_bytes' => ebookFileSize($resolvedPath),
            'size_mb' => round(
                ebookFileSize($resolvedPath) / 1048576,
                2
            ),
            'sha256' => null,
        ];
    }

    $metadata['file_name'] = $storedFileName;
    $metadata['absolute_path'] = $resolvedPath;

    return $metadata;
}

function deleteStoredEbookFile(string $storedFileName): void
{
    $storedFileName = trim($storedFileName);

    if (!isSafeEbookFileName($storedFileName)) {
        return;
    }

    foreach (
        [
            ebookPrivateStorageDirectory(),
            ebookLegacyStorageDirectory(),
        ] as $directory
    ) {
        $path = $directory .
            DIRECTORY_SEPARATOR .
            $storedFileName;

        if (is_file($path)) {
            @unlink($path);
        }
    }
}

function customerOwnsEbook(
    PDO $pdo,
    int $userId,
    int $productId,
    bool $forUpdate = false
): bool {
    if ($userId < 1 || $productId < 1) {
        return false;
    }

    $sql = "
        SELECT oi.order_item_id
        FROM order_items oi
        JOIN orders o
            ON o.order_id = oi.order_item_order_id
        WHERE o.order_user_id = ?
        AND oi.order_item_product_id = ?
        AND oi.order_item_type = 'ebook'
        AND o.order_payment_status IN (
            'pending_confirmation',
            'confirmed'
        )
        LIMIT 1
    ";

    if ($forUpdate) {
        if (!$pdo->inTransaction()) {
            throw new RuntimeException(
                'E-book ownership locking requires a transaction.'
            );
        }

        $sql .= ' FOR UPDATE';
    }

    $statement = $pdo->prepare($sql);
    $statement->execute([
        $userId,
        $productId,
    ]);

    return $statement->fetchColumn() !== false;
}

function loadPurchasableEbook(
    PDO $pdo,
    int $productId,
    bool $forUpdate = false
): array {
    if ($productId < 1) {
        throw new RuntimeException(
            'The e-book product is invalid.'
        );
    }

    $sql = "
        SELECT
            p.product_id,
            p.product_title,
            p.product_type,
            p.product_is_available,
            pe.ebook_file_path,
            pe.ebook_file_format,
            pe.ebook_download_limit
        FROM products p
        JOIN product_ebook pe
            ON pe.ebook_product_id = p.product_id
        WHERE p.product_id = ?
        LIMIT 1
    ";

    if ($forUpdate) {
        if (!$pdo->inTransaction()) {
            throw new RuntimeException(
                'E-book product locking requires a transaction.'
            );
        }

        $sql .= ' FOR UPDATE';
    }

    $statement = $pdo->prepare($sql);
    $statement->execute([$productId]);
    $ebook = $statement->fetch(PDO::FETCH_ASSOC);

    if (
        !$ebook ||
        $ebook['product_type'] !== 'ebook' ||
        (int) $ebook['product_is_available'] !== 1 ||
        (int) $ebook['ebook_download_limit'] < 1
    ) {
        throw new RuntimeException(
            'This e-book is not available for purchase.'
        );
    }

    $file = resolveStoredEbookFile(
        (string) $ebook['ebook_file_path'],
        (string) $ebook['ebook_file_format']
    );

    return array_merge($ebook, [
        'file' => $file,
    ]);
}

function assertCustomerCanPurchaseEbook(
    PDO $pdo,
    int $userId,
    int $productId,
    bool $forUpdate = false
): array {
    if ($userId < 1) {
        throw new RuntimeException(
            'The customer account is invalid.'
        );
    }

    if ($forUpdate) {
        if (!$pdo->inTransaction()) {
            throw new RuntimeException(
                'E-book purchase validation requires a transaction.'
            );
        }

        $customerLock = $pdo->prepare("
            SELECT user_id
            FROM users
            WHERE user_id = ?
            FOR UPDATE
        "
        );
        $customerLock->execute([$userId]);

        if ($customerLock->fetchColumn() === false) {
            throw new RuntimeException(
                'The customer account was not found.'
            );
        }
    }

    $ebook = loadPurchasableEbook(
        $pdo,
        $productId,
        $forUpdate
    );

    if (customerOwnsEbook(
        $pdo,
        $userId,
        $productId,
        $forUpdate
    )) {
        throw new RuntimeException(
            'You already own this e-book.'
        );
    }

    return $ebook;
}
