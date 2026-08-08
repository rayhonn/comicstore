ALTER TABLE users
    ADD COLUMN IF NOT EXISTS
        user_welcome_reward_granted_at DATETIME NULL DEFAULT NULL
    AFTER user_deleted_at;

UPDATE users u
SET
    u.user_welcome_reward_granted_at = NOW()
WHERE
    u.user_role = 'customer'
AND
    u.user_welcome_reward_granted_at IS NULL
AND EXISTS (
    SELECT 1
    FROM user_vouchers uv
    INNER JOIN vouchers v
        ON v.voucher_id = uv.uv_voucher_id
    WHERE
        uv.uv_user_id = u.user_id
    AND
        v.voucher_code IN (
            'WELCOME10',
            'NEWUSER20',
            'NEWMEMBER50'
        )
);