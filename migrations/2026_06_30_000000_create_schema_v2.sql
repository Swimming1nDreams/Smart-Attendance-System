-- Schema v2.0 for SAPAS

SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin', 'employee') NOT NULL DEFAULT 'employee',
    department_id INT UNSIGNED DEFAULT NULL,
    position_id INT UNSIGNED DEFAULT NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_users_role (role),
    KEY idx_users_status (status),
    KEY idx_users_department_id (department_id),
    KEY idx_users_position_id (position_id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS departments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL UNIQUE,
    description TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS positions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL UNIQUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS holidays (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    holiday_name VARCHAR(255) NOT NULL,
    holiday_date DATE NOT NULL,
    description TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_holidays_date (holiday_date)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS employee_profiles (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL UNIQUE,
    phone VARCHAR(50) DEFAULT NULL,
    address TEXT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT fk_employee_profiles_user_id FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

SET @sapas_add_users_department_fk_sql = (
    SELECT IF(
        EXISTS (
            SELECT 1
            FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE()
              AND TABLE_NAME = 'users'
              AND CONSTRAINT_NAME = 'fk_users_department_id'
        ),
        'DO 1',
        'ALTER TABLE users ADD CONSTRAINT fk_users_department_id FOREIGN KEY (department_id) REFERENCES departments (id) ON DELETE SET NULL ON UPDATE CASCADE'
    )
);

PREPARE sapas_add_users_department_fk_stmt FROM @sapas_add_users_department_fk_sql;
EXECUTE sapas_add_users_department_fk_stmt;
DEALLOCATE PREPARE sapas_add_users_department_fk_stmt;

SET @sapas_add_users_position_fk_sql = (
    SELECT IF(
        EXISTS (
            SELECT 1
            FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE()
              AND TABLE_NAME = 'users'
              AND CONSTRAINT_NAME = 'fk_users_position_id'
        ),
        'DO 1',
        'ALTER TABLE users ADD CONSTRAINT fk_users_position_id FOREIGN KEY (position_id) REFERENCES positions (id) ON DELETE SET NULL ON UPDATE CASCADE'
    )
);

PREPARE sapas_add_users_position_fk_stmt FROM @sapas_add_users_position_fk_sql;
EXECUTE sapas_add_users_position_fk_stmt;
DEALLOCATE PREPARE sapas_add_users_position_fk_stmt;

SET FOREIGN_KEY_CHECKS = 1;
