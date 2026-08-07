<?php

declare(strict_types=1);

use App\Services\AttendanceCalculationService;
use App\Services\AttendanceOperationException;
use App\Services\AttendanceOperationService;
use App\Services\AttendancePolicyService;
use App\Services\AttendanceReportContextService;
use App\Services\AttendanceSummaryReportService;
use App\Services\AuditLogService;
use App\Services\AnalyticsService;
use App\Services\OvertimeReviewService;
use App\Services\ReportExcelExportService;
use App\Services\WorkScheduleAssignmentService;
use App\Services\WorkScheduleResolverService;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\Notification;

test('workforce policy resolver uses company schedule by default and assignment priority when present', function (): void {
    $database = TestDatabase::connect();
    $ids = $database->resetAndSeed();
    $pdo = $database->pdo();
    $resolver = new WorkScheduleResolverService($pdo);

    $sundayDefault = $resolver->resolve($ids['employee_id'], '2026-07-26');
    assert_same('non_working_day', $sundayDefault['day_type']);
    assert_same('weekend', $sundayDefault['legacy_status']);

    $customScheduleId = workforce_policy_test_schedule($pdo, 'Sunday Department Policy', [7, 1, 2, 3, 4]);
    $statement = $pdo->prepare(
        "INSERT INTO work_schedule_assignments (work_schedule_template_id, scope_type, scope_id, effective_from, is_active, created_by)
         VALUES (:template_id, 'department', :department_id, '2026-01-01', 1, :admin_id)"
    );
    $statement->execute([
        ':template_id' => $customScheduleId,
        ':department_id' => $ids['department_id'],
        ':admin_id' => $ids['admin_id'],
    ]);

    $resolved = $resolver->resolve($ids['employee_id'], '2026-07-26');
    assert_same($customScheduleId, (int) $resolved['policy_version_id']);
    assert_same('department', $resolved['assignment_scope']);
    assert_same('working_day', $resolved['day_type']);

    $batch = $resolver->resolveMany([
        ['employee_id' => $ids['employee_id'], 'business_date' => '2026-07-26'],
        ['employee_id' => $ids['other_employee_id'], 'business_date' => '2026-07-26'],
    ]);
    assert_same($customScheduleId, (int) $batch[$ids['employee_id'] . '|2026-07-26']['policy_version_id']);
    assert_same($customScheduleId, (int) $batch[$ids['other_employee_id'] . '|2026-07-26']['policy_version_id']);
});

test('admin attendance writes use centralized workforce policy calculations', function (): void {
    $database = TestDatabase::connect();
    $ids = $database->resetAndSeed();
    $pdo = $database->pdo();
    $operations = workforce_policy_attendance_operations($pdo);

    $operations->createRecord($ids['employee_id'], '2026-07-29', '09:00:00', '17:00:00', 'absent', null, $ids['admin_id']);
    $weekday = workforce_policy_attendance_row($pdo, $ids['employee_id'], '2026-07-29');
    assert_same('present', $weekday['status']);
    assert_same('present', $weekday['attendance_state']);
    assert_same(0, (int) $weekday['late_minutes']);
    assert_same(480, (int) $weekday['regular_minutes']);

    try {
        $operations->createRecord($ids['other_employee_id'], '2026-07-29', null, null, 'present', null, $ids['admin_id']);
        throw new RuntimeException('Present attendance without times should be rejected.');
    } catch (AttendanceOperationException $exception) {
        assert_same('present_requires_time', $exception->getMessage());
    }

    $operations->createRecord($ids['employee_id'], '2026-07-26', '09:00:00', '17:00:00', 'present', null, $ids['admin_id']);
    $weekend = workforce_policy_attendance_row($pdo, $ids['employee_id'], '2026-07-26');
    assert_same('weekend', $weekend['status']);
    assert_same('non_working_day', $weekend['day_type']);
    assert_same(0, (int) $weekend['late_minutes']);
    assert_same(480, (int) $weekend['non_working_day_overtime_minutes']);

    $pdo->exec("INSERT INTO holidays (holiday_name, holiday_date) VALUES ('Policy Test Holiday', '2026-07-30')");
    $operations->createRecord($ids['employee_id'], '2026-07-30', '09:45:00', '17:00:00', 'present', null, $ids['admin_id']);
    $holiday = workforce_policy_attendance_row($pdo, $ids['employee_id'], '2026-07-30');
    assert_same('holiday', $holiday['status']);
    assert_same('holiday', $holiday['day_type']);
    assert_same(0, (int) $holiday['late_minutes']);
    assert_same(435, (int) $holiday['holiday_overtime_minutes']);
});

test('workforce policy assignments reject overlaps and remove employee overrides with audit history', function (): void {
    $database = TestDatabase::connect();
    $ids = $database->resetAndSeed();
    $pdo = $database->pdo();
    $audit = new AuditLogService($pdo);
    $assignments = new WorkScheduleAssignmentService($pdo, $audit);
    $policyId = workforce_policy_test_schedule($pdo, 'Employee Override Policy', [1, 2, 3, 4, 5, 7]);

    $created = $assignments->create([
        'scope_type' => 'employee',
        'employee_id' => $ids['employee_id'],
        'work_schedule_template_id' => $policyId,
        'effective_from' => '2026-01-01',
        'reason' => 'Temporary employee override',
        'is_active' => '1',
    ], $ids['admin_id']);
    assert_true($created['ok']);

    $overlap = $assignments->create([
        'scope_type' => 'employee',
        'employee_id' => $ids['employee_id'],
        'work_schedule_template_id' => $policyId,
        'effective_from' => '2026-06-01',
        'reason' => 'Overlapping override',
        'is_active' => '1',
    ], $ids['admin_id']);
    assert_false($overlap['ok']);
    assert_same('An active assignment already overlaps this scope and effective period.', $overlap['errors']['form'][0]);

    $removed = $assignments->removeEmployeeOverride($ids['employee_id'], $ids['admin_id']);
    assert_same(1, $removed);

    $activeOverrides = (int) $pdo->query("SELECT COUNT(*) FROM work_schedule_assignments WHERE scope_type = 'employee' AND is_active = 1")->fetchColumn();
    assert_same(0, $activeOverrides);
    $auditCount = (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action IN ('work_schedule_assignment.created', 'work_schedule_assignment.override_removed')")->fetchColumn();
    assert_same(2, $auditCount);
});

test('overtime review decisions update approved minutes, notifications, and audit history', function (): void {
    $database = TestDatabase::connect();
    $ids = $database->resetAndSeed();
    $pdo = $database->pdo();
    $notification = new Notification($pdo);
    $audit = new AuditLogService($pdo);
    $operations = workforce_policy_attendance_operations($pdo, $notification);
    $review = new OvertimeReviewService($pdo, $audit, $notification);

    $pdo->exec('UPDATE overtime_policy_settings SET approval_required = 1, reason_required = 1, allow_admin_adjustment = 1, allow_approval_above_calculated = 0');
    $operations->createRecord($ids['employee_id'], '2026-07-29', '09:00:00', '18:30:00', 'present', null, $ids['admin_id']);
    $row = workforce_policy_attendance_row($pdo, $ids['employee_id'], '2026-07-29');
    $attendanceId = (int) $row['id'];
    assert_same(90, (int) $row['calculated_overtime_minutes']);
    assert_same(0, (int) $row['approved_overtime_minutes']);
    assert_same('pending', $row['overtime_approval_status']);

    $review->notifyPendingIfNeeded($attendanceId);
    $pendingNoticeCount = (int) $pdo->query("SELECT COUNT(*) FROM notifications WHERE type = 'overtime_pending'")->fetchColumn();
    assert_same(1, $pendingNoticeCount);

    $review->approve($attendanceId, null, null, $ids['admin_id']);
    $approved = workforce_policy_attendance_row($pdo, $ids['employee_id'], '2026-07-29');
    assert_same('approved', $approved['overtime_approval_status']);
    assert_same(90, (int) $approved['approved_overtime_minutes']);
    try {
        $review->approve($attendanceId, null, null, $ids['admin_id']);
        throw new RuntimeException('Duplicate stale approval should be rejected.');
    } catch (AttendanceOperationException $exception) {
        assert_same('stale_decision', $exception->getMessage());
    }

    $review->adjust($attendanceId, 60, 'Manager approved only one hour.', $ids['admin_id']);
    $adjusted = workforce_policy_attendance_row($pdo, $ids['employee_id'], '2026-07-29');
    assert_same('approved', $adjusted['overtime_approval_status']);
    assert_same(60, (int) $adjusted['approved_overtime_minutes']);

    $review->resetPending($attendanceId, 'Needs another review.', $ids['admin_id']);
    $pending = workforce_policy_attendance_row($pdo, $ids['employee_id'], '2026-07-29');
    assert_same('pending', $pending['overtime_approval_status']);
    assert_same(0, (int) $pending['approved_overtime_minutes']);

    $review->reject($attendanceId, 'Not eligible after review.', $ids['admin_id']);
    $rejected = workforce_policy_attendance_row($pdo, $ids['employee_id'], '2026-07-29');
    assert_same('rejected', $rejected['overtime_approval_status']);
    assert_same(0, (int) $rejected['approved_overtime_minutes']);

    assert_same(4, count($review->decisions($attendanceId)));
    $auditCount = (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action LIKE 'overtime.%'")->fetchColumn();
    assert_same(4, $auditCount);
    $employeeNoticeCount = (int) $pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id = " . (int) $ids['employee_id'] . " AND type LIKE 'overtime_%'")->fetchColumn();
    assert_same(4, $employeeNoticeCount);
});

test('overtime review lists attendance overtime rows across approval states', function (): void {
    $database = TestDatabase::connect();
    $ids = $database->resetAndSeed();
    $pdo = $database->pdo();
    $audit = new AuditLogService($pdo);
    $operations = workforce_policy_attendance_operations($pdo);
    $review = new OvertimeReviewService($pdo, $audit);

    $pdo->exec('UPDATE overtime_policy_settings SET approval_required = 0');
    $operations->createRecord($ids['employee_id'], '2026-07-24', '09:00:00', '18:00:00', 'present', null, $ids['admin_id']);

    $pdo->exec('UPDATE overtime_policy_settings SET approval_required = 1, reason_required = 1, allow_admin_adjustment = 1, allow_approval_above_calculated = 0');
    $operations->createRecord($ids['employee_id'], '2026-07-29', '09:00:00', '18:30:00', 'present', null, $ids['admin_id']);
    $operations->createRecord($ids['other_employee_id'], '2026-07-30', '09:00:00', '18:30:00', 'present', null, $ids['admin_id']);
    $operations->createRecord($ids['employee_id'], '2026-07-31', '09:00:00', '18:30:00', 'present', null, $ids['admin_id']);
    $operations->createRecord($ids['other_employee_id'], '2026-07-28', '09:00:00', '17:00:00', 'present', null, $ids['admin_id']);

    $approved = workforce_policy_attendance_row($pdo, $ids['other_employee_id'], '2026-07-30');
    $rejected = workforce_policy_attendance_row($pdo, $ids['employee_id'], '2026-07-31');
    $review->approve((int) $approved['id'], null, null, $ids['admin_id']);
    $review->reject((int) $rejected['id'], 'Not eligible after review.', $ids['admin_id']);

    $all = $review->list(['from' => '2026-07-01', 'to' => '2026-07-31', 'approval_status' => ''], 1, 20);
    assert_same(4, $all['total']);
    assert_same(4, count($all['records']));
    $statuses = array_values(array_unique(array_map(
        static fn (array $row): string => (string) $row['overtime_approval_status'],
        $all['records']
    )));
    sort($statuses);
    assert_same(['approved', 'not_required', 'pending', 'rejected'], $statuses);

    $notRequired = $review->list(['approval_status' => 'not_required'], 1, 20);
    assert_same(1, $notRequired['total']);
    assert_same('not_required', $notRequired['records'][0]['overtime_approval_status']);

    $exactDate = $review->list(['from' => '2026-07-31', 'to' => '2026-07-31'], 1, 20);
    assert_same(1, $exactDate['total']);
    assert_same('2026-07-31', $exactDate['records'][0]['attendance_date']);

    $highPage = $review->list([], 99, 20);
    assert_same(4, $highPage['total']);
    assert_same(1, $highPage['page']);
    assert_same(4, count($highPage['records']));

    set_error_handler(static function (int $severity, string $message): never {
        throw new RuntimeException($message, $severity);
    });
    try {
        $unsafeQuery = $review->list([
            'from' => ['2026-07-01'],
            'to' => ['2026-07-31'],
            'approval_status' => ['pending'],
            'employee_id' => ['1'],
        ], 1, 20);
    } finally {
        restore_error_handler();
    }
    assert_same(4, $unsafeQuery['total']);
});

test('workforce policy resolver batches dense employee date ranges with bounded queries', function (): void {
    try {
        $database = TestDatabase::connectWithQueryCounter();
    } catch (Throwable $exception) {
        skip_test('MySQL test database is unavailable: ' . $exception->getMessage());
    }
    $ids = $database->resetAndSeed();
    $pdo = $database->pdo();
    assert_true($pdo instanceof QueryCountingPDO, 'Expected query-counting PDO for resolver performance test.');

    $departments = [$ids['department_id']];
    for ($i = 2; $i <= 5; $i++) {
        $pdo->prepare('INSERT INTO departments (name, description) VALUES (:name, :description)')->execute([
            ':name' => 'Batch Department ' . $i,
            ':description' => 'Resolver batch fixture',
        ]);
        $departments[] = (int) $pdo->lastInsertId();
    }

    $employeeIds = [$ids['employee_id'], $ids['other_employee_id']];
    for ($i = 3; $i <= 50; $i++) {
        $employeeIds[] = workforce_policy_insert_employee(
            $pdo,
            'Batch Employee ' . $i,
            'batch.employee.' . $i . '@sapas.test',
            $departments[$i % count($departments)],
            $ids['position_id'],
            'BATCH-' . str_pad((string) $i, 4, '0', STR_PAD_LEFT),
        );
    }

    $departmentScheduleId = workforce_policy_test_schedule($pdo, 'Batch Department Saturday Policy', [1, 2, 3, 4, 6]);
    $employeeScheduleId = workforce_policy_test_schedule($pdo, 'Batch Employee Sunday Policy', [7, 1, 2, 3, 4]);
    $pdo->prepare(
        "INSERT INTO work_schedule_assignments (work_schedule_template_id, scope_type, scope_id, effective_from, is_active, created_by)
         VALUES (:schedule_id, 'department', :department_id, '2026-01-01', 1, :admin_id)"
    )->execute([
        ':schedule_id' => $departmentScheduleId,
        ':department_id' => $departments[2],
        ':admin_id' => $ids['admin_id'],
    ]);
    $pdo->prepare(
        "INSERT INTO work_schedule_assignments (work_schedule_template_id, scope_type, scope_id, effective_from, is_active, created_by)
         VALUES (:schedule_id, 'employee', :employee_id, '2026-01-01', 1, :admin_id)"
    )->execute([
        ':schedule_id' => $employeeScheduleId,
        ':employee_id' => $ids['employee_id'],
        ':admin_id' => $ids['admin_id'],
    ]);
    $pdo->exec("INSERT INTO holidays (holiday_name, holiday_date) VALUES ('Batch Holiday', '2026-07-06')");

    $requests = [];
    for ($day = new DateTimeImmutable('2026-07-01'), $end = new DateTimeImmutable('2026-07-10'); $day <= $end; $day = $day->modify('+1 day')) {
        foreach ($employeeIds as $employeeId) {
            $requests[] = ['employee_id' => $employeeId, 'business_date' => $day->format('Y-m-d')];
        }
    }

    $resolver = new WorkScheduleResolverService($pdo);
    $pdo->resetQueryCount();
    foreach ($requests as $request) {
        $resolver->resolve($request['employee_id'], $request['business_date']);
    }
    $singleCount = $pdo->queryCount();

    $pdo->resetQueryCount();
    $batch = $resolver->resolveMany($requests);
    $batchCount = $pdo->queryCount();

    assert_same(count($requests), count($batch));
    assert_true($singleCount > 1000, 'Single-row resolution should exercise the old N+1 query shape.');
    assert_true($batchCount <= 12, 'Batch resolution should stay bounded. Count: ' . $batchCount);
    assert_true($batchCount * 10 < $singleCount, 'Batch resolution should materially reduce query count.');
    assert_same('holiday', $batch[$ids['employee_id'] . '|2026-07-06']['day_type']);
    assert_same($employeeScheduleId, (int) $batch[$ids['employee_id'] . '|2026-07-05']['policy_version_id']);
});

test('analytics working days use resolved schedules for selected employees', function (): void {
    $database = TestDatabase::connect();
    $ids = $database->resetAndSeed();
    $pdo = $database->pdo();
    $customScheduleId = workforce_policy_test_schedule($pdo, 'Analytics Tuesday Saturday Policy', [2, 3, 4, 5, 6]);
    $pdo->prepare(
        "INSERT INTO work_schedule_assignments (work_schedule_template_id, scope_type, scope_id, effective_from, is_active, created_by)
         VALUES (:schedule_id, 'employee', :employee_id, '2026-01-01', 1, :admin_id)"
    )->execute([
        ':schedule_id' => $customScheduleId,
        ':employee_id' => $ids['employee_id'],
        ':admin_id' => $ids['admin_id'],
    ]);

    $analytics = new AnalyticsService(
        new Employee($pdo),
        new Attendance($pdo),
        new Holiday($pdo),
        new LeaveRequest($pdo),
        new AttendancePolicyService(),
        new WorkScheduleResolverService($pdo),
    );
    $result = $analytics->generate([
        'month' => '2026-07',
        'department_id' => null,
        'employee_id' => $ids['employee_id'],
    ]);
    $workingDateMap = array_fill_keys(array_column($result['working_days'], 'date'), true);

    assert_true(isset($workingDateMap['2026-07-25']), 'Selected employee schedule should include Saturday.');
    assert_false(isset($workingDateMap['2026-07-27']), 'Selected employee schedule should exclude Monday.');
});

test('attendance report screen export and excel data share enriched V3 values', function (): void {
    $database = TestDatabase::connect();
    $ids = $database->resetAndSeed();
    $pdo = $database->pdo();
    $operations = workforce_policy_attendance_operations($pdo);
    $policy = new AttendancePolicyService();
    $attendance = new Attendance($pdo, $policy);
    $context = new AttendanceReportContextService(
        new Holiday($pdo),
        new LeaveRequest($pdo),
        $policy,
        new WorkScheduleResolverService($pdo),
        new AttendanceCalculationService(),
    );
    $reports = new AttendanceSummaryReportService($attendance, $context, $policy);

    $operations->createRecord($ids['employee_id'], '2026-07-24', '09:35:00', '18:00:00', 'present', null, $ids['admin_id']);
    $operations->createRecord($ids['employee_id'], '2026-07-25', '09:00:00', '12:00:00', 'present', null, $ids['admin_id']);

    $filters = ['date_from' => '2026-07-24', 'date_to' => '2026-07-25', 'page' => 1, 'errors' => []];
    $screen = $reports->generate($filters, 1, false);
    $export = $reports->generate($filters, 1, true);

    assert_same($export['all_records'][0]['status'], $screen['records'][0]['status']);
    assert_same($export['all_records'][0]['attendance_display_status'], $screen['records'][0]['attendance_display_status']);
    assert_same($export['all_records'][0]['actual_work_minutes'], $screen['records'][0]['actual_work_minutes']);
    assert_same($export['totals'], $screen['totals']);
    assert_same('weekend', $export['all_records'][0]['status']);
    assert_same(0, (int) $export['all_records'][0]['late_minutes']);

    $xlsx = (new ReportExcelExportService())->build([
        'project_name' => 'SAPAS',
        'system_label' => 'System',
        'generated_at_label' => 'Generated at',
        'active_filters_label' => 'Active Filters',
        'filter_summary' => ['2026-07-24 - 2026-07-25'],
        'sheets' => [[
            'name' => 'Attendance',
            'title' => 'Attendance',
            'columns' => [
                ['header' => 'Status', 'width' => 16],
                ['header' => 'Late', 'width' => 12],
                ['header' => 'Work', 'width' => 16],
            ],
            'rows' => array_map(static fn (array $row): array => [
                (string) $row['attendance_display_status'],
                ['value' => (int) $row['late_minutes'], 'type' => 'integer'],
                ['value' => (int) $row['actual_work_minutes'], 'type' => 'duration'],
            ], $export['records']),
        ]],
    ]);
    assert_true(str_starts_with($xlsx, 'PK'), 'Excel export should build a valid OOXML ZIP container.');
});

function workforce_policy_attendance_operations(PDO $pdo, ?Notification $notifications = null): AttendanceOperationService
{
    return new AttendanceOperationService(
        $pdo,
        new AttendancePolicyService(),
        null,
        new WorkScheduleResolverService($pdo),
        new AttendanceCalculationService(),
        $notifications
    );
}

function workforce_policy_attendance_row(PDO $pdo, int $userId, string $date): array
{
    $statement = $pdo->prepare('SELECT * FROM attendance_records WHERE user_id = :user_id AND attendance_date = :date LIMIT 1');
    $statement->execute([':user_id' => $userId, ':date' => $date]);
    $row = $statement->fetch();
    if ($row === false) {
        throw new RuntimeException('Expected attendance row was not found.');
    }

    return $row;
}

function workforce_policy_test_schedule(PDO $pdo, string $name, array $workingDays): int
{
    $statement = $pdo->prepare(
        "INSERT INTO work_schedule_templates (name, description, timezone, effective_from, is_active, lifecycle_status)
         VALUES (:name, 'Test workforce policy schedule', NULL, '2026-01-01', 1, 'active')"
    );
    $statement->execute([':name' => $name]);
    $scheduleId = (int) $pdo->lastInsertId();

    $insertDay = $pdo->prepare(
        'INSERT INTO work_schedule_days (work_schedule_template_id, day_of_week, is_working_day, start_time, end_time, is_overnight, break_minutes, grace_minutes, expected_minutes)
         VALUES (:schedule_id, :day_of_week, :is_working_day, :start_time, :end_time, 0, 0, :grace_minutes, :expected_minutes)'
    );
    for ($day = 1; $day <= 7; $day++) {
        $isWorking = in_array($day, $workingDays, true);
        $insertDay->execute([
            ':schedule_id' => $scheduleId,
            ':day_of_week' => $day,
            ':is_working_day' => $isWorking ? 1 : 0,
            ':start_time' => $isWorking ? '09:00:00' : null,
            ':end_time' => $isWorking ? '17:00:00' : null,
            ':grace_minutes' => $isWorking ? 30 : 0,
            ':expected_minutes' => $isWorking ? 480 : 0,
        ]);
    }

    $pdo->prepare('INSERT INTO attendance_policy_settings (work_schedule_template_id) VALUES (:schedule_id)')->execute([':schedule_id' => $scheduleId]);
    $pdo->prepare('INSERT INTO overtime_policy_settings (work_schedule_template_id) VALUES (:schedule_id)')->execute([':schedule_id' => $scheduleId]);

    return $scheduleId;
}

function workforce_policy_insert_employee(PDO $pdo, string $name, string $email, int $departmentId, int $positionId, string $staffCode): int
{
    $statement = $pdo->prepare(
        "INSERT INTO users (name, email, password, role, department_id, position_id, status, staff_code, email_verified_at)
         VALUES (:name, :email, :password, 'employee', :department_id, :position_id, 'active', :staff_code, NOW())"
    );
    $statement->execute([
        ':name' => $name,
        ':email' => $email,
        ':password' => password_hash('Password123!', PASSWORD_DEFAULT),
        ':department_id' => $departmentId,
        ':position_id' => $positionId,
        ':staff_code' => $staffCode,
    ]);

    return (int) $pdo->lastInsertId();
}
