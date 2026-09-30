-- 001_lesson_progress.sql
--
-- Adds persistent per-student lesson completion. Before this, the course page
-- rendered a hardcoded "25% / 3 of 12 lessons" panel and the "Mark Complete"
-- button only restyled itself; nothing was ever stored.
--
-- Safe to run more than once. Run against an existing database with:
--     mysql -u USER -p DBNAME < database/migrations/001_lesson_progress.sql
--
-- A single row per (user, lesson) pair is kept whether or not the lesson is
-- done, so a later "mark not complete" is a flag flip rather than a delete,
-- and completed_at records when the work was actually finished.

CREATE TABLE IF NOT EXISTS `lesson_progress` (
    `progress_id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `lesson_id` INT NOT NULL,
    `is_completed` TINYINT(1) NOT NULL DEFAULT 0,
    `completed_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_user_lesson` (`user_id`, `lesson_id`),
    KEY `idx_lesson_progress_user` (`user_id`),
    KEY `idx_lesson_progress_lesson` (`lesson_id`),
    CONSTRAINT `fk_lesson_progress_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_lesson_progress_lesson` FOREIGN KEY (`lesson_id`) REFERENCES `lessons` (`lesson_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
