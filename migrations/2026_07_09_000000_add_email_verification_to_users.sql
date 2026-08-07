SET @sapas_add_email_verified_at_sql = (
    SELECT IF(
        EXISTS (
            SELECT 1
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'users'
              AND COLUMN_NAME = 'email_verified_at'
        ),
        'DO 1',
        'ALTER TABLE users ADD COLUMN email_verified_at DATETIME NULL AFTER status'
    )
);

PREPARE sapas_add_email_verified_at_stmt FROM @sapas_add_email_verified_at_sql;
EXECUTE sapas_add_email_verified_at_stmt;
DEALLOCATE PREPARE sapas_add_email_verified_at_stmt;

SET @sapas_add_email_verification_token_sql = (
    SELECT IF(
        EXISTS (
            SELECT 1
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'users'
              AND COLUMN_NAME = 'email_verification_token'
        ),
        'DO 1',
        'ALTER TABLE users ADD COLUMN email_verification_token CHAR(64) NULL AFTER email_verified_at'
    )
);

PREPARE sapas_add_email_verification_token_stmt FROM @sapas_add_email_verification_token_sql;
EXECUTE sapas_add_email_verification_token_stmt;
DEALLOCATE PREPARE sapas_add_email_verification_token_stmt;

SET @sapas_add_email_verification_expires_at_sql = (
    SELECT IF(
        EXISTS (
            SELECT 1
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'users'
              AND COLUMN_NAME = 'email_verification_expires_at'
        ),
        'DO 1',
        'ALTER TABLE users ADD COLUMN email_verification_expires_at DATETIME NULL AFTER email_verification_token'
    )
);

PREPARE sapas_add_email_verification_expires_at_stmt FROM @sapas_add_email_verification_expires_at_sql;
EXECUTE sapas_add_email_verification_expires_at_stmt;
DEALLOCATE PREPARE sapas_add_email_verification_expires_at_stmt;

SET @sapas_add_email_verification_sent_at_sql = (
    SELECT IF(
        EXISTS (
            SELECT 1
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'users'
              AND COLUMN_NAME = 'email_verification_sent_at'
        ),
        'DO 1',
        'ALTER TABLE users ADD COLUMN email_verification_sent_at DATETIME NULL AFTER email_verification_expires_at'
    )
);

PREPARE sapas_add_email_verification_sent_at_stmt FROM @sapas_add_email_verification_sent_at_sql;
EXECUTE sapas_add_email_verification_sent_at_stmt;
DEALLOCATE PREPARE sapas_add_email_verification_sent_at_stmt;

SET @sapas_add_email_verification_token_index_sql = (
    SELECT IF(
        EXISTS (
            SELECT 1
            FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'users'
              AND INDEX_NAME = 'idx_users_email_verification_token'
        ),
        'DO 1',
        'ALTER TABLE users ADD KEY idx_users_email_verification_token (email_verification_token)'
    )
);

PREPARE sapas_add_email_verification_token_index_stmt FROM @sapas_add_email_verification_token_index_sql;
EXECUTE sapas_add_email_verification_token_index_stmt;
DEALLOCATE PREPARE sapas_add_email_verification_token_index_stmt;

UPDATE users
SET email_verified_at = COALESCE(email_verified_at, created_at)
WHERE email_verified_at IS NULL
  AND email_verification_token IS NULL;
