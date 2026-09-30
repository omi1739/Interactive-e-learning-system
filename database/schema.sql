-- =======================================================
-- Interactive e-Learning System - Database Schema
-- =======================================================
-- Import this file INTO an existing, empty database, e.g.:
--   phpMyAdmin -> select your database -> Import -> choose this file
-- It does not create or switch databases itself, so it works even on
-- shared hosts whose MySQL user cannot run CREATE DATABASE.
-- =======================================================

SET FOREIGN_KEY_CHECKS = 0;

-- 1. Users Table
DROP TABLE IF EXISTS `review_scores`;
DROP TABLE IF EXISTS `peer_reviews`;
DROP TABLE IF EXISTS `rubrics`;
DROP TABLE IF EXISTS `submissions`;
DROP TABLE IF EXISTS `assignments`;
DROP TABLE IF EXISTS `lessons`;
DROP TABLE IF EXISTS `modules`;
DROP TABLE IF EXISTS `forum_posts`;
DROP TABLE IF EXISTS `forums`;
DROP TABLE IF EXISTS `enrollments`;
DROP TABLE IF EXISTS `courses`;
DROP TABLE IF EXISTS `users`;

CREATE TABLE `users` (
    `user_id` INT AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) NOT NULL UNIQUE,
    `email` VARCHAR(100) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `first_name` VARCHAR(50) NOT NULL,
    `last_name` VARCHAR(50) NOT NULL,
    `role` ENUM('student', 'instructor', 'admin') NOT NULL DEFAULT 'student',
    `profile_picture` VARCHAR(255) DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `last_login` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Courses Table
CREATE TABLE `courses` (
    `course_id` INT AUTO_INCREMENT PRIMARY KEY,
    `instructor_id` INT NOT NULL,
    `title` VARCHAR(200) NOT NULL,
    `course_code` VARCHAR(20) NOT NULL UNIQUE,
    `description` TEXT DEFAULT NULL,
    `max_students` INT NOT NULL DEFAULT 50,
    `start_date` DATE DEFAULT NULL,
    `end_date` DATE DEFAULT NULL,
    `is_published` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_courses_instructor` FOREIGN KEY (`instructor_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Enrollments Table
CREATE TABLE `enrollments` (
    `enrollment_id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `course_id` INT NOT NULL,
    `enrollment_status` ENUM('pending', 'approved', 'rejected', 'completed') NOT NULL DEFAULT 'pending',
    `grade` DECIMAL(5,2) DEFAULT NULL,
    `enrolled_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_user_course` (`user_id`, `course_id`),
    CONSTRAINT `fk_enrollments_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_enrollments_course` FOREIGN KEY (`course_id`) REFERENCES `courses` (`course_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Modules Table
CREATE TABLE `modules` (
    `module_id` INT AUTO_INCREMENT PRIMARY KEY,
    `course_id` INT NOT NULL,
    `title` VARCHAR(200) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `module_order` INT NOT NULL DEFAULT 1,
    `is_published` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_modules_course` FOREIGN KEY (`course_id`) REFERENCES `courses` (`course_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Lessons Table
CREATE TABLE `lessons` (
    `lesson_id` INT AUTO_INCREMENT PRIMARY KEY,
    `module_id` INT NOT NULL,
    `title` VARCHAR(200) NOT NULL,
    `content` TEXT DEFAULT NULL,
    `content_type` ENUM('text', 'video', 'document', 'images') NOT NULL DEFAULT 'text',
    `duration_minutes` INT DEFAULT 15,
    `lesson_order` INT NOT NULL DEFAULT 1,
    `is_published` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_lessons_module` FOREIGN KEY (`module_id`) REFERENCES `modules` (`module_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Lesson Progress Table
-- One row per student per lesson, created when they first mark it, so
-- completion is a flag flip rather than an insert-and-delete. See
-- database/migrations/001_lesson_progress.sql for the upgrade path.
CREATE TABLE `lesson_progress` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Assignments Table
CREATE TABLE `assignments` (
    `assignment_id` INT AUTO_INCREMENT PRIMARY KEY,
    `module_id` INT NOT NULL,
    `title` VARCHAR(200) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `assignment_type` VARCHAR(50) NOT NULL DEFAULT 'assignment',
    `max_points` DECIMAL(5,2) NOT NULL DEFAULT 100.00,
    `due_date` DATETIME DEFAULT NULL,
    `submission_format` ENUM('text', 'file', 'both') NOT NULL DEFAULT 'file',
    `max_file_size` INT NOT NULL DEFAULT 10,
    `allowed_file_types` VARCHAR(100) DEFAULT 'pdf,doc,docx,zip,txt',
    `is_published` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_assignments_module` FOREIGN KEY (`module_id`) REFERENCES `modules` (`module_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Submissions Table
CREATE TABLE `submissions` (
    `submission_id` INT AUTO_INCREMENT PRIMARY KEY,
    `assignment_id` INT NOT NULL,
    `student_id` INT NOT NULL,
    `submission_text` LONGTEXT DEFAULT NULL,
    `file_path` VARCHAR(255) DEFAULT NULL,
    `file_name` VARCHAR(255) DEFAULT NULL,
    `status` ENUM('draft', 'submitted', 'graded') NOT NULL DEFAULT 'submitted',
    `final_grade` DECIMAL(5,2) DEFAULT NULL,
    `instructor_feedback` TEXT DEFAULT NULL,
    `submission_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_student_assignment` (`assignment_id`, `student_id`),
    CONSTRAINT `fk_submissions_assignment` FOREIGN KEY (`assignment_id`) REFERENCES `assignments` (`assignment_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_submissions_student` FOREIGN KEY (`student_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Rubrics Table
CREATE TABLE `rubrics` (
    `rubric_id` INT AUTO_INCREMENT PRIMARY KEY,
    `assignment_id` INT NOT NULL,
    `criterion_name` VARCHAR(200) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `max_score` DECIMAL(5,2) NOT NULL DEFAULT 10.00,
    `weight` DECIMAL(3,2) NOT NULL DEFAULT 1.00,
    `rubric_order` INT NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_rubrics_assignment` FOREIGN KEY (`assignment_id`) REFERENCES `assignments` (`assignment_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. Peer Reviews Table
CREATE TABLE `peer_reviews` (
    `review_id` INT AUTO_INCREMENT PRIMARY KEY,
    `submission_id` INT NOT NULL,
    `reviewer_id` INT NOT NULL,
    `status` ENUM('in_progress', 'completed') NOT NULL DEFAULT 'in_progress',
    `overall_feedback` TEXT DEFAULT NULL,
    `is_anonymous` TINYINT(1) NOT NULL DEFAULT 1,
    `review_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_submission_reviewer` (`submission_id`, `reviewer_id`),
    CONSTRAINT `fk_peer_reviews_submission` FOREIGN KEY (`submission_id`) REFERENCES `submissions` (`submission_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_peer_reviews_reviewer` FOREIGN KEY (`reviewer_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. Review Scores Table
CREATE TABLE `review_scores` (
    `score_id` INT AUTO_INCREMENT PRIMARY KEY,
    `review_id` INT NOT NULL,
    `rubric_id` INT NOT NULL,
    `score` DECIMAL(5,2) NOT NULL,
    `feedback` TEXT DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_review_scores_review` FOREIGN KEY (`review_id`) REFERENCES `peer_reviews` (`review_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_review_scores_rubric` FOREIGN KEY (`rubric_id`) REFERENCES `rubrics` (`rubric_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. Forums Table
CREATE TABLE `forums` (
    `forum_id` INT AUTO_INCREMENT PRIMARY KEY,
    `course_id` INT NOT NULL,
    `title` VARCHAR(200) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `is_locked` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_forums_course` FOREIGN KEY (`course_id`) REFERENCES `courses` (`course_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 13. Forum Posts Table
CREATE TABLE `forum_posts` (
    `post_id` INT AUTO_INCREMENT PRIMARY KEY,
    `forum_id` INT NOT NULL,
    `user_id` INT NOT NULL,
    `parent_post_id` INT DEFAULT NULL,
    `title` VARCHAR(200) DEFAULT NULL,
    `content` TEXT NOT NULL,
    `is_pinned` TINYINT(1) NOT NULL DEFAULT 0,
    `is_locked` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_forum_posts_forum` FOREIGN KEY (`forum_id`) REFERENCES `forums` (`forum_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_forum_posts_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_forum_posts_parent` FOREIGN KEY (`parent_post_id`) REFERENCES `forum_posts` (`post_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- =======================================================
-- NO SEED DATA
-- =======================================================
-- The demo dataset (users with the shared password "password123", sample
-- courses, submissions, reviews and forum posts) has been removed. Those
-- accounts were reachable with publicly known credentials, so they must not be
-- imported into a real deployment.
--
-- This file is identical in content to database/schema-only.sql. It is kept so
-- older instructions that reference "schema.sql" still work.
--
-- After importing, create the first real administrator from the terminal:
--     php install/create_admin.php
-- =======================================================
