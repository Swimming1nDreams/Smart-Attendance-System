<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeProfile;
use App\Core\Localization;
use App\Services\AccountManagementService;
use App\Services\AuditLogService;
use App\Services\DateValidationService;
use App\Services\DemoAccountRefreshService;
use App\Services\PasswordPolicyService;
use App\Services\ReportFilterService;

test('phase 3 audit redacts secrets while preserving safe security metadata', function (): void {
    $database = TestDatabase::connect();
    $ids = $database->resetAndSeed();
    $service = new AuditLogService($database->pdo());
    $service->record($ids['admin_id'], 'security.test', 'user', $ids['employee_id'], 'Redaction test', null, [
        'password' => 'PlaintextMustNotPersist',
        'password_hash' => 'HashMustNotPersist',
        'verification_code' => '123456',
        'session_id' => 'session-secret',
        'password_changed' => true,
        'session_version_incremented' => true,
    ], ['ip_address' => '127.0.0.1', 'user_agent' => str_repeat('A', 300)]);

    $row = $database->pdo()->query("SELECT new_values, ip_address, user_agent FROM audit_logs WHERE action = 'security.test'")->fetch();
    $values = json_decode((string) $row['new_values'], true, 512, JSON_THROW_ON_ERROR);
    assert_same('[REDACTED]', $values['password']);
    assert_same('[REDACTED]', $values['password_hash']);
    assert_same('[REDACTED]', $values['verification_code']);
    assert_same('[REDACTED]', $values['session_id']);
    assert_same(true, $values['password_changed']);
    assert_same(true, $values['session_version_incremented']);
    assert_same('127.0.0.1', $row['ip_address']);
    assert_same(255, strlen((string) $row['user_agent']));
});

test('phase 3 employee creation assigns the locked next staff code and audits the transaction', function (): void {
    $database = TestDatabase::connect();
    $ids = $database->resetAndSeed();
    $pdo = $database->pdo();
    $service = phase3_account_service($pdo, new AuditLogService($pdo));
    $account = phase3_new_account($ids);
    $userId = $service->createEmployee($account, ['phone' => '09 700 999 001', 'address' => 'Fictional Test Address, Yangon'],
        'Phase3!StrongPass9', 'Phase3!StrongPass9', $ids['admin_id']);

    $created = $pdo->query('SELECT staff_code FROM users WHERE id = ' . $userId)->fetch();
    assert_same('SAP-0005', $created['staff_code']);
    assert_same(1, (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE entity_id = '{$userId}' AND action = 'employee.created'")->fetchColumn());
    assert_same(1, (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE entity_id = '{$userId}' AND action = 'employee.staff_code_assigned'")->fetchColumn());
    assert_same(0, (int) $pdo->query("SELECT COUNT(*) - COUNT(DISTINCT staff_code) FROM users")->fetchColumn());
});

test('phase 3 critical audit failure rolls back employee creation', function (): void {
    $database = TestDatabase::connect();
    $ids = $database->resetAndSeed();
    $pdo = $database->pdo();
    $before = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $pdo->exec('DROP TABLE audit_logs');
    $thrown = false;
    try {
        phase3_account_service($pdo, new AuditLogService($pdo))->createEmployee(
            phase3_new_account($ids), ['phone' => null, 'address' => null],
            'Phase3!Rollback9', 'Phase3!Rollback9', $ids['admin_id']
        );
    } catch (Throwable) {
        $thrown = true;
    }
    assert_true($thrown);
    assert_same($before, (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
});

test('phase 3 audit records are filterable and paginated through a read-only model', function (): void {
    $database = TestDatabase::connect();
    $ids = $database->resetAndSeed();
    $service = new AuditLogService($database->pdo());
    for ($index = 1; $index <= 25; $index++) {
        $service->record($ids['admin_id'], 'employee.updated', 'user', $ids['employee_id'], 'Change ' . $index);
    }
    $model = new AuditLog($database->pdo());
    $filters = ['action' => 'employee.updated', 'entity_type' => 'user', 'entity_id' => (string) $ids['employee_id']];
    assert_same(25, $model->countAll($filters));
    assert_same(20, count($model->findAll($filters, 1, 20)));
    assert_same(5, count($model->findAll($filters, 2, 20)));
    $routes = file_get_contents(project_path('public/index.php'));
    assert_not_contains("addRoute('POST', '/admin/audit-logs", (string) $routes);
});

test('phase 3 audit filters reject invalid input instead of broadening the query', function (): void {
    $database = TestDatabase::connect();
    $database->resetAndSeed();
    Localization::setCurrent(new Localization(project_path('lang')));
    $filters = (new ReportFilterService(new DateValidationService(), new Employee($database->pdo()), new Department($database->pdo())))
        ->auditLogs([
            'date_from' => '2026-99-99', 'date_to' => 'not-a-date', 'page' => 'zero',
            'actor_user_id' => '-7', 'action' => 'not.allowed', 'entity_type' => 'not.allowed',
        ], ['employee.updated'], ['user']);
    assert_true(count($filters['errors']) >= 6);
    assert_same(null, $filters['date_from']);
    assert_same(null, $filters['date_to']);
    assert_same(null, $filters['actor_user_id']);
    assert_same(null, $filters['action']);
    assert_same(null, $filters['entity_type']);
    assert_same(1, $filters['page']);
});

test('phase 3 demo refresh dry run is non-mutating and apply preserves IDs and history', function (): void {
    $database = TestDatabase::connect();
    $database->resetAndSeed();
    $pdo = $database->pdo();
    $service = new DemoAccountRefreshService($pdo, new PasswordPolicyService(), new AuditLogService($pdo), 'silveroakhr.com');
    $beforeUsers = phase3_table_signature($pdo, 'users');
    $beforeHistory = [phase3_table_signature($pdo, 'attendance_records'), phase3_table_signature($pdo, 'leave_requests'), phase3_table_signature($pdo, 'notifications')];
    $preview = $service->preview();
    assert_same($beforeUsers, phase3_table_signature($pdo, 'users'));
    assert_same(count($preview), count(array_unique(array_column($preview, 'email'))));
    assert_same(count($preview), count(array_unique(array_column($preview, 'phone'))));
    assert_not_contains('.local', implode(' ', array_column($preview, 'email')));

    $privateDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sapas_phase3_private_test';
    $result = $service->apply($privateDirectory);
    assert_same($beforeUsers['ids'], phase3_table_signature($pdo, 'users')['ids']);
    assert_same($beforeHistory, [phase3_table_signature($pdo, 'attendance_records'), phase3_table_signature($pdo, 'leave_requests'), phase3_table_signature($pdo, 'notifications')]);
    $passwords = array_column($result['accounts'], 'temporary_password');
    assert_same(count($passwords), count(array_unique($passwords)));
    foreach ($result['accounts'] as $account) {
        assert_true(strlen((string) $account['temporary_password']) >= 16);
        $hash = $pdo->query('SELECT password FROM users WHERE id = ' . (int) $account['id'])->fetchColumn();
        assert_true(password_verify((string) $account['temporary_password'], (string) $hash));
    }
    assert_true(is_file($result['credential_path']));
    @unlink($result['credential_path']);
});

test('phase 3 injected demo refresh failure rolls back every account change', function (): void {
    $database = TestDatabase::connect();
    $database->resetAndSeed();
    $pdo = $database->pdo();
    $before = $pdo->query('SELECT id, email, password, session_version FROM users ORDER BY id')->fetchAll();
    putenv('SAPAS_DEMO_REFRESH_FAIL_AFTER=1');
    $thrown = false;
    try {
        (new DemoAccountRefreshService($pdo, new PasswordPolicyService(), new AuditLogService($pdo), 'silveroakhr.com'))
            ->apply(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sapas_phase3_failure_test');
    } catch (Throwable) {
        $thrown = true;
    } finally {
        putenv('SAPAS_DEMO_REFRESH_FAIL_AFTER');
    }
    assert_true($thrown);
    assert_same($before, $pdo->query('SELECT id, email, password, session_version FROM users ORDER BY id')->fetchAll());
});

function phase3_account_service(PDO $pdo, AuditLogService $audit): AccountManagementService
{
    return new AccountManagementService($pdo, new Employee($pdo), new EmployeeProfile($pdo), new PasswordPolicyService(), $audit);
}

function phase3_new_account(array $ids): array
{
    return [
        'name' => 'Phase Three Employee', 'email' => 'phase.three@silveroakhr.com', 'role' => 'employee',
        'department_id' => $ids['department_id'], 'position_id' => $ids['position_id'], 'status' => 'active',
        'employment_type' => 'full-time', 'join_date' => '2026-07-22', 'expected_daily_work_hours' => 8.0,
        'overtime_eligible' => true,
    ];
}

function phase3_table_signature(PDO $pdo, string $table): array
{
    return [
        'count' => (int) $pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn(),
        'ids' => (string) $pdo->query('SELECT COALESCE(GROUP_CONCAT(id ORDER BY id), "") FROM ' . $table)->fetchColumn(),
    ];
}
