CREATE TABLE IF NOT EXISTS customer_identity_history (
    identity_history_id INT NOT NULL AUTO_INCREMENT,
    identity_user_id INT NOT NULL,

    identity_type ENUM(
        'email',
        'phone'
    ) NOT NULL,

    identity_fingerprint CHAR(64)
        CHARACTER SET ascii
        COLLATE ascii_bin
        NOT NULL,

    identity_status ENUM(
        'active',
        'historical',
        'released'
    ) NOT NULL DEFAULT 'active',

    identity_claim_key TINYINT NULL DEFAULT 1,

    identity_source ENUM(
        'registration',
        'profile_update',
        'account_deletion',
        'admin_restore',
        'admin_reassignment',
        'legacy_sync'
    ) NOT NULL,

    identity_created_at DATETIME NOT NULL
        DEFAULT CURRENT_TIMESTAMP,

    identity_updated_at DATETIME NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    identity_released_at DATETIME NULL,
    identity_released_by INT NULL,
    identity_release_reason VARCHAR(500) NULL,

    PRIMARY KEY (
        identity_history_id
    ),

    UNIQUE KEY uq_customer_identity_claim (
        identity_type,
        identity_fingerprint,
        identity_claim_key
    ),

    KEY idx_customer_identity_user (
        identity_user_id,
        identity_status
    ),

    KEY idx_customer_identity_lookup (
        identity_type,
        identity_fingerprint,
        identity_status
    ),

    CONSTRAINT fk_customer_identity_user
        FOREIGN KEY (
            identity_user_id
        )
        REFERENCES users (
            user_id
        )
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT fk_customer_identity_released_by
        FOREIGN KEY (
            identity_released_by
        )
        REFERENCES users (
            user_id
        )
        ON UPDATE CASCADE
        ON DELETE SET NULL
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;