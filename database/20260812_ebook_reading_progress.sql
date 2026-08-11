CREATE TABLE IF NOT EXISTS ebook_reading_progress (
    ebook_progress_id INT(11) NOT NULL AUTO_INCREMENT,
    ebook_progress_user_id INT(11) NOT NULL,
    ebook_progress_order_item_id INT(11) NOT NULL,
    ebook_progress_page INT(10) UNSIGNED DEFAULT NULL,
    ebook_progress_locator VARCHAR(1024) DEFAULT NULL,
    ebook_progress_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    ebook_progress_updated_at DATETIME NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (ebook_progress_id),

    UNIQUE KEY uq_ebook_reading_progress_user_item (
        ebook_progress_user_id,
        ebook_progress_order_item_id
    ),

    KEY idx_ebook_reading_progress_item (
        ebook_progress_order_item_id
    ),

    CONSTRAINT fk_ebook_reading_progress_user
        FOREIGN KEY (ebook_progress_user_id)
        REFERENCES users (user_id)
        ON DELETE CASCADE,

    CONSTRAINT fk_ebook_reading_progress_item
        FOREIGN KEY (ebook_progress_order_item_id)
        REFERENCES order_items (order_item_id)
        ON DELETE CASCADE,

    CONSTRAINT chk_ebook_reading_progress_page
        CHECK (
            ebook_progress_page IS NULL
            OR ebook_progress_page >= 1
        ),

    CONSTRAINT chk_ebook_reading_progress_percent
        CHECK (
            ebook_progress_percent >= 0.00
            AND ebook_progress_percent <= 100.00
        )
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_general_ci;