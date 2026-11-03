<?php

declare(strict_types=1);

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Services\AnalyticsService;
use App\Services\AttendanceCalculationService;
use App\Services\AttendanceOperationException;
use App\Services\AttendanceOperationService;
use App\Services\AttendancePolicyService;
use App\Services\WorkScheduleResolverService;

test('employee early checkout stores authoritative reason and keeps attendance present', function (): void {
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $pdo = $database->pdo();
    early_departure_add_profile($pdo, $ids['employee_id']);
    early_departure_set_company_rules($pdo, 1, 10);
    $operations = early_departure_operations($pdo);

    $operations->clockIn($ids['employee_id'], '2026-08-03', '09:45:00', $ids['employee_id']);
    assert_same('early_departure_reason_required', early_departure_reason(static fn () => $operations->clockOut(
        $ids['employee_id'], '2026-08-03', '16:00:00', $ids['employee_id']
    )));

    $record = (new Attendance($pdo))->findByUserIdAndDate($ids['employee_id'], '2026-08-03');
    assert_not_null($record);
    assert_same(null, $record['clock_out_time']);

    $result = $operations->clockOut(
        $ids['employee_id'], '2026-08-03', '16:00:00', $ids['employee_id'], [], 'medical', 'Clinic appointment.'
    );
    assert_same('present', $result['attendance_state']);
    assert_same(45, $result['late_minutes']);
    assert_same(60, $result['early_departure_minutes']);
    assert_same(375, $result['actual_work_minutes']);

    $record = (new Attendance($pdo))->findByUserIdAndDate($ids['employee_id'], '2026-08-03');
    assert_same('present', $record['attendance_state']);
    assert_same(1, (int) $record['is_late']);
    assert_same(1, (int) $record['is_early_departure']);
    assert_same(60, (int) $record['early_departure_minutes']);
    assert_same('medical', $record['early_departure_reason']);
    assert_same('Clinic appointment.', $record['early_departure_note']);
    assert_same('6.25', (string) $record['working_hours']);
});

test('a later sub-minute checkout remains present and still requires early-departure context', function (): void {
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $pdo = $database->pdo();
    early_departure_add_profile($pdo, $ids['employee_id']);
    early_departure_set_company_rules($pdo, 1, 0);
    $operations = early_departure_operations($pdo);

    $operations->clockIn($ids['employee_id'], '2026-08-12', '09:00:00', $ids['employee_id']);
    assert_same('early_departure_reason_required', early_departure_reason(static fn () => $operations->clockOut(
        $ids['employee_id'], '2026-08-12', '09:00:01', $ids['employee_id']
    )));

    $result = $operations->clockOut(
        $ids['employee_id'],
        '2026-08-12',
        '09:00:01',
        $ids['employee_id'],
        [],
        'supervisor_approved',
    );
    assert_same('present', $result['attendance_state']);
    assert_same(0, $result['actual_work_minutes']);
    assert_same(1, $result['is_early_departure']);

    $record = (new Attendance($pdo))->findByUserIdAndDate($ids['employee_id'], '2026-08-12');
    assert_not_null($record);
    assert_same('09:00:00', $record['clock_in_time']);
    assert_same('09:00:01', $record['clock_out_time']);
    assert_same('2026-08-12 09:00:00', $record['check_in_at']);
    assert_same('2026-08-12 09:00:01', $record['check_out_at']);
    assert_same('present', $record['attendance_state']);
    assert_same('supervisor_approved', $record['early_departure_reason']);
});

test('early checkout rejects tampered reasons and enforces Other note while disabled tracking never blocks checkout', function (): void {
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $pdo = $database->pdo();
    early_departure_add_profile($pdo, $ids['employee_id']);
    early_departure_set_company_rules($pdo, 1, 0);
    $operations = early_departure_operations($pdo);
    $operations->clockIn($ids['employee_id'], '2026-08-03', '09:00:00', $ids['employee_id']);

    assert_same('early_departure_reason_invalid', early_departure_reason(static fn () => $operations->clockOut(
        $ids['employee_id'], '2026-08-03', '15:30:00', $ids['employee_id'], [], 'invented_reason'
    )));
    assert_same('early_departure_note_required', early_departure_reason(static fn () => $operations->clockOut(
        $ids['employee_id'], '2026-08-03', '15:30:00', $ids['employee_id'], [], 'other_authorized'
    )));
    $operations->clockOut($ids['employee_id'], '2026-08-03', '15:30:00', $ids['employee_id'], [], 'other_authorized', 'Approved personal matter.');

    $ids = $database->resetAndSeed();
    $pdo = $database->pdo();
    early_departure_add_profile($pdo, $ids['employee_id']);
    early_departure_set_company_rules($pdo, 0, 0);
    $operations = early_departure_operations($pdo);
    $operations->clockIn($ids['employee_id'], '2026-08-03', '09:00:00', $ids['employee_id']);
    $operations->clockOut($ids['employee_id'], '2026-08-03', '15:30:00', $ids['employee_id']);
    $record = (new Attendance($pdo))->findByUserIdAndDate($ids['employee_id'], '2026-08-03');
    assert_same('present', $record['attendance_state']);
    assert_same(0, (int) $record['is_early_departure']);
    assert_same(0, (int) $record['early_departure_minutes']);
    assert_same(null, $record['early_departure_reason']);
});

test('early departure follows employee and department schedule precedence and remains separate in analytics', function (): void {
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $pdo = $database->pdo();
    early_departure_add_profile($pdo, $ids['employee_id']);
    $departmentPolicy = early_departure_policy($pdo, 'Department 16:00', '16:00:00');
    $employeePolicy = early_departure_policy($pdo, 'Employee 18:00', '18:00:00');
    $pdo->prepare("INSERT INTO work_schedule_assignments (work_schedule_template_id, scope_type, scope_id, effective_from, is_active) VALUES (:policy, 'department', :scope, '2026-01-01', 1)")
        ->execute([':policy' => $departmentPolicy, ':scope' => $ids['department_id']]);
    $pdo->prepare("INSERT INTO work_schedule_assignments (work_schedule_template_id, scope_type, scope_id, effective_from, is_active) VALUES (:policy, 'employee', :scope, '2026-01-01', 1)")
        ->execute([':policy' => $employeePolicy, ':scope' => $ids['employee_id']]);

    $resolver = new WorkScheduleResolverService($pdo);
    assert_same('2026-08-03 18:00:00', $resolver->resolve($ids['employee_id'], '2026-08-03')['scheduled_end_at']);
    assert_same('2026-08-03 16:00:00', $resolver->resolve($ids['other_employee_id'], '2026-08-03')['scheduled_end_at']);

    $operations = early_departure_operations($pdo);
    $operations->clockIn($ids['employee_id'], '2026-08-03', '09:00:00', $ids['employee_id']);
    $operations->clockOut($ids['employee_id'], '2026-08-03', '17:00:00', $ids['employee_id'], [], 'supervisor_approved');

    $policy = new AttendancePolicyService();
    $analytics = new AnalyticsService(
        new Employee($pdo),
        new Attendance($pdo, $policy),
        new Holiday($pdo),
        new LeaveRequest($pdo),
        $policy,
        $resolver,
    );
    $data = $analytics->generate(
        ['month' => '2026-08', 'department_id' => null, 'employee_id' => $ids['employee_id']],
        new DateTimeImmutable('2026-08-03 19:01:00', new DateTimeZone('Asia/Yangon')),
    );
    $stats = $data['employee_analytics'][0];
    assert_same(1, $stats['early_departure_count']);
    assert_same(60, $stats['early_departure_minutes']);
    assert_same(100.0, $stats['attendance_percentage']);
    assert_true($stats['has_100_percent_attendance']);
});

function early_departure_operations(PDO $pdo): AttendanceOperationService
{
    return new AttendanceOperationService(
        $pdo,
        new AttendancePolicyService(),
        null,
        new WorkScheduleResolverService($pdo),
        new AttendanceCalculationService(),
    );
}

function early_departure_set_company_rules(PDO $pdo, int $enabled, int $grace): void
{
    $policyId = (int) $pdo->query('SELECT default_work_schedule_id FROM company_profiles ORDER BY id ASC LIMIT 1')->fetchColumn();
    $pdo->prepare('UPDATE attendance_policy_settings SET early_departure_enabled = :enabled, early_departure_grace_minutes = :grace WHERE work_schedule_template_id = :policy')
        ->execute([':enabled' => $enabled, ':grace' => $grace, ':policy' => $policyId]);
}

function early_departure_add_profile(PDO $pdo, int $employeeId): void
{
    $pdo->prepare('INSERT INTO employee_profiles (user_id) VALUES (:user_id)')->execute([':user_id' => $employeeId]);
}

function early_departure_policy(PDO $pdo, string $name, string $endTime): int
{
    $pdo->prepare("INSERT INTO work_schedule_templates (name, timezone, effective_from, is_active, lifecycle_status) VALUES (:name, 'Asia/Yangon', '2026-01-01', 1, 'active')")
        ->execute([':name' => $name]);
    $policyId = (int) $pdo->lastInsertId();
    $day = $pdo->prepare('INSERT INTO work_schedule_days (work_schedule_template_id, day_of_week, is_working_day, start_time, end_time, is_overnight, break_minutes, grace_minutes, expected_minutes) VALUES (:policy, :day, :working, :start, :end, 0, 0, 30, 480)');
    for ($dayNumber = 1; $dayNumber <= 7; $dayNumber++) {
        $working = $dayNumber <= 5 ? 1 : 0;
        $day->execute([':policy' => $policyId, ':day' => $dayNumber, ':working' => $working, ':start' => $working ? '09:00:00' : null, ':end' => $working ? $endTime : null]);
    }
    $pdo->prepare('INSERT INTO attendance_policy_settings (work_schedule_template_id, early_departure_enabled, early_departure_grace_minutes) VALUES (:policy, 1, 0)')->execute([':policy' => $policyId]);
    $pdo->prepare('INSERT INTO overtime_policy_settings (work_schedule_template_id) VALUES (:policy)')->execute([':policy' => $policyId]);

    return $policyId;
}

function early_departure_reason(callable $callback): ?string
{
    try {
        $callback();
    } catch (AttendanceOperationException $exception) {
        return $exception->reason();
    }

    return null;
}
