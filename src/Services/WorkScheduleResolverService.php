<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class WorkScheduleResolverService
{
    public function __construct(private PDO $connection)
    {
    }

    /** @return array<string, mixed> */
    public function resolve(int $employeeId, string $businessDate, bool $lock = false): array
    {
        $employee = $this->findEmployee($employeeId, $lock);
        if ($employee === null) {
            throw new AttendanceOperationException('employee_invalid');
        }

        $assignment = $this->findAssignment($employeeId, (int) ($employee['department_id'] ?? 0), $businessDate, $lock);
        $templateId = $assignment !== null ? (int) $assignment['work_schedule_template_id'] : $this->fallbackTemplateId($lock);
        if ($templateId <= 0) {
            throw new AttendanceOperationException('policy_missing');
        }

        $template = $this->findTemplate($templateId, $lock);
        if ($template === null) {
            throw new AttendanceOperationException('policy_missing');
        }

        $profile = $this->findCompanyProfile($lock);
        $timezoneName = $this->timezoneName($template, $profile);
        $weekday = (int) (new DateTimeImmutable($businessDate, new DateTimeZone($timezoneName)))->format('N');
        $day = $this->findScheduleDay($templateId, $weekday, $lock);
        if ($day === null) {
            throw new AttendanceOperationException('policy_day_missing');
        }

        $holiday = $this->findHoliday($businessDate, $lock);
        $attendancePolicy = $this->findAttendancePolicy($templateId, $lock);
        $overtimePolicy = $this->findOvertimePolicy($templateId, $lock);

        return $this->buildResolution($template, $assignment, $profile, $day, $holiday, $businessDate, $attendancePolicy, $overtimePolicy);
    }

    /**
     * @param array<string, mixed> $template
     * @param array<string, mixed>|null $assignment
     * @param array<string, mixed> $profile
     * @param array<string, mixed> $day
     * @param array<string, mixed>|null $holiday
     * @param array<string, mixed> $attendancePolicy
     * @param array<string, mixed> $overtimePolicy
     * @return array<string, mixed>
     */
    private function buildResolution(array $template, ?array $assignment, array $profile, array $day, ?array $holiday, string $businessDate, array $attendancePolicy, array $overtimePolicy): array
    {
        $templateId = (int) $template['id'];
        $timezoneName = $this->timezoneName($template, $profile);
        $timezone = new DateTimeZone($timezoneName);
        $weekday = (int) (new DateTimeImmutable($businessDate, $timezone))->format('N');
        $isHoliday = $holiday !== null;
        $isWorkingDay = (int) ($day['is_working_day'] ?? 0) === 1;
        $dayType = $isHoliday ? 'holiday' : ($isWorkingDay ? 'working_day' : 'non_working_day');
        $scheduledStartAt = null;
        $scheduledEndAt = null;
        $breakStartAt = null;
        $breakEndAt = null;
        if ($isWorkingDay) {
            $scheduledStartAt = $this->combineDateTime($businessDate, (string) ($day['start_time'] ?? ''), $timezone);
            $scheduledEndAt = $this->combineDateTime($businessDate, (string) ($day['end_time'] ?? ''), $timezone);
            if ((int) ($day['is_overnight'] ?? 0) === 1) {
                $scheduledEndAt = $scheduledEndAt->modify('+1 day');
            }

            if (!empty($day['break_start_time']) && !empty($day['break_end_time'])) {
                $breakStartAt = $this->combineDateTime($businessDate, (string) $day['break_start_time'], $timezone);
                $breakEndAt = $this->combineDateTime($businessDate, (string) $day['break_end_time'], $timezone);
                if ($breakEndAt <= $breakStartAt && (int) ($day['is_overnight'] ?? 0) === 1) {
                    $breakEndAt = $breakEndAt->modify('+1 day');
                }
            }
        }

        return [
            'policy_version_id' => $templateId,
            'assignment_id' => $assignment !== null ? (int) $assignment['id'] : null,
            'assignment_scope' => $assignment !== null ? (string) $assignment['scope_type'] : 'compatibility',
            'policy_name' => (string) $template['name'],
            'version_number' => (int) ($template['version_number'] ?? 1),
            'lifecycle_status' => (string) ($template['lifecycle_status'] ?? 'active'),
            'timezone' => $timezoneName,
            'business_date' => $businessDate,
            'weekday' => $weekday,
            'day_type' => $dayType,
            'legacy_status' => $this->legacyStatusFor($dayType, $isWorkingDay),
            'is_working_day' => $isWorkingDay,
            'is_holiday' => $isHoliday,
            'holiday_name' => $holiday['holiday_name'] ?? null,
            'scheduled_start_at' => $scheduledStartAt?->format('Y-m-d H:i:s'),
            'scheduled_end_at' => $scheduledEndAt?->format('Y-m-d H:i:s'),
            'is_overnight' => (int) ($day['is_overnight'] ?? 0) === 1,
            'break_start_at' => $breakStartAt?->format('Y-m-d H:i:s'),
            'break_end_at' => $breakEndAt?->format('Y-m-d H:i:s'),
            'break_minutes' => (int) ($day['break_minutes'] ?? 0),
            'grace_minutes' => $isWorkingDay ? (int) ($day['grace_minutes'] ?? 0) : 0,
            'expected_regular_minutes' => $isWorkingDay ? (int) ($day['expected_minutes'] ?? 0) : 0,
            'attendance_policy' => $attendancePolicy,
            'overtime_policy' => $overtimePolicy,
        ];
    }

    /**
     * @param list<array{employee_id: int, business_date: string}> $requests
     * @return array<string, array<string, mixed>>
     */
    public function resolveMany(array $requests): array
    {
        $uniqueRequests = [];
        foreach ($requests as $request) {
            $employeeId = (int) ($request['employee_id'] ?? 0);
            $businessDate = (string) ($request['business_date'] ?? '');
            if ($employeeId <= 0 || $businessDate === '') {
                continue;
            }
            $key = $employeeId . '|' . $businessDate;
            $uniqueRequests[$key] = ['employee_id' => $employeeId, 'business_date' => $businessDate];
        }
        if ($uniqueRequests === []) {
            return [];
        }

        $employeeIds = array_values(array_unique(array_column($uniqueRequests, 'employee_id')));
        $dates = array_values(array_column($uniqueRequests, 'business_date'));
        $fromDate = min($dates);
        $toDate = max($dates);
        $employees = $this->findEmployees($employeeIds);
        $departmentIds = array_values(array_unique(array_filter(array_map(
            static fn (array $employee): int => (int) ($employee['department_id'] ?? 0),
            $employees
        ), static fn (int $id): bool => $id > 0)));
        $assignments = $this->findAssignmentsForRange($employeeIds, $departmentIds, $fromDate, $toDate);
        $profile = $this->findCompanyProfile(false);
        $fallbackTemplateId = $this->fallbackTemplateId(false);
        $selectedAssignments = [];
        $templateIds = $fallbackTemplateId > 0 ? [$fallbackTemplateId] : [];

        foreach ($uniqueRequests as $key => $request) {
            $employee = $employees[$request['employee_id']] ?? null;
            if ($employee === null) {
                throw new AttendanceOperationException('employee_invalid');
            }
            $assignment = $this->assignmentFor(
                $assignments,
                (int) $request['employee_id'],
                (int) ($employee['department_id'] ?? 0),
                (string) $request['business_date']
            );
            $selectedAssignments[$key] = $assignment;
            $templateIds[] = $assignment !== null ? (int) $assignment['work_schedule_template_id'] : $fallbackTemplateId;
        }

        $templateIds = array_values(array_unique(array_filter($templateIds, static fn (int $id): bool => $id > 0)));
        if ($templateIds === []) {
            throw new AttendanceOperationException('policy_missing');
        }
        $templates = $this->findTemplates($templateIds);
        $scheduleDays = $this->findScheduleDays($templateIds);
        $attendancePolicies = $this->findAttendancePolicies($templateIds);
        $overtimePolicies = $this->findOvertimePolicies($templateIds);
        $holidays = $this->findHolidays($fromDate, $toDate);

        $resolved = [];
        foreach ($uniqueRequests as $key => $request) {
            $employee = $employees[$request['employee_id']];
            $assignment = $selectedAssignments[$key];
            $templateId = $assignment !== null ? (int) $assignment['work_schedule_template_id'] : $fallbackTemplateId;
            $template = $templates[$templateId] ?? null;
            if ($template === null) {
                throw new AttendanceOperationException('policy_missing');
            }
            $timezoneName = $this->timezoneName($template, $profile);
            $weekday = (int) (new DateTimeImmutable((string) $request['business_date'], new DateTimeZone($timezoneName)))->format('N');
            $day = $scheduleDays[$templateId][$weekday] ?? null;
            if ($day === null) {
                throw new AttendanceOperationException('policy_day_missing');
            }

            $resolved[$key] = $this->buildResolution(
                $template,
                $assignment,
                $profile,
                $day,
                $holidays[(string) $request['business_date']] ?? null,
                (string) $request['business_date'],
                $attendancePolicies[$templateId] ?? $this->defaultAttendancePolicy(),
                $overtimePolicies[$templateId] ?? $this->defaultOvertimePolicy()
            );
        }

        return $resolved;
    }

    /** @param list<int> $employeeIds @return array<int, array<string, mixed>> */
    private function findEmployees(array $employeeIds): array
    {
        $placeholders = implode(',', array_fill(0, count($employeeIds), '?'));
        $statement = $this->connection->prepare("SELECT id, role, status, department_id, expected_daily_work_hours, overtime_eligible FROM users WHERE id IN ($placeholders)");
        $statement->execute($employeeIds);
        $employees = [];
        foreach ($statement->fetchAll() as $row) {
            $employees[(int) $row['id']] = $row;
        }

        return $employees;
    }

    /** @param list<int> $employeeIds @param list<int> $departmentIds @return list<array<string, mixed>> */
    private function findAssignmentsForRange(array $employeeIds, array $departmentIds, string $fromDate, string $toDate): array
    {
        $where = ["scope_type = 'company' AND scope_id IS NULL"];
        $params = [$toDate, $fromDate];
        if ($departmentIds !== []) {
            $where[] = "scope_type = 'department' AND scope_id IN (" . implode(',', array_fill(0, count($departmentIds), '?')) . ')';
            array_push($params, ...$departmentIds);
        }
        if ($employeeIds !== []) {
            $where[] = "scope_type = 'employee' AND scope_id IN (" . implode(',', array_fill(0, count($employeeIds), '?')) . ')';
            array_push($params, ...$employeeIds);
        }
        $sql = 'SELECT * FROM work_schedule_assignments
                WHERE is_active = 1
                  AND effective_from <= ?
                  AND (effective_to IS NULL OR effective_to >= ?)
                  AND (' . implode(' OR ', array_map(static fn (string $part): string => '(' . $part . ')', $where)) . ')
                ORDER BY FIELD(scope_type, "employee", "department", "company"), effective_from DESC, id DESC';
        $statement = $this->connection->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll();
    }

    /** @param list<array<string, mixed>> $assignments */
    private function assignmentFor(array $assignments, int $employeeId, int $departmentId, string $businessDate): ?array
    {
        foreach (['employee', 'department', 'company'] as $scope) {
            foreach ($assignments as $assignment) {
                if ((string) ($assignment['scope_type'] ?? '') !== $scope) {
                    continue;
                }
                $scopeId = $assignment['scope_id'] ?? null;
                if ($scope === 'employee' && (int) $scopeId !== $employeeId) {
                    continue;
                }
                if ($scope === 'department' && ($departmentId <= 0 || (int) $scopeId !== $departmentId)) {
                    continue;
                }
                if ($scope === 'company' && $scopeId !== null) {
                    continue;
                }
                if ((string) $assignment['effective_from'] <= $businessDate && (empty($assignment['effective_to']) || (string) $assignment['effective_to'] >= $businessDate)) {
                    return $assignment;
                }
            }
        }

        return null;
    }

    /** @param list<int> $templateIds @return array<int, array<string, mixed>> */
    private function findTemplates(array $templateIds): array
    {
        $placeholders = implode(',', array_fill(0, count($templateIds), '?'));
        $statement = $this->connection->prepare("SELECT * FROM work_schedule_templates WHERE id IN ($placeholders)");
        $statement->execute($templateIds);
        $templates = [];
        foreach ($statement->fetchAll() as $row) {
            $templates[(int) $row['id']] = $row;
        }

        return $templates;
    }

    /** @param list<int> $templateIds @return array<int, array<int, array<string, mixed>>> */
    private function findScheduleDays(array $templateIds): array
    {
        $placeholders = implode(',', array_fill(0, count($templateIds), '?'));
        $statement = $this->connection->prepare("SELECT * FROM work_schedule_days WHERE work_schedule_template_id IN ($placeholders)");
        $statement->execute($templateIds);
        $days = [];
        foreach ($statement->fetchAll() as $row) {
            $days[(int) $row['work_schedule_template_id']][(int) $row['day_of_week']] = $row;
        }

        return $days;
    }

    /** @param list<int> $templateIds @return array<int, array<string, mixed>> */
    private function findAttendancePolicies(array $templateIds): array
    {
        $placeholders = implode(',', array_fill(0, count($templateIds), '?'));
        $statement = $this->connection->prepare("SELECT * FROM attendance_policy_settings WHERE work_schedule_template_id IN ($placeholders)");
        $statement->execute($templateIds);
        $policies = [];
        foreach ($statement->fetchAll() as $row) {
            $policies[(int) $row['work_schedule_template_id']] = $row;
        }

        return $policies;
    }

    /** @param list<int> $templateIds @return array<int, array<string, mixed>> */
    private function findOvertimePolicies(array $templateIds): array
    {
        $placeholders = implode(',', array_fill(0, count($templateIds), '?'));
        $statement = $this->connection->prepare("SELECT * FROM overtime_policy_settings WHERE work_schedule_template_id IN ($placeholders)");
        $statement->execute($templateIds);
        $policies = [];
        foreach ($statement->fetchAll() as $row) {
            $policies[(int) $row['work_schedule_template_id']] = $row;
        }

        return $policies;
    }

    /** @return array<string, array<string, mixed>> */
    private function findHolidays(string $fromDate, string $toDate): array
    {
        $statement = $this->connection->prepare('SELECT id, holiday_name, holiday_date FROM holidays WHERE holiday_date BETWEEN :from_date AND :to_date');
        $statement->execute([':from_date' => $fromDate, ':to_date' => $toDate]);
        $holidays = [];
        foreach ($statement->fetchAll() as $row) {
            $holidays[(string) $row['holiday_date']] = $row;
        }

        return $holidays;
    }

    private function findEmployee(int $employeeId, bool $lock): ?array
    {
        $sql = 'SELECT id, role, status, department_id, expected_daily_work_hours, overtime_eligible FROM users WHERE id = :id LIMIT 1';
        if ($lock) {
            $sql .= ' FOR UPDATE';
        }
        $statement = $this->connection->prepare($sql);
        $statement->execute([':id' => $employeeId]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    private function findAssignment(int $employeeId, int $departmentId, string $businessDate, bool $lock): ?array
    {
        $candidates = [
            ['employee', $employeeId],
            ['department', $departmentId > 0 ? $departmentId : null],
            ['company', null],
        ];

        foreach ($candidates as [$scopeType, $scopeId]) {
            if ($scopeType !== 'company' && $scopeId === null) {
                continue;
            }
            $sql = 'SELECT * FROM work_schedule_assignments
                    WHERE scope_type = :scope_type
                      AND is_active = 1
                      AND effective_from <= :business_date_from
                      AND (effective_to IS NULL OR effective_to >= :business_date_to)';
            $params = [
                ':scope_type' => $scopeType,
                ':business_date_from' => $businessDate,
                ':business_date_to' => $businessDate,
            ];
            if ($scopeType === 'company') {
                $sql .= ' AND scope_id IS NULL';
            } else {
                $sql .= ' AND scope_id = :scope_id';
                $params[':scope_id'] = $scopeId;
            }
            $sql .= ' ORDER BY effective_from DESC, id DESC LIMIT 1';
            if ($lock) {
                $sql .= ' FOR UPDATE';
            }
            $statement = $this->connection->prepare($sql);
            $statement->execute($params);
            $row = $statement->fetch();
            if ($row !== false) {
                return $row;
            }
        }

        return null;
    }

    private function fallbackTemplateId(bool $lock): int
    {
        $sql = 'SELECT default_work_schedule_id FROM company_profiles ORDER BY id ASC LIMIT 1';
        if ($lock) {
            $sql .= ' FOR UPDATE';
        }
        $id = $this->connection->query($sql)->fetchColumn();

        return $id === false ? 0 : (int) $id;
    }

    private function findCompanyProfile(bool $lock): array
    {
        $sql = 'SELECT timezone FROM company_profiles ORDER BY id ASC LIMIT 1';
        if ($lock) {
            $sql .= ' FOR UPDATE';
        }
        $row = $this->connection->query($sql)->fetch();

        return $row === false ? [] : $row;
    }

    private function findTemplate(int $templateId, bool $lock): ?array
    {
        $sql = 'SELECT * FROM work_schedule_templates WHERE id = :id LIMIT 1';
        if ($lock) {
            $sql .= ' FOR UPDATE';
        }
        $statement = $this->connection->prepare($sql);
        $statement->execute([':id' => $templateId]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    private function findScheduleDay(int $templateId, int $weekday, bool $lock): ?array
    {
        $sql = 'SELECT * FROM work_schedule_days WHERE work_schedule_template_id = :template_id AND day_of_week = :weekday LIMIT 1';
        if ($lock) {
            $sql .= ' FOR UPDATE';
        }
        $statement = $this->connection->prepare($sql);
        $statement->execute([':template_id' => $templateId, ':weekday' => $weekday]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    private function findHoliday(string $businessDate, bool $lock): ?array
    {
        $sql = 'SELECT id, holiday_name, holiday_date FROM holidays WHERE holiday_date = :business_date LIMIT 1';
        if ($lock) {
            $sql .= ' FOR UPDATE';
        }
        $statement = $this->connection->prepare($sql);
        $statement->execute([':business_date' => $businessDate]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    private function findAttendancePolicy(int $templateId, bool $lock): array
    {
        $sql = 'SELECT * FROM attendance_policy_settings WHERE work_schedule_template_id = :template_id LIMIT 1';
        if ($lock) {
            $sql .= ' FOR UPDATE';
        }
        $statement = $this->connection->prepare($sql);
        $statement->execute([':template_id' => $templateId]);
        $row = $statement->fetch();

        return $row === false ? $this->defaultAttendancePolicy() : $row;
    }

    private function findOvertimePolicy(int $templateId, bool $lock): array
    {
        $sql = 'SELECT * FROM overtime_policy_settings WHERE work_schedule_template_id = :template_id LIMIT 1';
        if ($lock) {
            $sql .= ' FOR UPDATE';
        }
        $statement = $this->connection->prepare($sql);
        $statement->execute([':template_id' => $templateId]);
        $row = $statement->fetch();

        return $row === false ? $this->defaultOvertimePolicy() : $row;
    }

    /** @return array<string, mixed> */
    private function defaultAttendancePolicy(): array
    {
        return [
            'allow_non_working_day_check_in' => 1,
            'allow_holiday_check_in' => 1,
            'allow_overnight_attendance' => 0,
            'maximum_attendance_minutes' => 960,
            'missing_check_out_mode' => 'mark_incomplete',
            'early_departure_enabled' => 1,
            'manual_override_allowed' => 1,
            'override_reason_required' => 1,
        ];
    }

    /** @return array<string, mixed> */
    private function defaultOvertimePolicy(): array
    {
        return [
            'early_work_eligible' => 1,
            'after_hours_work_eligible' => 1,
            'non_working_day_work_eligible' => 1,
            'holiday_work_eligible' => 1,
            'approval_required' => 0,
            'minimum_overtime_minutes' => 0,
            'rounding_mode' => 'exact',
            'rounding_interval_minutes' => null,
            'special_day_break_threshold_minutes' => null,
            'special_day_break_minutes' => 0,
            'allow_admin_adjustment' => 1,
            'allow_approval_above_calculated' => 0,
            'reason_required' => 1,
        ];
    }

    /** @param array<string, mixed> $template @param array<string, mixed> $profile */
    private function timezoneName(array $template, array $profile): string
    {
        $timezoneName = (string) ($template['timezone'] ?: ($profile['timezone'] ?? 'Asia/Yangon'));

        return in_array($timezoneName, DateTimeZone::listIdentifiers(), true) ? $timezoneName : 'Asia/Yangon';
    }

    private function combineDateTime(string $date, string $time, DateTimeZone $timezone): DateTimeImmutable
    {
        return new DateTimeImmutable($date . ' ' . substr($time, 0, 8), $timezone);
    }

    private function legacyStatusFor(string $dayType, bool $isWorkingDay): string
    {
        return match ($dayType) {
            'holiday' => 'holiday',
            'non_working_day' => 'weekend',
            default => $isWorkingDay ? 'present' : 'weekend',
        };
    }
}
