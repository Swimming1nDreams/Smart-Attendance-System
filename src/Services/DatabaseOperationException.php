<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;
use Throwable;

final class DatabaseOperationException extends RuntimeException
{
    public function __construct(
        private string $component,
        private string $operation,
        Throwable $previous
    ) {
        parent::__construct('Database operation failed.', 0, $previous);
    }

    public static function forOperation(string $component, string $operation, Throwable $previous): self
    {
        return new self($component, $operation, $previous);
    }

    public function component(): string
    {
        return $this->component;
    }

    public function operation(): string
    {
        return $this->operation;
    }
}
