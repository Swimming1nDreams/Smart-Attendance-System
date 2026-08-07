<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;

final class AttendancePolicyService
{
    public const DEFAULT_EXPECTED_HOURS = 8.0;
    public const MIN_EXPECTED_HOURS = 0.5;
    public const MAX_EXPECTED_HOURS = 24.0;
    public const SHIFT_START = '09:00:00';
    public const GRACE_PERIOD_END = '09:30:00';
    public const MISSING_CHECKOUT_CUTOFF_MINUTES = 60; // Grace period after expected shift end before marking incomplete

    public function __construct(private ?DateValidationService $dateValidator = null)
    {
        $this->dateValidator ??= new DateValidationService();
    }

    public function deriveMissingAttendanceStatus(string $date, bool $isWorkingDay, bool $isHoliday, bool $hasApprovedLeave): string
    {
        $today = date('Y-m-d');
        if ($date > $today) {
            return 'upcoming';
        }
        if ($hasApprovedLeave) {
            return 'leave';
        }
        if ($isHoliday) {
            return 'holiday';
        }
        if (!$isWorkingDay) {
            return 'weekend';
        }
        return 'absent';
    }

    public function expectedWorkMinutes(mixed $expectedHours): int
    {
        if (!is_numeric($expectedHours)) {
            return 480;
        }

        $hours = (float) $expectedHours;
        if ($hours < self::MIN_EXPECTED_HOURS || $hours > self::MAX_EXPECTED_HOURS) {
            return 480;
        }

        return (int) round($hours * 60);
    }

    public function calculateLateMinutes(?string $clockInTime, string $status = 'present'): int
    {
        if ($status !== 'present' || $clockInTime === null || $clockInTime === '') {
            return 0;
        }

        $clock = $this->parseTime($clockInTime);
        $shiftStart = $this->parseTime(self::SHIFT_START);
        $graceEnd = $this->parseTime(self::GRACE_PERIOD_END);

        // The grace period waives lateness; once exceeded, lateness starts at the official shift time.
        if ($clock <= $graceEnd) {
            return 0;
        }

        $interval = $shiftStart->diff($clock);

        return ($interval->h * 60) + $interval->i;
    }

    public function deriveStatus(string $attendanceDate, string $requestedStatus, ?string $clockInTime, ?string $clockOutTime, bool $isHoliday = false): string
    {
        if ($isHoliday) {
            return 'holiday';
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $attendanceDate);
        if ($date instanceof DateTimeImmutable && (int) $date->format('N') >= 6) {
            return 'weekend';
        }

        $hasClockTime = ($clockInTime !== null && $clockInTime !== '') || ($clockOutTime !== null && $clockOutTime !== '');

        return $hasClockTime ? 'present' : 'absent';
    }

    public function actualWorkMinutes(?string $clockInTime, ?string $clockOutTime): ?int
    {
        if ($clockInTime === null || $clockInTime === '' || $clockOutTime === null || $clockOutTime === '') {
            return null;
        }

        $clockIn = $this->parseTime($clockInTime);
        $clockOut = $this->parseTime($clockOutTime);

        if ($clockOut < $clockIn) {
            throw new AttendancePolicyException('clock_out_before_clock_in');
        }

        $interval = $clockIn->diff($clockOut);

        return ($interval->h * 60) + $interval->i;
    }

    public function workingHours(?int $actualWorkMinutes): float
    {
        return $actualWorkMinutes === null ? 0.0 : round($actualWorkMinutes / 60, 2);
    }

    private function actualWorkMinutesForRecord(array $record, ?string $clockInTime, ?string $clockOutTime): ?int
    {
        $checkInAt = isset($record['check_in_at']) ? (string) $record['check_in_at'] : '';
        $checkOutAt = isset($record['check_out_at']) ? (string) $record['check_out_at'] : '';

        if ($checkInAt !== '' && $checkOutAt !== '') {
            $minutes = (int) floor(
                ((new DateTimeImmutable($checkOutAt))->getTimestamp() - (new DateTimeImmutable($checkInAt))->getTimestamp()) / 60
            );

            if ($minutes <= 0) {
                throw new AttendancePolicyException('clock_out_before_clock_in');
            }

            return $minutes;
        }

        return $this->actualWorkMinutes($clockInTime, $clockOutTime);
    }

    /**
     * Overtime V2 is based on the employee's scheduled time boundaries.
     *
     * Normal workday:
     * - time before SHIFT_START is early overtime;
     * - time after SHIFT_START + expected work minutes is late overtime.
     *
     * Weekend/company holiday:
     * - all completed work minutes are special-day overtime.
     *
     * Approved leave and overtime-ineligible employees never receive overtime.
     */
    public function calculateOvertimeMinutes(
        string $status,
        ?int $actualWorkMinutes,
        mixed $expectedHours,
        string $attendanceDate,
        bool $isHoliday = false,
        bool $hasApprovedLeave = false,
        bool $overtimeEligible = true,
        ?string $clockInTime = null,
        ?string $clockOutTime = null
    ): ?int {
        if ($actualWorkMinutes === null) {
            return null;
        }

        if (!$overtimeEligible || $hasApprovedLeave || $status === 'absent') {
            return 0;
        }

        if (!$this->dateValidator->isValidDate($attendanceDate)) {
            return 0;
        }

        if ($this->isSpecialWorkday($status, $attendanceDate, $isHoliday)) {
            return $actualWorkMinutes;
        }

        if ($status !== 'present') {
            return 0;
        }

        // Real attendance read paths always provide exact clock times. Keep a safe
        // fallback for older direct callers that only supplied a completed duration.
        if ($clockInTime === null || $clockOutTime === null || $clockInTime === '' || $clockOutTime === '') {
            return max(0, $actualWorkMinutes - $this->expectedWorkMinutes($expectedHours));
        }

        $clockInMinute = $this->minuteOfDay($clockInTime);
        $clockOutMinute = $this->minuteOfDay($clockOutTime);
        if ($clockOutMinute < $clockInMinute) {
            throw new AttendancePolicyException('clock_out_before_clock_in');
        }

        $shiftStartMinute = $this->minuteOfDay(self::SHIFT_START);
        $scheduledEndMinute = min(24 * 60, $shiftStartMinute + $this->expectedWorkMinutes($expectedHours));

        $earlyOvertime = max(0, min($clockOutMinute, $shiftStartMinute) - $clockInMinute);
        $lateOvertime = max(0, $clockOutMinute - max($clockInMinute, $scheduledEndMinute));

        return $earlyOvertime + $lateOvertime;
    }

    public function enrichRecord(array $record, bool $isHoliday = false, bool $hasApprovedLeave = false): array
    {
        if (array_key_exists('attendance_state', $record) && ($record['attendance_state'] ?? null) !== null) {
            $clockInTime = isset($record['clock_in_time']) ? (string) $record['clock_in_time'] : null;
            $clockOutTime = isset($record['clock_out_time']) ? (string) $record['clock_out_time'] : null;
            $actualMinutes = isset($record['actual_work_minutes'])
                ? (int) $record['actual_work_minutes']
                : $this->actualWorkMinutesForRecord($record, $clockInTime, $clockOutTime);
            $overtimeMinutes = isset($record['approved_overtime_minutes']) && $record['approved_overtime_minutes'] !== null
                ? (int) $record['approved_overtime_minutes']
                : ($record['calculated_overtime_minutes'] ?? null);

            $record['actual_work_minutes'] = $actualMinutes;
            $record['working_hours'] = $this->workingHours($actualMinutes);
            $record['expected_work_minutes'] = (int) ($record['regular_minutes'] ?? 0);
            $record['overtime_minutes'] = $overtimeMinutes === null ? null : (int) $overtimeMinutes;
            $record['working_duration'] = $this->formatDuration($actualMinutes);
            $record['overtime_duration'] = $this->formatDuration($record['overtime_minutes']);
            $record['late_duration'] = $this->formatDuration((int) ($record['late_minutes'] ?? 0));
            $record['attendance_display_status'] = $this->displayStatusFromV3($record);

            return $record;
        }

        $status = (string) ($record['status'] ?? '');
        $clockInTime = isset($record['clock_in_time']) ? (string) $record['clock_in_time'] : null;
        $clockOutTime = isset($record['clock_out_time']) ? (string) $record['clock_out_time'] : null;
        $attendanceDate = (string) ($record['attendance_date'] ?? '');
        $expectedHours = $record['expected_daily_work_hours'] ?? null;

        $actualMinutes = $this->actualWorkMinutes($clockInTime, $clockOutTime);
        $lateMinutes = $this->calculateLateMinutes($clockInTime, $status);

        $record['late_minutes'] = $lateMinutes;
        $record['actual_work_minutes'] = $actualMinutes;
        $record['working_hours'] = $this->workingHours($actualMinutes);
        $record['expected_work_minutes'] = $this->expectedWorkMinutes($expectedHours);
        $record['overtime_minutes'] = $this->calculateOvertimeMinutes(
            $status,
            $actualMinutes,
            $expectedHours,
            $attendanceDate,
            $isHoliday,
            $hasApprovedLeave,
            (bool) ($record['overtime_eligible'] ?? true),
            $clockInTime,
            $clockOutTime
        );
        $record['working_duration'] = $this->formatDuration($actualMinutes);
        $record['overtime_duration'] = $this->formatDuration($record['overtime_minutes']);
        $record['late_duration'] = $this->formatDuration((int) ($record['late_minutes'] ?? 0));
        
        if ($status === 'present') {
            $isLate = $lateMinutes > 0;
            if ($clockOutTime === null || $clockOutTime === '') {
                $today = date('Y-m-d');
                $isOverdue = $attendanceDate < $today;
                if ($attendanceDate === $today && $clockInTime !== null && $clockInTime !== '') {
                    $now = new DateTimeImmutable();
                    $checkIn = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $attendanceDate . ' ' . $clockInTime);
                    if ($checkIn) {
                        // Estimate shift length + 1 hour break + cutoff
                        $cutoff = $checkIn->modify('+' . ($record['expected_work_minutes'] + 60 + self::MISSING_CHECKOUT_CUTOFF_MINUTES) . ' minutes');
                        if ($now > $cutoff) {
                            $isOverdue = true;
                        }
                    }
                }
                $record['attendance_display_status'] = $isOverdue ? 'incomplete' : ($isLate ? 'late' : 'checked_in');
            } else {
                $record['attendance_display_status'] = $isLate ? 'late' : 'present';
            }
        } else {
            $record['attendance_display_status'] = $status;
        }

        return $record;
    }

    /**
     * @param array{hours_minutes?: string, hours?: string, minutes?: string, less_than_minute?: string, empty?: string} $formats
     */
    public function formatDuration(?int $minutes, array $formats = []): string
    {
        $formats = array_replace([
            'hours_minutes' => '{hours} hr {minutes} min',
            'hours' => '{hours} hr',
            'minutes' => '{minutes} min',
            'less_than_minute' => '0 min',
            'empty' => '—',
        ], $formats);

        if ($minutes === null) {
            return $formats['empty'];
        }

        $minutes = max(0, $minutes);
        if ($minutes === 0) {
            return $formats['less_than_minute'];
        }

        $hours = intdiv($minutes, 60);
        $remaining = $minutes % 60;

        if ($hours === 0) {
            return strtr($formats['minutes'], ['{minutes}' => (string) $remaining]);
        }

        if ($remaining === 0) {
            return strtr($formats['hours'], ['{hours}' => (string) $hours]);
        }

        return strtr($formats['hours_minutes'], [
            '{hours}' => (string) $hours,
            '{minutes}' => (string) $remaining,
        ]);
    }

    private function isSpecialWorkday(string $status, string $attendanceDate, bool $isHoliday): bool
    {
        if ($isHoliday || $status === 'holiday' || $status === 'weekend') {
            return true;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $attendanceDate);
        if (!$date instanceof DateTimeImmutable) {
            return false;
        }

        return (int) $date->format('N') >= 6;
    }

    private function displayStatusFromV3(array $record): string
    {
        $dayType = (string) ($record['day_type'] ?? '');
        $state = (string) ($record['attendance_state'] ?? '');
        if ($dayType === 'holiday' && in_array($state, ['present', 'incomplete'], true)) {
            return 'holiday';
        }
        if ($dayType === 'non_working_day' && in_array($state, ['present', 'incomplete'], true)) {
            return 'weekend';
        }
        
        $isLate = (int) ($record['is_late'] ?? 0) === 1;

        if ($state === 'incomplete') {
            $attendanceDate = (string) ($record['attendance_date'] ?? '');
            $today = date('Y-m-d');
            $isOverdue = $attendanceDate < $today;
            
            if ($attendanceDate === $today) {
                $now = new DateTimeImmutable();
                $checkInTime = (string) ($record['clock_in_time'] ?? '09:00:00');
                $checkIn = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $attendanceDate . ' ' . substr($checkInTime, 0, 8));
                if ($checkIn) {
                    $expectedMinutes = (int) ($record['expected_work_minutes'] ?? 480);
                    if ($expectedMinutes <= 0) $expectedMinutes = 480;
                    $cutoff = $checkIn->modify('+' . ($expectedMinutes + 60 + self::MISSING_CHECKOUT_CUTOFF_MINUTES) . ' minutes');
                    if ($now > $cutoff) {
                        $isOverdue = true;
                    }
                }
            }
            
            if ($isOverdue) {
                return 'incomplete';
            }
            
            return $isLate ? 'late' : 'checked_in';
        }

        if ($isLate) {
            return 'late';
        }

        return (string) ($record['status'] ?? ($state !== '' ? $state : 'absent'));
    }

    private function minuteOfDay(string $value): int
    {
        $time = $this->parseTime($value);

        return ((int) $time->format('H') * 60) + (int) $time->format('i');
    }

    private function parseTime(string $value): DateTimeImmutable
    {
        $normalized = $this->dateValidator->normalizeTime($value);
        if ($normalized === null) {
            throw new AttendancePolicyException('invalid_time');
        }

        return DateTimeImmutable::createFromFormat('!H:i:s', $normalized);
    }
}
