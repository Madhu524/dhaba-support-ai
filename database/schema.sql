-- ============================================================
-- Dhaba Support AI - Database Schema
-- Database: dhaba_support
-- Compatible with: MariaDB 10.x / MySQL 8.x
-- ============================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

-- Create and select database
CREATE DATABASE IF NOT EXISTS `dhaba_support`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `dhaba_support`;

-- ============================================================
-- TABLE: users
-- Support agent and administrator accounts
-- ============================================================
CREATE TABLE IF NOT EXISTS `users` (
    `id`            INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `name`          VARCHAR(120)     NOT NULL,
    `email`         VARCHAR(180)     NOT NULL,
    `password_hash` VARCHAR(255)     NOT NULL,
    `role`          ENUM('agent','admin') NOT NULL DEFAULT 'agent',
    `is_active`     TINYINT(1)       NOT NULL DEFAULT 1,
    `created_at`    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_users_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: api_tokens
-- Authentication tokens (hashed)
-- ============================================================
CREATE TABLE IF NOT EXISTS `api_tokens` (
    `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `user_id`    INT UNSIGNED  NOT NULL,
    `token_hash` VARCHAR(255)  NOT NULL,
    `expires_at` DATETIME      NOT NULL,
    `created_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_api_tokens_hash` (`token_hash`),
    KEY `idx_api_tokens_user` (`user_id`),
    KEY `idx_api_tokens_expires` (`expires_at`),
    CONSTRAINT `fk_api_tokens_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: tickets
-- Customer support tickets
-- ============================================================
CREATE TABLE IF NOT EXISTS `tickets` (
    `id`                      INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    `ticket_id`               VARCHAR(50)    NOT NULL,
    `received_at`             DATETIME       NOT NULL,
    `subject`                 VARCHAR(500)   NOT NULL,
    `body`                    TEXT           NOT NULL,
    `app_opens_since_renewal` INT UNSIGNED   NOT NULL DEFAULT 0,
    `status`                  ENUM('new','triaged','in_review','resolved','closed') NOT NULL DEFAULT 'new',
    `assigned_to`             INT UNSIGNED   NULL DEFAULT NULL,
    `created_at`              DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`              DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_tickets_ticket_id` (`ticket_id`),
    KEY `idx_tickets_status` (`status`),
    KEY `idx_tickets_received` (`received_at`),
    KEY `idx_tickets_assigned` (`assigned_to`),
    CONSTRAINT `fk_tickets_assigned`
        FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: purchases
-- Purchase records associated with tickets
-- ============================================================
CREATE TABLE IF NOT EXISTS `purchases` (
    `id`            INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    `ticket_id`     VARCHAR(50)       NOT NULL,
    `purchase_id`   VARCHAR(100)      NOT NULL,
    `purchase_type` VARCHAR(100)      NOT NULL,
    `amount_inr`    DECIMAL(12,2)     NOT NULL DEFAULT 0.00,
    `status`        ENUM('success','failed','initiated','refunded','pending') NOT NULL,
    `purchased_at`  DATETIME          NOT NULL,
    `created_at`    DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_purchases_purchase_id` (`purchase_id`),
    KEY `idx_purchases_ticket` (`ticket_id`),
    KEY `idx_purchases_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: triage_results
-- AI triage decisions for tickets
-- ============================================================
CREATE TABLE IF NOT EXISTS `triage_results` (
    `id`                  INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `ticket_id`           VARCHAR(50)      NOT NULL,
    `category`            ENUM('billing','cancellation','technical','account','feature_request','complaint','general') NOT NULL DEFAULT 'general',
    `severity`            ENUM('low','medium','high','critical') NOT NULL DEFAULT 'low',
    `refund_requested`    TINYINT(1)       NOT NULL DEFAULT 0,
    `refund_recommended`  TINYINT(1)       NOT NULL DEFAULT 0,
    `refund_amount_inr`   DECIMAL(12,2)    NOT NULL DEFAULT 0.00,
    `refund_reason`       TEXT             NULL,
    `reply_draft`         TEXT             NOT NULL,
    `needs_human`         TINYINT(1)       NOT NULL DEFAULT 0,
    `confidence`          DECIMAL(4,3)     NOT NULL DEFAULT 0.500,
    `degraded`            TINYINT(1)       NOT NULL DEFAULT 0,
    `model_provider`      VARCHAR(50)      NOT NULL DEFAULT 'rule_based',
    `injection_detected`  TINYINT(1)       NOT NULL DEFAULT 0,
    `created_at`          DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`          DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_triage_ticket` (`ticket_id`),
    KEY `idx_triage_category` (`category`),
    KEY `idx_triage_severity` (`severity`),
    KEY `idx_triage_needs_human` (`needs_human`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: refund_requests
-- Refund recommendations awaiting human review
-- ============================================================
CREATE TABLE IF NOT EXISTS `refund_requests` (
    `id`                   INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `ticket_id`            VARCHAR(50)      NOT NULL,
    `purchase_id`          VARCHAR(100)     NOT NULL,
    `requested_amount_inr` DECIMAL(12,2)    NOT NULL DEFAULT 0.00,
    `approved_amount_inr`  DECIMAL(12,2)    NULL DEFAULT NULL,
    `status`               ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    `reason`               TEXT             NOT NULL,
    `reviewed_by`          INT UNSIGNED     NULL DEFAULT NULL,
    `reviewed_at`          DATETIME         NULL DEFAULT NULL,
    `created_at`           DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`           DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_refunds_ticket` (`ticket_id`),
    KEY `idx_refunds_status` (`status`),
    KEY `idx_refunds_purchase` (`purchase_id`),
    CONSTRAINT `fk_refunds_reviewer`
        FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: audit_events
-- Security-sensitive event log
-- ============================================================
CREATE TABLE IF NOT EXISTS `audit_events` (
    `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `ticket_id`  VARCHAR(50)   NULL DEFAULT NULL,
    `actor_id`   INT UNSIGNED  NULL DEFAULT NULL,
    `event_type` VARCHAR(100)  NOT NULL,
    `event_data` JSON          NULL,
    `created_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_audit_ticket` (`ticket_id`),
    KEY `idx_audit_actor` (`actor_id`),
    KEY `idx_audit_event_type` (`event_type`),
    KEY `idx_audit_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

