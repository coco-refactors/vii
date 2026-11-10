-- Sprint 4: quest attempts
-- Apply to the Vii database after initial-tables.sql
-- @author: Calvin Nguyen

CREATE TABLE IF NOT EXISTS `quest_attempt` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `user_id` int(11) NOT NULL,
    `quest_id` int(11) NOT NULL,
    `attempt_number` int(11) NOT NULL,
    `launch_ref` varchar(64) DEFAULT NULL,
    `status` enum('open','finished','abandoned') NOT NULL DEFAULT 'open',
    `started_at` datetime NOT NULL DEFAULT current_timestamp(),
    `finished_at` datetime DEFAULT NULL,
    `tasks_completed` int(11) DEFAULT NULL,
    `tasks_total` int(11) DEFAULT NULL,
    `score` decimal(5,4) DEFAULT NULL,
    `progress_sent` tinyint(4) NOT NULL DEFAULT 0,
    `hook_status` enum('none','pending','confirmed','failed') NOT NULL DEFAULT 'none',
    `hook_tries` int(11) NOT NULL DEFAULT 0,
    `next_retry_at` datetime DEFAULT NULL,
    `last_hook_error` varchar(255) DEFAULT NULL,
    `created_at` datetime NOT NULL DEFAULT current_timestamp(),
    `updated_at` datetime DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_quest_attempt_number` (`user_id`, `quest_id`, `attempt_number`),
    KEY `idx_quest_attempt_hook` (`hook_status`, `next_retry_at`),
    KEY `fk_quest_attempt_quest1_idx` (`quest_id`),
    CONSTRAINT `fk_quest_attempt_user1`
    FOREIGN KEY (`user_id`) REFERENCES `user` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_quest_attempt_quest1`
    FOREIGN KEY (`quest_id`) REFERENCES `quest` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;