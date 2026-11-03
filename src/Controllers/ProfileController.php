<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Application;
use App\Core\Request;
use App\Core\SessionManager;
use App\Models\Employee;
use App\Models\EmployeeProfile;
use App\Models\WorkScheduleTemplate;
use App\Services\AuditLogService;
use App\Services\EmployeeValidationService;
use App\Services\EmployeeWorkPolicyPresenter;
use App\Services\ProfilePhotoException;
use App\Services\ProfilePhotoStorageService;
use App\Services\WorkScheduleResolverService;

final class ProfileController extends MasterDataController
{
    public function __construct(
        private Employee $employeeModel,
        private EmployeeProfile $profileModel,
        private EmployeeValidationService $validationService,
        private ProfilePhotoStorageService $photoStorage,
        SessionManager $sessionManager,
        Application $app,
        private ?AuditLogService $auditLogService = null,
        private ?WorkScheduleResolverService $scheduleResolver = null,
        private ?WorkScheduleTemplate $workScheduleTemplateModel = null,
        private ?EmployeeWorkPolicyPresenter $workPolicyPresenter = null,
    ) {
        parent::__construct($sessionManager, $app);
    }

    public function show(Request $request): void
    {
        $userId = $this->requireOwnIdentity();
        $this->renderProfile($userId);
    }

    public function update(Request $request): void
    {
        $userId = $this->requireOwnIdentity();
        $this->validateCsrfToken($request);
        $validation = $this->validationService->validateContact($request->getBodyParams());

        if ($validation['errors'] !== []) {
            $this->renderProfile($userId, $validation['data'], $validation['errors']);
            return;
        }

        $oldProfile = $this->profileModel->findByUserId($userId);
        $phone = $validation['data']['phone'] !== '' ? $validation['data']['phone'] : null;
        $address = $validation['data']['address'] !== '' ? $validation['data']['address'] : null;

        if (!$this->profileModel->save($userId, $phone, $address)) {
            $this->sessionManager->setFlash('error', $this->t('flash.profile_update_failed'));
            $this->redirectToProfile();
        }

        $this->recordAudit(
            $request,
            $userId,
            'profile_contact_updated',
            ['phone' => $oldProfile['phone'] ?? null, 'address' => $oldProfile['address'] ?? null],
            ['phone' => $phone, 'address' => $address],
        );
        $this->sessionManager->setFlash('success', $this->t('flash.profile_updated'));
        $this->redirectToProfile();
    }

    public function updatePhoto(Request $request): void
    {
        $userId = $this->requireOwnIdentity();
        $this->validateCsrfToken($request);
        $oldProfile = $this->profileModel->findByUserId($userId);
        $oldFilename = trim((string) ($oldProfile['profile_photo_filename'] ?? ''));
        $newFilename = null;

        try {
            $upload = $_FILES['profile_photo'] ?? [];
            $newFilename = $this->photoStorage->store(is_array($upload) ? $upload : []);
            if (!$this->profileModel->updatePhotoFilename($userId, $newFilename)) {
                $this->photoStorage->delete($newFilename);
                throw new ProfilePhotoException('profile_photo_save_failed');
            }
        } catch (ProfilePhotoException $exception) {
            $this->sessionManager->setFlash('error', $this->t('validation.' . $exception->errorKey()));
            $this->redirectToProfile();
        }

        if ($oldFilename !== '' && $oldFilename !== $newFilename) {
            $this->photoStorage->delete($oldFilename);
        }

        $this->recordAudit($request, $userId, 'profile_photo_updated', null, ['photo' => 'present']);
        $this->sessionManager->setFlash('success', $this->t('flash.profile_photo_updated'));
        $this->redirectToProfile();
    }

    public function removePhoto(Request $request): void
    {
        $userId = $this->requireOwnIdentity();
        $this->validateCsrfToken($request);
        $profile = $this->profileModel->findByUserId($userId);
        $filename = trim((string) ($profile['profile_photo_filename'] ?? ''));

        if ($filename === '') {
            $this->sessionManager->setFlash('success', $this->t('flash.profile_photo_removed'));
            $this->redirectToProfile();
        }

        if (!$this->profileModel->updatePhotoFilename($userId, null)) {
            $this->sessionManager->setFlash('error', $this->t('flash.profile_photo_remove_failed'));
            $this->redirectToProfile();
        }

        $this->photoStorage->delete($filename);
        $this->recordAudit($request, $userId, 'profile_photo_removed', ['photo' => 'present'], ['photo' => null]);
        $this->sessionManager->setFlash('success', $this->t('flash.profile_photo_removed'));
        $this->redirectToProfile();
    }

    public function photo(Request $request): void
    {
        $this->sessionManager->requireAuthentication();
        $authorization = $this->authorizePhotoRequest($request);

        if ($authorization['status'] !== 200 || $authorization['user_id'] === null) {
            $this->renderPhotoError($authorization['status']);
            return;
        }

        $profile = $this->profileModel->findByUserId($authorization['user_id']);
        $filename = trim((string) ($profile['profile_photo_filename'] ?? ''));
        $stored = $filename !== '' ? $this->photoStorage->resolve($filename) : null;

        if ($stored === null) {
            $this->renderPhotoError(404);
            return;
        }

        header('Content-Type: ' . $stored['mime']);
        header('Content-Length: ' . (string) filesize($stored['path']));
        header('Cache-Control: private, max-age=300');
        header('X-Content-Type-Options: nosniff');
        readfile($stored['path']);
    }

    /** @return array{status: int, user_id: ?int} */
    protected function authorizePhotoRequest(Request $request): array
    {
        $authenticatedUserId = $this->sessionManager->getUserId();
        $role = $this->sessionManager->getUserRole();

        if ($authenticatedUserId === null || $authenticatedUserId <= 0) {
            return ['status' => 403, 'user_id' => null];
        }

        if (!$request->hasQuery('id')) {
            return ['status' => 200, 'user_id' => $authenticatedUserId];
        }

        $requestedId = filter_var($request->query('id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($requestedId === false) {
            return ['status' => 404, 'user_id' => null];
        }

        if ($requestedId === $authenticatedUserId) {
            return ['status' => 200, 'user_id' => $authenticatedUserId];
        }

        if ($role !== 'admin') {
            return ['status' => 403, 'user_id' => null];
        }

        $employee = $this->employeeModel->findById((int) $requestedId);
        if ($employee === null || ($employee['role'] ?? null) !== 'employee') {
            return ['status' => 404, 'user_id' => null];
        }

        return ['status' => 200, 'user_id' => (int) $requestedId];
    }

    /** @param array{phone: string, address: string}|null $formData @param list<string> $errors */
    private function renderProfile(int $userId, ?array $formData = null, array $errors = []): void
    {
        $employee = $this->employeeModel->findById($userId);
        if ($employee === null) {
            $this->sessionManager->setFlash('error', $this->t('flash.employee_not_found'));
            header('Location: /login');
            exit;
        }

        $profile = $this->profileModel->findByUserId($userId) ?? [
            'phone' => null,
            'address' => null,
            'profile_photo_filename' => null,
        ];
        $formData ??= [
            'phone' => (string) ($profile['phone'] ?? ''),
            'address' => (string) ($profile['address'] ?? ''),
        ];
        $photoFilename = trim((string) ($profile['profile_photo_filename'] ?? ''));

        $hasPhoto = $photoFilename !== '' && $this->photoStorage->resolve($photoFilename) !== null;
        $profilePhotoUrl = $hasPhoto
            ? '/profile/photo?v=' . rawurlencode((string) ($profile['updated_at'] ?? ''))
            : null;
        $resolvedWorkPolicy = $this->resolveWorkPolicy($userId, $employee);

        $this->render('profile/show', [
            'layout' => 'layouts/app',
            'title' => $this->t('profile.title'),
            'pageTitle' => $this->t('profile.title'),
            'pageDescription' => $this->t('profile.description'),
            'pageIcon' => 'user',
            'employee' => $employee,
            'profile' => $profile,
            'hasPhoto' => $hasPhoto,
            'profilePhotoUrl' => $profilePhotoUrl,
            'topbarAvatarUrl' => $profilePhotoUrl,
            'resolvedWorkPolicy' => $resolvedWorkPolicy,
            'formData' => $formData,
            'errors' => $errors,
        ]);
    }

    /** @param array<string, mixed> $employee @return array<string, mixed> */
    private function resolveWorkPolicy(int $userId, array $employee): array
    {
        if ($this->scheduleResolver === null || $this->workScheduleTemplateModel === null || $this->workPolicyPresenter === null) {
            return $this->workPolicyPresenter?->unavailable() ?? ['available' => false];
        }

        try {
            $resolution = $this->scheduleResolver->resolve($userId, date('Y-m-d'));
            $template = $this->workScheduleTemplateModel->findWithDays((int) ($resolution['policy_version_id'] ?? 0));
            if ($template === null) {
                return $this->workPolicyPresenter->unavailable();
            }

            return $this->workPolicyPresenter->present($resolution, $template, $employee, fn (string $key): string => $this->t($key));
        } catch (\Throwable) {
            return $this->workPolicyPresenter->unavailable();
        }
    }

    private function requireOwnIdentity(): int
    {
        $this->sessionManager->requireAuthentication();
        $userId = $this->sessionManager->getUserId();

        if ($userId === null || $userId <= 0) {
            header('Location: /login');
            exit;
        }

        return $userId;
    }

    public function changePassword(Request $request): void
    {
        $userId = $this->requireOwnIdentity();
        $employee = $this->employeeModel->findById($userId);
        if ($employee === null) {
            header('Location: /login');
            exit;
        }

        $this->render('profile/password', [
            'layout' => 'layouts/app',
            'title' => $this->t('profile.change_password'),
            'pageTitle' => $this->t('profile.change_password'),
            'pageDescription' => $this->t('profile.change_password_desc'),
            'pageIcon' => 'key',
            'employee' => $employee,
            'errors' => [],
        ]);
    }

    public function updatePassword(Request $request): void
    {
        $userId = $this->requireOwnIdentity();
        $this->validateCsrfToken($request);
        $employee = $this->employeeModel->findById($userId);
        if ($employee === null) {
            header('Location: /login');
            exit;
        }

        $params = $request->getBodyParams();
        $currentPassword = (string) ($params['current_password'] ?? '');
        $newPassword = (string) ($params['new_password'] ?? '');
        $confirmPassword = (string) ($params['confirm_password'] ?? '');

        $errors = [];
        if ($currentPassword === '') {
            $errors['current_password'] = $this->t('validation.current_password_required');
        }
        if ($newPassword === '') {
            $errors['new_password'] = $this->t('validation.new_password_required');
        }

        $userModel = new \App\Models\User($this->employeeModel->getConnection());
        $userState = $userModel->findByEmail((string) $employee['email']);
        if ($currentPassword !== '' && ($userState === null || !$userModel->verifyPassword($currentPassword, (string) $userState['password']))) {
            $errors['current_password'] = $this->t('validation.current_password_invalid');
        }

        if ($newPassword !== $confirmPassword) {
            $errors['confirm_password'] = $this->t('validation.password_confirmation_mismatch');
        }

        if ($errors === []) {
            try {
                $passwordPolicy = new \App\Services\PasswordPolicyService();
                $passwordPolicy->assertValid($newPassword, $confirmPassword);
            } catch (\App\Services\PasswordPolicyException $e) {
                $errorCodes = $e->getErrorCodes();
                $errors['new_password'] = $this->t('validation.' . ($errorCodes[0] ?? 'password_invalid'));
            }
        }

        if ($errors !== []) {
            $this->render('profile/password', [
                'layout' => 'layouts/app',
                'title' => $this->t('profile.change_password'),
                'pageTitle' => $this->t('profile.change_password'),
                'pageDescription' => $this->t('profile.change_password_desc'),
                'pageIcon' => 'key',
                'employee' => $employee,
                'errors' => $errors,
            ]);
            return;
        }

        if (!$this->employeeModel->resetPassword($userId, $newPassword)) {
            $this->sessionManager->setFlash('error', $this->t('flash.password_update_failed'));
            header('Location: /profile/password');
            exit;
        }

        $this->recordAudit($request, $userId, 'profile_password_updated', null, null);
        $this->sessionManager->setFlash('success', $this->t('flash.password_updated'));
        
        $this->sessionManager->invalidateAuthenticatedSession();
        header('Location: /login');
        return;
    }

    /** @param array<string, mixed>|null $oldValues @param array<string, mixed>|null $newValues */
    private function recordAudit(Request $request, int $userId, string $action, ?array $oldValues, ?array $newValues): void
    {
        $this->auditLogService?->record(
            $userId,
            $action,
            'employee_profile',
            $userId,
            'Employee updated their own profile.',
            $oldValues,
            $newValues,
            $this->auditContext($request),
            false,
        );
    }

    private function redirectToProfile(): never
    {
        header('Location: /profile');
        exit;
    }

    private function renderPhotoError(int $status): void
    {
        http_response_code($status);
        header('Content-Type: text/plain; charset=UTF-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo $this->t($status === 403 ? 'profile.photo_access_denied' : 'profile.photo_not_found');
    }
}
