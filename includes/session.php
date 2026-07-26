<?php

require_once __DIR__ . '/logger.php';

/**
 * Determine whether the current request is using HTTPS.
 *
 * Forwarded headers are intentionally not trusted because the
 * application has no configured trusted reverse proxy.
 */
function app_request_uses_https(): bool
{
    $https = strtolower(
        trim((string) ($_SERVER['HTTPS'] ?? ''))
    );

    return (
        $https !== '' &&
        $https !== 'off' &&
        $https !== '0'
    ) || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
}

/**
 * Return the shared session cookie parameters.
 */
function app_session_cookie_parameters(): array
{
    return [
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => app_request_uses_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

/**
 * Reissue an active session cookie with the secure attributes.
 */
function app_refresh_session_cookie(): void
{
    if (
        session_status() !== PHP_SESSION_ACTIVE ||
        !ini_get('session.use_cookies') ||
        headers_sent()
    ) {
        return;
    }

    $parameters =
        app_session_cookie_parameters();

    setcookie(
        session_name(),
        session_id(),
        [
            'expires' => 0,
            'path' => $parameters['path'],
            'domain' => $parameters['domain'],
            'secure' => $parameters['secure'],
            'httponly' => $parameters['httponly'],
            'samesite' => $parameters['samesite'],
        ]
    );
}

/**
 * Start the application session with secure cookie settings.
 */
function start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        app_refresh_session_cookie();
        return;
    }

    if (session_status() === PHP_SESSION_DISABLED) {
        app_log(
            'CRITICAL',
            'Session',
            'PHP sessions are disabled.'
        );

        http_response_code(500);

        exit(
            'A session error occurred. ' .
            'Please try again later.'
        );
    }

    if (headers_sent($file, $line)) {
        app_log(
            'CRITICAL',
            'Session',
            'Headers were already sent before session start.',
            [
                'file' => $file,
                'line' => $line,
            ]
        );

        http_response_code(500);

        exit(
            'A session error occurred. ' .
            'Please try again later.'
        );
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');

    session_set_cookie_params(
        app_session_cookie_parameters()
    );

    if (!session_start()) {
        app_log(
            'CRITICAL',
            'Session',
            'Unable to start the PHP session.'
        );

        http_response_code(500);

        exit(
            'A session error occurred. ' .
            'Please try again later.'
        );
    }

    app_refresh_session_cookie();
}