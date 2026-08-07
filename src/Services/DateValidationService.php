<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;

final class DateValidationService
{
    public const DATE_FORMAT = 'Y-m-d';
    public const MONTH_FORMAT = 'Y-m';

    public function isValidDate(string $value): bool
    {
        return $this->isValid($value, self::DATE_FORMAT);
    }

    public function isValidMonth(string $value): bool
    {
        return $this->isValid($value, self::MONTH_FORMAT);
    }

    public function isValid(string $value, string $format): bool
    {
        if ($value === '') {
            return false;
        }

        $date = DateTimeImmutable::createFromFormat('!' . $format, $value);
        $errors = DateTimeImmutable::getLastErrors();

        return $date instanceof DateTimeImmutable
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            && $date->format($format) === $value;
    }

    public function normalizeOptionalDate(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        return $this->isValidDate($value) ? $value : null;
    }

    public function isValidRange(?string $fromDate, ?string $toDate): bool
    {
        return $fromDate === null || $toDate === null || $fromDate <= $toDate;
    }

    public function normalizeTime(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        foreach (['H:i:s', 'H:i'] as $format) {
            $time = DateTimeImmutable::createFromFormat('!' . $format, $value);
            $errors = DateTimeImmutable::getLastErrors();

            if (
                $time instanceof DateTimeImmutable
                && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
                && $time->format($format) === $value
            ) {
                return $time->format('H:i:s');
            }
        }

        return null;
    }
}
