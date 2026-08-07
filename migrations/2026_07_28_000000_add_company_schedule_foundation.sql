CREATE TABLE IF NOT EXISTS work_schedule_templates (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(120) NOT NULL,
    description TEXT NULL,
    timezone VARCHAR(64) NULL,
    effective_from DATE NOT NULL,
    effective_to DATE NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT UNSIGNED NULL,
    updated_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_work_schedule_templates_name_active (name, is_active),
    KEY idx_work_schedule_templates_active_effective (is_active, effective_from, effective_to),
    KEY idx_work_schedule_templates_created_by (created_by),
    KEY idx_work_schedule_templates_updated_by (updated_by),
    CONSTRAINT fk_work_schedule_templates_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_work_schedule_templates_updated_by FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT chk_work_schedule_templates_effective_range CHECK (effective_to IS NULL OR effective_to >= effective_from)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS work_schedule_days (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    work_schedule_template_id INT UNSIGNED NOT NULL,
    day_of_week TINYINT UNSIGNED NOT NULL,
    is_working_day TINYINT(1) NOT NULL DEFAULT 0,
    start_time TIME NULL,
    end_time TIME NULL,
    is_overnight TINYINT(1) NOT NULL DEFAULT 0,
    break_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    grace_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    expected_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_work_schedule_days_template_weekday (work_schedule_template_id, day_of_week),
    KEY idx_work_schedule_days_weekday (day_of_week),
    CONSTRAINT fk_work_schedule_days_template FOREIGN KEY (work_schedule_template_id) REFERENCES work_schedule_templates (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT chk_work_schedule_days_weekday CHECK (day_of_week BETWEEN 1 AND 7),
    CONSTRAINT chk_work_schedule_days_non_negative CHECK (break_minutes >= 0 AND grace_minutes >= 0 AND expected_minutes >= 0)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS company_profiles (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_name VARCHAR(150) NOT NULL,
    short_name VARCHAR(50) NULL,
    email VARCHAR(190) NULL,
    phone VARCHAR(50) NULL,
    address TEXT NULL,
    timezone VARCHAR(64) NOT NULL DEFAULT 'Asia/Yangon',
    date_format VARCHAR(32) NOT NULL DEFAULT 'Y-m-d',
    time_format VARCHAR(32) NOT NULL DEFAULT 'H:i',
    default_work_schedule_id INT UNSIGNED NULL,
    created_by INT UNSIGNED NULL,
    updated_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_company_profiles_default_schedule (default_work_schedule_id),
    KEY idx_company_profiles_created_by (created_by),
    KEY idx_company_profiles_updated_by (updated_by),
    CONSTRAINT fk_company_profiles_default_schedule FOREIGN KEY (default_work_schedule_id) REFERENCES work_schedule_templates (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_company_profiles_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_company_profiles_updated_by FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

INSERT INTO work_schedule_templates (name, description, timezone, effective_from, effective_to, is_active, created_by, updated_by)
SELECT 'SAPAS Standard Office', 'Seeded default schedule matching the current SAPAS attendance policy.', NULL, '2026-01-01', NULL, 1, NULL, NULL
WHERE NOT EXISTS (
    SELECT 1 FROM work_schedule_templates WHERE name = 'SAPAS Standard Office'
);

SET @sapas_default_schedule_id = (
    SELECT id FROM work_schedule_templates WHERE name = 'SAPAS Standard Office' ORDER BY id ASC LIMIT 1
);

INSERT INTO work_schedule_days (work_schedule_template_id, day_of_week, is_working_day, start_time, end_time, is_overnight, break_minutes, grace_minutes, expected_minutes)
SELECT @sapas_default_schedule_id, weekday_config.day_of_week, weekday_config.is_working_day, weekday_config.start_time, weekday_config.end_time, weekday_config.is_overnight, weekday_config.break_minutes, weekday_config.grace_minutes, weekday_config.expected_minutes
FROM (
    SELECT 1 AS day_of_week, 1 AS is_working_day, CAST('09:00:00' AS TIME) AS start_time, CAST('17:00:00' AS TIME) AS end_time, 0 AS is_overnight, 0 AS break_minutes, 30 AS grace_minutes, 480 AS expected_minutes
    UNION ALL SELECT 2, 1, CAST('09:00:00' AS TIME), CAST('17:00:00' AS TIME), 0, 0, 30, 480
    UNION ALL SELECT 3, 1, CAST('09:00:00' AS TIME), CAST('17:00:00' AS TIME), 0, 0, 30, 480
    UNION ALL SELECT 4, 1, CAST('09:00:00' AS TIME), CAST('17:00:00' AS TIME), 0, 0, 30, 480
    UNION ALL SELECT 5, 1, CAST('09:00:00' AS TIME), CAST('17:00:00' AS TIME), 0, 0, 30, 480
    UNION ALL SELECT 6, 0, NULL, NULL, 0, 0, 0, 0
    UNION ALL SELECT 7, 0, NULL, NULL, 0, 0, 0, 0
) AS weekday_config
WHERE @sapas_default_schedule_id IS NOT NULL
  AND NOT EXISTS (
      SELECT 1 FROM work_schedule_days existing
      WHERE existing.work_schedule_template_id = @sapas_default_schedule_id
        AND existing.day_of_week = weekday_config.day_of_week
  );

INSERT INTO company_profiles (company_name, short_name, email, phone, address, timezone, date_format, time_format, default_work_schedule_id, created_by, updated_by)
SELECT 'Smart Attendance And Performance Analytic System', 'Smart Attendance', NULL, NULL, NULL, 'Asia/Yangon', 'Y-m-d', 'H:i', @sapas_default_schedule_id, NULL, NULL
WHERE NOT EXISTS (
    SELECT 1 FROM company_profiles
);

UPDATE company_profiles
SET default_work_schedule_id = @sapas_default_schedule_id
WHERE default_work_schedule_id IS NULL
  AND @sapas_default_schedule_id IS NOT NULL;
