<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Application;
use App\Core\Controller;
use App\Core\SessionManager;
use App\Core\Request;

abstract class MasterDataController extends Controller
{
    public function __construct(SessionManager $sessionManager, Application $app)
    {
        parent::__construct($app, $sessionManager);
    }

    protected function render(string $view, array $data = []): void
    {
        $data['flash'] = $this->sessionManager->consumeFlash();
        $data['userName'] = $this->sessionManager->getUserName();
        parent::renderView($view, $data);
    }

    protected function ensureAdmin(): void
    {
        $this->sessionManager->requireRole('admin');
    }

    protected function ensureAdminOrDepartmentHead(): void
    {
        $this->sessionManager->requireAuthentication();
        $role = $this->sessionManager->getUserRole();
        if ($role !== 'admin' && $role !== 'department_head') {
            header('Location: /access-denied');
            exit;
        }
    }

    /**
     * Administrators may manage every allowed target role. Department Heads may
     * manage only employees in their currently authenticated department.
     *
     * @param list<string> $adminTargetRoles
     */
    protected function canManageUserInScope(
        \App\Models\Employee $employeeModel,
        int $targetUserId,
        array $adminTargetRoles = ['employee'],
    ): bool {
        $target = $employeeModel->findById($targetUserId);
        if ($target === null || !in_array((string) ($target['role'] ?? ''), $adminTargetRoles, true)) {
            return false;
        }

        $role = $this->sessionManager->getUserRole();
        if ($role === 'admin') {
            return true;
        }

        $managerId = $this->sessionManager->getUserId();
        $managerDepartmentId = $this->sessionManager->getUserDepartmentId();

        return $role === 'department_head'
            && ($target['role'] ?? '') === 'employee'
            && $managerId !== null
            && $targetUserId !== $managerId
            && $managerDepartmentId !== null
            && $managerDepartmentId > 0
            && (int) ($target['department_id'] ?? 0) === $managerDepartmentId;
    }

    protected function denyOutOfScopeManagement(): void
    {
        $this->sessionManager->setFlash('error', $this->t('validation.department_scope_denied'));
        header('Location: /access-denied');
        exit;
    }

    protected function resolveScopeFilters(Request $request): array
    {
        $role = $this->sessionManager->getUserRole();
        $filters = [
            'department_id' => null,
            'employee_id' => null,
            'errors' => [],
        ];

        $reqDeptId = $this->parseOptionalInt($request->query('department_id') ?? $request->query('department'));
        $reqEmpId = $this->parseOptionalInt($request->query('employee_id') ?? $request->query('employee'));
        
        if ($reqEmpId !== null && $reqEmpId <= 0) {
            $reqEmpId = null;
        }
        if ($reqDeptId !== null && $reqDeptId <= 0) {
            $reqDeptId = null;
        }

        if ($role === 'admin') {
            $filters['department_id'] = $reqDeptId;
            $filters['employee_id'] = $reqEmpId;
        } elseif ($role === 'department_head') {
            $filters['department_id'] = $this->sessionManager->getUserDepartmentId();
            $filters['employee_id'] = $reqEmpId;
            if ($reqDeptId !== null && $reqDeptId !== $filters['department_id']) {
                $filters['errors'][] = $this->t('validation.unauthorized_department');
            }
        } elseif ($role === 'employee') {
            $filters['employee_id'] = $this->sessionManager->getUserId();
            if ($reqEmpId !== null && $reqEmpId !== $filters['employee_id']) {
                $filters['errors'][] = $this->t('validation.unauthorized_employee');
            }
        }

        return $filters;
    }

    protected function normalizeEmployeeForDepartment(\App\Models\Employee $employeeModel, ?int $employeeId, ?int $departmentId): ?int
    {
        if ($employeeId === null || $departmentId === null) {
            return $employeeId;
        }

        $employee = $employeeModel->findById($employeeId);
        if ($employee === null || (int) ($employee['department_id'] ?? 0) !== $departmentId || ($employee['role'] ?? '') !== 'employee') {
            return null;
        }

        return $employeeId;
    }

    protected function parseOptionalInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_INT);
        return $parsed === false ? null : $parsed;
    }

    protected function auditContext(Request $request): array
    {
        return [
            'ip_address' => (string) $request->getServerParam('REMOTE_ADDR', ''),
            'user_agent' => (string) $request->getServerParam('HTTP_USER_AGENT', ''),
        ];
    }

    /**
     * @param array $departments
     * @param int|null $forcedDepartmentId
     * @return array
     */
    protected function filterAllowedDepartments(array $departments, ?int $forcedDepartmentId): array
    {
        if ($forcedDepartmentId === null) {
            return $departments;
        }

        return array_values(array_filter($departments, static function (array $department) use ($forcedDepartmentId) {
            return (int) $department['id'] === $forcedDepartmentId;
        }));
    }
}
