<?php

declare(strict_types=1);

namespace App\Core;

class SessionManager
{
    private const LOGIN_ATTEMPTS_SESSION_KEY = 'login_attempts';
    private const PENDING_VERIFICATION_EMAIL_SESSION_KEY = 'pending_verification_email';
    private const IDLE_TIMEOUT_SECONDS = 1800;
    private const MAX_SESSION_LIFETIME_SECONDS = 28800;
    /** @var callable(array<string, mixed>): (array<string, mixed>|null)|null */
    private $authenticatedSessionValidator = null;
    private bool $authenticatedSessionValidated = false;

    public function __construct()
    {
        if (session_status() === PHP_SESSION_NONE) {
            $this->ensureWritableSessionPath();
            session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Lax']);
        }
    }

    private function ensureWritableSessionPath(): void
    {
        if (PHP_SAPI === 'cli-server') {
            $this->useFallbackSessionPath();
            return;
        }

        $currentPath = (string) ini_get('session.save_path');

        if ($currentPath !== '' && is_dir($currentPath) && is_writable($currentPath)) {
            return;
        }

        $this->useFallbackSessionPath();
    }

    private function useFallbackSessionPath(): void
    {
        $fallbackPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'sessions';

        if (!is_dir($fallbackPath)) {
            mkdir($fallbackPath, 0775, true);
        }

        if (is_dir($fallbackPath) && is_writable($fallbackPath)) {
            session_save_path($fallbackPath);
        }
    }

    public function regenerateSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public function destroy(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly'],
            );
        }

        session_destroy();
    }

    public function setAuthenticatedSessionValidator(callable $validator): void
    {
        $this->authenticatedSessionValidator = $validator;
        $this->authenticatedSessionValidated = false;
    }

    public function setAuthenticatedUser(int $id, string $name, string $email, string $role, int $sessionVersion): void
    {
        $now = time();

        $_SESSION['auth'] = [
            'id' => $id,
            'name' => $name,
            'email' => $email,
            'role' => $role,
            'session_version' => $sessionVersion,
            'authenticated_at' => $now,
            'last_activity_at' => $now,
        ];
        $this->authenticatedSessionValidated = false;
    }

    public function isAuthenticated(): bool
    {
        $status = $this->getAuthenticatedSessionStatus(false);

        if ($status['expired'] || $status['invalid']) {
            $this->invalidateAuthenticatedSession();
            return false;
        }

        return $status['authenticated'];
    }

    public function getUserId(): ?int
    {
        return isset($_SESSION['auth']['id']) ? (int) $_SESSION['auth']['id'] : null;
    }

    public function getUserName(): ?string
    {
        return $_SESSION['auth']['name'] ?? null;
    }

    public function getUserRole(): ?string
    {
        return $_SESSION['auth']['role'] ?? null;
    }

    public function setFlash(string $type, string $message): void
    {
        $_SESSION['flash'][$type] = $message;
    }

    public function getFlash(string $type): ?string
    {
        return $_SESSION['flash'][$type] ?? null;
    }

    public function consumeFlash(): array
    {
        $flash = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);

        return $flash;
    }

    public function setPendingVerificationEmail(string $email): void
    {
        $_SESSION[self::PENDING_VERIFICATION_EMAIL_SESSION_KEY] = trim($email);
    }

    public function getPendingVerificationEmail(): ?string
    {
        $email = $_SESSION[self::PENDING_VERIFICATION_EMAIL_SESSION_KEY] ?? null;

        return is_string($email) && $email !== '' ? $email : null;
    }

    public function clearPendingVerificationEmail(): void
    {
        unset($_SESSION[self::PENDING_VERIFICATION_EMAIL_SESSION_KEY]);
    }

    public function requireAuthentication(): void
    {
        $status = $this->getAuthenticatedSessionStatus(true);

        if ($status['expired']) {
            $this->invalidateAuthenticatedSession();
            $this->setFlash('error', Localization::current()->translate('flash.session_expired'));
            header('Location: /login');
            exit;
        }

        if ($status['invalid']) {
            $this->invalidateAuthenticatedSession();
            $this->setFlash('error', Localization::current()->translate('flash.session_invalid'));
            header('Location: /login');
            exit;
        }

        if (!$status['authenticated']) {
            header('Location: /login');
            exit;
        }
    }

    public function requireRole(string $role): void
    {
        $this->requireAuthentication();

        if ($this->getUserRole() !== $role) {
            header('Location: /access-denied');
            exit;
        }
    }

    public function generateCsrfToken(): string
    {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }

    public function getCsrfToken(): string
    {
        return $_SESSION['csrf_token'] ?? '';
    }

    public function validateCsrfToken(?string $token): bool
    {
        if ($token === null || $token === '') {
            return false;
        }

        $sessionToken = $_SESSION['csrf_token'] ?? '';

        if ($sessionToken === '') {
            return false;
        }

        return hash_equals($sessionToken, $token);
    }

    public function regenerateCsrfToken(): void
    {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    public function invalidateAuthenticatedSession(): void
    {
        unset($_SESSION['auth'], $_SESSION['csrf_token']);
        $this->authenticatedSessionValidated = false;
        $this->regenerateSession();
    }

    public function getLoginAttemptStatus(string $email, string $ipAddress, int $windowSeconds): array
    {
        $this->cleanupExpiredLoginAttempts($windowSeconds);

        $attemptKey = $this->buildLoginAttemptKey($email, $ipAddress);
        $attempt = $_SESSION[self::LOGIN_ATTEMPTS_SESSION_KEY][$attemptKey] ?? null;
        $now = time();

        if (!is_array($attempt)) {
            return [
                'identifier' => $attemptKey,
                'failed_attempts' => 0,
                'last_failed_attempt_at' => null,
                'locked_until' => null,
                'is_locked' => false,
                'remaining_lock_seconds' => 0,
            ];
        }

        $lockedUntil = isset($attempt['locked_until']) ? (int) $attempt['locked_until'] : 0;

        return [
            'identifier' => $attemptKey,
            'failed_attempts' => (int) ($attempt['failed_attempts'] ?? 0),
            'last_failed_attempt_at' => isset($attempt['last_failed_attempt_at']) ? (int) $attempt['last_failed_attempt_at'] : null,
            'locked_until' => $lockedUntil > 0 ? $lockedUntil : null,
            'is_locked' => $lockedUntil > $now,
            'remaining_lock_seconds' => $lockedUntil > $now ? $lockedUntil - $now : 0,
        ];
    }

    public function recordFailedLoginAttempt(string $email, string $ipAddress, int $maxAttempts, int $windowSeconds, int $lockSeconds): array
    {
        $attemptStatus = $this->getLoginAttemptStatus($email, $ipAddress, $windowSeconds);
        $attemptKey = $attemptStatus['identifier'];
        $failedAttempts = (int) $attemptStatus['failed_attempts'] + 1;
        $now = time();
        $lockedUntil = $failedAttempts >= $maxAttempts ? $now + $lockSeconds : null;

        $_SESSION[self::LOGIN_ATTEMPTS_SESSION_KEY][$attemptKey] = [
            'failed_attempts' => $failedAttempts,
            'last_failed_attempt_at' => $now,
            'locked_until' => $lockedUntil,
        ];

        return $this->getLoginAttemptStatus($email, $ipAddress, $windowSeconds);
    }

    public function clearLoginAttemptStatus(string $email, string $ipAddress): void
    {
        $attemptKey = $this->buildLoginAttemptKey($email, $ipAddress);

        unset($_SESSION[self::LOGIN_ATTEMPTS_SESSION_KEY][$attemptKey]);

        if (empty($_SESSION[self::LOGIN_ATTEMPTS_SESSION_KEY])) {
            unset($_SESSION[self::LOGIN_ATTEMPTS_SESSION_KEY]);
        }
    }

    private function cleanupExpiredLoginAttempts(int $windowSeconds): void
    {
        $attempts = $_SESSION[self::LOGIN_ATTEMPTS_SESSION_KEY] ?? null;

        if (!is_array($attempts) || $attempts === []) {
            return;
        }

        $now = time();

        foreach ($attempts as $attemptKey => $attempt) {
            if (!is_array($attempt)) {
                unset($attempts[$attemptKey]);
                continue;
            }

            $lastFailedAttemptAt = isset($attempt['last_failed_attempt_at']) ? (int) $attempt['last_failed_attempt_at'] : 0;
            $lockedUntil = isset($attempt['locked_until']) ? (int) $attempt['locked_until'] : 0;

            if (($lockedUntil > 0 && $lockedUntil <= $now) || ($lockedUntil === 0 && $lastFailedAttemptAt > 0 && ($lastFailedAttemptAt + $windowSeconds) <= $now)) {
                unset($attempts[$attemptKey]);
            }
        }

        if ($attempts === []) {
            unset($_SESSION[self::LOGIN_ATTEMPTS_SESSION_KEY]);
            return;
        }

        $_SESSION[self::LOGIN_ATTEMPTS_SESSION_KEY] = $attempts;
    }

    private function buildLoginAttemptKey(string $email, string $ipAddress): string
    {
        $normalizedEmail = mb_strtolower(trim($email));
        $normalizedIpAddress = trim($ipAddress);

        return hash('sha256', $normalizedEmail . '|' . $normalizedIpAddress);
    }

    private function getAuthenticatedSessionStatus(bool $touchActivity): array
    {
        if (!isset($_SESSION['auth']['id'], $_SESSION['auth']['role'], $_SESSION['auth']['name'], $_SESSION['auth']['session_version'])) {
            return [
                'authenticated' => false,
                'expired' => false,
                'invalid' => false,
            ];
        }

        $authenticatedAt = isset($_SESSION['auth']['authenticated_at']) ? (int) $_SESSION['auth']['authenticated_at'] : 0;
        $lastActivityAt = isset($_SESSION['auth']['last_activity_at']) ? (int) $_SESSION['auth']['last_activity_at'] : $authenticatedAt;
        $now = time();

        if ($authenticatedAt <= 0 || $lastActivityAt <= 0) {
            return [
                'authenticated' => false,
                'expired' => true,
                'invalid' => false,
            ];
        }

        $expiredByIdleTimeout = ($lastActivityAt + self::IDLE_TIMEOUT_SECONDS) <= $now;
        $expiredByMaximumLifetime = ($authenticatedAt + self::MAX_SESSION_LIFETIME_SECONDS) <= $now;

        if ($expiredByIdleTimeout || $expiredByMaximumLifetime) {
            return [
                'authenticated' => false,
                'expired' => true,
                'invalid' => false,
            ];
        }

        if (!$this->authenticatedSessionValidated && $this->authenticatedSessionValidator !== null) {
            $validatedUser = ($this->authenticatedSessionValidator)($_SESSION['auth']);

            if (!is_array($validatedUser)) {
                return [
                    'authenticated' => false,
                    'expired' => false,
                    'invalid' => true,
                ];
            }

            foreach (['id', 'name', 'email', 'role', 'session_version'] as $key) {
                if (array_key_exists($key, $validatedUser)) {
                    $_SESSION['auth'][$key] = $validatedUser[$key];
                }
            }

            $this->authenticatedSessionValidated = true;
        }

        if ($touchActivity) {
            $_SESSION['auth']['last_activity_at'] = $now;
        }

        return [
            'authenticated' => true,
            'expired' => false,
            'invalid' => false,
        ];
    }

}
