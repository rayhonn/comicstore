CREATE TABLE IF NOT EXISTS wallet_accounts (
    wallet_id INT(11) NOT NULL AUTO_INCREMENT,
    wallet_user_id INT(11) NOT NULL,
    wallet_balance DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    wallet_reserved_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    wallet_created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    wallet_updated_at DATETIME NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (wallet_id),

    UNIQUE KEY uq_wallet_accounts_user (
        wallet_user_id
    ),

    CONSTRAINT fk_wallet_accounts_user
        FOREIGN KEY (wallet_user_id)
        REFERENCES users (user_id)
        ON DELETE CASCADE,

    CONSTRAINT chk_wallet_accounts_balance
        CHECK (wallet_balance >= 0.00),

    CONSTRAINT chk_wallet_accounts_reserved
        CHECK (
            wallet_reserved_amount >= 0.00
            AND wallet_reserved_amount <= wallet_balance
        )
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS wallet_transactions (
    wallet_tx_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    wallet_tx_wallet_id INT(11) NOT NULL,
    wallet_tx_user_id INT(11) NOT NULL,
    wallet_tx_effect VARCHAR(20) NOT NULL,
    wallet_tx_type VARCHAR(40) NOT NULL,
    wallet_tx_amount DECIMAL(10,2) NOT NULL,
    wallet_tx_balance_after DECIMAL(10,2) NOT NULL,
    wallet_tx_reserved_after DECIMAL(10,2) NOT NULL,
    wallet_tx_reference_type VARCHAR(40) DEFAULT NULL,
    wallet_tx_reference_id INT(11) DEFAULT NULL,
    wallet_tx_idempotency_key VARCHAR(191) NOT NULL,
    wallet_tx_description VARCHAR(255) NOT NULL,
    wallet_tx_created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (wallet_tx_id),

    UNIQUE KEY uq_wallet_transactions_idempotency (
        wallet_tx_idempotency_key
    ),

    KEY idx_wallet_transactions_user_created (
        wallet_tx_user_id,
        wallet_tx_created_at
    ),

    KEY idx_wallet_transactions_reference (
        wallet_tx_reference_type,
        wallet_tx_reference_id
    ),

    CONSTRAINT fk_wallet_transactions_wallet
        FOREIGN KEY (wallet_tx_wallet_id)
        REFERENCES wallet_accounts (wallet_id)
        ON DELETE CASCADE,

    CONSTRAINT fk_wallet_transactions_user
        FOREIGN KEY (wallet_tx_user_id)
        REFERENCES users (user_id)
        ON DELETE CASCADE,

    CONSTRAINT chk_wallet_transactions_effect
        CHECK (
            wallet_tx_effect IN (
                'credit',
                'debit',
                'reserve',
                'release'
            )
        ),

    CONSTRAINT chk_wallet_transactions_type
        CHECK (
            wallet_tx_type IN (
                'topup',
                'return_refund',
                'cancellation_refund',
                'payment_timeout_refund',
                'withdrawal_reserve',
                'withdrawal_release',
                'withdrawal_complete'
            )
        ),

    CONSTRAINT chk_wallet_transactions_amount
        CHECK (wallet_tx_amount > 0.00),

    CONSTRAINT chk_wallet_transactions_after
        CHECK (
            wallet_tx_balance_after >= 0.00
            AND wallet_tx_reserved_after >= 0.00
            AND wallet_tx_reserved_after <= wallet_tx_balance_after
        )
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS wallet_topups (
    wallet_topup_id INT(11) NOT NULL AUTO_INCREMENT,
    wallet_topup_user_id INT(11) NOT NULL,
    wallet_topup_amount DECIMAL(10,2) NOT NULL,
    wallet_topup_currency CHAR(3) NOT NULL DEFAULT 'myr',
    wallet_topup_status VARCHAR(30) NOT NULL DEFAULT 'pending',
    wallet_topup_stripe_session_id VARCHAR(255) DEFAULT NULL,
    wallet_topup_stripe_payment_intent_id VARCHAR(255) DEFAULT NULL,
    wallet_topup_stripe_expires_at DATETIME DEFAULT NULL,
    wallet_topup_paid_at DATETIME DEFAULT NULL,
    wallet_topup_created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    wallet_topup_updated_at DATETIME NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (wallet_topup_id),

    UNIQUE KEY uq_wallet_topups_stripe_session (
        wallet_topup_stripe_session_id
    ),

    UNIQUE KEY uq_wallet_topups_payment_intent (
        wallet_topup_stripe_payment_intent_id
    ),

    KEY idx_wallet_topups_user_created (
        wallet_topup_user_id,
        wallet_topup_created_at
    ),

    KEY idx_wallet_topups_status (
        wallet_topup_status
    ),

    CONSTRAINT fk_wallet_topups_user
        FOREIGN KEY (wallet_topup_user_id)
        REFERENCES users (user_id)
        ON DELETE CASCADE,

    CONSTRAINT chk_wallet_topups_amount
        CHECK (
            wallet_topup_amount >= 1.00
            AND wallet_topup_amount <= 5000.00
        ),

    CONSTRAINT chk_wallet_topups_currency
        CHECK (wallet_topup_currency = 'myr'),

    CONSTRAINT chk_wallet_topups_status
        CHECK (
            wallet_topup_status IN (
                'pending',
                'checkout_open',
                'completed',
                'cancelled',
                'expired',
                'failed'
            )
        )
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS wallet_refund_credits (
    wallet_refund_credit_id INT(11) NOT NULL AUTO_INCREMENT,
    wallet_refund_credit_wallet_id INT(11) NOT NULL,
    wallet_refund_credit_user_id INT(11) NOT NULL,
    wallet_refund_credit_source_type VARCHAR(40) NOT NULL,
    wallet_refund_credit_source_id INT(11) NOT NULL,
    wallet_refund_credit_amount DECIMAL(10,2) NOT NULL,
    wallet_refund_credit_transaction_id BIGINT UNSIGNED NOT NULL,
    wallet_refund_credit_credited_at DATETIME NOT NULL,
    wallet_refund_credit_withdrawal_expires_at DATETIME NOT NULL,

    PRIMARY KEY (wallet_refund_credit_id),

    UNIQUE KEY uq_wallet_refund_credits_source (
        wallet_refund_credit_source_type,
        wallet_refund_credit_source_id
    ),

    UNIQUE KEY uq_wallet_refund_credits_transaction (
        wallet_refund_credit_transaction_id
    ),

    KEY idx_wallet_refund_credits_user_expiry (
        wallet_refund_credit_user_id,
        wallet_refund_credit_withdrawal_expires_at
    ),

    CONSTRAINT fk_wallet_refund_credits_wallet
        FOREIGN KEY (wallet_refund_credit_wallet_id)
        REFERENCES wallet_accounts (wallet_id)
        ON DELETE CASCADE,

    CONSTRAINT fk_wallet_refund_credits_user
        FOREIGN KEY (wallet_refund_credit_user_id)
        REFERENCES users (user_id)
        ON DELETE CASCADE,

    CONSTRAINT fk_wallet_refund_credits_transaction
        FOREIGN KEY (wallet_refund_credit_transaction_id)
        REFERENCES wallet_transactions (wallet_tx_id)
        ON DELETE RESTRICT,

    CONSTRAINT chk_wallet_refund_credits_source
        CHECK (
            wallet_refund_credit_source_type IN (
                'return',
                'customer_cancellation',
                'payment_timeout'
            )
        ),

    CONSTRAINT chk_wallet_refund_credits_amount
        CHECK (wallet_refund_credit_amount > 0.00),

    CONSTRAINT chk_wallet_refund_credits_expiry
        CHECK (
            wallet_refund_credit_withdrawal_expires_at >
                wallet_refund_credit_credited_at
        )
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS wallet_withdrawal_requests (
    wallet_withdrawal_id INT(11) NOT NULL AUTO_INCREMENT,
    wallet_withdrawal_wallet_id INT(11) NOT NULL,
    wallet_withdrawal_user_id INT(11) NOT NULL,
    wallet_withdrawal_amount DECIMAL(10,2) NOT NULL,
    wallet_withdrawal_status VARCHAR(30) NOT NULL DEFAULT 'pending',
    wallet_withdrawal_bank_code VARCHAR(30) NOT NULL,
    wallet_withdrawal_bank_name VARCHAR(100) NOT NULL,
    wallet_withdrawal_account_holder VARCHAR(120) NOT NULL,
    wallet_withdrawal_account_number_encrypted VARCHAR(512) NOT NULL,
    wallet_withdrawal_account_number_last4 CHAR(4) NOT NULL,
    wallet_withdrawal_reserved_tx_id BIGINT UNSIGNED DEFAULT NULL,
    wallet_withdrawal_release_tx_id BIGINT UNSIGNED DEFAULT NULL,
    wallet_withdrawal_debit_tx_id BIGINT UNSIGNED DEFAULT NULL,
    wallet_withdrawal_reviewed_by INT(11) DEFAULT NULL,
    wallet_withdrawal_reviewed_at DATETIME DEFAULT NULL,
    wallet_withdrawal_admin_note VARCHAR(1000) DEFAULT NULL,
    wallet_withdrawal_receipt_file VARCHAR(255) DEFAULT NULL,
    wallet_withdrawal_receipt_sha256 CHAR(64) DEFAULT NULL,
    wallet_withdrawal_receipt_uploaded_by INT(11) DEFAULT NULL,
    wallet_withdrawal_receipt_uploaded_at DATETIME DEFAULT NULL,
    wallet_withdrawal_completed_at DATETIME DEFAULT NULL,
    wallet_withdrawal_created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    wallet_withdrawal_updated_at DATETIME NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (wallet_withdrawal_id),

    UNIQUE KEY uq_wallet_withdrawal_reserved_tx (
        wallet_withdrawal_reserved_tx_id
    ),

    UNIQUE KEY uq_wallet_withdrawal_release_tx (
        wallet_withdrawal_release_tx_id
    ),

    UNIQUE KEY uq_wallet_withdrawal_debit_tx (
        wallet_withdrawal_debit_tx_id
    ),

    KEY idx_wallet_withdrawal_user_created (
        wallet_withdrawal_user_id,
        wallet_withdrawal_created_at
    ),

    KEY idx_wallet_withdrawal_status (
        wallet_withdrawal_status
    ),

    CONSTRAINT fk_wallet_withdrawal_wallet
        FOREIGN KEY (wallet_withdrawal_wallet_id)
        REFERENCES wallet_accounts (wallet_id)
        ON DELETE CASCADE,

    CONSTRAINT fk_wallet_withdrawal_user
        FOREIGN KEY (wallet_withdrawal_user_id)
        REFERENCES users (user_id)
        ON DELETE CASCADE,

    CONSTRAINT fk_wallet_withdrawal_reserved_tx
        FOREIGN KEY (wallet_withdrawal_reserved_tx_id)
        REFERENCES wallet_transactions (wallet_tx_id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_wallet_withdrawal_release_tx
        FOREIGN KEY (wallet_withdrawal_release_tx_id)
        REFERENCES wallet_transactions (wallet_tx_id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_wallet_withdrawal_debit_tx
        FOREIGN KEY (wallet_withdrawal_debit_tx_id)
        REFERENCES wallet_transactions (wallet_tx_id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_wallet_withdrawal_reviewed_by
        FOREIGN KEY (wallet_withdrawal_reviewed_by)
        REFERENCES users (user_id)
        ON DELETE SET NULL,

    CONSTRAINT fk_wallet_withdrawal_receipt_uploaded_by
        FOREIGN KEY (wallet_withdrawal_receipt_uploaded_by)
        REFERENCES users (user_id)
        ON DELETE SET NULL,

    CONSTRAINT chk_wallet_withdrawal_amount
        CHECK (wallet_withdrawal_amount > 0.00),

    CONSTRAINT chk_wallet_withdrawal_status
        CHECK (
            wallet_withdrawal_status IN (
                'pending',
                'approved',
                'rejected',
                'completed'
            )
        )
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS wallet_withdrawal_allocations (
    wallet_withdrawal_allocation_id INT(11) NOT NULL AUTO_INCREMENT,
    wallet_withdrawal_allocation_request_id INT(11) NOT NULL,
    wallet_withdrawal_allocation_refund_credit_id INT(11) NOT NULL,
    wallet_withdrawal_allocation_amount DECIMAL(10,2) NOT NULL,
    wallet_withdrawal_allocation_created_at DATETIME NOT NULL
        DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (wallet_withdrawal_allocation_id),

    UNIQUE KEY uq_wallet_withdrawal_allocation_pair (
        wallet_withdrawal_allocation_request_id,
        wallet_withdrawal_allocation_refund_credit_id
    ),

    KEY idx_wallet_withdrawal_allocation_credit (
        wallet_withdrawal_allocation_refund_credit_id
    ),

    CONSTRAINT fk_wallet_withdrawal_allocation_request
        FOREIGN KEY (wallet_withdrawal_allocation_request_id)
        REFERENCES wallet_withdrawal_requests (wallet_withdrawal_id)
        ON DELETE CASCADE,

    CONSTRAINT fk_wallet_withdrawal_allocation_credit
        FOREIGN KEY (wallet_withdrawal_allocation_refund_credit_id)
        REFERENCES wallet_refund_credits (wallet_refund_credit_id)
        ON DELETE RESTRICT,

    CONSTRAINT chk_wallet_withdrawal_allocation_amount
        CHECK (wallet_withdrawal_allocation_amount > 0.00)
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_general_ci;

INSERT INTO wallet_accounts (
    wallet_user_id
)
SELECT user_id
FROM users
WHERE user_role = 'customer'
ON DUPLICATE KEY UPDATE
    wallet_user_id = VALUES(wallet_user_id);
