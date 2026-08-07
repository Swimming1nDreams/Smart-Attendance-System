<?php

declare(strict_types=1);

namespace App\Services;

use DomainException;

final class AccountSecurityException extends DomainException
{
    public const ACCOUNT_NOT_FOUND = 'account_not_found';
    public const SELF_DEACTIVATION = 'self_deactivation';
    public const SELF_ROLE_CHANGE = 'self_role_change';
    public const LAST_ACTIVE_ADMIN = 'last_active_admin';
    public const STALE_SECURITY_STATE = 'stale_security_state';

    public function __construct(private string $reason)
    {
        parent::__construct('The requested account security change is not allowed.');
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
