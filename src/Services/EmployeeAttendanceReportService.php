<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Attendance;
use App\Models\LeaveRequest;

final class EmployeeAttendanceReportService
{
    public function __construct(
        private Attendance $attendance,
        private LeaveRequest $leaveRequests,
        private AttendanceReportContextService $context,
    ) {
    }

    /** @param array<string, mixed> $filters */
    public function generate(array $filters, int $perPage = 20, bool $export = false): array
    {
        $empty = ['records' => [], 'approved_leave_requests' => [], 'approved_leave_by_date' => [], 'total_records' => 0, 'page' => 1, 'per_page' => $perPage];
        if (($filters['errors'] ?? []) !== [] || ($filters['employee_id'] ?? null) === null) {
            return $empty;
        }
        $userId = (int) $filters['employee_id'];
        $count = $this->attendance->countAll($userId, '', $filters['date_from'], $filters['date_to']);
        $all = $count > 0 ? $this->attendance->findAll($userId, '', $filters['date_from'], $filters['date_to'], 1, $count) : [];
        $from = $filters['date_from'] ?? '1000-01-01';
        $to = $filters['date_to'] ?? '9999-12-31';
        $leaves = $this->leaveRequests->findApprovedRequestsForEmployeeInRange($userId, $from, $to);
        $all = $this->context->enrich($all, $leaves);
        $lastPage = max(1, (int) ceil($count / $perPage));
        $page = min(max(1, (int) ($filters['page'] ?? 1)), $lastPage);
        $mapped = $this->context->approvedLeaveDateMap($leaves, $from, $to);
        return [
            'records' => $export ? $all : array_slice($all, ($page - 1) * $perPage, $perPage),
            'approved_leave_requests' => $leaves,
            'approved_leave_by_date' => $mapped[$userId] ?? [],
            'total_records' => $count,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }
}
