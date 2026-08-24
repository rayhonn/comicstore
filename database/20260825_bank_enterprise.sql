-- MangaVault / BankLink Enterprise Settlement Upgrade
-- Run ONCE after database/20260824_bank_gateway_simulator.sql.
-- This migration is idempotent for the added columns, indexes and foreign keys.

DELIMITER $$

DROP PROCEDURE IF EXISTS mv_bank_enterprise_upgrade$$
CREATE PROCEDURE mv_bank_enterprise_upgrade()
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'wallet_withdrawal_requests'
          AND COLUMN_NAME = 'wallet_withdrawal_bank_settlement_status'
    ) THEN
        ALTER TABLE wallet_withdrawal_requests
            ADD COLUMN wallet_withdrawal_bank_settlement_status VARCHAR(30)
                NOT NULL DEFAULT 'not_required'
                AFTER wallet_withdrawal_bank_verification_hash;
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'wallet_withdrawal_requests'
          AND COLUMN_NAME = 'wallet_withdrawal_bank_settlement_started_by'
    ) THEN
        ALTER TABLE wallet_withdrawal_requests
            ADD COLUMN wallet_withdrawal_bank_settlement_started_by INT UNSIGNED DEFAULT NULL
                AFTER wallet_withdrawal_bank_settlement_status;
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'wallet_withdrawal_requests'
          AND COLUMN_NAME = 'wallet_withdrawal_bank_settlement_started_at'
    ) THEN
        ALTER TABLE wallet_withdrawal_requests
            ADD COLUMN wallet_withdrawal_bank_settlement_started_at DATETIME DEFAULT NULL
                AFTER wallet_withdrawal_bank_settlement_started_by;
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'wallet_withdrawal_requests'
          AND COLUMN_NAME = 'wallet_withdrawal_bank_settled_by'
    ) THEN
        ALTER TABLE wallet_withdrawal_requests
            ADD COLUMN wallet_withdrawal_bank_settled_by INT UNSIGNED DEFAULT NULL
                AFTER wallet_withdrawal_bank_settlement_started_at;
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'wallet_withdrawal_requests'
          AND COLUMN_NAME = 'wallet_withdrawal_bank_settled_at'
    ) THEN
        ALTER TABLE wallet_withdrawal_requests
            ADD COLUMN wallet_withdrawal_bank_settled_at DATETIME DEFAULT NULL
                AFTER wallet_withdrawal_bank_settled_by;
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'wallet_withdrawal_requests'
          AND COLUMN_NAME = 'wallet_withdrawal_bank_settlement_note'
    ) THEN
        ALTER TABLE wallet_withdrawal_requests
            ADD COLUMN wallet_withdrawal_bank_settlement_note VARCHAR(1000) DEFAULT NULL
                AFTER wallet_withdrawal_bank_settled_at;
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'wallet_withdrawal_requests'
          AND COLUMN_NAME = 'wallet_withdrawal_bank_settlement_hash'
    ) THEN
        ALTER TABLE wallet_withdrawal_requests
            ADD COLUMN wallet_withdrawal_bank_settlement_hash CHAR(64) DEFAULT NULL
                AFTER wallet_withdrawal_bank_settlement_note;
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.TABLE_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE()
          AND TABLE_NAME = 'wallet_withdrawal_requests'
          AND CONSTRAINT_NAME = 'fk_wallet_withdrawal_bank_settlement_started_by'
    ) THEN
        ALTER TABLE wallet_withdrawal_requests
            ADD CONSTRAINT fk_wallet_withdrawal_bank_settlement_started_by
                FOREIGN KEY (wallet_withdrawal_bank_settlement_started_by)
                REFERENCES bank_gateway_operators (bank_gateway_operator_id)
                ON DELETE SET NULL;
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.TABLE_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE()
          AND TABLE_NAME = 'wallet_withdrawal_requests'
          AND CONSTRAINT_NAME = 'fk_wallet_withdrawal_bank_settled_by'
    ) THEN
        ALTER TABLE wallet_withdrawal_requests
            ADD CONSTRAINT fk_wallet_withdrawal_bank_settled_by
                FOREIGN KEY (wallet_withdrawal_bank_settled_by)
                REFERENCES bank_gateway_operators (bank_gateway_operator_id)
                ON DELETE SET NULL;
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.TABLE_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE()
          AND TABLE_NAME = 'wallet_withdrawal_requests'
          AND CONSTRAINT_NAME = 'chk_wallet_withdrawal_bank_settlement_status'
    ) THEN
        ALTER TABLE wallet_withdrawal_requests
            ADD CONSTRAINT chk_wallet_withdrawal_bank_settlement_status
                CHECK (
                    wallet_withdrawal_bank_settlement_status IN (
                        'not_required',
                        'ready',
                        'processing',
                        'settled',
                        'failed'
                    )
                );
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'wallet_withdrawal_requests'
          AND INDEX_NAME = 'idx_wallet_withdrawal_bank_settlement_queue'
    ) THEN
        CREATE INDEX idx_wallet_withdrawal_bank_settlement_queue
            ON wallet_withdrawal_requests (
                wallet_withdrawal_bank_code,
                wallet_withdrawal_bank_status,
                wallet_withdrawal_bank_settlement_status,
                wallet_withdrawal_bank_settlement_started_at
            );
    END IF;
END$$

CALL mv_bank_enterprise_upgrade()$$
DROP PROCEDURE mv_bank_enterprise_upgrade$$

DELIMITER ;

-- Backfill lifecycle state for records that existed before this upgrade.
UPDATE wallet_withdrawal_requests
SET wallet_withdrawal_bank_settlement_status = 'ready'
WHERE wallet_withdrawal_status = 'approved'
  AND wallet_withdrawal_bank_status = 'approved'
  AND wallet_withdrawal_bank_settlement_status = 'not_required';

UPDATE wallet_withdrawal_requests
SET
    wallet_withdrawal_bank_settlement_status = 'settled',
    wallet_withdrawal_bank_settlement_started_at = COALESCE(
        wallet_withdrawal_bank_decided_at,
        wallet_withdrawal_reviewed_at
    ),
    wallet_withdrawal_bank_settled_at = COALESCE(
        wallet_withdrawal_completed_at,
        wallet_withdrawal_bank_decided_at
    ),
    wallet_withdrawal_bank_settlement_note = COALESCE(
        wallet_withdrawal_bank_settlement_note,
        'Backfilled from a completed withdrawal created before the enterprise settlement upgrade.'
    )
WHERE wallet_withdrawal_status = 'completed'
  AND wallet_withdrawal_bank_status = 'approved'
  AND wallet_withdrawal_bank_settlement_status <> 'settled';

UPDATE wallet_withdrawal_requests
SET wallet_withdrawal_bank_settlement_status = 'not_required'
WHERE wallet_withdrawal_bank_status = 'rejected'
  AND wallet_withdrawal_bank_settlement_status <> 'not_required';