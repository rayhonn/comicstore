<?php

/**
 * Award the customer's annual birthday points and configured birthday voucher.
 *
 * This function is idempotent per customer and calendar year. It can be
 * called by the midnight CLI task and by normal customer requests as a
 * fallback when the scheduled task was missed.
 */
function awardAnnualBirthdayBonus(
    PDO $pdo,
    int $userId,
    ?DateTimeImmutable $runAt = null
): int {
    if ($userId < 1) {
        return 0;
    }

    $timezone = new DateTimeZone(
        'Asia/Kuala_Lumpur'
    );

    $now = $runAt === null
        ? new DateTimeImmutable('now', $timezone)
        : $runAt->setTimezone($timezone);

    $today = $now->setTime(0, 0);
    $currentYear = (int) $today->format('Y');
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

        $tierName = strtolower(
            trim(
                (string) (
                    $user['user_tier'] ??
                    'bronze'
                )
            )
        );

        $tierStatement = $pdo->prepare("
            SELECT
                tier_birthday_bonus_points,
                tier_birthday_voucher_id,
                tier_birthday_voucher_valid_days
            FROM tier_config
            WHERE tier_name = ?
            LIMIT 1
        ");
        $tierStatement->execute([
            $tierName,
        ]);

        $tierConfig = $tierStatement->fetch(
            PDO::FETCH_ASSOC
        );

        if (!$tierConfig) {
            if ($ownsTransaction) {
                $pdo->commit();
            }

            return 0;
        }

        $awardStatement = $pdo->prepare("
            SELECT
                birthday_award_id,
                birthday_award_points,
                birthday_award_template_voucher_id,
                birthday_award_generated_voucher_id
            FROM birthday_reward_awards
            WHERE birthday_award_user_id = ?
            AND birthday_award_year = ?
            LIMIT 1
            FOR UPDATE
        ");
        $awardStatement->execute([
            $userId,
            $currentYear,
        ]);

        $existingAward = $awardStatement->fetch(
            PDO::FETCH_ASSOC
        );

        $configuredPoints = max(
            0,
            (int) (
                $tierConfig[
                    'tier_birthday_bonus_points'
                ] ?? 0
            )
        );

        $existingPoints = max(
            0,
            (int) (
                $existingAward[
                    'birthday_award_points'
                ] ?? 0
            )
        );

        $newPoints = 0;

        if (
            $existingPoints === 0 &&
            $configuredPoints > 0
        ) {
            $updatePoints = $pdo->prepare("
                UPDATE users
                SET user_points = user_points + ?
                WHERE user_id = ?
                AND user_role = 'customer'
                AND user_is_active = 1
            ");
            $updatePoints->execute([
                $configuredPoints,
                $userId,
            ]);

            if ($updatePoints->rowCount() !== 1) {
                throw new RuntimeException(
                    'Birthday points update failed.'
                );
            }

            $logDescription =
                'Annual birthday bonus ' .
                $currentYear;

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
                $configuredPoints,
                $logDescription,
            ]);

            $newPoints = $configuredPoints;
        }

        $existingGeneratedVoucherId =
            $existingAward[
                'birthday_award_generated_voucher_id'
            ] ?? null;

        $templateVoucherId = filter_var(
            $tierConfig[
                'tier_birthday_voucher_id'
            ] ?? null,
            FILTER_VALIDATE_INT,
            [
                'options' => [
                    'min_range' => 1,
                ],
            ]
        );

        if ($templateVoucherId === false) {
            $templateVoucherId = null;
        }

        $generatedVoucherId = null;
        $generatedVoucherCode = null;
        $generatedVoucherExpiry = null;

        if (
            $existingGeneratedVoucherId === null &&
            $templateVoucherId !== null
        ) {
            $templateStatement = $pdo->prepare("
                SELECT
                    voucher_id,
                    voucher_type,
                    voucher_value,
                    voucher_min_order,
                    voucher_max_discount,
                    voucher_start_date,
                    voucher_end_date
                FROM vouchers
                WHERE voucher_id = ?
                AND voucher_is_active = 1
                AND voucher_is_points_redeem = 0
                AND voucher_is_system_generated = 0
                AND (
                    voucher_start_date IS NULL
                    OR voucher_start_date <= ?
                )
                AND (
                    voucher_end_date IS NULL
                    OR voucher_end_date >= ?
                )
                LIMIT 1
                FOR UPDATE
            ");
            $formattedNow =
                $now->format('Y-m-d H:i:s');

            $templateStatement->execute([
                $templateVoucherId,
                $formattedNow,
                $formattedNow,
            ]);

            $template = $templateStatement->fetch(
                PDO::FETCH_ASSOC
            );

            if ($template) {
                $validDays = max(
                    1,
                    min(
                        365,
                        (int) (
                            $tierConfig[
                                'tier_birthday_voucher_valid_days'
                            ] ?? 30
                        )
                    )
                );

                $expiry = $now
                    ->modify(
                        '+' . $validDays . ' days'
                    )
                    ->setTime(23, 59, 59);

                if (
                    !empty(
                        $template[
                            'voucher_end_date'
                        ]
                    )
                ) {
                    $templateExpiry =
                        new DateTimeImmutable(
                            (string) $template[
                                'voucher_end_date'
                            ],
                            $timezone
                        );

                    if ($templateExpiry < $expiry) {
                        $expiry = $templateExpiry;
                    }
                }

                if ($expiry >= $now) {
                    $generatedVoucherCode =
                        'BDAY' .
                        $currentYear .
                        'U' .
                        $userId .
                        'V' .
                        $templateVoucherId;

                    $insertGeneratedVoucher =
                        $pdo->prepare("
                            INSERT INTO vouchers (
                                voucher_code,
                                voucher_type,
                                voucher_value,
                                voucher_min_order,
                                voucher_max_discount,
                                voucher_usage_limit,
                                voucher_used_count,
                                voucher_start_date,
                                voucher_end_date,
                                voucher_is_active,
                                voucher_points_required,
                                voucher_is_points_redeem,
                                voucher_template_id,
                                voucher_is_system_generated
                            )
                            VALUES (
                                ?, ?, ?, ?, ?, 1, 0,
                                ?, ?, 1, 0, 0, ?, 1
                            )
                        ");

                    $insertGeneratedVoucher->execute([
                        $generatedVoucherCode,
                        $template['voucher_type'],
                        $template['voucher_value'],
                        $template['voucher_min_order'],
                        $template[
                            'voucher_max_discount'
                        ],
                        $formattedNow,
                        $expiry->format(
                            'Y-m-d H:i:s'
                        ),
                        $templateVoucherId,
                    ]);

                    $generatedVoucherId =
                        (int) $pdo->lastInsertId();

                    if ($generatedVoucherId < 1) {
                        throw new RuntimeException(
                            'Birthday voucher creation failed.'
                        );
                    }

                    $assignVoucher = $pdo->prepare("
                        INSERT INTO user_vouchers (
                            uv_user_id,
                            uv_voucher_id,
                            uv_expires_at
                        )
                        VALUES (?, ?, ?)
                    ");
                    $assignVoucher->execute([
                        $userId,
                        $generatedVoucherId,
                        $expiry->format(
                            'Y-m-d H:i:s'
                        ),
                    ]);

                    $generatedVoucherExpiry =
                        $expiry;
                }
            }
        }

        $hasExistingAward =
            $existingAward !== false;

        $finalPoints =
            $existingPoints > 0
                ? $existingPoints
                : $newPoints;

        $finalTemplateVoucherId =
            $existingAward[
                'birthday_award_template_voucher_id'
            ] ??
            (
                $generatedVoucherId !== null
                    ? $templateVoucherId
                    : null
            );

        $finalGeneratedVoucherId =
            $existingGeneratedVoucherId ??
            $generatedVoucherId;

        if (
            !$hasExistingAward &&
            $finalPoints === 0 &&
            $finalGeneratedVoucherId === null
        ) {
            if ($ownsTransaction) {
                $pdo->commit();
            }

            return 0;
        }

        if ($hasExistingAward) {
            $updateAward = $pdo->prepare("
                UPDATE birthday_reward_awards
                SET birthday_award_tier = ?,
                    birthday_award_points = ?,
                    birthday_award_template_voucher_id = ?,
                    birthday_award_generated_voucher_id = ?,
                    birthday_award_updated_at = NOW()
                WHERE birthday_award_id = ?
            ");
            $updateAward->execute([
                $tierName,
                $finalPoints,
                $finalTemplateVoucherId,
                $finalGeneratedVoucherId,
                (int) $existingAward[
                    'birthday_award_id'
                ],
            ]);
        } else {
            $insertAward = $pdo->prepare("
                INSERT INTO birthday_reward_awards (
                    birthday_award_user_id,
                    birthday_award_year,
                    birthday_award_tier,
                    birthday_award_points,
                    birthday_award_template_voucher_id,
                    birthday_award_generated_voucher_id
                )
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $insertAward->execute([
                $userId,
                $currentYear,
                $tierName,
                $finalPoints,
                $finalTemplateVoucherId,
                $finalGeneratedVoucherId,
            ]);
        }

        if (
            $newPoints > 0 ||
            $generatedVoucherId !== null
        ) {
            $messageParts = [];

            if ($newPoints > 0) {
                $messageParts[] =
                    number_format($newPoints) .
                    ' birthday points';
            }

            if ($generatedVoucherId !== null) {
                $voucherMessage =
                    'voucher ' .
                    $generatedVoucherCode;

                if (
                    $generatedVoucherExpiry
                    instanceof DateTimeImmutable
                ) {
                    $voucherMessage .=
                        ' valid until ' .
                        $generatedVoucherExpiry
                            ->format('d M Y');
                }

                $messageParts[] = $voucherMessage;
            }

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
                'Birthday Rewards Added',
                'Happy birthday! MangaVault added ' .
                    implode(
                        ' and ',
                        $messageParts
                    ) .
                    ' to your account.',
            ]);
        }

        if ($ownsTransaction) {
            $pdo->commit();
        }

        return $newPoints;
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
