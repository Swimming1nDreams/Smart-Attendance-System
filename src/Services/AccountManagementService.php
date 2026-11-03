<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeProfile;
use PDO;
use RuntimeException;
use Throwable;

final class AccountManagementService
{
    public function __construct(
        private PDO $connection,
        private Employee $employeeModel,
        private EmployeeProfile $employeeProfileModel,
        private PasswordPolicyService $passwordPolicy,
        private ?AuditLogService $auditLog = null,
    ) {
    }

    /**
     * @param array<string, mixed> $account
     * @param array<string, mixed> $profile
     */
    public function createEmployee(array $account, array $profile, string $password, string $passwordConfirmation, ?int $actorUserId = null, array $auditContext = []): int
    {
        $this->passwordPolicy->assertValid($password, $passwordConfirmation);
        $staffCode = trim((string) ($account['staff_code'] ?? ''));
        if (preg_match('/\A[0-9]{6}\z/D', $staffCode) !== 1) {
            throw new RuntimeException('Staff code must contain exactly 6 digits.');
        }
        $this->connection->beginTransaction();

        try {
            if ($this->employeeModel->existsByStaffCode($staffCode)) {
                throw new RuntimeException('Staff code is already in use.');
            }
            $userId = $this->employeeModel->create(
                (string) $account['name'],
                (string) $account['email'],
                $password,
                (string) $account['role'],
                $account['department_id'] === null ? null : (int) $account['department_id'],
                $account['position_id'] === null ? null : (int) $account['position_id'],
                (string) $account['status'],
                $staffCode,
                (string) $account['employment_type'],
                $account['join_date'] === null ? null : (string) $account['join_date'],
                (float) $account['expected_daily_work_hours'],
                (bool) $account['overtime_eligible'],
            );

            if ($userId <= 0) {
                throw new RuntimeException('Employee account creation failed.');
            }

            if (!$this->employeeProfileModel->create(
                $userId,
                $profile['phone'] === null ? null : (string) $profile['phone'],
                $profile['address'] === null ? null : (string) $profile['address'],
            )) {
                throw new RuntimeException('Employee profile creation failed.');
            }

            $this->auditLog?->record($actorUserId, 'employee.created', 'user', $userId, 'Employee account created', null, [
                'name' => (string) $account['name'], 'email' => (string) $account['email'], 'role' => (string) $account['role'],
                'status' => (string) $account['status'], 'staff_code' => $staffCode,
                'department_id' => $account['department_id'], 'position_id' => $account['position_id'],
                'employment_type' => (string) $account['employment_type'],
                'join_date' => $account['join_date'],
                'expected_daily_work_hours' => (float) $account['expected_daily_work_hours'],
                'overtime_eligible' => (bool) $account['overtime_eligible'],
                'phone' => $profile['phone'], 'address' => $profile['address'],
            ], $auditContext);
            $this->auditLog?->record($actorUserId, 'employee.staff_code_assigned', 'user', $userId, 'Permanent staff code assigned', null,
                ['staff_code' => $staffCode], $auditContext);

            $this->connection->commit();

            return $userId;
        } catch (Throwable $exception) {
            $this->rollBackIfNeeded();
            throw $exception;
        }
    }

    /**
     * @param array<string, mixed> $account
     * @param array<string, mixed> $profile
     * @return array{security_changed: bool, current_session_invalidated: bool}
     */
    public function updateEmployee(
        int $authenticatedUserId,
        int $targetUserId,
        array $account,
        array $profile,
        ?string $password = null,
        ?string $passwordConfirmation = null,
        array $auditContext = [],
        ?array $expectedSecurityState = null,
    ): array {
        if ($password !== null) {
            $this->passwordPolicy->assertValid($password, $passwordConfirmation);
        }

        $this->connection->beginTransaction();

        try {
            $activeAdministratorIds = $this->employeeModel->lockActiveAdministratorIds();
            $current = $this->employeeModel->lockById($targetUserId);

            if ($current === null) {
                throw new AccountSecurityException(AccountSecurityException::ACCOUNT_NOT_FOUND);
            }

            // Account email is an identity field after creation. Keep it tied to
            // the locked database record even if a caller submits a stale or
            // forged replacement value.
            $account['email'] = (string) $current['email'];

            $newRole = (string) $account['role'];
            $newStatus = (string) $account['status'];
            $staffCode = trim((string) ($account['staff_code'] ?? ''));
            if (preg_match('/\A[0-9]{6}\z/D', $staffCode) !== 1 && $staffCode !== trim((string) ($current['staff_code'] ?? ''))) {
                throw new RuntimeException('Staff code must contain exactly 6 digits.');
            }
            if ($this->employeeModel->existsByStaffCode($staffCode, $targetUserId)) {
                throw new RuntimeException('Staff code is already in use.');
            }
            $securityChangeRequested = (string) $current['role'] !== $newRole
                || (string) $current['status'] !== $newStatus
                || $password !== null;

            if ($securityChangeRequested && $expectedSecurityState !== null) {
                $this->assertExpectedSecurityState($current, $expectedSecurityState);
            }

            $this->assertAccessChangeAllowed(
                $authenticatedUserId,
                $targetUserId,
                (string) $current['role'],
                (string) $current['status'],
                $newRole,
                $newStatus,
                $activeAdministratorIds,
            );

            $securityChanged = $securityChangeRequested;
            $oldProfile = $this->employeeProfileModel->findByUserId($targetUserId) ?? [];

            $updated = $this->employeeModel->update(
                $targetUserId,
                (string) $account['name'],
                (string) $account['email'],
                $newRole,
                $account['department_id'] === null ? null : (int) $account['department_id'],
                $account['position_id'] === null ? null : (int) $account['position_id'],
                $newStatus,
                $staffCode,
                (string) $account['employment_type'],
                $account['join_date'] === null ? null : (string) $account['join_date'],
                (float) $account['expected_daily_work_hours'],
                (bool) $account['overtime_eligible'],
                $password,
                $securityChanged,
            );

            if (!$updated) {
                throw new RuntimeException('Employee account update failed.');
            }

            if (!$this->employeeProfileModel->save(
                $targetUserId,
                $profile['phone'] === null ? null : (string) $profile['phone'],
                $profile['address'] === null ? null : (string) $profile['address'],
            )) {
                throw new RuntimeException('Employee profile update failed.');
            }

            $newState = [
                'name' => (string) $account['name'], 'email' => (string) $account['email'], 'role' => $newRole,
                'status' => $newStatus, 'staff_code' => $staffCode,
                'department_id' => $account['department_id'], 'position_id' => $account['position_id'],
                'employment_type' => (string) $account['employment_type'],
                'join_date' => $account['join_date'],
                'expected_daily_work_hours' => (float) $account['expected_daily_work_hours'],
                'overtime_eligible' => (bool) $account['overtime_eligible'],
                'phone' => $profile['phone'], 'address' => $profile['address'],
            ];
            $oldState = [
                'name' => $current['name'], 'email' => $current['email'], 'role' => $current['role'], 'status' => $current['status'],
                'staff_code' => $current['staff_code'], 'department_id' => $current['department_id'], 'position_id' => $current['position_id'],
                'employment_type' => $current['employment_type'],
                'join_date' => $current['join_date'],
                'expected_daily_work_hours' => (float) $current['expected_daily_work_hours'],
                'overtime_eligible' => (int) $current['overtime_eligible'] === 1,
                'phone' => $oldProfile['phone'] ?? null, 'address' => $oldProfile['address'] ?? null,
            ];
            if ($password !== null) {
                $newState['password_changed'] = true;
                $newState['session_version_incremented'] = true;
            }
            $this->auditLog?->record($authenticatedUserId, 'employee.updated', 'user', $targetUserId, 'Employee account and profile updated', $oldState, $newState, $auditContext);
            foreach ([
                'role' => 'employee.role_changed',
                'phone' => 'employee.phone_changed',
                'address' => 'employee.address_changed',
                'staff_code' => 'employee.staff_code_changed',
            ] as $field => $action) {
                if (($oldState[$field] ?? null) !== ($newState[$field] ?? null)) {
                    $this->auditLog?->record($authenticatedUserId, $action, 'user', $targetUserId, ucfirst($field) . ' changed',
                        [$field => $oldState[$field] ?? null], [$field => $newState[$field] ?? null], $auditContext);
                }
            }
            if (($oldState['status'] ?? null) !== ($newState['status'] ?? null)) {
                $action = $newState['status'] === 'active' ? 'employee.activated' : 'employee.deactivated';
                $this->auditLog?->record($authenticatedUserId, $action, 'user', $targetUserId, 'Account status changed',
                    ['status' => $oldState['status']], ['status' => $newState['status']], $auditContext);
            }
            if ($securityChanged) {
                $this->auditLog?->record($authenticatedUserId, 'security.session_invalidated', 'user', $targetUserId,
                    'Account security change invalidated existing sessions', null, ['session_version_incremented' => true], $auditContext);
            }

            $this->connection->commit();

            return [
                'security_changed' => $securityChanged,
                'current_session_invalidated' => $securityChanged && $authenticatedUserId === $targetUserId,
            ];
        } catch (Throwable $exception) {
            $this->rollBackIfNeeded();
            throw $exception;
        }
    }

    /** @return array{security_changed: bool, current_session_invalidated: bool} */
    public function deactivateEmployee(int $authenticatedUserId, int $targetUserId, array $auditContext = []): array
    {
        $this->connection->beginTransaction();

        try {
            $activeAdministratorIds = $this->employeeModel->lockActiveAdministratorIds();
            $current = $this->employeeModel->lockById($targetUserId);

            if ($current === null) {
                throw new AccountSecurityException(AccountSecurityException::ACCOUNT_NOT_FOUND);
            }

            $this->assertAccessChangeAllowed(
                $authenticatedUserId,
                $targetUserId,
                (string) $current['role'],
                (string) $current['status'],
                (string) $current['role'],
                'inactive',
                $activeAdministratorIds,
            );

            $securityChanged = (string) $current['status'] !== 'inactive';

            if (!$securityChanged) {
                $this->connection->commit();

                return [
                    'security_changed' => false,
                    'current_session_invalidated' => false,
                ];
            }

            if (!$this->employeeModel->deactivate($targetUserId)) {
                throw new RuntimeException('Employee deactivation failed.');
            }

            $this->auditLog?->record($authenticatedUserId, 'employee.deactivated', 'user', $targetUserId, 'Employee account deactivated',
                ['status' => $current['status']], ['status' => 'inactive', 'session_version_incremented' => true], $auditContext);
            if ($securityChanged) {
                $this->auditLog?->record($authenticatedUserId, 'security.session_invalidated', 'user', $targetUserId,
                    'Account deactivation invalidated existing sessions', null, ['session_version_incremented' => true], $auditContext);
            }

            $this->connection->commit();

            return [
                'security_changed' => $securityChanged,
                'current_session_invalidated' => false,
            ];
        } catch (Throwable $exception) {
            $this->rollBackIfNeeded();
            throw $exception;
        }
    }

    /** @return array{security_changed: bool, current_session_invalidated: bool} */
    public function resetPassword(
        int $authenticatedUserId,
        int $targetUserId,
        string $password,
        string $passwordConfirmation,
        array $auditContext = [],
        ?int $expectedSessionVersion = null,
    ): array {
        $this->passwordPolicy->assertValid($password, $passwordConfirmation);
        $this->connection->beginTransaction();

        try {
            $current = $this->employeeModel->lockById($targetUserId);
            if ($current === null) {
                throw new AccountSecurityException(AccountSecurityException::ACCOUNT_NOT_FOUND);
            }

            if ($expectedSessionVersion !== null && $expectedSessionVersion !== (int) $current['session_version']) {
                throw new AccountSecurityException(AccountSecurityException::STALE_SECURITY_STATE);
            }

            if (!$this->employeeModel->resetPassword($targetUserId, $password)) {
                throw new RuntimeException('Employee password reset failed.');
            }

            $this->auditLog?->record($authenticatedUserId, 'employee.password_reset', 'user', $targetUserId, 'Administrator reset account password',
                null, ['password_changed' => true, 'session_version_incremented' => true], $auditContext);
            $this->auditLog?->record($authenticatedUserId, 'security.session_invalidated', 'user', $targetUserId,
                'Password reset invalidated existing sessions', null, ['session_version_incremented' => true], $auditContext);

            $this->connection->commit();

            return [
                'security_changed' => true,
                'current_session_invalidated' => $authenticatedUserId === $targetUserId,
            ];
        } catch (Throwable $exception) {
            $this->rollBackIfNeeded();
            throw $exception;
        }
    }

    /** @param array<string, mixed> $current */
    private function assertExpectedSecurityState(array $current, ?array $expectedSecurityState): void
    {
        $expectedRole = (string) ($expectedSecurityState['role'] ?? '');
        $expectedStatus = (string) ($expectedSecurityState['status'] ?? '');
        $expectedSessionVersion = filter_var($expectedSecurityState['session_version'] ?? null, FILTER_VALIDATE_INT);

        if (
            $expectedRole === ''
            || $expectedStatus === ''
            || $expectedSessionVersion === false
            || $expectedSessionVersion <= 0
            || $expectedRole !== (string) $current['role']
            || $expectedStatus !== (string) $current['status']
            || (int) $expectedSessionVersion !== (int) $current['session_version']
        ) {
            throw new AccountSecurityException(AccountSecurityException::STALE_SECURITY_STATE);
        }
    }

    /** @param list<int> $activeAdministratorIds */
    private function assertAccessChangeAllowed(
        int $authenticatedUserId,
        int $targetUserId,
        string $currentRole,
        string $currentStatus,
        string $newRole,
        string $newStatus,
        array $activeAdministratorIds,
    ): void {
        $isSelfAdministrator = $authenticatedUserId === $targetUserId && $currentRole === 'admin';

        if ($isSelfAdministrator && $newRole !== 'admin') {
            throw new AccountSecurityException(AccountSecurityException::SELF_ROLE_CHANGE);
        }

        if ($isSelfAdministrator && $newStatus !== 'active') {
            throw new AccountSecurityException(AccountSecurityException::SELF_DEACTIVATION);
        }

        $isCurrentlyActiveAdministrator = $currentRole === 'admin' && $currentStatus === 'active';
        $willRemainActiveAdministrator = $newRole === 'admin' && $newStatus === 'active';

        if ($isCurrentlyActiveAdministrator && !$willRemainActiveAdministrator && count($activeAdministratorIds) <= 1) {
            throw new AccountSecurityException(AccountSecurityException::LAST_ACTIVE_ADMIN);
        }
    }

    private function rollBackIfNeeded(): void
    {
        if ($this->connection->inTransaction()) {
            $this->connection->rollBack();
        }
    }

}
