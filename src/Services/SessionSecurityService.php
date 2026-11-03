<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;

final class SessionSecurityService
{
    public const AUTHENTICATED_ROLES = ['admin', 'department_head', 'employee'];

    public function __construct(private User $userModel)
    {
    }

    /**
     * @param array<string, mixed> $authenticatedSession
     * @return array<string, mixed>|null
     */
    public function validate(array $authenticatedSession): ?array
    {
        $userId = (int) ($authenticatedSession['id'] ?? 0);
        $sessionVersion = (int) ($authenticatedSession['session_version'] ?? 0);
        $sessionRole = (string) ($authenticatedSession['role'] ?? '');

        if ($userId <= 0 || $sessionVersion <= 0 || !in_array($sessionRole, self::AUTHENTICATED_ROLES, true)) {
            return null;
        }

        $user = $this->userModel->findAuthenticationStateById($userId);

        if ($user === null) {
            return null;
        }

        $databaseRole = (string) ($user['role'] ?? '');
        $databaseStatus = (string) ($user['status'] ?? '');
        $databaseVersion = (int) ($user['session_version'] ?? 0);
        $databaseDepartmentId = isset($user['department_id']) ? (int) $user['department_id'] : null;

        if (
            $databaseStatus !== 'active'
            || !in_array($databaseRole, self::AUTHENTICATED_ROLES, true)
            || $databaseRole !== $sessionRole
            || $databaseVersion !== $sessionVersion
            || ($databaseRole === 'department_head' && ($databaseDepartmentId === null || $databaseDepartmentId <= 0))
        ) {
            return null;
        }

        return [
            'id' => (int) $user['id'],
            'name' => (string) $user['name'],
            'email' => (string) $user['email'],
            'role' => $databaseRole,
            'department_id' => $databaseDepartmentId,
            'session_version' => $databaseVersion,
        ];
    }
}
