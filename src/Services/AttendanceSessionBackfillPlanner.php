<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Converts one legacy daily attendance row into the single physical interval
 * that can be safely backfilled into attendance_sessions.
 *
 * The planner is intentionally storage-agnostic. It never mutates a row and
 * treats incomplete or ambiguous historical data as manual-review material.
 */
final class AttendanceSessionBackfillPlanner
{
    /**
     * @param array<string, mixed> $record
     * @param list<array<string, mixed>> $existingSessions
     * @return array<string, mixed>
     */
    public function plan(array $record, array $existingSessions = [], ?DateTimeZone $timezone = null): array
    {
        $timezone ??= new DateTimeZone(date_default_timezone_get());
        $attendanceId = (int) ($record['id'] ?? 0);
        $businessDate = trim((string) ($record['business_date'] ?? ''));
        if ($businessDate === '') {
            $businessDate = trim((string) ($record['attendance_date'] ?? ''));
        }

        $base = [
            'attendance_id' => $attendanceId,
            'user_id' => (int) ($record['user_id'] ?? 0),
            'attendance_date' => trim((string) ($record['attendance_date'] ?? '')),
            'business_date' => $businessDate,
            'classification' => 'manual_review',
            'source' => null,
            'check_in_at' => null,
            'check_out_at' => null,
            'duration_minutes' => null,
            'issues' => [],
        ];

        if ($attendanceId <= 0 || $businessDate === '') {
            $base['issues'][] = 'missing_parent_identity';
            return $base;
        }

        $interval = $this->resolveLegacyInterval($record, $businessDate, $timezone);
        $base = array_replace($base, $interval);

        if (($interval['classification'] ?? '') !== 'candidate') {
            return $base;
        }

        if ($existingSessions !== []) {
            if (count($existingSessions) === 1 && $this->matches($existingSessions[0], (string) $interval['check_in_at'], (string) $interval['check_out_at'])) {
                $base['classification'] = 'already_backfilled';
                return $base;
            }

            $base['classification'] = 'existing_session_conflict';
            $base['issues'][] = count($existingSessions) > 1
                ? 'multiple_existing_sessions'
                : 'existing_session_differs_from_legacy_interval';
            return $base;
        }

        return $base;
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    private function resolveLegacyInterval(array $record, string $businessDate, DateTimeZone $timezone): array
    {
        $fullCheckIn = trim((string) ($record['check_in_at'] ?? ''));
        $fullCheckOut = trim((string) ($record['check_out_at'] ?? ''));
        $clockIn = trim((string) ($record['clock_in_time'] ?? ''));
        $clockOut = trim((string) ($record['clock_out_time'] ?? ''));

        $hasAnyCheckIn = $fullCheckIn !== '' || $clockIn !== '';
        $hasAnyCheckOut = $fullCheckOut !== '' || $clockOut !== '';

        if (!$hasAnyCheckIn && !$hasAnyCheckOut) {
            return [
                'classification' => 'no_physical_session',
                'source' => null,
                'check_in_at' => null,
                'check_out_at' => null,
                'duration_minutes' => null,
                'issues' => [],
            ];
        }

        if (!$hasAnyCheckIn && $hasAnyCheckOut) {
            return [
                'classification' => 'manual_review',
                'source' => null,
                'check_in_at' => null,
                'check_out_at' => null,
                'duration_minutes' => null,
                'issues' => ['checkout_without_checkin'],
            ];
        }

        $checkInAt = $fullCheckIn !== ''
            ? $this->parseDateTime($fullCheckIn, $timezone)
            : $this->combine($businessDate, $clockIn, $timezone);
        if (!$checkInAt instanceof DateTimeImmutable) {
            return [
                'classification' => 'manual_review',
                'source' => null,
                'check_in_at' => null,
                'check_out_at' => null,
                'duration_minutes' => null,
                'issues' => ['invalid_checkin_datetime'],
            ];
        }

        if (!$hasAnyCheckOut) {
            return [
                'classification' => 'skipped_incomplete',
                'source' => $fullCheckIn !== '' ? 'full_datetime' : 'legacy_clock_time',
                'check_in_at' => $checkInAt->format('Y-m-d H:i:s'),
                'check_out_at' => null,
                'duration_minutes' => null,
                'issues' => ['missing_checkout'],
            ];
        }

        $source = $fullCheckIn !== '' && $fullCheckOut !== '' ? 'full_datetime' : 'legacy_clock_time';
        $checkOutAt = $fullCheckOut !== ''
            ? $this->parseDateTime($fullCheckOut, $timezone)
            : $this->combine($businessDate, $clockOut, $timezone);
        if (!$checkOutAt instanceof DateTimeImmutable) {
            return [
                'classification' => 'manual_review',
                'source' => $source,
                'check_in_at' => $checkInAt->format('Y-m-d H:i:s'),
                'check_out_at' => null,
                'duration_minutes' => null,
                'issues' => ['invalid_checkout_datetime'],
            ];
        }

        if ($checkOutAt <= $checkInAt) {
            $isOvernight = (int) ($record['is_overnight_attendance'] ?? 0) === 1;
            $reconstructedFromClockOnly = $fullCheckOut === '';
            if ($isOvernight && $reconstructedFromClockOnly) {
                $checkOutAt = $checkOutAt->modify('+1 day');
            }
        }

        if ($checkOutAt <= $checkInAt) {
            return [
                'classification' => 'manual_review',
                'source' => $source,
                'check_in_at' => $checkInAt->format('Y-m-d H:i:s'),
                'check_out_at' => $checkOutAt->format('Y-m-d H:i:s'),
                'duration_minutes' => null,
                'issues' => [
                    (int) ($record['is_overnight_attendance'] ?? 0) === 1
                        ? 'invalid_overnight_interval'
                        : 'ambiguous_or_invalid_interval',
                ],
            ];
        }

        $durationMinutes = (int) floor(($checkOutAt->getTimestamp() - $checkInAt->getTimestamp()) / 60);

        return [
            'classification' => 'candidate',
            'source' => $source,
            'check_in_at' => $checkInAt->format('Y-m-d H:i:s'),
            'check_out_at' => $checkOutAt->format('Y-m-d H:i:s'),
            'duration_minutes' => $durationMinutes,
            'issues' => [],
        ];
    }

    /** @param array<string, mixed> $session */
    private function matches(array $session, string $checkInAt, string $checkOutAt): bool
    {
        return trim((string) ($session['check_in_at'] ?? '')) === $checkInAt
            && trim((string) ($session['check_out_at'] ?? '')) === $checkOutAt;
    }

    private function combine(string $date, string $time, DateTimeZone $timezone): ?DateTimeImmutable
    {
        if ($date === '' || $time === '') {
            return null;
        }

        return $this->parseDateTime($date . ' ' . $time, $timezone);
    }

    private function parseDateTime(string $value, DateTimeZone $timezone): ?DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($value, $timezone);
        } catch (\Throwable) {
            return null;
        }
    }
}
