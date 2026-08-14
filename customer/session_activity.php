<?php

require_once __DIR__ .
    '/../includes/auth.php';

header(
    'Cache-Control: no-store, no-cache, must-revalidate'
);

if (
    $_SERVER['REQUEST_METHOD'] !==
    'POST'
) {
    header('Allow: POST');

    http_response_code(405);
    exit;
}

if (
    ($_SERVER[
        'HTTP_X_REQUESTED_WITH'
    ] ?? '') !==
    'XMLHttpRequest'
) {
    http_response_code(400);
    exit;
}

if (
    !empty(
        $_SESSION[
            'auth_session_expired'
        ]
    ) ||
    empty($_SESSION['user_id']) ||
    ($_SESSION['role'] ?? '') !==
        'customer'
) {
    http_response_code(401);
    exit;
}

/*
 * start_secure_session() has already refreshed
 * auth_last_activity_at for this valid request.
 */
http_response_code(204);
exit;