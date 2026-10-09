-- =====================================================================
-- Migration 0003: Phase 2 authentication additions
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE email_verifications (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     BIGINT UNSIGNED NOT NULL,
    email       VARCHAR(191) NOT NULL COMMENT 'address being verified (user may change email later)',
    token_hash  CHAR(64) NOT NULL,
    expires_at  DATETIME NOT NULL,
    used_at     DATETIME NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_email_verifications_token (token_hash),
    KEY idx_email_verifications_user (user_id),
    CONSTRAINT fk_email_verifications_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE users
    ADD COLUMN terms_accepted_at   DATETIME NULL AFTER email_verified_at,
    ADD COLUMN password_changed_at DATETIME NULL AFTER password_hash;
