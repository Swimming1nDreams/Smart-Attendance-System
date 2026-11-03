<?php

declare(strict_types=1);

namespace App\Services;

final class BrandingPresenter
{
    public const LEGACY_DEFAULT_POLICY = 'SAPAS Standard Office';
    public const PREVIOUS_DEFAULT_POLICY = 'OAPAS Standard Office';
    public const DEFAULT_POLICY = 'OAAS Standard Office';

    public static function companyValue(
        string $key,
        mixed $value,
        string $officialName,
        string $acronym,
    ): string {
        $displayValue = trim((string) $value);
        $normalized = strtolower($displayValue);

        if ($key === 'company_name' && in_array($normalized, [
            'smart attendance and performance analytic system',
            'smart attendance and performance analytics system',
            'office attendance and performance analysis system',
            'office attendance and performance analytic system',
            'office attendance and performance analytics system',
        ], true)) {
            return $officialName;
        }

        if ($key === 'short_name' && in_array($normalized, ['smart attendance', 'sapas', 'oapas'], true)) {
            return $acronym;
        }

        return $displayValue;
    }

    public static function policyName(mixed $value): string
    {
        $displayValue = trim((string) $value);

        return in_array(strtolower($displayValue), [
            strtolower(self::LEGACY_DEFAULT_POLICY),
            strtolower(self::PREVIOUS_DEFAULT_POLICY),
        ], true) ? self::DEFAULT_POLICY : $displayValue;
    }

    public static function policyDescription(mixed $value): string
    {
        $displayValue = trim((string) $value);

        return in_array(strtolower($displayValue), [
            strtolower('Seeded default schedule matching the current SAPAS attendance policy.'),
            strtolower('Seeded default schedule matching the current OAPAS attendance policy.'),
        ], true)
            ? 'Seeded default schedule matching the current OAAS attendance policy.'
            : $displayValue;
    }
}
