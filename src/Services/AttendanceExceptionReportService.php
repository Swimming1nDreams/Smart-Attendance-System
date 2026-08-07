<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Attendance;

final class AttendanceExceptionReportService
{
    public function __construct(private Attendance $attendance, private AttendanceReportContextService $context)
    {
    }

    /** @param array<string, mixed> $filters */
    public function generate(array $filters, int $perPage = 20, bool $export = false): array
    {
        if (($filters['errors'] ?? []) !== []) {
            return ['records' => [], 'total_records' => 0, 'page' => 1, 'per_page' => $perPage];
        }
        $count = $this->attendance->countLateAbsentExceptionReportRecords($filters['date_from'], $filters['date_to'], $filters['department_id'] ?? null, $filters['employee_id'] ?? null, $filters['exception_type']);
        $lastPage = max(1, (int) ceil($count / $perPage));
        $page = min(max(1, (int) ($filters['page'] ?? 1)), $lastPage);
        $records = $count > 0 ? $this->attendance->findLateAbsentExceptionReportRecords(
            $filters['date_from'], $filters['date_to'], $filters['department_id'] ?? null, $filters['employee_id'] ?? null,
            $filters['exception_type'], $export ? 1 : $page, $export ? $count : $perPage,
        ) : [];
        $records = $this->context->enrich($records);
        return ['records' => $records, 'total_records' => $count, 'page' => $page, 'per_page' => $perPage];
    }
}
