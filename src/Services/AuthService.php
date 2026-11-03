<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Core\SessionManager;

class AuthService
{
    private User $userModel;
    private SessionManager $sessionManager;
    private ?string $lastLoginFailureReason = null;

    public function __construct(User $userModel, SessionManager $sessionManager)
    {
        $this->userModel = $userModel;
        $this->sessionManager = $sessionManager;
    }

    public function login(string $email, string $password): bool
    {
        $this->lastLoginFailureReason = null;
        $user = $this->userModel->findByEmail($email);

        if ($user === null) {
            $this->lastLoginFailureReason = 'invalid';
            return false;
        }

        if (!$this->userModel->verifyPassword($password, $user['password'])) {
            $this->lastLoginFailureReason = 'invalid';
            return false;
        }

        if (($user['status'] ?? '') !== 'active') {
            $this->lastLoginFailureReason = 'inactive';
            return false;
        }

        if (array_key_exists('email_verified_at', $user) && $user['email_verified_at'] === null) {
            $this->lastLoginFailureReason = 'email_unverified';
            return false;
        }

        $role = (string) ($user['role'] ?? '');
        $departmentId = isset($user['department_id']) ? (int) $user['department_id'] : null;

        if (
            !in_array($role, SessionSecurityService::AUTHENTICATED_ROLES, true)
            || ($role === 'department_head' && ($departmentId === null || $departmentId <= 0))
        ) {
            $this->lastLoginFailureReason = 'invalid';
            return false;
        }

        $this->sessionManager->regenerateSession();
        $this->sessionManager->setAuthenticatedUser(
            (int) $user['id'],
            $user['name'],
            $user['email'],
            $role,
            (int) ($user['session_version'] ?? 1),
            $departmentId
        );

        return true;
    }

    public function getLastLoginFailureReason(): ?string
    {
        return $this->lastLoginFailureReason;
    }

    public function logout(): void
    {
        $this->sessionManager->destroy();
    }

    public function isAuthenticated(): bool
    {
        return $this->sessionManager->isAuthenticated();
    }

    public function getUserId(): ?int
    {
        return $this->sessionManager->getUserId();
    }

    public function getUserName(): ?string
    {
        return $this->sessionManager->getUserName();
    }

    public function getUserRole(): ?string
    {
        return $this->sessionManager->getUserRole();
    }
}
