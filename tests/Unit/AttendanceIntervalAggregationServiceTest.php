<?php

declare(strict_types=1);

use App\Services\AttendanceCalculationService;
use App\Services\AttendanceIntervalAggregationService;

/** @return array<string, mixed> */
function session_resolution(string $start = '2026-08-12 09:00:00', string $end = '2026-08-12 17:00:00'): array
{
    return [
        'day_type' => 'working_day',
        'scheduled_start_at' => $start,
        'scheduled_end_at' => $end,
        'timezone' => 'Asia/Yangon',
        'grace_minutes' => 30,
        'break_minutes' => 0,
        'expected_regular_minutes' => 480,
        'attendance_policy' => [
            'maximum_attendance_minutes' => 960,
            'early_departure_enabled' => 1,
            'early_departure_grace_minutes' => 10,
        ],
        'overtime_policy' => [
            'early_work_eligible' => 1,
            'after_hours_work_eligible' => 1,
            'non_working_day_work_eligible' => 1,
            'holiday_work_eligible' => 1,
            'minimum_overtime_minutes' => 0,
            'rounding_mode' => 'exact',
            'approval_required' => 1,
        ],
    ];
}

/** @return array<string, mixed> */
function session_employee(): array
{
    return ['overtime_eligible' => 1];
}

test('interval union separates scheduled and out-of-schedule work across multiple sessions', function (): void {
    $service = new AttendanceIntervalAggregationService();
    $aggregate = $service->aggregate([
        ['check_in_at' => '2026-08-12 04:37:00', 'check_out_at' => '2026-08-12 06:47:00'],
        ['check_in_at' => '2026-08-12 09:00:00', 'check_out_at' => '2026-08-12 17:00:00'],
    ], session_resolution());

    assert_same(610, $aggregate['actual_work_minutes']);
    assert_same(480, $aggregate['scheduled_overlap_minutes']);
    assert_same(130, $aggregate['pre_shift_work_minutes']);
    assert_same(0, $aggregate['post_shift_work_minutes']);
    assert_same(130, $aggregate['out_of_schedule_work_minutes']);
    assert_same('2026-08-12 04:37:00', $aggregate['first_check_in_at']);
    assert_same('2026-08-12 17:00:00', $aggregate['last_check_out_at']);
    assert_same(2, $aggregate['session_count']);
    assert_same(2, $aggregate['merged_interval_count']);
});

test('completed pre-shift-only work remains out of schedule without scheduled participation', function (): void {
    $calculator = new AttendanceCalculationService(new AttendanceIntervalAggregationService());
    $result = $calculator->calculateFromSessions(
        session_resolution(),
        session_employee(),
        [['check_in_at' => '2026-08-12 04:30:00', 'check_out_at' => '2026-08-12 06:30:00']],
        false,
        new DateTimeImmutable('2026-08-12 07:00:00', new DateTimeZone('Asia/Yangon')),
    );

    assert_same('out_of_schedule', $result['attendance_state']);
    assert_same(120, $result['actual_work_minutes']);
    assert_same(120, $result['pre_shift_work_minutes']);
    assert_same(120, $result['out_of_schedule_work_minutes']);
    assert_same(0, $result['scheduled_overlap_minutes']);
    assert_same(0, (int) $result['has_scheduled_overlap']);
    assert_null($result['scheduled_entry_at']);
});

test('interval union defensively prevents overlapping sessions from double counting work', function (): void {
    $service = new AttendanceIntervalAggregationService();
    $aggregate = $service->aggregate([
        ['check_in_at' => '2026-08-12 08:00:00', 'check_out_at' => '2026-08-12 11:00:00'],
        ['check_in_at' => '2026-08-12 10:00:00', 'check_out_at' => '2026-08-12 13:00:00'],
        ['check_in_at' => '2026-08-12 13:00:00', 'check_out_at' => '2026-08-12 17:00:00'],
    ], session_resolution());

    assert_same(540, $aggregate['actual_work_minutes']);
    assert_same(480, $aggregate['scheduled_overlap_minutes']);
    assert_same(60, $aggregate['pre_shift_work_minutes']);
    assert_same(1, $aggregate['merged_interval_count']);
});

test('multi-session calculation uses scheduled entry for Late and final scheduled exit for Early Departure', function (): void {
    $calculator = new AttendanceCalculationService(new AttendanceIntervalAggregationService());
    $result = $calculator->calculateFromSessions(
        session_resolution(),
        session_employee(),
        [
            ['check_in_at' => '2026-08-12 04:37:00', 'check_out_at' => '2026-08-12 06:47:00'],
            ['check_in_at' => '2026-08-12 10:00:00', 'check_out_at' => '2026-08-12 12:00:00'],
            ['check_in_at' => '2026-08-12 13:00:00', 'check_out_at' => '2026-08-12 15:00:00'],
        ],
        false,
        new DateTimeImmutable('2026-08-12 18:00:00', new DateTimeZone('Asia/Yangon')),
    );

    assert_same('present', $result['attendance_state']);
    assert_same(60, $result['late_minutes']);
    assert_same(1, $result['is_late']);
    assert_same(120, $result['early_departure_minutes']);
    assert_same(1, $result['is_early_departure']);
    assert_same(130, $result['early_overtime_minutes']);
    assert_same(0, $result['after_hours_overtime_minutes']);
    assert_same(130, $result['calculated_overtime_minutes']);
    assert_same('pending', $result['overtime_approval_status']);
});

test('intermediate checkout is provisional until scheduled end and later return clears Early Departure', function (): void {
    $calculator = new AttendanceCalculationService(new AttendanceIntervalAggregationService());
    $timezone = new DateTimeZone('Asia/Yangon');

    $provisional = $calculator->calculateFromSessions(
        session_resolution(),
        session_employee(),
        [['check_in_at' => '2026-08-12 09:00:00', 'check_out_at' => '2026-08-12 12:00:00']],
        false,
        new DateTimeImmutable('2026-08-12 12:01:00', $timezone),
    );
    assert_same(300, $provisional['early_departure_candidate_minutes']);
    assert_same(1, $provisional['early_departure_provisional']);
    assert_same(0, $provisional['early_departure_minutes']);
    assert_same(0, $provisional['is_early_departure']);

    $returned = $calculator->calculateFromSessions(
        session_resolution(),
        session_employee(),
        [
            ['check_in_at' => '2026-08-12 09:00:00', 'check_out_at' => '2026-08-12 12:00:00'],
            ['check_in_at' => '2026-08-12 13:00:00', 'check_out_at' => '2026-08-12 17:00:00'],
        ],
        false,
        new DateTimeImmutable('2026-08-12 17:01:00', $timezone),
    );
    assert_same(0, $returned['early_departure_candidate_minutes']);
    assert_same(0, $returned['early_departure_minutes']);
    assert_same(0, $returned['is_early_departure']);
});

test('active pre-shift session earns scheduled overlap once it reaches the shift', function (): void {
    $calculator = new AttendanceCalculationService(new AttendanceIntervalAggregationService());
    $result = $calculator->calculateFromSessions(
        session_resolution(),
        session_employee(),
        [['check_in_at' => '2026-08-12 04:37:00', 'check_out_at' => null]],
        false,
        new DateTimeImmutable('2026-08-12 10:00:00', new DateTimeZone('Asia/Yangon')),
    );

    assert_same('incomplete', $result['attendance_state']);
    assert_same(60, $result['scheduled_overlap_minutes']);
    assert_same(263, $result['pre_shift_work_minutes']);
    assert_same(1, (int) $result['has_scheduled_overlap']);
    assert_same(0, $result['late_minutes']);
});

test('overnight multi-session aggregation respects full datetime boundaries', function (): void {
    $calculator = new AttendanceCalculationService(new AttendanceIntervalAggregationService());
    $resolution = session_resolution('2026-08-12 22:00:00', '2026-08-13 06:00:00');
    $result = $calculator->calculateFromSessions(
        $resolution,
        session_employee(),
        [
            ['check_in_at' => '2026-08-12 21:00:00', 'check_out_at' => '2026-08-13 01:00:00'],
            ['check_in_at' => '2026-08-13 02:00:00', 'check_out_at' => '2026-08-13 06:00:00'],
        ],
        false,
        new DateTimeImmutable('2026-08-13 07:00:00', new DateTimeZone('Asia/Yangon')),
    );

    assert_same(420, $result['scheduled_overlap_minutes']);
    assert_same(60, $result['pre_shift_work_minutes']);
    assert_same(480, $result['actual_work_minutes']);
    assert_same(1, $result['is_overnight_attendance']);
    assert_same('present', $result['attendance_state']);
});

test('open scheduled session is recognized immediately at exact check-in second without inventing worked time', function (): void {
    $calculator = new AttendanceCalculationService(new AttendanceIntervalAggregationService());
    $result = $calculator->calculateFromSessions(
        session_resolution(),
        session_employee(),
        [['check_in_at' => '2026-08-12 15:30:00', 'check_out_at' => null]],
        false,
        new DateTimeImmutable('2026-08-12 15:30:00', new DateTimeZone('Asia/Yangon')),
    );

    assert_same('incomplete', $result['attendance_state']);
    assert_same(0, $result['actual_work_minutes']);
    assert_same(0, $result['scheduled_overlap_minutes']);
    assert_same(1, (int) $result['has_scheduled_overlap']);
    assert_same('2026-08-12 15:30:00', $result['scheduled_entry_at']);
    assert_same(390, $result['late_minutes']);
    assert_same(1, $result['is_late']);
});

test('open scheduled session is recognized during a sub-second read without inventing worked time', function (): void {
    $calculator = new AttendanceCalculationService(new AttendanceIntervalAggregationService());
    $result = $calculator->calculateFromSessions(
        session_resolution(),
        session_employee(),
        [['check_in_at' => '2026-08-12 15:30:00', 'check_out_at' => null]],
        false,
        new DateTimeImmutable('2026-08-12 15:30:00.500000', new DateTimeZone('Asia/Yangon')),
    );

    assert_same('incomplete', $result['attendance_state']);
    assert_same(0, $result['actual_work_seconds']);
    assert_same(0, $result['actual_work_minutes']);
    assert_same(0, $result['scheduled_overlap_seconds']);
    assert_same(0, $result['scheduled_overlap_minutes']);
    assert_same(0, $result['early_overtime_minutes']);
    assert_same(0, $result['after_hours_overtime_minutes']);
    assert_same(0, $result['calculated_overtime_minutes']);
    assert_same(1, (int) $result['has_scheduled_overlap']);
    assert_same('2026-08-12 15:30:00', $result['scheduled_entry_at']);
    assert_same(390, $result['late_minutes']);
    assert_same(1, $result['is_late']);
});

test('legacy open session participation is recognized immediately inside the scheduled window', function (): void {
    $calculator = new AttendanceCalculationService(new AttendanceIntervalAggregationService());
    $result = $calculator->scheduleParticipation(
        session_resolution(),
        '2026-08-12 15:30:00',
        null,
        new DateTimeImmutable('2026-08-12 15:30:00', new DateTimeZone('Asia/Yangon')),
    );

    assert_same(0, $result['scheduled_overlap_seconds']);
    assert_same(0, $result['scheduled_overlap_minutes']);
    assert_same(0, $result['out_of_schedule_work_minutes']);
    assert_same(1, (int) $result['has_scheduled_overlap']);
});

test('legacy open session participation recognizes a sub-second read inside the scheduled window', function (): void {
    $calculator = new AttendanceCalculationService(new AttendanceIntervalAggregationService());
    $result = $calculator->scheduleParticipation(
        session_resolution(),
        '2026-08-12 15:30:00',
        null,
        new DateTimeImmutable('2026-08-12 15:30:00.500000', new DateTimeZone('Asia/Yangon')),
    );

    assert_same(0, $result['scheduled_overlap_seconds']);
    assert_same(0, $result['scheduled_overlap_minutes']);
    assert_same(0, $result['out_of_schedule_work_minutes']);
    assert_same(1, (int) $result['has_scheduled_overlap']);
});
