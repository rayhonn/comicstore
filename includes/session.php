<?php

require_once __DIR__ . '/logger.php';

const APP_SESSION_IDLE_TIMEOUT_SECONDS = 900;
const APP_EXTERNAL_FLOW_GRACE_MAX_SECONDS = 2400;

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

    if ($role === 'bank') {
        unset(
            $_SESSION['user_id'],
            $_SESSION['user_name'],
            $_SESSION['user_first_name'],
            $_SESSION['supplier_id'],
            $_SESSION['supplier_name'],
            $_SESSION['admin_level']
        );

        return;
    }

    if ($role === 'supplier') {
        unset(
            $_SESSION['user_id'],
            $_SESSION['user_name'],
            $_SESSION['user_first_name'],
            $_SESSION['admin_level'],
            $_SESSION['bank_operator_id'],
            $_SESSION['bank_operator_code'],
            $_SESSION['bank_operator_name'],
            $_SESSION['bank_operator_bank_name']
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
            $_SESSION['supplier_name'],
            $_SESSION['bank_operator_id'],
            $_SESSION['bank_operator_code'],
            $_SESSION['bank_operator_name'],
            $_SESSION['bank_operator_bank_name']
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
        $_SESSION['bank_operator_id'],
        $_SESSION['bank_operator_code'],
        $_SESSION['bank_operator_name'],
        $_SESSION['bank_operator_bank_name'],
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

    if ($role === 'bank') {
        return (
            !empty($_SESSION['bank_operator_id']) &&
            !empty($_SESSION['bank_operator_code'])
        );
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

    if (
        ($_SESSION['role'] ?? '') === 'bank'
    ) {
        return (int) (
            $_SESSION['bank_operator_id'] ?? 0
        );
    }

    return (int) ($_SESSION['user_id'] ?? 0);
}

/**
 * Return the request path without query parameters.
 */
function app_session_request_path(): string
{
    $requestUri = (string) (
        $_SERVER['REQUEST_URI'] ?? ''
    );

    $path = parse_url(
        $requestUri,
        PHP_URL_PATH
    );

    return is_string($path)
        ? '/' . ltrim($path, '/')
        : '';
}

/**
 * Determine whether the current request is an approved return from
 * an external Stripe Checkout flow.
 */
function app_is_external_flow_return_request(): bool
{
    $requestPath = app_session_request_path();

    foreach (
        [
            '/customer/payment_success.php',
            '/customer/payment_cancel.php',
            '/customer/wallet_topup_success.php',
            '/customer/wallet_topup_cancel.php',
        ] as $allowedSuffix
    ) {
        if (
            str_ends_with(
                $requestPath,
                $allowedSuffix
            )
        ) {
            return true;
        }
    }

    return false;
}

/**
 * Temporarily allow one approved Stripe return request to resume the
 * authenticated session after the normal idle limit.
 */
function app_begin_external_auth_flow(
    int $externalExpiresAt
): void {
    if (
        session_status() !== PHP_SESSION_ACTIVE ||
        !app_session_is_authenticated()
    ) {
        return;
    }

    $now = time();

    $_SESSION['auth_external_flow_until'] = min(
        $now + APP_EXTERNAL_FLOW_GRACE_MAX_SECONDS,
        max(
            $now + 60,
            $externalExpiresAt + 300
        )
    );
}

/**
 * Remove the temporary Stripe return allowance.
 */
function app_clear_external_auth_flow(): void
{
    unset(
        $_SESSION['auth_external_flow_until']
    );
}

/**
 * Determine whether an approved Stripe return may resume this session.
 */
function app_can_resume_external_auth_flow(
    int $now
): bool {
    $graceUntil = (int) (
        $_SESSION['auth_external_flow_until'] ?? 0
    );

    return (
        $graceUntil >= $now &&
        app_is_external_flow_return_request()
    );
}

/**
 * Expire authenticated sessions after 15 minutes of inactivity.
 */
function app_enforce_session_idle_timeout(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    if (!app_session_is_authenticated()) {
        unset(
            $_SESSION['auth_last_activity_at'],
            $_SESSION['auth_external_flow_until']
        );
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
        if (
            app_can_resume_external_auth_flow(
                $now
            )
        ) {
            $_SESSION['auth_last_activity_at'] =
                $now;

            app_clear_external_auth_flow();

            app_log(
                'INFO',
                'Session',
                'Session resumed from an approved external payment return.',
                [
                    'account_id' =>
                        app_session_account_id(),
                    'role' =>
                        (string) $_SESSION['role'],
                ]
            );

            return;
        }

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
