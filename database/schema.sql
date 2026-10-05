-- ========================================================
-- AI Mailbox Pro Database Schema
-- Compatible with MySQL 5.7+ / MariaDB 10.3+
-- Charset: utf8mb4_unicode_ci
-- ========================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- 1. Connected Mailboxes (IMAP/SMTP accounts)
CREATE TABLE IF NOT EXISTS `mailboxes` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `email` VARCHAR(255) NOT NULL,
    `imap_host` VARCHAR(255) NOT NULL,
    `imap_port` INT DEFAULT 993,
    `imap_encryption` VARCHAR(10) DEFAULT 'ssl',
    `smtp_host` VARCHAR(255) NOT NULL,
    `smtp_port` INT DEFAULT 465,
    `smtp_encryption` VARCHAR(10) DEFAULT 'ssl',
    `username` VARCHAR(255) NOT NULL,
    `password_encrypted` TEXT NOT NULL,
    `is_default` TINYINT(1) DEFAULT 0,
    `is_active` TINYINT(1) DEFAULT 1,
    `daily_send_limit` INT DEFAULT 40,
    `warmup_status` ENUM('not_started', 'warming', 'ready', 'paused') DEFAULT 'not_started',
    `warmup_started_at` DATETIME NULL,
    `warmup_day` INT DEFAULT 0,
    `health_score` DECIMAL(5,2) DEFAULT 100.00,
    `last_health_check` DATETIME NULL,
    `pause_reason` TEXT NULL,
    `last_synced_at` DATETIME NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_email` (`email`),
    INDEX `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Email Messages (Synced inbound & sent outbound)
CREATE TABLE IF NOT EXISTS `email_messages` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `mailbox_id` INT NOT NULL,
    `message_uid` VARCHAR(255) NULL,
    `thread_id` VARCHAR(255) NOT NULL,
    `in_reply_to` VARCHAR(255) NULL,
    `references_header` TEXT NULL,
    `subject` VARCHAR(500) NULL,
    `from_email` VARCHAR(255) NOT NULL,
    `from_name` VARCHAR(255) NULL,
    `to_email` VARCHAR(255) NOT NULL,
    `body_text` LONGTEXT NULL,
    `body_html` LONGTEXT NULL,
    `direction` ENUM('inbound', 'outbound') DEFAULT 'inbound',
    `is_read` TINYINT(1) DEFAULT 0,
    `has_attachments` TINYINT(1) DEFAULT 0,
    `lead_id` INT NULL,
    `received_at` DATETIME NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_mailbox_id` (`mailbox_id`),
    INDEX `idx_thread` (`thread_id`),
    INDEX `idx_from` (`from_email`),
    INDEX `idx_to` (`to_email`),
    INDEX `idx_direction` (`direction`),
    INDEX `idx_received_at` (`received_at`),
    CONSTRAINT `fk_email_mailbox` FOREIGN KEY (`mailbox_id`) REFERENCES `mailboxes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Outgoing Email Queue & AI Drafts
CREATE TABLE IF NOT EXISTS `email_queue` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `mailbox_id` INT NULL,
    `lead_id` INT NULL,
    `to_email` VARCHAR(255) NOT NULL,
    `subject` VARCHAR(500) NOT NULL,
    `body` TEXT NOT NULL,
    `thread_id` VARCHAR(255) NULL,
    `status` ENUM('draft', 'approved', 'pending', 'processing', 'sent', 'failed') DEFAULT 'draft',
    `source` VARCHAR(50) DEFAULT 'manual' COMMENT 'manual, ai_draft, drip_campaign',
    `ai_reasoning` TEXT NULL,
    `bounce_type` VARCHAR(20) NULL COMMENT 'hard, soft, complaint',
    `tracking_pixel_id` VARCHAR(64) NULL,
    `error_message` TEXT NULL,
    `scheduled_for` DATETIME NULL,
    `sent_at` DATETIME NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_queue_status` (`status`),
    INDEX `idx_queue_mailbox` (`mailbox_id`),
    INDEX `idx_queue_scheduled` (`scheduled_for`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Deliverability & Health Events
CREATE TABLE IF NOT EXISTS `email_health_events` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `mailbox_id` INT NOT NULL,
    `event_type` ENUM('bounce_hard', 'bounce_soft', 'spam_complaint', 'auto_pause', 'manual_pause', 'resume') NOT NULL,
    `event_data` JSON NULL,
    `related_email_id` INT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_health_mailbox` (`mailbox_id`),
    INDEX `idx_health_type` (`event_type`),
    INDEX `idx_health_created` (`created_at`),
    CONSTRAINT `fk_health_mailbox` FOREIGN KEY (`mailbox_id`) REFERENCES `mailboxes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Email Warmup Logs
CREATE TABLE IF NOT EXISTS `warmup_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `mailbox_id` INT NOT NULL,
    `day_number` INT NOT NULL,
    `target_count` INT NOT NULL,
    `sent_count` INT DEFAULT 0,
    `received_count` INT DEFAULT 0,
    `spam_saved_count` INT DEFAULT 0,
    `status` ENUM('in_progress', 'completed', 'paused') DEFAULT 'in_progress',
    `log_date` DATE NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_warmup_mb_date` (`mailbox_id`, `log_date`),
    CONSTRAINT `fk_warmup_mailbox` FOREIGN KEY (`mailbox_id`) REFERENCES `mailboxes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
