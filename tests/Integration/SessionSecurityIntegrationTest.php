<?php

declare(strict_types=1);

use App\Core\SessionManager;
use App\Models\Employee;
use App\Models\EmployeeProfile;
use App\Models\User;
use App\Services\AccountManagementService;
use App\Services\AuthService;
use App\Services\PasswordPolicyService;
use App\Services\SessionSecurityService;

test('database integration: deactivated account existing session is rejected', function (): void {
    [$database, $ids, $employees, $accounts] = session_security_fixture();
    $session = session_security_login($database->pdo(), 'employee.integration@sapas.test');
    assert_true($session->isAuthenticated());

    $accounts->deactivateEmployee($ids['admin_id'], $ids['employee_id']);

    $nextRequest = session_security_manager($database->pdo());
    assert_false($nextRequest->isAuthenticated());
    assert_null($nextRequest->getUserId());
});

test('database integration: deactivated administrator existing session is rejected', function (): void {
    [$database, $ids, , $accounts] = session_security_fixture();
    $otherAdminId = session_security_insert_admin($database->pdo());
    $session = session_security_login($database->pdo(), 'session.admin.integration@sapas.test');
    assert_true($session->isAuthenticated());
    assert_same('admin', $session->getUserRole());

    $accounts->deactivateEmployee($ids['admin_id'], $otherAdminId);

    $nextRequest = session_security_manager($database->pdo());
    assert_false($nextRequest->isAuthenticated());
    assert_null($nextRequest->getUserId());
    assert_null($nextRequest->getUserRole());
});

test('database integration: downgraded administrator existing session cannot remain authenticated', function (): void {
    [$database, $ids, $employees, $accounts] = session_security_fixture();
    $otherAdminId = session_security_insert_admin($database->pdo());
    $session = session_security_login($database->pdo(), 'session.admin.integration@sapas.test');
    assert_true($session->isAuthenticated());
    assert_same('admin', $session->getUserRole());

    $account = session_security_account_data($employees, $otherAdminId, ['role' => 'employee']);
    $accounts->updateEmployee($ids['admin_id'], $otherAdminId, $account, ['phone' => null, 'address' => null]);

    $nextRequest = session_security_manager($database->pdo());
    assert_false($nextRequest->isAuthenticated());
    assert_null($nextRequest->getUserRole());
});

test('database integration: promoted employee old employee session cannot gain administrator access', function (): void {
    [$database, $ids, $employees, $accounts] = session_security_fixture();
    $session = session_security_login($database->pdo(), 'employee.integration@sapas.test');
    assert_true($session->isAuthenticated());
    assert_same('employee', $session->getUserRole());

    $account = session_security_account_data($employees, $ids['employee_id'], ['role' => 'admin']);
    $accounts->updateEmployee($ids['admin_id'], $ids['employee_id'], $account, ['phone' => null, 'address' => null]);

    $nextRequest = session_security_manager($database->pdo());
    assert_false($nextRequest->isAuthenticated());
    assert_null($nextRequest->getUserRole());
});

test('database integration: session version mismatch forces authenticated session logout', function (): void {
    [$database] = session_security_fixture();
    $session = session_security_login($database->pdo(), 'employee.integration@sapas.test');
    assert_true($session->isAuthenticated());

    $database->pdo()->exec(
        "UPDATE users SET session_version = session_version + 1 WHERE email = 'employee.integration@sapas.test'"
    );

    $nextRequest = session_security_manager($database->pdo());
    assert_false($nextRequest->isAuthenticated());
    assert_null($nextRequest->getUserId());
});

test('database integration: valid active account with matching session version remains authenticated', function (): void {
    [$database] = session_security_fixture();
    $session = session_security_login($database->pdo(), 'employee.integration@sapas.test');
    assert_true($session->isAuthenticated());

    $nextRequest = session_security_manager($database->pdo());
    assert_true($nextRequest->isAuthenticated());
    assert_same('employee', $nextRequest->getUserRole());
});

test('database integration: login stores the current database session version', function (): void {
    [$database] = session_security_fixture();
    $database->pdo()->exec(
        "UPDATE users SET session_version = 7 WHERE email = 'employee.integration@sapas.test'"
    );

    $session = session_security_login($database->pdo(), 'employee.integration@sapas.test');
    assert_true($session->isAuthenticated());
    assert_same(7, (int) ($_SESSION['auth']['session_version'] ?? 0));
});

test('database integration: own password reset invalidates the current administrator session', function (): void {
    [$database, $ids, , $accounts] = session_security_fixture();
    $session = session_security_login($database->pdo(), 'admin.integration@sapas.test');
    assert_true($session->isAuthenticated());

    $result = $accounts->resetPassword(
        $ids['admin_id'],
        $ids['admin_id'],
        'NewAdminSecure42',
        'NewAdminSecure42',
    );
    assert_true($result['current_session_invalidated']);

    $nextRequest = session_security_manager($database->pdo());
    assert_false($nextRequest->isAuthenticated());
});

test('database integration: resetting another logged-in user password invalidates that user session', function (): void {
    [$database, $ids, , $accounts] = session_security_fixture();
    $session = session_security_login($database->pdo(), 'employee.integration@sapas.test');
    assert_true($session->isAuthenticated());

    $result = $accounts->resetPassword(
        $ids['admin_id'],
        $ids['employee_id'],
        'OtherUserReset42',
        'OtherUserReset42',
    );
    assert_true($result['security_changed']);
    assert_false($result['current_session_invalidated']);

    $nextRequest = session_security_manager($database->pdo());
    assert_false($nextRequest->isAuthenticated());
    assert_null($nextRequest->getUserId());
});

/** @return array{0: TestDatabase, 1: array<string, int>, 2: Employee, 3: AccountManagementService} */
function session_security_fixture(): array
{
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $employees = new Employee($database->pdo());
    $accounts = new AccountManagementService(
        $database->pdo(),
        $employees,
        new EmployeeProfile($database->pdo()),
        new PasswordPolicyService(),
    );

    reset_test_session();

    return [$database, $ids, $employees, $accounts];
}

function session_security_manager(PDO $pdo): SessionManager
{
    $session = new SessionManager();
    $security = new SessionSecurityService(new User($pdo));
    $session->setAuthenticatedSessionValidator([$security, 'validate']);

    return $session;
}

function session_security_login(PDO $pdo, string $email): SessionManager
{
    $session = session_security_manager($pdo);
    $auth = new AuthService(new User($pdo), $session);
    assert_true($auth->login($email, 'Password123!'));

    return $session;
}

function session_security_insert_admin(PDO $pdo): int
{
    $statement = $pdo->prepare(
        "INSERT INTO users (name, email, password, role, status, staff_code, email_verified_at)
         VALUES ('Session Integration Admin', 'session.admin.integration@sapas.test', :password, 'admin', 'active', 'SAP-9002', NOW())"
    );
    $statement->execute([':password' => password_hash('Password123!', PASSWORD_DEFAULT)]);

    return (int) $pdo->lastInsertId();
}

/** @param array<string, mixed> $overrides */
function session_security_account_data(Employee $employees, int $userId, array $overrides = []): array
{
    $user = $employees->findById($userId);

    if (!is_array($user)) {
        throw new RuntimeException('Session security fixture user was not found.');
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
