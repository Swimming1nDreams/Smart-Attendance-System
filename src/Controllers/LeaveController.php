<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Application;
use App\Core\Controller;
use App\Core\Request;
use App\Core\SessionManager;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\Notification;
use App\Services\DateValidationService;
use App\Services\LeaveOperationException;
use App\Services\LeaveOperationService;
use DateTimeImmutable;
use DateInterval;
use App\Services\WorkScheduleResolverService;

class LeaveController extends Controller
{
    private const ALLOWED_LEAVE_TYPES = ['casual', 'medical', 'unpaid', 'other'];
    private const MAX_ADMIN_NOTE_LENGTH = 1000;

    private LeaveRequest $leaveRequestModel;
    private Holiday $holidayModel;
    private Employee $employeeModel;
    private ?Notification $notificationModel;
    private DateValidationService $dateValidator;
    private ?LeaveOperationService $leaveOperations;

    public function __construct(LeaveRequest $leaveRequestModel, Holiday $holidayModel, Employee $employeeModel, SessionManager $sessionManager, Application $app, ?Notification $notificationModel = null, ?DateValidationService $dateValidator = null, ?LeaveOperationService $leaveOperations = null, private ?WorkScheduleResolverService $scheduleResolver = null)
    {
        parent::__construct($app, $sessionManager);

        $this->leaveRequestModel = $leaveRequestModel;
        $this->holidayModel = $holidayModel;
        $this->employeeModel = $employeeModel;
        $this->notificationModel = $notificationModel;
        $this->dateValidator = $dateValidator ?? new DateValidationService();
        $this->leaveOperations = $leaveOperations;
    }

    public function index(Request $request): void
    {
        $this->sessionManager->requireRole('employee');
        $this->ensureActiveEmployee();

        $userId = $this->sessionManager->getUserId();

        if ($userId === null) {
            header('Location: /login');
            exit;
        }

        $page = max(1, (int) $request->query('page', 1));
        $requestedPerPage = (int) $request->query('per_page', 20);
        $perPage = in_array($requestedPerPage, [20, 50, 100], true) ? $requestedPerPage : 20;
        $status = $this->normalizeAllowedFilter((string) $request->query('status', ''), ['pending', 'approved', 'rejected', 'cancelled']);
        $leaveType = $this->normalizeAllowedFilter((string) $request->query('leave_type', ''), self::ALLOWED_LEAVE_TYPES);
        $fromInput = trim((string) $request->query('from', ''));
        $toInput = trim((string) $request->query('to', ''));
        $fromDate = $this->dateValidator->normalizeOptionalDate($fromInput);
        $toDate = $this->dateValidator->normalizeOptionalDate($toInput);
        $filterErrors = $this->dateFilterErrors($fromInput, $toInput, $fromDate, $toDate);

        $totalRequests = $filterErrors === [] ? $this->leaveRequestModel->countRequestsForEmployee($userId, $status, $leaveType, $fromDate, $toDate) : 0;
        $lastPage = max(1, (int) ceil($totalRequests / $perPage));
        $page = min($page, $lastPage);
        $leaveRequests = $filterErrors === [] ? $this->leaveRequestModel->findRequestsForEmployee($userId, $page, $perPage, $status, $leaveType, $fromDate, $toDate) : [];

        $this->renderView('leave/index', [
            'leaveRequests' => $leaveRequests,
            'totalRequests' => $totalRequests,
            'page' => $page,
            'perPage' => $perPage,
            'filters' => [
                'status' => $status ?? '',
                'leave_type' => $leaveType ?? '',
                'from' => $fromDate ?? '',
                'to' => $toDate ?? '',
            ],
            'title' => $this->t('leave_module.my_title'),
            'pageTitle' => $this->t('leave_module.my_title'),
            'pageDescription' => $this->t('leave_module.my_description'),
            'pageActions' => '<a href="/leave/create" class="inline-flex items-center justify-center rounded-lg bg-sky-600 px-5 py-2 text-white hover:bg-sky-700">' . htmlspecialchars($this->t('leave_module.request_leave'), ENT_QUOTES, 'UTF-8') . '</a>',
            'errors' => $filterErrors,
        ]);
    }

    public function adminIndex(Request $request): void
    {
        $this->sessionManager->requireRole('admin');

        $page = max(1, (int) $request->query('page', 1));
        $requestedPerPage = (int) $request->query('per_page', 20);
        $perPage = in_array($requestedPerPage, [20, 50, 100], true) ? $requestedPerPage : 20;
        $status = $this->normalizeAllowedFilter((string) $request->query('status', ''), ['pending', 'approved', 'rejected', 'cancelled']);
        $leaveType = $this->normalizeAllowedFilter((string) $request->query('leave_type', ''), self::ALLOWED_LEAVE_TYPES);
        $search = trim((string) $request->query('search', ''));
        $fromInput = trim((string) $request->query('from', ''));
        $toInput = trim((string) $request->query('to', ''));
        $fromDate = $this->dateValidator->normalizeOptionalDate($fromInput);
        $toDate = $this->dateValidator->normalizeOptionalDate($toInput);
        $filterErrors = $this->dateFilterErrors($fromInput, $toInput, $fromDate, $toDate);

        if ($search !== '') {
            $search = mb_substr($search, 0, 100);
        }

        $totalRequests = $filterErrors === [] ? $this->leaveRequestModel->countAll(null, $status, $leaveType, $search, $fromDate, $toDate) : 0;
        $lastPage = max(1, (int) ceil($totalRequests / $perPage));
        $page = min($page, $lastPage);
        $leaveRequests = $filterErrors === [] ? $this->leaveRequestModel->findAll(null, $status, $leaveType, $search, $fromDate, $toDate, $page, $perPage) : [];

        $this->renderView('leave/admin_index', [
            'leaveRequests' => $leaveRequests,
            'totalRequests' => $totalRequests,
            'page' => $page,
            'perPage' => $perPage,
            'filters' => [
                'status' => $status ?? '',
                'leave_type' => $leaveType ?? '',
                'search' => $search,
                'from' => $fromDate ?? '',
                'to' => $toDate ?? '',
            ],
            'title' => $this->t('leave_module.admin_title'),
            'pageTitle' => $this->t('leave_module.admin_title'),
            'pageDescription' => $this->t('leave_module.admin_description'),
            'errors' => $filterErrors,
        ]);
    }

    public function adminShow(Request $request): void
    {
        $this->sessionManager->requireRole('admin');

        $leaveId = max(0, (int) $request->query('id', 0));

        if ($leaveId <= 0) {
            $this->sessionManager->setFlash('error', $this->t('flash.leave_not_found'));
            header('Location: /admin/leave');
            exit;
        }

        $leaveRequest = $this->leaveRequestModel->findById($leaveId);

        if ($leaveRequest === null) {
            $this->sessionManager->setFlash('error', $this->t('flash.leave_not_found'));
            header('Location: /admin/leave');
            exit;
        }

        $this->renderView('leave/admin_show', [
            'leaveRequest' => $leaveRequest,
            'title' => $this->t('leave_module.details_title'),
            'pageTitle' => $this->t('leave_module.details_title'),
            'pageDescription' => $this->t('leave_module.admin_details_description'),
            'pageActions' => '<a href="/admin/leave" class="inline-flex items-center justify-center rounded-lg bg-slate-100 px-5 py-2 text-slate-700 hover:bg-slate-200">' . htmlspecialchars($this->t('leave_module.back_to_requests'), ENT_QUOTES, 'UTF-8') . '</a>',
        ]);
    }

    public function adminApprove(Request $request): void
    {
        $this->sessionManager->requireRole('admin');
        $this->validateCsrfToken($request);

        $leaveId = $this->parsePostedLeaveId($request);
        $leaveRequest = $this->findAdminActionLeaveRequest($leaveId);

        if ($leaveRequest === null) {
            $this->redirectAdminLeaveWithFlash($leaveId, 'error', $this->t('flash.leave_admin_approve_not_found'));
        }

        if (($leaveRequest['status'] ?? '') !== 'pending') {
            $this->redirectAdminLeaveWithFlash($leaveId, 'error', $this->t('flash.leave_admin_approve_pending_only'));
        }

        $employeeId = (int) ($leaveRequest['user_id'] ?? 0);
        $employee = $this->employeeModel->findById($employeeId);

        if ($employee === null || ($employee['role'] ?? '') !== 'employee' || ($employee['status'] ?? '') !== 'active') {
            $this->redirectAdminLeaveWithFlash($leaveId, 'error', $this->t('flash.leave_admin_approve_inactive_employee'));
        }

        $overlappingRequests = $this->leaveRequestModel->findOverlappingRequests(
            $employeeId,
            (string) ($leaveRequest['start_date'] ?? ''),
            (string) ($leaveRequest['end_date'] ?? ''),
            $leaveId
        );

        if ($overlappingRequests !== []) {
            $this->redirectAdminLeaveWithFlash($leaveId, 'error', $this->t('flash.leave_admin_overlap'));
        }

        $adminId = $this->sessionManager->getUserId();

        if ($adminId === null) {
            header('Location: /login');
            exit;
        }

        try {
            $this->requireLeaveOperations()->approve($leaveId, $adminId, (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'), $this->auditContext($request));
            $this->notifyEmployeeOfLeaveEvent($employeeId, $leaveId, 'leave_approved');
            $this->redirectAdminLeaveWithFlash($leaveId, 'success', $this->t('flash.leave_admin_approve_success'));
        } catch (LeaveOperationException $exception) {
            $this->redirectAdminLeaveWithFlash($leaveId, 'error', $this->leaveOperationMessage($exception->reason(), 'flash.leave_admin_approve_failed'));
        }
    }

    public function adminReject(Request $request): void
    {
        $this->sessionManager->requireRole('admin');
        $this->validateCsrfToken($request);

        $leaveId = $this->parsePostedLeaveId($request);
        $reviewNote = trim((string) ($request->getBodyParams()['review_note'] ?? ''));
        $leaveRequest = $this->findAdminActionLeaveRequest($leaveId);

        if ($leaveRequest === null) {
            $this->redirectAdminLeaveWithFlash($leaveId, 'error', $this->t('flash.leave_admin_reject_not_found'));
        }

        if (($leaveRequest['status'] ?? '') !== 'pending') {
            $this->redirectAdminLeaveWithFlash($leaveId, 'error', $this->t('flash.leave_admin_reject_pending_only'));
        }

        if ($reviewNote === '') {
            $this->redirectAdminLeaveWithFlash($leaveId, 'error', $this->t('flash.leave_admin_rejection_note_required'));
        }

        if (mb_strlen($reviewNote) > self::MAX_ADMIN_NOTE_LENGTH) {
            $this->redirectAdminLeaveWithFlash($leaveId, 'error', $this->t('flash.leave_admin_rejection_note_too_long'));
        }

        $adminId = $this->sessionManager->getUserId();

        if ($adminId === null) {
            header('Location: /login');
            exit;
        }

        try {
            $this->requireLeaveOperations()->reject($leaveId, $adminId, (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'), $reviewNote, $this->auditContext($request));
            $this->notifyEmployeeOfLeaveEvent((int) ($leaveRequest['user_id'] ?? 0), $leaveId, 'leave_rejected');
            $this->redirectAdminLeaveWithFlash($leaveId, 'success', $this->t('flash.leave_admin_reject_success'));
        } catch (LeaveOperationException $exception) {
            $this->redirectAdminLeaveWithFlash($leaveId, 'error', $this->leaveOperationMessage($exception->reason(), 'flash.leave_admin_reject_failed'));
        }
    }

    public function adminCancel(Request $request): void
    {
        $this->sessionManager->requireRole('admin');
        $this->validateCsrfToken($request);

        $leaveId = $this->parsePostedLeaveId($request);
        $leaveRequest = $this->findAdminActionLeaveRequest($leaveId);

        if ($leaveRequest === null) {
            $this->redirectAdminLeaveWithFlash($leaveId, 'error', $this->t('flash.leave_admin_cancel_not_found'));
        }

        $status = (string) ($leaveRequest['status'] ?? '');

        if (!in_array($status, ['pending', 'approved'], true)) {
            $this->redirectAdminLeaveWithFlash($leaveId, 'error', $this->t('flash.leave_admin_cancel_allowed_status'));
        }

        $adminId = $this->sessionManager->getUserId();

        if ($adminId === null) {
            header('Location: /login');
            exit;
        }

        try {
            $this->requireLeaveOperations()->cancelByAdmin($leaveId, $adminId, (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'), 'leave.cancel_reasons.admin', $this->auditContext($request));
            $this->notifyEmployeeOfLeaveEvent((int) ($leaveRequest['user_id'] ?? 0), $leaveId, 'leave_cancelled_by_admin');
            $this->redirectAdminLeaveWithFlash($leaveId, 'success', $this->t('flash.leave_admin_cancel_success'));
        } catch (LeaveOperationException $exception) {
            $this->redirectAdminLeaveWithFlash($leaveId, 'error', $this->leaveOperationMessage($exception->reason(), 'flash.leave_admin_cancel_failed'));
        }
    }

    public function create(Request $request): void
    {
        $this->sessionManager->requireRole('employee');
        $this->ensureActiveEmployee();

        $this->renderView('leave/form', [
            'errors' => [],
            'leaveRequest' => [
                'leave_type' => '',
                'start_date' => '',
                'end_date' => '',
                'reason' => '',
            ],
            'title' => $this->t('leave_module.request_title'),
            'pageTitle' => $this->t('leave_module.request_title'),
            'pageDescription' => $this->t('leave_module.request_description'),
            'pageActions' => '<a href="/leave" class="inline-flex items-center justify-center rounded-lg bg-slate-100 px-5 py-2 text-slate-700 hover:bg-slate-200">' . htmlspecialchars($this->t('leave_module.back_to_leave'), ENT_QUOTES, 'UTF-8') . '</a>',
        ]);
    }

    public function show(Request $request): void
    {
        $this->sessionManager->requireRole('employee');
        $this->ensureActiveEmployee();

        $leaveId = max(0, (int) $request->query('id', 0));

        if ($leaveId <= 0) {
            $this->sessionManager->setFlash('error', $this->t('flash.leave_not_found'));
            header('Location: /leave');
            exit;
        }

        $userId = $this->sessionManager->getUserId();

        if ($userId === null) {
            header('Location: /login');
            exit;
        }

        $leaveRequest = $this->fetchOwnedLeaveRequest($leaveId, $userId);

        if ($leaveRequest === null) {
            $this->sessionManager->setFlash('error', $this->t('flash.leave_not_found'));
            header('Location: /leave');
            exit;
        }

        $this->renderView('leave/show', [
            'leaveRequest' => $leaveRequest,
            'title' => $this->t('leave_module.details_title'),
            'pageTitle' => $this->t('leave_module.details_title'),
            'pageDescription' => $this->t('leave_module.details_description'),
            'pageActions' => '<a href="/leave" class="inline-flex items-center justify-center rounded-lg bg-slate-100 px-5 py-2 text-slate-700 hover:bg-slate-200">' . htmlspecialchars($this->t('leave_module.back_to_leave'), ENT_QUOTES, 'UTF-8') . '</a>',
        ]);
    }

    public function cancel(Request $request): void
    {
        $this->sessionManager->requireRole('employee');
        $this->ensureActiveEmployee();
        $this->validateCsrfToken($request);

        $userId = $this->sessionManager->getUserId();

        if ($userId === null) {
            header('Location: /login');
            exit;
        }

        $leaveId = max(0, (int) ($request->getBodyParams()['id'] ?? 0));

        if ($leaveId <= 0) {
            $this->sessionManager->setFlash('error', $this->t('flash.leave_cancel_not_found'));
            header('Location: /leave');
            exit;
        }

        $leaveRequest = $this->fetchOwnedLeaveRequest($leaveId, $userId);

        if ($leaveRequest === null) {
            $this->sessionManager->setFlash('error', $this->t('flash.leave_cancel_not_found'));
            header('Location: /leave');
            exit;
        }

        $status = (string) ($leaveRequest['status'] ?? '');

        if ($status !== 'pending') {
            $this->sessionManager->setFlash('error', $this->t('flash.leave_employee_cancel_pending_only'));
            header('Location: /leave');
            exit;
        }

        try {
            $this->requireLeaveOperations()->cancelByEmployee($leaveId, $userId, (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'), 'leave.cancel_reasons.employee', $this->auditContext($request));
            $this->sessionManager->setFlash('success', $this->t('flash.leave_employee_cancel_success'));
        } catch (LeaveOperationException $exception) {
            $this->sessionManager->setFlash('error', $this->leaveOperationMessage($exception->reason(), 'flash.leave_employee_cancel_failed'));
        }

        header('Location: /leave');
        exit;
    }

    public function store(Request $request): void
    {
        $this->sessionManager->requireRole('employee');
        $this->ensureActiveEmployee();
        $this->validateCsrfToken($request);

        $userId = $this->sessionManager->getUserId();

        if ($userId === null) {
            header('Location: /login');
            exit;
        }

        $leaveType = trim((string) ($request->getBodyParams()['leave_type'] ?? ''));
        $startDate = trim((string) ($request->getBodyParams()['start_date'] ?? ''));
        $endDate = trim((string) ($request->getBodyParams()['end_date'] ?? ''));
        $reason = trim((string) ($request->getBodyParams()['reason'] ?? ''));

        $errors = $this->validateLeaveRequest($userId, $leaveType, $startDate, $endDate, $reason);

        if (!empty($errors)) {
            $this->sessionManager->setFlash('error', $this->t('flash.leave_form_invalid'));
            $this->renderView('leave/form', [
                'errors' => $errors,
                'leaveRequest' => [
                    'leave_type' => $leaveType,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'reason' => $reason,
                ],
                'title' => $this->t('leave_module.request_title'),
                'pageTitle' => $this->t('leave_module.request_title'),
                'pageDescription' => $this->t('leave_module.request_description'),
                'pageActions' => '<a href="/leave" class="inline-flex items-center justify-center rounded-lg bg-slate-100 px-5 py-2 text-slate-700 hover:bg-slate-200">' . htmlspecialchars($this->t('leave_module.back_to_leave'), ENT_QUOTES, 'UTF-8') . '</a>',
            ]);
            return;
        }

        $durationDays = $this->calculateWorkingDays($startDate, $endDate, $userId);

        try {
            $createdId = $this->requireLeaveOperations()->createRequest($userId, $leaveType, $startDate, $endDate, $durationDays, $reason);
        } catch (LeaveOperationException $exception) {
            $errors = [$this->leaveOperationMessage($exception->reason(), 'flash.leave_submit_failed')];
            $this->sessionManager->setFlash('error', $this->t('flash.leave_submit_failed'));
            $this->renderView('leave/form', [
                'errors' => $errors,
                'leaveRequest' => [
                    'leave_type' => $leaveType,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'reason' => $reason,
                ],
                'title' => $this->t('leave_module.request_title'),
                'pageTitle' => $this->t('leave_module.request_title'),
                'pageDescription' => $this->t('leave_module.request_description'),
                'pageActions' => '<a href="/leave" class="inline-flex items-center justify-center rounded-lg bg-slate-100 px-5 py-2 text-slate-700 hover:bg-slate-200">' . htmlspecialchars($this->t('leave_module.back_to_leave'), ENT_QUOTES, 'UTF-8') . '</a>',
            ]);
            return;
        }

        $this->sessionManager->setFlash('success', $this->t('flash.leave_submit_success'));
        $this->notifyAdminsOfLeaveSubmission($createdId, $userId);
        header('Location: /leave');
        exit;
    }

    private function validateLeaveRequest(int $userId, string $leaveType, string $startDate, string $endDate, string $reason): array
    {
        $errors = [];

        if ($leaveType === '') {
            $errors[] = $this->t('validation.leave_type_required');
        } elseif (!in_array($leaveType, self::ALLOWED_LEAVE_TYPES, true)) {
            $errors[] = $this->t('validation.leave_type_invalid');
        }

        if ($startDate === '') {
            $errors[] = $this->t('validation.start_date_required');
        } elseif (!$this->isValidDateString($startDate)) {
            $errors[] = $this->t('validation.start_date_invalid_format');
        }

        if ($endDate === '') {
            $errors[] = $this->t('validation.end_date_required');
        } elseif (!$this->isValidDateString($endDate)) {
            $errors[] = $this->t('validation.end_date_invalid_format');
        }

        $isDateRangeValid = $this->isValidDateString($startDate) && $this->isValidDateString($endDate);

        if ($isDateRangeValid && $endDate < $startDate) {
            $errors[] = $this->t('validation.end_date_before_start');
        }

        $today = (new DateTimeImmutable('today'))->format('Y-m-d');

        if ($isDateRangeValid && $startDate < $today) {
            $errors[] = $this->t('validation.leave_past_date');
        }

        if ($isDateRangeValid && $endDate < $today) {
            $errors[] = $this->t('validation.leave_past_date');
        }

        if ($reason === '') {
            $errors[] = $this->t('validation.reason_required');
        } elseif (mb_strlen($reason) > 1000) {
            $errors[] = $this->t('validation.reason_too_long');
        }

        if ($isDateRangeValid && $endDate >= $startDate) {
            $durationDays = $this->calculateWorkingDays($startDate, $endDate, $userId);

            if ($durationDays <= 0) {
                $errors[] = $this->t('validation.leave_requires_working_day');
            }
        }

        if ($isDateRangeValid && $endDate >= $startDate && empty($errors)) {
            $overlappingRequests = $this->leaveRequestModel->findOverlappingRequests($userId, $startDate, $endDate);

            if ($overlappingRequests !== []) {
                $errors[] = $this->t('validation.leave_overlap');
            }
        }

        return $errors;
    }

    private function isValidDateString(string $date): bool
    {
        return $this->dateValidator->isValidDate($date);
    }

    private function normalizeDate(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        return $this->dateValidator->normalizeOptionalDate($value);
    }

    private function normalizeAllowedFilter(string $value, array $allowedValues): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        return in_array($value, $allowedValues, true) ? $value : null;
    }

    private function notifyAdminsOfLeaveSubmission(int $leaveId, int $employeeId): void
    {
        if ($this->notificationModel === null || $leaveId <= 0) {
            return;
        }

        $adminIds = array_map(
            static fn (array $admin): int => (int) ($admin['id'] ?? 0),
            $this->employeeModel->findByRoleAndStatus('admin', 'active', 500)
        );

        $this->notificationModel->createForUsers(
            $adminIds,
            'leave_request_submitted',
            'notifications.events.leave_request_submitted.title',
            'notifications.events.leave_request_submitted.message',
            '/admin/leave/show?id=' . $leaveId,
            $employeeId,
            $leaveId
        );
    }

    private function notifyEmployeeOfLeaveEvent(int $employeeId, int $leaveId, string $event): void
    {
        if ($this->notificationModel === null || $employeeId <= 0 || $leaveId <= 0) {
            return;
        }

        if (!in_array($event, ['leave_approved', 'leave_rejected', 'leave_cancelled_by_admin'], true)) {
            return;
        }

        $this->notificationModel->create(
            $employeeId,
            $event,
            'notifications.events.' . $event . '.title',
            'notifications.events.' . $event . '.message',
            '/leave/show?id=' . $leaveId,
            null,
            $leaveId
        );
    }

    private function parsePostedLeaveId(Request $request): int
    {
        return max(0, (int) ($request->getBodyParams()['id'] ?? 0));
    }

    private function findAdminActionLeaveRequest(int $leaveId): ?array
    {
        if ($leaveId <= 0) {
            return null;
        }

        return $this->leaveRequestModel->findById($leaveId);
    }

    private function redirectAdminLeaveWithFlash(int $leaveId, string $type, string $message): void
    {
        $this->sessionManager->setFlash($type, $message);

        if ($leaveId > 0) {
            header('Location: /admin/leave/show?id=' . $leaveId);
            exit;
        }

        header('Location: /admin/leave');
        exit;
    }

    private function ensureActiveEmployee(): void
    {
        $userId = $this->sessionManager->getUserId();

        if ($userId === null) {
            header('Location: /login');
            exit;
        }

        $employee = $this->employeeModel->findById($userId);

        if ($employee === null || ($employee['status'] ?? '') !== 'active') {
            $this->redirectInactiveAuthenticatedUser();
        }
    }

    private function fetchOwnedLeaveRequest(int $leaveId, int $userId): ?array
    {
        $leaveRequest = $this->leaveRequestModel->findById($leaveId);

        if ($leaveRequest === null) {
            return null;
        }

        return ((int) ($leaveRequest['user_id'] ?? 0)) === $userId ? $leaveRequest : null;
    }

    private function calculateWorkingDays(string $startDate, string $endDate, int $userId): int
    {
        if ($startDate === '' || $endDate === '') {
            return 0;
        }

        $start = new DateTimeImmutable($startDate);
        $end = new DateTimeImmutable($endDate);

        if ($end < $start) {
            return 0;
        }

        $workingDays = 0;
        $current = $start;

        while ($current <= $end) {
            $dateString = $current->format('Y-m-d');
            if ($this->isResolvedWorkingLeaveDate($userId, $dateString)) {
                $workingDays++;
            }

            $current = $current->add(new DateInterval('P1D'));
        }

        return $workingDays;
    }

    private function isResolvedWorkingLeaveDate(int $userId, string $dateString): bool
    {
        if ($this->scheduleResolver !== null) {
            $resolution = $this->scheduleResolver->resolve($userId, $dateString);

            return (bool) ($resolution['is_working_day'] ?? false) && !(bool) ($resolution['is_holiday'] ?? false);
        }

        $dayOfWeek = (int) (new DateTimeImmutable($dateString))->format('N');

        return $dayOfWeek < 6 && !$this->holidayModel->existsByDate($dateString);
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

    private function requireLeaveOperations(): LeaveOperationService
    {
        if ($this->leaveOperations === null) {
            throw new \LogicException('Leave operation service is required for leave writes.');
        }

        return $this->leaveOperations;
    }

    /** @return array{ip_address: string, user_agent: string} */
    private function auditContext(Request $request): array
    {
        return [
            'ip_address' => (string) $request->getServerParam('REMOTE_ADDR', ''),
            'user_agent' => (string) $request->getServerParam('HTTP_USER_AGENT', ''),
        ];
    }

    private function leaveOperationMessage(string $reason, string $fallbackKey): string
    {
        return match ($reason) {
            'overlap' => $this->t('validation.leave_overlap'),
            'attendance_conflict' => $this->t('validation.leave_attendance_conflict'),
            'concurrent_update' => $this->t('validation.concurrent_record_update'),
            'employee_invalid' => $this->t('flash.leave_admin_approve_inactive_employee'),
            'not_found' => $this->t('flash.leave_not_found'),
            default => $this->t($fallbackKey),
        };
    }

}
