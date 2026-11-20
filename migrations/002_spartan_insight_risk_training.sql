USE spartan_insight;

CREATE TABLE IF NOT EXISTS academic_risk_training_records (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_key CHAR(64) NOT NULL COMMENT 'Synthetic pseudonymous row key or de-identified CICS key',
    section_code VARCHAR(10) NOT NULL,
    academic_year CHAR(9) NOT NULL,
    semester ENUM('1st Semester', '2nd Semester', 'Summer') NOT NULL,
    midtermGrade DECIMAL(5,2) NOT NULL,
    attendanceRate DECIMAL(5,2) NOT NULL,
    missingTasks TINYINT UNSIGNED NOT NULL,
    capacityUtilization DECIMAL(5,2) NOT NULL,
    seteEngagement DECIMAL(5,2) NOT NULL,
    academic_difficulty_outcome TINYINT UNSIGNED NOT NULL COMMENT '1 = prototype difficulty label; 0 = prototype no-difficulty label',
    outcome_definition VARCHAR(255) NOT NULL,
    source_dataset VARCHAR(60) NOT NULL,
    recorded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_risk_midterm_range CHECK (midtermGrade BETWEEN 0 AND 100),
    CONSTRAINT chk_risk_attendance_range CHECK (attendanceRate BETWEEN 0 AND 100),
    CONSTRAINT chk_risk_tasks_range CHECK (missingTasks BETWEEN 0 AND 10),
    CONSTRAINT chk_risk_capacity_range CHECK (capacityUtilization BETWEEN 0 AND 110),
    CONSTRAINT chk_risk_sete_range CHECK (seteEngagement BETWEEN 0 AND 100),
    CONSTRAINT chk_risk_outcome_binary CHECK (academic_difficulty_outcome IN (0, 1)),
    CONSTRAINT uq_risk_source_student_term UNIQUE (source_dataset, student_key, section_code, academic_year, semester),
    INDEX idx_risk_source_outcome (source_dataset, academic_difficulty_outcome),
    INDEX idx_risk_source_section_term (source_dataset, section_code, academic_year, semester),
    CONSTRAINT fk_risk_source_section FOREIGN KEY (section_code) REFERENCES sections(id)
        ON DELETE CASCADE
) ENGINE=InnoDB;
