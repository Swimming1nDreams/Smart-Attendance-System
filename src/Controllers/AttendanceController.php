<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Application;
use App\Core\Request;
use App\Core\SessionManager;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Services\AttendanceNetworkAccessService;
use App\Services\AttendanceOperationException;
use App\Services\AttendanceOperationService;
use App\Services\AttendanceReportContextService;
use App\Services\AttendancePolicyException;
use App\Services\AttendancePolicyService;
use App\Services\DateValidationService;
use DateTimeImmutable;

class AttendanceController extends MasterDataController
{
    private Attendance $attendanceModel;
    private Holiday $holidayModel;
    private Employee $employeeModel;
    private LeaveRequest $leaveRequestModel;
    private DateValidationService $dateValidator;
    private AttendancePolicyService $attendancePolicy;
    private ?AttendanceOperationService $attendanceOperations;
    private AttendanceNetworkAccessService $attendanceNetworkAccess;

    public function __construct(Attendance $attendanceModel, Holiday $holidayModel, Employee $employeeModel, LeaveRequest $leaveRequestModel, SessionManager $sessionManager, Application $app, ?DateValidationService $dateValidator = null, ?AttendancePolicyService $attendancePolicy = null, ?AttendanceOperationService $attendanceOperations = null, private ?AttendanceReportContextService $attendanceContext = null, ?AttendanceNetworkAccessService $attendanceNetworkAccess = null)
    {
        parent::__construct($sessionManager, $app);
        $this->attendanceModel = $attendanceModel;
        $this->holidayModel = $holidayModel;
        $this->employeeModel = $employeeModel;
        $this->leaveRequestModel = $leaveRequestModel;
        $this->dateValidator = $dateValidator ?? new DateValidationService();
        $this->attendancePolicy = $attendancePolicy ?? new AttendancePolicyService($this->dateValidator);
        $this->attendanceOperations = $attendanceOperations;
        $this->attendanceNetworkAccess = $attendanceNetworkAccess ?? new AttendanceNetworkAccessService();
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
        $today = date('Y-m-d');
        $todayRecord = null;
        $todayStatus = $this->resolveAttendanceStatus($today);
        $showClockActions = false;
        $canClockIn = false;
        $canClockOut = false;
        $hasApprovedLeaveToday = false;
        $clockInBlockReason = null;

        if ($filterErrors !== []) {
            $records = [];
            $total = 0;
        } elseif ($role === 'admin') {
            $total = $this->attendanceModel->countAll($selectedEmployeeId, $search, $fromDate, $toDate);
            $page = $this->clampPage($page, $perPage, $total);
            $records = $this->attendanceModel->findAll($selectedEmployeeId, $search, $fromDate, $toDate, $page, $perPage);
            $records = $this->withOvertimeContext($records);
        } else {
            if ($userId === null) {
                header('Location: /login');
                exit;
            }

            $this->ensureActiveEmployee($userId);

            $total = $this->attendanceModel->countAll($userId, '', $fromDate, $toDate);
            $page = $this->clampPage($page, $perPage, $total);
            $records = $this->attendanceModel->findAll($userId, '', $fromDate, $toDate, $page, $perPage);
            $records = $this->withOvertimeContext($records);
            $todayRecord = $this->attendanceModel->findByUserIdAndDate($userId, $today);
            if ($todayRecord !== null) {
                $todayRecord = $this->withOvertimeContext([$todayRecord])[0];
            }
            $showClockActions = true;
            $hasApprovedLeaveToday = $this->leaveRequestModel->existsApprovedLeaveForDate($userId, $today);
            $clockInBlockReason = $this->selfClockInBlockReason($userId, $today, $todayRecord, $hasApprovedLeaveToday);
            $canClockIn = $clockInBlockReason === null;
            $canClockOut = $todayRecord !== null && $todayRecord['clock_in_time'] !== null && $todayRecord['clock_out_time'] === null;
        }

        $this->render('attendance/index', [
            'title' => $this->t('attendance.title'),
            'isAdmin' => $role === 'admin',
            'records' => $records,
            'search' => $search,
            'selectedEmployeeId' => $role === 'admin' ? $selectedEmployeeId : null,
            'fromDate' => $fromDate,
            'toDate' => $toDate,
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
            'todayRecord' => $todayRecord,
            'todayStatus' => $todayStatus,
            'showClockActions' => $showClockActions,
            'canClockIn' => $canClockIn,
            'canClockOut' => $canClockOut,
            'hasApprovedLeaveToday' => $hasApprovedLeaveToday,
            'clockInBlockReason' => $clockInBlockReason,
            'isHolidayOrWeekend' => $todayStatus !== 'present',
            'today' => $today,
            'errors' => $filterErrors,
        ]);
    }

    public function clockIn(Request $request): void
    {
        $this->sessionManager->requireRole('employee');
        $this->validateCsrfToken($request);

        $userId = $this->sessionManager->getUserId();
        if ($userId === null) {
            header('Location: /login');
            exit;
        }

        $this->ensureActiveEmployee($userId);

        if (!$this->selfAttendanceNetworkAllowed($request, $userId, 'clock_in')) {
            $this->sessionManager->setFlash('error', $this->t('flash.attendance_office_network_required'));
            header('Location: /attendance');
            exit;
        }

        $today = date('Y-m-d');
        try {
            $this->requireAttendanceOperations()->clockIn($userId, $today, date('H:i:s'), $userId, $this->auditContext($request));
            $this->sessionManager->setFlash('success', $this->t('flash.attendance_clock_in_success'));
        } catch (AttendanceOperationException $exception) {
            $this->sessionManager->setFlash('error', $this->attendanceOperationMessage($exception->reason(), 'flash.attendance_clock_in_failed'));
        }

        header('Location: /attendance');
        exit;
    }

    public function clockOut(Request $request): void
    {
        $this->sessionManager->requireRole('employee');
        $this->validateCsrfToken($request);

        $userId = $this->sessionManager->getUserId();
        if ($userId === null) {
            header('Location: /login');
            exit;
        }

        $this->ensureActiveEmployee($userId);

        if (!$this->selfAttendanceNetworkAllowed($request, $userId, 'clock_out')) {
            $this->sessionManager->setFlash('error', $this->t('flash.attendance_office_network_required'));
            header('Location: /attendance');
            exit;
        }

        $today = date('Y-m-d');
        try {
            $this->requireAttendanceOperations()->clockOut($userId, $today, date('H:i:s'), $userId, $this->auditContext($request));
            $this->sessionManager->setFlash('success', $this->t('flash.attendance_clock_out_success'));
        } catch (AttendanceOperationException $exception) {
            $this->sessionManager->setFlash('error', $this->attendanceOperationMessage($exception->reason(), 'flash.attendance_clock_out_failed'));
        }

        header('Location: /attendance');
        exit;
    }

    public function create(Request $request): void
    {
        $this->ensureAdmin();

        $this->render('attendance/form', [
            'title' => $this->t('attendance.add_record'),
            'action' => '/attendance/create',
            'method' => 'POST',
            'errors' => [],
            'attendance' => ['employee_id' => '', 'attendance_date' => '', 'clock_in_time' => '', 'clock_out_time' => '', 'status' => 'present', 'remarks' => ''],
            'employees' => $this->getAttendanceEmployees(),
            'isEdit' => false,
        ]);
    }

    public function store(Request $request): void
    {
        $this->ensureAdmin();
        $this->validateCsrfToken($request);

        $employeeId = $this->parseOptionalInt($request->getBodyParams()['employee_id'] ?? null);
        $attendanceDate = trim((string) ($request->getBodyParams()['attendance_date'] ?? ''));
        $clockInInput = trim((string) ($request->getBodyParams()['clock_in_time'] ?? ''));
        $clockOutInput = trim((string) ($request->getBodyParams()['clock_out_time'] ?? ''));
        $clockInTime = $this->dateValidator->normalizeTime($clockInInput);
        $clockOutTime = $this->dateValidator->normalizeTime($clockOutInput);
        $status = trim((string) ($request->getBodyParams()['status'] ?? 'present'));
        $remarks = trim((string) ($request->getBodyParams()['remarks'] ?? ''));

        $errors = $this->validateAttendanceData($employeeId, $attendanceDate, $clockInInput, $clockOutInput, $clockInTime, $clockOutTime, $status);

        if ($employeeId !== null && !$this->isValidAttendanceEmployee($employeeId)) {
            $errors[] = $this->t('validation.attendance_employee_invalid');
        }

        if ($employeeId !== null && $this->attendanceModel->existsByUserAndDate($employeeId, $attendanceDate)) {
            $errors[] = $this->t('validation.attendance_duplicate');
        }

        if ($employeeId !== null && $this->isApprovedLeaveAttendanceConflict($employeeId, $attendanceDate, $status)) {
            $errors[] = $this->t('validation.attendance_approved_leave_conflict');
        }

        if (!empty($errors)) {
            $this->render('attendance/form', [
                'title' => $this->t('attendance.add_record'),
                'action' => '/attendance/create',
                'method' => 'POST',
                'errors' => $errors,
                'attendance' => ['employee_id' => $employeeId, 'attendance_date' => $attendanceDate, 'clock_in_time' => $clockInTime ?? '', 'clock_out_time' => $clockOutTime ?? '', 'status' => $status, 'remarks' => $remarks],
                'employees' => $this->getAttendanceEmployees(),
                'isEdit' => false,
            ]);
            return;
        }

        try {
            $this->requireAttendanceOperations()->createRecord($employeeId, $attendanceDate, $clockInTime, $clockOutTime, $status, $remarks !== '' ? $remarks : null, $this->sessionManager->getUserId(), $this->auditContext($request));
            $this->sessionManager->setFlash('success', $this->t('flash.attendance_created'));
            header('Location: /attendance');
            exit;
        } catch (AttendanceOperationException $exception) {
            $errors[] = $this->attendanceOperationMessage($exception->reason(), 'flash.attendance_create_failed');
        }

        $this->render('attendance/form', [
            'title' => $this->t('attendance.add_record'),
            'action' => '/attendance/create',
            'method' => 'POST',
            'errors' => $errors,
            'attendance' => ['employee_id' => $employeeId, 'attendance_date' => $attendanceDate, 'clock_in_time' => $clockInInput, 'clock_out_time' => $clockOutInput, 'status' => $status, 'remarks' => $remarks],
            'employees' => $this->getAttendanceEmployees(),
            'isEdit' => false,
        ]);
    }

    public function edit(Request $request): void
    {
        $this->ensureAdmin();

        $id = (int) $request->query('id', 0);
        $attendance = $this->attendanceModel->findById($id);

        if ($attendance === null) {
            $this->sessionManager->setFlash('error', $this->t('flash.attendance_not_found'));
            header('Location: /attendance');
            exit;
        }

        $this->render('attendance/form', [
            'title' => $this->t('attendance.edit_record_title'),
            'action' => '/attendance/edit?id=' . $id,
            'method' => 'POST',
            'errors' => [],
            'attendance' => $this->attendanceFormData($attendance),
            'employees' => $this->getAttendanceEmployees((int) $attendance['user_id']),
            'isEdit' => true,
        ]);
    }

    public function update(Request $request): void
    {
        $this->ensureAdmin();
        $this->validateCsrfToken($request);

        $id = (int) $request->query('id', 0);
        $attendance = $this->attendanceModel->findById($id);

        if ($attendance === null) {
            $this->sessionManager->setFlash('error', $this->t('flash.attendance_not_found'));
            header('Location: /attendance');
            exit;
        }

        $employeeId = $this->parseOptionalInt($request->getBodyParams()['employee_id'] ?? null);
        $attendanceDate = trim((string) ($request->getBodyParams()['attendance_date'] ?? ''));
        $clockInInput = trim((string) ($request->getBodyParams()['clock_in_time'] ?? ''));
        $clockOutInput = trim((string) ($request->getBodyParams()['clock_out_time'] ?? ''));
        $clockInTime = $this->dateValidator->normalizeTime($clockInInput);
        $clockOutTime = $this->dateValidator->normalizeTime($clockOutInput);
        $status = trim((string) ($request->getBodyParams()['status'] ?? 'present'));
        $remarks = trim((string) ($request->getBodyParams()['remarks'] ?? ''));
        $freshnessToken = trim((string) ($request->getBodyParams()['attendance_freshness'] ?? ''));

        $errors = $this->validateAttendanceData($employeeId, $attendanceDate, $clockInInput, $clockOutInput, $clockInTime, $clockOutTime, $status);
        if ($freshnessToken === '') {
            $errors[] = $this->t('validation.concurrent_record_update');
        }

        if ($employeeId !== null) {
            if ($employeeId === (int) $attendance['user_id']) {
                if (!$this->isExistingAttendanceEmployee($employeeId)) {
                    $errors[] = $this->t('validation.attendance_employee_invalid');
                }
            } elseif (!$this->isValidAttendanceEmployee($employeeId)) {
                $errors[] = $this->t('validation.attendance_employee_invalid');
            }
        }

        if ($employeeId !== null && $this->attendanceModel->existsByUserAndDate($employeeId, $attendanceDate, $id)) {
            $errors[] = $this->t('validation.attendance_duplicate_edit');
        }

        if ($employeeId !== null && $this->isApprovedLeaveAttendanceConflict($employeeId, $attendanceDate, $status)) {
            $errors[] = $this->t('validation.attendance_approved_leave_conflict');
        }

        if (!empty($errors)) {
            $this->render('attendance/form', [
                'title' => $this->t('attendance.edit_record_title'),
                'action' => '/attendance/edit?id=' . $id,
                'method' => 'POST',
                'errors' => $errors,
                'attendance' => ['employee_id' => $employeeId, 'attendance_date' => $attendanceDate, 'clock_in_time' => $clockInTime ?? '', 'clock_out_time' => $clockOutTime ?? '', 'status' => $status, 'remarks' => $remarks, 'freshness_token' => $freshnessToken],
                'employees' => $this->getAttendanceEmployees($employeeId),
                'isEdit' => true,
            ]);
            return;
        }

        try {
            $this->requireAttendanceOperations()->updateRecord($id, $employeeId, $attendanceDate, $clockInTime, $clockOutTime, $status, $remarks !== '' ? $remarks : null, $this->sessionManager->getUserId(), $this->auditContext($request), $freshnessToken);
            $this->sessionManager->setFlash('success', $this->t('flash.attendance_updated'));
            header('Location: /attendance');
            exit;
        } catch (AttendanceOperationException $exception) {
            $errors[] = $this->attendanceOperationMessage($exception->reason(), 'flash.attendance_update_failed');
        }

        $this->render('attendance/form', [
            'title' => $this->t('attendance.edit_record_title'),
            'action' => '/attendance/edit?id=' . $id,
            'method' => 'POST',
            'errors' => $errors,
            'attendance' => ['employee_id' => $employeeId, 'attendance_date' => $attendanceDate, 'clock_in_time' => $clockInInput, 'clock_out_time' => $clockOutInput, 'status' => $status, 'remarks' => $remarks, 'freshness_token' => $freshnessToken],
            'employees' => $this->getAttendanceEmployees($employeeId),
            'isEdit' => true,
        ]);
    }

    public function delete(Request $request): void
    {
        $this->ensureAdmin();
        $this->validateCsrfToken($request);

        $this->sessionManager->setFlash('error', $this->t('flash.attendance_delete_not_permitted'));
        header('Location: /attendance');
        exit;
    }

    private function validateAttendanceData(?int $employeeId, string $attendanceDate, string $clockInInput, string $clockOutInput, ?string $clockInTime, ?string $clockOutTime, string $status): array
    {
        $errors = [];

        if ($employeeId === null) {
            $errors[] = $this->t('validation.employee_required');
        }

        if ($attendanceDate === '') {
            $errors[] = $this->t('validation.attendance_date_required');
        }

        if ($attendanceDate !== '' && !$this->dateValidator->isValidDate($attendanceDate)) {
            $errors[] = $this->t('validation.attendance_date_invalid');
        }

        if ($clockInInput !== '' && $clockInTime === null) {
            $errors[] = $this->t('validation.clock_in_time_invalid');
        }

        if ($clockOutInput !== '' && $clockOutTime === null) {
            $errors[] = $this->t('validation.clock_out_time_invalid');
        }

        if ($clockOutTime !== null && $clockInTime === null) {
            $errors[] = $this->t('validation.clock_in_required_for_clock_out');
        }

        $validStatuses = ['present', 'absent', 'holiday', 'weekend'];
        if (!in_array($status, $validStatuses, true)) {
            $errors[] = $this->t('validation.attendance_status_invalid');
        }

        if ($clockInTime !== null && $clockOutTime !== null && $clockOutTime === $clockInTime) {
            $errors[] = $this->t('validation.clock_out_after_clock_in');
        } elseif ($clockInTime !== null && $clockOutTime !== null && $clockOutTime > $clockInTime) {
            try {
                $this->attendancePolicy->actualWorkMinutes($clockInTime, $clockOutTime);
            } catch (AttendancePolicyException) {
                $errors[] = $this->t('validation.clock_out_after_clock_in');
            }
        }

        return $errors;
    }

    private function getAttendanceEmployees(?int $includeEmployeeId = null): array
    {
        $employees = array_values(array_filter(
            $this->employeeModel->findAll('', null, null, 'active', 1, 500),
            static fn (array $employee): bool => ($employee['role'] ?? '') === 'employee'
        ));

        if ($includeEmployeeId !== null) {
            $included = $this->employeeModel->findById($includeEmployeeId);

            if ($included !== null && ($included['role'] ?? '') === 'employee' && ($included['status'] ?? '') !== 'active') {
                $included['name'] = $included['name'] . ' (inactive)';
                $employees[] = $included;
            }
        }

        return $employees;
    }

    /** @param array<string, mixed> $attendance */
    private function attendanceFormData(array $attendance): array
    {
        return [
            'employee_id' => $attendance['user_id'],
            'attendance_date' => $attendance['attendance_date'],
            'clock_in_time' => $attendance['clock_in_time'] ?? '',
            'clock_out_time' => $attendance['clock_out_time'] ?? '',
            'status' => $attendance['status'],
            'remarks' => $attendance['remarks'] ?? '',
            'working_hours' => $attendance['working_hours'] ?? null,
            'late_minutes' => $attendance['late_minutes'] ?? null,
            'early_departure_minutes' => $attendance['early_departure_minutes'] ?? null,
            'is_missing_check_out' => $attendance['is_missing_check_out'] ?? null,
            'calculated_overtime_minutes' => $attendance['calculated_overtime_minutes'] ?? null,
            'attendance_state' => $attendance['attendance_state'] ?? null,
            'day_type' => $attendance['day_type'] ?? null,
            'freshness_token' => AttendanceOperationService::freshnessToken($attendance),
        ];
    }

    private function isExistingAttendanceEmployee(int $employeeId): bool
    {
        $employee = $this->employeeModel->findById($employeeId);

        return $employee !== null && ($employee['role'] ?? '') === 'employee';
    }

    private function isValidAttendanceEmployee(int $employeeId): bool
    {
        $employee = $this->employeeModel->findById($employeeId);

        return $employee !== null && ($employee['role'] ?? '') === 'employee' && ($employee['status'] ?? '') === 'active';
    }

    private function isApprovedLeaveAttendanceConflict(int $employeeId, string $attendanceDate, string $status): bool
    {
        if ($attendanceDate === '' || !in_array($status, ['present', 'absent'], true)) {
            return false;
        }

        return $this->leaveRequestModel->existsApprovedLeaveForDate($employeeId, $attendanceDate);
    }

    private function ensureActiveEmployee(int $userId): void
    {
        $employee = $this->employeeModel->findById($userId);

        if ($employee === null || ($employee['status'] ?? '') !== 'active') {
            $this->redirectInactiveAuthenticatedUser();
        }
    }

    private function resolveAttendanceStatus(string $attendanceDate): string
    {
        if ($this->holidayModel->existsByDate($attendanceDate)) {
            return 'holiday';
        }

        $dayOfWeek = (int) (new DateTimeImmutable($attendanceDate))->format('N');
        if ($dayOfWeek === 6 || $dayOfWeek === 7) {
            return 'weekend';
        }

        return 'present';
    }

    /** @param array<string, mixed>|null $todayRecord */
    private function selfClockInBlockReason(int $userId, string $attendanceDate, ?array $todayRecord, bool $hasApprovedLeaveToday): ?string
    {
        if ($this->attendanceOperations !== null) {
            return $this->attendanceOperations->selfClockInBlockReason($userId, $attendanceDate, $todayRecord, $hasApprovedLeaveToday);
        }

        if ($todayRecord !== null && !empty($todayRecord['clock_in_time'])) {
            return 'already_clocked_in';
        }

        return $hasApprovedLeaveToday ? 'approved_leave_conflict' : null;
    }

    private function withOvertimeContext(array $records): array
    {
        if ($this->attendanceContext !== null) {
            return $this->attendanceContext->enrich($records);
        }
        foreach ($records as &$record) {
            $attendanceDate = (string) ($record['attendance_date'] ?? '');
            $userId = (int) ($record['user_id'] ?? 0);

            $record = $this->attendancePolicy->enrichRecord(
                $record,
                $attendanceDate !== '' && $this->holidayModel->existsByDate($attendanceDate),
                $userId > 0 && $attendanceDate !== '' && $this->leaveRequestModel->existsApprovedLeaveForDate($userId, $attendanceDate)
            );
        }

        unset($record);

        return $records;
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

    private function requireAttendanceOperations(): AttendanceOperationService
    {
        if ($this->attendanceOperations === null) {
            throw new \LogicException('Attendance operation service is required for attendance writes.');
        }

        return $this->attendanceOperations;
    }

    private function selfAttendanceNetworkAllowed(Request $request, int $userId, string $operation): bool
    {
        $remoteAddress = (string) $request->getServerParam('REMOTE_ADDR', '');

        return $this->attendanceNetworkAccess->authorizeSelfAttendance(
            $remoteAddress,
            $userId,
            $operation,
            $this->auditContext($request),
        );
    }

    private function attendanceOperationMessage(string $reason, string $fallbackKey): string
    {
        return match ($reason) {
            'duplicate' => $this->t('validation.attendance_duplicate'),
            'approved_leave_conflict' => $this->t('validation.attendance_approved_leave_conflict'),
            'clock_out_before_clock_in' => $this->t('validation.clock_out_after_clock_in'),
            'attendance_duration_too_long' => $this->t('validation.attendance_duration_too_long'),
            'present_requires_time' => $this->t('validation.attendance_present_requires_time'),
            'policy_missing', 'policy_day_missing' => $this->t('validation.attendance_policy_missing'),
            'non_working_day_blocked' => $this->t('validation.attendance_non_working_day_check_in_blocked'),
            'holiday_blocked' => $this->t('validation.attendance_holiday_check_in_blocked'),
            'overnight_not_allowed' => $this->t('validation.attendance_overnight_not_allowed'),
            'already_clocked_in' => $this->t('flash.attendance_already_clocked_in'),
            'clock_in_required' => $this->t('flash.attendance_clock_out_required'),
            'already_clocked_out' => $this->t('flash.attendance_already_clocked_out'),
            'concurrent_update' => $this->t('validation.concurrent_record_update'),
            'employee_profile_missing' => $this->t('validation.attendance_employee_profile_missing'),
            'employee_invalid' => $this->t('validation.attendance_employee_invalid'),
            default => $this->t($fallbackKey),
        };
    }

}
