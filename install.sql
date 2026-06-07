-- Email MFA V2 — table schema.
--
-- This file is loaded by the module's install() method, exactly
-- the way HostBill's built-in locations_v2 module bootstraps its
-- tables. Statements are separated by the literal token "######"
-- (see class.locations_v2.php line 53).
--
-- The hb_email_mfa_codes table is the source of truth for issued
-- OTPs. Plaintext codes are never stored — only SHA-256 hashes.
--
-- Indexes:
--   idx_user_active  covers the hot read path (verify + cache warm)
--   idx_hash         used during cross-user fallback disambiguation
--   idx_expires      used by cron cleanup

CREATE TABLE IF NOT EXISTS `hb_email_mfa_codes` (
    `id`         BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_type`  ENUM('Admin','Client') NOT NULL,
    `user_id`    INT(10) UNSIGNED NOT NULL,
    `module_id`  INT(10) UNSIGNED NOT NULL,
    `code_hash`  CHAR(64) NOT NULL,
    `purpose`    ENUM('login','setup','action') NOT NULL DEFAULT 'login',
    `expires_at` DATETIME NOT NULL,
    `used_at`    DATETIME DEFAULT NULL,
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `user_agent` VARCHAR(255) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user_active` (`user_type`, `user_id`, `purpose`, `expires_at`, `used_at`),
    KEY `idx_hash`        (`code_hash`),
    KEY `idx_expires`     (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
######
