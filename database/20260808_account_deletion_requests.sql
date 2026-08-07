ALTER TABLE users
    ADD COLUMN IF NOT EXISTS user_deleted_at DATETIME NULL DEFAULT NULL
    AFTER user_is_active;

CREATE TABLE IF NOT EXISTS account_deletion_requests (
    deletion_request_id INT NOT NULL AUTO_INCREMENT,
    deletion_request_user_id INT NOT NULL,

    deletion_request_status ENUM(
        'pending',
        'approved',
        'rejected',
        'cancelled'
    ) NOT NULL DEFAULT 'pending',

    deletion_request_email_snapshot VARCHAR(100) NOT NULL,
    deletion_request_phone_snapshot VARCHAR(20) NULL,

    deletion_request_reason VARCHAR(500) NULL,

    deletion_request_agreement_version VARCHAR(30) NOT NULL,
    deletion_request_agreement_accepted_at DATETIME NOT NULL,

    deletion_request_requested_at DATETIME NOT NULL
        DEFAULT CURRENT_TIMESTAMP,

    deletion_request_cancelled_at DATETIME NULL,

    deletion_request_processed_by INT NULL,
    deletion_request_processed_at DATETIME NULL,
    deletion_request_admin_note VARCHAR(1000) NULL,

    deletion_request_open_key TINYINT NULL DEFAULT 1,

    PRIMARY KEY (deletion_request_id),

    UNIQUE KEY uq_account_deletion_open_request (
        deletion_request_user_id,
        deletion_request_open_key
    ),

    KEY idx_account_deletion_status (
        deletion_request_status,
        deletion_request_requested_at
    ),

    CONSTRAINT fk_account_deletion_user
        FOREIGN KEY (deletion_request_user_id)
        REFERENCES users (user_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT fk_account_deletion_processed_by
        FOREIGN KEY (deletion_request_processed_by)
        REFERENCES users (user_id)
        ON UPDATE CASCADE
        ON DELETE SET NULL
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;