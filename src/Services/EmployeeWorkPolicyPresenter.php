<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;

final class EmployeeWorkPolicyPresenter
{
    public function __construct(private AttendancePolicyService $durationFormatter)
    {
    }

    /** @return array<string, mixed> */
    public function unavailable(): array
    {
        return [
            'available' => false,
            'name' => null,
            'source_label' => null,
            'source_context' => null,
            'status_label' => null,
            'timezone' => null,
            'today' => null,
            'weekly_schedule' => [],
            'attendance_rules' => [],
            'overtime_rules' => [],
        ];
    }

    /**
     * @param array<string, mixed> $resolution
     * @param array<string, mixed> $template
     * @param array<string, mixed> $employee
     * @param callable(string): string $translate
     * @return array<string, mixed>
     */
    public function present(array $resolution, array $template, array $employee, callable $translate): array
    {
        $scope = (string) ($resolution['assignment_scope'] ?? 'compatibility');
        $sourceLabel = match ($scope) {
            'employee' => $translate('work_policy.source.employee'),
            'department' => $translate('work_policy.source.department'),
            'company' => $translate('work_policy.source.company'),
            default => $translate('work_policy.source.compatibility'),
        };
        $sourceContext = $scope === 'department'
            ? trim((string) ($employee['department_name'] ?? ''))
            : '';
        $todayWorking = (bool) ($resolution['is_working_day'] ?? false);
        $isHoliday = (bool) ($resolution['is_holiday'] ?? false);
        $todayBreak = $this->breakLabel(
            $resolution['break_start_at'] ?? null,
            $resolution['break_end_at'] ?? null,
            (int) ($resolution['break_minutes'] ?? 0),
            $translate,
        );

        $attendancePolicy = is_array($resolution['attendance_policy'] ?? null)
            ? $resolution['attendance_policy']
            : [];
        $overtimePolicy = is_array($resolution['overtime_policy'] ?? null)
            ? $resolution['overtime_policy']
            : [];

        return [
            'available' => true,
            'name' => BrandingPresenter::policyName($resolution['policy_name'] ?? ''),
            'source_label' => $sourceLabel,
            'source_context' => $sourceContext !== '' ? $sourceContext : null,
            'status_label' => $translate('work_policy.active'),
            'timezone' => (string) ($resolution['timezone'] ?? ''),
            'today' => [
                'weekday' => $translate('work_schedules.weekdays.' . (int) ($resolution['weekday'] ?? 0)),
                'date' => (string) ($resolution['business_date'] ?? ''),
                'is_working_day' => $todayWorking,
                'is_holiday' => $isHoliday,
                'holiday_name' => $isHoliday ? (string) ($resolution['holiday_name'] ?? '') : null,
                'day_status_label' => $isHoliday
                    ? $translate('work_policy.holiday')
                    : $translate($todayWorking ? 'work_policy.working_day' : 'work_policy.non_working_day'),
                'schedule_label' => $todayWorking
                    ? $this->timeRange($resolution['scheduled_start_at'] ?? null, $resolution['scheduled_end_at'] ?? null, (bool) ($resolution['is_overnight'] ?? false), $translate)
                    : $translate('work_policy.non_working_day'),
                'grace_label' => $todayWorking
                    ? $this->durationLabel((int) ($resolution['grace_minutes'] ?? 0), $translate)
                    : null,
                'expected_work_label' => $todayWorking
                    ? $this->durationLabel((int) ($resolution['expected_regular_minutes'] ?? 0), $translate)
                    : null,
                'break_label' => $todayBreak,
            ],
            'weekly_schedule' => $this->weeklySchedule((array) ($template['days'] ?? []), $translate),
            'attendance_rules' => [
                $this->rule($translate('work_policy.check_in_grace'), $this->durationLabel((int) ($resolution['grace_minutes'] ?? 0), $translate)),
                $this->rule($translate('work_policy.early_departure'), $this->enabledLabel((bool) ($attendancePolicy['early_departure_enabled'] ?? false), $translate)),
                $this->rule($translate('work_policy.early_departure_grace'), $this->durationLabel((int) ($attendancePolicy['early_departure_grace_minutes'] ?? 0), $translate)),
                $this->rule($translate('work_policy.maximum_attendance'), $this->durationLabel((int) ($attendancePolicy['maximum_attendance_minutes'] ?? 0), $translate)),
                $this->rule($translate('work_policy.holiday_check_in'), $this->allowedLabel((bool) ($attendancePolicy['allow_holiday_check_in'] ?? false), $translate)),
                $this->rule($translate('work_policy.non_working_day_check_in'), $this->allowedLabel((bool) ($attendancePolicy['allow_non_working_day_check_in'] ?? false), $translate)),
            ],
            'overtime_rules' => [
                $this->rule($translate('work_policy.overtime_eligible'), $this->eligibleLabel((bool) ($employee['overtime_eligible'] ?? false), $translate)),
                $this->rule($translate('work_policy.early_work_overtime'), $this->eligibleLabel((bool) ($overtimePolicy['early_work_eligible'] ?? false), $translate)),
                $this->rule($translate('work_policy.after_hours_overtime'), $this->eligibleLabel((bool) ($overtimePolicy['after_hours_work_eligible'] ?? false), $translate)),
                $this->rule($translate('work_policy.non_working_day_overtime'), $this->eligibleLabel((bool) ($overtimePolicy['non_working_day_work_eligible'] ?? false), $translate)),
                $this->rule($translate('work_policy.holiday_overtime'), $this->eligibleLabel((bool) ($overtimePolicy['holiday_work_eligible'] ?? false), $translate)),
                $this->rule($translate('work_policy.approval'), (bool) ($overtimePolicy['approval_required'] ?? false) ? $translate('work_policy.required') : $translate('work_policy.not_required')),
                $this->rule($translate('work_policy.minimum_overtime'), $this->durationLabel((int) ($overtimePolicy['minimum_overtime_minutes'] ?? 0), $translate)),
            ],
        ];
    }

    /** @param list<array<string, mixed>> $days @param callable(string): string $translate */
    private function weeklySchedule(array $days, callable $translate): array
    {
        $byWeekday = [];
        foreach ($days as $day) {
            $weekday = (int) ($day['day_of_week'] ?? 0);
            if ($weekday >= 1 && $weekday <= 7) {
                $byWeekday[$weekday] = $day;
            }
        }

        $schedule = [];
        for ($weekday = 1; $weekday <= 7; $weekday++) {
            $day = $byWeekday[$weekday] ?? null;
            $isWorking = is_array($day) && (int) ($day['is_working_day'] ?? 0) === 1;
            $schedule[] = [
                'weekday' => $translate('work_schedules.weekdays.' . $weekday),
                'is_working_day' => $isWorking,
                'status_label' => $day === null
                    ? $translate('common.not_available')
                    : $translate($isWorking ? 'work_policy.working' : 'work_policy.off'),
                'schedule_label' => $day === null
                    ? $translate('common.not_available')
                    : ($isWorking
                        ? $this->timeRange($day['start_time'] ?? null, $day['end_time'] ?? null, (bool) ($day['is_overnight'] ?? false), $translate)
                        : $translate('work_policy.off')),
                'break_label' => $isWorking
                    ? $this->breakLabel($day['break_start_time'] ?? null, $day['break_end_time'] ?? null, (int) ($day['break_minutes'] ?? 0), $translate)
                    : null,
            ];
        }

        return $schedule;
    }

    /** @param callable(string): string $translate */
    private function durationLabel(int $minutes, callable $translate): string
    {
        if ($minutes <= 0) {
            return $translate('common.none');
        }

        return $this->durationFormatter->formatDuration($minutes, [
            'hours_minutes' => $translate('common.duration_formats.hours_minutes'),
            'hours' => $translate('common.duration_formats.hours'),
            'minutes' => $translate('common.duration_formats.minutes'),
            'less_than_minute' => $translate('common.duration_formats.less_than_minute'),
            'empty' => $translate('common.not_available'),
        ]);
    }

    /** @param callable(string): string $translate */
    private function timeRange(mixed $start, mixed $end, bool $overnight, callable $translate): string
    {
        $startLabel = $this->timeLabel($start);
        $endLabel = $this->timeLabel($end);
        if ($startLabel === null || $endLabel === null) {
            return $translate('common.not_available');
        }

        return $startLabel . ' – ' . $endLabel . ($overnight ? ' ' . $translate('work_policy.next_day') : '');
    }

    private function timeLabel(mixed $value): ?string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        $time = substr($raw, -8);
        $parsed = DateTimeImmutable::createFromFormat('!H:i:s', $time);

        return $parsed instanceof DateTimeImmutable ? $parsed->format('g:i A') : null;
    }

    /** @param callable(string): string $translate */
    private function breakLabel(mixed $start, mixed $end, int $minutes, callable $translate): ?string
    {
        if (trim((string) $start) !== '' && trim((string) $end) !== '') {
            return $this->timeRange($start, $end, false, $translate);
        }

        return $minutes > 0 ? $this->durationLabel($minutes, $translate) : null;
    }

    /** @return array{label: string, value: string} */
    private function rule(string $label, string $value): array
    {
        return ['label' => $label, 'value' => $value];
    }

    /** @param callable(string): string $translate */
    private function eligibleLabel(bool $eligible, callable $translate): string
    {
        return $translate($eligible ? 'work_policy.eligible' : 'work_policy.not_eligible');
    }

    /** @param callable(string): string $translate */
    private function allowedLabel(bool $allowed, callable $translate): string
    {
        return $translate($allowed ? 'work_policy.allowed' : 'work_policy.not_allowed');
    }

    /** @param callable(string): string $translate */
    private function enabledLabel(bool $enabled, callable $translate): string
    {
        return $translate($enabled ? 'work_policy.enabled' : 'work_policy.disabled');
    }
}
