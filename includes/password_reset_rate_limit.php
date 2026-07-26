<?php

const PASSWORD_RESET_RATE_LIMIT_WINDOW_MINUTES = 15;
const PASSWORD_RESET_RATE_LIMIT_IDENTIFIER_MAX = 3;
const PASSWORD_RESET_RATE_LIMIT_IP_MAX = 10;

/**
 * Normalize and hash a password-reset email identifier.
 *
 * Raw email addresses are not stored in the rate-limit table.
 */
function password_reset_rate_limit_identifier_hash(
    string $email
): string {
    return hash(
        'sha256',
        strtolower(trim($email))
    );
}

/**
 * Return a hash of the direct client IP address.
 *
 * X-Forwarded-For is intentionally not trusted because the
 * application has no configured trusted reverse proxy.
 */
function password_reset_rate_limit_ip_hash(): string
{
    $ip_address = (string) (
        $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    );

    return hash('sha256', $ip_address);
}

/**
 * Consume one request from a single atomic rate-limit bucket.
 *
 * The caller must already have an active transaction.
 */
function password_reset_rate_limit_consume_bucket(
    PDO $pdo,
    string $type,
    string $key
): int {
    $window_minutes =
        PASSWORD_RESET_RATE_LIMIT_WINDOW_MINUTES;

    $insert = $pdo->prepare("
        INSERT IGNORE INTO auth_password_reset_rate_limits (
            rate_limit_type,
            rate_limit_key,
            rate_limit_window_started_at,
            rate_limit_request_count,
            rate_limit_updated_at
        )
        VALUES (?, ?, UTC_TIMESTAMP(), 0, UTC_TIMESTAMP())
    ");

    $insert->execute([
        $type,
        $key,
    ]);

    $select = $pdo->prepare("
        SELECT
            rate_limit_request_count,
            CASE
                WHEN rate_limit_window_started_at <=
                    UTC_TIMESTAMP() -
                    INTERVAL {$window_minutes} MINUTE
                THEN 1
                ELSE 0
            END AS window_expired
        FROM auth_password_reset_rate_limits
        WHERE rate_limit_type = ?
        AND rate_limit_key = ?
        FOR UPDATE
    ");

    $select->execute([
        $type,
        $key,
    ]);

    $bucket = $select->fetch(PDO::FETCH_ASSOC);

    if (!$bucket) {
        throw new RuntimeException(
            'Unable to load password reset rate-limit bucket.'
        );
    }

    $window_expired =
        (int) $bucket['window_expired'] === 1;

    if ($window_expired) {
        $update = $pdo->prepare("
            UPDATE auth_password_reset_rate_limits
            SET
                rate_limit_window_started_at =
                    UTC_TIMESTAMP(),
                rate_limit_request_count = 1,
                rate_limit_updated_at = UTC_TIMESTAMP()
            WHERE rate_limit_type = ?
            AND rate_limit_key = ?
        ");

        $update->execute([
            $type,
            $key,
        ]);

        return 1;
    }

    $update = $pdo->prepare("
        UPDATE auth_password_reset_rate_limits
        SET
            rate_limit_request_count =
                rate_limit_request_count + 1,
            rate_limit_updated_at = UTC_TIMESTAMP()
        WHERE rate_limit_type = ?
        AND rate_limit_key = ?
    ");

    $update->execute([
        $type,
        $key,
    ]);

    return (int) $bucket['rate_limit_request_count'] + 1;
}

/**
 * Remove inactive rate-limit buckets without affecting the
 * current request if cleanup fails.
 */
function password_reset_rate_limit_cleanup(PDO $pdo): void
{
    try {
        $cleanup = $pdo->prepare("
            DELETE FROM auth_password_reset_rate_limits
            WHERE rate_limit_updated_at <
                UTC_TIMESTAMP() - INTERVAL 1 DAY
            LIMIT 500
        ");

        $cleanup->execute();
    } catch (Throwable $exception) {
        app_log_exception(
            'PasswordResetRateLimit',
            $exception,
            ['operation' => 'cleanup']
        );
    }
}

/**
 * Record a password-reset request and return its limit status.
 *
 * Every valid request is counted, including requests for email
 * addresses that are not registered in the application.
 */
function password_reset_rate_limit_consume(
    PDO $pdo,
    string $email
): array {
    $identifier_hash =
        password_reset_rate_limit_identifier_hash($email);

    $ip_hash = password_reset_rate_limit_ip_hash();

    try {
        $pdo->beginTransaction();

        $identifier_requests =
            password_reset_rate_limit_consume_bucket(
                $pdo,
                'identifier',
                $identifier_hash
            );

        $ip_requests =
            password_reset_rate_limit_consume_bucket(
                $pdo,
                'ip',
                $ip_hash
            );

        $pdo->commit();

        $result = [
            'blocked' =>
                $identifier_requests >
                    PASSWORD_RESET_RATE_LIMIT_IDENTIFIER_MAX ||
                $ip_requests >
                    PASSWORD_RESET_RATE_LIMIT_IP_MAX,
            'identifier_requests' => $identifier_requests,
            'ip_requests' => $ip_requests,
        ];

        password_reset_rate_limit_cleanup($pdo);

        return $result;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        app_log_exception(
            'PasswordResetRateLimit',
            $exception,
            ['operation' => 'consume']
        );

        /*
         * Fail open so a temporary rate-limit table problem does
         * not disable password recovery for every customer.
         */
        return [
            'blocked' => false,
            'identifier_requests' => 0,
            'ip_requests' => 0,
        ];
    }
}