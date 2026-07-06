<?php
/**
 * Load .env file into PHP constants.
 * Place .env in project root (C:\xampp\htdocs\comicstore\.env)
 * Never commit .env to Git.
 */
function load_env(string $path): void {
    if (!file_exists($path)) {
        error_log('[Config] .env file not found at: ' . $path);
        return;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        if (!str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim($value);
        if (!defined($key)) {
            define($key, $value);
        }
    }
}

// Root = one level up from includes/
load_env(dirname(__DIR__) . '/.env');