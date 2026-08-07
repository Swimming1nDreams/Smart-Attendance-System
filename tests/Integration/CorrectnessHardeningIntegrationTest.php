<?php

declare(strict_types=1);

use App\Models\Attendance;
use App\Models\LeaveRequest;
use App\Services\AttendanceOperationException;
use App\Services\AttendanceOperationService;
use App\Services\AttendancePolicyService;
use App\Services\AttendanceCalculationService;
use App\Services\AuditLogService;
use App\Services\DatabaseOperationException;
use App\Services\LeaveOperationException;
use App\Services\LeaveOperationService;
use App\Services\WorkScheduleResolverService;

test('database integration: employee-specific expected minutes drive attendance overtime', function (): void {
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $pdo = $database->pdo();
    $pdo->prepare('UPDATE users SET expected_daily_work_hours = 7.00 WHERE id = :id')->execute([':id' => $ids['employee_id']]);
    $policy = new AttendancePolicyService();
    $operations = new AttendanceOperationService($pdo, $policy);
    $attendance = new Attendance($pdo, $policy);

    $operations->createRecord($ids['employee_id'], '2026-07-22', '09:00:00', '16:30:00', 'present', null);
    $record = $attendance->findByUserIdAndDate($ids['employee_id'], '2026-07-22');
    assert_not_null($record);
    $record = $policy->enrichRecord($record);

    assert_same(420, $record['expected_work_minutes']);
    assert_same(450, $record['actual_work_minutes']);
    assert_same(30, $record['overtime_minutes']);
    assert_same('7 hr 30 min', $record['working_duration']);
});

test('database integration: invalid attendance edit rolls back and duplicate insert is domain-safe', function (): void {
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $policy = new AttendancePolicyService();
    $operations = new AttendanceOperationService($database->pdo(), $policy);
    $attendance = new Attendance($database->pdo(), $policy);

    $operations->createRecord($ids['employee_id'], '2026-07-22', '09:00:00', '17:00:00', 'present', 'original');
    $original = $attendance->findByUserIdAndDate($ids['employee_id'], '2026-07-22');
    assert_not_null($original);

    assert_same('clock_out_before_clock_in', capture_attendance_operation_reason(static function () use ($operations, $original, $ids): void {
        $operations->updateRecord((int) $original['id'], $ids['employee_id'], '2026-07-22', '17:00:00', '09:00:00', 'present', 'changed');
    }));

    $unchanged = $attendance->findById((int) $original['id']);
    assert_same('09:00:00', $unchanged['clock_in_time']);
    assert_same('17:00:00', $unchanged['clock_out_time']);
    assert_same('original', $unchanged['remarks']);

    assert_same('duplicate', capture_attendance_operation_reason(static function () use ($operations, $ids): void {
        $operations->createRecord($ids['employee_id'], '2026-07-22', '09:05:00', null, 'present', null);
    }));
});

test('database integration: stale attendance edit tokens cannot overwrite newer corrections', function (): void {
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $policy = new AttendancePolicyService();
    $operations = new AttendanceOperationService($database->pdo(), $policy);
    $attendance = new Attendance($database->pdo(), $policy);

    $operations->createRecord($ids['employee_id'], '2026-07-22', '09:00:00', '17:00:00', 'present', 'original');
    $original = $attendance->findByUserIdAndDate($ids['employee_id'], '2026-07-22');
    assert_not_null($original);
    $staleToken = AttendanceOperationService::freshnessToken($original);

    $operations->updateRecord((int) $original['id'], $ids['employee_id'], '2026-07-22', '09:10:00', '17:00:00', 'present', 'first edit', null, [], $staleToken);

    assert_same('concurrent_update', capture_attendance_operation_reason(static function () use ($operations, $original, $ids, $staleToken): void {
        $operations->updateRecord((int) $original['id'], $ids['employee_id'], '2026-07-22', '09:00:00', '17:00:00', 'present', 'stale edit', null, [], $staleToken);
    }));

    $current = $attendance->findById((int) $original['id']);
    assert_same('09:10:00', $current['clock_in_time']);
    assert_same('first edit', $current['remarks']);
});

test('database integration: clock-out is guarded and recalculates official values atomically', function (): void {
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $policy = new AttendancePolicyService();
    $operations = new AttendanceOperationService($database->pdo(), $policy);
    $attendance = new Attendance($database->pdo(), $policy);

    $operations->clockIn($ids['employee_id'], '2026-07-22', '09:31:00');
    $open = $attendance->findByUserIdAndDate($ids['employee_id'], '2026-07-22');
    assert_same(31, (int) $open['late_minutes']);
    assert_null($open['clock_out_time']);

    $operations->clockOut($ids['employee_id'], '2026-07-22', '17:31:00');
    $closed = $attendance->findByUserIdAndDate($ids['employee_id'], '2026-07-22');
    assert_same('17:31:00', $closed['clock_out_time']);
    assert_same(8.0, (float) $closed['working_hours']);
    assert_same('already_clocked_out', capture_attendance_operation_reason(static function () use ($operations, $ids): void {
        $operations->clockOut($ids['employee_id'], '2026-07-22', '17:32:00');
    }));
});

test('database integration: employee self clock in and clock out audit only successful logical actions', function (): void {
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $pdo = $database->pdo();
    $policy = new AttendancePolicyService();
    $operations = new AttendanceOperationService($pdo, $policy, new AuditLogService($pdo), new WorkScheduleResolverService($pdo), new AttendanceCalculationService());
    $attendance = new Attendance($pdo, $policy);
    seed_employee_profile($pdo, $ids['employee_id']);

    $operations->clockIn($ids['employee_id'], '2026-07-22', '09:31:00', $ids['employee_id'], ['ip_address' => '127.0.0.1']);
    $open = $attendance->findByUserIdAndDate($ids['employee_id'], '2026-07-22');
    assert_not_null($open);
    assert_same('09:31:00', $open['clock_in_time']);
    assert_null($open['clock_out_time']);
    assert_same('incomplete', $open['attendance_state']);
    assert_same(1, (int) $open['is_missing_check_out']);
    assert_same(31, (int) $open['late_minutes']);
    assert_same(1, attendance_audit_count($pdo, 'attendance.checked_in'));
    assert_same(0, attendance_audit_count($pdo, 'attendance.checked_out'));

    assert_same('already_clocked_in', capture_attendance_operation_reason(static function () use ($operations, $ids): void {
        $operations->clockIn($ids['employee_id'], '2026-07-22', '09:32:00', $ids['employee_id']);
    }));
    $stillOpen = $attendance->findByUserIdAndDate($ids['employee_id'], '2026-07-22');
    assert_same('09:31:00', $stillOpen['clock_in_time']);
    assert_same(1, attendance_audit_count($pdo, 'attendance.checked_in'));

    $operations->clockOut($ids['employee_id'], '2026-07-22', '17:00:00', $ids['employee_id'], ['ip_address' => '127.0.0.1']);
    $closed = $attendance->findByUserIdAndDate($ids['employee_id'], '2026-07-22');
    assert_same('17:00:00', $closed['clock_out_time']);
    assert_same('present', $closed['attendance_state']);
    assert_same(0, (int) $closed['is_missing_check_out']);
    assert_same(7.48, (float) $closed['working_hours']);
    assert_same(0, (int) $closed['calculated_overtime_minutes']);
    assert_same(1, attendance_audit_count($pdo, 'attendance.checked_in'));
    assert_same(1, attendance_audit_count($pdo, 'attendance.checked_out'));
    assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn());
    assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM overtime_decisions')->fetchColumn());

    assert_same('already_clocked_out', capture_attendance_operation_reason(static function () use ($operations, $ids): void {
        $operations->clockOut($ids['employee_id'], '2026-07-22', '17:32:00', $ids['employee_id']);
    }));
    $unchanged = $attendance->findByUserIdAndDate($ids['employee_id'], '2026-07-22');
    assert_same('17:00:00', $unchanged['clock_out_time']);
    assert_same(1, attendance_audit_count($pdo, 'attendance.checked_out'));
});

test('database integration: administrator attendance create and update writes are audited', function (): void {
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $pdo = $database->pdo();
    $policy = new AttendancePolicyService();
    $operations = new AttendanceOperationService($pdo, $policy, new AuditLogService($pdo), new WorkScheduleResolverService($pdo), new AttendanceCalculationService());
    $attendance = new Attendance($pdo, $policy);

    $operations->createRecord(
        $ids['employee_id'],
        '2026-07-23',
        '09:00:00',
        '17:00:00',
        'present',
        'Administrator correction',
        $ids['admin_id'],
        ['ip_address' => '127.0.0.1']
    );

    assert_same(1, attendance_audit_count($pdo, 'attendance.created'));
    assert_same(0, attendance_audit_count($pdo, 'attendance.updated'));

    $record = $attendance->findByUserIdAndDate($ids['employee_id'], '2026-07-23');
    assert_not_null($record);
    $freshnessToken = AttendanceOperationService::freshnessToken($record);

    $operations->updateRecord(
        (int) $record['id'],
        $ids['employee_id'],
        '2026-07-23',
        '09:15:00',
        '17:00:00',
        'present',
        'Administrator correction updated',
        $ids['admin_id'],
        ['ip_address' => '127.0.0.1'],
        $freshnessToken
    );

    assert_same(1, attendance_audit_count($pdo, 'attendance.created'));
    assert_same(1, attendance_audit_count($pdo, 'attendance.updated'));
});

test('database integration: employee self attendance rolls back when success audit cannot be written', function (): void {
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $pdo = $database->pdo();
    $policy = new AttendancePolicyService();
    $attendance = new Attendance($pdo, $policy);
    seed_employee_profile($pdo, $ids['employee_id']);

    $pdo->exec('DROP TABLE audit_logs');
    $auditedOperations = new AttendanceOperationService($pdo, $policy, new AuditLogService($pdo), new WorkScheduleResolverService($pdo), new AttendanceCalculationService());

    $failedClockIn = false;
    try {
        $auditedOperations->clockIn($ids['employee_id'], '2026-07-22', '09:00:00', $ids['employee_id']);
    } catch (DatabaseOperationException) {
        $failedClockIn = true;
    }
    assert_true($failedClockIn);
    assert_null($attendance->findByUserIdAndDate($ids['employee_id'], '2026-07-22'));

    $ids = $database->resetAndSeed();
    $pdo = $database->pdo();
    seed_employee_profile($pdo, $ids['employee_id']);
    $attendance = new Attendance($pdo, $policy);
    $plainOperations = new AttendanceOperationService($pdo, $policy, null, new WorkScheduleResolverService($pdo), new AttendanceCalculationService());
    $plainOperations->clockIn($ids['employee_id'], '2026-07-22', '09:00:00');
    $open = $attendance->findByUserIdAndDate($ids['employee_id'], '2026-07-22');
    assert_not_null($open);
    assert_null($open['clock_out_time']);

    $pdo->exec('DROP TABLE audit_logs');
    $auditedOperations = new AttendanceOperationService($pdo, $policy, new AuditLogService($pdo), new WorkScheduleResolverService($pdo), new AttendanceCalculationService());
    $failedClockOut = false;
    try {
        $auditedOperations->clockOut($ids['employee_id'], '2026-07-22', '17:00:00', $ids['employee_id']);
    } catch (DatabaseOperationException) {
        $failedClockOut = true;
    }
    assert_true($failedClockOut);
    $stillOpen = $attendance->findByUserIdAndDate($ids['employee_id'], '2026-07-22');
    assert_null($stillOpen['clock_out_time']);
    assert_same('incomplete', $stillOpen['attendance_state']);
    assert_same(1, (int) $stillOpen['is_missing_check_out']);
});

test('database integration: weekend and holiday check-in records special-day overtime', function (): void {
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $pdo = $database->pdo();
    $policy = new AttendancePolicyService();
    $operations = new AttendanceOperationService($pdo, $policy);
    $attendance = new Attendance($pdo, $policy);

    $operations->clockIn($ids['employee_id'], '2026-07-25', '08:00:00');
    $operations->clockOut($ids['employee_id'], '2026-07-25', '12:00:00');
    $weekend = $attendance->findByUserIdAndDate($ids['employee_id'], '2026-07-25');
    assert_not_null($weekend);
    assert_same('weekend', $weekend['status']);
    $weekend = $policy->enrichRecord($weekend);
    assert_same(240, $weekend['actual_work_minutes']);
    assert_same(240, $weekend['overtime_minutes']);

    $pdo->exec("INSERT INTO holidays (holiday_name, holiday_date) VALUES ('Special Work Holiday', '2026-07-24')");
    $operations->clockIn($ids['employee_id'], '2026-07-24', '07:30:00');
    $operations->clockOut($ids['employee_id'], '2026-07-24', '11:00:00');
    $holiday = $attendance->findByUserIdAndDate($ids['employee_id'], '2026-07-24');
    assert_not_null($holiday);
    assert_same('holiday', $holiday['status']);
    $holiday = $policy->enrichRecord($holiday, true);
    assert_same(210, $holiday['actual_work_minutes']);
    assert_same(210, $holiday['overtime_minutes']);
});

test('database integration: employee self check-in restrictions respect leave status and avoid failure side effects', function (): void {
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $pdo = $database->pdo();
    seed_employee_profile($pdo, $ids['employee_id']);
    $policy = new AttendancePolicyService();
    $operations = new AttendanceOperationService($pdo, $policy, new AuditLogService($pdo), new WorkScheduleResolverService($pdo), new AttendanceCalculationService());
    $attendance = new Attendance($pdo, $policy);
    $leaveModel = new LeaveRequest($pdo);

    $leaveModel->create($ids['employee_id'], 'casual', '2026-08-03', '2026-08-03', 1, 'Approved', 'approved');
    assert_same('approved_leave_conflict', capture_attendance_operation_reason(static function () use ($operations, $ids): void {
        $operations->clockIn($ids['employee_id'], '2026-08-03', '09:00:00', $ids['employee_id']);
    }));
    assert_null($attendance->findByUserIdAndDate($ids['employee_id'], '2026-08-03'));
    assert_same(0, attendance_audit_count($pdo, 'attendance.checked_in'));

    foreach (['pending' => '2026-08-04', 'rejected' => '2026-08-05', 'cancelled' => '2026-08-06'] as $status => $date) {
        $leaveModel->create($ids['employee_id'], 'casual', $date, $date, 1, ucfirst($status), $status);
        $operations->clockIn($ids['employee_id'], $date, '09:00:00', $ids['employee_id']);
        $record = $attendance->findByUserIdAndDate($ids['employee_id'], $date);
        assert_not_null($record);
        assert_same('09:00:00', $record['clock_in_time']);
    }

    assert_same(3, attendance_audit_count($pdo, 'attendance.checked_in'));
});

test('database integration: employee self check-in follows non-working and holiday policy flags', function (): void {
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $pdo = $database->pdo();
    seed_employee_profile($pdo, $ids['employee_id']);
    $policy = new AttendancePolicyService();
    $operations = new AttendanceOperationService($pdo, $policy, new AuditLogService($pdo), new WorkScheduleResolverService($pdo), new AttendanceCalculationService());
    $attendance = new Attendance($pdo, $policy);

    $pdo->exec('UPDATE attendance_policy_settings SET allow_non_working_day_check_in = 0, allow_holiday_check_in = 1');
    assert_same('non_working_day_blocked', $operations->selfClockInBlockReason($ids['employee_id'], '2026-07-25'));
    assert_same('non_working_day_blocked', capture_attendance_operation_reason(static function () use ($operations, $ids): void {
        $operations->clockIn($ids['employee_id'], '2026-07-25', '08:00:00', $ids['employee_id']);
    }));
    assert_null($attendance->findByUserIdAndDate($ids['employee_id'], '2026-07-25'));
    assert_same(0, attendance_audit_count($pdo, 'attendance.checked_in'));

    $pdo->exec('UPDATE attendance_policy_settings SET allow_non_working_day_check_in = 1');
    assert_null($operations->selfClockInBlockReason($ids['employee_id'], '2026-07-25'));
    $operations->clockIn($ids['employee_id'], '2026-07-25', '08:00:00', $ids['employee_id']);
    $operations->clockOut($ids['employee_id'], '2026-07-25', '12:00:00', $ids['employee_id']);
    $weekend = $attendance->findByUserIdAndDate($ids['employee_id'], '2026-07-25');
    assert_not_null($weekend);
    assert_same('weekend', $weekend['status']);
    assert_same('non_working_day', $weekend['day_type']);
    assert_same(0, (int) $weekend['late_minutes']);
    assert_same(240, (int) $weekend['calculated_overtime_minutes']);
});

test('database integration: employee self check-in treats holiday as higher precedence than weekend', function (): void {
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $pdo = $database->pdo();
    seed_employee_profile($pdo, $ids['employee_id']);
    $policy = new AttendancePolicyService();
    $operations = new AttendanceOperationService($pdo, $policy, new AuditLogService($pdo), new WorkScheduleResolverService($pdo), new AttendanceCalculationService());
    $attendance = new Attendance($pdo, $policy);

    $pdo->exec("INSERT INTO holidays (holiday_name, holiday_date) VALUES ('Weekend Holiday', '2026-07-25')");
    $pdo->exec('UPDATE attendance_policy_settings SET allow_non_working_day_check_in = 0, allow_holiday_check_in = 0');
    assert_same('holiday_blocked', $operations->selfClockInBlockReason($ids['employee_id'], '2026-07-25'));
    assert_same('holiday_blocked', capture_attendance_operation_reason(static function () use ($operations, $ids): void {
        $operations->clockIn($ids['employee_id'], '2026-07-25', '08:00:00', $ids['employee_id']);
    }));
    assert_null($attendance->findByUserIdAndDate($ids['employee_id'], '2026-07-25'));

    $pdo->exec('UPDATE attendance_policy_settings SET allow_holiday_check_in = 1');
    assert_null($operations->selfClockInBlockReason($ids['employee_id'], '2026-07-25'));
    $operations->clockIn($ids['employee_id'], '2026-07-25', '08:00:00', $ids['employee_id']);
    $operations->clockOut($ids['employee_id'], '2026-07-25', '12:00:00', $ids['employee_id']);
    $holiday = $attendance->findByUserIdAndDate($ids['employee_id'], '2026-07-25');
    assert_not_null($holiday);
    assert_same('holiday', $holiday['status']);
    assert_same('holiday', $holiday['day_type']);
    assert_same(0, (int) $holiday['late_minutes']);
    assert_same(240, (int) $holiday['calculated_overtime_minutes']);
});

test('database integration: employee self check-out remains available after leave or policy changes following check-in', function (): void {
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $pdo = $database->pdo();
    seed_employee_profile($pdo, $ids['employee_id']);
    $policy = new AttendancePolicyService();
    $operations = new AttendanceOperationService($pdo, $policy, new AuditLogService($pdo), new WorkScheduleResolverService($pdo), new AttendanceCalculationService());
    $attendance = new Attendance($pdo, $policy);
    $leaveModel = new LeaveRequest($pdo);

    $operations->clockIn($ids['employee_id'], '2026-08-07', '09:00:00', $ids['employee_id']);
    $leaveModel->create($ids['employee_id'], 'casual', '2026-08-07', '2026-08-07', 1, 'Approved after Check In', 'approved');
    $operations->clockOut($ids['employee_id'], '2026-08-07', '17:00:00', $ids['employee_id']);

    $closed = $attendance->findByUserIdAndDate($ids['employee_id'], '2026-08-07');
    assert_not_null($closed);
    assert_same('17:00:00', $closed['clock_out_time']);
    assert_same('present', $closed['attendance_state']);
    assert_same(1, attendance_audit_count($pdo, 'attendance.checked_in'));
    assert_same(1, attendance_audit_count($pdo, 'attendance.checked_out'));
});

test('database integration: invalid self-attendance accounts fail safely', function (): void {
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $pdo = $database->pdo();
    $policy = new AttendancePolicyService();
    $operations = new AttendanceOperationService($pdo, $policy, new AuditLogService($pdo), new WorkScheduleResolverService($pdo), new AttendanceCalculationService());
    $attendance = new Attendance($pdo, $policy);

    assert_same('employee_profile_missing', $operations->selfClockInBlockReason($ids['employee_id'], '2026-08-10'));
    assert_same('employee_profile_missing', capture_attendance_operation_reason(static function () use ($operations, $ids): void {
        $operations->clockIn($ids['employee_id'], '2026-08-10', '09:00:00', $ids['employee_id']);
    }));
    assert_null($attendance->findByUserIdAndDate($ids['employee_id'], '2026-08-10'));

    seed_employee_profile($pdo, $ids['inactive_employee_id']);
    assert_same('employee_invalid', $operations->selfClockInBlockReason($ids['inactive_employee_id'], '2026-08-10'));
    assert_same('employee_invalid', capture_attendance_operation_reason(static function () use ($operations, $ids): void {
        $operations->clockIn($ids['inactive_employee_id'], '2026-08-10', '09:00:00', $ids['inactive_employee_id']);
    }));
    assert_null($attendance->findByUserIdAndDate($ids['inactive_employee_id'], '2026-08-10'));
    assert_same(0, attendance_audit_count($pdo, 'attendance.checked_in'));
});

test('database integration: leave overlap and decision races recheck locked state', function (): void {
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $leaveOperations = new LeaveOperationService($database->pdo());
    $leaveModel = new LeaveRequest($database->pdo());

    assert_same('invalid_date_range', capture_leave_operation_reason(static function () use ($leaveOperations, $ids): void {
        $leaveOperations->createRequest($ids['employee_id'], 'casual', '2026-02-30', '2026-03-02', 1, 'Invalid date');
    }));
    assert_same('invalid_duration', capture_leave_operation_reason(static function () use ($leaveOperations, $ids): void {
        $leaveOperations->createRequest($ids['employee_id'], 'casual', '2026-11-02', '2026-11-02', 0, 'Invalid duration');
    }));

    $firstId = $leaveOperations->createRequest($ids['employee_id'], 'casual', '2026-11-02', '2026-11-04', 3, 'First');
    assert_true($firstId > 0);
    assert_same('overlap', capture_leave_operation_reason(static function () use ($leaveOperations, $ids): void {
        $leaveOperations->createRequest($ids['employee_id'], 'medical', '2026-11-04', '2026-11-05', 2, 'Overlap');
    }));

    $decisionId = $leaveModel->create($ids['other_employee_id'], 'casual', '2026-11-09', '2026-11-09', 1, 'Decision', 'pending');
    $leaveOperations->approve($decisionId, $ids['admin_id'], '2026-07-22 10:00:00');
    assert_same('concurrent_update', capture_leave_operation_reason(static function () use ($leaveOperations, $decisionId, $ids): void {
        $leaveOperations->approve($decisionId, $ids['admin_id'], '2026-07-22 10:01:00');
    }));
    assert_same('approved', $leaveModel->findById($decisionId)['status']);
});

test('database integration: failed leave approval preserves pending state and attendance', function (): void {
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $policy = new AttendancePolicyService();
    $attendanceOperations = new AttendanceOperationService($database->pdo(), $policy);
    $leaveOperations = new LeaveOperationService($database->pdo());
    $leaveModel = new LeaveRequest($database->pdo());

    $attendanceOperations->createRecord($ids['employee_id'], '2026-11-10', '09:00:00', '17:00:00', 'present', null);
    $leaveId = $leaveModel->create($ids['employee_id'], 'medical', '2026-11-10', '2026-11-10', 1, 'Conflict', 'pending');

    assert_same('attendance_conflict', capture_leave_operation_reason(static function () use ($leaveOperations, $leaveId, $ids): void {
        $leaveOperations->approve($leaveId, $ids['admin_id'], '2026-07-22 10:00:00');
    }));
    assert_same('pending', $leaveModel->findById($leaveId)['status']);
});

test('database integration: empty leave result differs from controlled database failure', function (): void {
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $leaveModel = new LeaveRequest($database->pdo());

    assert_same([], $leaveModel->findRequestsForEmployee($ids['employee_id']));

    $database->pdo()->exec('SET FOREIGN_KEY_CHECKS = 0');
    $database->pdo()->exec('DROP TABLE leave_requests');
    $database->pdo()->exec('SET FOREIGN_KEY_CHECKS = 1');
    $thrown = false;
    try {
        $leaveModel->findRequestsForEmployee($ids['employee_id']);
    } catch (DatabaseOperationException $exception) {
        $thrown = $exception->getPrevious() !== null && $exception->getMessage() === 'Database operation failed.';
    }
    assert_true($thrown);
});

function capture_attendance_operation_reason(callable $callback): string
{
    try {
        $callback();
    } catch (AttendanceOperationException $exception) {
        return $exception->reason();
    }

    return '';
}

function capture_leave_operation_reason(callable $callback): string
{
    try {
        $callback();
    } catch (LeaveOperationException $exception) {
        return $exception->reason();
    }

    return '';
}

function attendance_audit_count(\PDO $pdo, string $action): int
{
    $statement = $pdo->prepare('SELECT COUNT(*) FROM audit_logs WHERE action = :action');
    $statement->execute([':action' => $action]);

    return (int) $statement->fetchColumn();
}

function seed_employee_profile(\PDO $pdo, int $userId): void
{
    $statement = $pdo->prepare('INSERT INTO employee_profiles (user_id, phone, address) VALUES (:user_id, NULL, NULL)');
    $statement->execute([':user_id' => $userId]);
}
