<?php

const LOGIN_RATE_LIMIT_WINDOW_MINUTES = 15;
const LOGIN_RATE_LIMIT_IDENTIFIER_MAX = 5;
const LOGIN_RATE_LIMIT_IP_MAX = 20;

/**
 * Normalize and hash a login identifier.
 *
 * Raw usernames and email addresses are not stored in the
 * login-attempt table.
 */
function login_rate_limit_identifier_hash(
    string $scope,
    string $identifier
): string {
    $normalized_identifier = strtolower(
        trim($identifier)
    );

    return hash(
        'sha256',
        $scope . '|' . $normalized_identifier
    );
}

/**
 * Return a hash of the direct client IP address.
 *
 * X-Forwarded-For is intentionally not trusted because the
 * application has no configured trusted reverse proxy.
 */
function login_rate_limit_ip_hash(): string
{
    $ip_address = (string) (
        $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    );

    return hash('sha256', $ip_address);
}

/**
 * Check whether the identifier or IP has exceeded the limit.
 */
function login_rate_limit_status(
    PDO $pdo,
    string $scope,
    string $identifier
): array {
    $identifier_hash =
        login_rate_limit_identifier_hash(
            $scope,
            $identifier
        );

    $ip_hash = login_rate_limit_ip_hash();

    try {
        $statement = $pdo->prepare("
            SELECT
                SUM(
                    CASE
                        WHEN attempt_identifier_hash = ?
                        THEN 1
                        ELSE 0
                    END
                ) AS identifier_attempts,
                SUM(
                    CASE
                        WHEN attempt_ip_hash = ?
                        THEN 1
                        ELSE 0
                    END
                ) AS ip_attempts
            FROM auth_login_attempts
            WHERE attempt_scope = ?
            AND attempt_created_at >=
                UTC_TIMESTAMP() - INTERVAL 15 MINUTE
        ");

        $statement->execute([
            $identifier_hash,
            $ip_hash,
            $scope,
        ]);

        $result =
            $statement->fetch(PDO::FETCH_ASSOC) ?: [];

        $identifier_attempts = (int) (
            $result['identifier_attempts'] ?? 0
        );

        $ip_attempts = (int) (
            $result['ip_attempts'] ?? 0
        );

        return [
            'blocked' =>
                $identifier_attempts >=
                    LOGIN_RATE_LIMIT_IDENTIFIER_MAX ||
                $ip_attempts >=
                    LOGIN_RATE_LIMIT_IP_MAX,
            'identifier_attempts' =>
                $identifier_attempts,
            'ip_attempts' => $ip_attempts,
        ];
    } catch (Throwable $exception) {
        app_log_exception(
            'LoginRateLimit',
            $exception,
            [
                'operation' => 'check',
                'scope' => $scope,
            ]
        );

        /*
         * Fail open so a temporary logging-table problem does
         * not lock every user out of the application.
         */
        return [
            'blocked' => false,
            'identifier_attempts' => 0,
            'ip_attempts' => 0,
        ];
    }
}

/**
 * Record one failed login attempt.
 */
function login_rate_limit_record_failure(
    PDO $pdo,
    string $scope,
    string $identifier
): void {
    $identifier_hash =
        login_rate_limit_identifier_hash(
            $scope,
            $identifier
        );

    $ip_hash = login_rate_limit_ip_hash();

    try {
        $cleanup = $pdo->prepare("
            DELETE FROM auth_login_attempts
            WHERE attempt_created_at <
                UTC_TIMESTAMP() - INTERVAL 1 DAY
        ");
        $cleanup->execute();

        $insert = $pdo->prepare("
            INSERT INTO auth_login_attempts (
                attempt_scope,
                attempt_identifier_hash,
                attempt_ip_hash,
                attempt_created_at
            )
            VALUES (?, ?, ?, UTC_TIMESTAMP())
        ");

        $insert->execute([
            $scope,
            $identifier_hash,
            $ip_hash,
        ]);
    } catch (Throwable $exception) {
        app_log_exception(
            'LoginRateLimit',
            $exception,
            [
                'operation' => 'record_failure',
                'scope' => $scope,
            ]
        );
    }
}

/**
 * Clear failed attempts for an identifier after successful login.
 *
 * IP records are not cleared because the same IP may still be
 * attempting attacks against other accounts.
 */
function login_rate_limit_clear_identifier(
    PDO $pdo,
    string $scope,
    string $identifier
): void {
    $identifier_hash =
        login_rate_limit_identifier_hash(
            $scope,
            $identifier
        );

    try {
        $statement = $pdo->prepare("
            DELETE FROM auth_login_attempts
            WHERE attempt_scope = ?
            AND attempt_identifier_hash = ?
        ");

        $statement->execute([
            $scope,
            $identifier_hash,
        ]);
    } catch (Throwable $exception) {
        app_log_exception(
            'LoginRateLimit',
            $exception,
            [
                'operation' => 'clear_identifier',
                'scope' => $scope,
            ]
        );
    }
}