ALTER TABLE wallet_withdrawal_requests
    ADD COLUMN wallet_withdrawal_account_fingerprint CHAR(64) DEFAULT NULL
        AFTER wallet_withdrawal_account_number_last4,
    ADD COLUMN wallet_withdrawal_customer_reauthenticated_at DATETIME DEFAULT NULL
        AFTER wallet_withdrawal_account_fingerprint,
    ADD COLUMN wallet_withdrawal_transfer_reference VARCHAR(100) DEFAULT NULL
        AFTER wallet_withdrawal_admin_note,
    ADD COLUMN wallet_withdrawal_receipt_mime VARCHAR(100) DEFAULT NULL
        AFTER wallet_withdrawal_receipt_file,
    ADD COLUMN wallet_withdrawal_receipt_size INT UNSIGNED DEFAULT NULL
        AFTER wallet_withdrawal_receipt_mime;

CREATE INDEX idx_wallet_withdrawal_account_fingerprint
    ON wallet_withdrawal_requests (
        wallet_withdrawal_account_fingerprint
    );

CREATE UNIQUE INDEX uq_wallet_withdrawal_transfer_reference
    ON wallet_withdrawal_requests (
        wallet_withdrawal_transfer_reference
    );
