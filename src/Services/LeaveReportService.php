<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\LeaveRequest;

final class LeaveReportService
{
    public function __construct(private LeaveRequest $leaveRequests)
    {
    }

    /** @param array<string, mixed> $filters */
    public function generate(array $filters, int $perPage = 20, bool $export = false): array
    {
        if (($filters['errors'] ?? []) !== []) {
            return ['records' => [], 'total_records' => 0, 'page' => 1, 'per_page' => $perPage];
        }
        $count = $this->leaveRequests->countAll($filters['employee_id'] ?? null, $filters['status'] ?? null, $filters['leave_type'] ?? null, '', $filters['date_from'], $filters['date_to']);
        $lastPage = max(1, (int) ceil($count / $perPage));
        $page = min(max(1, (int) ($filters['page'] ?? 1)), $lastPage);
        $records = $count > 0 ? $this->leaveRequests->findAll(
            $filters['employee_id'] ?? null,
            $filters['status'] ?? null,
            $filters['leave_type'] ?? null,
            '',
            $filters['date_from'],
            $filters['date_to'],
            $export ? 1 : $page,
            $export ? $count : $perPage,
        ) : [];
        return ['records' => $records, 'total_records' => $count, 'page' => $page, 'per_page' => $perPage];
    }
}
