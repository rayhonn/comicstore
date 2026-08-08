<?php

require_once __DIR__ .
    '/../includes/db.php';

require_once __DIR__ .
    '/../includes/auth.php';

require_once __DIR__ .
    '/../includes/identity_verification_helper.php';

require_senior_admin();

$requestId =
    filter_var(
        $_GET['request_id'] ?? null,
        FILTER_VALIDATE_INT,
        [
            'options' => [
                'min_range' => 1,
            ],
        ]
    );

$type =
    $_GET['type'] ?? null;

if (
    $requestId === false ||
    $requestId === null ||
    !is_string($type) ||
    !in_array(
        $type,
        [
            'identity',
            'phone',
        ],
        true
    )
) {
    http_response_code(400);
    exit('Invalid evidence request.');
}

$statement =
    $pdo->prepare("
        SELECT
            verification_request_status,
            verification_request_identity_file,
            verification_request_identity_file_sha256,
            verification_request_phone_evidence_file,
            verification_request_phone_evidence_sha256
        FROM
            identity_verification_requests
        WHERE
            verification_request_id = ?
        LIMIT 1
    ");

$statement->execute([
    (int) $requestId,
]);

$request =
    $statement->fetch(
        PDO::FETCH_ASSOC
    );

if (!$request) {
    http_response_code(404);
    exit('Verification request not found.');
}

if (
    $request[
        'verification_request_status'
    ] !== 'pending'
) {
    http_response_code(403);
    exit(
        'Evidence is no longer available for review.'
    );
}

if ($type === 'identity') {
    $fileName =
        (string) $request[
            'verification_request_identity_file'
        ];

    $expectedHash =
        (string) $request[
            'verification_request_identity_file_sha256'
        ];

    $downloadName =
        'identity-evidence';
} else {
    $fileName =
        (string) $request[
            'verification_request_phone_evidence_file'
        ];

    $expectedHash =
        (string) $request[
            'verification_request_phone_evidence_sha256'
        ];

    $downloadName =
        'phone-ownership-evidence';
}

$fileName =
    trim($fileName);

if (
    $fileName === '' ||
    $fileName !== basename($fileName) ||
    preg_match(
        '/\A(?:identity|phone)_[A-Za-z0-9_-]+\.(?:jpe?g|png|webp)\z/i',
        $fileName
    ) !== 1
) {
    http_response_code(400);
    exit('Invalid evidence file.');
}

$filePath =
    identityEvidenceStoragePath() .
    DIRECTORY_SEPARATOR .
    $fileName;

if (
    !is_file($filePath) ||
    !is_readable($filePath)
) {
    http_response_code(404);
    exit('Evidence file was not found.');
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
        'Identity verification evidence integrity check failed for request #' .
        (int) $requestId .
        ', type ' .
        $type
    );

    http_response_code(409);

    exit(
        'Evidence integrity verification failed.'
    );
}

$mimeType =
    getUploadedMimeType(
        $filePath
    );

$allowedMimeTypes = [
    'image/jpeg',
    'image/png',
    'image/webp',
];

if (
    !in_array(
        $mimeType,
        $allowedMimeTypes,
        true
    )
) {
    http_response_code(415);
    exit('Unsupported evidence file type.');
}

$extension =
    strtolower(
        pathinfo(
            $fileName,
            PATHINFO_EXTENSION
        )
    );

header(
    'Content-Type: ' .
    $mimeType
);

header(
    'Content-Disposition: inline; filename="' .
    $downloadName .
    '.' .
    $extension .
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
    'Referrer-Policy: no-referrer'
);

$fileSize =
    filesize(
        $filePath
    );

if ($fileSize !== false) {
    header(
        'Content-Length: ' .
        $fileSize
    );
}

readfile(
    $filePath
);

exit;