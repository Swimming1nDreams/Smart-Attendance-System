SET @sapas_add_session_version_sql = (
    SELECT IF(
        EXISTS (
            SELECT 1
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'users'
              AND COLUMN_NAME = 'session_version'
        ),
        'DO 1',
        'ALTER TABLE users ADD COLUMN session_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER status'
    )
);
PREPARE sapas_add_session_version_stmt FROM @sapas_add_session_version_sql;
EXECUTE sapas_add_session_version_stmt;
DEALLOCATE PREPARE sapas_add_session_version_stmt;

SET @sapas_add_users_role_status_index_sql = (
    SELECT IF(
        EXISTS (
            SELECT 1
            FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'users'
              AND INDEX_NAME = 'idx_users_role_status'
        ),
        'DO 1',
        'ALTER TABLE users ADD KEY idx_users_role_status (role, status)'
    )
);
PREPARE sapas_add_users_role_status_index_stmt FROM @sapas_add_users_role_status_index_sql;
EXECUTE sapas_add_users_role_status_index_stmt;
DEALLOCATE PREPARE sapas_add_users_role_status_index_stmt;
