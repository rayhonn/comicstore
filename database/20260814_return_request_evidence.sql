CREATE TABLE IF NOT EXISTS return_request_evidence (
    return_evidence_id INT UNSIGNED NOT NULL
        AUTO_INCREMENT,

    return_evidence_return_id INT NOT NULL,

    return_evidence_file_name VARCHAR(160) NOT NULL,

    return_evidence_file_sha256 CHAR(64)
        CHARACTER SET ascii
        COLLATE ascii_bin
        NOT NULL,

    return_evidence_mime_type VARCHAR(50) NOT NULL,

    return_evidence_file_size INT UNSIGNED NOT NULL,

    return_evidence_created_at DATETIME NOT NULL
        DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (
        return_evidence_id
    ),

    UNIQUE KEY uq_return_request_evidence_return (
        return_evidence_return_id
    ),

    UNIQUE KEY uq_return_request_evidence_file (
        return_evidence_file_name
    ),

    CONSTRAINT fk_return_request_evidence_return
        FOREIGN KEY (
            return_evidence_return_id
        )
        REFERENCES return_requests (
            return_id
        )
        ON UPDATE CASCADE
        ON DELETE CASCADE
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;