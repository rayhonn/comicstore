-- MangaVault birthday voucher template classification
-- Run once after 20260729_birthday_voucher_rewards.sql.

ALTER TABLE vouchers
    ADD COLUMN voucher_is_birthday_template TINYINT(1)
        NOT NULL DEFAULT 0
        AFTER voucher_is_system_generated;

-- Preserve any voucher that was already selected in Tier Management.
UPDATE vouchers v
INNER JOIN tier_config tc
    ON tc.tier_birthday_voucher_id = v.voucher_id
SET v.voucher_is_birthday_template = 1
WHERE v.voucher_is_system_generated = 0
AND v.voucher_is_points_redeem = 0;