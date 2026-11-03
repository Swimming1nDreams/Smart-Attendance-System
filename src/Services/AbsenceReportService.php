<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Attendance;

final class AbsenceReportService
{
    public function __construct(private Attendance $attendance)
    {
    }

    /** @param array<string, mixed> $filters */
    public function generate(array $filters, int $perPage = 20, bool $export = false): array
    {
        $empty = [
            'records' => [],
            'total_records' => 0,
            'affected_employees' => 0,
            'page' => 1,
            'per_page' => $perPage,
        ];

        if (($filters['errors'] ?? []) !== []) {
            return $empty;
        }

        $employeeId = isset($filters['employee_id']) ? (int) $filters['employee_id'] : null;
        $departmentId = isset($filters['department_id']) ? (int) $filters['department_id'] : null;
        $count = $this->attendance->countAll(
            $employeeId,
            '',
            $filters['date_from'] ?? null,
            $filters['date_to'] ?? null,
            $departmentId,
            null,
            'absent',
        );

        if ($count === 0) {
            return $empty;
        }

        $all = $this->attendance->findAll(
            $employeeId,
            '',
            $filters['date_from'] ?? null,
            $filters['date_to'] ?? null,
            1,
            $count,
            $departmentId,
            null,
            'absent',
        );
        $lastPage = max(1, (int) ceil($count / $perPage));
        $page = min(max(1, (int) ($filters['page'] ?? 1)), $lastPage);
        $employeeIds = array_unique(array_map(
            static fn (array $record): int => (int) ($record['user_id'] ?? 0),
            $all,
        ));

        return [
            'records' => $export ? $all : array_slice($all, ($page - 1) * $perPage, $perPage),
            'total_records' => $count,
            'affected_employees' => count(array_filter($employeeIds, static fn (int $id): bool => $id > 0)),
            'page' => $page,
            'per_page' => $perPage,
        ];
    }
}
