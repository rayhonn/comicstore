ALTER TABLE wallet_withdrawal_requests
    DROP CONSTRAINT chk_wallet_withdrawal_status,
    ADD COLUMN wallet_withdrawal_failed_by INT(11) DEFAULT NULL
        AFTER wallet_withdrawal_reviewed_at,
    ADD COLUMN wallet_withdrawal_failed_at DATETIME DEFAULT NULL
        AFTER wallet_withdrawal_failed_by,
    ADD COLUMN wallet_withdrawal_failure_reason VARCHAR(1000) DEFAULT NULL
        AFTER wallet_withdrawal_failed_at,
    ADD CONSTRAINT fk_wallet_withdrawal_failed_by
        FOREIGN KEY (
            wallet_withdrawal_failed_by
        )
        REFERENCES users (
            user_id
        )
        ON DELETE SET NULL,
    ADD CONSTRAINT chk_wallet_withdrawal_status
        CHECK (
            wallet_withdrawal_status IN (
                'pending',
                'approved',
                'rejected',
                'failed',
                'completed'
            )
        );