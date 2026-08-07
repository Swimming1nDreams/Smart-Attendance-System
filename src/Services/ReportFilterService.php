<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Localization;
use App\Models\Department;
use App\Models\Employee;

final class ReportFilterService
{
    private const LEAVE_STATUSES = ['pending', 'approved', 'rejected', 'cancelled'];
    private const LEAVE_TYPES = ['casual', 'medical', 'unpaid', 'other'];
    private const EXCEPTION_TYPES = ['all', 'late', 'absent'];

    public function __construct(
        private DateValidationService $dateValidator,
        private Employee $employees,
        private Department $departments,
    ) {
    }

    /** @param array<string, mixed> $input */
    public function attendanceSummary(array $input): array
    {
        $filters = $this->dateAndPagination($input);
        $this->resolveDepartment($filters, $input);
        $this->resolveEmployee($filters, $input, false);
        return $filters;
    }

    /** @param array<string, mixed> $input */
    public function employeeAttendance(array $input): array
    {
        $filters = $this->dateAndPagination($input);
        $this->resolveEmployee($filters, $input, true);
        return $filters;
    }

    /** @param array<string, mixed> $input */
    public function leaveRequests(array $input): array
    {
        $filters = $this->dateAndPagination($input);
        $this->resolveEmployee($filters, $input, false);
        $this->resolveAllowed($filters, $input, 'status', self::LEAVE_STATUSES, 'validation.leave_status_filter_invalid');
        $this->resolveAllowed($filters, $input, 'leave_type', self::LEAVE_TYPES, 'validation.leave_type_filter_invalid');
        return $filters;
    }

    /** @param array<string, mixed> $input */
    public function attendanceExceptions(array $input): array
    {
        $filters = $this->dateAndPagination($input);
        $this->resolveDepartment($filters, $input);
        $this->resolveEmployee($filters, $input, false);
        $value = trim((string) ($input['exception_type'] ?? 'all'));
        if (!in_array($value, self::EXCEPTION_TYPES, true)) {
            $filters['errors'][] = $this->t('validation.exception_type_filter_invalid');
            $value = 'all';
        }
        $filters['exception_type'] = $value;
        return $filters;
    }

    /** @param array<string, mixed> $input */
    public function analytics(array $input): array
    {
        $monthInput = trim((string) ($input['month'] ?? date('Y-m')));
        $filters = ['month' => $monthInput, 'errors' => []];
        if (!$this->dateValidator->isValidMonth($monthInput)) {
            $filters['errors'][] = $this->t('validation.invalid_month');
            $filters['month'] = date('Y-m');
        }
        $this->resolveDepartment($filters, $input);
        $this->resolveEmployee($filters, $input, false);
        return $filters;
    }

    /** @param array<string, mixed> $input */
    public function auditLogs(array $input, array $allowedActions, array $allowedEntityTypes): array
    {
        $filters = $this->dateAndPagination($input);
        $filters['search'] = mb_substr(trim((string) ($input['search'] ?? '')), 0, 100);
        $this->resolvePositiveInt($filters, $input, 'actor_user_id', 'validation.audit_actor_invalid');
        $this->resolveAllowed($filters, $input, 'action', $allowedActions, 'validation.audit_action_invalid');
        $this->resolveAllowed($filters, $input, 'entity_type', $allowedEntityTypes, 'validation.audit_entity_type_invalid');
        $filters['entity_id'] = mb_substr(trim((string) ($input['entity_id'] ?? '')), 0, 100);
        return $filters;
    }

    public function dateRangeFilename(array $filters): string
    {
        $from = $filters['date_from'] ?? null;
        $to = $filters['date_to'] ?? null;
        if ($from === null && $to === null) {
            return 'all_dates';
        }
        return $this->filenamePart(($from ?? 'start') . '_to_' . ($to ?? 'end'));
    }

    public function filenamePart(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/u', '_', $value) ?? '';
        return trim($value, '_') ?: 'report';
    }

    /** @param array<string, mixed> $input */
    private function dateAndPagination(array $input): array
    {
        $fromInput = trim((string) ($input['date_from'] ?? ''));
        $toInput = trim((string) ($input['date_to'] ?? ''));
        $from = $this->dateValidator->normalizeOptionalDate($fromInput);
        $to = $this->dateValidator->normalizeOptionalDate($toInput);
        $errors = [];
        if ($fromInput !== '' && $from === null) {
            $errors[] = $this->t('validation.date_from_invalid');
        }
        if ($toInput !== '' && $to === null) {
            $errors[] = $this->t('validation.date_to_invalid');
        }
        if ($from !== null && $to !== null && !$this->dateValidator->isValidRange($from, $to)) {
            $errors[] = $this->t('validation.date_to_before_from');
        }
        $pageValue = $input['page'] ?? 1;
        $page = filter_var($pageValue, FILTER_VALIDATE_INT);
        if ($page === false || $page < 1) {
            $errors[] = $this->t('validation.page_invalid');
            $page = 1;
        }
        $requestedPerPage = (int) ($input['per_page'] ?? 20);
        $perPage = in_array($requestedPerPage, [20, 50, 100], true) ? $requestedPerPage : 20;
        return [
            'date_from_input' => $fromInput,
            'date_to_input' => $toInput,
            'date_from' => $from,
            'date_to' => $to,
            'page' => $page,
            'per_page' => $perPage,
            'errors' => $errors,
        ];
    }

    /** @param array<string, mixed> $filters @param array<string, mixed> $input */
    private function resolveDepartment(array &$filters, array $input): void
    {
        $this->resolvePositiveInt($filters, $input, 'department_id', 'validation.department_filter_invalid');
        if (($filters['department_id'] ?? null) !== null) {
            $department = $this->departments->findById((int) $filters['department_id']);
            if ($department === null) {
                $filters['errors'][] = $this->t('validation.department_not_found');
            } else {
                $filters['department'] = $department;
            }
        }
    }

    /** @param array<string, mixed> $filters @param array<string, mixed> $input */
    private function resolveEmployee(array &$filters, array $input, bool $required): void
    {
        $raw = $input['employee_id'] ?? null;
        $this->resolvePositiveInt($filters, $input, 'employee_id', 'validation.employee_filter_invalid');
        if ($required && ($raw === null || $raw === '')) {
            $filters['errors'][] = $this->t('validation.choose_employee_report');
            return;
        }
        if (($filters['employee_id'] ?? null) !== null) {
            $employee = $this->employees->findById((int) $filters['employee_id']);
            if ($employee === null || ($employee['role'] ?? '') !== 'employee') {
                $filters['errors'][] = $this->t('validation.employee_not_found');
            } else {
                $filters['employee'] = $employee;
            }
        }
    }

    /** @param array<string, mixed> $filters @param array<string, mixed> $input */
    private function resolvePositiveInt(array &$filters, array $input, string $key, string $errorKey): void
    {
        $raw = $input[$key] ?? null;
        if ($raw === null || $raw === '') {
            $filters[$key] = null;
            return;
        }
        $value = filter_var($raw, FILTER_VALIDATE_INT);
        if ($value === false || $value <= 0) {
            $filters[$key] = null;
            $filters['errors'][] = $this->t($errorKey);
            return;
        }
        $filters[$key] = (int) $value;
    }

    /** @param array<string, mixed> $filters @param array<string, mixed> $input @param list<string> $allowed */
    private function resolveAllowed(array &$filters, array $input, string $key, array $allowed, string $errorKey): void
    {
        $value = trim((string) ($input[$key] ?? ''));
        if ($value === '') {
            $filters[$key] = null;
            return;
        }
        if (!in_array($value, $allowed, true)) {
            $filters[$key] = null;
            $filters['errors'][] = $this->t($errorKey);
            return;
        }
        $filters[$key] = $value;
    }

    private function t(string $key): string
    {
        return Localization::current()->translate($key);
    }
}
