<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;

final class SessionSecurityService
{
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

        if ($userId <= 0 || $sessionVersion <= 0 || !in_array($sessionRole, ['admin', 'employee'], true)) {
            return null;
        }

        $user = $this->userModel->findAuthenticationStateById($userId);

        if ($user === null) {
            return null;
        }

        $databaseRole = (string) ($user['role'] ?? '');
        $databaseStatus = (string) ($user['status'] ?? '');
        $databaseVersion = (int) ($user['session_version'] ?? 0);

        if (
            $databaseStatus !== 'active'
            || !in_array($databaseRole, ['admin', 'employee'], true)
            || $databaseRole !== $sessionRole
            || $databaseVersion !== $sessionVersion
        ) {
            return null;
        }

        return [
            'id' => (int) $user['id'],
            'name' => (string) $user['name'],
            'email' => (string) $user['email'],
            'role' => $databaseRole,
            'session_version' => $databaseVersion,
        ];
    }
}
