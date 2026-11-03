<?php

declare(strict_types=1);

use App\Controllers\MyWorkPolicyController;
use App\Core\Localization;
use App\Core\SessionManager;
use App\Models\Employee;
use App\Models\WorkScheduleDay;
use App\Models\WorkScheduleTemplate;
use App\Services\AttendancePolicyService;
use App\Services\EmployeeWorkPolicyPresenter;
use App\Services\WorkScheduleResolverService;
use Tests\Support\FakeApplication;

test('my work policy projection preserves employee department company precedence and effective dates', function (): void {
    $database = TestDatabase::connect();
    $ids = $database->resetAndSeed();
    $pdo = $database->pdo();
    $resolver = new WorkScheduleResolverService($pdo);
    $employeeModel = new Employee($pdo);
    $templateModel = new WorkScheduleTemplate($pdo, new WorkScheduleDay($pdo));
    $presenter = new EmployeeWorkPolicyPresenter(new AttendancePolicyService());
    $translate = my_work_policy_translator();
    $businessDate = '2026-08-14';

    $departmentPolicyId = my_work_policy_test_schedule($pdo, 'Operations Department Policy');
    $employeePolicyId = my_work_policy_test_schedule($pdo, 'Direct Employee Policy');
    $expiredPolicyId = my_work_policy_test_schedule($pdo, 'Expired Employee Policy');
    $futurePolicyId = my_work_policy_test_schedule($pdo, 'Future Employee Policy');

    my_work_policy_assign($pdo, $departmentPolicyId, 'department', $ids['department_id'], '2026-01-01');
    $directAssignmentId = my_work_policy_assign($pdo, $employeePolicyId, 'employee', $ids['employee_id'], '2026-08-01');

    $direct = $resolver->resolve($ids['employee_id'], $businessDate);
    assert_same($employeePolicyId, (int) $direct['policy_version_id']);
    assert_same('employee', $direct['assignment_scope']);
    $directView = $presenter->present($direct, (array) $templateModel->findWithDays($employeePolicyId), (array) $employeeModel->findById($ids['employee_id']), $translate);
    assert_same('Assigned directly to you', $directView['source_label']);
    assert_same('Direct Employee Policy', $directView['name']);
    assert_same('9:00 AM – 5:00 PM', $directView['today']['schedule_label']);
    assert_same('12:00 PM – 1:00 PM', $directView['today']['break_label']);
    assert_same('8h', $directView['today']['expected_work_label']);
    assert_same(7, count($directView['weekly_schedule']));
    assert_same(6, count($directView['attendance_rules']));
    assert_same(7, count($directView['overtime_rules']));

    $pdo->exec('DELETE FROM work_schedule_assignments WHERE id = ' . $directAssignmentId);
    my_work_policy_assign($pdo, $expiredPolicyId, 'employee', $ids['employee_id'], '2026-08-01', '2026-08-10');
    my_work_policy_assign($pdo, $futurePolicyId, 'employee', $ids['employee_id'], '2026-08-20');

    $department = $resolver->resolve($ids['employee_id'], $businessDate);
    assert_same($departmentPolicyId, (int) $department['policy_version_id']);
    assert_same('department', $department['assignment_scope']);
    $departmentView = $presenter->present($department, (array) $templateModel->findWithDays($departmentPolicyId), (array) $employeeModel->findById($ids['employee_id']), $translate);
    assert_same('Assigned through your department', $departmentView['source_label']);
    assert_same('Engineering', $departmentView['source_context']);

    $pdo->exec("DELETE FROM work_schedule_assignments WHERE scope_type IN ('employee', 'department')");
    $company = $resolver->resolve($ids['employee_id'], $businessDate);
    assert_same('company', $company['assignment_scope']);
    $companyView = $presenter->present($company, (array) $templateModel->findWithDays((int) $company['policy_version_id']), (array) $employeeModel->findById($ids['employee_id']), $translate);
    assert_same('Company-wide policy', $companyView['source_label']);
    assert_same('OAAS Standard Office', $companyView['name']);

    $sunday = $resolver->resolve($ids['employee_id'], '2026-08-16');
    $sundayView = $presenter->present($sunday, (array) $templateModel->findWithDays((int) $sunday['policy_version_id']), (array) $employeeModel->findById($ids['employee_id']), $translate);
    assert_false($sundayView['today']['is_working_day']);
    assert_same('Non-working Day', $sundayView['today']['schedule_label']);
    assert_null($sundayView['today']['break_label']);

    $serialized = json_encode($directView, JSON_THROW_ON_ERROR);
    foreach (['assignment_id', 'policy_version_id', 'template_id', 'scope_id', 'scope_type'] as $internalField) {
        assert_not_contains($internalField, $serialized);
    }
});

test('my work policy controller renders only the authenticated employee policy and ignores query identity', function (): void {
    $database = TestDatabase::connect();
    $ids = $database->resetAndSeed();
    $pdo = $database->pdo();
    $otherPolicyId = my_work_policy_test_schedule($pdo, 'Other Employee Private Policy');
    my_work_policy_assign($pdo, $otherPolicyId, 'employee', $ids['other_employee_id'], '2020-01-01');

    $_SESSION['locale'] = 'en';
    Localization::setCurrent(new Localization(project_path('lang')));
    $session = new class($ids['employee_id']) extends SessionManager {
        public function __construct(private int $employeeId)
        {
        }

        public function requireRole(string $role): void
        {
            if ($role !== 'employee') {
                throw new RuntimeException('role denied');
            }
        }

        public function getUserId(): ?int { return $this->employeeId; }
        public function getUserRole(): ?string { return 'employee'; }
        public function getUserName(): ?string { return 'Session Employee'; }
        public function generateCsrfToken(): string { return 'policy-test-csrf'; }
        public function consumeFlash(): array { return []; }
    };

    $controller = new MyWorkPolicyController(
        new Employee($pdo),
        new WorkScheduleTemplate($pdo, new WorkScheduleDay($pdo)),
        new WorkScheduleResolverService($pdo),
        new EmployeeWorkPolicyPresenter(new AttendancePolicyService()),
        $session,
        new FakeApplication(),
    );

    $originalRequestUri = $_SERVER['REQUEST_URI'] ?? null;
    $_SERVER['REQUEST_URI'] = '/my-work-policy?employee_id=' . $ids['other_employee_id'];
    try {
        ob_start();
        $controller->index();
        $output = (string) ob_get_clean();
    } finally {
        if ($originalRequestUri === null) {
            unset($_SERVER['REQUEST_URI']);
        } else {
            $_SERVER['REQUEST_URI'] = $originalRequestUri;
        }
    }

    assert_contains('OAAS Standard Office', $output);
    assert_contains('Company-wide policy', $output);
    assert_contains('Weekly Schedule', $output);
    assert_contains('Attendance Rules', $output);
    assert_contains('Overtime Rules', $output);
    assert_not_contains('Other Employee Private Policy', $output);
    assert_not_contains('/admin/work-schedules', $output);
});

/** @return callable(string): string */
function my_work_policy_translator(): callable
{
    $_SESSION['locale'] = 'en';
    $localization = new Localization(project_path('lang'));
    Localization::setCurrent($localization);

    return static fn (string $key): string => $localization->translate($key);
}

function my_work_policy_test_schedule(PDO $pdo, string $name): int
{
    $statement = $pdo->prepare(
        "INSERT INTO work_schedule_templates (name, description, timezone, effective_from, is_active, lifecycle_status)
         VALUES (:name, 'Employee work-policy page fixture', 'Asia/Yangon', '2026-01-01', 1, 'active')"
    );
    $statement->execute([':name' => $name]);
    $templateId = (int) $pdo->lastInsertId();

    $dayStatement = $pdo->prepare(
        'INSERT INTO work_schedule_days
         (work_schedule_template_id, day_of_week, is_working_day, start_time, end_time, is_overnight, break_start_time, break_end_time, break_minutes, grace_minutes, expected_minutes)
         VALUES (:template_id, :weekday, :working, :start_time, :end_time, 0, :break_start, :break_end, :break_minutes, :grace_minutes, :expected_minutes)'
    );
    for ($weekday = 1; $weekday <= 7; $weekday++) {
        $working = $weekday <= 5;
        $dayStatement->execute([
            ':template_id' => $templateId,
            ':weekday' => $weekday,
            ':working' => $working ? 1 : 0,
            ':start_time' => $working ? '09:00:00' : null,
            ':end_time' => $working ? '17:00:00' : null,
            ':break_start' => $working ? '12:00:00' : null,
            ':break_end' => $working ? '13:00:00' : null,
            ':break_minutes' => $working ? 60 : 0,
            ':grace_minutes' => $working ? 30 : 0,
            ':expected_minutes' => $working ? 480 : 0,
        ]);
    }

    $pdo->prepare(
        'INSERT INTO attendance_policy_settings
         (work_schedule_template_id, allow_non_working_day_check_in, allow_holiday_check_in, maximum_attendance_minutes, early_departure_enabled, early_departure_grace_minutes)
         VALUES (:template_id, 0, 0, 720, 1, 15)'
    )->execute([':template_id' => $templateId]);
    $pdo->prepare(
        'INSERT INTO overtime_policy_settings
         (work_schedule_template_id, early_work_eligible, after_hours_work_eligible, non_working_day_work_eligible, holiday_work_eligible, approval_required, minimum_overtime_minutes)
         VALUES (:template_id, 1, 1, 0, 1, 1, 30)'
    )->execute([':template_id' => $templateId]);

    return $templateId;
}

function my_work_policy_assign(PDO $pdo, int $templateId, string $scope, ?int $scopeId, string $from, ?string $to = null): int
{
    $statement = $pdo->prepare(
        'INSERT INTO work_schedule_assignments
         (work_schedule_template_id, scope_type, scope_id, effective_from, effective_to, is_active)
         VALUES (:template_id, :scope_type, :scope_id, :effective_from, :effective_to, 1)'
    );
    $statement->execute([
        ':template_id' => $templateId,
        ':scope_type' => $scope,
        ':scope_id' => $scopeId,
        ':effective_from' => $from,
        ':effective_to' => $to,
    ]);

    return (int) $pdo->lastInsertId();
}
