<?php

declare(strict_types=1);

namespace App\Services;

use DomainException;

final class PasswordPolicyException extends DomainException
{
    /** @param list<string> $errorCodes */
    public function __construct(private array $errorCodes)
    {
        parent::__construct('The password does not meet the configured security policy.');
    }

    /** @return list<string> */
    public function getErrorCodes(): array
    {
        return $this->errorCodes;
    }
}
