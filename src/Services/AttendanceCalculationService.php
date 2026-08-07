<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;

final class AttendanceCalculationService
{
    public const VERSION = 'v3';

    /**
     * @param array<string, mixed> $resolution
     * @param array<string, mixed> $employee
     * @return array<string, mixed>
     */
    public function calculate(array $resolution, array $employee, ?string $checkInAt, ?string $checkOutAt, bool $manualOverride = false): array
    {
        $dayType = (string) $resolution['day_type'];
        $attendancePolicy = (array) ($resolution['attendance_policy'] ?? []);
        $overtimePolicy = (array) ($resolution['overtime_policy'] ?? []);
        $hasCheckIn = $checkInAt !== null && $checkInAt !== '';
        $hasCheckOut = $checkOutAt !== null && $checkOutAt !== '';
        $actualMinutes = $hasCheckIn && $hasCheckOut ? $this->minutesBetween($checkInAt, $checkOutAt) : null;

        if ($actualMinutes !== null && $actualMinutes <= 0) {
            throw new AttendancePolicyException('clock_out_before_clock_in');
        }
        $maximum = max(1, min(1440, (int) ($attendancePolicy['maximum_attendance_minutes'] ?? 960)));
        if ($actualMinutes !== null && $actualMinutes > $maximum) {
            throw new AttendancePolicyException('attendance_duration_too_long');
        }

        $state = 'absent';
        if ($hasCheckIn && !$hasCheckOut) {
            $state = 'incomplete';
        } elseif ($hasCheckIn && $hasCheckOut) {
            $state = 'present';
        }

        $lateMinutes = 0;
        if (in_array($state, ['present', 'incomplete'], true) && $dayType === 'working_day' && $hasCheckIn && !empty($resolution['scheduled_start_at'])) {
            $lateMinutes = $this->lateMinutes($checkInAt, (string) $resolution['scheduled_start_at'], (int) ($resolution['grace_minutes'] ?? 0));
        }

        $regularMinutes = null;
        $earlyDepartureMinutes = 0;
        $earlyOvertime = 0;
        $afterHoursOvertime = 0;
        $nonWorkingOvertime = 0;
        $holidayOvertime = 0;
        if ($actualMinutes !== null) {
            if ($dayType === 'working_day') {
                $regularMinutes = $this->workingOverlapMinutes($checkInAt, $checkOutAt, $resolution);
                $earlyOvertime = $this->minutesBefore($checkInAt, $checkOutAt, (string) $resolution['scheduled_start_at']);
                $afterHoursOvertime = $this->minutesAfter($checkInAt, $checkOutAt, (string) $resolution['scheduled_end_at']);
                if ((int) ($attendancePolicy['early_departure_enabled'] ?? 1) === 1) {
                    $earlyDepartureMinutes = max(0, (int) ($resolution['expected_regular_minutes'] ?? 0) - $regularMinutes);
                }
            } elseif ($dayType === 'holiday') {
                $holidayOvertime = $this->specialDayOvertime($actualMinutes, $overtimePolicy);
                $regularMinutes = 0;
            } else {
                $nonWorkingOvertime = $this->specialDayOvertime($actualMinutes, $overtimePolicy);
                $regularMinutes = 0;
            }
        }

        $overtimeEligible = (int) ($employee['overtime_eligible'] ?? 1) === 1;
        $rawOvertime = $earlyOvertime + $afterHoursOvertime + $nonWorkingOvertime + $holidayOvertime;
        $eligibleOvertime = $overtimeEligible ? $this->eligibleOvertime($dayType, $earlyOvertime, $afterHoursOvertime, $nonWorkingOvertime, $holidayOvertime, $overtimePolicy) : 0;
        $calculatedOvertime = $actualMinutes === null ? null : $this->applyOvertimeThresholdAndRounding($eligibleOvertime, $overtimePolicy);
        $approvalRequired = (int) ($overtimePolicy['approval_required'] ?? 0) === 1;
        $approvalStatus = $calculatedOvertime === null ? null : ($approvalRequired && $calculatedOvertime > 0 ? 'pending' : 'not_required');
        $approvedOvertime = $calculatedOvertime === null ? null : ($approvalStatus === 'pending' ? 0 : $calculatedOvertime);

        return [
            'attendance_state' => $state,
            'late_minutes' => $lateMinutes,
            'is_late' => $lateMinutes > 0 ? 1 : 0,
            'is_early_departure' => $earlyDepartureMinutes > 0 ? 1 : 0,
            'is_missing_check_out' => $state === 'incomplete' ? 1 : 0,
            'is_manual_override' => $manualOverride ? 1 : 0,
            'is_overnight_attendance' => $hasCheckIn && $hasCheckOut && substr((string) $checkInAt, 0, 10) !== substr((string) $checkOutAt, 0, 10) ? 1 : 0,
            'actual_work_minutes' => $actualMinutes,
            'working_hours' => $actualMinutes === null ? 0.0 : round($actualMinutes / 60, 2),
            'regular_minutes' => $regularMinutes,
            'early_departure_minutes' => $earlyDepartureMinutes,
            'early_overtime_minutes' => $earlyOvertime,
            'after_hours_overtime_minutes' => $afterHoursOvertime,
            'non_working_day_overtime_minutes' => $nonWorkingOvertime,
            'holiday_overtime_minutes' => $holidayOvertime,
            'raw_calculated_overtime_minutes' => $actualMinutes === null ? null : $rawOvertime,
            'calculated_overtime_minutes' => $calculatedOvertime,
            'approved_overtime_minutes' => $approvedOvertime,
            'overtime_approval_status' => $approvalStatus,
            'calculation_version' => self::VERSION,
        ];
    }

    /** @param array<string, mixed> $resolution */
    private function workingOverlapMinutes(string $checkInAt, string $checkOutAt, array $resolution): int
    {
        $minutes = $this->overlapMinutes($checkInAt, $checkOutAt, (string) $resolution['scheduled_start_at'], (string) $resolution['scheduled_end_at']);
        if (!empty($resolution['break_start_at']) && !empty($resolution['break_end_at'])) {
            $minutes -= $this->overlapMinutes($checkInAt, $checkOutAt, (string) $resolution['break_start_at'], (string) $resolution['break_end_at']);
        } elseif ((int) ($resolution['break_minutes'] ?? 0) > 0 && $minutes >= (int) ($resolution['expected_regular_minutes'] ?? 0) + (int) $resolution['break_minutes']) {
            $minutes -= (int) $resolution['break_minutes'];
        }

        return max(0, $minutes);
    }

    private function lateMinutes(string $checkInAt, string $scheduledStartAt, int $graceMinutes): int
    {
        $checkIn = new DateTimeImmutable($checkInAt);
        $start = new DateTimeImmutable($scheduledStartAt);
        if ($checkIn <= $start->modify('+' . max(0, $graceMinutes) . ' minutes')) {
            return 0;
        }

        return max(0, $this->minutesBetween($scheduledStartAt, $checkInAt));
    }

    /** @param array<string, mixed> $policy */
    private function specialDayOvertime(int $actualMinutes, array $policy): int
    {
        $threshold = $policy['special_day_break_threshold_minutes'] ?? null;
        $break = (int) ($policy['special_day_break_minutes'] ?? 0);
        if ($threshold !== null && (int) $threshold > 0 && $actualMinutes >= (int) $threshold) {
            return max(0, $actualMinutes - $break);
        }

        return $actualMinutes;
    }

    /** @param array<string, mixed> $policy */
    private function eligibleOvertime(string $dayType, int $early, int $after, int $nonWorking, int $holiday, array $policy): int
    {
        if ($dayType === 'holiday') {
            return (int) ($policy['holiday_work_eligible'] ?? 1) === 1 ? $holiday : 0;
        }
        if ($dayType === 'non_working_day') {
            return (int) ($policy['non_working_day_work_eligible'] ?? 1) === 1 ? $nonWorking : 0;
        }

        return ((int) ($policy['early_work_eligible'] ?? 1) === 1 ? $early : 0)
            + ((int) ($policy['after_hours_work_eligible'] ?? 1) === 1 ? $after : 0);
    }

    /** @param array<string, mixed> $policy */
    private function applyOvertimeThresholdAndRounding(int $minutes, array $policy): int
    {
        if ($minutes < (int) ($policy['minimum_overtime_minutes'] ?? 0)) {
            return 0;
        }

        $mode = (string) ($policy['rounding_mode'] ?? 'exact');
        if ($mode === 'exact') {
            return $minutes;
        }
        $interval = (int) ($policy['rounding_interval_minutes'] ?? 0);
        if (!in_array($interval, [5, 10, 15, 30], true)) {
            return $minutes;
        }

        return match ($mode) {
            'down' => intdiv($minutes, $interval) * $interval,
            'up' => (int) ceil($minutes / $interval) * $interval,
            'nearest' => (int) round($minutes / $interval) * $interval,
            default => $minutes,
        };
    }

    private function minutesBefore(string $checkInAt, string $checkOutAt, string $boundaryAt): int
    {
        return $this->overlapMinutes($checkInAt, $checkOutAt, $checkInAt, $boundaryAt);
    }

    private function minutesAfter(string $checkInAt, string $checkOutAt, string $boundaryAt): int
    {
        return $this->overlapMinutes($checkInAt, $checkOutAt, $boundaryAt, $checkOutAt);
    }

    private function overlapMinutes(string $startA, string $endA, string $startB, string $endB): int
    {
        $start = max((new DateTimeImmutable($startA))->getTimestamp(), (new DateTimeImmutable($startB))->getTimestamp());
        $end = min((new DateTimeImmutable($endA))->getTimestamp(), (new DateTimeImmutable($endB))->getTimestamp());

        return $end <= $start ? 0 : (int) floor(($end - $start) / 60);
    }

    private function minutesBetween(string $startAt, string $endAt): int
    {
        return (int) floor(((new DateTimeImmutable($endAt))->getTimestamp() - (new DateTimeImmutable($startAt))->getTimestamp()) / 60);
    }
}
