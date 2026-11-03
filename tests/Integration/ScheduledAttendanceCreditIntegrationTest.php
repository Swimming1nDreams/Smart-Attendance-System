<?php

declare(strict_types=1);

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Services\AnalyticsService;
use App\Services\AttendanceCalculationService;
use App\Services\AttendanceOperationService;
use App\Services\AttendancePolicyService;
use App\Services\AttendanceReportContextService;
use App\Services\WorkScheduleResolverService;

test('zero-overlap work stays pending before threshold and becomes derived absent after threshold', function (): void {
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $pdo = $database->pdo();
    $pdo->prepare('INSERT INTO employee_profiles (user_id) VALUES (:user_id)')->execute([':user_id' => $ids['employee_id']]);
    $pdo->exec('UPDATE overtime_policy_settings SET approval_required = 1, minimum_overtime_minutes = 0, rounding_mode = "exact"');

    $operations = scheduled_credit_operations($pdo);
    $operations->clockIn($ids['employee_id'], '2026-08-12', '04:37:00', $ids['employee_id']);
    $preview = $operations->previewClockOut($ids['employee_id'], '2026-08-12', '06:47:00');
    assert_same('out_of_schedule', $preview['attendance_state']);
    assert_same(0, $preview['is_early_departure']);
    $operations->clockOut($ids['employee_id'], '2026-08-12', '06:47:00', $ids['employee_id']);

    $row = (new Attendance($pdo))->findByUserIdAndDate($ids['employee_id'], '2026-08-12');
    assert_same('out_of_schedule', $row['attendance_state']);
    assert_same(0, (int) $row['is_early_departure']);
    assert_same(null, $row['early_departure_reason']);
    assert_same(130, (int) $row['calculated_overtime_minutes']);
    assert_same(0, (int) $row['approved_overtime_minutes']);
    assert_same('pending', $row['overtime_approval_status']);

    $context = scheduled_credit_context($pdo);
    $pending = $context->enrich([$row], null, new DateTimeImmutable('2026-08-12 09:30:00', new DateTimeZone('Asia/Yangon')))[0];
    assert_same('pending', $pending['scheduled_attendance_status']);
    assert_same('out_of_schedule', $pending['attendance_display_status']);
    assert_same(0, $pending['scheduled_overlap_minutes']);
    assert_same(130, $pending['out_of_schedule_work_minutes']);

    $absent = $context->enrich([$row], null, new DateTimeImmutable('2026-08-12 10:00:01', new DateTimeZone('Asia/Yangon')))[0];
    assert_same('absent', $absent['scheduled_attendance_status']);
    assert_same('absent', $absent['attendance_display_status']);

    $stats = scheduled_credit_analytics($pdo)->generate(
        ['month' => '2026-08', 'department_id' => null, 'employee_id' => $ids['employee_id']],
        new DateTimeImmutable('2026-08-12 10:00:01', new DateTimeZone('Asia/Yangon')),
    )['employee_analytics'][0];
    assert_same(0, $stats['present_days']);
    assert_same(8, $stats['absent_days']);
    assert_same(0.0, $stats['attendance_percentage']);
    assert_false($stats['has_100_percent_attendance']);
    assert_same(130, $stats['out_of_schedule_work_minutes']);
    assert_same(130, $stats['pending_overtime_minutes']);
});

test('active pre-shift session satisfies attendance once authoritative now overlaps the shift', function (): void {
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $pdo = $database->pdo();
    $pdo->prepare('INSERT INTO attendance_records (user_id, attendance_date, clock_in_time, check_in_at, status, attendance_state) VALUES (:user_id, :date, :time, :at, "present", "incomplete")')
        ->execute([':user_id' => $ids['employee_id'], ':date' => '2026-08-12', ':time' => '04:37:00', ':at' => '2026-08-12 04:37:00']);

    $stats = scheduled_credit_analytics($pdo)->generate(
        ['month' => '2026-08', 'department_id' => null, 'employee_id' => $ids['employee_id']],
        new DateTimeImmutable('2026-08-12 10:00:00', new DateTimeZone('Asia/Yangon')),
    )['employee_analytics'][0];
    assert_same(1, $stats['present_days']);
    assert_same(7, $stats['absent_days']);
});

test('approved leave and special days remain scheduled exceptions when actual work is outside the shift', function (): void {
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $pdo = $database->pdo();
    $pdo->prepare('INSERT INTO leave_requests (user_id, leave_type, start_date, end_date, duration_days, status) VALUES (:user_id, "casual", "2026-08-12", "2026-08-12", 1, "approved")')
        ->execute([':user_id' => $ids['employee_id']]);
    $record = ['user_id' => $ids['employee_id'], 'attendance_date' => '2026-08-12', 'clock_in_time' => '04:37:00', 'clock_out_time' => '06:47:00', 'status' => 'present', 'early_departure_reason' => null, 'early_departure_note' => null];
    $leave = scheduled_credit_context($pdo)->enrich([$record], null, new DateTimeImmutable('2026-08-12 12:00:00', new DateTimeZone('Asia/Yangon')))[0];
    assert_same('approved_leave', $leave['scheduled_attendance_status']);
    assert_same('approved_leave', $leave['attendance_display_status']);

    $weekendRecord = ['user_id' => $ids['employee_id'], 'attendance_date' => '2026-08-15', 'clock_in_time' => '04:37:00', 'clock_out_time' => '06:47:00', 'status' => 'weekend', 'early_departure_reason' => null, 'early_departure_note' => null];
    $weekend = scheduled_credit_context($pdo)->enrich([$weekendRecord], [], new DateTimeImmutable('2026-08-15 12:00:00', new DateTimeZone('Asia/Yangon')))[0];
    assert_same('weekend', $weekend['scheduled_attendance_status']);
    assert_same('weekend', $weekend['attendance_display_status']);
});

function scheduled_credit_operations(PDO $pdo): AttendanceOperationService
{
    return new AttendanceOperationService($pdo, new AttendancePolicyService(), null, new WorkScheduleResolverService($pdo), new AttendanceCalculationService());
}

function scheduled_credit_context(PDO $pdo): AttendanceReportContextService
{
    $policy = new AttendancePolicyService();
    return new AttendanceReportContextService(new Holiday($pdo), new LeaveRequest($pdo), $policy, new WorkScheduleResolverService($pdo), new AttendanceCalculationService());
}

function scheduled_credit_analytics(PDO $pdo): AnalyticsService
{
    $policy = new AttendancePolicyService();
    return new AnalyticsService(new Employee($pdo), new Attendance($pdo, $policy), new Holiday($pdo), new LeaveRequest($pdo), $policy, new WorkScheduleResolverService($pdo), new AttendanceCalculationService());
}
