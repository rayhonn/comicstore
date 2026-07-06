<?php
/**
 * Unified auth helpers for MangaVault
 * Usage: require_once '../includes/auth.php'; at top of any protected page
 * Then call the appropriate require_*() function.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ─── Redirect helpers ────────────────────────────────────────────────────────

function redirect_to(string $url): void {
    header('Location: ' . $url);
    exit;
}

/**
 * Sanitize a redirect target — only allow relative paths, no http/https/protocol-relative.
 * Falls back to $default if invalid.
 */
function safe_redirect_target(string $target, string $default): string {
    $target = trim($target);
    // Must be a relative path: starts with alphanum, dot, or slash — no protocol
    if (preg_match('/^[a-zA-Z0-9_\-\.\/][a-zA-Z0-9_\-\.\/]*\.php$/', $target)) {
        return $target;
    }
    return $default;
}

// ─── Auth guards ─────────────────────────────────────────────────────────────

/**
 * Require customer login. Redirects to customer login if not authenticated.
 */
function require_customer(): void {
    if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'customer') {
        $current = urlencode($_SERVER['REQUEST_URI'] ?? '');
        redirect_to('/comicstore/customer/login.php?redirect=' . $current);
    }
}

/**
 * Require admin login. Redirects to admin login if not authenticated.
 */
function require_admin(): void {
    if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
        $current = urlencode($_SERVER['REQUEST_URI'] ?? '');
        redirect_to('/comicstore/admin/login.php?redirect=' . $current);
    }
}

/**
 * Require staff login. Redirects to admin login if not authenticated.
 */
function require_staff(): void {
    if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'staff') {
        redirect_to('/comicstore/admin/login.php');
    }
}

/**
 * Require admin OR staff. Useful for shared pages.
 */
function require_admin_or_staff(): void {
    $role = $_SESSION['role'] ?? '';
    if (empty($_SESSION['user_id']) || !in_array($role, ['admin', 'staff'])) {
        redirect_to('/comicstore/admin/login.php');
    }
}

/**
 * Require senior_admin specifically (for high-privilege procurement actions).
 * Must be called AFTER require_admin().
 */
function require_senior_admin(): void {
    require_admin();
    if (($_SESSION['admin_level'] ?? '') !== 'senior_admin') {
        http_response_code(403);
        die("Access denied: senior admin required.");
    }
}

/**
 * Require supplier login. Redirects to supplier login if not authenticated.
 */
function require_supplier(): void {
    if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'supplier') {
        redirect_to('/comicstore/supplier/login.php');
    }
}

// ─── Session helpers ──────────────────────────────────────────────────────────

/**
 * Call immediately after successful login to prevent session fixation.
 */
function regenerate_session(): void {
    session_regenerate_id(true);
}

/**
 * Destroy session cleanly (for logout).
 */
function destroy_session(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

// ─── Convenience getters ──────────────────────────────────────────────────────

function current_user_id(): int {
    return (int)($_SESSION['user_id'] ?? 0);
}

function current_role(): string {
    return $_SESSION['role'] ?? '';
}

function is_senior_admin(): bool {
    return ($_SESSION['admin_level'] ?? '') === 'senior_admin';
}