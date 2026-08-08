CREATE TABLE IF NOT EXISTS identity_verification_requests (
    verification_request_id INT NOT NULL AUTO_INCREMENT,

    verification_request_user_id INT NOT NULL,

    verification_request_phone_snapshot VARCHAR(20) NOT NULL,

    verification_request_phone_fingerprint CHAR(64)
        CHARACTER SET ascii
        COLLATE ascii_bin
        NOT NULL,

    verification_request_nric_fingerprint CHAR(64)
        CHARACTER SET ascii
        COLLATE ascii_bin
        NOT NULL,

    verification_request_status ENUM(
        'pending',
        'approved',
        'rejected',
        'cancelled'
    ) NOT NULL DEFAULT 'pending',

    verification_request_reason VARCHAR(500) NOT NULL,

    verification_request_identity_file VARCHAR(160) NOT NULL,

    verification_request_identity_file_sha256 CHAR(64)
        CHARACTER SET ascii
        COLLATE ascii_bin
        NOT NULL,

    verification_request_phone_evidence_file VARCHAR(160) NOT NULL,

    verification_request_phone_evidence_sha256 CHAR(64)
        CHARACTER SET ascii
        COLLATE ascii_bin
        NOT NULL,

    verification_request_consent_version VARCHAR(30) NOT NULL,

    verification_request_consent_accepted_at DATETIME NOT NULL,

    verification_request_requested_at DATETIME NOT NULL
        DEFAULT CURRENT_TIMESTAMP,

    verification_request_reviewed_by INT NULL,

    verification_request_reviewed_at DATETIME NULL,

    verification_request_admin_note VARCHAR(1000) NULL,

    verification_request_open_key TINYINT NULL DEFAULT 1,

    PRIMARY KEY (
        verification_request_id
    ),

    UNIQUE KEY uq_identity_verification_user_open (
        verification_request_user_id,
        verification_request_open_key
    ),

    UNIQUE KEY uq_identity_verification_phone_open (
        verification_request_phone_fingerprint,
        verification_request_open_key
    ),

    KEY idx_identity_verification_status (
        verification_request_status,
        verification_request_requested_at
    ),

    CONSTRAINT fk_identity_verification_user
        FOREIGN KEY (
            verification_request_user_id
        )
        REFERENCES users (
            user_id
        )
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT fk_identity_verification_reviewed_by
        FOREIGN KEY (
            verification_request_reviewed_by
        )
        REFERENCES users (
            user_id
        )
        ON UPDATE CASCADE
        ON DELETE SET NULL
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;