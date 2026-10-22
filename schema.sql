-- ==============================================================================
-- VAuLT LMS Integration Gateway - Database Schema (LTI 1.1 & LTI 1.3 Advantage)
-- ==============================================================================
-- This schema represents the LMS integration entities used by vault_gateway.
-- It can be deployed in MariaDB/MySQL alongside VAuLT or in an independent database.
-- ==============================================================================

-- 1. LMS Platforms / Tool Consumers
-- Stores registered LMS platforms (Canvas, Moodle, Blackboard, etc.) with OAuth 1.0
-- consumer credentials (for LTI 1.1) and OIDC/JWKS configurations (for LTI 1.3).
CREATE TABLE IF NOT EXISTS `lti_platform` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(255) NOT NULL DEFAULT 'Canvas LMS',
  `consumer_key` VARCHAR(255) NOT NULL,
  `shared_secret` VARCHAR(255) NOT NULL,
  `issuer` VARCHAR(255) DEFAULT NULL,
  `client_id` VARCHAR(255) DEFAULT NULL,
  `deployment_id` VARCHAR(255) DEFAULT NULL,
  `auth_login_url` VARCHAR(500) DEFAULT NULL,
  `auth_token_url` VARCHAR(500) DEFAULT NULL,
  `key_set_url` VARCHAR(500) DEFAULT NULL,
  `private_key` TEXT DEFAULT NULL,
  `public_key` TEXT DEFAULT NULL,
  `key_id` VARCHAR(255) DEFAULT 'vault-lti-key-1',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_consumer_key` (`consumer_key`),
  KEY `idx_issuer_client` (`issuer`, `client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. LMS Contexts (Courses / Classrooms)
-- Tracks the specific course or context from which the tool was launched.
CREATE TABLE IF NOT EXISTS `lti_context` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `platform_id` INT(11) NOT NULL,
  `context_id` VARCHAR(255) NOT NULL,
  `title` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_platform_context` (`platform_id`, `context_id`),
  KEY `fk_lti_context_platform` (`platform_id`),
  CONSTRAINT `fk_lti_context_platform` FOREIGN KEY (`platform_id`) REFERENCES `lti_platform` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. LMS Resource Links (Assignments to Quest Bindings)
-- Maps a specific LMS assignment (resource_link_id) to a VAuLT quest (quest_id).
CREATE TABLE IF NOT EXISTS `lti_resource_link` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `platform_id` INT(11) NOT NULL,
  `context_id` INT(11) DEFAULT NULL,
  `resource_link_id` VARCHAR(255) NOT NULL,
  `quest_id` INT(11) DEFAULT NULL,
  `title` VARCHAR(255) DEFAULT NULL,
  `lineitem_url` VARCHAR(1000) DEFAULT NULL,
  `lis_outcome_service_url` VARCHAR(1000) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_platform_resource` (`platform_id`, `resource_link_id`),
  KEY `fk_lti_resource_platform` (`platform_id`),
  KEY `fk_lti_resource_context` (`context_id`),
  KEY `fk_lti_resource_quest` (`quest_id`),
  CONSTRAINT `fk_lti_resource_platform` FOREIGN KEY (`platform_id`) REFERENCES `lti_platform` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_lti_resource_context` FOREIGN KEY (`context_id`) REFERENCES `lti_context` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Student Launch Sessions & Outcomes
-- Tracks active student launch tokens, LIS grade passback endpoints, and completion scores.
CREATE TABLE IF NOT EXISTS `lti_launch_session` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `platform_id` INT(11) NOT NULL,
  `resource_link_id` INT(11) NOT NULL,
  `user_id` INT(11) DEFAULT NULL,
  `canvas_user_id` VARCHAR(255) NOT NULL,
  `token` VARCHAR(64) NOT NULL,
  `lis_result_sourcedid` TEXT DEFAULT NULL,
  `lis_outcome_service_url` VARCHAR(1000) DEFAULT NULL,
  `is_completed` TINYINT(4) NOT NULL DEFAULT 0,
  `grade_passed_back` TINYINT(4) NOT NULL DEFAULT 0,
  `score` FLOAT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_session_token` (`token`),
  KEY `fk_lti_session_platform` (`platform_id`),
  KEY `fk_lti_session_resource` (`resource_link_id`),
  KEY `fk_lti_session_user` (`user_id`),
  CONSTRAINT `fk_lti_session_platform` FOREIGN KEY (`platform_id`) REFERENCES `lti_platform` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_lti_session_resource` FOREIGN KEY (`resource_link_id`) REFERENCES `lti_resource_link` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed default platform credentials for Canvas Manual Entry
INSERT INTO `lti_platform` (`id`, `name`, `consumer_key`, `shared_secret`, `issuer`, `client_id`, `created_at`)
VALUES (1, 'Canvas LMS QA', 'vault_canvas_key', 'vault_canvas_secret_2026', 'https://canvas-production-30c6.up.railway.app', 'canvas_vault_client', NOW())
ON DUPLICATE KEY UPDATE `consumer_key` = VALUES(`consumer_key`), `shared_secret` = VALUES(`shared_secret`);
