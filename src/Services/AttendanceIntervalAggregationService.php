<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Builds one truthful daily timeline from one or more physical attendance
 * sessions. The service is deliberately storage-agnostic so the same rules
 * can be exercised in unit tests and reused during the later write cutover.
 */
final class AttendanceIntervalAggregationService
{
    /**
     * @param list<array<string, mixed>> $sessions
     * @param array<string, mixed> $resolution
     * @return array<string, mixed>
     */
    public function aggregate(array $sessions, array $resolution, ?DateTimeImmutable $asOf = null): array
    {
        $timezone = $this->timezone($resolution, $asOf);
        $scheduledStart = $this->dateTime($resolution['scheduled_start_at'] ?? null, $timezone);
        $scheduledEnd = $this->dateTime($resolution['scheduled_end_at'] ?? null, $timezone);
        $breakStart = $this->dateTime($resolution['break_start_at'] ?? null, $timezone);
        $breakEnd = $this->dateTime($resolution['break_end_at'] ?? null, $timezone);

        $normalized = [];
        $hasOpenSession = false;
        $firstCheckInAt = null;
        $lastCompletedCheckoutAt = null;
        $isOvernightAttendance = false;
        $hasInstantScheduledPresence = false;
        $instantScheduledEntryTimestamp = null;

        foreach ($sessions as $session) {
            $checkInRaw = trim((string) ($session['check_in_at'] ?? ''));
            if ($checkInRaw === '') {
                continue;
            }

            $checkIn = new DateTimeImmutable($checkInRaw, $timezone);
            $firstCheckInAt = $firstCheckInAt === null || $checkIn < $firstCheckInAt ? $checkIn : $firstCheckInAt;

            $checkOutRaw = trim((string) ($session['check_out_at'] ?? ''));
            if ($checkOutRaw === '') {
                $hasOpenSession = true;
                $checkOut = $asOf?->setTimezone($timezone);
                if (!$checkOut instanceof DateTimeImmutable || $checkOut < $checkIn) {
                    continue;
                }

                $maximumMinutes = max(1, min(1440, (int) (($resolution['attendance_policy'] ?? [])['maximum_attendance_minutes'] ?? 960)));
                $staleThreshold = $checkIn->modify('+' . $maximumMinutes . ' minutes');
                if ($checkOut >= $staleThreshold) {
                    continue;
                }

                if ($checkOut->getTimestamp() === $checkIn->getTimestamp()) {
                    if ($scheduledStart instanceof DateTimeImmutable && $scheduledEnd instanceof DateTimeImmutable && $checkIn >= $scheduledStart && $checkIn < $scheduledEnd) {
                        $hasInstantScheduledPresence = true;
                        $instantScheduledEntryTimestamp = $instantScheduledEntryTimestamp === null ? $checkIn->getTimestamp() : min($instantScheduledEntryTimestamp, $checkIn->getTimestamp());
                    }
                    continue;
                }
            } else {
                $checkOut = new DateTimeImmutable($checkOutRaw, $timezone);
                if ($checkOut <= $checkIn) {
                    throw new AttendancePolicyException('clock_out_before_clock_in');
                }
                $lastCompletedCheckoutAt = $lastCompletedCheckoutAt === null || $checkOut > $lastCompletedCheckoutAt
                    ? $checkOut
                    : $lastCompletedCheckoutAt;
            }

            if ($checkIn->format('Y-m-d') !== $checkOut->format('Y-m-d')) {
                $isOvernightAttendance = true;
            }

            $normalized[] = [
                'start' => $checkIn->getTimestamp(),
                'end' => $checkOut->getTimestamp(),
            ];
        }

        $union = $this->mergeIntervals($normalized);
        $actualSeconds = $this->durationSeconds($union);
        $scheduledOverlapSeconds = 0;
        $preShiftSeconds = 0;
        $postShiftSeconds = 0;
        $breakOverlapSeconds = 0;
        $scheduledEntryTimestamp = $instantScheduledEntryTimestamp;
        $scheduledExitTimestamp = null;

        if ($scheduledStart instanceof DateTimeImmutable && $scheduledEnd instanceof DateTimeImmutable) {
            $scheduledStartTs = $scheduledStart->getTimestamp();
            $scheduledEndTs = $scheduledEnd->getTimestamp();

            foreach ($union as $interval) {
                $beforeEnd = min($interval['end'], $scheduledStartTs);
                if ($beforeEnd > $interval['start']) {
                    $preShiftSeconds += $beforeEnd - $interval['start'];
                }

                $overlapStart = max($interval['start'], $scheduledStartTs);
                $overlapEnd = min($interval['end'], $scheduledEndTs);
                if ($overlapEnd > $overlapStart) {
                    $scheduledOverlapSeconds += $overlapEnd - $overlapStart;
                    $scheduledEntryTimestamp = $scheduledEntryTimestamp === null
                        ? $overlapStart
                        : min($scheduledEntryTimestamp, $overlapStart);
                    $scheduledExitTimestamp = $scheduledExitTimestamp === null
                        ? $overlapEnd
                        : max($scheduledExitTimestamp, $overlapEnd);
                }

                $afterStart = max($interval['start'], $scheduledEndTs);
                if ($interval['end'] > $afterStart) {
                    $postShiftSeconds += $interval['end'] - $afterStart;
                }

                if ($breakStart instanceof DateTimeImmutable && $breakEnd instanceof DateTimeImmutable) {
                    $breakOverlapStart = max($interval['start'], $breakStart->getTimestamp());
                    $breakOverlapEnd = min($interval['end'], $breakEnd->getTimestamp());
                    if ($breakOverlapEnd > $breakOverlapStart) {
                        $breakOverlapSeconds += $breakOverlapEnd - $breakOverlapStart;
                    }
                }
            }
        }

        $outOfScheduleSeconds = $preShiftSeconds + $postShiftSeconds;
        $lastCheckoutAt = $hasOpenSession ? null : $lastCompletedCheckoutAt;

        return [
            'session_count' => count($sessions),
            'measured_session_count' => count($normalized),
            'merged_interval_count' => count($union),
            'has_open_session' => $hasOpenSession,
            'first_check_in_at' => $firstCheckInAt?->format('Y-m-d H:i:s'),
            'last_check_out_at' => $lastCheckoutAt?->format('Y-m-d H:i:s'),
            'last_completed_check_out_at' => $lastCompletedCheckoutAt?->format('Y-m-d H:i:s'),
            'scheduled_entry_at' => $scheduledEntryTimestamp === null
                ? null
                : (new DateTimeImmutable('@' . $scheduledEntryTimestamp))->setTimezone($timezone)->format('Y-m-d H:i:s'),
            'scheduled_exit_at' => $scheduledExitTimestamp === null
                ? null
                : (new DateTimeImmutable('@' . $scheduledExitTimestamp))->setTimezone($timezone)->format('Y-m-d H:i:s'),
            'actual_work_seconds' => $actualSeconds,
            'actual_work_minutes' => (int) floor($actualSeconds / 60),
            'scheduled_overlap_seconds' => $scheduledOverlapSeconds,
            'scheduled_overlap_minutes' => (int) floor($scheduledOverlapSeconds / 60),
            'pre_shift_work_seconds' => $preShiftSeconds,
            'pre_shift_work_minutes' => (int) floor($preShiftSeconds / 60),
            'post_shift_work_seconds' => $postShiftSeconds,
            'post_shift_work_minutes' => (int) floor($postShiftSeconds / 60),
            'out_of_schedule_work_seconds' => $outOfScheduleSeconds,
            'out_of_schedule_work_minutes' => (int) floor($outOfScheduleSeconds / 60),
            'break_overlap_seconds' => $breakOverlapSeconds,
            'break_overlap_minutes' => (int) floor($breakOverlapSeconds / 60),
            'has_scheduled_overlap' => $scheduledOverlapSeconds > 0 || $hasInstantScheduledPresence,
            'is_overnight_attendance' => $isOvernightAttendance ? 1 : 0,
        ];
    }

    /**
     * @param list<array{start:int,end:int}> $intervals
     * @return list<array{start:int,end:int}>
     */
    private function mergeIntervals(array $intervals): array
    {
        if ($intervals === []) {
            return [];
        }

        usort($intervals, static fn (array $left, array $right): int => $left['start'] <=> $right['start'] ?: $left['end'] <=> $right['end']);
        $merged = [];
        foreach ($intervals as $interval) {
            $lastIndex = count($merged) - 1;
            if ($lastIndex < 0 || $interval['start'] > $merged[$lastIndex]['end']) {
                $merged[] = $interval;
                continue;
            }

            $merged[$lastIndex]['end'] = max($merged[$lastIndex]['end'], $interval['end']);
        }

        return $merged;
    }

    /** @param list<array{start:int,end:int}> $intervals */
    private function durationSeconds(array $intervals): int
    {
        $seconds = 0;
        foreach ($intervals as $interval) {
            $seconds += max(0, $interval['end'] - $interval['start']);
        }

        return $seconds;
    }

    /** @param array<string, mixed> $resolution */
    private function timezone(array $resolution, ?DateTimeImmutable $asOf): DateTimeZone
    {
        $timezoneName = trim((string) ($resolution['timezone'] ?? ''));
        if ($timezoneName !== '' && in_array($timezoneName, DateTimeZone::listIdentifiers(), true)) {
            return new DateTimeZone($timezoneName);
        }

        return $asOf?->getTimezone() ?? new DateTimeZone(date_default_timezone_get());
    }

    private function dateTime(mixed $value, DateTimeZone $timezone): ?DateTimeImmutable
    {
        $value = trim((string) $value);
        return $value === '' ? null : new DateTimeImmutable($value, $timezone);
    }
}
