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