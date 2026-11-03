<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Application;
use App\Core\Request;
use App\Core\SessionManager;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Department;
use App\Services\DateValidationService;

class AbsenceController extends MasterDataController
{
    private Attendance $attendanceModel;
    private Employee $employeeModel;
    private Department $departmentModel;
    private DateValidationService $dateValidator;

    public function __construct(Attendance $attendanceModel, Employee $employeeModel, Department $departmentModel, SessionManager $sessionManager, Application $app, ?DateValidationService $dateValidator = null)
    {
        parent::__construct($sessionManager, $app);
        $this->attendanceModel = $attendanceModel;
        $this->employeeModel = $employeeModel;
        $this->departmentModel = $departmentModel;
        $this->dateValidator = $dateValidator ?? new DateValidationService();
    }

    public function index(Request $request): void
    {
        $this->sessionManager->requireAuthentication();

        $role = $this->sessionManager->getUserRole();
        $page = max(1, (int) $this->queryScalar($request, 'page', 1));
        $requestedPerPage = (int) $this->queryScalar($request, 'per_page', 20);
        $perPage = in_array($requestedPerPage, [20, 50, 100], true) ? $requestedPerPage : 20;
        $search = trim((string) $this->queryScalar($request, 'search', ''));
        $fromInput = trim((string) $this->queryScalar($request, 'from', ''));
        $toInput = trim((string) $this->queryScalar($request, 'to', ''));
        $fromDate = $this->dateValidator->normalizeOptionalDate($fromInput);
        $toDate = $this->dateValidator->normalizeOptionalDate($toInput);
        $filterErrors = $this->dateFilterErrors($fromInput, $toInput, $fromDate, $toDate);
        $selectedEmployeeId = $this->parseOptionalInt($this->queryScalar($request, 'employee_id'));
        if ($selectedEmployeeId !== null && $selectedEmployeeId <= 0) {
            $selectedEmployeeId = null;
        }
        $userId = $this->sessionManager->getUserId();

        $records = [];
        $total = 0;

        if ($filterErrors !== []) {
            $records = [];
            $total = 0;
        } elseif ($role === 'admin' || $role === 'department_head') {
            $scope = $this->resolveScopeFilters($request);
            $departmentId = $scope['department_id'] ?? null;
            if (isset($scope['employee_id'])) {
                $selectedEmployeeId = (int) $scope['employee_id'];
            }
            $selectedEmployeeId = $this->normalizeEmployeeForDepartment($this->employeeModel, $selectedEmployeeId, $departmentId);
            $total = $this->attendanceModel->countAll($selectedEmployeeId, $search, $fromDate, $toDate, $departmentId, null, 'absent');
            $page = $this->clampPage($page, $perPage, $total);
            $records = $this->attendanceModel->findAll($selectedEmployeeId, $search, $fromDate, $toDate, $page, $perPage, $departmentId, null, 'absent');
            
            $departments = $this->filterAllowedDepartments($this->departmentModel->findAll(''), $departmentId);
            $employees = $this->employeeModel->findReportEmployees($departmentId);
        } else {
            if ($userId === null) {
                header('Location: /login');
                exit;
            }

            $this->ensureActiveEmployee($userId);

            $total = $this->attendanceModel->countAll($userId, '', $fromDate, $toDate, null, null, 'absent');
            $page = $this->clampPage($page, $perPage, $total);
            $records = $this->attendanceModel->findAll($userId, '', $fromDate, $toDate, $page, $perPage, null, null, 'absent');
        }

        $this->render('absence/index', [
            'title' => $this->t('absence.title'),
            'isTeamViewer' => $role === 'admin' || $role === 'department_head',
            'canManageAttendance' => $role === 'admin',
            'departmentScopeLocked' => $role === 'department_head',
            'records' => $records,
            'search' => $search,
            'selectedEmployeeId' => ($role === 'admin' || $role === 'department_head') ? $selectedEmployeeId : null,
            'departmentId' => ($role === 'admin' || $role === 'department_head') ? ($departmentId ?? null) : null,
            'fromDate' => $fromDate,
            'toDate' => $toDate,
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
            'departments' => $departments ?? [],
            'employees' => $employees ?? [],
            'errors' => $filterErrors,
        ]);
    }

    private function ensureActiveEmployee(int $userId): void
    {
        $employee = $this->employeeModel->findById($userId);

        if ($employee === null || ($employee['status'] ?? '') !== 'active') {
            $this->redirectInactiveAuthenticatedUser();
        }
    }

    private function dateFilterErrors(string $fromInput, string $toInput, ?string $fromDate, ?string $toDate): array
    {
        $errors = [];

        if ($fromInput !== '' && $fromDate === null) {
            $errors[] = $this->t('validation.invalid_date');
        }

        if ($toInput !== '' && $toDate === null) {
            $errors[] = $this->t('validation.invalid_date');
        }

        if ($fromDate !== null && $toDate !== null && !$this->dateValidator->isValidRange($fromDate, $toDate)) {
            $errors[] = $this->t('validation.invalid_date_range');
        }

        return array_values(array_unique($errors));
    }

    private function clampPage(int $page, int $perPage, int $total): int
    {
        $lastPage = max(1, (int) ceil(max(0, $total) / max(1, $perPage)));

        return min(max(1, $page), $lastPage);
    }

    private function queryScalar(Request $request, string $key, mixed $default = null): mixed
    {
        $value = $request->query($key, $default);

        return is_scalar($value) ? $value : $default;
    }

}
