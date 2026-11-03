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
use App\Services\LeaveReasonCatalog;
use DateTimeImmutable;
use DateInterval;
use App\Services\WorkScheduleResolverService;

class LeaveController extends MasterDataController
{
    private const ALLOWED_LEAVE_TYPES = ['casual', 'medical', 'unpaid', 'other'];
    private const MAX_ADMIN_NOTE_LENGTH = 1000;

    private LeaveRequest $leaveRequestModel;
    private Holiday $holidayModel;
    private Employee $employeeModel;
    private ?Notification $notificationModel;
    private DateValidationService $dateValidator;
    private ?LeaveOperationService $leaveOperations;

    public function __construct(LeaveRequest $leaveRequestModel, Holiday $holidayModel, Employee $employeeModel, SessionManager $sessionManager, Application $app, ?Notification $notificationModel = null, ?DateValidationService $dateValidator = null, ?LeaveOperationService $leaveOperations = null, private ?WorkScheduleResolverService $scheduleResolver = null, private ?\App\Models\Department $departmentModel = null)
    {
        parent::__construct($sessionManager, $app);

        $this->leaveRequestModel = $leaveRequestModel;
        $this->holidayModel = $holidayModel;
        $this->employeeModel = $employeeModel;
        $this->notificationModel = $notificationModel;
        $this->dateValidator = $dateValidator ?? new DateValidationService();
        $this->leaveOperations = $leaveOperations;
    }

    public function index(Request $request): void
    {
        $this->sessionManager->requireAnyRole(['employee', 'department_head']);
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
        $this->ensureAdminOrDepartmentHead();

        $scopeFilters = $this->resolveScopeFilters($request);
        $selectedEmployeeId = $scopeFilters['employee_id'];
        $selectedDepartmentId = $scopeFilters['department_id'];
        $selectedEmployeeId = $this->normalizeEmployeeForDepartment($this->employeeModel, $selectedEmployeeId, $selectedDepartmentId);

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
        if ($scopeFilters['errors'] !== []) {
            $filterErrors = array_merge($filterErrors, $scopeFilters['errors']);
        }

        if ($search !== '') {
            $search = mb_substr($search, 0, 100);
        }

        $managedRequesterRole = $this->sessionManager->getUserRole() === 'department_head' ? 'employee' : null;
        $totalRequests = $filterErrors === [] ? $this->leaveRequestModel->countAll($selectedEmployeeId, $status, $leaveType, $search, $fromDate, $toDate, $selectedDepartmentId, $managedRequesterRole) : 0;
        $lastPage = max(1, (int) ceil($totalRequests / $perPage));
        $page = min($page, $lastPage);
        $leaveRequests = $filterErrors === [] ? $this->leaveRequestModel->findAll($selectedEmployeeId, $status, $leaveType, $search, $fromDate, $toDate, $page, $perPage, $selectedDepartmentId, $managedRequesterRole) : [];

        $departments = $this->departmentModel ? $this->filterAllowedDepartments($this->departmentModel->findAll(''), $selectedDepartmentId) : [];
        $employees = $this->employeeModel->findReportEmployees($selectedDepartmentId);

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
                'department_id' => $selectedDepartmentId,
                'employee_id' => $selectedEmployeeId,
            ],
            'title' => $this->t('leave_module.admin_title'),
            'pageTitle' => $this->t('leave_module.admin_title'),
            'pageDescription' => $this->t('leave_module.admin_description'),
            'errors' => $filterErrors,
            'departments' => $departments ?? [],
            'employees' => $employees ?? [],
            'departmentScopeLocked' => $this->sessionManager->getUserRole() === 'department_head',
            'canManageLeave' => in_array($this->sessionManager->getUserRole(), ['admin', 'department_head'], true),
        ]);
    }

    public function adminShow(Request $request): void
    {
        $this->ensureAdminOrDepartmentHead();

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

        if (!$this->canManageLeaveRequester((int) ($leaveRequest['user_id'] ?? 0))) {
            $this->denyOutOfScopeManagement();
        }

        $attachment = $this->leaveRequestModel->getAttachment($leaveId);

        $this->renderView('leave/admin_show', [
            'leaveRequest' => $leaveRequest,
            'attachment' => $attachment,
            'title' => $this->t('leave_module.details_title'),
            'pageTitle' => $this->t('leave_module.details_title'),
            'pageDescription' => $this->t('leave_module.admin_details_description'),
            'pageActions' => '<a href="/admin/leave" class="inline-flex items-center justify-center rounded-lg bg-slate-100 px-5 py-2 text-slate-700 hover:bg-slate-200">' . htmlspecialchars($this->t('leave_module.back_to_requests'), ENT_QUOTES, 'UTF-8') . '</a>',
        ]);
    }

    public function adminApprove(Request $request): void
    {
        $this->ensureAdminOrDepartmentHead();
        $this->validateCsrfToken($request);

        $leaveId = $this->parsePostedLeaveId($request);
        $leaveRequest = $this->findAdminActionLeaveRequest($leaveId);

        if ($leaveRequest === null) {
            $this->redirectAdminLeaveWithFlash($leaveId, 'error', $this->t('flash.leave_admin_approve_not_found'));
        }

        if (!$this->canManageLeaveRequester((int) ($leaveRequest['user_id'] ?? 0))) {
            $this->denyOutOfScopeManagement();
        }

        if (($leaveRequest['status'] ?? '') !== 'pending') {
            $this->redirectAdminLeaveWithFlash($leaveId, 'error', $this->t('flash.leave_admin_approve_pending_only'));
        }

        $employeeId = (int) ($leaveRequest['user_id'] ?? 0);
        $employee = $this->employeeModel->findById($employeeId);

        if ($employee === null || !in_array(($employee['role'] ?? ''), ['employee', 'department_head'], true) || ($employee['status'] ?? '') !== 'active') {
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

        $reviewerId = $this->sessionManager->getUserId();

        if ($reviewerId === null) {
            header('Location: /login');
            exit;
        }

        try {
            $this->requireLeaveOperations()->approve($leaveId, $reviewerId, (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'), $this->auditContext($request));
            $this->notifyEmployeeOfLeaveEvent($employeeId, $leaveId, 'leave_approved');
            $this->redirectAdminLeaveWithFlash($leaveId, 'success', $this->t('flash.leave_admin_approve_success'));
        } catch (LeaveOperationException $exception) {
            $this->redirectAdminLeaveWithFlash($leaveId, 'error', $this->leaveOperationMessage($exception->reason(), 'flash.leave_admin_approve_failed'));
        }
    }

    public function adminReject(Request $request): void
    {
        $this->ensureAdminOrDepartmentHead();
        $this->validateCsrfToken($request);

        $leaveId = $this->parsePostedLeaveId($request);
        $reviewNote = trim((string) ($request->getBodyParams()['review_note'] ?? ''));
        $leaveRequest = $this->findAdminActionLeaveRequest($leaveId);

        if ($leaveRequest === null) {
            $this->redirectAdminLeaveWithFlash($leaveId, 'error', $this->t('flash.leave_admin_reject_not_found'));
        }

        if (!$this->canManageLeaveRequester((int) ($leaveRequest['user_id'] ?? 0))) {
            $this->denyOutOfScopeManagement();
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

        $reviewerId = $this->sessionManager->getUserId();

        if ($reviewerId === null) {
            header('Location: /login');
            exit;
        }

        try {
            $this->requireLeaveOperations()->reject($leaveId, $reviewerId, (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'), $reviewNote, $this->auditContext($request));
            $this->notifyEmployeeOfLeaveEvent((int) ($leaveRequest['user_id'] ?? 0), $leaveId, 'leave_rejected');
            $this->redirectAdminLeaveWithFlash($leaveId, 'success', $this->t('flash.leave_admin_reject_success'));
        } catch (LeaveOperationException $exception) {
            $this->redirectAdminLeaveWithFlash($leaveId, 'error', $this->leaveOperationMessage($exception->reason(), 'flash.leave_admin_reject_failed'));
        }
    }

    public function adminCancel(Request $request): void
    {
        $this->ensureAdminOrDepartmentHead();
        $this->validateCsrfToken($request);

        $leaveId = $this->parsePostedLeaveId($request);
        $leaveRequest = $this->findAdminActionLeaveRequest($leaveId);

        if ($leaveRequest === null) {
            $this->redirectAdminLeaveWithFlash($leaveId, 'error', $this->t('flash.leave_admin_cancel_not_found'));
        }

        if (!$this->canManageLeaveRequester((int) ($leaveRequest['user_id'] ?? 0))) {
            $this->denyOutOfScopeManagement();
        }

        $status = (string) ($leaveRequest['status'] ?? '');

        if (!in_array($status, ['pending', 'approved'], true)) {
            $this->redirectAdminLeaveWithFlash($leaveId, 'error', $this->t('flash.leave_admin_cancel_allowed_status'));
        }

        $actorId = $this->sessionManager->getUserId();

        if ($actorId === null) {
            header('Location: /login');
            exit;
        }

        try {
            $isDepartmentHead = $this->sessionManager->getUserRole() === 'department_head';
            $cancelReason = $isDepartmentHead ? 'leave.cancel_reasons.department_head' : 'leave.cancel_reasons.admin';
            $event = $isDepartmentHead ? 'leave_cancelled_by_department_head' : 'leave_cancelled_by_admin';
            $this->requireLeaveOperations()->cancelByAdmin($leaveId, $actorId, (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'), $cancelReason, $this->auditContext($request));
            $this->notifyEmployeeOfLeaveEvent((int) ($leaveRequest['user_id'] ?? 0), $leaveId, $event);
            $this->redirectAdminLeaveWithFlash($leaveId, 'success', $this->t('flash.leave_admin_cancel_success'));
        } catch (LeaveOperationException $exception) {
            $this->redirectAdminLeaveWithFlash($leaveId, 'error', $this->leaveOperationMessage($exception->reason(), 'flash.leave_admin_cancel_failed'));
        }
    }

    public function create(Request $request): void
    {
        $this->sessionManager->requireAnyRole(['employee', 'department_head']);
        $this->ensureActiveEmployee();

        $this->renderView('leave/form', [
            'errors' => [],
            'leaveRequest' => [
                'leave_type' => '',
                'reason_category' => '',
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
        $this->sessionManager->requireAnyRole(['employee', 'department_head']);
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

        $attachment = $this->leaveRequestModel->getAttachment($leaveId);

        $this->renderView('leave/show', [
            'leaveRequest' => $leaveRequest,
            'attachment' => $attachment,
            'title' => $this->t('leave_module.details_title'),
            'pageTitle' => $this->t('leave_module.details_title'),
            'pageDescription' => $this->t('leave_module.details_description'),
            'pageActions' => '<a href="/leave" class="inline-flex items-center justify-center rounded-lg bg-slate-100 px-5 py-2 text-slate-700 hover:bg-slate-200">' . htmlspecialchars($this->t('leave_module.back_to_leave'), ENT_QUOTES, 'UTF-8') . '</a>',
        ]);
    }

    public function cancel(Request $request): void
    {
        $this->sessionManager->requireAnyRole(['employee', 'department_head']);
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
        $this->sessionManager->requireAnyRole(['employee', 'department_head']);
        $this->ensureActiveEmployee();
        $this->validateCsrfToken($request);

        $userId = $this->sessionManager->getUserId();

        if ($userId === null) {
            header('Location: /login');
            exit;
        }

        $leaveType = trim((string) ($request->getBodyParams()['leave_type'] ?? ''));
        $reasonCategory = trim((string) ($request->getBodyParams()['reason_category'] ?? ''));
        $startDate = trim((string) ($request->getBodyParams()['start_date'] ?? ''));
        $endDate = trim((string) ($request->getBodyParams()['end_date'] ?? ''));
        $reason = trim((string) ($request->getBodyParams()['reason'] ?? ''));

        $errors = $this->validateLeaveRequestFields($userId, $leaveType, $reasonCategory, $startDate, $endDate, $reason);

        if (!empty($errors)) {
            $this->sessionManager->setFlash('error', $this->t('flash.leave_form_invalid'));
            $this->renderView('leave/form', [
                'errors' => $errors,
                'leaveRequest' => [
                    'leave_type' => $leaveType,
                    'reason_category' => $reasonCategory,
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

        $attachmentData = null;
        if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] !== UPLOAD_ERR_NO_FILE) {
            $attachmentResult = $this->handleAttachmentUpload($_FILES['attachment']);
            if (isset($attachmentResult['error'])) {
                $errors[] = $attachmentResult['error'];
                $this->sessionManager->setFlash('error', $this->t('flash.leave_form_invalid'));
                $this->renderView('leave/form', [
                    'errors' => $errors,
                    'leaveRequest' => [
                        'leave_type' => $leaveType,
                        'reason_category' => $reasonCategory,
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
            $attachmentData = $attachmentResult;
        }

        try {
            $createdId = $this->requireLeaveOperations()->createRequest($userId, $leaveType, $startDate, $endDate, $durationDays, $reason, $reasonCategory, $attachmentData);
        } catch (LeaveOperationException $exception) {
            $errors = [$this->leaveOperationMessage($exception->reason(), 'flash.leave_submit_failed')];
            $this->sessionManager->setFlash('error', $this->t('flash.leave_submit_failed'));
            $this->renderView('leave/form', [
                'errors' => $errors,
                'leaveRequest' => [
                    'leave_type' => $leaveType,
                    'reason_category' => $reasonCategory,
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
        $this->notifyLeaveReviewersOfSubmission($createdId, $userId);
        header('Location: /leave');
        exit;
    }

    private function validateLeaveRequest(int $userId, string $leaveType, string $reasonCategory, string $startDate, string $endDate, string $reason): array
    {
        return $this->validateLeaveRequestFields($userId, $leaveType, $reasonCategory, $startDate, $endDate, $reason);
    }

    private function validateLeaveRequestFields(int $userId, string $leaveType, string $reasonCategory, string $startDate, string $endDate, string $reason): array
    {
        $errors = [];

        if ($leaveType === '') {
            $errors[] = $this->t('validation.leave_type_required');
        } elseif (!in_array($leaveType, self::ALLOWED_LEAVE_TYPES, true)) {
            $errors[] = $this->t('validation.leave_type_invalid');
        }

        if ($reasonCategory === '') {
            $errors[] = $this->t('validation.reason_category_required');
        } elseif (!LeaveReasonCatalog::isValid($leaveType, $reasonCategory)) {
            $errors[] = $this->t('validation.reason_category_invalid');
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

        if (trim($reason) === '') {
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

    private function notifyLeaveReviewersOfSubmission(int $leaveId, int $requesterId): void
    {
        if ($this->notificationModel === null || $leaveId <= 0) {
            return;
        }

        $reviewerIds = array_map(
            static fn (array $admin): int => (int) ($admin['id'] ?? 0),
            $this->employeeModel->findByRoleAndStatus('admin', 'active', 500)
        );

        $requester = $this->employeeModel->findById($requesterId);
        $requesterDepartmentId = (int) ($requester['department_id'] ?? 0);
        if (($requester['role'] ?? '') === 'employee' && $requesterDepartmentId > 0) {
            foreach ($this->employeeModel->findByRoleAndStatus('department_head', 'active', 500) as $departmentHead) {
                if ((int) ($departmentHead['department_id'] ?? 0) === $requesterDepartmentId) {
                    $reviewerIds[] = (int) ($departmentHead['id'] ?? 0);
                }
            }
        }

        $this->notificationModel->createForUsers(
            array_values(array_unique(array_filter($reviewerIds, static fn (int $id): bool => $id > 0))),
            'leave_request_submitted',
            'notifications.events.leave_request_submitted.title',
            'notifications.events.leave_request_submitted.message',
            '/admin/leave/show?id=' . $leaveId,
            $requesterId,
            $leaveId
        );
    }

    private function notifyEmployeeOfLeaveEvent(int $employeeId, int $leaveId, string $event): void
    {
        if ($this->notificationModel === null || $employeeId <= 0 || $leaveId <= 0) {
            return;
        }

        if (!in_array($event, ['leave_approved', 'leave_rejected', 'leave_cancelled_by_admin', 'leave_cancelled_by_department_head'], true)) {
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
    protected function auditContext(Request $request): array
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
            'authorization_denied' => $this->t('validation.department_scope_denied'),
            'not_found' => $this->t('flash.leave_not_found'),
            default => $this->t($fallbackKey),
        };
    }

    /**
     * @param array $fileData $_FILES array element
     * @return array{file_path?: string, original_name?: string, mime_type?: string, file_size?: int, error?: string}
     */
    private function handleAttachmentUpload(array $fileData): array
    {
        $uploadError = (int) ($fileData['error'] ?? UPLOAD_ERR_NO_FILE);
        if (in_array($uploadError, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            return ['error' => $this->t('validation.file_too_large')];
        }

        if ($uploadError !== UPLOAD_ERR_OK) {
            return ['error' => $this->t('validation.upload_failed')];
        }

        $size = (int) ($fileData['size'] ?? 0);
        if ($size > 5 * 1024 * 1024) {
            return ['error' => $this->t('validation.file_too_large')];
        }

        $originalName = basename(str_replace('\\', '/', (string) ($fileData['name'] ?? '')));
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'pdf'];

        if (!in_array($extension, $allowedExtensions, true)) {
            return ['error' => $this->t('validation.invalid_file_extension')];
        }

        $tmpPath = (string) ($fileData['tmp_name'] ?? '');
        if ($tmpPath === '' || !$this->isHttpUploadedFile($tmpPath)) {
            return ['error' => $this->t('validation.upload_failed')];
        }

        $actualSize = filesize($tmpPath);
        if ($actualSize === false) {
            return ['error' => $this->t('validation.upload_failed')];
        }
        if ($actualSize > 5 * 1024 * 1024) {
            return ['error' => $this->t('validation.file_too_large')];
        }
        $size = $actualSize;

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = $finfo === false ? false : finfo_file($finfo, $tmpPath);
        if ($finfo !== false) {
            finfo_close($finfo);
        }

        $allowedMimes = ['image/jpeg', 'image/png', 'application/pdf'];
        if (!is_string($mimeType) || !in_array($mimeType, $allowedMimes, true)) {
            return ['error' => $this->t('validation.invalid_file_type')];
        }

        $storageDir = dirname(__DIR__, 2) . '/storage/private/leave_attachments';
        if (!is_dir($storageDir) && !mkdir($storageDir, 0750, true) && !is_dir($storageDir)) {
            return ['error' => $this->t('validation.upload_failed')];
        }

        $storedName = bin2hex(random_bytes(16)) . '.' . $extension;
        $storedPath = $storageDir . '/' . $storedName;

        if (!$this->moveHttpUploadedFile($tmpPath, $storedPath)) {
            return ['error' => $this->t('validation.upload_failed')];
        }

        return [
            'file_path' => 'leave_attachments/' . $storedName,
            'original_name' => $originalName,
            'mime_type' => $mimeType,
            'file_size' => $size,
        ];
    }

    public function downloadAttachment(Request $request): void
    {
        if (!$this->sessionManager->isAuthenticated()) {
            http_response_code(401);
            echo 'Authentication required.';
            exit;
        }

        $userId = $this->sessionManager->getUserId();
        $role = $this->sessionManager->getUserRole();
        if ($userId === null || $role === null) {
            http_response_code(401);
            echo 'Authentication required.';
            exit;
        }

        $attachmentId = max(0, (int) $request->query('id', 0));
        if ($attachmentId <= 0) {
            http_response_code(404);
            echo 'File not found.';
            exit;
        }

        $download = $this->prepareAttachmentDownload($attachmentId, $userId, $role);
        http_response_code($download['status']);

        if ($download['status'] !== 200) {
            echo $download['message'];
            exit;
        }

        $attachment = $download['attachment'];
        $fullPath = $download['full_path'];
        $allowedMimes = ['image/jpeg', 'image/png', 'application/pdf'];
        $mimeType = in_array($attachment['mime_type'], $allowedMimes, true)
            ? $attachment['mime_type']
            : 'application/octet-stream';

        header('Content-Type: ' . $mimeType);
        header('Content-Disposition: ' . $this->buildAttachmentContentDisposition((string) $attachment['original_name']));
        header('Content-Length: ' . (string) filesize($fullPath));
        header('Cache-Control: private, no-store, max-age=0, must-revalidate');
        header('X-Content-Type-Options: nosniff');

        readfile($fullPath);
        exit;
    }

    /** @return array{status:int,message:string,full_path?:string,attachment?:array} */
    private function prepareAttachmentDownload(int $attachmentId, int $userId, string $role): array
    {
        if (!in_array($role, ['admin', 'department_head', 'employee'], true)) {
            return ['status' => 403, 'message' => 'Access denied.'];
        }

        $statement = $this->app->getDatabase()->getConnection()->prepare(
            'SELECT a.file_path, a.original_name, a.mime_type, a.file_size, lr.user_id FROM leave_request_attachments a JOIN leave_requests lr ON a.leave_request_id = lr.id WHERE a.id = :id LIMIT 1'
        );
        $statement->execute([':id' => $attachmentId]);
        $attachment = $statement->fetch();

        if ($attachment === false) {
            return ['status' => 404, 'message' => 'File not found.'];
        }

        $isAdmin = $role === 'admin';
        $isOwner = ((int) $attachment['user_id']) === $userId;
        $isDepartmentManager = $role === 'department_head'
            && $this->departmentHeadCanAccessEmployeeAttachment($userId, (int) $attachment['user_id']);

        if (!$isAdmin && !$isOwner && !$isDepartmentManager) {
            return ['status' => 403, 'message' => 'Access denied.'];
        }

        $relativePath = str_replace('\\', '/', (string) ($attachment['file_path'] ?? ''));
        if (preg_match('/\Aleave_attachments\/[A-Za-z0-9][A-Za-z0-9._-]*\z/D', $relativePath) !== 1) {
            return ['status' => 404, 'message' => 'File not found.'];
        }

        $attachmentRoot = realpath(dirname(__DIR__, 2) . '/storage/private/leave_attachments');
        $fullPath = realpath(dirname(__DIR__, 2) . '/storage/private/' . $relativePath);

        if (
            $attachmentRoot === false
            || $fullPath === false
            || !str_starts_with($fullPath, $attachmentRoot . DIRECTORY_SEPARATOR)
            || !is_file($fullPath)
        ) {
            return ['status' => 404, 'message' => 'File not found.'];
        }

        return [
            'status' => 200,
            'message' => '',
            'full_path' => $fullPath,
            'attachment' => $attachment,
        ];
    }

    private function canManageLeaveRequester(int $requesterId): bool
    {
        return $this->canManageUserInScope(
            $this->employeeModel,
            $requesterId,
            ['employee', 'department_head']
        );
    }

    private function departmentHeadCanAccessEmployeeAttachment(int $managerId, int $employeeId): bool
    {
        $statement = $this->app->getDatabase()->getConnection()->prepare(
            "SELECT 1
             FROM users manager
             JOIN users employee ON employee.id = :employee_id
             WHERE manager.id = :manager_id
               AND manager.role = 'department_head'
               AND manager.status = 'active'
               AND employee.role = 'employee'
               AND manager.department_id IS NOT NULL
               AND manager.department_id = employee.department_id
             LIMIT 1"
        );
        $statement->execute([
            ':manager_id' => $managerId,
            ':employee_id' => $employeeId,
        ]);

        return $statement->fetchColumn() !== false;
    }

    private function buildAttachmentContentDisposition(string $originalName): string
    {
        $cleanName = preg_replace('/[\x{0000}-\x{001F}\x{007F}-\x{009F}]/u', '', $originalName);
        if (!is_string($cleanName)) {
            $cleanName = 'attachment';
        }

        $cleanName = basename(str_replace('\\', '/', trim($cleanName)));
        if ($cleanName === '' || $cleanName === '.' || $cleanName === '..') {
            $cleanName = 'attachment';
        }

        $asciiFallback = preg_replace('/[^A-Za-z0-9._-]+/', '_', $cleanName);
        $asciiFallback = is_string($asciiFallback) ? trim($asciiFallback, '._-') : '';
        if ($asciiFallback === '') {
            $extension = preg_replace('/[^A-Za-z0-9]+/', '', pathinfo($cleanName, PATHINFO_EXTENSION));
            $asciiFallback = 'attachment' . ($extension !== '' ? '.' . $extension : '');
        }

        return 'inline; filename="' . $asciiFallback . '"; filename*=UTF-8\'\'' . rawurlencode($cleanName);
    }

    protected function isHttpUploadedFile(string $path): bool
    {
        return is_uploaded_file($path);
    }

    protected function moveHttpUploadedFile(string $source, string $destination): bool
    {
        return move_uploaded_file($source, $destination);
    }
}
