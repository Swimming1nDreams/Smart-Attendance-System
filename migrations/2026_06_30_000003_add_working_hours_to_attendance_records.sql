-- Add working_hours column to attendance_records table.
-- This migration is idempotent for WAMP/MySQL setups where migration history
-- may be missing but the column was already added manually or by a prior patch.

SET @sapas_add_working_hours_sql = (
    SELECT IF(
        EXISTS (
            SELECT 1
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'attendance_records'
              AND COLUMN_NAME = 'working_hours'
        ),
        'DO 1',
        'ALTER TABLE attendance_records ADD COLUMN working_hours DECIMAL(5,2) NULL AFTER clock_out_time'
    )
);

PREPARE sapas_add_working_hours_stmt FROM @sapas_add_working_hours_sql;
EXECUTE sapas_add_working_hours_stmt;
DEALLOCATE PREPARE sapas_add_working_hours_stmt;
