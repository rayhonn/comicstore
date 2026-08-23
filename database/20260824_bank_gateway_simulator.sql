CREATE TABLE IF NOT EXISTS bank_gateway_operators (
    bank_gateway_operator_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    bank_gateway_operator_bank_code VARCHAR(30) NOT NULL,
    bank_gateway_operator_bank_name VARCHAR(100) NOT NULL,
    bank_gateway_operator_username VARCHAR(60) NOT NULL,
    bank_gateway_operator_password_hash VARCHAR(255) NOT NULL,
    bank_gateway_operator_display_name VARCHAR(120) NOT NULL,
    bank_gateway_operator_is_active TINYINT(1) NOT NULL DEFAULT 1,
    bank_gateway_operator_last_login_at DATETIME DEFAULT NULL,
    bank_gateway_operator_created_at DATETIME NOT NULL
        DEFAULT CURRENT_TIMESTAMP,
    bank_gateway_operator_updated_at DATETIME NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (bank_gateway_operator_id),

    UNIQUE KEY uq_bank_gateway_operator_username (
        bank_gateway_operator_username
    ),

    KEY idx_bank_gateway_operator_bank (
        bank_gateway_operator_bank_code,
        bank_gateway_operator_is_active
    ),

    CONSTRAINT chk_bank_gateway_operator_active
        CHECK (
            bank_gateway_operator_is_active IN (
                0,
                1
            )
        )
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_general_ci;

INSERT INTO bank_gateway_operators (
    bank_gateway_operator_bank_code,
    bank_gateway_operator_bank_name,
    bank_gateway_operator_username,
    bank_gateway_operator_password_hash,
    bank_gateway_operator_display_name
)
VALUES
    ('MBB', 'Maybank', 'maybank_ops', '$2y$12$58rPVXSLBYq7Y8mO80DtZOr3YXsxjOQTDKECKq/F2eqWK7u2PjTyq', 'Maybank Verification Desk'),
    ('CIMB', 'CIMB Bank', 'cimb_ops', '$2y$12$58rPVXSLBYq7Y8mO80DtZOr3YXsxjOQTDKECKq/F2eqWK7u2PjTyq', 'CIMB Verification Desk'),
    ('PBB', 'Public Bank', 'publicbank_ops', '$2y$12$58rPVXSLBYq7Y8mO80DtZOr3YXsxjOQTDKECKq/F2eqWK7u2PjTyq', 'Public Bank Verification Desk'),
    ('RHB', 'RHB Bank', 'rhb_ops', '$2y$12$58rPVXSLBYq7Y8mO80DtZOr3YXsxjOQTDKECKq/F2eqWK7u2PjTyq', 'RHB Verification Desk'),
    ('HLB', 'Hong Leong Bank', 'hongleong_ops', '$2y$12$58rPVXSLBYq7Y8mO80DtZOr3YXsxjOQTDKECKq/F2eqWK7u2PjTyq', 'Hong Leong Verification Desk'),
    ('AMB', 'AmBank', 'ambank_ops', '$2y$12$58rPVXSLBYq7Y8mO80DtZOr3YXsxjOQTDKECKq/F2eqWK7u2PjTyq', 'AmBank Verification Desk'),
    ('BIMB', 'Bank Islam', 'bankislam_ops', '$2y$12$58rPVXSLBYq7Y8mO80DtZOr3YXsxjOQTDKECKq/F2eqWK7u2PjTyq', 'Bank Islam Verification Desk'),
    ('BSN', 'Bank Simpanan Nasional', 'bsn_ops', '$2y$12$58rPVXSLBYq7Y8mO80DtZOr3YXsxjOQTDKECKq/F2eqWK7u2PjTyq', 'BSN Verification Desk'),
    ('AFFIN', 'Affin Bank', 'affin_ops', '$2y$12$58rPVXSLBYq7Y8mO80DtZOr3YXsxjOQTDKECKq/F2eqWK7u2PjTyq', 'Affin Verification Desk'),
    ('ALLIANCE', 'Alliance Bank', 'alliance_ops', '$2y$12$58rPVXSLBYq7Y8mO80DtZOr3YXsxjOQTDKECKq/F2eqWK7u2PjTyq', 'Alliance Verification Desk'),
    ('BKR', 'Bank Rakyat', 'bankrakyat_ops', '$2y$12$58rPVXSLBYq7Y8mO80DtZOr3YXsxjOQTDKECKq/F2eqWK7u2PjTyq', 'Bank Rakyat Verification Desk'),
    ('OCBC', 'OCBC Bank Malaysia', 'ocbc_ops', '$2y$12$58rPVXSLBYq7Y8mO80DtZOr3YXsxjOQTDKECKq/F2eqWK7u2PjTyq', 'OCBC Verification Desk'),
    ('UOB', 'UOB Malaysia', 'uob_ops', '$2y$12$58rPVXSLBYq7Y8mO80DtZOr3YXsxjOQTDKECKq/F2eqWK7u2PjTyq', 'UOB Verification Desk'),
    ('HSBC', 'HSBC Malaysia', 'hsbc_ops', '$2y$12$58rPVXSLBYq7Y8mO80DtZOr3YXsxjOQTDKECKq/F2eqWK7u2PjTyq', 'HSBC Verification Desk'),
    ('SCB', 'Standard Chartered Malaysia', 'scb_ops', '$2y$12$58rPVXSLBYq7Y8mO80DtZOr3YXsxjOQTDKECKq/F2eqWK7u2PjTyq', 'Standard Chartered Verification Desk')
ON DUPLICATE KEY UPDATE
    bank_gateway_operator_bank_name =
        VALUES(bank_gateway_operator_bank_name),
    bank_gateway_operator_display_name =
        VALUES(bank_gateway_operator_display_name);

ALTER TABLE wallet_withdrawal_requests
    ADD COLUMN wallet_withdrawal_bank_status VARCHAR(30)
        NOT NULL DEFAULT 'not_submitted'
        AFTER wallet_withdrawal_admin_note,
    ADD COLUMN wallet_withdrawal_bank_submission_id CHAR(32)
        DEFAULT NULL
        AFTER wallet_withdrawal_bank_status,
    ADD COLUMN wallet_withdrawal_bank_submitted_at DATETIME
        DEFAULT NULL
        AFTER wallet_withdrawal_bank_submission_id,
    ADD COLUMN wallet_withdrawal_bank_decision_reference VARCHAR(80)
        DEFAULT NULL
        AFTER wallet_withdrawal_bank_submitted_at,
    ADD COLUMN wallet_withdrawal_bank_decided_by INT UNSIGNED
        DEFAULT NULL
        AFTER wallet_withdrawal_bank_decision_reference,
    ADD COLUMN wallet_withdrawal_bank_decided_at DATETIME
        DEFAULT NULL
        AFTER wallet_withdrawal_bank_decided_by,
    ADD COLUMN wallet_withdrawal_bank_decision_note VARCHAR(1000)
        DEFAULT NULL
        AFTER wallet_withdrawal_bank_decided_at,
    ADD COLUMN wallet_withdrawal_bank_verification_hash CHAR(64)
        DEFAULT NULL
        AFTER wallet_withdrawal_bank_decision_note,
    ADD CONSTRAINT fk_wallet_withdrawal_bank_operator
        FOREIGN KEY (
            wallet_withdrawal_bank_decided_by
        )
        REFERENCES bank_gateway_operators (
            bank_gateway_operator_id
        )
        ON DELETE SET NULL,
    ADD CONSTRAINT chk_wallet_withdrawal_bank_status
        CHECK (
            wallet_withdrawal_bank_status IN (
                'not_submitted',
                'pending',
                'approved',
                'rejected'
            )
        );

CREATE UNIQUE INDEX uq_wallet_withdrawal_bank_submission
    ON wallet_withdrawal_requests (
        wallet_withdrawal_bank_submission_id
    );

CREATE UNIQUE INDEX uq_wallet_withdrawal_bank_reference
    ON wallet_withdrawal_requests (
        wallet_withdrawal_bank_decision_reference
    );

CREATE INDEX idx_wallet_withdrawal_bank_queue
    ON wallet_withdrawal_requests (
        wallet_withdrawal_bank_code,
        wallet_withdrawal_bank_status,
        wallet_withdrawal_bank_submitted_at
    );

CREATE TABLE IF NOT EXISTS bank_gateway_audit_logs (
    bank_gateway_log_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    bank_gateway_log_operator_id INT UNSIGNED NOT NULL,
    bank_gateway_log_withdrawal_id INT(11) NOT NULL,
    bank_gateway_log_action VARCHAR(60) NOT NULL,
    bank_gateway_log_details VARCHAR(1000) NOT NULL,
    bank_gateway_log_created_at DATETIME NOT NULL
        DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (bank_gateway_log_id),

    KEY idx_bank_gateway_log_withdrawal (
        bank_gateway_log_withdrawal_id,
        bank_gateway_log_created_at
    ),

    KEY idx_bank_gateway_log_operator (
        bank_gateway_log_operator_id,
        bank_gateway_log_created_at
    ),

    CONSTRAINT fk_bank_gateway_log_operator
        FOREIGN KEY (
            bank_gateway_log_operator_id
        )
        REFERENCES bank_gateway_operators (
            bank_gateway_operator_id
        )
        ON DELETE RESTRICT,

    CONSTRAINT fk_bank_gateway_log_withdrawal
        FOREIGN KEY (
            bank_gateway_log_withdrawal_id
        )
        REFERENCES wallet_withdrawal_requests (
            wallet_withdrawal_id
        )
        ON DELETE RESTRICT
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_general_ci;

UPDATE wallet_withdrawal_requests
SET
    wallet_withdrawal_bank_status = 'pending',
    wallet_withdrawal_bank_submission_id =
        REPLACE(UUID(), '-', ''),
    wallet_withdrawal_bank_submitted_at =
        COALESCE(
            wallet_withdrawal_reviewed_at,
            wallet_withdrawal_created_at
        )
WHERE wallet_withdrawal_status = 'approved'
AND wallet_withdrawal_bank_status = 'not_submitted';

UPDATE wallet_withdrawal_requests
SET
    wallet_withdrawal_bank_status = 'approved',
    wallet_withdrawal_bank_submission_id =
        REPLACE(UUID(), '-', ''),
    wallet_withdrawal_bank_submitted_at =
        COALESCE(
            wallet_withdrawal_reviewed_at,
            wallet_withdrawal_created_at
        ),
    wallet_withdrawal_bank_decision_reference =
        CONCAT(
            'LEGACY-',
            LPAD(
                wallet_withdrawal_id,
                8,
                '0'
            )
        ),
    wallet_withdrawal_bank_decided_at =
        COALESCE(
            wallet_withdrawal_completed_at,
            wallet_withdrawal_reviewed_at
        ),
    wallet_withdrawal_bank_decision_note =
        'Migrated completed withdrawal.',
    wallet_withdrawal_bank_verification_hash =
        SHA2(
            CONCAT(
                'LEGACY|',
                wallet_withdrawal_id,
                '|',
                wallet_withdrawal_amount
            ),
            256
        )
WHERE wallet_withdrawal_status = 'completed'
AND wallet_withdrawal_bank_status = 'not_submitted';
