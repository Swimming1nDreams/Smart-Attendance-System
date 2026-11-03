<?php

declare(strict_types=1);

namespace App\Services;

final class LeaveReasonCatalog
{
    /** @var array<string, list<string>> */
    private const CATEGORIES_BY_LEAVE_TYPE = [
        'casual' => [
            'personal_matter',
            'family_matter',
            'personal_appointment',
            'urgent_personal_work',
            'short_personal_break',
            'other_casual',
        ],
        'medical' => [
            'illness',
            'medical_appointment',
            'medical_treatment',
            'recovery_rest',
            'family_medical_care',
            'other_medical',
        ],
        'unpaid' => [
            'extended_personal_matter',
            'family_matter',
            'study_education',
            'travel',
            'personal_commitment',
            'other_unpaid',
        ],
        'other' => [
            'emergency',
            'personal_circumstance',
            'official_matter',
            'special_request',
            'other',
        ],
    ];

    /** @var list<string> */
    private const LEGACY_CATEGORIES = [
        'personal',
        'medical_sick',
        'family',
        'emergency',
        'appointment',
        'travel',
        'other',
    ];

    /** @return list<string> */
    public static function leaveTypes(): array
    {
        return array_keys(self::CATEGORIES_BY_LEAVE_TYPE);
    }

    /** @return list<string> */
    public static function categoriesForLeaveType(string $leaveType): array
    {
        return self::CATEGORIES_BY_LEAVE_TYPE[$leaveType] ?? [];
    }

    public static function supportsLeaveType(string $leaveType): bool
    {
        return isset(self::CATEGORIES_BY_LEAVE_TYPE[$leaveType]);
    }

    public static function isValid(string $leaveType, string $category): bool
    {
        return in_array($category, self::categoriesForLeaveType($leaveType), true);
    }

    /** @return array<string, list<array{value: string, label: string}>> */
    public static function localizedMapping(callable $translate): array
    {
        $mapping = [];
        foreach (self::CATEGORIES_BY_LEAVE_TYPE as $leaveType => $categories) {
            $mapping[$leaveType] = array_map(
                static fn (string $category): array => [
                    'value' => $category,
                    'label' => self::label($category, $translate),
                ],
                $categories
            );
        }

        return $mapping;
    }

    public static function label(string $category, callable $translate): string
    {
        $category = trim($category);
        if ($category === '') {
            return $translate('leave_module.no_reason_category');
        }

        if (in_array($category, self::allKnownCategories(), true)) {
            return $translate('leave_module.reason_categories.' . $category);
        }

        return self::humanizeLegacyValue($category);
    }

    /** @return list<string> */
    private static function allKnownCategories(): array
    {
        $categories = self::LEGACY_CATEGORIES;
        foreach (self::CATEGORIES_BY_LEAVE_TYPE as $typeCategories) {
            $categories = array_merge($categories, $typeCategories);
        }

        return array_values(array_unique($categories));
    }

    private static function humanizeLegacyValue(string $category): string
    {
        $humanized = preg_replace('/[_-]+/u', ' ', $category) ?? $category;
        $humanized = preg_replace('/\s+/u', ' ', trim($humanized)) ?? trim($humanized);
        $humanized = mb_substr($humanized, 0, 80);

        return mb_convert_case($humanized, MB_CASE_TITLE, 'UTF-8');
    }
}
