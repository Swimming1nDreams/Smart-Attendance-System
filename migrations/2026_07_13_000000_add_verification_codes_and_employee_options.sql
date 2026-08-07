SET @sapas_add_verification_code_hash_sql = (
    SELECT IF(
        EXISTS (
            SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'users'
              AND COLUMN_NAME = 'email_verification_code_hash'
        ),
        'DO 1',
        'ALTER TABLE users ADD COLUMN email_verification_code_hash VARCHAR(255) NULL AFTER email_verification_token'
    )
);
PREPARE sapas_add_verification_code_hash_stmt FROM @sapas_add_verification_code_hash_sql;
EXECUTE sapas_add_verification_code_hash_stmt;
DEALLOCATE PREPARE sapas_add_verification_code_hash_stmt;

SET @sapas_add_verification_attempts_sql = (
    SELECT IF(
        EXISTS (
            SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'users'
              AND COLUMN_NAME = 'email_verification_attempts'
        ),
        'DO 1',
        'ALTER TABLE users ADD COLUMN email_verification_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER email_verification_sent_at'
    )
);
PREPARE sapas_add_verification_attempts_stmt FROM @sapas_add_verification_attempts_sql;
EXECUTE sapas_add_verification_attempts_stmt;
DEALLOCATE PREPARE sapas_add_verification_attempts_stmt;

SET @sapas_add_staff_code_sql = (
    SELECT IF(
        EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'staff_code'),
        'DO 1',
        'ALTER TABLE users ADD COLUMN staff_code VARCHAR(50) NULL AFTER position_id'
    )
);
PREPARE sapas_add_staff_code_stmt FROM @sapas_add_staff_code_sql;
EXECUTE sapas_add_staff_code_stmt;
DEALLOCATE PREPARE sapas_add_staff_code_stmt;

SET @sapas_add_employment_type_sql = (
    SELECT IF(
        EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'employment_type'),
        'DO 1',
        'ALTER TABLE users ADD COLUMN employment_type ENUM(''full-time'', ''part-time'', ''intern'', ''contract'') NOT NULL DEFAULT ''full-time'' AFTER staff_code'
    )
);
PREPARE sapas_add_employment_type_stmt FROM @sapas_add_employment_type_sql;
EXECUTE sapas_add_employment_type_stmt;
DEALLOCATE PREPARE sapas_add_employment_type_stmt;

SET @sapas_add_join_date_sql = (
    SELECT IF(
        EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'join_date'),
        'DO 1',
        'ALTER TABLE users ADD COLUMN join_date DATE NULL AFTER employment_type'
    )
);
PREPARE sapas_add_join_date_stmt FROM @sapas_add_join_date_sql;
EXECUTE sapas_add_join_date_stmt;
DEALLOCATE PREPARE sapas_add_join_date_stmt;

SET @sapas_add_expected_hours_sql = (
    SELECT IF(
        EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'expected_daily_work_hours'),
        'DO 1',
        'ALTER TABLE users ADD COLUMN expected_daily_work_hours DECIMAL(4,2) NOT NULL DEFAULT 8.00 AFTER join_date'
    )
);
PREPARE sapas_add_expected_hours_stmt FROM @sapas_add_expected_hours_sql;
EXECUTE sapas_add_expected_hours_stmt;
DEALLOCATE PREPARE sapas_add_expected_hours_stmt;

SET @sapas_add_overtime_eligible_sql = (
    SELECT IF(
        EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'overtime_eligible'),
        'DO 1',
        'ALTER TABLE users ADD COLUMN overtime_eligible TINYINT(1) NOT NULL DEFAULT 1 AFTER expected_daily_work_hours'
    )
);
PREPARE sapas_add_overtime_eligible_stmt FROM @sapas_add_overtime_eligible_sql;
EXECUTE sapas_add_overtime_eligible_stmt;
DEALLOCATE PREPARE sapas_add_overtime_eligible_stmt;

SET @sapas_add_staff_code_index_sql = (
    SELECT IF(
        EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'uq_users_staff_code'),
        'DO 1',
        'ALTER TABLE users ADD UNIQUE KEY uq_users_staff_code (staff_code)'
    )
);
PREPARE sapas_add_staff_code_index_stmt FROM @sapas_add_staff_code_index_sql;
EXECUTE sapas_add_staff_code_index_stmt;
DEALLOCATE PREPARE sapas_add_staff_code_index_stmt;

UPDATE users
SET email_verification_token = NULL,
    email_verification_code_hash = NULL,
    email_verification_expires_at = NULL,
    email_verification_sent_at = NULL,
    email_verification_attempts = 0
WHERE email_verified_at IS NULL;
