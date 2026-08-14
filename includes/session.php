<?php

require_once __DIR__ . '/logger.php';

const APP_SESSION_IDLE_TIMEOUT_SECONDS = 1800;

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
 * Remove session identity fields that do not belong to the
 * currently authenticated account type.
 */
function app_normalize_session_identity(): void
{
    $role = (string) ($_SESSION['role'] ?? '');

    if ($role === 'supplier') {
        unset(
            $_SESSION['user_id'],
            $_SESSION['user_name'],
            $_SESSION['user_first_name'],
            $_SESSION['admin_level']
        );

        return;
    }

    if (
        in_array(
            $role,
            [
                'customer',
                'admin',
                'staff',
            ],
            true
        )
    ) {
        unset(
            $_SESSION['supplier_id'],
            $_SESSION['supplier_name']
        );

        if ($role !== 'admin') {
            unset($_SESSION['admin_level']);
        }

        return;
    }

    unset(
        $_SESSION['user_id'],
        $_SESSION['user_name'],
        $_SESSION['user_first_name'],
        $_SESSION['supplier_id'],
        $_SESSION['supplier_name'],
        $_SESSION['admin_level'],
        $_SESSION['role']
    );
}

/**
 * Determine whether the session contains an authenticated account.
 */
function app_session_is_authenticated(): bool
{
    $role = (string) ($_SESSION['role'] ?? '');

    if ($role === 'supplier') {
        return !empty($_SESSION['supplier_id']);
    }

    return (
        !empty($_SESSION['user_id']) &&
        in_array(
            $role,
            [
                'customer',
                'admin',
                'staff',
            ],
            true
        )
    );
}

/**
 * Return the authenticated account ID for logging.
 */
function app_session_account_id(): int
{
    if (
        ($_SESSION['role'] ?? '') === 'supplier'
    ) {
        return (int) (
            $_SESSION['supplier_id'] ?? 0
        );
    }

    return (int) ($_SESSION['user_id'] ?? 0);
}

/**
 * Expire authenticated sessions after 30 minutes of inactivity.
 */
function app_enforce_session_idle_timeout(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    if (!app_session_is_authenticated()) {
        unset($_SESSION['auth_last_activity_at']);
        return;
    }

    $now = time();

    $lastActivity = (int) (
        $_SESSION['auth_last_activity_at'] ?? 0
    );

    if (
        $lastActivity > 0 &&
        ($now - $lastActivity) >=
            APP_SESSION_IDLE_TIMEOUT_SECONDS
    ) {
        $accountId = app_session_account_id();
        $role = (string) $_SESSION['role'];

        $_SESSION = [];

        if (!session_regenerate_id(true)) {
            app_log(
                'ERROR',
                'Session',
                'Unable to regenerate an expired session ID.',
                [
                    'account_id' => $accountId,
                    'role' => $role,
                ]
            );

            session_destroy();
            return;
        }

        $_SESSION[
            'auth_session_expired'
        ] = true;

        $_SESSION[
            'auth_expired_role'
        ] = $role;

        $_SESSION[
            'auth_expired_at'
        ] = $now;

        app_log(
            'INFO',
            'Session',
            'Authenticated session expired after inactivity.',
            [
                'account_id' => $accountId,
                'role' => $role,
                'idle_timeout_seconds' =>
                    APP_SESSION_IDLE_TIMEOUT_SECONDS,
            ]
        );

        return;
    }

    $_SESSION['auth_last_activity_at'] = $now;
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
        app_normalize_session_identity();
        app_enforce_session_idle_timeout();
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

    app_normalize_session_identity();
    app_enforce_session_idle_timeout();
    app_refresh_session_cookie();
}