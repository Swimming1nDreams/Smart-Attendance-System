<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Holiday;
use App\Models\LeaveRequest;
use DateInterval;
use DateTimeImmutable;

final class AttendanceReportContextService
{
    public function __construct(
        private Holiday $holidays,
        private LeaveRequest $leaveRequests,
        private AttendancePolicyService $policy,
        private ?WorkScheduleResolverService $resolver = null,
        private ?AttendanceCalculationService $calculator = null,
        private ?AttendanceSessionReadService $sessionReader = null,
    ) {
        $this->calculator ??= new AttendanceCalculationService();
    }

    /** @param array<int, array<string, mixed>> $records @param array<int, array<string, mixed>>|null $approvedLeaves */
    public function enrich(array $records, ?array $approvedLeaves = null, ?DateTimeImmutable $asOf = null): array
    {
        if ($records === []) {
            return [];
        }
        $asOf ??= new DateTimeImmutable('now');
        $dates = array_values(array_filter(array_map(static fn (array $record): string => (string) ($record['attendance_date'] ?? ''), $records)));
        $userIds = array_values(array_unique(array_map(static fn (array $record): int => (int) ($record['user_id'] ?? 0), $records)));
        $fromDate = min($dates);
        $toDate = max($dates);
        $holidayMap = [];
        foreach ($this->holidays->findInRange($fromDate, $toDate) as $holiday) {
            $holidayMap[(string) $holiday['holiday_date']] = true;
        }
        $approvedLeaves ??= $this->leaveRequests->findApprovedForUsersInRange($userIds, $fromDate, $toDate);
        $leaveMap = $this->approvedLeaveDateMap($approvedLeaves, $fromDate, $toDate);

        $resolvedSchedules = $this->resolveSchedules($records);
        $resolvedLegacy = $this->resolveLegacyRecords($records, $resolvedSchedules);
        $prefetchedSessions = $this->sessionReader?->prefetch($records) ?? [];
        foreach ($records as $index => &$record) {
            $date = (string) ($record['attendance_date'] ?? '');
            $userId = (int) ($record['user_id'] ?? 0);
            $sessionCalculation = null;
            if (isset($resolvedSchedules[$index]) && $this->sessionReader !== null) {
                $sessionCalculation = $this->sessionReader->calculateIfSessions(
                    $record,
                    $resolvedSchedules[$index],
                    $asOf,
                    $prefetchedSessions,
                );
                if ($sessionCalculation !== null) {
                    $record = $this->sessionReader->applyToRecord($record, $sessionCalculation);
                }
            }

            if ($sessionCalculation === null) {
                if (($record['attendance_state'] ?? null) === null && isset($resolvedLegacy[$index])) {
                    $record = array_replace($record, $resolvedLegacy[$index]);
                    $record['read_model_source'] = 'resolved_legacy';
                } elseif (($record['attendance_state'] ?? null) !== null) {
                    $record['read_model_source'] = 'stored_v3';
                } else {
                    $record['read_model_source'] = 'compatibility_legacy';
                }
            }
            if (isset($resolvedSchedules[$index])) {
                $record['scheduled_start_at'] = $resolvedSchedules[$index]['scheduled_start_at'] ?? null;
                $record['scheduled_end_at'] = $resolvedSchedules[$index]['scheduled_end_at'] ?? null;
                $record = $this->withScheduledParticipation(
                    $record,
                    $resolvedSchedules[$index],
                    isset($leaveMap[$userId][$date]),
                    $asOf,
                );
            }
            $record = $this->policy->enrichRecord($record, isset($holidayMap[$date]), isset($leaveMap[$userId][$date]));
            $record['scheduled_overlap_duration'] = $this->policy->formatDuration((int) ($record['scheduled_overlap_minutes'] ?? 0));
            $record['out_of_schedule_work_duration'] = $this->policy->formatDuration((int) ($record['out_of_schedule_work_minutes'] ?? 0));
            if (($record['scheduled_attendance_status'] ?? null) === 'absent') {
                $record['attendance_display_status'] = 'absent';
            } elseif (($record['scheduled_attendance_status'] ?? null) === 'pending' && !empty($record['has_actual_work_session'])) {
                $record['attendance_display_status'] = 'out_of_schedule';
            } elseif (($record['scheduled_attendance_status'] ?? null) === 'approved_leave') {
                $record['attendance_display_status'] = 'approved_leave';
            }
            $record['has_approved_leave'] = isset($leaveMap[$userId][$date]);
        }
        unset($record);
        return $records;
    }

    /** @param array<string, mixed> $record @param array<string, mixed> $resolution @return array<string, mixed> */
    private function withScheduledParticipation(array $record, array $resolution, bool $hasApprovedLeave, DateTimeImmutable $asOf): array
    {
        $date = (string) ($record['attendance_date'] ?? '');
        $checkInAt = trim((string) ($record['check_in_at'] ?? ''));
        if ($checkInAt === '') {
            $checkInAt = (string) ($this->dateTimeFor($date, $record['clock_in_time'] ?? null) ?? '');
        }
        $checkOutAt = trim((string) ($record['check_out_at'] ?? ''));
        if ($checkOutAt === '') {
            $checkOutAt = (string) ($this->dateTimeFor($date, $record['clock_out_time'] ?? null) ?? '');
        }
        if (($record['read_model_source'] ?? '') === 'attendance_sessions') {
            $participation = [
                'scheduled_overlap_seconds' => (int) ($record['scheduled_overlap_seconds'] ?? 0),
                'scheduled_overlap_minutes' => (int) ($record['scheduled_overlap_minutes'] ?? 0),
                'out_of_schedule_work_minutes' => (int) ($record['out_of_schedule_work_minutes'] ?? 0),
                'has_scheduled_overlap' => (bool) ($record['has_scheduled_overlap'] ?? false),
            ];
            $record['has_actual_work_session'] = (int) ($record['session_count'] ?? 0) > 0;
        } else {
            $activeAsOf = $checkOutAt === '' && $date === $asOf->format('Y-m-d') ? $asOf : null;
            $participation = $this->calculator->scheduleParticipation(
                $resolution,
                $checkInAt !== '' ? $checkInAt : null,
                $checkOutAt !== '' ? $checkOutAt : null,
                $activeAsOf,
            );
            $record = array_replace($record, $participation);
            $record['has_actual_work_session'] = $checkInAt !== '';
        }

        $dayType = (string) ($resolution['day_type'] ?? '');
        if ($hasApprovedLeave) {
            $record['scheduled_attendance_status'] = 'approved_leave';
            return $record;
        }
        if ($dayType === 'holiday') {
            $record['scheduled_attendance_status'] = 'holiday';
            return $record;
        }
        if ($dayType === 'non_working_day') {
            $record['scheduled_attendance_status'] = 'weekend';
            return $record;
        }
        if ($participation['has_scheduled_overlap']) {
            $record['scheduled_attendance_status'] = 'present';
            return $record;
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
        $record['scheduled_attendance_status'] = $missingStatus === 'absent' ? 'absent' : 'pending';
        $record['is_early_departure'] = 0;
        $record['early_departure_minutes'] = 0;
        if ($checkOutAt !== '') {
            $record['attendance_state'] = 'out_of_schedule';
        }

        return $record;
    }

    /** @param array<int, array<string, mixed>> $records @return array<int, array<string, mixed>> */
    private function resolveLegacyRecords(array $records, array $resolvedSchedules): array
    {
        $resolved = [];
        foreach ($records as $index => $record) {
            if (($record['attendance_state'] ?? null) !== null) {
                continue;
            }
            $resolution = $resolvedSchedules[$index] ?? null;
            if ($resolution === null) {
                continue;
            }
            $clockInAt = $this->dateTimeFor((string) ($record['attendance_date'] ?? ''), $record['clock_in_time'] ?? null);
            $clockOutAt = $this->dateTimeFor((string) ($record['attendance_date'] ?? ''), $record['clock_out_time'] ?? null);
            if ($clockInAt !== null && $clockOutAt !== null && $clockOutAt < $clockInAt && (bool) ($resolution['is_overnight'] ?? false)) {
                $clockOutAt = (new DateTimeImmutable($clockOutAt))->modify('+1 day')->format('Y-m-d H:i:s');
            }
            try {
                $calculation = $this->calculator->calculate($resolution, $record, $clockInAt, $clockOutAt);
            } catch (AttendancePolicyException) {
                continue;
            }
            $calculation['status'] = $this->legacyStatus($resolution, (string) $calculation['attendance_state']);
            $calculation['day_type'] = (string) $resolution['day_type'];
            $calculation['resolved_policy_version_id'] = (int) $resolution['policy_version_id'];
            $calculation['resolved_assignment_id'] = $resolution['assignment_id'];
            $calculation['resolved_policy_name'] = (string) ($resolution['policy_name'] ?? '');
            $calculation['assignment_scope'] = (string) ($resolution['assignment_scope'] ?? 'compatibility');
            $calculation['scheduled_start_at'] = $resolution['scheduled_start_at'];
            $calculation['scheduled_end_at'] = $resolution['scheduled_end_at'];
            $calculation['calculation_version'] = AttendanceCalculationService::VERSION . '-read';
            $resolved[$index] = $calculation;
        }

        return $resolved;
    }

    /** @param array<int, array<string, mixed>> $records @return array<int, array<string, mixed>> */
    private function resolveSchedules(array $records): array
    {
        if ($this->resolver === null) {
            return [];
        }

        $requests = [];
        $indexes = [];
        foreach ($records as $index => $record) {
            $userId = (int) ($record['user_id'] ?? 0);
            $date = (string) ($record['attendance_date'] ?? '');
            if ($userId <= 0 || $date === '') {
                continue;
            }
            $key = $userId . '|' . $date;
            if (!isset($indexes[$key])) {
                $requests[] = ['employee_id' => $userId, 'business_date' => $date];
            }
            $indexes[$key][] = $index;
        }

        $resolved = [];
        foreach ($this->resolver->resolveMany($requests) as $key => $resolution) {
            foreach ($indexes[$key] ?? [] as $index) {
                $resolved[$index] = $resolution;
            }
        }

        return $resolved;
    }

    private function dateTimeFor(string $date, mixed $time): ?string
    {
        $time = trim((string) $time);
        if ($date === '' || $time === '') {
            return null;
        }

        return $date . ' ' . substr($time, 0, 8);
    }

    /** @param array<string, mixed> $resolution */
    private function legacyStatus(array $resolution, string $attendanceState): string
    {
        if ($attendanceState === 'absent') {
            return 'absent';
        }
        if (($resolution['day_type'] ?? '') === 'holiday') {
            return 'holiday';
        }
        if (($resolution['day_type'] ?? '') === 'non_working_day') {
            return 'weekend';
        }

        return 'present';
    }

    /** @param array<int, array<string, mixed>> $leaves @return array<int, array<string, array<int, array<string, mixed>>>> */
    public function approvedLeaveDateMap(array $leaves, string $fromDate = '1000-01-01', string $toDate = '9999-12-31'): array
    {
        $map = [];
        $rangeStart = new DateTimeImmutable($fromDate);
        $rangeEnd = new DateTimeImmutable($toDate);
        foreach ($leaves as $leave) {
            $userId = (int) ($leave['user_id'] ?? 0);
            if ($userId <= 0) {
                continue;
            }
            $start = max(new DateTimeImmutable((string) $leave['start_date']), $rangeStart);
            $end = min(new DateTimeImmutable((string) $leave['end_date']), $rangeEnd);
            for ($day = $start; $day <= $end; $day = $day->add(new DateInterval('P1D'))) {
                $map[$userId][$day->format('Y-m-d')][] = $leave;
            }
        }
        return $map;
    }
}
