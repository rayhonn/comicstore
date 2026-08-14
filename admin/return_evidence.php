<?php

require_once __DIR__ .
    '/../includes/db.php';

require_once __DIR__ .
    '/../includes/auth.php';

require_once __DIR__ .
    '/../includes/return_evidence_helper.php';

require_admin_or_staff();

$returnId =
    filter_var(
        $_GET['return_id'] ?? null,
        FILTER_VALIDATE_INT,
        [
            'options' => [
                'min_range' => 1,
            ],
        ]
    );

if (
    $returnId === false ||
    $returnId === null
) {
    http_response_code(400);
    exit('Invalid return evidence request.');
}

$statement =
    $pdo->prepare("
        SELECT
            return_evidence_file_name,
            return_evidence_file_sha256,
            return_evidence_mime_type,
            return_evidence_file_size
        FROM return_request_evidence
        WHERE return_evidence_return_id = ?
        LIMIT 1
    ");

$statement->execute([
    (int) $returnId,
]);

$evidence =
    $statement->fetch(
        PDO::FETCH_ASSOC
    );

if (!$evidence) {
    http_response_code(404);
    exit('Return evidence was not found.');
}

$fileName =
    trim(
        (string) $evidence[
            'return_evidence_file_name'
        ]
    );

$expectedHash =
    strtolower(
        trim(
            (string) $evidence[
                'return_evidence_file_sha256'
            ]
        )
    );

$expectedMimeType =
    strtolower(
        trim(
            (string) $evidence[
                'return_evidence_mime_type'
            ]
        )
    );

$expectedFileSize =
    (int) $evidence[
        'return_evidence_file_size'
    ];

$extensionByMimeType = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
];

if (
    !isValidReturnEvidenceFileName(
        $fileName
    ) ||
    preg_match(
        '/\A[a-f0-9]{64}\z/',
        $expectedHash
    ) !== 1 ||
    !isset(
        $extensionByMimeType[
            $expectedMimeType
        ]
    ) ||
    $expectedFileSize < 1 ||
    $expectedFileSize >
        5 * 1024 * 1024
) {
    app_error_log(
        'Return evidence metadata validation failed for Return #' .
        (int) $returnId
    );

    http_response_code(409);
    exit('Return evidence metadata is invalid.');
}

$filePath =
    returnEvidenceFilePath(
        $fileName
    );

if (
    !is_file($filePath) ||
    !is_readable($filePath)
) {
    http_response_code(404);
    exit('Return evidence file was not found.');
}

$actualFileSize =
    filesize(
        $filePath
    );

if (
    $actualFileSize === false ||
    $actualFileSize !==
        $expectedFileSize
) {
    app_error_log(
        'Return evidence size verification failed for Return #' .
        (int) $returnId
    );

    http_response_code(409);
    exit('Return evidence integrity verification failed.');
}

$actualHash =
    hash_file(
        'sha256',
        $filePath
    );

if (
    !is_string($actualHash) ||
    strlen($actualHash) !== 64 ||
    !hash_equals(
        $expectedHash,
        $actualHash
    )
) {
    app_error_log(
        'Return evidence hash verification failed for Return #' .
        (int) $returnId
    );

    http_response_code(409);
    exit('Return evidence integrity verification failed.');
}

try {
    $actualMimeType =
        getUploadedMimeType(
            $filePath
        );
} catch (Throwable $e) {
    app_error_log(
        'Return evidence MIME verification failed for Return #' .
        (int) $returnId .
        ': ' .
        $e->getMessage()
    );

    http_response_code(409);
    exit('Return evidence integrity verification failed.');
}

if (
    $actualMimeType !==
        $expectedMimeType ||
    !isset(
        $extensionByMimeType[
            $actualMimeType
        ]
    )
) {
    app_error_log(
        'Return evidence MIME mismatch for Return #' .
        (int) $returnId
    );

    http_response_code(415);
    exit('Unsupported return evidence file type.');
}

$downloadName =
    'return-evidence-' .
    str_pad(
        (string) $returnId,
        4,
        '0',
        STR_PAD_LEFT
    ) .
    '.' .
    $extensionByMimeType[
        $actualMimeType
    ];

header(
    'Content-Type: ' .
    $actualMimeType
);

header(
    'Content-Disposition: inline; filename="' .
    $downloadName .
    '"'
);

header(
    'Cache-Control: no-store, no-cache, must-revalidate, private'
);

header(
    'Pragma: no-cache'
);

header(
    'Expires: 0'
);

header(
    'X-Content-Type-Options: nosniff'
);

header(
    'X-Frame-Options: DENY'
);

header(
    'Referrer-Policy: no-referrer'
);

header(
    'Cross-Origin-Resource-Policy: same-origin'
);

header(
    'Content-Length: ' .
    $actualFileSize
);

readfile(
    $filePath
);

exit;