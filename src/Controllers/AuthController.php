<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Application;
use App\Core\Controller;
use App\Core\Request;
use App\Core\SessionManager;
use App\Services\AuthService;
use App\Services\EmailVerificationService;
use App\Services\AuditLogService;

class AuthController extends Controller
{
    private const MAX_LOGIN_ATTEMPTS = 5;
    private const LOGIN_ATTEMPT_WINDOW_SECONDS = 900;
    private const LOGIN_LOCK_DURATION_SECONDS = 900;

    private AuthService $authService;
    private EmailVerificationService $emailVerificationService;
    private array $errors = [];

    public function __construct(
        AuthService $authService,
        EmailVerificationService $emailVerificationService,
        Application $app,
        SessionManager $sessionManager,
        private ?AuditLogService $auditLog = null,
    ) {
        parent::__construct($app, $sessionManager);
        $this->authService = $authService;
        $this->emailVerificationService = $emailVerificationService;
    }

    public function showLogin(Request $request): void
    {
        $this->sessionManager->clearPendingVerificationEmail();

        if ($this->authService->isAuthenticated()) {
            $role = $this->authService->getUserRole();

            if ($role === 'admin') {
                header('Location: /admin/dashboard');
                exit;
            }

            header('Location: /employee/dashboard');
            exit;
        }

        $this->renderView('auth/login', [
            'errors' => $this->errors,
            'flash' => $this->sessionManager->consumeFlash(),
            'old' => ['email' => trim((string) ($request->getBodyParams()['email'] ?? ''))],
        ]);
    }

    public function login(Request $request): void
    {
        $this->validateCsrfToken($request);

        $email = trim((string) ($request->getBodyParams()['email'] ?? ''));
        $password = (string) ($request->getBodyParams()['password'] ?? '');
        $ipAddress = $this->resolveClientIpAddress($request);

        $this->validateCredentials($email, $password);

        if (!empty($this->errors)) {
            $this->renderView('auth/login', ['errors' => $this->errors, 'flash' => $this->sessionManager->consumeFlash(), 'old' => ['email' => $email]]);
            return;
        }

        $attemptStatus = $this->sessionManager->getLoginAttemptStatus(
            $email,
            $ipAddress,
            self::LOGIN_ATTEMPT_WINDOW_SECONDS,
        );

        if ($attemptStatus['is_locked']) {
            $this->errors[] = $this->t('validation.login_locked');
            $this->renderView('auth/login', ['errors' => $this->errors, 'flash' => $this->sessionManager->consumeFlash(), 'old' => ['email' => $email]]);
            return;
        }

        if (!$this->authService->login($email, $password)) {
            if ($this->authService->getLastLoginFailureReason() === 'email_unverified') {
                $this->sessionManager->setPendingVerificationEmail($email);
                $sent = $this->emailVerificationService->sendForEmail($email);
                $this->renderVerificationScreen($sent ? 'pending' : 'delivery_failed');
                return;
            }

            $attemptStatus = $this->sessionManager->recordFailedLoginAttempt(
                $email,
                $ipAddress,
                self::MAX_LOGIN_ATTEMPTS,
                self::LOGIN_ATTEMPT_WINDOW_SECONDS,
                self::LOGIN_LOCK_DURATION_SECONDS,
            );

            if ($attemptStatus['is_locked']) {
                $this->auditLog?->record(null, 'auth.failed_threshold', 'user_account', null, 'Repeated failed login threshold reached', null,
                    ['email' => mb_strtolower($email), 'account_locked_temporarily' => true],
                    $this->auditLog->requestContext($request->getServerParams()), false);
                $this->errors[] = $this->t('validation.login_locked');
            } else {
                $this->errors[] = $this->t('validation.login_invalid');
            }

            $this->renderView('auth/login', ['errors' => $this->errors, 'flash' => $this->sessionManager->consumeFlash(), 'old' => ['email' => $email]]);
            return;
        }

        $this->sessionManager->clearLoginAttemptStatus($email, $ipAddress);

        $role = $this->authService->getUserRole();

        if ($role === 'admin') {
            $this->auditLog?->record($this->authService->getUserId(), 'auth.admin_login_succeeded', 'user', $this->authService->getUserId(),
                'Administrator signed in successfully', null, ['role' => 'admin'],
                $this->auditLog->requestContext($request->getServerParams()), false);
            header('Location: /admin/dashboard');
            exit;
        }

        header('Location: /employee/dashboard');
        exit;
    }

    public function verifyEmail(Request $request): void
    {
        $this->validateCsrfToken($request);

        $email = $this->sessionManager->getPendingVerificationEmail();
        $code = trim((string) ($request->getBodyParams()['code'] ?? ''));
        $status = $email === null ? 'invalid' : $this->emailVerificationService->verifyCode($email, $code);

        if ($status === 'verified') {
            $this->sessionManager->clearPendingVerificationEmail();
        }

        $this->renderVerificationScreen($status);
    }

    public function resendVerification(Request $request): void
    {
        $this->validateCsrfToken($request);

        $email = $this->sessionManager->getPendingVerificationEmail();

        if ($email === null) {
            $this->renderVerificationScreen('invalid');
            return;
        }

        $sent = $this->emailVerificationService->sendForEmail($email);
        $this->renderVerificationScreen($sent ? 'resent' : 'delivery_failed');
    }

    private function renderVerificationScreen(string $status): void
    {
        $this->renderView('auth/email_verification', [
            'status' => $status,
            'layout' => 'layouts/guest',
            'expiryMinutes' => EmailVerificationService::CODE_EXPIRY_MINUTES,
        ]);
    }

    public function logout(Request $request): void
    {
        $this->validateCsrfToken($request);
        $logoutLocale = \App\Core\Localization::current()->locale();
        $this->authService->logout();
        header('Location: /login?lang=' . rawurlencode($logoutLocale));
        exit;
    }

    private function validateCredentials(string $email, string $password): void
    {
        if ($email === '') {
            $this->errors[] = $this->t('validation.email_required');
        }

        if ($password === '') {
            $this->errors[] = $this->t('validation.password_required');
        }

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->errors[] = $this->t('validation.email_invalid');
        }
    }

    private function resolveClientIpAddress(Request $request): string
    {
        $ipAddress = trim((string) $request->getServerParam('REMOTE_ADDR', 'unknown'));

        return $ipAddress !== '' ? $ipAddress : 'unknown';
    }
}
