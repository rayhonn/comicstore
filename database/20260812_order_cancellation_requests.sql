CREATE TABLE IF NOT EXISTS order_cancellation_requests (
    cancel_request_id INT(11) NOT NULL AUTO_INCREMENT,
    cancel_request_order_id INT(11) NOT NULL,
    cancel_request_user_id INT(11) NOT NULL,
    cancel_request_reason VARCHAR(100) NOT NULL,
    cancel_request_details VARCHAR(500) DEFAULT NULL,
    cancel_request_created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (cancel_request_id),

    UNIQUE KEY uq_order_cancellation_request_order (
        cancel_request_order_id
    ),

    KEY idx_order_cancellation_request_user (
        cancel_request_user_id
    ),

    CONSTRAINT fk_order_cancellation_request_order
        FOREIGN KEY (cancel_request_order_id)
        REFERENCES orders (order_id)
        ON DELETE CASCADE,

    CONSTRAINT fk_order_cancellation_request_user
        FOREIGN KEY (cancel_request_user_id)
        REFERENCES users (user_id)
        ON DELETE CASCADE
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_general_ci;