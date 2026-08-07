<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use DateInterval;
use DateTimeImmutable;

final class AnalyticsService
{
    public function __construct(
        private Employee $employees,
        private Attendance $attendance,
        private Holiday $holidays,
        private LeaveRequest $leaveRequests,
        private AttendancePolicyService $policy,
        private ?WorkScheduleResolverService $scheduleResolver = null,
    ) {
    }

    /** @param array<string, mixed> $filters */
    public function generate(array $filters): array
    {
        $month = (string) $filters['month'];
        $monthDate = DateTimeImmutable::createFromFormat('!Y-m', $month) ?: new DateTimeImmutable(date('Y-m-01'));
        $from = $monthDate->format('Y-m-01');
        $to = $monthDate->format('Y-m-t');
        $employees = $this->employees->findAnalyticsScope($filters['department_id'] ?? null);
        $employeeIds = array_map(static fn (array $employee): int => (int) $employee['id'], $employees);
        $selectedEmployeeId = ($filters['employee_id'] ?? null) !== null ? (int) $filters['employee_id'] : null;
        $workingDayEmployeeIds = $selectedEmployeeId !== null
            ? array_values(array_filter($employeeIds, static fn (int $id): bool => $id === $selectedEmployeeId))
            : $employeeIds;
        $records = $this->attendance->findForUsersInRange($employeeIds, $from, $to);
        $leaves = $this->leaveRequests->findApprovedForUsersInRange($employeeIds, $from, $to);
        $holidayMap = [];
        foreach ($this->holidays->findInRange($from, $to) as $holiday) {
            $holidayMap[(string) $holiday['holiday_date']] = true;
        }
        $workingDays = $this->workingDays($from, $to, $holidayMap, $workingDayEmployeeIds);
        $recordsByUser = [];
        foreach ($records as $record) {
            $recordsByUser[(int) $record['user_id']][] = $record;
        }
        $leaveDatesByUser = $this->approvedWorkingLeaveDates($leaves, $from, $to, array_fill_keys(array_column($workingDays, 'date'), true));

        $filterEmployees = [];
        $employeeAnalytics = [];
        $selectedEmployeeName = null;
        $summary = [
            'total_employees' => 0, 'employees_with_attendance' => 0, 'average_attendance' => 0.0,
            'total_present_days' => 0, 'total_late_arrivals' => 0, 'total_late_minutes' => 0,
            'total_absent_days' => 0, 'total_approved_leave_days' => 0, 'total_working_hours' => 0.0,
            'total_overtime_minutes' => 0, 'working_day_count' => count($workingDays),
        ];
        $attendancePercentSum = 0.0;

        foreach ($employees as $employee) {
            $userId = (int) $employee['id'];
            $filterEmployees[] = [
                'id' => $userId, 'name' => (string) $employee['name'], 'email' => (string) $employee['email'],
                'staff_code' => (string) ($employee['staff_code'] ?? ''), 'department_name' => (string) ($employee['department_name'] ?? ''),
            ];
            if (($filters['employee_id'] ?? null) !== null && $userId === (int) $filters['employee_id']) {
                $selectedEmployeeName = (string) $employee['name'];
            }
            if (($filters['employee_id'] ?? null) !== null && $userId !== (int) $filters['employee_id']) {
                continue;
            }

            $present = $absent = $late = $lateMinutes = $workMinutes = $overtime = 0;
            $incomplete = 0;
            $approvedLeaveDates = $leaveDatesByUser[$userId] ?? [];
            foreach ($recordsByUser[$userId] ?? [] as $record) {
                $date = (string) $record['attendance_date'];
                $record = $this->policy->enrichRecord($record, isset($holidayMap[$date]), isset($approvedLeaveDates[$date]));
                $workMinutes += (int) ($record['actual_work_minutes'] ?? 0);
                $overtime += (int) ($record['overtime_minutes'] ?? 0);

                if (($record['status'] ?? '') === 'present') {
                    $present++;
                    if ((int) ($record['late_minutes'] ?? 0) > 0) {
                        $late++;
                        $lateMinutes += (int) $record['late_minutes'];
                    }
                    if (($record['attendance_display_status'] ?? '') === 'incomplete') {
                        $incomplete++;
                    }
                } elseif (($record['status'] ?? '') === 'absent') {
                    $absent++;
                }
            }
            $today = date('Y-m-d');
            $expectedDays = 0;
            $derivedAbsent = 0;

            foreach ($workingDays as $workingDay) {
                $date = $workingDay['date'];
                if ($date > $today) {
                    continue;
                }
                if (isset($approvedLeaveDates[$date])) {
                    continue;
                }
                $expectedDays++;

                $hasRecord = false;
                foreach ($recordsByUser[$userId] ?? [] as $record) {
                    if ((string) ($record['attendance_date'] ?? '') === $date) {
                        $hasRecord = true;
                        break;
                    }
                }
                
                if (!$hasRecord) {
                    $derivedAbsent++;
                }
            }

            $absent += $derivedAbsent;
            $recordedDays = $present + $absent;
            $percentage = $expectedDays > 0 ? round(($present / $expectedDays) * 100, 1) : 0.0;
            $has100Percent = $expectedDays > 0 && $present >= $expectedDays && $absent === 0 && $incomplete === 0;
            
            // Treat an employee as "having attendance/expected" if they have expected days OR they came in on non-expected days
            if ($expectedDays > 0 || $present > 0) {
                $summary['employees_with_attendance']++;
                $attendancePercentSum += $percentage;
            }
            $employeeAnalytics[] = [
                'id' => $userId, 'name' => $employee['name'], 'email' => $employee['email'], 'staff_code' => $employee['staff_code'] ?? '',
                'department_name' => $employee['department_name'] ?? '', 'position_name' => $employee['position_name'] ?? '',
                'present_days' => $present, 'absent_days' => $absent, 'approved_leave_days' => count($approvedLeaveDates),
                'late_days' => $late, 'late_minutes_total' => $lateMinutes, 'working_hours' => round($workMinutes / 60, 2),
                'work_minutes' => $workMinutes, 'overtime_minutes' => $overtime, 'recorded_days' => $recordedDays,
                'attendance_percentage' => $percentage,
                'has_100_percent_attendance' => $has100Percent,
            ];
            $summary['total_employees']++;
            $summary['total_present_days'] += $present;
            $summary['total_absent_days'] += $absent;
            $summary['total_late_arrivals'] += $late;
            $summary['total_late_minutes'] += $lateMinutes;
            $summary['total_approved_leave_days'] += count($approvedLeaveDates);
            $summary['total_working_hours'] += $workMinutes / 60;
            $summary['total_overtime_minutes'] += $overtime;
        }
        $summary['average_attendance'] = $summary['employees_with_attendance'] > 0
            ? round($attendancePercentSum / $summary['employees_with_attendance'], 1) : 0.0;
        $summary['total_working_hours'] = round($summary['total_working_hours'], 2);

        return [
            'first_day' => $from, 'last_day' => $to, 'working_days' => $workingDays,
            'filter_employees' => $filterEmployees, 'selected_employee_name' => $selectedEmployeeName,
            'employee_analytics' => $employeeAnalytics, 'summary' => $summary,
        ];
    }

    /** @param array<string, bool> $holidayMap @param list<int> $employeeIds */
    private function workingDays(string $from, string $to, array $holidayMap, array $employeeIds): array
    {
        if ($this->scheduleResolver !== null && $employeeIds !== []) {
            $requests = [];
            for ($day = new DateTimeImmutable($from), $end = new DateTimeImmutable($to); $day <= $end; $day = $day->add(new DateInterval('P1D'))) {
                $date = $day->format('Y-m-d');
                foreach ($employeeIds as $employeeId) {
                    $requests[] = ['employee_id' => $employeeId, 'business_date' => $date];
                }
            }

            try {
                $workingDateMap = [];
                foreach ($this->scheduleResolver->resolveMany($requests) as $resolution) {
                    if ((bool) ($resolution['is_working_day'] ?? false) && !(bool) ($resolution['is_holiday'] ?? false)) {
                        $workingDateMap[(string) $resolution['business_date']] = true;
                    }
                }
                ksort($workingDateMap);

                return array_map(
                    static fn (string $date): array => ['date' => $date, 'day' => (int) (new DateTimeImmutable($date))->format('j')],
                    array_keys($workingDateMap)
                );
            } catch (\Throwable) {
                return [];
            }
        }

        return [];
    }

    /** @param array<int, array<string, mixed>> $leaves @param array<string, bool> $fallbackWorkingDateMap */
    private function approvedWorkingLeaveDates(array $leaves, string $from, string $to, array $fallbackWorkingDateMap): array
    {
        $map = [];
        foreach ($leaves as $leave) {
            $userId = (int) $leave['user_id'];
            $start = max(new DateTimeImmutable((string) $leave['start_date']), new DateTimeImmutable($from));
            $end = min(new DateTimeImmutable((string) $leave['end_date']), new DateTimeImmutable($to));
            for ($day = $start; $day <= $end; $day = $day->add(new DateInterval('P1D'))) {
                $date = $day->format('Y-m-d');
                if ($this->isResolvedWorkingDate($userId, $date, $fallbackWorkingDateMap)) {
                    $map[$userId][$date] = true;
                }
            }
        }
        return $map;
    }

    /** @param array<string, bool> $fallbackWorkingDateMap */
    private function isResolvedWorkingDate(int $userId, string $date, array $fallbackWorkingDateMap): bool
    {
        if ($this->scheduleResolver !== null) {
            $resolution = $this->scheduleResolver->resolve($userId, $date);

            return (bool) ($resolution['is_working_day'] ?? false) && !(bool) ($resolution['is_holiday'] ?? false);
        }

        return isset($fallbackWorkingDateMap[$date]);
    }
}
