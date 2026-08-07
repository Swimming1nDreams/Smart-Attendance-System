<?php

declare(strict_types=1);

namespace App\Services;

final class AuditLogPresenter
{
    /** @param callable(string): string $translate */
    public static function actionLabel(string $action, callable $translate): string
    {
        $action = trim($action);
        if ($action === '') {
            return self::missingValue();
        }

        $translationKey = 'audit_logs.actions.' . $action;
        $translated = $translate($translationKey);

        return $translated !== $translationKey ? $translated : self::humanizeKey($action);
    }

    /** @param callable(string): string $translate */
    public static function entityTypeLabel(string $entityType, callable $translate): string
    {
        $entityType = trim($entityType);
        if ($entityType === '') {
            return self::missingValue();
        }

        $translationKey = 'audit_logs.entity_types.' . $entityType;
        $translated = $translate($translationKey);

        return $translated !== $translationKey ? $translated : self::humanizeKey($entityType);
    }

    /** @param callable(string): string $translate */
    public static function description(string $description, callable $translate): string
    {
        $description = trim($description);
        if ($description === '') {
            return self::missingValue();
        }

        $descriptionKey = self::standardDescriptionKey($description);
        if ($descriptionKey === null) {
            return $description;
        }

        $translated = $translate($descriptionKey);

        return $translated !== $descriptionKey ? $translated : $description;
    }

    public static function humanizeKey(string $value): string
    {
        $label = str_replace(['.', '_', '-'], ' ', trim($value));

        return ucwords($label);
    }

    private static function standardDescriptionKey(string $description): ?string
    {
        return match ($description) {
            'Administrator signed in successfully' => 'audit_logs.descriptions.auth.admin_login_succeeded',
            'Employee checked in' => 'audit_logs.descriptions.attendance.checked_in',
            'Employee checked out' => 'audit_logs.descriptions.attendance.checked_out',
            'Employee self-attendance blocked outside approved office network' => 'audit_logs.descriptions.attendance.network_access_denied',
            'Excel report generated' => 'audit_logs.descriptions.report.excel_exported',
            'PDF report generated' => 'audit_logs.descriptions.report.pdf_exported',
            'Weekly schedule updated' => 'audit_logs.descriptions.work_schedule.weekly_schedule_updated',
            'Work schedule lifecycle changed' => 'audit_logs.descriptions.work_schedule.lifecycle_changed',
            default => null,
        };
    }

    private static function missingValue(): string
    {
        return '—';
    }
}
