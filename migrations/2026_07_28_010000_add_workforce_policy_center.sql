SET @sapas_add_template_lifecycle_sql = (
    SELECT IF(
        EXISTS (
            SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'work_schedule_templates'
              AND COLUMN_NAME = 'lifecycle_status'
        ),
        'DO 1',
        'ALTER TABLE work_schedule_templates ADD COLUMN lifecycle_status ENUM(''draft'', ''scheduled'', ''active'', ''archived'') NOT NULL DEFAULT ''active'' AFTER is_active'
    )
);
PREPARE sapas_add_template_lifecycle_stmt FROM @sapas_add_template_lifecycle_sql;
EXECUTE sapas_add_template_lifecycle_stmt;
DEALLOCATE PREPARE sapas_add_template_lifecycle_stmt;

SET @sapas_add_template_version_sql = (
    SELECT IF(
        EXISTS (
            SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'work_schedule_templates'
              AND COLUMN_NAME = 'version_number'
        ),
        'DO 1',
        'ALTER TABLE work_schedule_templates ADD COLUMN version_number INT UNSIGNED NOT NULL DEFAULT 1 AFTER lifecycle_status'
    )
);
PREPARE sapas_add_template_version_stmt FROM @sapas_add_template_version_sql;
EXECUTE sapas_add_template_version_stmt;
DEALLOCATE PREPARE sapas_add_template_version_stmt;

SET @sapas_add_template_locked_at_sql = (
    SELECT IF(
        EXISTS (
            SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'work_schedule_templates'
              AND COLUMN_NAME = 'locked_at'
        ),
        'DO 1',
        'ALTER TABLE work_schedule_templates ADD COLUMN locked_at DATETIME NULL AFTER version_number'
    )
);
PREPARE sapas_add_template_locked_at_stmt FROM @sapas_add_template_locked_at_sql;
EXECUTE sapas_add_template_locked_at_stmt;
DEALLOCATE PREPARE sapas_add_template_locked_at_stmt;

SET @sapas_add_template_locked_by_sql = (
    SELECT IF(
        EXISTS (
            SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'work_schedule_templates'
              AND COLUMN_NAME = 'locked_by'
        ),
        'DO 1',
        'ALTER TABLE work_schedule_templates ADD COLUMN locked_by INT UNSIGNED NULL AFTER locked_at'
    )
);
PREPARE sapas_add_template_locked_by_stmt FROM @sapas_add_template_locked_by_sql;
EXECUTE sapas_add_template_locked_by_stmt;
DEALLOCATE PREPARE sapas_add_template_locked_by_stmt;

SET @sapas_add_template_created_from_sql = (
    SELECT IF(
        EXISTS (
            SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'work_schedule_templates'
              AND COLUMN_NAME = 'created_from_template_id'
        ),
        'DO 1',
        'ALTER TABLE work_schedule_templates ADD COLUMN created_from_template_id INT UNSIGNED NULL AFTER locked_by'
    )
);
PREPARE sapas_add_template_created_from_stmt FROM @sapas_add_template_created_from_sql;
EXECUTE sapas_add_template_created_from_stmt;
DEALLOCATE PREPARE sapas_add_template_created_from_stmt;

SET @sapas_add_template_reason_sql = (
    SELECT IF(
        EXISTS (
            SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'work_schedule_templates'
              AND COLUMN_NAME = 'change_reason'
        ),
        'DO 1',
        'ALTER TABLE work_schedule_templates ADD COLUMN change_reason TEXT NULL AFTER created_from_template_id'
    )
);
PREPARE sapas_add_template_reason_stmt FROM @sapas_add_template_reason_sql;
EXECUTE sapas_add_template_reason_stmt;
DEALLOCATE PREPARE sapas_add_template_reason_stmt;

SET @sapas_add_day_break_start_sql = (
    SELECT IF(
        EXISTS (
            SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'work_schedule_days'
              AND COLUMN_NAME = 'break_start_time'
        ),
        'DO 1',
        'ALTER TABLE work_schedule_days ADD COLUMN break_start_time TIME NULL AFTER is_overnight'
    )
);
PREPARE sapas_add_day_break_start_stmt FROM @sapas_add_day_break_start_sql;
EXECUTE sapas_add_day_break_start_stmt;
DEALLOCATE PREPARE sapas_add_day_break_start_stmt;

SET @sapas_add_day_break_end_sql = (
    SELECT IF(
        EXISTS (
            SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'work_schedule_days'
              AND COLUMN_NAME = 'break_end_time'
        ),
        'DO 1',
        'ALTER TABLE work_schedule_days ADD COLUMN break_end_time TIME NULL AFTER break_start_time'
    )
);
PREPARE sapas_add_day_break_end_stmt FROM @sapas_add_day_break_end_sql;
EXECUTE sapas_add_day_break_end_stmt;
DEALLOCATE PREPARE sapas_add_day_break_end_stmt;

CREATE TABLE IF NOT EXISTS attendance_policy_settings (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    work_schedule_template_id INT UNSIGNED NOT NULL,
    allow_non_working_day_check_in TINYINT(1) NOT NULL DEFAULT 1,
    allow_holiday_check_in TINYINT(1) NOT NULL DEFAULT 1,
    allow_overnight_attendance TINYINT(1) NOT NULL DEFAULT 0,
    maximum_attendance_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 960,
    missing_check_out_mode ENUM('mark_incomplete', 'admin_review_required') NOT NULL DEFAULT 'mark_incomplete',
    early_departure_enabled TINYINT(1) NOT NULL DEFAULT 1,
    manual_override_allowed TINYINT(1) NOT NULL DEFAULT 1,
    override_reason_required TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_attendance_policy_settings_template (work_schedule_template_id),
    CONSTRAINT fk_attendance_policy_settings_template FOREIGN KEY (work_schedule_template_id) REFERENCES work_schedule_templates (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT chk_attendance_policy_maximum CHECK (maximum_attendance_minutes BETWEEN 1 AND 1440)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS overtime_policy_settings (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    work_schedule_template_id INT UNSIGNED NOT NULL,
    early_work_eligible TINYINT(1) NOT NULL DEFAULT 1,
    after_hours_work_eligible TINYINT(1) NOT NULL DEFAULT 1,
    non_working_day_work_eligible TINYINT(1) NOT NULL DEFAULT 1,
    holiday_work_eligible TINYINT(1) NOT NULL DEFAULT 1,
    approval_required TINYINT(1) NOT NULL DEFAULT 0,
    minimum_overtime_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    rounding_mode ENUM('exact', 'down', 'up', 'nearest') NOT NULL DEFAULT 'exact',
    rounding_interval_minutes TINYINT UNSIGNED NULL,
    special_day_break_threshold_minutes SMALLINT UNSIGNED NULL,
    special_day_break_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    allow_admin_adjustment TINYINT(1) NOT NULL DEFAULT 1,
    allow_approval_above_calculated TINYINT(1) NOT NULL DEFAULT 0,
    reason_required TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_overtime_policy_settings_template (work_schedule_template_id),
    CONSTRAINT fk_overtime_policy_settings_template FOREIGN KEY (work_schedule_template_id) REFERENCES work_schedule_templates (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS work_schedule_assignments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    work_schedule_template_id INT UNSIGNED NOT NULL,
    scope_type ENUM('company', 'department', 'employee') NOT NULL,
    scope_id INT UNSIGNED NULL,
    effective_from DATE NOT NULL,
    effective_to DATE NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    reason VARCHAR(255) NULL,
    created_by INT UNSIGNED NULL,
    updated_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_work_schedule_assignments_scope_date (scope_type, scope_id, is_active, effective_from, effective_to),
    KEY idx_work_schedule_assignments_template (work_schedule_template_id),
    KEY idx_work_schedule_assignments_created_by (created_by),
    KEY idx_work_schedule_assignments_updated_by (updated_by),
    CONSTRAINT fk_work_schedule_assignments_template FOREIGN KEY (work_schedule_template_id) REFERENCES work_schedule_templates (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_work_schedule_assignments_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_work_schedule_assignments_updated_by FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT chk_work_schedule_assignments_scope CHECK ((scope_type = 'company' AND scope_id IS NULL) OR (scope_type IN ('department', 'employee') AND scope_id IS NOT NULL)),
    CONSTRAINT chk_work_schedule_assignments_range CHECK (effective_to IS NULL OR effective_to >= effective_from)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS overtime_decisions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    attendance_id INT UNSIGNED NOT NULL,
    decision ENUM('approved', 'rejected', 'adjusted', 'reset_to_pending') NOT NULL,
    calculated_minutes INT UNSIGNED NOT NULL DEFAULT 0,
    previous_approved_minutes INT UNSIGNED NOT NULL DEFAULT 0,
    approved_minutes INT UNSIGNED NOT NULL DEFAULT 0,
    reason VARCHAR(1000) NULL,
    decided_by INT UNSIGNED NULL,
    decided_at DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_overtime_decisions_attendance (attendance_id),
    KEY idx_overtime_decisions_decided_by (decided_by),
    CONSTRAINT fk_overtime_decisions_attendance FOREIGN KEY (attendance_id) REFERENCES attendance_records (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_overtime_decisions_decided_by FOREIGN KEY (decided_by) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

SET @sapas_add_att_check_in_at_sql = (
    SELECT IF(EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance_records' AND COLUMN_NAME = 'check_in_at'), 'DO 1', 'ALTER TABLE attendance_records ADD COLUMN check_in_at DATETIME NULL AFTER clock_out_time')
);
PREPARE sapas_add_att_check_in_at_stmt FROM @sapas_add_att_check_in_at_sql;
EXECUTE sapas_add_att_check_in_at_stmt;
DEALLOCATE PREPARE sapas_add_att_check_in_at_stmt;

SET @sapas_add_att_check_out_at_sql = (
    SELECT IF(EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance_records' AND COLUMN_NAME = 'check_out_at'), 'DO 1', 'ALTER TABLE attendance_records ADD COLUMN check_out_at DATETIME NULL AFTER check_in_at')
);
PREPARE sapas_add_att_check_out_at_stmt FROM @sapas_add_att_check_out_at_sql;
EXECUTE sapas_add_att_check_out_at_stmt;
DEALLOCATE PREPARE sapas_add_att_check_out_at_stmt;

SET @sapas_add_att_business_date_sql = (
    SELECT IF(EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance_records' AND COLUMN_NAME = 'business_date'), 'DO 1', 'ALTER TABLE attendance_records ADD COLUMN business_date DATE NULL AFTER check_out_at')
);
PREPARE sapas_add_att_business_date_stmt FROM @sapas_add_att_business_date_sql;
EXECUTE sapas_add_att_business_date_stmt;
DEALLOCATE PREPARE sapas_add_att_business_date_stmt;

SET @sapas_add_att_day_type_sql = (
    SELECT IF(EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance_records' AND COLUMN_NAME = 'day_type'), 'DO 1', 'ALTER TABLE attendance_records ADD COLUMN day_type ENUM(''working_day'', ''non_working_day'', ''holiday'') NULL AFTER business_date')
);
PREPARE sapas_add_att_day_type_stmt FROM @sapas_add_att_day_type_sql;
EXECUTE sapas_add_att_day_type_stmt;
DEALLOCATE PREPARE sapas_add_att_day_type_stmt;

SET @sapas_add_att_state_sql = (
    SELECT IF(EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance_records' AND COLUMN_NAME = 'attendance_state'), 'DO 1', 'ALTER TABLE attendance_records ADD COLUMN attendance_state ENUM(''present'', ''absent'', ''incomplete'', ''approved_leave'') NULL AFTER day_type')
);
PREPARE sapas_add_att_state_stmt FROM @sapas_add_att_state_sql;
EXECUTE sapas_add_att_state_stmt;
DEALLOCATE PREPARE sapas_add_att_state_stmt;

SET @sapas_add_att_flags_sql = (
    SELECT IF(EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance_records' AND COLUMN_NAME = 'is_late'), 'DO 1', 'ALTER TABLE attendance_records ADD COLUMN is_late TINYINT(1) NOT NULL DEFAULT 0 AFTER attendance_state, ADD COLUMN is_early_departure TINYINT(1) NOT NULL DEFAULT 0 AFTER is_late, ADD COLUMN is_missing_check_out TINYINT(1) NOT NULL DEFAULT 0 AFTER is_early_departure, ADD COLUMN is_manual_override TINYINT(1) NOT NULL DEFAULT 0 AFTER is_missing_check_out, ADD COLUMN is_overnight_attendance TINYINT(1) NOT NULL DEFAULT 0 AFTER is_manual_override')
);
PREPARE sapas_add_att_flags_stmt FROM @sapas_add_att_flags_sql;
EXECUTE sapas_add_att_flags_stmt;
DEALLOCATE PREPARE sapas_add_att_flags_stmt;

SET @sapas_add_att_minutes_sql = (
    SELECT IF(EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance_records' AND COLUMN_NAME = 'regular_minutes'), 'DO 1', 'ALTER TABLE attendance_records ADD COLUMN regular_minutes INT UNSIGNED NULL AFTER is_overnight_attendance, ADD COLUMN early_departure_minutes INT UNSIGNED NOT NULL DEFAULT 0 AFTER regular_minutes, ADD COLUMN early_overtime_minutes INT UNSIGNED NOT NULL DEFAULT 0 AFTER early_departure_minutes, ADD COLUMN after_hours_overtime_minutes INT UNSIGNED NOT NULL DEFAULT 0 AFTER early_overtime_minutes, ADD COLUMN non_working_day_overtime_minutes INT UNSIGNED NOT NULL DEFAULT 0 AFTER after_hours_overtime_minutes, ADD COLUMN holiday_overtime_minutes INT UNSIGNED NOT NULL DEFAULT 0 AFTER non_working_day_overtime_minutes, ADD COLUMN raw_calculated_overtime_minutes INT UNSIGNED NULL AFTER holiday_overtime_minutes, ADD COLUMN calculated_overtime_minutes INT UNSIGNED NULL AFTER raw_calculated_overtime_minutes, ADD COLUMN approved_overtime_minutes INT UNSIGNED NULL AFTER calculated_overtime_minutes')
);
PREPARE sapas_add_att_minutes_stmt FROM @sapas_add_att_minutes_sql;
EXECUTE sapas_add_att_minutes_stmt;
DEALLOCATE PREPARE sapas_add_att_minutes_stmt;

SET @sapas_add_att_approval_sql = (
    SELECT IF(EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance_records' AND COLUMN_NAME = 'overtime_approval_status'), 'DO 1', 'ALTER TABLE attendance_records ADD COLUMN overtime_approval_status ENUM(''not_required'', ''pending'', ''approved'', ''rejected'') NULL AFTER approved_overtime_minutes')
);
PREPARE sapas_add_att_approval_stmt FROM @sapas_add_att_approval_sql;
EXECUTE sapas_add_att_approval_stmt;
DEALLOCATE PREPARE sapas_add_att_approval_stmt;

SET @sapas_add_att_resolution_sql = (
    SELECT IF(EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance_records' AND COLUMN_NAME = 'resolved_policy_version_id'), 'DO 1', 'ALTER TABLE attendance_records ADD COLUMN resolved_policy_version_id INT UNSIGNED NULL AFTER overtime_approval_status, ADD COLUMN resolved_assignment_id INT UNSIGNED NULL AFTER resolved_policy_version_id, ADD COLUMN calculation_version VARCHAR(20) NULL AFTER resolved_assignment_id')
);
PREPARE sapas_add_att_resolution_stmt FROM @sapas_add_att_resolution_sql;
EXECUTE sapas_add_att_resolution_stmt;
DEALLOCATE PREPARE sapas_add_att_resolution_stmt;

INSERT INTO attendance_policy_settings (work_schedule_template_id)
SELECT id FROM work_schedule_templates template
WHERE NOT EXISTS (
    SELECT 1 FROM attendance_policy_settings existing
    WHERE existing.work_schedule_template_id = template.id
);

INSERT INTO overtime_policy_settings (work_schedule_template_id)
SELECT id FROM work_schedule_templates template
WHERE NOT EXISTS (
    SELECT 1 FROM overtime_policy_settings existing
    WHERE existing.work_schedule_template_id = template.id
);

INSERT INTO work_schedule_assignments (work_schedule_template_id, scope_type, scope_id, effective_from, effective_to, is_active, reason, created_by, updated_by)
SELECT cp.default_work_schedule_id, 'company', NULL, '1970-01-01', NULL, 1, 'Seeded from company default schedule for Workforce Policy Center compatibility.', NULL, NULL
FROM company_profiles cp
WHERE cp.default_work_schedule_id IS NOT NULL
  AND NOT EXISTS (
      SELECT 1 FROM work_schedule_assignments existing
      WHERE existing.scope_type = 'company'
        AND existing.scope_id IS NULL
        AND existing.effective_from = '1970-01-01'
  )
LIMIT 1;

UPDATE work_schedule_templates
SET lifecycle_status = CASE
        WHEN is_active = 1 AND effective_from <= CURRENT_DATE() AND (effective_to IS NULL OR effective_to >= CURRENT_DATE()) THEN 'active'
        WHEN is_active = 1 AND effective_from > CURRENT_DATE() THEN 'scheduled'
        ELSE 'archived'
    END,
    locked_at = CASE
        WHEN is_active = 1 AND effective_from <= CURRENT_DATE() AND locked_at IS NULL THEN NOW()
        ELSE locked_at
    END
WHERE lifecycle_status = 'active'
  AND locked_at IS NULL;
