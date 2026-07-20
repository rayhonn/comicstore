<?php
require_once __DIR__ . '/config.php';
/**
 * Unified authentication and session helpers.
 *
 * Usage:
 * require_once __DIR__ . '/../includes/auth.php';
 * require_customer();
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function app_base_path(): string
{
    if (!defined('APP_URL')) {
        return '';
    }

    $path = parse_url(APP_URL, PHP_URL_PATH);

    if (!is_string($path) || $path === '' || $path === '/') {
        return '';
    }

    return '/' . trim($path, '/');
}

function app_path(string $path = ''): string
{
    $base = app_base_path();
    $path = ltrim($path, '/');

    if ($path === '') {
        return $base === '' ? '/' : $base . '/';
    }

    return $base . '/' . $path;
}

/**
 * Redirect to another page and stop the current script.
 */
function redirect_to(string $url): void
{
    header('Location: ' . $url);
    exit;
}

/**
 * Validate a redirect destination.
 *
 * Only approved internal PHP pages are accepted.
 * External URLs, protocol-relative URLs and directory traversal
 * attempts are rejected.
 */
function safe_redirect_target(string $target, string $default): string
{
    $target = trim($target);

    if ($target === '') {
        return $default;
    }

    // Reject control characters.
    if (preg_match('/[\x00-\x1F\x7F]/', $target)) {
        return $default;
    }

    // Reject absolute URLs such as https://example.com.
    if (preg_match('/^[a-z][a-z0-9+\-.]*:/i', $target)) {
        return $default;
    }

    // Reject protocol-relative URLs such as //example.com.
    if (str_starts_with($target, '//')) {
        return $default;
    }

    // Only use the path. Query strings and fragments are discarded.
    $path = parse_url($target, PHP_URL_PATH);

    if (!is_string($path) || $path === '') {
        return $default;
    }

    // Reject directory traversal.
    if (str_contains($path, '..') || str_contains($path, '\\')) {
        return $default;
    }

    /*
     * Remove the local project prefix so that both forms work:
     *
     * /comicstore/customer/orders.php
     * customer/orders.php
     */
    $path = ltrim($path, '/');

    $basePrefix = trim(app_base_path(), '/');

    if (
        $basePrefix !== '' &&
        str_starts_with($path, $basePrefix . '/')
    ) {
        $path = substr($path, strlen($basePrefix) + 1);
    }

    /**
     * Approved redirect destinations.
     *
     * Add a page here only when users genuinely need to return
     * to that page after logging in.
     */
    $allowedPaths = [
        'index.php',

        'customer/index.php',
        'customer/profile.php',
        'customer/cart.php',
        'customer/checkout.php',
        'customer/orders.php',
        'customer/order_history.php',
        'customer/vouchers.php',
        'customer/wishlist.php',
        'customer/addresses.php',

        'admin/index.php',
        'admin/dashboard.php',
        'admin/goods_received.php',

        'staff/index.php',
        'staff/dashboard.php',

        'supplier/index.php',
        'supplier/dashboard.php',
    ];

    if (!in_array($path, $allowedPaths, true)) {
        return $default;
    }

    /*
     * goods_received.php only accepts a positive numeric po_id.
     * Other redirect query parameters remain discarded.
     */
    if ($path === 'admin/goods_received.php') {
        $query = parse_url($target, PHP_URL_QUERY);

        if (!is_string($query)) {
            return $default;
        }

        parse_str($query, $queryParameters);

        $poId = filter_var(
            $queryParameters['po_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        if ($poId === false) {
            return $default;
        }

        return app_path($path) . '?po_id=' . $poId;
    }

    return app_path($path);
}

/**
 * Require a customer account.
 */
function require_customer(): void
{
    if (
        empty($_SESSION['user_id']) ||
        ($_SESSION['role'] ?? '') !== 'customer'
    ) {
        $currentPage = $_SERVER['REQUEST_URI'] ?? '';
        $redirect = urlencode($currentPage);

        // Customer login is located in the project root.
        redirect_to(app_path('login.php') . '?redirect=' . $redirect);
    }
}

/**
 * Require an administrator account.
 */
function require_admin(): void
{
    if (
        empty($_SESSION['user_id']) ||
        ($_SESSION['role'] ?? '') !== 'admin'
    ) {
        $currentPage = $_SERVER['REQUEST_URI'] ?? '';
        $redirect = urlencode($currentPage);

        redirect_to(app_path('admin/login.php') . '?redirect=' . $redirect);
    }
}

/**
 * Require a staff account.
 */
function require_staff(): void
{
    if (
        empty($_SESSION['user_id']) ||
        ($_SESSION['role'] ?? '') !== 'staff'
    ) {
        redirect_to(app_path('admin/login.php'));
    }
}

/**
 * Allow either an administrator or staff account.
 */
function require_admin_or_staff(): void
{
    $role = $_SESSION['role'] ?? '';

    if (
        empty($_SESSION['user_id']) ||
        !in_array($role, ['admin', 'staff'], true)
    ) {
        redirect_to(app_path('admin/login.php'));
    }
}

/**
 * Require a senior administrator.
 */
function require_senior_admin(): void
{
    require_admin();

    if (($_SESSION['admin_level'] ?? '') !== 'senior_admin') {
        http_response_code(403);
        exit('Access denied: senior administrator required.');
    }
}

/**
 * Require a supplier account.
 */
function require_supplier(): void
{
    if (
        empty($_SESSION['user_id']) ||
        ($_SESSION['role'] ?? '') !== 'supplier'
    ) {
        redirect_to(app_path('supplier/login.php'));
    }
}

/**
 * Regenerate the session ID after successful login.
 */
function regenerate_session(): void
{
    session_regenerate_id(true);
}

/**
 * Completely destroy the current session.
 */
function destroy_session(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $cookieParameters = session_get_cookie_params();

        setcookie(
            session_name(),
            '',
            time() - 3600,
            $cookieParameters['path'],
            $cookieParameters['domain'],
            $cookieParameters['secure'],
            $cookieParameters['httponly']
        );
    }

    session_destroy();
}

/**
 * Return the logged-in user ID.
 */
function current_user_id(): int
{
    return (int) ($_SESSION['user_id'] ?? 0);
}

/**
 * Return the logged-in account role.
 */
function current_role(): string
{
    return (string) ($_SESSION['role'] ?? '');
}

/**
 * Check whether the logged-in administrator is a senior administrator.
 */
function is_senior_admin(): bool
{
    return (
        ($_SESSION['role'] ?? '') === 'admin' &&
        ($_SESSION['admin_level'] ?? '') === 'senior_admin'
    );
}