ALTER TABLE wallet_transactions
    DROP CONSTRAINT chk_wallet_transactions_type;

ALTER TABLE wallet_transactions
    ADD CONSTRAINT chk_wallet_transactions_type
        CHECK (
            wallet_tx_type IN (
                'topup',
                'return_refund',
                'cancellation_refund',
                'payment_timeout_refund',
                'order_payment',
                'order_payment_refund',
                'withdrawal_reserve',
                'withdrawal_release',
                'withdrawal_complete'
            )
        );

ALTER TABLE orders
    ADD COLUMN order_wallet_transaction_id BIGINT UNSIGNED DEFAULT NULL
        AFTER order_stripe_session_id,
    ADD UNIQUE KEY uq_orders_wallet_transaction (
        order_wallet_transaction_id
    ),
    ADD CONSTRAINT fk_orders_wallet_transaction
        FOREIGN KEY (
            order_wallet_transaction_id
        )
        REFERENCES wallet_transactions (
            wallet_tx_id
        )
        ON DELETE RESTRICT;