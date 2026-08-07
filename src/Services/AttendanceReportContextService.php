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
    ) {
        $this->calculator ??= new AttendanceCalculationService();
    }

    /** @param array<int, array<string, mixed>> $records @param array<int, array<string, mixed>>|null $approvedLeaves */
    public function enrich(array $records, ?array $approvedLeaves = null): array
    {
        if ($records === []) {
            return [];
        }
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

        $resolvedLegacy = $this->resolveLegacyRecords($records);
        foreach ($records as $index => &$record) {
            $date = (string) ($record['attendance_date'] ?? '');
            $userId = (int) ($record['user_id'] ?? 0);
            if (($record['attendance_state'] ?? null) === null && isset($resolvedLegacy[$index])) {
                $record = array_replace($record, $resolvedLegacy[$index]);
                $record['read_model_source'] = 'resolved_legacy';
            } elseif (($record['attendance_state'] ?? null) !== null) {
                $record['read_model_source'] = 'stored_v3';
            } else {
                $record['read_model_source'] = 'compatibility_legacy';
            }
            $record = $this->policy->enrichRecord($record, isset($holidayMap[$date]), isset($leaveMap[$userId][$date]));
            $record['has_approved_leave'] = isset($leaveMap[$userId][$date]);
        }
        unset($record);
        return $records;
    }

    /** @param array<int, array<string, mixed>> $records @return array<int, array<string, mixed>> */
    private function resolveLegacyRecords(array $records): array
    {
        if ($this->resolver === null) {
            return [];
        }
        $requests = [];
        $indexes = [];
        foreach ($records as $index => $record) {
            if (($record['attendance_state'] ?? null) !== null) {
                continue;
            }
            $userId = (int) ($record['user_id'] ?? 0);
            $date = (string) ($record['attendance_date'] ?? '');
            if ($userId <= 0 || $date === '') {
                continue;
            }
            $requests[] = ['employee_id' => $userId, 'business_date' => $date];
            $indexes[$userId . '|' . $date][] = $index;
        }
        if ($requests === []) {
            return [];
        }

        $resolutions = $this->resolver->resolveMany($requests);
        $resolved = [];
        foreach ($resolutions as $key => $resolution) {
            foreach ($indexes[$key] ?? [] as $index) {
                $record = $records[$index];
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
