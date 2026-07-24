<?php

function restorePendingUserVoucher(
    PDO $pdo,
    mixed $voucher_id,
    int $user_id
): bool {
    if ($user_id <= 0) {
        return false;
    }

    $validated_voucher_id = filter_var(
        $voucher_id,
        FILTER_VALIDATE_INT,
        [
            'options' => [
                'min_range' => 1,
            ],
        ]
    );

    if ($validated_voucher_id === false) {
        return false;
    }

    $restore_voucher = $pdo->prepare("
        UPDATE user_vouchers
        SET uv_status = 'available',
            uv_is_used = 0,
            uv_pending_at = NULL,
            uv_used_at = NULL
        WHERE uv_voucher_id = ?
        AND uv_user_id = ?
        AND uv_is_used = 0
        AND uv_status = 'pending'
    ");

    $restore_voucher->execute([
        (int) $validated_voucher_id,
        $user_id,
    ]);

    return $restore_voucher->rowCount() === 1;
}

function restoreOrderVoucherUsage(
    PDO $pdo,
    mixed $voucher_code,
    int $order_id,
    int $user_id
): bool {
    if ($order_id <= 0 || $user_id <= 0) {
        return false;
    }

    $validated_voucher_code = trim(
        (string) $voucher_code
    );

    if ($validated_voucher_code === '') {
        return false;
    }

    $voucher_stmt = $pdo->prepare("
        SELECT voucher_id
        FROM vouchers
        WHERE voucher_code = ?
        FOR UPDATE
    ");

    $voucher_stmt->execute([
        $validated_voucher_code,
    ]);

    $voucher_id = $voucher_stmt->fetchColumn();

    if ($voucher_id === false) {
        return false;
    }

    $voucher_id = (int) $voucher_id;

    $delete_usage = $pdo->prepare("
        DELETE FROM voucher_usage
        WHERE usage_order_id = ?
        AND usage_user_id = ?
        AND usage_voucher_id = ?
    ");

    $delete_usage->execute([
        $order_id,
        $user_id,
        $voucher_id,
    ]);

    $usage_deleted =
        $delete_usage->rowCount() === 1;

    if ($usage_deleted) {
        $reduce_count = $pdo->prepare("
            UPDATE vouchers
            SET voucher_used_count =
                voucher_used_count - 1
            WHERE voucher_id = ?
            AND voucher_used_count > 0
        ");

        $reduce_count->execute([
            $voucher_id,
        ]);

        if ($reduce_count->rowCount() !== 1) {
            throw new RuntimeException(
                'Unable to restore voucher usage.'
            );
        }
    }

    $restore_voucher = $pdo->prepare("
        UPDATE user_vouchers
        SET uv_is_used = 0,
            uv_status = 'available',
            uv_pending_at = NULL,
            uv_used_at = NULL
        WHERE uv_voucher_id = ?
        AND uv_user_id = ?
        AND uv_is_used = 0
        AND uv_status = 'pending'
    ");

    $restore_voucher->execute([
        $voucher_id,
        $user_id,
    ]);

    return
        $usage_deleted ||
        $restore_voucher->rowCount() === 1;
}
