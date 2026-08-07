CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    actor_user_id INT UNSIGNED NULL,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(100) NOT NULL,
    entity_id VARCHAR(100) NULL,
    description VARCHAR(255) NULL,
    old_values JSON NULL,
    new_values JSON NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audit_logs_created_at (created_at),
    KEY idx_audit_logs_actor_created (actor_user_id, created_at),
    KEY idx_audit_logs_entity (entity_type, entity_id),
    KEY idx_audit_logs_action (action),
    CONSTRAINT fk_audit_logs_actor FOREIGN KEY (actor_user_id) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS staff_code_sequences (
    sequence_key VARCHAR(40) NOT NULL,
    next_number INT UNSIGNED NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (sequence_key)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

UPDATE users
SET staff_code = CONCAT('SAP-', LPAD(id, 4, '0'))
WHERE staff_code IS NULL OR TRIM(staff_code) = '';

INSERT INTO staff_code_sequences (sequence_key, next_number)
SELECT 'employee', COALESCE(MAX(CAST(SUBSTRING(staff_code, 5) AS UNSIGNED)), 0) + 1
FROM users
WHERE staff_code REGEXP '^SAP-[0-9]+$'
ON DUPLICATE KEY UPDATE next_number = GREATEST(next_number, VALUES(next_number));

SET @sapas_require_staff_code_sql = (
    SELECT IF(
        EXISTS (
            SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'users'
              AND COLUMN_NAME = 'staff_code'
              AND IS_NULLABLE = 'YES'
        ),
        'ALTER TABLE users MODIFY COLUMN staff_code VARCHAR(50) NOT NULL',
        'DO 1'
    )
);
PREPARE sapas_require_staff_code_stmt FROM @sapas_require_staff_code_sql;
EXECUTE sapas_require_staff_code_stmt;
DEALLOCATE PREPARE sapas_require_staff_code_stmt;

SET @sapas_add_attendance_date_status_user_index_sql = (
    SELECT IF(
        EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance_records' AND INDEX_NAME = 'idx_attendance_date_status_user'),
        'DO 1',
        'ALTER TABLE attendance_records ADD KEY idx_attendance_date_status_user (attendance_date, status, user_id)'
    )
);
PREPARE sapas_add_attendance_date_status_user_index_stmt FROM @sapas_add_attendance_date_status_user_index_sql;
EXECUTE sapas_add_attendance_date_status_user_index_stmt;
DEALLOCATE PREPARE sapas_add_attendance_date_status_user_index_stmt;

SET @sapas_add_leave_status_user_range_index_sql = (
    SELECT IF(
        EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leave_requests' AND INDEX_NAME = 'idx_leave_status_user_range'),
        'DO 1',
        'ALTER TABLE leave_requests ADD KEY idx_leave_status_user_range (status, user_id, start_date, end_date)'
    )
);
PREPARE sapas_add_leave_status_user_range_index_stmt FROM @sapas_add_leave_status_user_range_index_sql;
EXECUTE sapas_add_leave_status_user_range_index_stmt;
DEALLOCATE PREPARE sapas_add_leave_status_user_range_index_stmt;

SET @sapas_add_users_department_role_status_index_sql = (
    SELECT IF(
        EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'idx_users_department_role_status'),
        'DO 1',
        'ALTER TABLE users ADD KEY idx_users_department_role_status (department_id, role, status)'
    )
);
PREPARE sapas_add_users_department_role_status_index_stmt FROM @sapas_add_users_department_role_status_index_sql;
EXECUTE sapas_add_users_department_role_status_index_stmt;
DEALLOCATE PREPARE sapas_add_users_department_role_status_index_stmt;
