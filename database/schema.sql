-- E-Assessment PT Lampung Jasa Utama
-- Jalankan pada database aplikasi E-Assessment, BUKAN pada database SSO.
-- Database ini menyimpan ID users sebagai referensi; tidak mengubah tabel SSO users.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS assessment_modules (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(40) NOT NULL,
    name VARCHAR(180) NOT NULL,
    category VARCHAR(60) NOT NULL DEFAULT 'OTHER',
    module_kind ENUM('standard', 'review_360') NOT NULL DEFAULT 'standard',
    description TEXT NULL,
    duration_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    question_selection_mode ENUM('all', 'selected', 'random') NOT NULL DEFAULT 'all',
    random_question_count SMALLINT UNSIGNED NULL,
    shuffle_questions TINYINT(1) NOT NULL DEFAULT 0,
    shuffle_options TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('draft', 'published', 'archived') NOT NULL DEFAULT 'draft',
    created_by_user_id INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_assessment_modules_code (code),
    KEY idx_assessment_modules_status (status, category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS question_bank (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(50) NULL,
    category VARCHAR(60) NOT NULL DEFAULT 'OTHER',
    question_type ENUM('single_choice', 'multi_select', 'forced_choice', 'essay', 'file_upload') NOT NULL,
    question_text LONGTEXT NOT NULL,
    question_image_path VARCHAR(500) NULL,
    question_image_alt VARCHAR(255) NULL,
    instructions LONGTEXT NULL,
    papi_item_code VARCHAR(40) NULL,
    papi_aspect_code VARCHAR(40) NULL,
    rubric LONGTEXT NULL,
    reference_file_path VARCHAR(500) NULL,
    reference_file_name VARCHAR(255) NULL,
    reference_mime_type VARCHAR(150) NULL,
    allowed_extensions VARCHAR(255) NULL,
    max_file_size_mb SMALLINT UNSIGNED NULL,
    max_score DECIMAL(8,2) NOT NULL DEFAULT 1.00,
    weight DECIMAL(8,2) NOT NULL DEFAULT 1.00,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by_user_id INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_question_bank_code (code),
    KEY idx_question_bank_category_type (category, question_type, is_active),
    KEY idx_question_bank_papi (papi_item_code, papi_aspect_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS question_options (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    question_id BIGINT UNSIGNED NOT NULL,
    option_key VARCHAR(20) NOT NULL,
    option_text LONGTEXT NOT NULL,
    option_image_path VARCHAR(500) NULL,
    is_correct TINYINT(1) NOT NULL DEFAULT 0,
    score_value DECIMAL(8,2) NOT NULL DEFAULT 0.00,
    papi_aspect_code VARCHAR(40) NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_question_option_key (question_id, option_key),
    KEY idx_question_options_order (question_id, sort_order),
    CONSTRAINT fk_question_options_question
        FOREIGN KEY (question_id) REFERENCES question_bank (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS module_questions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    module_id BIGINT UNSIGNED NOT NULL,
    question_id BIGINT UNSIGNED NOT NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    points_override DECIMAL(8,2) NULL,
    is_required TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_module_question (module_id, question_id),
    KEY idx_module_questions_order (module_id, sort_order),
    CONSTRAINT fk_module_questions_module
        FOREIGN KEY (module_id) REFERENCES assessment_modules (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_module_questions_question
        FOREIGN KEY (question_id) REFERENCES question_bank (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS test_packages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(40) NOT NULL,
    name VARCHAR(180) NOT NULL,
    package_type ENUM('recruitment', 'internal', 'review_360') NOT NULL DEFAULT 'recruitment',
    description TEXT NULL,
    target_position VARCHAR(150) NULL,
    passing_score DECIMAL(8,2) NULL,
    status ENUM('draft', 'published', 'archived') NOT NULL DEFAULT 'draft',
    created_by_user_id INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_test_packages_code (code),
    KEY idx_test_packages_status (status, package_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS package_modules (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    package_id BIGINT UNSIGNED NOT NULL,
    module_id BIGINT UNSIGNED NOT NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    duration_override_minutes SMALLINT UNSIGNED NULL,
    is_required TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_package_module (package_id, module_id),
    UNIQUE KEY uq_package_module_order (package_id, sort_order),
    KEY idx_package_modules_module (module_id),
    CONSTRAINT fk_package_modules_package
        FOREIGN KEY (package_id) REFERENCES test_packages (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_package_modules_module
        FOREIGN KEY (module_id) REFERENCES assessment_modules (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS exam_schedules (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    package_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(40) NOT NULL,
    name VARCHAR(180) NOT NULL,
    access_mode ENUM('employee_sso', 'external_token', 'mixed') NOT NULL DEFAULT 'employee_sso',
    instructions LONGTEXT NULL,
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NOT NULL,
    allow_retake TINYINT(1) NOT NULL DEFAULT 0,
    max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 1,
    status ENUM('draft', 'scheduled', 'open', 'closed', 'archived') NOT NULL DEFAULT 'draft',
    created_by_user_id INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_exam_schedules_code (code),
    KEY idx_exam_schedules_window (status, starts_at, ends_at),
    KEY idx_exam_schedules_package (package_id),
    CONSTRAINT fk_exam_schedules_package
        FOREIGN KEY (package_id) REFERENCES test_packages (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS external_candidates (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(191) NOT NULL,
    phone VARCHAR(40) NULL,
    nik VARCHAR(50) NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_external_candidates_email (email),
    KEY idx_external_candidates_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS schedule_participants (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    schedule_id BIGINT UNSIGNED NOT NULL,
    participant_type ENUM('employee', 'candidate') NOT NULL,
    sso_user_id INT NULL,
    candidate_id BIGINT UNSIGNED NULL,
    invite_token_hash CHAR(64) NULL,
    token_expires_at DATETIME NULL,
    status ENUM('invited', 'ready', 'in_progress', 'submitted', 'pending_review', 'completed', 'cancelled') NOT NULL DEFAULT 'invited',
    invited_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_schedule_employee (schedule_id, sso_user_id),
    UNIQUE KEY uq_schedule_candidate (schedule_id, candidate_id),
    KEY idx_participants_token (invite_token_hash),
    KEY idx_participants_status (schedule_id, status),
    CONSTRAINT fk_schedule_participants_schedule
        FOREIGN KEY (schedule_id) REFERENCES exam_schedules (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_schedule_participants_candidate
        FOREIGN KEY (candidate_id) REFERENCES external_candidates (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS review_assignments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    schedule_id BIGINT UNSIGNED NOT NULL,
    reviewer_user_id INT NOT NULL,
    subject_user_id INT NOT NULL,
    status ENUM('assigned', 'in_progress', 'submitted', 'pending_review', 'reviewed', 'cancelled') NOT NULL DEFAULT 'assigned',
    assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_360_assignment (schedule_id, reviewer_user_id, subject_user_id),
    KEY idx_360_reviewer (reviewer_user_id, schedule_id, status),
    KEY idx_360_subject (subject_user_id, schedule_id),
    CONSTRAINT fk_360_assignment_schedule
        FOREIGN KEY (schedule_id) REFERENCES exam_schedules (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS exam_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    schedule_participant_id BIGINT UNSIGNED NOT NULL,
    attempt_number TINYINT UNSIGNED NOT NULL DEFAULT 1,
    status ENUM('in_progress', 'submitted', 'auto_submitted', 'pending_review', 'completed', 'voided') NOT NULL DEFAULT 'in_progress',
    started_at DATETIME NOT NULL,
    submitted_at DATETIME NULL,
    auto_submitted TINYINT(1) NOT NULL DEFAULT 0,
    objective_score DECIMAL(8,2) NULL,
    manual_score DECIMAL(8,2) NULL,
    total_score DECIMAL(8,2) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_participant_attempt_number (schedule_participant_id, attempt_number),
    KEY idx_exam_attempts_status (status, submitted_at),
    CONSTRAINT fk_exam_attempts_participant
        FOREIGN KEY (schedule_participant_id) REFERENCES schedule_participants (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attempt_module_progress (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    attempt_id BIGINT UNSIGNED NOT NULL,
    package_module_id BIGINT UNSIGNED NOT NULL,
    status ENUM('not_started', 'in_progress', 'completed', 'skipped') NOT NULL DEFAULT 'not_started',
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    time_remaining_seconds INT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_attempt_package_module (attempt_id, package_module_id),
    CONSTRAINT fk_module_progress_attempt
        FOREIGN KEY (attempt_id) REFERENCES exam_attempts (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_module_progress_package_module
        FOREIGN KEY (package_module_id) REFERENCES package_modules (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attempt_module_questions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    attempt_module_progress_id BIGINT UNSIGNED NOT NULL,
    question_id BIGINT UNSIGNED NOT NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_attempt_progress_question (attempt_module_progress_id, question_id),
    UNIQUE KEY uq_attempt_progress_question_order (attempt_module_progress_id, sort_order),
    KEY idx_attempt_module_questions_question (question_id),
    CONSTRAINT fk_attempt_module_questions_progress
        FOREIGN KEY (attempt_module_progress_id) REFERENCES attempt_module_progress (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_attempt_module_questions_question
        FOREIGN KEY (question_id) REFERENCES question_bank (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attempt_question_options (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    attempt_module_question_id BIGINT UNSIGNED NOT NULL,
    question_option_id BIGINT UNSIGNED NOT NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_attempt_question_option (attempt_module_question_id, question_option_id),
    UNIQUE KEY uq_attempt_question_option_order (attempt_module_question_id, sort_order),
    KEY idx_attempt_question_options_option (question_option_id),
    CONSTRAINT fk_attempt_question_options_question
        FOREIGN KEY (attempt_module_question_id) REFERENCES attempt_module_questions (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_attempt_question_options_option
        FOREIGN KEY (question_option_id) REFERENCES question_options (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attempt_answers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    attempt_id BIGINT UNSIGNED NOT NULL,
    module_id BIGINT UNSIGNED NOT NULL,
    question_id BIGINT UNSIGNED NOT NULL,
    review_assignment_id BIGINT UNSIGNED NULL,
    answer_text LONGTEXT NULL,
    answer_payload JSON NULL,
    auto_score DECIMAL(8,2) NULL,
    manual_score DECIMAL(8,2) NULL,
    review_status ENUM('not_required', 'pending', 'reviewed') NOT NULL DEFAULT 'pending',
    saved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_answers_attempt_module (attempt_id, module_id),
    KEY idx_answers_question (question_id),
    KEY idx_answers_review_assignment (review_assignment_id),
    CONSTRAINT fk_attempt_answers_attempt
        FOREIGN KEY (attempt_id) REFERENCES exam_attempts (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_attempt_answers_module
        FOREIGN KEY (module_id) REFERENCES assessment_modules (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_attempt_answers_question
        FOREIGN KEY (question_id) REFERENCES question_bank (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_attempt_answers_360_assignment
        FOREIGN KEY (review_assignment_id) REFERENCES review_assignments (id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attempt_answer_files (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    answer_id BIGINT UNSIGNED NOT NULL,
    original_file_name VARCHAR(255) NOT NULL,
    stored_file_path VARCHAR(500) NOT NULL,
    mime_type VARCHAR(150) NOT NULL,
    file_size_bytes BIGINT UNSIGNED NOT NULL,
    uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_answer_files_answer (answer_id),
    CONSTRAINT fk_answer_files_answer
        FOREIGN KEY (answer_id) REFERENCES attempt_answers (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS assessment_reviews (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    attempt_id BIGINT UNSIGNED NOT NULL,
    answer_id BIGINT UNSIGNED NULL,
    review_type ENUM('essay', 'practical', 'psychometric', 'papi', 'review_360', 'overall') NOT NULL,
    reviewer_user_id INT NULL,
    status ENUM('pending', 'in_progress', 'completed') NOT NULL DEFAULT 'pending',
    score DECIMAL(8,2) NULL,
    recommendation ENUM('recommended', 'consider', 'not_recommended') NULL,
    strengths TEXT NULL,
    development_areas TEXT NULL,
    notes LONGTEXT NULL,
    report_file_path VARCHAR(500) NULL,
    reviewed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_assessment_reviews_queue (status, review_type, created_at),
    KEY idx_assessment_reviews_attempt (attempt_id, review_type),
    KEY idx_assessment_reviews_answer (answer_id),
    CONSTRAINT fk_assessment_reviews_attempt
        FOREIGN KEY (attempt_id) REFERENCES exam_attempts (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_assessment_reviews_answer
        FOREIGN KEY (answer_id) REFERENCES attempt_answers (id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attempt_papi_scores (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    attempt_id BIGINT UNSIGNED NOT NULL,
    aspect_code VARCHAR(40) NOT NULL,
    raw_score DECIMAL(8,2) NOT NULL DEFAULT 0.00,
    PRIMARY KEY (id),
    UNIQUE KEY uq_attempt_papi_aspect (attempt_id, aspect_code),
    CONSTRAINT fk_attempt_papi_scores_attempt
        FOREIGN KEY (attempt_id) REFERENCES exam_attempts (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notification_outbox (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    schedule_participant_id BIGINT UNSIGNED NULL,
    recipient_email VARCHAR(191) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    body LONGTEXT NOT NULL,
    status ENUM('queued', 'sent', 'failed') NOT NULL DEFAULT 'queued',
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    last_error TEXT NULL,
    sent_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_notification_outbox_status (status, created_at),
    CONSTRAINT fk_notification_outbox_participant
        FOREIGN KEY (schedule_participant_id) REFERENCES schedule_participants (id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    actor_user_id INT NULL,
    action VARCHAR(80) NOT NULL,
    entity_type VARCHAR(80) NOT NULL,
    entity_id BIGINT UNSIGNED NULL,
    metadata JSON NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audit_logs_entity (entity_type, entity_id, created_at),
    KEY idx_audit_logs_actor (actor_user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
