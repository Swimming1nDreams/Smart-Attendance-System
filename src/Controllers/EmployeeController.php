<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Application;
use App\Core\Request;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeProfile;
use App\Models\Position;
use App\Services\AccountManagementService;
use App\Services\AccountSecurityException;
use App\Services\AuditLogService;
use App\Services\EmailVerificationService;
use App\Services\EmployeeValidationService;
use App\Services\PasswordPolicyException;
use App\Services\PasswordPolicyService;
use App\Services\ProfilePhotoStorageService;
use App\Services\WorkScheduleResolverService;

class EmployeeController extends MasterDataController
{
    private Employee $employeeModel;
    private EmployeeProfile $employeeProfileModel;
    private Department $departmentModel;
    private Position $positionModel;
    private EmailVerificationService $emailVerificationService;
    private AccountManagementService $accountManagementService;
    private EmployeeValidationService $employeeValidationService;
    private PasswordPolicyService $passwordPolicy;
    private ?WorkScheduleResolverService $scheduleResolver;
    private ?AuditLogService $auditLog;
    private ?ProfilePhotoStorageService $profilePhotoStorage;

    public function __construct(Employee $employeeModel, EmployeeProfile $employeeProfileModel, Department $departmentModel, Position $positionModel, EmailVerificationService $emailVerificationService, AccountManagementService $accountManagementService, EmployeeValidationService $employeeValidationService, PasswordPolicyService $passwordPolicy, \App\Core\SessionManager $sessionManager, Application $app, ?WorkScheduleResolverService $scheduleResolver = null, ?AuditLogService $auditLog = null, ?ProfilePhotoStorageService $profilePhotoStorage = null)
    {
        parent::__construct($sessionManager, $app);
        $this->employeeModel = $employeeModel;
        $this->employeeProfileModel = $employeeProfileModel;
        $this->departmentModel = $departmentModel;
        $this->positionModel = $positionModel;
        $this->emailVerificationService = $emailVerificationService;
        $this->accountManagementService = $accountManagementService;
        $this->employeeValidationService = $employeeValidationService;
        $this->passwordPolicy = $passwordPolicy;
        $this->scheduleResolver = $scheduleResolver;
        $this->auditLog = $auditLog;
        $this->profilePhotoStorage = $profilePhotoStorage;
    }

    public function index(Request $request): void
    {
        $this->ensureAdminOrDepartmentHead();
        $currentRole = $this->sessionManager->getUserRole();
        
        $scopeFilters = $this->resolveScopeFilters($request);
        $forcedDepartmentId = $scopeFilters['department_id'];

        $search = trim((string) $request->query('search', ''));
        $departmentIdInput = $this->parseOptionalInt($request->query('department_id'));
        $departmentId = $forcedDepartmentId ?? $departmentIdInput;
        
        $positionId = $this->parseOptionalInt($request->query('position_id'));
        $role = trim((string) $request->query('role', ''));
        if (!in_array($role, ['admin', 'department_head', 'employee'], true)) {
            $role = '';
        }
        if ($currentRole === 'department_head') {
            $role = 'employee';
        }
        $status = trim((string) $request->query('status', ''));
        if (!in_array($status, ['active', 'inactive'], true)) {
            $status = '';
        }

        $requestedPage = max(1, (int) $request->query('page', 1));
        $requestedPerPage = (int) $request->query('per_page', 20);
        $perPage = in_array($requestedPerPage, [20, 50, 100], true) ? $requestedPerPage : 20;

        $total = $this->employeeModel->countAll($search, $departmentId, $positionId, $status, $role);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($requestedPage, $pages);
        $employees = $this->employeeModel->findAll($search, $departmentId, $positionId, $status, $page, $perPage, $role);
        $departments = $this->departmentModel->findAll('');
        $departments = $this->filterAllowedDepartments($departments, $forcedDepartmentId);
        $positions = $this->positionModel->findAll('');

        $this->render('employees/index', [
            'employees' => $employees,
            'departments' => $departments,
            'positions' => $positions,
            'search' => $search,
            'departmentId' => $departmentId,
            'positionId' => $positionId,
            'status' => $status,
            'role' => $role,
            'canManageUsers' => $currentRole === 'admin',
            'departmentScopeLocked' => $currentRole === 'department_head',
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
            'title' => $this->t('employees_module.title'),
        ]);
    }

    public function create(Request $request): void
    {
        $this->ensureAdmin();

        $this->render('employees/form', [
            'errors' => [],
            'employee' => ['name' => '', 'email' => '', 'password' => '', 'role' => 'employee', 'department_id' => '', 'position_id' => '', 'status' => 'active', 'staff_code' => '', 'employment_type' => 'full-time', 'join_date' => '', 'expected_daily_work_hours' => '8.00', 'overtime_eligible' => '1', 'send_verification_code' => '1', 'phone' => '', 'address' => ''],
            'departments' => $this->departmentModel->findAll(''),
            'positions' => $this->positionModel->findAll(''),
            'action' => '/employees/create',
            'method' => 'POST',
            'title' => $this->t('employees_module.add_title'),
        ]);
    }

    public function store(Request $request): void
    {
        $this->ensureAdmin();
        $this->validateCsrfToken($request);

        $validation = $this->employeeValidationService->validate($request->getBodyParams());
        $data = $validation['data'];
        $errors = $this->translateValidationErrors($validation['errors']);

        if (!empty($errors)) {
            $this->render('employees/form', [
                'errors' => $errors,
                'employee' => $this->buildEmployeeFormData($data),
                'departments' => $this->departmentModel->findAll(''),
                'positions' => $this->positionModel->findAll(''),
                'action' => '/employees/create',
                'method' => 'POST',
                'title' => $this->t('employees_module.add_title'),
            ]);
            return;
        }

        try {
            $userId = $this->accountManagementService->createEmployee(
                $this->buildAccountData($data),
                $this->buildProfileData($data),
                (string) $data['password'],
                (string) $data['password_confirmation'],
                $this->authenticatedUserId(),
                $this->auditContext($request),
            );

            if ($data['send_verification_code']) {
                $employee = $this->employeeModel->findById($userId);
                $emailSent = $employee !== null && $this->emailVerificationService->sendForUser($employee, false);
                $this->sessionManager->setFlash(
                    $emailSent ? 'success' : 'error',
                    $emailSent ? $this->t('flash.employee_created_verification_sent') : $this->t('flash.employee_created_verification_failed')
                );
            } else {
                $this->sessionManager->setFlash('success', $this->t('flash.employee_created_verification_deferred'));
            }
            header('Location: /employees');
            exit;
        } catch (\Throwable $exception) {
            error_log('Employee creation failed: ' . $exception::class);
            $this->sessionManager->setFlash('error', $this->t('flash.employee_create_failed'));
            header('Location: /employees');
            exit;
        }
    }

    public function show(Request $request): void
    {
        $this->ensureAdmin();

        $id = (int) $request->query('id', 0);
        $employee = $this->employeeModel->findById($id);

        if ($employee === null) {
            $this->sessionManager->setFlash('error', $this->t('flash.employee_not_found'));
            header('Location: /employees');
            exit;
        }

        $profile = $this->employeeProfileModel->findByUserId($id);
        $photoFilename = trim((string) ($profile['profile_photo_filename'] ?? ''));
        $hasProfilePhoto = ($employee['role'] ?? null) === 'employee'
            && $photoFilename !== ''
            && $this->profilePhotoStorage?->resolve($photoFilename) !== null;
        $profilePhotoUrl = $hasProfilePhoto
            ? '/profile/photo?id=' . $id . '&v=' . rawurlencode((string) ($profile['updated_at'] ?? ''))
            : null;

        $resolvedWorkPolicy = null;
        if ($this->scheduleResolver !== null) {
            try {
                $resolution = $this->scheduleResolver->resolve($id, date('Y-m-d'));
                $resolvedWorkPolicy = \App\Services\WorkPolicyDisplayPresenter::present($resolution);
            } catch (\Throwable) {
                $resolvedWorkPolicy = \App\Services\WorkPolicyDisplayPresenter::present(null);
            }
        }

        $this->render('employees/show', [
            'employee' => $employee,
            'profile' => $profile,
            'profilePhotoUrl' => $profilePhotoUrl,
            'resolvedWorkPolicy' => $resolvedWorkPolicy,
            'title' => $this->t('employees_module.details_title'),
        ]);
    }

    public function edit(Request $request): void
    {
        $this->ensureAdmin();

        $id = (int) $request->query('id', 0);
        $employee = $this->employeeModel->findById($id);

        if ($employee === null) {
            $this->sessionManager->setFlash('error', $this->t('flash.employee_not_found'));
            header('Location: /employees');
            exit;
        }

        $profile = $this->employeeProfileModel->findByUserId($id);

        $this->render('employees/form', [
            'errors' => [],
            'employee' => [
                'id' => $employee['id'],
                'name' => $employee['name'],
                'email' => $employee['email'],
                'password' => '',
                'role' => $employee['role'],
                'session_version' => $employee['session_version'] ?? '',
                'department_id' => $employee['department_id'],
                'position_id' => $employee['position_id'],
                'status' => $employee['status'],
                'staff_code' => $employee['staff_code'] ?? '',
                'employment_type' => $employee['employment_type'] ?? 'full-time',
                'join_date' => $employee['join_date'] ?? '',
                'expected_daily_work_hours' => $employee['expected_daily_work_hours'] ?? '8.00',
                'overtime_eligible' => (string) ($employee['overtime_eligible'] ?? '1'),
                'phone' => $profile['phone'] ?? '',
                'address' => $profile['address'] ?? '',
            ],
            'departments' => $this->departmentModel->findAll(''),
            'positions' => $this->positionModel->findAll(''),
            'action' => '/employees/edit?id=' . $id,
            'method' => 'POST',
            'title' => $this->t('employees_module.edit_title'),
        ]);
    }

    public function update(Request $request): void
    {
        $this->ensureAdmin();
        $this->validateCsrfToken($request);

        $id = (int) $request->query('id', 0);
        $employee = $this->employeeModel->findById($id);

        if ($employee === null) {
            $this->sessionManager->setFlash('error', $this->t('flash.employee_not_found'));
            header('Location: /employees');
            exit;
        }

        $staffCode = (string) ($employee['staff_code'] ?? '');
        $input = $request->getBodyParams();
        // Existing account email is an identity field, not a normal edit field.
        // Supply the current value for shared validation, then let the service
        // enforce the same invariant at the write boundary.
        $input['email'] = (string) $employee['email'];
        $validation = $this->employeeValidationService->validate($input, $id, $staffCode);
        $data = $validation['data'];
        $errors = $this->translateValidationErrors($validation['errors']);
        $expectedSecurityState = [
            'role' => trim((string) ($request->getBodyParams()['expected_security_role'] ?? '')),
            'status' => trim((string) ($request->getBodyParams()['expected_security_status'] ?? '')),
            'session_version' => trim((string) ($request->getBodyParams()['expected_security_session_version'] ?? '')),
        ];

        if (!empty($errors)) {
            $this->render('employees/form', [
                'errors' => $errors,
                'employee' => $this->buildEmployeeFormData($data, $id, $expectedSecurityState['session_version']),
                'departments' => $this->departmentModel->findAll(''),
                'positions' => $this->positionModel->findAll(''),
                'action' => '/employees/edit?id=' . $id,
                'method' => 'POST',
                'title' => $this->t('employees_module.edit_title'),
            ]);
            return;
        }

        try {
            $result = $this->accountManagementService->updateEmployee(
                $this->authenticatedUserId(),
                $id,
                $this->buildAccountData($data),
                $this->buildProfileData($data),
                $data['password'] !== '' ? (string) $data['password'] : null,
                $data['password'] !== '' ? (string) $data['password_confirmation'] : null,
                $this->auditContext($request),
                $expectedSecurityState,
            );

            if ($result['current_session_invalidated']) {
                $this->sessionManager->invalidateAuthenticatedSession();
                $this->sessionManager->setFlash('error', $this->t('flash.session_invalid_after_security_change'));
                header('Location: /login');
                exit;
            }

            $this->sessionManager->setFlash('success', $this->t('flash.employee_updated'));
        } catch (AccountSecurityException $exception) {
            $this->sessionManager->setFlash('error', $this->t($this->accountSecurityMessageKey($exception)));
            header('Location: /employees/edit?id=' . $id);
            exit;
        } catch (PasswordPolicyException $exception) {
            $messages = $this->translatePasswordErrors($exception->getErrorCodes());
            $this->sessionManager->setFlash('error', $messages[0] ?? $this->t('flash.employee_update_failed'));
            header('Location: /employees/edit?id=' . $id);
            exit;
        } catch (\Throwable $exception) {
            error_log('Employee update failed: ' . $exception::class);
            $this->sessionManager->setFlash('error', $this->t('flash.employee_update_failed'));
        }

        header('Location: /employees');
        exit;
    }

    public function deactivate(Request $request): void
    {
        $this->ensureAdmin();
        $this->validateCsrfToken($request);

        $id = (int) $request->query('id', 0);
        $employee = $this->employeeModel->findById($id);

        if ($employee === null) {
            $this->sessionManager->setFlash('error', $this->t('flash.employee_not_found'));
            header('Location: /employees');
            exit;
        }

        try {
            $result = $this->accountManagementService->deactivateEmployee($this->authenticatedUserId(), $id, $this->auditContext($request));
            $this->sessionManager->setFlash('success', $this->t($result['security_changed'] ? 'flash.employee_deactivated' : 'flash.employee_already_inactive'));
        } catch (AccountSecurityException $exception) {
            $this->sessionManager->setFlash('error', $this->t($this->accountSecurityMessageKey($exception)));
        } catch (\Throwable $exception) {
            error_log('Employee deactivation failed: ' . $exception::class);
            $this->sessionManager->setFlash('error', $this->t('flash.employee_deactivate_failed'));
        }

        header('Location: /employees');
        exit;
    }

    public function resetPassword(Request $request): void
    {
        $this->ensureAdmin();
        $this->validateCsrfToken($request);

        $id = (int) $request->query('id', 0);
        $employee = $this->employeeModel->findById($id);

        if ($employee === null) {
            $this->sessionManager->setFlash('error', $this->t('flash.employee_not_found'));
            header('Location: /employees');
            exit;
        }

        $password = (string) ($request->getBodyParams()['password'] ?? '');
        $passwordConfirmation = (string) ($request->getBodyParams()['password_confirmation'] ?? '');
        $expectedSessionVersion = filter_var($request->getBodyParams()['expected_session_version'] ?? null, FILTER_VALIDATE_INT);
        if ($expectedSessionVersion === false || $expectedSessionVersion <= 0) {
            $this->sessionManager->setFlash('error', $this->t('flash.employee_security_state_changed'));
            header('Location: /employees/show?id=' . $id);
            exit;
        }

        $passwordErrors = $this->translatePasswordErrors(
            $this->passwordPolicy->validate($password, $passwordConfirmation)
        );

        if ($passwordErrors !== []) {
            $this->sessionManager->setFlash('error', $passwordErrors[0]);
            header('Location: /employees/show?id=' . $id);
            exit;
        }

        try {
            $result = $this->accountManagementService->resetPassword(
                $this->authenticatedUserId(),
                $id,
                $password,
                $passwordConfirmation,
                $this->auditContext($request),
                $expectedSessionVersion,
            );

            if ($result['current_session_invalidated']) {
                $this->sessionManager->invalidateAuthenticatedSession();
                $this->sessionManager->setFlash('error', $this->t('flash.session_invalid_after_security_change'));
                header('Location: /login');
                exit;
            }

            $this->sessionManager->setFlash('success', $this->t('flash.employee_password_reset'));
        } catch (AccountSecurityException $exception) {
            $this->sessionManager->setFlash('error', $this->t($this->accountSecurityMessageKey($exception)));
        } catch (PasswordPolicyException $exception) {
            $messages = $this->translatePasswordErrors($exception->getErrorCodes());
            $this->sessionManager->setFlash('error', $messages[0] ?? $this->t('flash.employee_password_reset_failed'));
        } catch (\Throwable $exception) {
            error_log('Employee password reset failed: ' . $exception::class);
            $this->sessionManager->setFlash('error', $this->t('flash.employee_password_reset_failed'));
        }

        header('Location: /employees/show?id=' . $id);
        exit;
    }

    public function resendVerification(Request $request): void
    {
        $this->ensureAdmin();
        $this->validateCsrfToken($request);

        $id = (int) $request->query('id', 0);
        $employee = $this->employeeModel->findById($id);

        if ($employee === null) {
            $this->sessionManager->setFlash('error', $this->t('flash.employee_not_found'));
            header('Location: /employees');
            exit;
        }

        $result = $this->emailVerificationService->sendForAdminResend($employee);

        if ($result === EmailVerificationService::SEND_SENT) {
            $this->auditLog?->record(
                $this->authenticatedUserId(),
                'employee.verification_resent',
                'user',
                $id,
                'Administrator resent account email verification code',
                null,
                ['verification_code_sent' => true],
                $this->auditContext($request),
            );
            $this->sessionManager->setFlash('success', $this->t('flash.employee_verification_resent'));
        } elseif ($result === EmailVerificationService::SEND_ALREADY_VERIFIED) {
            $this->sessionManager->setFlash('success', $this->t('flash.employee_email_already_verified'));
        } elseif ($result === EmailVerificationService::SEND_COOLDOWN) {
            $this->sessionManager->setFlash('error', $this->t('flash.employee_verification_resend_cooldown'));
        } elseif ($result === EmailVerificationService::SEND_INACTIVE) {
            $this->sessionManager->setFlash('error', $this->t('flash.employee_verification_resend_inactive'));
        } else {
            $this->sessionManager->setFlash('error', $this->t('flash.employee_verification_resend_failed'));
        }

        header('Location: /employees/show?id=' . $id);
        exit;
    }

    /** @param array<string, mixed> $data */
    private function buildAccountData(array $data): array
    {
        return [
            'name' => $data['name'],
            'email' => $data['email'],
            'role' => $data['role'],
            'department_id' => $data['department_id'],
            'position_id' => $data['position_id'],
            'status' => $data['status'],
            'staff_code' => $data['staff_code'] !== '' ? $data['staff_code'] : null,
            'employment_type' => $data['employment_type'],
            'join_date' => $data['join_date'] !== '' ? $data['join_date'] : null,
            'expected_daily_work_hours' => $data['expected_daily_work_hours'],
            'overtime_eligible' => $data['overtime_eligible'],
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array{phone: ?string, address: ?string}
     */
    private function buildProfileData(array $data): array
    {
        return [
            'phone' => $data['phone'] !== '' ? $data['phone'] : null,
            'address' => $data['address'] !== '' ? $data['address'] : null,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function buildEmployeeFormData(array $data, ?int $id = null, ?string $sessionVersion = null): array
    {
        $employee = [
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => '',
            'role' => $data['role'],
            'department_id' => $data['department_id'],
            'position_id' => $data['position_id'],
            'status' => $data['status'],
            'staff_code' => $data['staff_code'],
            'employment_type' => $data['employment_type'],
            'join_date' => $data['join_date'],
            'expected_daily_work_hours' => $data['expected_daily_work_hours_value'],
            'overtime_eligible' => (string) ($data['overtime_eligible_value'] ?? ''),
            'send_verification_code' => $data['send_verification_code'] ? '1' : '0',
            'phone' => $data['phone'],
            'address' => $data['address'],
        ];

        if ($id !== null) {
            $employee['id'] = $id;
            $employee['session_version'] = $sessionVersion ?? '';
        }

        return $employee;
    }

    /** @param list<string> $errorCodes */
    private function translatePasswordErrors(array $errorCodes): array
    {
        return $this->translateValidationErrors($errorCodes);
    }

    /** @param list<string> $errorCodes */
    private function translateValidationErrors(array $errorCodes): array
    {
        return array_map(fn (string $errorCode): string => $this->t('validation.' . $errorCode), $errorCodes);
    }

    private function accountSecurityMessageKey(AccountSecurityException $exception): string
    {
        return match ($exception->reason()) {
            AccountSecurityException::SELF_DEACTIVATION => 'flash.account_self_deactivation_forbidden',
            AccountSecurityException::SELF_ROLE_CHANGE => 'flash.account_self_role_change_forbidden',
            AccountSecurityException::LAST_ACTIVE_ADMIN => 'flash.last_active_admin_required',
            AccountSecurityException::STALE_SECURITY_STATE => 'flash.employee_security_state_changed',
            AccountSecurityException::ACCOUNT_NOT_FOUND => 'flash.employee_not_found',
            default => 'flash.employee_update_failed',
        };
    }

    private function authenticatedUserId(): int
    {
        $userId = $this->sessionManager->getUserId();

        if ($userId === null || $userId <= 0) {
            throw new \RuntimeException('An authenticated user ID is required for account administration.');
        }

        return $userId;
    }

    protected function parseOptionalInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_INT);
        return $parsed === false ? null : $parsed;
    }

}
