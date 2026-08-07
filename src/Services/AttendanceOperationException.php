<?php

declare(strict_types=1);

namespace App\Services;

use DomainException;

final class AttendanceOperationException extends DomainException
{
    public function __construct(private string $reason)
    {
        parent::__construct($reason);
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
