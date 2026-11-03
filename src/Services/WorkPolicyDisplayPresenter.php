<?php

declare(strict_types=1);

namespace App\Services;

class WorkPolicyDisplayPresenter
{
    /**
     * @param array<string, mixed>|null $resolution
     * @return array<string, mixed>
     */
    public static function present(?array $resolution): array
    {
        if ($resolution === null) {
            return [
                'available' => false,
                'name' => null,
                'source_label' => null,
                'is_working_day' => false,
                'schedule_label' => null,
                'grace_label' => null,
            ];
        }

        $sourceLabel = match ($resolution['assignment_scope'] ?? '') {
            'employee' => 'Employee Override',
            'department' => 'Department',
            'company' => 'Company',
            'compatibility' => 'Default Work Policy',
            default => 'Default Work Policy',
        };

        $isWorkingDay = (bool) ($resolution['is_working_day'] ?? false);
        $scheduleLabel = 'Non-working Day';

        if ($isWorkingDay && !empty($resolution['scheduled_start_at']) && !empty($resolution['scheduled_end_at'])) {
            $start = (new \DateTimeImmutable($resolution['scheduled_start_at']))->format('g:i A');
            $end = (new \DateTimeImmutable($resolution['scheduled_end_at']))->format('g:i A');
            $scheduleLabel = $start . ' – ' . $end;
        }

        $graceMinutes = (int) ($resolution['grace_minutes'] ?? 0);
        $graceLabel = $graceMinutes > 0 ? $graceMinutes . ' min' : 'None';

        return [
            'available' => true,
            'name' => BrandingPresenter::policyName($resolution['policy_name'] ?? 'Unknown Policy'),
            'source_label' => $sourceLabel,
            'is_working_day' => $isWorkingDay,
            'schedule_label' => $scheduleLabel,
            'grace_label' => $graceLabel,
        ];
    }
}
