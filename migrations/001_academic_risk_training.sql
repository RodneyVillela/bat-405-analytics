-- Add the de-identified, term-level fields needed for supervised academic-risk
-- training. Apply to bat405_analytics after reviewing the outcome definition.
-- Do not load identifiable student records into this table.
CREATE TABLE IF NOT EXISTS academic_risk_training_records (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_key CHAR(64) NOT NULL COMMENT 'Stable de-identified key, e.g. SHA-256 of a local ID plus private salt',
    section_code VARCHAR(20) NOT NULL,
    academic_year CHAR(9) NOT NULL,
    semester ENUM('1st Semester', '2nd Semester', 'Summer') NOT NULL,
    midterm_grade_average DECIMAL(5,2) NOT NULL,
    attendance_rate DECIMAL(5,2) NOT NULL,
    missing_portal_submissions TINYINT UNSIGNED NOT NULL,
    section_enrollment SMALLINT UNSIGNED NOT NULL,
    section_capacity SMALLINT UNSIGNED NOT NULL,
    sete_response_rate DECIMAL(5,2) NOT NULL,
    academic_difficulty_outcome TINYINT UNSIGNED NOT NULL COMMENT '1 = documented difficulty under the institution-approved label; 0 = no documented difficulty',
    outcome_definition VARCHAR(255) NOT NULL COMMENT 'Consistent, approved definition used to assign the outcome label',
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

-- The model's capacity feature is derived consistently from these two source
-- fields as 100 * section_enrollment / section_capacity. It is not a separate
-- manually entered value.
