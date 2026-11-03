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
use App\Services\WorkScheduleResolverService;

test('analytics uses each resolved scheduled start for the strict auto-absent threshold', function (): void {
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $pdo = $database->pdo();
    $analytics = analytics_auto_absent_service($pdo);
    $timezone = new DateTimeZone('Asia/Yangon');

    $atCompanyThreshold = analytics_auto_absent_stats($analytics->generate(
        ['month' => '2026-08', 'employee_id' => $ids['employee_id']],
        new DateTimeImmutable('2026-08-03 10:00:00', $timezone),
    ), $ids['employee_id']);
    assert_same(0, $atCompanyThreshold['absent_days']);

    $afterCompanyThreshold = analytics_auto_absent_stats($analytics->generate(
        ['month' => '2026-08', 'employee_id' => $ids['employee_id']],
        new DateTimeImmutable('2026-08-03 10:00:01', $timezone),
    ), $ids['employee_id']);
    assert_same(1, $afterCompanyThreshold['absent_days']);

    analytics_auto_absent_assign_schedule($pdo, $ids['other_employee_id'], '10:00:00', '18:00:00', [1, 2, 3, 4, 5]);
    $duringOverrideLateWindow = analytics_auto_absent_stats($analytics->generate(
        ['month' => '2026-08', 'employee_id' => $ids['other_employee_id']],
        new DateTimeImmutable('2026-08-03 10:40:00', $timezone),
    ), $ids['other_employee_id']);
    assert_same(0, $duringOverrideLateWindow['absent_days']);

    $atOverrideThreshold = analytics_auto_absent_stats($analytics->generate(
        ['month' => '2026-08', 'employee_id' => $ids['other_employee_id']],
        new DateTimeImmutable('2026-08-03 11:00:00', $timezone),
    ), $ids['other_employee_id']);
    assert_same(0, $atOverrideThreshold['absent_days']);

    $afterOverrideThreshold = analytics_auto_absent_stats($analytics->generate(
        ['month' => '2026-08', 'employee_id' => $ids['other_employee_id']],
        new DateTimeImmutable('2026-08-03 11:00:01', $timezone),
    ), $ids['other_employee_id']);
    assert_same(1, $afterOverrideThreshold['absent_days']);
});

test('approved leave holidays and resolved non-working days suppress derived absence', function (): void {
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $pdo = $database->pdo();
    analytics_auto_absent_assign_schedule($pdo, $ids['employee_id'], '09:00:00', '17:00:00', [1, 2, 4, 5]);
    $pdo->prepare(
        "INSERT INTO leave_requests (user_id, leave_type, start_date, end_date, duration_days, status)
         VALUES (:user_id, 'casual', '2026-08-03', '2026-08-03', 1, 'approved')"
    )->execute([':user_id' => $ids['employee_id']]);
    $pdo->exec("INSERT INTO holidays (holiday_name, holiday_date) VALUES ('Analytics Holiday', '2026-08-04')");

    $result = analytics_auto_absent_service($pdo)->generate(
        ['month' => '2026-08', 'employee_id' => $ids['employee_id']],
        new DateTimeImmutable('2026-08-05 12:00:00', new DateTimeZone('Asia/Yangon')),
    );
    $stats = analytics_auto_absent_stats($result, $ids['employee_id']);
    $workingDates = array_fill_keys(array_column($result['working_days'], 'date'), true);

    assert_same(0, $stats['absent_days']);
    assert_same(1, $stats['approved_leave_days']);
    assert_true(isset($workingDates['2026-08-03']));
    assert_false(isset($workingDates['2026-08-04']));
    assert_false(isset($workingDates['2026-08-05']));
});

test('derived absence is not persisted and later actual attendance wins as late', function (): void {
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $pdo = $database->pdo();
    $analytics = analytics_auto_absent_service($pdo);
    $timezone = new DateTimeZone('Asia/Yangon');

    $derived = analytics_auto_absent_stats($analytics->generate(
        ['month' => '2026-08', 'employee_id' => $ids['employee_id']],
        new DateTimeImmutable('2026-08-03 10:05:00', $timezone),
    ), $ids['employee_id']);
    assert_same(1, $derived['absent_days']);
    assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM attendance_records')->fetchColumn());

    analytics_auto_absent_operations($pdo)->createRecord(
        $ids['employee_id'],
        '2026-08-03',
        '10:20:00',
        '17:00:00',
        'present',
        null,
        $ids['admin_id'],
    );

    $actual = analytics_auto_absent_stats($analytics->generate(
        ['month' => '2026-08', 'employee_id' => $ids['employee_id']],
        new DateTimeImmutable('2026-08-03 17:01:00', $timezone),
    ), $ids['employee_id']);
    assert_same(1, $actual['present_days']);
    assert_same(1, $actual['late_days']);
    assert_same(0, $actual['absent_days']);
    assert_same(100.0, $actual['attendance_percentage']);
    assert_true($actual['has_100_percent_attendance']);
    assert_same(1, (int) $pdo->query('SELECT COUNT(*) FROM attendance_records')->fetchColumn());
});

test('a matured missing day removes 100 percent recognition while pending and late days do not', function (): void {
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $pdo = $database->pdo();
    analytics_auto_absent_operations($pdo)->createRecord(
        $ids['employee_id'],
        '2026-08-03',
        '09:50:00',
        '17:00:00',
        'present',
        null,
        $ids['admin_id'],
    );
    $analytics = analytics_auto_absent_service($pdo);
    $timezone = new DateTimeZone('Asia/Yangon');

    $pending = analytics_auto_absent_stats($analytics->generate(
        ['month' => '2026-08', 'employee_id' => $ids['employee_id']],
        new DateTimeImmutable('2026-08-04 10:00:00', $timezone),
    ), $ids['employee_id']);
    assert_same(0, $pending['absent_days']);
    assert_same(1, $pending['late_days']);
    assert_same(100.0, $pending['attendance_percentage']);
    assert_true($pending['has_100_percent_attendance']);

    $matured = analytics_auto_absent_stats($analytics->generate(
        ['month' => '2026-08', 'employee_id' => $ids['employee_id']],
        new DateTimeImmutable('2026-08-04 10:00:01', $timezone),
    ), $ids['employee_id']);
    assert_same(1, $matured['absent_days']);
    assert_same(50.0, $matured['attendance_percentage']);
    assert_false($matured['has_100_percent_attendance']);

    $historical = analytics_auto_absent_stats($analytics->generate(
        ['month' => '2026-08', 'employee_id' => $ids['employee_id']],
        new DateTimeImmutable('2026-08-05 08:00:00', $timezone),
    ), $ids['employee_id']);
    assert_same(1, $historical['absent_days']);
    assert_false($historical['has_100_percent_attendance']);
});

function analytics_auto_absent_service(PDO $pdo): AnalyticsService
{
    $policy = new AttendancePolicyService();

    return new AnalyticsService(
        new Employee($pdo),
        new Attendance($pdo, $policy),
        new Holiday($pdo),
        new LeaveRequest($pdo),
        $policy,
        new WorkScheduleResolverService($pdo),
    );
}

function analytics_auto_absent_operations(PDO $pdo): AttendanceOperationService
{
    return new AttendanceOperationService(
        $pdo,
        new AttendancePolicyService(),
        null,
        new WorkScheduleResolverService($pdo),
        new AttendanceCalculationService(),
    );
}

/** @return array<string, mixed> */
function analytics_auto_absent_stats(array $result, int $employeeId): array
{
    foreach ($result['employee_analytics'] as $stats) {
        if ((int) $stats['id'] === $employeeId) {
            return $stats;
        }
    }

    throw new RuntimeException('Expected analytics employee was not found.');
}

/** @param list<int> $workingDays */
function analytics_auto_absent_assign_schedule(PDO $pdo, int $employeeId, string $startTime, string $endTime, array $workingDays): int
{
    $pdo->prepare(
        "INSERT INTO work_schedule_templates (name, description, timezone, effective_from, is_active, lifecycle_status)
         VALUES (:name, 'Deterministic analytics auto-absent fixture', 'Asia/Yangon', '2026-01-01', 1, 'active')"
    )->execute([':name' => 'Analytics Auto-Absent ' . $employeeId . ' ' . $startTime]);
    $scheduleId = (int) $pdo->lastInsertId();
    $insertDay = $pdo->prepare(
        'INSERT INTO work_schedule_days
         (work_schedule_template_id, day_of_week, is_working_day, start_time, end_time, is_overnight, break_minutes, grace_minutes, expected_minutes)
         VALUES (:schedule_id, :day_of_week, :is_working_day, :start_time, :end_time, 0, 0, :grace_minutes, :expected_minutes)'
    );
    for ($day = 1; $day <= 7; $day++) {
        $isWorking = in_array($day, $workingDays, true);
        $insertDay->execute([
            ':schedule_id' => $scheduleId,
            ':day_of_week' => $day,
            ':is_working_day' => $isWorking ? 1 : 0,
            ':start_time' => $isWorking ? $startTime : null,
            ':end_time' => $isWorking ? $endTime : null,
            ':grace_minutes' => $isWorking ? 30 : 0,
            ':expected_minutes' => $isWorking ? 480 : 0,
        ]);
    }
    $pdo->prepare('INSERT INTO attendance_policy_settings (work_schedule_template_id) VALUES (:schedule_id)')
        ->execute([':schedule_id' => $scheduleId]);
    $pdo->prepare('INSERT INTO overtime_policy_settings (work_schedule_template_id) VALUES (:schedule_id)')
        ->execute([':schedule_id' => $scheduleId]);
    $pdo->prepare(
        "INSERT INTO work_schedule_assignments
         (work_schedule_template_id, scope_type, scope_id, effective_from, is_active)
         VALUES (:schedule_id, 'employee', :employee_id, '2026-01-01', 1)"
    )->execute([
        ':schedule_id' => $scheduleId,
        ':employee_id' => $employeeId,
    ]);

    return $scheduleId;
}
