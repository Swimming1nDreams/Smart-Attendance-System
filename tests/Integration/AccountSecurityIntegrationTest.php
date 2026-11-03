<?php

declare(strict_types=1);

use App\Models\Employee;
use App\Models\EmployeeProfile;
use App\Services\AccountManagementService;
use App\Services\AccountSecurityException;
use App\Services\AuditLogService;
use App\Services\PasswordPolicyService;

test('database integration: administrator cannot deactivate their own account', function (): void {
    [$database, $ids, , $service] = account_security_fixture();
    $before = account_security_state($database->pdo(), $ids['admin_id']);

    $exception = capture_account_security_exception(
        static fn () => $service->deactivateEmployee($ids['admin_id'], $ids['admin_id'])
    );

    assert_same(AccountSecurityException::SELF_DEACTIVATION, $exception->reason());
    assert_same($before, account_security_state($database->pdo(), $ids['admin_id']));
});

test('database integration: administrator cannot downgrade their own role', function (): void {
    [$database, $ids, $employees, $service] = account_security_fixture();
    $before = account_security_state($database->pdo(), $ids['admin_id']);
    $account = account_security_data($employees, $ids['admin_id'], ['role' => 'employee']);

    $exception = capture_account_security_exception(
        static fn () => $service->updateEmployee($ids['admin_id'], $ids['admin_id'], $account, account_security_profile())
    );

    assert_same(AccountSecurityException::SELF_ROLE_CHANGE, $exception->reason());
    assert_same($before, account_security_state($database->pdo(), $ids['admin_id']));
});

test('database integration: final active administrator cannot be deactivated', function (): void {
    [$database, $ids, , $service] = account_security_fixture();
    $before = account_security_state($database->pdo(), $ids['admin_id']);

    $exception = capture_account_security_exception(
        static fn () => $service->deactivateEmployee($ids['employee_id'], $ids['admin_id'])
    );

    assert_same(AccountSecurityException::LAST_ACTIVE_ADMIN, $exception->reason());
    assert_same($before, account_security_state($database->pdo(), $ids['admin_id']));
});

test('database integration: final active administrator cannot be downgraded', function (): void {
    [$database, $ids, $employees, $service] = account_security_fixture();
    $before = account_security_state($database->pdo(), $ids['admin_id']);
    $account = account_security_data($employees, $ids['admin_id'], ['role' => 'employee']);

    $exception = capture_account_security_exception(
        static fn () => $service->updateEmployee($ids['employee_id'], $ids['admin_id'], $account, account_security_profile())
    );

    assert_same(AccountSecurityException::LAST_ACTIVE_ADMIN, $exception->reason());
    assert_same($before, account_security_state($database->pdo(), $ids['admin_id']));
});

test('database integration: administrator can deactivate another administrator when one remains active', function (): void {
    [$database, $ids, , $service] = account_security_fixture();
    $otherAdminId = insert_account_security_admin($database->pdo());

    $service->deactivateEmployee($ids['admin_id'], $otherAdminId);
    $state = account_security_state($database->pdo(), $otherAdminId);

    assert_same('inactive', $state['status']);
    assert_same(2, $state['session_version']);
    assert_same(1, active_administrator_count($database->pdo()));
});

test('database integration: administrator can downgrade another administrator when one remains active', function (): void {
    [$database, $ids, $employees, $service] = account_security_fixture();
    $otherAdminId = insert_account_security_admin($database->pdo());
    $account = account_security_data($employees, $otherAdminId, ['role' => 'employee']);

    $service->updateEmployee($ids['admin_id'], $otherAdminId, $account, account_security_profile());
    $state = account_security_state($database->pdo(), $otherAdminId);

    assert_same('employee', $state['role']);
    assert_same(2, $state['session_version']);
    assert_same(1, active_administrator_count($database->pdo()));
});

test('database integration: protected account failure leaves all submitted changes unapplied', function (): void {
    [$database, $ids, $employees, $service] = account_security_fixture();
    $before = $employees->findById($ids['admin_id']);
    assert_true(is_array($before));
    $account = account_security_data($employees, $ids['admin_id'], [
        'name' => 'Name Must Not Persist',
        'role' => 'employee',
    ]);

    capture_account_security_exception(
        static fn () => $service->updateEmployee(
            $ids['admin_id'],
            $ids['admin_id'],
            $account,
            ['phone' => '000', 'address' => 'Must not persist'],
        )
    );

    $after = $employees->findById($ids['admin_id']);
    assert_true(is_array($after));
    assert_same($before['name'], $after['name']);
    assert_same($before['role'], $after['role']);
    assert_same($before['session_version'], $after['session_version']);
    assert_same(0, (int) $database->pdo()->query('SELECT COUNT(*) FROM employee_profiles WHERE user_id = ' . $ids['admin_id'])->fetchColumn());
});

test('database integration: administrator password reset increments session version atomically', function (): void {
    [$database, $ids, , $service] = account_security_fixture();
    $before = account_security_state($database->pdo(), $ids['employee_id']);

    $service->resetPassword($ids['admin_id'], $ids['employee_id'], 'ResetSecure42', 'ResetSecure42');
    $after = account_security_state($database->pdo(), $ids['employee_id']);

    assert_same($before['session_version'] + 1, $after['session_version']);
});

test('database integration: stale administrator password reset cannot overwrite a newer password', function (): void {
    [$database, $ids] = account_security_fixture();
    $pdo = $database->pdo();
    $service = new AccountManagementService(
        $pdo,
        new Employee($pdo),
        new EmployeeProfile($pdo),
        new PasswordPolicyService(),
        new AuditLogService($pdo),
    );
    $before = account_security_state($pdo, $ids['employee_id']);

    $service->resetPassword($ids['admin_id'], $ids['employee_id'], 'FirstReset42', 'FirstReset42', [], $before['session_version']);
    $afterFirst = account_security_state($pdo, $ids['employee_id']);
    $hashAfterFirst = (string) $pdo->query('SELECT password FROM users WHERE id = ' . $ids['employee_id'])->fetchColumn();
    $auditAfterFirst = (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'employee.password_reset' AND entity_id = " . $ids['employee_id'])->fetchColumn();

    $exception = capture_account_security_exception(
        static fn () => $service->resetPassword($ids['admin_id'], $ids['employee_id'], 'SecondReset42', 'SecondReset42', [], $before['session_version'])
    );

    assert_same(AccountSecurityException::STALE_SECURITY_STATE, $exception->reason());
    assert_same($afterFirst, account_security_state($pdo, $ids['employee_id']));
    assert_same($hashAfterFirst, (string) $pdo->query('SELECT password FROM users WHERE id = ' . $ids['employee_id'])->fetchColumn());
    assert_same($auditAfterFirst, (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'employee.password_reset' AND entity_id = " . $ids['employee_id'])->fetchColumn());
});

test('database integration: role change increments session version', function (): void {
    [$database, $ids, $employees, $service] = account_security_fixture();
    $before = account_security_state($database->pdo(), $ids['employee_id']);
    $account = account_security_data($employees, $ids['employee_id'], ['role' => 'admin']);

    $service->updateEmployee($ids['admin_id'], $ids['employee_id'], $account, account_security_profile());
    $after = account_security_state($database->pdo(), $ids['employee_id']);

    assert_same($before['session_version'] + 1, $after['session_version']);
});

test('database integration: activation and deactivation each increment session version', function (): void {
    [$database, $ids, $employees, $service] = account_security_fixture();

    $service->deactivateEmployee($ids['admin_id'], $ids['employee_id']);
    $inactive = account_security_state($database->pdo(), $ids['employee_id']);
    assert_same(2, $inactive['session_version']);

    $account = account_security_data($employees, $ids['employee_id'], ['status' => 'active']);
    $service->updateEmployee($ids['admin_id'], $ids['employee_id'], $account, account_security_profile());
    $active = account_security_state($database->pdo(), $ids['employee_id']);

    assert_same('active', $active['status']);
    assert_same(3, $active['session_version']);
});

test('database integration: stale employee security state cannot restore administrator access', function (): void {
    [$database, $ids, $employees, $service] = account_security_fixture();
    $otherAdminId = insert_account_security_admin($database->pdo());
    $staleExpected = [
        'role' => 'admin',
        'status' => 'active',
        'session_version' => 1,
    ];

    $demoteAccount = account_security_data($employees, $otherAdminId, ['role' => 'employee']);
    $service->updateEmployee($ids['admin_id'], $otherAdminId, $demoteAccount, account_security_profile());
    $afterDemotion = account_security_state($database->pdo(), $otherAdminId);
    assert_same('employee', $afterDemotion['role']);
    assert_same(2, $afterDemotion['session_version']);

    $staleRestoreAccount = account_security_data($employees, $otherAdminId, ['role' => 'admin']);
    $exception = capture_account_security_exception(
        static fn () => $service->updateEmployee(
            $ids['admin_id'],
            $otherAdminId,
            $staleRestoreAccount,
            account_security_profile(),
            null,
            null,
            [],
            $staleExpected,
        )
    );

    assert_same(AccountSecurityException::STALE_SECURITY_STATE, $exception->reason());
    assert_same($afterDemotion, account_security_state($database->pdo(), $otherAdminId));
});

test('database integration: repeated employee deactivation is a no-op without duplicate success audit', function (): void {
    [$database, $ids, , $service] = account_security_fixture();
    $pdo = $database->pdo();
    $auditedService = new AccountManagementService(
        $pdo,
        new Employee($pdo),
        new EmployeeProfile($pdo),
        new PasswordPolicyService(),
        new AuditLogService($pdo),
    );

    $first = $auditedService->deactivateEmployee($ids['admin_id'], $ids['employee_id']);
    $afterFirst = account_security_state($pdo, $ids['employee_id']);
    $auditAfterFirst = (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'employee.deactivated' AND entity_id = " . $ids['employee_id'])->fetchColumn();
    $second = $auditedService->deactivateEmployee($ids['admin_id'], $ids['employee_id']);

    assert_true($first['security_changed']);
    assert_false($second['security_changed']);
    assert_same($afterFirst, account_security_state($pdo, $ids['employee_id']));
    assert_same($auditAfterFirst, (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'employee.deactivated' AND entity_id = " . $ids['employee_id'])->fetchColumn());
});

test('database integration: successful security changes audit each session invalidation', function (): void {
    [$database, $ids, $employees] = account_security_fixture();
    $pdo = $database->pdo();
    $service = new AccountManagementService(
        $pdo,
        $employees,
        new EmployeeProfile($pdo),
        new PasswordPolicyService(),
        new AuditLogService($pdo),
    );

    $service->deactivateEmployee($ids['admin_id'], $ids['employee_id']);

    $promotedAccount = account_security_data($employees, $ids['other_employee_id'], ['role' => 'admin']);
    $service->updateEmployee($ids['admin_id'], $ids['other_employee_id'], $promotedAccount, account_security_profile());
    $service->resetPassword($ids['admin_id'], $ids['other_employee_id'], 'AuditResetSecure42', 'AuditResetSecure42');

    assert_same(1, account_security_audit_count($pdo, 'employee.deactivated', $ids['employee_id']));
    assert_same(1, account_security_audit_count($pdo, 'security.session_invalidated', $ids['employee_id']));
    assert_same(1, account_security_audit_count($pdo, 'employee.role_changed', $ids['other_employee_id']));
    assert_same(1, account_security_audit_count($pdo, 'employee.password_reset', $ids['other_employee_id']));
    assert_same(2, account_security_audit_count($pdo, 'security.session_invalidated', $ids['other_employee_id']));
});

test('database integration: valid employee account and profile update remains atomic and successful', function (): void {
    [$database, $ids, $employees, $service] = account_security_fixture();
    $account = account_security_data($employees, $ids['employee_id'], ['name' => 'Updated Employee']);

    $service->updateEmployee(
        $ids['admin_id'],
        $ids['employee_id'],
        $account,
        ['phone' => '09-000-000', 'address' => 'Updated address'],
    );

    $updated = $employees->findById($ids['employee_id']);
    $profile = (new EmployeeProfile($database->pdo()))->findByUserId($ids['employee_id']);
    assert_true(is_array($updated));
    assert_true(is_array($profile));
    assert_same('Updated Employee', $updated['name']);
    assert_same('09-000-000', $profile['phone']);
    assert_same('Updated address', $profile['address']);
    assert_same(1, (int) $updated['session_version']);
});

test('database integration: existing account email remains immutable during normal employee update', function (): void {
    [$database, $ids, $employees, $service] = account_security_fixture();
    $before = $employees->findById($ids['employee_id']);
    assert_true(is_array($before));

    $submitted = account_security_data($employees, $ids['employee_id'], [
        'email' => 'forged-replacement@example.test',
        'name' => 'Email Must Stay Stable',
    ]);
    $service->updateEmployee($ids['admin_id'], $ids['employee_id'], $submitted, account_security_profile());

    $after = $employees->findById($ids['employee_id']);
    assert_true(is_array($after));
    assert_same($before['email'], $after['email']);
    assert_same('Email Must Stay Stable', $after['name']);
    assert_same(0, account_security_audit_count($database->pdo(), 'employee.email_changed', $ids['employee_id']));
});

test('database integration: employee update audit includes employment profile fields', function (): void {
    [$database, $ids, $employees] = account_security_fixture();
    $service = new AccountManagementService(
        $database->pdo(),
        $employees,
        new EmployeeProfile($database->pdo()),
        new PasswordPolicyService(),
        new AuditLogService($database->pdo()),
    );
    $account = account_security_data($employees, $ids['employee_id'], [
        'employment_type' => 'part-time',
        'join_date' => '2026-08-10',
        'expected_daily_work_hours' => 7.5,
        'overtime_eligible' => false,
    ]);

    $service->updateEmployee($ids['admin_id'], $ids['employee_id'], $account, account_security_profile());

    $statement = $database->pdo()->prepare("SELECT old_values, new_values FROM audit_logs WHERE action = 'employee.updated' AND entity_id = :id ORDER BY id DESC LIMIT 1");
    $statement->execute([':id' => $ids['employee_id']]);
    $audit = $statement->fetch();
    assert_true(is_array($audit));

    $oldValues = json_decode((string) $audit['old_values'], true);
    $newValues = json_decode((string) $audit['new_values'], true);
    assert_same('full-time', $oldValues['employment_type']);
    assert_same(null, $oldValues['join_date']);
    assert_same(8.0, (float) $oldValues['expected_daily_work_hours']);
    assert_true($oldValues['overtime_eligible']);
    assert_same('part-time', $newValues['employment_type']);
    assert_same('2026-08-10', $newValues['join_date']);
    assert_same(7.5, (float) $newValues['expected_daily_work_hours']);
    assert_false($newValues['overtime_eligible']);
});

test('database integration: employee update rolls back when profile save fails', function (): void {
    [$database, $ids, $employees] = account_security_fixture();
    $pdo = $database->pdo();
    $profiles = new class ($pdo) extends EmployeeProfile {
        public function save(int $userId, ?string $phone, ?string $address): bool
        {
            return false;
        }
    };
    $service = new AccountManagementService($pdo, $employees, $profiles, new PasswordPolicyService(), new AuditLogService($pdo));
    $before = $employees->findById($ids['employee_id']);
    assert_true(is_array($before));
    $account = account_security_data($employees, $ids['employee_id'], [
        'name' => 'Should Roll Back',
        'email' => 'rollback.update.integration@sapas.test',
        'employment_type' => 'contract',
        'expected_daily_work_hours' => 6.0,
    ]);
    $beforeAuditCount = (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'employee.updated'")->fetchColumn();

    try {
        $service->updateEmployee($ids['admin_id'], $ids['employee_id'], $account, ['phone' => '09 333 333 333', 'address' => 'Must not persist']);
    } catch (RuntimeException) {
        // Expected path.
    }

    $after = $employees->findById($ids['employee_id']);
    assert_true(is_array($after));
    assert_same($before['name'], $after['name']);
    assert_same($before['email'], $after['email']);
    assert_same($before['employment_type'], $after['employment_type']);
    assert_same((float) $before['expected_daily_work_hours'], (float) $after['expected_daily_work_hours']);
    assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM employee_profiles WHERE user_id = ' . $ids['employee_id'])->fetchColumn());
    assert_same($beforeAuditCount, (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'employee.updated'")->fetchColumn());
});

test('database integration: employee creation rolls back when profile creation fails', function (): void {
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $pdo = $database->pdo();
    $employees = new Employee($pdo);
    $profiles = new class ($pdo) extends EmployeeProfile {
        public function create(int $userId, ?string $phone, ?string $address): bool
        {
            return false;
        }
    };
    $service = new AccountManagementService($pdo, $employees, $profiles, new PasswordPolicyService());
    $beforeUsers = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $beforeProfiles = (int) $pdo->query('SELECT COUNT(*) FROM employee_profiles')->fetchColumn();

    try {
        $service->createEmployee(
            [
                'name' => 'Rollback Employee',
                'email' => 'rollback.integration@sapas.test',
                'role' => 'employee',
                'department_id' => $ids['department_id'],
                'position_id' => $ids['position_id'],
                'status' => 'active',
                'staff_code' => '654322',
                'employment_type' => 'full-time',
                'join_date' => null,
                'expected_daily_work_hours' => 8.0,
                'overtime_eligible' => true,
            ],
            ['phone' => null, 'address' => null],
            'Rollback42',
            'Rollback42',
        );
    } catch (RuntimeException) {
        // Expected path.
    }

    assert_same($beforeUsers, (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
    assert_same($beforeProfiles, (int) $pdo->query('SELECT COUNT(*) FROM employee_profiles')->fetchColumn());
    assert_same(0, (int) $pdo->query("SELECT COUNT(*) FROM users WHERE email = 'rollback.integration@sapas.test'")->fetchColumn());
});

/** @return array{0: TestDatabase, 1: array<string, int>, 2: Employee, 3: AccountManagementService} */
function account_security_fixture(): array
{
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $employees = new Employee($database->pdo());
    $service = new AccountManagementService(
        $database->pdo(),
        $employees,
        new EmployeeProfile($database->pdo()),
        new PasswordPolicyService(),
    );

    return [$database, $ids, $employees, $service];
}

/** @param array<string, mixed> $overrides */
function account_security_data(Employee $employees, int $userId, array $overrides = []): array
{
    $user = $employees->findById($userId);

    if (!is_array($user)) {
        throw new RuntimeException('Account fixture user was not found.');
    }

    return array_replace([
        'name' => $user['name'],
        'email' => $user['email'],
        'role' => $user['role'],
        'department_id' => $user['department_id'] === null ? null : (int) $user['department_id'],
        'position_id' => $user['position_id'] === null ? null : (int) $user['position_id'],
        'status' => $user['status'],
        'staff_code' => $user['staff_code'] ?? null,
        'employment_type' => $user['employment_type'] ?? 'full-time',
        'join_date' => $user['join_date'] ?? null,
        'expected_daily_work_hours' => (float) ($user['expected_daily_work_hours'] ?? 8),
        'overtime_eligible' => (int) ($user['overtime_eligible'] ?? 1) === 1,
    ], $overrides);
}

/** @return array{phone: null, address: null} */
function account_security_profile(): array
{
    return ['phone' => null, 'address' => null];
}

function capture_account_security_exception(callable $callback): AccountSecurityException
{
    try {
        $callback();
    } catch (AccountSecurityException $exception) {
        return $exception;
    }

    throw new RuntimeException('Expected an account security exception.');
}

function insert_account_security_admin(PDO $pdo): int
{
    $statement = $pdo->prepare(
        "INSERT INTO users (name, email, password, role, status, staff_code, email_verified_at)
         VALUES ('Second Integration Admin', 'second.admin.integration@sapas.test', :password, 'admin', 'active', 'SAP-9001', NOW())"
    );
    $statement->execute([':password' => password_hash('SecondAdmin42', PASSWORD_DEFAULT)]);

    return (int) $pdo->lastInsertId();
}

/** @return array{role: string, status: string, session_version: int} */
function account_security_state(PDO $pdo, int $userId): array
{
    $statement = $pdo->prepare('SELECT role, status, session_version FROM users WHERE id = :id');
    $statement->execute([':id' => $userId]);
    $row = $statement->fetch();

    if (!is_array($row)) {
        throw new RuntimeException('Account security state could not be read.');
    }

    return [
        'role' => (string) $row['role'],
        'status' => (string) $row['status'],
        'session_version' => (int) $row['session_version'],
    ];
}

function active_administrator_count(PDO $pdo): int
{
    return (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active'")->fetchColumn();
}

function account_security_audit_count(PDO $pdo, string $action, int $entityId): int
{
    $statement = $pdo->prepare('SELECT COUNT(*) FROM audit_logs WHERE action = :action AND entity_id = :entity_id');
    $statement->execute([':action' => $action, ':entity_id' => (string) $entityId]);

    return (int) $statement->fetchColumn();
}
