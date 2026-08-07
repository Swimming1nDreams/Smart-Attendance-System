<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Localization;
use App\Models\Attendance;

final class AttendanceSummaryReportService
{
    public function __construct(
        private Attendance $attendance,
        private AttendanceReportContextService $context,
        private AttendancePolicyService $policy,
        private ?AnalyticsService $analytics = null,
    ) {
    }

    /** @param array<string, mixed> $filters */
    public function generate(array $filters, int $perPage = 20, bool $export = false): array
    {
        $empty = ['records' => [], 'all_records' => [], 'total_records' => 0, 'page' => 1, 'per_page' => $perPage, 'totals' => $this->totals([], $filters)];
        if (($filters['errors'] ?? []) !== []) {
            return $empty;
        }
        $count = $this->attendance->countAttendanceSummaryReportRecords($filters['date_from'], $filters['date_to'], $filters['department_id'] ?? null, $filters['employee_id'] ?? null);
        if ($count === 0) {
            return $empty;
        }
        $all = $this->attendance->findAttendanceSummaryReportRecords($filters['date_from'], $filters['date_to'], $filters['department_id'] ?? null, $filters['employee_id'] ?? null, 1, $count);
        $all = $this->context->enrich($all);
        $lastPage = max(1, (int) ceil($count / $perPage));
        $page = min(max(1, (int) ($filters['page'] ?? 1)), $lastPage);
        return [
            'records' => $export ? $all : array_slice($all, ($page - 1) * $perPage, $perPage),
            'all_records' => $all,
            'total_records' => $count,
            'page' => $page,
            'per_page' => $perPage,
            'totals' => $this->totals($all, $filters),
        ];
    }

    /** @param array<int, array<string, mixed>> $records @param array<string, mixed> $filters */
    public function totals(array $records, array $filters = []): array
    {
        $workMinutes = 0;
        $overtime = 0;
        $present = 0;
        $late = 0;
        $absent = 0;
        foreach ($records as $record) {
            if (($record['status'] ?? '') === 'present') {
                $present++;
            }
            if ((int) ($record['late_minutes'] ?? 0) > 0) {
                $late++;
            }
            if (($record['status'] ?? '') === 'absent') {
                $absent++;
            }
            $workMinutes += (int) ($record['actual_work_minutes'] ?? 0);
            $overtime += (int) ($record['overtime_minutes'] ?? 0);
        }

        if (isset($this->analytics) && $this->analytics !== null && isset($filters['date_from'], $filters['date_to'])) {
            try {
                $analyticsData = $this->analytics->generate([
                    'date_from' => $filters['date_from'],
                    'date_to' => $filters['date_to'],
                    'department_id' => $filters['department_id'] ?? null,
                    'employee_id' => $filters['employee_id'] ?? null,
                ]);
                if (isset($analyticsData['summary']['total_absent_days'])) {
                    $absent = (int) $analyticsData['summary']['total_absent_days'];
                }
            } catch (\Throwable $e) {
                // Fallback to explicit if analytics fails
            }
        }
        return [
            'total_records' => count($records),
            'present_count' => $present,
            'late_count' => $late,
            'absent_count' => $absent,
            'total_work_minutes' => $workMinutes,
            'total_work_duration' => $this->policy->formatDuration($workMinutes, $this->durationFormats()),
            'total_overtime_minutes' => $overtime,
        ];
    }

    private function durationFormats(): array
    {
        $localization = Localization::current();
        return [
            'hours_minutes' => $localization->translate('common.duration_formats.hours_minutes'),
            'hours' => $localization->translate('common.duration_formats.hours'),
            'minutes' => $localization->translate('common.duration_formats.minutes'),
        ];
    }
}
