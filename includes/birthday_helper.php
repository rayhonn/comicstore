<?php

/**
 * Award the customer's configured annual birthday bonus once per year.
 *
 * The bonus becomes eligible on the customer's birthday. If the customer
 * does not visit MangaVault on that exact date, it is awarded on the first
 * eligible visit later in the same calendar year. Accounts created after
 * that year's birthday become eligible from the following year.
 */
function awardAnnualBirthdayBonus(
    PDO $pdo,
    int $userId
): int {
    if ($userId < 1) {
        return 0;
    }

    $timezone = new DateTimeZone(
        'Asia/Kuala_Lumpur'
    );
    $today = new DateTimeImmutable(
        'today',
        $timezone
    );
    $currentYear = (int) $today->format('Y');
    $logDescription =
        'Annual birthday bonus ' . $currentYear;
    $ownsTransaction = !$pdo->inTransaction();

    try {
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        $userStatement = $pdo->prepare("
            SELECT
                user_dob,
                user_tier,
                user_created_at
            FROM users
            WHERE user_id = ?
            AND user_role = 'customer'
            AND user_is_active = 1
            LIMIT 1
            FOR UPDATE
        ");
        $userStatement->execute([
            $userId,
        ]);

        $user = $userStatement->fetch(
            PDO::FETCH_ASSOC
        );

        if (!$user || empty($user['user_dob'])) {
            if ($ownsTransaction) {
                $pdo->commit();
            }

            return 0;
        }

        $dateOfBirth =
            DateTimeImmutable::createFromFormat(
                '!Y-m-d',
                (string) $user['user_dob'],
                $timezone
            );
        $dateErrors =
            DateTimeImmutable::getLastErrors();

        if (
            !$dateOfBirth ||
            (
                is_array($dateErrors) &&
                (
                    $dateErrors['warning_count'] > 0 ||
                    $dateErrors['error_count'] > 0
                )
            )
        ) {
            if ($ownsTransaction) {
                $pdo->commit();
            }

            return 0;
        }

        $birthdayMonth =
            (int) $dateOfBirth->format('m');
        $birthdayDay =
            (int) $dateOfBirth->format('d');

        if (
            $birthdayMonth === 2 &&
            $birthdayDay === 29 &&
            !checkdate(2, 29, $currentYear)
        ) {
            $birthdayDay = 28;
        }

        $birthdayThisYear =
            DateTimeImmutable::createFromFormat(
                '!Y-n-j',
                $currentYear . '-' .
                    $birthdayMonth . '-' .
                    $birthdayDay,
                $timezone
            );

        if (
            !$birthdayThisYear ||
            $today < $birthdayThisYear
        ) {
            if ($ownsTransaction) {
                $pdo->commit();
            }

            return 0;
        }

        if (!empty($user['user_created_at'])) {
            $createdAt = new DateTimeImmutable(
                (string) $user['user_created_at'],
                $timezone
            );

            if (
                $createdAt->setTime(0, 0) >
                $birthdayThisYear
            ) {
                if ($ownsTransaction) {
                    $pdo->commit();
                }

                return 0;
            }
        }

        $existingAward = $pdo->prepare("
            SELECT log_id
            FROM points_log
            WHERE log_user_id = ?
            AND log_type = 'earn'
            AND log_order_id IS NULL
            AND log_description = ?
            LIMIT 1
        ");
        $existingAward->execute([
            $userId,
            $logDescription,
        ]);

        if ($existingAward->fetchColumn() !== false) {
            if ($ownsTransaction) {
                $pdo->commit();
            }

            return 0;
        }

        $tierName = strtolower(
            trim(
                (string) (
                    $user['user_tier'] ??
                    'bronze'
                )
            )
        );

        $tierStatement = $pdo->prepare("
            SELECT tier_birthday_bonus_points
            FROM tier_config
            WHERE tier_name = ?
            LIMIT 1
        ");
        $tierStatement->execute([
            $tierName,
        ]);

        $bonusPoints = max(
            0,
            (int) $tierStatement->fetchColumn()
        );

        if ($bonusPoints === 0) {
            if ($ownsTransaction) {
                $pdo->commit();
            }

            return 0;
        }

        $updatePoints = $pdo->prepare("
            UPDATE users
            SET user_points = user_points + ?
            WHERE user_id = ?
            AND user_role = 'customer'
            AND user_is_active = 1
        ");
        $updatePoints->execute([
            $bonusPoints,
            $userId,
        ]);

        if ($updatePoints->rowCount() !== 1) {
            throw new RuntimeException(
                'Birthday bonus customer update failed.'
            );
        }

        $insertLog = $pdo->prepare("
            INSERT INTO points_log (
                log_user_id,
                log_points,
                log_type,
                log_description,
                log_order_id
            )
            VALUES (?, ?, 'earn', ?, NULL)
        ");
        $insertLog->execute([
            $userId,
            $bonusPoints,
            $logDescription,
        ]);

        $tierLabel = ucfirst($tierName);
        $insertNotification = $pdo->prepare("
            INSERT INTO notifications (
                notif_user_id,
                notif_title,
                notif_message,
                notif_type
            )
            VALUES (?, ?, ?, 'promo')
        ");
        $insertNotification->execute([
            $userId,
            'Birthday Bonus Added',
            'Your annual ' .
                $tierLabel .
                ' birthday bonus added ' .
                number_format($bonusPoints) .
                ' points to your account.',
        ]);

        if ($ownsTransaction) {
            $pdo->commit();
        }

        return $bonusPoints;
    } catch (Throwable $exception) {
        if (
            $ownsTransaction &&
            $pdo->inTransaction()
        ) {
            $pdo->rollBack();
        }

        throw $exception;
    }
}