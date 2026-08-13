ALTER TABLE orders
    MODIFY COLUMN order_payment_method
        ENUM(
            'stripe_card',
            'wallet'
        )
        DEFAULT NULL;