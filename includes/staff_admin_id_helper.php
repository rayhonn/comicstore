<?php

/**
 * Allocate the next seven-digit Staff/Admin ID.
 *
 * Format:
 * YYMMNNN
 *
 * Example:
 * 2608001
 *
 * Staff and standard Admin accounts share the same
 * monthly sequence. The sequence resets automatically
 * when the year/month changes.
 *
 * This function must run inside an active database
 * transaction so the sequence row remains locked until
 * the account creation succeeds or rolls back.
 */
function allocateStaffAdminId(
    PDO $pdo
): string {
    if (!$pdo->inTransaction()) {
        throw new RuntimeException(
            'Staff/Admin ID allocation requires an active transaction.'
        );
    }

    $periodStatement =
        $pdo->query("
            SELECT DATE_FORMAT(
                NOW(),
                '%y%m'
            )
        ");

    $period =
        $periodStatement->fetchColumn();

    if (
        !is_string($period) ||
        !preg_match(
            '/^\d{4}$/',
            $period
        )
    ) {
        throw new RuntimeException(
            'Unable to determine the Staff/Admin ID period.'
        );
    }

    /*
     * Ensure the current month has a sequence row.
     *
     * The primary key prevents duplicate period rows.
     */
    $ensureSequence =
        $pdo->prepare("
            INSERT INTO
                staff_admin_id_sequences (
                    sequence_period,
                    sequence_last_number
                )
            VALUES (
                ?,
                0
            )
            ON DUPLICATE KEY UPDATE
                sequence_period =
                    VALUES(
                        sequence_period
                    )
        ");

    $ensureSequence->execute([
        $period,
    ]);

    /*
     * Lock the current month's sequence.
     *
     * Any second registration must wait until the
     * current transaction commits or rolls back.
     */
    $sequenceStatement =
        $pdo->prepare("
            SELECT sequence_last_number
            FROM staff_admin_id_sequences
            WHERE sequence_period = ?
            FOR UPDATE
        ");

    $sequenceStatement->execute([
        $period,
    ]);

    $lastNumber =
        $sequenceStatement->fetchColumn();

    if ($lastNumber === false) {
        throw new RuntimeException(
            'Unable to lock the Staff/Admin ID sequence.'
        );
    }

    $nextNumber =
        (int) $lastNumber + 1;

    /*
     * user_staff_id already has a UNIQUE constraint.
     *
     * This additional check safely skips any historical
     * ID that may already occupy a number from the old
     * random-ID implementation.
     */
    $existingIdStatement =
        $pdo->prepare("
            SELECT user_id
            FROM users
            WHERE user_staff_id = ?
            LIMIT 1
        ");

    while ($nextNumber <= 999) {
        $candidate =
            $period .
            str_pad(
                (string) $nextNumber,
                3,
                '0',
                STR_PAD_LEFT
            );

        $existingIdStatement->execute([
            $candidate,
        ]);

        if (
            !$existingIdStatement
                ->fetchColumn()
        ) {
            $updateSequence =
                $pdo->prepare("
                    UPDATE
                        staff_admin_id_sequences
                    SET
                        sequence_last_number = ?
                    WHERE
                        sequence_period = ?
                ");

            $updateSequence->execute([
                $nextNumber,
                $period,
            ]);

            if (
                $updateSequence->rowCount() !==
                1
            ) {
                throw new RuntimeException(
                    'Unable to update the Staff/Admin ID sequence.'
                );
            }

            return $candidate;
        }

        $nextNumber++;
    }

    throw new RuntimeException(
        'The monthly Staff/Admin ID limit has been reached.'
    );
}