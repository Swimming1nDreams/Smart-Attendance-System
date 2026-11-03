<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class ProfilePhotoException extends RuntimeException
{
    public function __construct(private string $errorKey)
    {
        parent::__construct($errorKey);
    }

    public function errorKey(): string
    {
        return $this->errorKey;
    }
}
