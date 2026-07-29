-- MangaVault birthday voucher and reward tracking
-- Run once in phpMyAdmin before opening the updated Tier Management page.

ALTER TABLE tier_config
    ADD COLUMN tier_birthday_voucher_id INT NULL
        AFTER tier_birthday_bonus_points,
    ADD COLUMN tier_birthday_voucher_valid_days INT NOT NULL
        DEFAULT 30
        AFTER tier_birthday_voucher_id,
    ADD KEY idx_tier_birthday_voucher (
        tier_birthday_voucher_id
    );

ALTER TABLE tier_config
    ADD CONSTRAINT fk_tier_birthday_voucher
    FOREIGN KEY (tier_birthday_voucher_id)
    REFERENCES vouchers (voucher_id)
    ON DELETE SET NULL;

ALTER TABLE vouchers
    ADD COLUMN voucher_template_id INT NULL
        AFTER voucher_is_points_redeem,
    ADD COLUMN voucher_is_system_generated TINYINT(1)
        NOT NULL DEFAULT 0
        AFTER voucher_template_id,
    ADD KEY idx_voucher_template (
        voucher_template_id
    ),
    ADD KEY idx_voucher_system_generated (
        voucher_is_system_generated
    );

ALTER TABLE vouchers
    ADD CONSTRAINT fk_voucher_template
    FOREIGN KEY (voucher_template_id)
    REFERENCES vouchers (voucher_id)
    ON DELETE SET NULL;

CREATE TABLE birthday_reward_awards (
    birthday_award_id INT NOT NULL AUTO_INCREMENT,
    birthday_award_user_id INT NOT NULL,
    birthday_award_year SMALLINT NOT NULL,
    birthday_award_tier ENUM(
        'bronze',
        'silver',
        'gold',
        'platinum'
    ) NOT NULL,
    birthday_award_points INT NOT NULL DEFAULT 0,
    birthday_award_template_voucher_id INT NULL,
    birthday_award_generated_voucher_id INT NULL,
    birthday_award_created_at DATETIME NOT NULL
        DEFAULT CURRENT_TIMESTAMP,
    birthday_award_updated_at DATETIME NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (birthday_award_id),
    UNIQUE KEY unique_birthday_award_year (
        birthday_award_user_id,
        birthday_award_year
    ),
    KEY idx_birthday_award_template (
        birthday_award_template_voucher_id
    ),
    KEY idx_birthday_award_generated (
        birthday_award_generated_voucher_id
    ),
    CONSTRAINT fk_birthday_award_user
        FOREIGN KEY (birthday_award_user_id)
        REFERENCES users (user_id)
        ON DELETE CASCADE,
    CONSTRAINT fk_birthday_award_template
        FOREIGN KEY (
            birthday_award_template_voucher_id
        )
        REFERENCES vouchers (voucher_id)
        ON DELETE SET NULL,
    CONSTRAINT fk_birthday_award_generated
        FOREIGN KEY (
            birthday_award_generated_voucher_id
        )
        REFERENCES vouchers (voucher_id)
        ON DELETE SET NULL
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_general_ci;
