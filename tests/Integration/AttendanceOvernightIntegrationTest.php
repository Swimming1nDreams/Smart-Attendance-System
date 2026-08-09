<?php

declare(strict_types=1);

use App\Models\Attendance;
use App\Services\AttendanceCalculationService;
use App\Services\AttendanceOperationException;
use App\Services\AttendanceOperationService;
use App\Services\AttendancePolicyService;
use App\Services\WorkScheduleResolverService;

test('admin attendance supports assigned overnight schedules without allowing daytime reversed times', function (): void {
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $pdo = $database->pdo();
    $operations = new AttendanceOperationService(
        $pdo,
        new AttendancePolicyService(),
        null,
        new WorkScheduleResolverService($pdo),
        new AttendanceCalculationService()
    );
    $attendance = new Attendance($pdo, new AttendancePolicyService());

    assert_same('clock_out_before_clock_in', attendance_overnight_reason(static function () use ($operations, $ids): void {
        $operations->createRecord($ids['other_employee_id'], '2026-08-03', '22:00:00', '06:00:00', 'present', 'daytime reversed time');
    }));

    $scheduleId = attendance_overnight_schedule($pdo, $ids['employee_id'], $ids['admin_id']);

    assert_same('clock_out_before_clock_in', attendance_overnight_reason(static function () use ($operations, $ids): void {
        $operations->createRecord($ids['employee_id'], '2026-08-03', '22:00:00', '22:00:00', 'present', 'zero duration');
    }));

    $operations->createRecord($ids['employee_id'], '2026-08-03', '22:00:00', '06:00:00', 'present', 'valid overnight');
    $overnight = $attendance->findByUserIdAndDate($ids['employee_id'], '2026-08-03');
    assert_not_null($overnight);
    assert_same($scheduleId, (int) $overnight['resolved_policy_version_id']);
    assert_same('2026-08-03', $overnight['attendance_date']);
    assert_same('2026-08-03 22:00:00', $overnight['check_in_at']);
    assert_same('2026-08-04 06:00:00', $overnight['check_out_at']);
    assert_same(1, (int) $overnight['is_overnight_attendance']);
    assert_same('present', $overnight['status']);
    assert_same(0, (int) $overnight['late_minutes']);
    assert_same(480, (int) $overnight['regular_minutes']);
    assert_same(0, (int) $overnight['early_departure_minutes']);
    assert_same(0, (int) $overnight['calculated_overtime_minutes']);
    assert_same('v3', $overnight['calculation_version']);

    $listed = $attendance->findAll($ids['employee_id']);
    assert_same(1, count($listed));
    $enriched = (new AttendancePolicyService())->enrichRecord($listed[0]);
    assert_same(480, (int) $enriched['actual_work_minutes']);
    assert_same('8 hr', $enriched['working_duration']);

    $operations->updateRecord((int) $overnight['id'], $ids['employee_id'], '2026-08-03', '22:30:00', '06:00:00', 'present', 'late overnight');
    $updated = $attendance->findById((int) $overnight['id']);
    assert_same((int) $overnight['id'], (int) $updated['id']);
    assert_same('2026-08-04 06:00:00', $updated['check_out_at']);
    assert_same(450, (int) $updated['regular_minutes']);
    assert_same(30, (int) $updated['late_minutes']);
    assert_same(30, (int) $updated['early_departure_minutes']);
    assert_same(0, (int) $updated['calculated_overtime_minutes']);
});

function attendance_overnight_schedule(PDO $pdo, int $employeeId, int $adminId): int
{
    $pdo->prepare(
        "INSERT INTO work_schedule_templates (name, description, timezone, effective_from, is_active, lifecycle_status, created_by)
         VALUES ('UAT Overnight Integration Policy', 'Overnight integration policy', NULL, '2026-01-01', 1, 'active', :admin_id)"
    )->execute([':admin_id' => $adminId]);
    $scheduleId = (int) $pdo->lastInsertId();

    $insertDay = $pdo->prepare(
        'INSERT INTO work_schedule_days (work_schedule_template_id, day_of_week, is_working_day, start_time, end_time, is_overnight, break_minutes, grace_minutes, expected_minutes)
         VALUES (:schedule_id, :day_of_week, :is_working_day, :start_time, :end_time, :is_overnight, 0, :grace_minutes, :expected_minutes)'
    );
    for ($day = 1; $day <= 7; $day++) {
        $isWorking = $day <= 5;
        $insertDay->execute([
            ':schedule_id' => $scheduleId,
            ':day_of_week' => $day,
            ':is_working_day' => $isWorking ? 1 : 0,
            ':start_time' => $isWorking ? '22:00:00' : null,
            ':end_time' => $isWorking ? '06:00:00' : null,
            ':is_overnight' => $isWorking ? 1 : 0,
            ':grace_minutes' => $isWorking ? 15 : 0,
            ':expected_minutes' => $isWorking ? 480 : 0,
        ]);
    }

    $pdo->prepare(
        'INSERT INTO attendance_policy_settings (work_schedule_template_id, allow_overnight_attendance, maximum_attendance_minutes)
         VALUES (:schedule_id, 1, 960)'
    )->execute([':schedule_id' => $scheduleId]);
    $pdo->prepare(
        'INSERT INTO overtime_policy_settings (work_schedule_template_id, minimum_overtime_minutes, rounding_mode, approval_required)
         VALUES (:schedule_id, 0, "exact", 0)'
    )->execute([':schedule_id' => $scheduleId]);
    $pdo->prepare(
        "INSERT INTO work_schedule_assignments (work_schedule_template_id, scope_type, scope_id, effective_from, is_active, created_by)
         VALUES (:schedule_id, 'employee', :employee_id, '2026-01-01', 1, :admin_id)"
    )->execute([':schedule_id' => $scheduleId, ':employee_id' => $employeeId, ':admin_id' => $adminId]);

    return $scheduleId;
}

function attendance_overnight_reason(callable $callback): ?string
{
    try {
        $callback();
    } catch (AttendanceOperationException $exception) {
        return $exception->reason();
    }

    return null;
}
