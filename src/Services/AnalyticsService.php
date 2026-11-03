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
        private ?AttendanceCalculationService $calculator = null,
        private ?AttendanceSessionReadService $sessionReader = null,
    ) {
        $this->calculator ??= new AttendanceCalculationService();
    }

    /** @param array<string, mixed> $filters */
    public function generate(array $filters, ?DateTimeImmutable $asOf = null): array
    {
        $asOf ??= new DateTimeImmutable('now');
        $month = (string) $filters['month'];
        $monthDate = DateTimeImmutable::createFromFormat('!Y-m', $month) ?: $asOf->modify('first day of this month')->setTime(0, 0);
        $from = $monthDate->format('Y-m-01');
        $to = $monthDate->format('Y-m-t');
        $employees = $this->employees->findAnalyticsScope($filters['department_id'] ?? null);
        $employeeIds = array_map(static fn (array $employee): int => (int) $employee['id'], $employees);
        $selectedEmployeeId = ($filters['employee_id'] ?? null) !== null ? (int) $filters['employee_id'] : null;
        $workingDayEmployeeIds = $selectedEmployeeId !== null
            ? array_values(array_filter($employeeIds, static fn (int $id): bool => $id === $selectedEmployeeId))
            : $employeeIds;
        $records = $this->attendance->findForUsersInRange($employeeIds, $from, $to);
        $prefetchedSessions = $this->sessionReader?->prefetch($records) ?? [];
        $leaves = $this->leaveRequests->findApprovedForUsersInRange($employeeIds, $from, $to);
        $holidayMap = [];
        foreach ($this->holidays->findInRange($from, $to) as $holiday) {
            $holidayMap[(string) $holiday['holiday_date']] = true;
        }
        $workingDays = $this->workingDays($from, $to, $holidayMap, $workingDayEmployeeIds);
        $recordsByUser = [];
        $recordsByUserDate = [];
        foreach ($records as $record) {
            $recordUserId = (int) $record['user_id'];
            $recordDate = (string) $record['attendance_date'];
            $recordsByUser[$recordUserId][] = $record;
            $recordsByUserDate[$recordUserId][$recordDate] = $record;
        }
        $leaveDatesByUser = $this->approvedWorkingLeaveDates($leaves, $from, $to, array_fill_keys(array_column($workingDays, 'date'), true));

        $filterEmployees = [];
        $employeeAnalytics = [];
        $selectedEmployeeName = null;
        $summary = [
            'total_employees' => 0, 'employees_with_attendance' => 0, 'average_attendance' => 0.0,
            'total_present_days' => 0, 'total_late_arrivals' => 0, 'total_late_minutes' => 0,
            'total_early_departures' => 0, 'total_early_departure_minutes' => 0,
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

            $present = $absent = $late = $lateMinutes = $earlyDepartures = $earlyDepartureMinutes = $workMinutes = $overtime = 0;
            $outOfScheduleMinutes = $pendingOvertimeMinutes = 0;
            $incomplete = 0;
            $qualifyingRecords = [];
            $explicitAbsentRecords = [];
            $approvedLeaveDates = $leaveDatesByUser[$userId] ?? [];
            $todayStr = $asOf->format('Y-m-d');
            foreach ($recordsByUser[$userId] ?? [] as $record) {
                $date = (string) $record['attendance_date'];
                
                // Do not process records that are in the future
                if ($date > $todayStr) {
                    continue;
                }
                
                $resolution = $this->scheduleResolver?->resolve($userId, $date);
                $sessionCalculation = null;
                if ($resolution !== null && $this->sessionReader !== null) {
                    $sessionCalculation = $this->sessionReader->calculateIfSessions(
                        $record,
                        $resolution,
                        $asOf,
                        $prefetchedSessions,
                    );
                    if ($sessionCalculation !== null) {
                        $record = $this->sessionReader->applyToRecord($record, $sessionCalculation);
                    }
                }

                $record = $this->policy->enrichRecord($record, isset($holidayMap[$date]), isset($approvedLeaveDates[$date]));
                $workMinutes += (int) ($record['actual_work_minutes'] ?? 0);
                $overtime += (int) ($record['overtime_minutes'] ?? 0);
                if ((string) ($record['overtime_approval_status'] ?? '') === 'pending') {
                    $pendingOvertimeMinutes += (int) ($record['calculated_overtime_minutes'] ?? 0);
                }

                $participation = $sessionCalculation !== null
                    ? [
                        'has_scheduled_overlap' => (bool) ($record['has_scheduled_overlap'] ?? false),
                        'scheduled_overlap_minutes' => (int) ($record['scheduled_overlap_minutes'] ?? 0),
                        'out_of_schedule_work_minutes' => (int) ($record['out_of_schedule_work_minutes'] ?? 0),
                    ]
                    : $this->recordParticipation($record, $resolution, $asOf);
                $qualifies = isset($approvedLeaveDates[$date])
                    ? false
                    : ($resolution === null
                    ? (($record['status'] ?? '') === 'present')
                    : ((string) ($resolution['day_type'] ?? '') === 'working_day' && $participation['has_scheduled_overlap']));
                $qualifyingRecords[$date] = $qualifies;
                $outOfScheduleMinutes += (int) $participation['out_of_schedule_work_minutes'];

                if ($qualifies && (int) ($record['is_early_departure'] ?? 0) === 1) {
                    $earlyDepartures++;
                    $earlyDepartureMinutes += (int) ($record['early_departure_minutes'] ?? 0);
                }

                if ($qualifies) {
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
                    $explicitAbsentRecords[$date] = true;
                }
            }
            $today = $asOf->format('Y-m-d');
            $expectedDays = 0;
            $derivedAbsent = 0;

            $fallbackMap = array_fill_keys(array_column($workingDays, 'date'), true);
            $endLimit = min(new DateTimeImmutable($to), new DateTimeImmutable($today));

            for ($day = new DateTimeImmutable($from); $day <= $endLimit; $day = $day->add(new DateInterval('P1D'))) {
                $date = $day->format('Y-m-d');

                $resolution = $this->resolvedWorkingDate($userId, $date, $fallbackMap);
                if ($resolution === null) {
                    continue;
                }
                if (isset($approvedLeaveDates[$date])) {
                    continue;
                }

                if (($qualifyingRecords[$date] ?? false) || isset($explicitAbsentRecords[$date])) {
                    $expectedDays++;
                    continue;
                }

                $missingStatus = $this->policy->deriveMissingAttendanceStatus(
                    $date,
                    (bool) ($resolution['is_working_day'] ?? false),
                    (bool) ($resolution['is_holiday'] ?? false),
                    false,
                    isset($resolution['scheduled_start_at']) ? (string) $resolution['scheduled_start_at'] : null,
                    $asOf,
                    isset($resolution['timezone']) ? (string) $resolution['timezone'] : null,
                );
                if ($missingStatus === 'absent') {
                    $expectedDays++;
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
                'early_departure_count' => $earlyDepartures, 'early_departure_minutes' => $earlyDepartureMinutes,
                'work_minutes' => $workMinutes, 'overtime_minutes' => $overtime, 'recorded_days' => $recordedDays,
                'out_of_schedule_work_minutes' => $outOfScheduleMinutes,
                'pending_overtime_minutes' => $pendingOvertimeMinutes,
                'attendance_percentage' => $percentage,
                'has_100_percent_attendance' => $has100Percent,
            ];
            $summary['total_employees']++;
            $summary['total_present_days'] += $present;
            $summary['total_absent_days'] += $absent;
            $summary['total_late_arrivals'] += $late;
            $summary['total_late_minutes'] += $lateMinutes;
            $summary['total_early_departures'] += $earlyDepartures;
            $summary['total_early_departure_minutes'] += $earlyDepartureMinutes;
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

    /** @param array<string, mixed> $record @param array<string, mixed>|null $resolution */
    private function recordParticipation(array $record, ?array $resolution, DateTimeImmutable $asOf): array
    {
        if ($resolution === null) {
            return ['has_scheduled_overlap' => false, 'scheduled_overlap_minutes' => 0, 'out_of_schedule_work_minutes' => 0];
        }
        $date = (string) ($record['attendance_date'] ?? '');
        $checkInAt = trim((string) ($record['check_in_at'] ?? ''));
        if ($checkInAt === '' && !empty($record['clock_in_time'])) {
            $checkInAt = $date . ' ' . substr((string) $record['clock_in_time'], 0, 8);
        }
        $checkOutAt = trim((string) ($record['check_out_at'] ?? ''));
        if ($checkOutAt === '' && !empty($record['clock_out_time'])) {
            $checkOutAt = $date . ' ' . substr((string) $record['clock_out_time'], 0, 8);
            if ($checkInAt !== '' && $checkOutAt < $checkInAt && (bool) ($resolution['is_overnight'] ?? false)) {
                $checkOutAt = (new DateTimeImmutable($checkOutAt))->modify('+1 day')->format('Y-m-d H:i:s');
            }
        }
        $activeAsOf = $checkOutAt === '' && $date === $asOf->format('Y-m-d') ? $asOf : null;

        return $this->calculator->scheduleParticipation(
            $resolution,
            $checkInAt !== '' ? $checkInAt : null,
            $checkOutAt !== '' ? $checkOutAt : null,
            $activeAsOf,
        );
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
                if ($this->resolvedWorkingDate($userId, $date, $fallbackWorkingDateMap) !== null) {
                    $map[$userId][$date] = true;
                }
            }
        }
        return $map;
    }

    /** @param array<string, bool> $fallbackWorkingDateMap */
    private function resolvedWorkingDate(int $userId, string $date, array $fallbackWorkingDateMap): ?array
    {
        if ($this->scheduleResolver !== null) {
            $resolution = $this->scheduleResolver->resolve($userId, $date);

            return (bool) ($resolution['is_working_day'] ?? false) && !(bool) ($resolution['is_holiday'] ?? false)
                ? $resolution
                : null;
        }

        if (!isset($fallbackWorkingDateMap[$date])) {
            return null;
        }

        return [
            'business_date' => $date,
            'is_working_day' => true,
            'is_holiday' => false,
            'scheduled_start_at' => $date . ' ' . AttendancePolicyService::SHIFT_START,
            'timezone' => date_default_timezone_get(),
        ];
    }
}
