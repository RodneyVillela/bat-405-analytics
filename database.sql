CREATE DATABASE IF NOT EXISTS bat405_analytics
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE bat405_analytics;

CREATE TABLE IF NOT EXISTS app_users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(254) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('program_chair') NOT NULL DEFAULT 'program_chair',
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_app_users_email UNIQUE (email)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS students (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    section CHAR(4) NOT NULL,
    track ENUM('BA', 'NT') NOT NULL,
    CONSTRAINT chk_students_section CHECK (section IN ('4101', '4102', '4103', '4104', '4105')),
    CONSTRAINT chk_students_track_section CHECK (
        (section IN ('4101', '4102') AND track = 'BA')
        OR (section IN ('4103', '4104', '4105') AND track = 'NT')
    ),
    INDEX idx_students_section (section),
    INDEX idx_students_track (track)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS faculty (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    department VARCHAR(120) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS grades (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id INT UNSIGNED NOT NULL,
    core_subject VARCHAR(120) NOT NULL,
    grade DECIMAL(5,2) NOT NULL,
    status ENUM('Pass', 'Fail') NOT NULL,
    semester ENUM('1st Semester', '2nd Semester', 'Summer') NOT NULL,
    academic_year CHAR(9) NOT NULL,
    CONSTRAINT chk_grades_range CHECK (grade >= 0 AND grade <= 100),
    CONSTRAINT fk_grades_student FOREIGN KEY (student_id) REFERENCES students(id)
        ON DELETE CASCADE,
    INDEX idx_grades_term (academic_year, semester),
    INDEX idx_grades_score (grade)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sete_evaluations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    faculty_id INT UNSIGNED NOT NULL,
    score DECIMAL(3,2) NOT NULL,
    semester ENUM('1st Semester', '2nd Semester', 'Summer') NOT NULL,
    academic_year CHAR(9) NOT NULL,
    CONSTRAINT chk_sete_score CHECK (score >= 0 AND score <= 5),
    CONSTRAINT fk_sete_faculty FOREIGN KEY (faculty_id) REFERENCES faculty(id)
        ON DELETE CASCADE,
    INDEX idx_sete_term (academic_year, semester)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS grade_submissions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    faculty_id INT UNSIGNED NOT NULL,
    is_submitted BOOLEAN NOT NULL DEFAULT FALSE,
    deadline DATETIME NOT NULL,
    submitted_at DATETIME NULL,
    semester ENUM('1st Semester', '2nd Semester', 'Summer') NOT NULL,
    academic_year CHAR(9) NOT NULL,
    CONSTRAINT fk_submissions_faculty FOREIGN KEY (faculty_id) REFERENCES faculty(id)
        ON DELETE CASCADE,
    CONSTRAINT uq_submission_faculty_term UNIQUE (faculty_id, semester, academic_year),
    INDEX idx_submissions_term (academic_year, semester)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS analytics_data (
    student_id INT UNSIGNED PRIMARY KEY,
    attendance_rate DECIMAL(5,2) NOT NULL,
    prelim_score DECIMAL(5,2) NOT NULL,
    lms_activity_score DECIMAL(5,2) NOT NULL,
    past_grades_avg DECIMAL(5,2) NOT NULL,
    risk_classification ENUM('Safe', 'At-Risk') NOT NULL DEFAULT 'Safe',
    CONSTRAINT chk_analytics_ranges CHECK (
        attendance_rate BETWEEN 0 AND 100
        AND prelim_score BETWEEN 0 AND 100
        AND lms_activity_score BETWEEN 0 AND 100
        AND past_grades_avg BETWEEN 0 AND 100
    ),
    CONSTRAINT fk_analytics_student FOREIGN KEY (student_id) REFERENCES students(id)
        ON DELETE CASCADE
) ENGINE=InnoDB;


-- Seed rows are intentionally omitted; import approved records only.
-- De-identified labeled records for supervised Academic Difficulty Risk Model.
CREATE TABLE IF NOT EXISTS academic_risk_training_records (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_key CHAR(64) NOT NULL COMMENT 'Stable de-identified key; never a name or student number',
    section_code VARCHAR(20) NOT NULL,
    academic_year CHAR(9) NOT NULL,
    semester ENUM('1st Semester', '2nd Semester', 'Summer') NOT NULL,
    midterm_grade_average DECIMAL(5,2) NOT NULL,
    attendance_rate DECIMAL(5,2) NOT NULL,
    missing_portal_submissions TINYINT UNSIGNED NOT NULL,
    section_enrollment SMALLINT UNSIGNED NOT NULL,
    section_capacity SMALLINT UNSIGNED NOT NULL,
    sete_response_rate DECIMAL(5,2) NOT NULL,
    academic_difficulty_outcome TINYINT UNSIGNED NOT NULL COMMENT '1 = documented difficulty; 0 = no documented difficulty',
    outcome_definition VARCHAR(255) NOT NULL COMMENT 'Consistent approved outcome definition',
    recorded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_risk_midterm_range CHECK (midterm_grade_average BETWEEN 0 AND 100),
    CONSTRAINT chk_risk_attendance_range CHECK (attendance_rate BETWEEN 0 AND 100),
    CONSTRAINT chk_risk_sete_range CHECK (sete_response_rate BETWEEN 0 AND 100),
    CONSTRAINT chk_risk_capacity_positive CHECK (section_capacity > 0),
    CONSTRAINT chk_risk_outcome_binary CHECK (academic_difficulty_outcome IN (0, 1)),
    CONSTRAINT uq_risk_student_term UNIQUE (student_key, section_code, academic_year, semester),
    INDEX idx_risk_outcome_term (academic_difficulty_outcome, academic_year, semester),
    INDEX idx_risk_section_term (section_code, academic_year, semester)
) ENGINE=InnoDB;

-- The model capacity feature is 100 * section_enrollment / section_capacity.
