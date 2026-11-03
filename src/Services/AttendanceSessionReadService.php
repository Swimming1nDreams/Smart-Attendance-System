<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AttendanceSession;
use DateTimeImmutable;

/**
 * Dual-read bridge for the additive attendance_sessions table.
 *
 * If a daily parent has child sessions, read-time calculations use those
 * sessions. If no child rows exist, callers keep their existing legacy parent
 * logic unchanged. This makes the session rollout additive and backfill-safe.
 */
final class AttendanceSessionReadService
{
    public function __construct(
        private AttendanceSession $sessions,
        private AttendanceCalculationService $calculator,
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $records
     * @return array<int, list<array<string, mixed>>>
     */
    public function prefetch(array $records): array
    {
        $ids = [];
        foreach ($records as $record) {
            $id = (int) ($record['id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return $this->sessions->findByAttendanceIds($ids);
    }

    /**
     * @param array<string, mixed> $record
     * @param array<string, mixed> $resolution
     * @param array<int, list<array<string, mixed>>>|null $prefetched
     * @return array<string, mixed>|null
     */
    public function calculateIfSessions(
        array $record,
        array $resolution,
        ?DateTimeImmutable $asOf = null,
        ?array $prefetched = null,
    ): ?array {
        $attendanceId = (int) ($record['id'] ?? 0);
        if ($attendanceId <= 0) {
            return null;
        }

        $sessions = $prefetched !== null
            ? ($prefetched[$attendanceId] ?? [])
            : $this->sessions->findByAttendanceId($attendanceId);
        if ($sessions === []) {
            return null;
        }

        $calculation = $this->calculator->calculateFromSessions(
            $resolution,
            $record,
            $sessions,
            (int) ($record['is_manual_override'] ?? 0) === 1,
            $asOf,
        );
        $calculation['session_read_source'] = 'attendance_sessions';

        return $calculation;
    }

    /**
     * Apply session-derived read values while intentionally preserving the
     * persisted overtime review decision. Decision invalidation is handled by
     * the transactional session write path when a physical session changes.
     *
     * @param array<string, mixed> $record
     * @param array<string, mixed> $calculation
     * @return array<string, mixed>
     */
    public function applyToRecord(array $record, array $calculation): array
    {
        $preservedApprovalStatus = $record['overtime_approval_status'] ?? null;
        $preservedApprovedMinutes = $record['approved_overtime_minutes'] ?? null;

        foreach ([
            'attendance_state',
            'late_minutes',
            'is_late',
            'is_early_departure',
            'is_missing_check_out',
            'is_overnight_attendance',
            'actual_work_minutes',
            'scheduled_overlap_seconds',
            'scheduled_overlap_minutes',
            'out_of_schedule_work_seconds',
            'out_of_schedule_work_minutes',
            'pre_shift_work_minutes',
            'post_shift_work_minutes',
            'has_scheduled_overlap',
            'working_hours',
            'regular_minutes',
            'early_departure_minutes',
            'early_departure_candidate_minutes',
            'early_departure_provisional',
            'early_overtime_minutes',
            'after_hours_overtime_minutes',
            'non_working_day_overtime_minutes',
            'holiday_overtime_minutes',
            'raw_calculated_overtime_minutes',
            'calculated_overtime_minutes',
            'session_count',
            'merged_interval_count',
            'has_open_session',
            'scheduled_entry_at',
            'scheduled_exit_at',
            'calculation_version',
        ] as $key) {
            if (array_key_exists($key, $calculation)) {
                $record[$key] = $calculation[$key];
            }
        }

        $firstCheckInAt = trim((string) ($calculation['first_check_in_at'] ?? ''));
        $lastCheckOutAt = trim((string) ($calculation['last_check_out_at'] ?? ''));
        if ($firstCheckInAt !== '') {
            $record['check_in_at'] = $firstCheckInAt;
            $record['clock_in_time'] = substr($firstCheckInAt, 11, 8);
        }
        if ((bool) ($calculation['has_open_session'] ?? false)) {
            $record['check_out_at'] = null;
            $record['clock_out_time'] = null;
        } elseif ($lastCheckOutAt !== '') {
            $record['check_out_at'] = $lastCheckOutAt;
            $record['clock_out_time'] = substr($lastCheckOutAt, 11, 8);
        }

        if ((int) ($calculation['is_early_departure'] ?? 0) !== 1) {
            $record['early_departure_reason'] = null;
            $record['early_departure_note'] = null;
        }

        // A prior Admin decision is persisted business state. Read-only session
        // enrichment preserves it; transactional session writes reset it when
        // later physical work invalidates the reviewed candidate.
        if ($preservedApprovalStatus !== null) {
            $record['overtime_approval_status'] = $preservedApprovalStatus;
        } elseif (array_key_exists('overtime_approval_status', $calculation)) {
            $record['overtime_approval_status'] = $calculation['overtime_approval_status'];
        }
        if ($preservedApprovedMinutes !== null) {
            $record['approved_overtime_minutes'] = $preservedApprovedMinutes;
        } elseif (array_key_exists('approved_overtime_minutes', $calculation)) {
            $record['approved_overtime_minutes'] = $calculation['approved_overtime_minutes'];
        }

        $record['read_model_source'] = 'attendance_sessions';

        return $record;
    }
}
