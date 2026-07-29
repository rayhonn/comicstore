<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) .
    '/includes/birthday_helper.php';

date_default_timezone_set(
    'Asia/Kuala_Lumpur'
);

$timezone = new DateTimeZone(
    'Asia/Kuala_Lumpur'
);
$runAt = new DateTimeImmutable(
    'now',
    $timezone
);

$month = (int) $runAt->format('m');
$day = (int) $runAt->format('d');
$year = (int) $runAt->format('Y');

$sql = "
    SELECT user_id
    FROM users
    WHERE user_role = 'customer'
    AND user_is_active = 1
    AND user_dob IS NOT NULL
    AND (
        (
            MONTH(user_dob) = ?
            AND DAY(user_dob) = ?
        )
";

$params = [
    $month,
    $day,
];

if (
    $month === 2 &&
    $day === 28 &&
    !checkdate(2, 29, $year)
) {
    $sql .= "
        OR (
            MONTH(user_dob) = 2
            AND DAY(user_dob) = 29
        )
    ";
}

$sql .= "
    )
    ORDER BY user_id
";

$statement = $pdo->prepare($sql);
$statement->execute($params);

$processed = 0;
$failed = 0;

foreach (
    $statement->fetchAll(PDO::FETCH_COLUMN)
    as $userId
) {
    try {
        awardAnnualBirthdayBonus(
            $pdo,
            (int) $userId,
            $runAt
        );

        $processed++;
    } catch (Throwable $exception) {
        $failed++;

        error_log(
            'Birthday reward failed for user ' .
            (int) $userId .
            ': ' .
            $exception->getMessage()
        );
    }
}

echo sprintf(
    "[%s] Birthday rewards processed: %d; failed: %d%s",
    $runAt->format('Y-m-d H:i:s'),
    $processed,
    $failed,
    PHP_EOL
);

exit($failed > 0 ? 1 : 0);
