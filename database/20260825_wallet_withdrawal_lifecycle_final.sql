-- MangaVault Final Wallet Withdrawal Lifecycle Upgrade
-- XAMPP / MariaDB compatible version.
-- This version intentionally avoids information_schema and dynamic PREPARE.
-- Safe to re-run on MariaDB versions supporting IF NOT EXISTS.

ALTER TABLE wallet_withdrawal_requests
    ADD COLUMN IF NOT EXISTS wallet_withdrawal_retry_expires_at_myt DATETIME DEFAULT NULL
    AFTER wallet_withdrawal_failure_reason;

ALTER TABLE wallet_withdrawal_requests
    ADD INDEX IF NOT EXISTS idx_wallet_withdrawal_retry_window (
        wallet_withdrawal_user_id,
        wallet_withdrawal_retry_expires_at_myt,
        wallet_withdrawal_status
    );

-- Give already-failed downstream bank attempts a fresh 3-day correction window.
-- Stored explicitly as Malaysia wall-clock time (MYT / UTC+8).
UPDATE wallet_withdrawal_requests
SET wallet_withdrawal_retry_expires_at_myt = DATE_ADD(
    CONVERT_TZ(UTC_TIMESTAMP(), '+00:00', '+08:00'),
    INTERVAL 3 DAY
)
WHERE wallet_withdrawal_retry_expires_at_myt IS NULL
  AND wallet_withdrawal_status = 'failed'
  AND wallet_withdrawal_release_tx_id IS NOT NULL
  AND (
      wallet_withdrawal_bank_status = 'rejected'
      OR wallet_withdrawal_bank_settlement_status = 'failed'
  );
