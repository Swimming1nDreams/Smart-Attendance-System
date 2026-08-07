-- Attendance records table for SAPAS

SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS attendance_records (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    attendance_date DATE NOT NULL,
    clock_in_time TIME DEFAULT NULL,
    clock_out_time TIME DEFAULT NULL,
    status ENUM('present', 'absent', 'holiday', 'weekend') NOT NULL DEFAULT 'present',
    late_minutes INT UNSIGNED NOT NULL DEFAULT 0,
    remarks VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_attendance_user_date (user_id, attendance_date),
    KEY idx_attendance_user_id (user_id),
    KEY idx_attendance_date (attendance_date),
    CONSTRAINT fk_attendance_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
