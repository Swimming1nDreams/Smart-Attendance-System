<?php

declare(strict_types=1);

final class TestDatabase
{
    private PDO $connection;
    private string $databaseName;

    private function __construct(PDO $connection, string $databaseName)
    {
        $this->connection = $connection;
        $this->databaseName = $databaseName;
    }

    public static function connect(?string $databaseName = null): self
    {
        $settings = require project_path('config/database.php');
        $databaseName ??= getenv('OAAS_TEST_DB_NAME') ?: getenv('SAPAS_TEST_DB_NAME') ?: 'sapas_test';
        self::assertSafeTestDatabaseName($databaseName, (string) ($settings['name'] ?? ''));

        $host = (string) (getenv('OAAS_TEST_DB_HOST') ?: getenv('SAPAS_TEST_DB_HOST') ?: $settings['host']);
        $user = (string) (getenv('OAAS_TEST_DB_USER') ?: getenv('SAPAS_TEST_DB_USER') ?: $settings['user']);
        $pass = (string) (getenv('OAAS_TEST_DB_PASS') ?: getenv('SAPAS_TEST_DB_PASS') ?: $settings['pass']);
        $charset = (string) (getenv('OAAS_TEST_DB_CHARSET') ?: getenv('SAPAS_TEST_DB_CHARSET') ?: $settings['charset']);
        $options = $settings['options'];

        $server = new PDO(
            sprintf('mysql:host=%s;charset=%s', $host, $charset),
            $user,
            $pass,
            $options
        );
        $quotedDatabase = self::quoteIdentifier($databaseName);
        $server->exec(sprintf('CREATE DATABASE IF NOT EXISTS %s CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', $quotedDatabase));

        $connection = new PDO(
            sprintf('mysql:host=%s;dbname=%s;charset=%s', $host, $databaseName, $charset),
            $user,
            $pass,
            $options
        );

        return new self($connection, $databaseName);
    }

    public static function connectWithQueryCounter(?string $databaseName = null): self
    {
        $settings = require project_path('config/database.php');
        $databaseName ??= getenv('OAAS_TEST_DB_NAME') ?: getenv('SAPAS_TEST_DB_NAME') ?: 'sapas_test';
        self::assertSafeTestDatabaseName($databaseName, (string) ($settings['name'] ?? ''));

        $host = (string) (getenv('OAAS_TEST_DB_HOST') ?: getenv('SAPAS_TEST_DB_HOST') ?: $settings['host']);
        $user = (string) (getenv('OAAS_TEST_DB_USER') ?: getenv('SAPAS_TEST_DB_USER') ?: $settings['user']);
        $pass = (string) (getenv('OAAS_TEST_DB_PASS') ?: getenv('SAPAS_TEST_DB_PASS') ?: $settings['pass']);
        $charset = (string) (getenv('OAAS_TEST_DB_CHARSET') ?: getenv('SAPAS_TEST_DB_CHARSET') ?: $settings['charset']);
        $options = $settings['options'];

        $server = new PDO(
            sprintf('mysql:host=%s;charset=%s', $host, $charset),
            $user,
            $pass,
            $options
        );
        $quotedDatabase = self::quoteIdentifier($databaseName);
        $server->exec(sprintf('CREATE DATABASE IF NOT EXISTS %s CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', $quotedDatabase));

        $connection = new QueryCountingPDO(
            sprintf('mysql:host=%s;dbname=%s;charset=%s', $host, $databaseName, $charset),
            $user,
            $pass,
            $options
        );

        return new self($connection, $databaseName);
    }

    public function pdo(): PDO
    {
        return $this->connection;
    }

    public function databaseName(): string
    {
        return $this->databaseName;
    }

    /**
     * @return array{department_id: int, position_id: int, admin_id: int, employee_id: int, other_employee_id: int, inactive_employee_id: int}
     */
    public function resetAndSeed(): array
    {
        $this->resetSchema();

        $this->connection->exec("INSERT INTO departments (name, description) VALUES ('Engineering', 'Test department')");
        $departmentId = (int) $this->connection->lastInsertId();

        $this->connection->exec("INSERT INTO positions (name) VALUES ('Developer')");
        $positionId = (int) $this->connection->lastInsertId();

        $adminId = $this->insertUser('Integration Admin', 'admin.integration@sapas.test', 'admin', 'active', null, null);
        $employeeId = $this->insertUser('Integration Employee', 'employee.integration@sapas.test', 'employee', 'active', $departmentId, $positionId);
        $otherEmployeeId = $this->insertUser('Other Employee', 'other.integration@sapas.test', 'employee', 'active', $departmentId, $positionId);
        $inactiveEmployeeId = $this->insertUser('Inactive Employee', 'inactive.integration@sapas.test', 'employee', 'inactive', $departmentId, $positionId);

        return [
            'department_id' => $departmentId,
            'position_id' => $positionId,
            'admin_id' => $adminId,
            'employee_id' => $employeeId,
            'other_employee_id' => $otherEmployeeId,
            'inactive_employee_id' => $inactiveEmployeeId,
        ];
    }

    public function resetSchema(): void
    {
        $queries = [
            'SET FOREIGN_KEY_CHECKS = 0',
            'DROP TABLE IF EXISTS leave_request_attachments, audit_logs, notifications, leave_requests, holidays, overtime_decisions, attendance_sessions, attendance_records, employee_profiles, work_schedule_assignments, overtime_policy_settings, attendance_policy_settings, company_profiles, work_schedule_days, work_schedule_templates, staff_code_sequences, users, positions, departments',
            'SET FOREIGN_KEY_CHECKS = 1',
            "CREATE TABLE departments (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(255) NOT NULL UNIQUE,
                description TEXT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id)
            ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci"
        ];
        
        foreach ($queries as $i => $q) {
            try {
                $res = $this->connection->exec($q);
                if ($res === false) {
                    $err = $this->connection->errorInfo();
                    if ($err[0] !== '00000') {
                        throw new RuntimeException("Query $i failed: " . implode(' - ', $err) . "\n" . $q);
                    }
                }
            } catch (Throwable $e) {
                throw new RuntimeException("Exception on query $i: " . $e->getMessage() . "\n" . $q, 0, $e);
            }
        }


        $this->connection->exec(
            "CREATE TABLE positions (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(255) NOT NULL UNIQUE,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id)
            ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci"
        );

        $this->connection->exec(
            "CREATE TABLE users (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(255) NOT NULL,
                email VARCHAR(255) NOT NULL UNIQUE,
                password VARCHAR(255) NOT NULL,
                role ENUM('admin', 'department_head', 'employee') NOT NULL DEFAULT 'employee',
                department_id INT UNSIGNED DEFAULT NULL,
                position_id INT UNSIGNED DEFAULT NULL,
                status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
                session_version INT UNSIGNED NOT NULL DEFAULT 1,
                email_verified_at DATETIME NULL,
                email_verification_token CHAR(64) NULL,
                email_verification_code_hash VARCHAR(255) NULL,
                email_verification_expires_at DATETIME NULL,
                email_verification_sent_at DATETIME NULL,
                email_verification_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
                staff_code VARCHAR(50) NOT NULL,
                employment_type ENUM('full-time', 'part-time', 'intern', 'contract') NOT NULL DEFAULT 'full-time',
                join_date DATE NULL,
                expected_daily_work_hours DECIMAL(4,2) NOT NULL DEFAULT 8.00,
                overtime_eligible TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_users_role (role),
                KEY idx_users_status (status),
                KEY idx_users_role_status (role, status),
                KEY idx_users_department_role_status (department_id, role, status),
                KEY idx_users_email_verification_token (email_verification_token),
                UNIQUE KEY uq_users_staff_code (staff_code),
                KEY idx_users_department_id (department_id),
                KEY idx_users_position_id (position_id),
                CONSTRAINT fk_test_users_department_id FOREIGN KEY (department_id) REFERENCES departments (id) ON DELETE SET NULL ON UPDATE CASCADE,
                CONSTRAINT fk_test_users_position_id FOREIGN KEY (position_id) REFERENCES positions (id) ON DELETE SET NULL ON UPDATE CASCADE
            ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci"
        );

        $this->connection->exec(
            "CREATE TABLE staff_code_sequences (
                sequence_key VARCHAR(40) NOT NULL,
                next_number INT UNSIGNED NOT NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (sequence_key)
            ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci"
        );
        $this->connection->exec("INSERT INTO staff_code_sequences (sequence_key, next_number) VALUES ('employee', 1)");

        $this->connection->exec(
            "CREATE TABLE work_schedule_templates (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(120) NOT NULL,
                description TEXT NULL,
                timezone VARCHAR(64) NULL,
                effective_from DATE NOT NULL,
                effective_to DATE NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                lifecycle_status ENUM('draft', 'scheduled', 'active', 'archived') NOT NULL DEFAULT 'active',
                version_number INT UNSIGNED NOT NULL DEFAULT 1,
                locked_at DATETIME NULL,
                locked_by INT UNSIGNED NULL,
                created_from_template_id INT UNSIGNED NULL,
                change_reason TEXT NULL,
                created_by INT UNSIGNED NULL,
                updated_by INT UNSIGNED NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_work_schedule_templates_name_active (name, is_active),
                KEY idx_work_schedule_templates_active_effective (is_active, effective_from, effective_to),
                CONSTRAINT fk_test_work_schedule_templates_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE,
                CONSTRAINT fk_test_work_schedule_templates_updated_by FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
            ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci"
        );

        $this->connection->exec(
            "CREATE TABLE work_schedule_days (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                work_schedule_template_id INT UNSIGNED NOT NULL,
                day_of_week TINYINT UNSIGNED NOT NULL,
                is_working_day TINYINT(1) NOT NULL DEFAULT 0,
                start_time TIME NULL,
                end_time TIME NULL,
                is_overnight TINYINT(1) NOT NULL DEFAULT 0,
                break_start_time TIME NULL,
                break_end_time TIME NULL,
                break_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                grace_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                expected_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_work_schedule_days_template_weekday (work_schedule_template_id, day_of_week),
                KEY idx_work_schedule_days_weekday (day_of_week),
                CONSTRAINT fk_test_work_schedule_days_template FOREIGN KEY (work_schedule_template_id) REFERENCES work_schedule_templates (id) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci"
        );

        $this->connection->exec(
            "CREATE TABLE company_profiles (
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
                CONSTRAINT fk_test_company_profiles_default_schedule FOREIGN KEY (default_work_schedule_id) REFERENCES work_schedule_templates (id) ON DELETE RESTRICT ON UPDATE CASCADE,
                CONSTRAINT fk_test_company_profiles_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE,
                CONSTRAINT fk_test_company_profiles_updated_by FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
            ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci"
        );

        $this->connection->exec(
            "CREATE TABLE attendance_policy_settings (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                work_schedule_template_id INT UNSIGNED NOT NULL,
                allow_non_working_day_check_in TINYINT(1) NOT NULL DEFAULT 1,
                allow_holiday_check_in TINYINT(1) NOT NULL DEFAULT 1,
                allow_overnight_attendance TINYINT(1) NOT NULL DEFAULT 0,
                maximum_attendance_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 960,
                missing_check_out_mode ENUM('mark_incomplete', 'admin_review_required') NOT NULL DEFAULT 'mark_incomplete',
                early_departure_enabled TINYINT(1) NOT NULL DEFAULT 1,
                early_departure_grace_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                manual_override_allowed TINYINT(1) NOT NULL DEFAULT 1,
                override_reason_required TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_test_attendance_policy_schedule (work_schedule_template_id),
                CONSTRAINT fk_test_attendance_policy_schedule FOREIGN KEY (work_schedule_template_id) REFERENCES work_schedule_templates (id) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci"
        );

        $this->connection->exec(
            "CREATE TABLE overtime_policy_settings (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                work_schedule_template_id INT UNSIGNED NOT NULL,
                early_work_eligible TINYINT(1) NOT NULL DEFAULT 1,
                after_hours_work_eligible TINYINT(1) NOT NULL DEFAULT 1,
                non_working_day_work_eligible TINYINT(1) NOT NULL DEFAULT 1,
                holiday_work_eligible TINYINT(1) NOT NULL DEFAULT 1,
                approval_required TINYINT(1) NOT NULL DEFAULT 0,
                minimum_overtime_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                rounding_mode ENUM('exact', 'nearest', 'up', 'down') NOT NULL DEFAULT 'exact',
                rounding_interval_minutes SMALLINT UNSIGNED NULL,
                special_day_break_threshold_minutes SMALLINT UNSIGNED NULL,
                special_day_break_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                allow_admin_adjustment TINYINT(1) NOT NULL DEFAULT 1,
                allow_approval_above_calculated TINYINT(1) NOT NULL DEFAULT 0,
                reason_required TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_test_overtime_policy_schedule (work_schedule_template_id),
                CONSTRAINT fk_test_overtime_policy_schedule FOREIGN KEY (work_schedule_template_id) REFERENCES work_schedule_templates (id) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci"
        );

        $this->connection->exec(
            "CREATE TABLE work_schedule_assignments (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                work_schedule_template_id INT UNSIGNED NOT NULL,
                scope_type ENUM('company', 'department', 'employee') NOT NULL,
                scope_id INT UNSIGNED NULL,
                effective_from DATE NOT NULL,
                effective_to DATE NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                reason TEXT NULL,
                created_by INT UNSIGNED NULL,
                updated_by INT UNSIGNED NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_test_work_schedule_assignments_scope (scope_type, scope_id, is_active, effective_from, effective_to),
                KEY idx_test_work_schedule_assignments_template (work_schedule_template_id),
                CONSTRAINT fk_test_work_schedule_assignments_template FOREIGN KEY (work_schedule_template_id) REFERENCES work_schedule_templates (id) ON DELETE RESTRICT ON UPDATE CASCADE,
                CONSTRAINT fk_test_work_schedule_assignments_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE,
                CONSTRAINT fk_test_work_schedule_assignments_updated_by FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
            ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci"
        );

        $this->seedDefaultScheduleFoundation();

        $this->connection->exec(
            "CREATE TABLE employee_profiles (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT UNSIGNED NOT NULL UNIQUE,
                phone VARCHAR(50) DEFAULT NULL,
                address TEXT DEFAULT NULL,
                profile_photo_filename VARCHAR(255) DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                CONSTRAINT fk_test_employee_profiles_user_id FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci"
        );

        $this->connection->exec(
            "CREATE TABLE attendance_records (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT UNSIGNED NOT NULL,
                attendance_date DATE NOT NULL,
                clock_in_time TIME DEFAULT NULL,
                clock_out_time TIME DEFAULT NULL,
                working_hours DECIMAL(5,2) NOT NULL DEFAULT 0.00,
                status ENUM('present', 'absent', 'holiday', 'weekend') NOT NULL DEFAULT 'present',
                late_minutes INT UNSIGNED NOT NULL DEFAULT 0,
                remarks VARCHAR(255) DEFAULT NULL,
                check_in_at DATETIME NULL,
                check_out_at DATETIME NULL,
                business_date DATE NULL,
                day_type ENUM('working_day', 'non_working_day', 'holiday') NULL,
                attendance_state ENUM('present', 'absent', 'incomplete', 'out_of_schedule') NULL,
                is_late TINYINT(1) NOT NULL DEFAULT 0,
                is_early_departure TINYINT(1) NOT NULL DEFAULT 0,
                is_missing_check_out TINYINT(1) NOT NULL DEFAULT 0,
                is_manual_override TINYINT(1) NOT NULL DEFAULT 0,
                is_overnight_attendance TINYINT(1) NOT NULL DEFAULT 0,
                regular_minutes INT UNSIGNED NULL,
                early_departure_minutes INT UNSIGNED NOT NULL DEFAULT 0,
                early_departure_reason VARCHAR(100) NULL,
                early_departure_note VARCHAR(500) NULL,
                early_overtime_minutes INT UNSIGNED NOT NULL DEFAULT 0,
                after_hours_overtime_minutes INT UNSIGNED NOT NULL DEFAULT 0,
                non_working_day_overtime_minutes INT UNSIGNED NOT NULL DEFAULT 0,
                holiday_overtime_minutes INT UNSIGNED NOT NULL DEFAULT 0,
                raw_calculated_overtime_minutes INT UNSIGNED NULL,
                calculated_overtime_minutes INT UNSIGNED NULL,
                approved_overtime_minutes INT UNSIGNED NULL,
                overtime_approval_status ENUM('not_required', 'pending', 'approved', 'rejected') NULL,
                resolved_policy_version_id INT UNSIGNED NULL,
                resolved_assignment_id INT UNSIGNED NULL,
                calculation_version VARCHAR(20) NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_attendance_user_date (user_id, attendance_date),
                KEY idx_attendance_user_id (user_id),
                KEY idx_attendance_date (attendance_date),
                KEY idx_attendance_date_status_user (attendance_date, status, user_id),
                CONSTRAINT fk_test_attendance_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci"
        );

        $this->connection->exec(
            "CREATE TABLE attendance_sessions (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                attendance_id INT UNSIGNED NOT NULL,
                check_in_at DATETIME NOT NULL,
                check_out_at DATETIME NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_test_attendance_sessions_time (attendance_id, check_in_at, id),
                KEY idx_test_attendance_sessions_open (attendance_id, check_out_at),
                CONSTRAINT fk_test_attendance_sessions_attendance FOREIGN KEY (attendance_id) REFERENCES attendance_records (id) ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT chk_test_attendance_sessions_interval CHECK (check_out_at IS NULL OR check_out_at > check_in_at)
            ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci"
        );

        $this->connection->exec(
            "CREATE TABLE overtime_decisions (
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
                KEY idx_test_overtime_decisions_attendance (attendance_id),
                CONSTRAINT fk_test_overtime_decisions_attendance FOREIGN KEY (attendance_id) REFERENCES attendance_records (id) ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT fk_test_overtime_decisions_decided_by FOREIGN KEY (decided_by) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
            ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci"
        );

        $this->connection->exec(
            "CREATE TABLE holidays (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                holiday_name VARCHAR(255) NOT NULL,
                holiday_date DATE NOT NULL,
                description TEXT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_holidays_date (holiday_date)
            ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci"
        );

        $this->connection->exec(
            "CREATE TABLE leave_requests (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT UNSIGNED NOT NULL,
                leave_type ENUM('casual', 'medical', 'unpaid', 'other') NOT NULL,
                reason_category VARCHAR(32) DEFAULT NULL,
                start_date DATE NOT NULL,
                end_date DATE NOT NULL,
                duration_days INT UNSIGNED NOT NULL,
                reason TEXT DEFAULT NULL,
                status ENUM('pending', 'approved', 'rejected', 'cancelled') NOT NULL DEFAULT 'pending',
                reviewed_by INT UNSIGNED DEFAULT NULL,
                reviewed_at TIMESTAMP NULL DEFAULT NULL,
                review_note TEXT DEFAULT NULL,
                cancelled_by INT UNSIGNED DEFAULT NULL,
                cancelled_at TIMESTAMP NULL DEFAULT NULL,
                cancel_reason TEXT DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_leave_requests_user_id (user_id),
                KEY idx_leave_requests_status (status),
                KEY idx_leave_requests_leave_type (leave_type),
                KEY idx_leave_requests_start_date (start_date),
                KEY idx_leave_requests_end_date (end_date),
                KEY idx_leave_requests_user_range (user_id, start_date, end_date),
                KEY idx_leave_status_user_range (status, user_id, start_date, end_date),
                CONSTRAINT fk_test_leave_requests_user_id FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT fk_test_leave_requests_reviewed_by FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE,
                CONSTRAINT fk_test_leave_requests_cancelled_by FOREIGN KEY (cancelled_by) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
            ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci"
        );

        $this->connection->exec(
            "CREATE TABLE notifications (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT UNSIGNED NOT NULL,
                type VARCHAR(80) NOT NULL,
                category VARCHAR(32) NOT NULL DEFAULT 'system',
                priority ENUM('urgent', 'high', 'normal', 'low') NOT NULL DEFAULT 'normal',
                title_key VARCHAR(150) NOT NULL,
                message_key VARCHAR(150) NOT NULL,
                context_json LONGTEXT NULL,
                deduplication_key VARCHAR(190) NULL,
                link_url VARCHAR(255) NULL,
                related_user_id INT UNSIGNED NULL,
                related_leave_request_id INT UNSIGNED NULL,
                related_work_schedule_assignment_id INT UNSIGNED NULL,
                read_at DATETIME NULL,
                archived_at DATETIME NULL,
                cancelled_at DATETIME NULL,
                available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                INDEX idx_notifications_user_read_created (user_id, read_at, created_at),
                INDEX idx_notifications_user_available_read (user_id, available_at, read_at),
                INDEX idx_notifications_center_filters (user_id, category, priority, archived_at, cancelled_at, available_at),
                INDEX idx_notifications_deduplication (user_id, deduplication_key),
                INDEX idx_notifications_related_leave (related_leave_request_id),
                INDEX idx_notifications_related_assignment (related_work_schedule_assignment_id),
                CONSTRAINT fk_test_notifications_user_id FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT fk_test_notifications_related_user_id FOREIGN KEY (related_user_id) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE,
                CONSTRAINT fk_test_notifications_related_leave_request_id FOREIGN KEY (related_leave_request_id) REFERENCES leave_requests (id) ON DELETE SET NULL ON UPDATE CASCADE,
                CONSTRAINT fk_test_notifications_related_assignment FOREIGN KEY (related_work_schedule_assignment_id) REFERENCES work_schedule_assignments (id) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci"
        );

        $this->connection->exec(
            "CREATE TABLE leave_request_attachments (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                leave_request_id INT UNSIGNED NOT NULL,
                file_path VARCHAR(255) NOT NULL,
                original_name VARCHAR(255) NOT NULL,
                mime_type VARCHAR(100) NOT NULL,
                file_size INT UNSIGNED NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_leave_attachments_leave_id (leave_request_id),
                CONSTRAINT fk_test_leave_attachments_leave_id FOREIGN KEY (leave_request_id) REFERENCES leave_requests(id) ON DELETE CASCADE
            ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci"
        );

        $this->connection->exec(
            "CREATE TABLE audit_logs (
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
                CONSTRAINT fk_test_audit_actor FOREIGN KEY (actor_user_id) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
            ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci"
        );
    }

    private function insertUser(string $name, string $email, string $role, string $status, ?int $departmentId, ?int $positionId): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO users (name, email, password, role, department_id, position_id, status, staff_code, email_verified_at) VALUES (:name, :email, :password, :role, :department_id, :position_id, :status, :staff_code, NOW())'
        );
        $statement->execute([
            ':name' => $name,
            ':email' => $email,
            ':password' => password_hash('Password123!', PASSWORD_DEFAULT),
            ':role' => $role,
            ':department_id' => $departmentId,
            ':position_id' => $positionId,
            ':status' => $status,
            ':staff_code' => 'SAP-' . str_pad((string) ((int) $this->connection->query('SELECT COUNT(*) + 1 FROM users')->fetchColumn()), 4, '0', STR_PAD_LEFT),
        ]);
        $userId = (int) $this->connection->lastInsertId();

        $this->connection->exec("UPDATE staff_code_sequences SET next_number = (SELECT COUNT(*) + 1 FROM users) WHERE sequence_key = 'employee'");

        return $userId;
    }

    private function seedDefaultScheduleFoundation(): void
    {
        $this->connection->exec(
            "INSERT INTO work_schedule_templates (name, description, timezone, effective_from, effective_to, is_active)
             VALUES ('SAPAS Standard Office', 'Seeded default schedule matching the current SAPAS attendance policy.', NULL, '2026-01-01', NULL, 1)"
        );
        $scheduleId = (int) $this->connection->lastInsertId();

        $insertDay = $this->connection->prepare(
            'INSERT INTO work_schedule_days (work_schedule_template_id, day_of_week, is_working_day, start_time, end_time, is_overnight, break_minutes, grace_minutes, expected_minutes)
             VALUES (:schedule_id, :day_of_week, :is_working_day, :start_time, :end_time, 0, :break_minutes, :grace_minutes, :expected_minutes)'
        );

        for ($day = 1; $day <= 7; $day++) {
            $isWorking = $day <= 5;
            $insertDay->execute([
                ':schedule_id' => $scheduleId,
                ':day_of_week' => $day,
                ':is_working_day' => $isWorking ? 1 : 0,
                ':start_time' => $isWorking ? '09:00:00' : null,
                ':end_time' => $isWorking ? '17:00:00' : null,
                ':break_minutes' => 0,
                ':grace_minutes' => $isWorking ? 30 : 0,
                ':expected_minutes' => $isWorking ? 480 : 0,
            ]);
        }

        $this->connection->prepare(
            "INSERT INTO company_profiles (company_name, short_name, timezone, date_format, time_format, default_work_schedule_id)
             VALUES ('Smart Attendance And Performance Analytic System', 'Smart Attendance', 'Asia/Yangon', 'Y-m-d', 'H:i', :schedule_id)"
        )->execute([':schedule_id' => $scheduleId]);

        $this->connection->prepare(
            'INSERT INTO attendance_policy_settings (work_schedule_template_id) VALUES (:schedule_id)'
        )->execute([':schedule_id' => $scheduleId]);

        $this->connection->prepare(
            'INSERT INTO overtime_policy_settings (work_schedule_template_id) VALUES (:schedule_id)'
        )->execute([':schedule_id' => $scheduleId]);

        $this->connection->prepare(
            "INSERT INTO work_schedule_assignments (work_schedule_template_id, scope_type, scope_id, effective_from, is_active)
             VALUES (:schedule_id, 'company', NULL, '1970-01-01', 1)"
        )->execute([':schedule_id' => $scheduleId]);
    }

    private static function assertSafeTestDatabaseName(string $databaseName, string $applicationDatabaseName): void
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $databaseName)) {
            throw new RuntimeException('Test database name may contain only letters, numbers, and underscores.');
        }

        if (!str_contains(strtolower($databaseName), 'test')) {
            throw new RuntimeException('Refusing to reset a database whose name does not contain "test".');
        }

        if ($applicationDatabaseName !== '' && strcasecmp($databaseName, $applicationDatabaseName) === 0) {
            throw new RuntimeException('Refusing to use the configured application database as the test database.');
        }
    }

    private static function quoteIdentifier(string $identifier): string
    {
        self::assertSafeTestDatabaseName($identifier, '');

        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}

function integration_database(): TestDatabase
{
    static $database = null;
    static $failure = null;

    if ($database instanceof TestDatabase) {
        return $database;
    }

    if ($failure !== null) {
        skip_test($failure);
    }

    try {
        $database = TestDatabase::connect();
        return $database;
    } catch (Throwable $exception) {
        $failure = 'MySQL test database is unavailable: ' . $exception->getMessage();
        skip_test($failure);
    }
}
