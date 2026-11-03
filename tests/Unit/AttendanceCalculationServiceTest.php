<?php

declare(strict_types=1);

use App\Services\AttendanceCalculationService;
use App\Services\AttendancePolicyException;

test('checkout ordering uses full datetimes before rounding elapsed minutes', function (): void {
    $originalTimezone = date_default_timezone_get();
    $calculator = new AttendanceCalculationService();
    $resolution = [
        'day_type' => 'working_day',
        'scheduled_start_at' => '2026-08-12 09:18:00',
        'scheduled_end_at' => '2026-08-12 17:18:00',
        'break_start_at' => null,
        'break_end_at' => null,
        'break_minutes' => 0,
        'grace_minutes' => 15,
        'expected_regular_minutes' => 480,
        'attendance_policy' => [
            'maximum_attendance_minutes' => 960,
            'early_departure_enabled' => 1,
            'early_departure_grace_minutes' => 0,
        ],
        'overtime_policy' => [],
    ];
    $employee = ['overtime_eligible' => 1];
    $reason = static function (callable $callback): ?string {
        try {
            $callback();
        } catch (AttendancePolicyException $exception) {
            return $exception->getMessage();
        }

        return null;
    };

    try {
        foreach (['Asia/Yangon', 'UTC'] as $timezone) {
            date_default_timezone_set($timezone);
            $later = $calculator->calculate(
                $resolution,
                $employee,
                '2026-08-12 04:37:18',
                '2026-08-12 04:38:18',
            );
            assert_same('out_of_schedule', $later['attendance_state']);
            assert_same(1, $later['actual_work_minutes']);
            assert_same(0, $later['is_early_departure']);

            $subMinute = $calculator->calculate(
                $resolution,
                $employee,
                '2026-08-12 04:37:18',
                '2026-08-12 04:37:19',
            );
            assert_same('out_of_schedule', $subMinute['attendance_state']);
            assert_same(0, $subMinute['actual_work_minutes']);
            assert_same(0, $subMinute['is_early_departure']);
        }

        assert_same('clock_out_before_clock_in', $reason(static fn (): array => $calculator->calculate(
            $resolution,
            $employee,
            '2026-08-12 04:37:18',
            '2026-08-12 04:37:18',
        )));
        assert_same('clock_out_before_clock_in', $reason(static fn (): array => $calculator->calculate(
            $resolution,
            $employee,
            '2026-08-12 04:37:18',
            '2026-08-12 04:37:17',
        )));

        $overnight = $resolution;
        $overnight['scheduled_start_at'] = '2026-08-12 22:00:00';
        $overnight['scheduled_end_at'] = '2026-08-13 06:00:00';
        $overnightResult = $calculator->calculate(
            $overnight,
            $employee,
            '2026-08-12 22:00:00',
            '2026-08-13 05:00:00',
        );
        assert_same('present', $overnightResult['attendance_state']);
        assert_same(420, $overnightResult['actual_work_minutes']);
        assert_same(1, $overnightResult['is_overnight_attendance']);
    } finally {
        date_default_timezone_set($originalTimezone);
    }
});

test('scheduled overlap separates attendance credit early departure and overtime components', function (): void {
    $calculator = new AttendanceCalculationService();
    $employee = ['overtime_eligible' => 1];
    $resolution = [
        'day_type' => 'working_day',
        'scheduled_start_at' => '2026-08-12 09:00:00',
        'scheduled_end_at' => '2026-08-12 17:00:00',
        'break_start_at' => null,
        'break_end_at' => null,
        'break_minutes' => 0,
        'grace_minutes' => 30,
        'expected_regular_minutes' => 480,
        'attendance_policy' => ['maximum_attendance_minutes' => 960, 'early_departure_enabled' => 1, 'early_departure_grace_minutes' => 0],
        'overtime_policy' => [
            'early_work_eligible' => 1,
            'after_hours_work_eligible' => 1,
            'approval_required' => 1,
            'minimum_overtime_minutes' => 0,
            'rounding_mode' => 'exact',
        ],
    ];

    $before = $calculator->calculate($resolution, $employee, '2026-08-12 04:37:00', '2026-08-12 06:47:00');
    assert_same('out_of_schedule', $before['attendance_state']);
    assert_same(0, $before['scheduled_overlap_minutes']);
    assert_same(130, $before['out_of_schedule_work_minutes']);
    assert_same(0, $before['is_early_departure']);
    assert_same(130, $before['calculated_overtime_minutes']);
    assert_same(0, $before['approved_overtime_minutes']);
    assert_same('pending', $before['overtime_approval_status']);

    $partial = $calculator->calculate($resolution, $employee, '2026-08-12 08:00:00', '2026-08-12 11:00:00');
    assert_same('present', $partial['attendance_state']);
    assert_same(120, $partial['scheduled_overlap_minutes']);
    assert_same(60, $partial['out_of_schedule_work_minutes']);
    assert_same(60, $partial['early_overtime_minutes']);
    assert_same(1, $partial['is_early_departure']);

    $longPreShift = $calculator->calculate($resolution, $employee, '2026-08-12 04:37:00', '2026-08-12 10:00:00');
    assert_same('present', $longPreShift['attendance_state']);
    assert_same(60, $longPreShift['scheduled_overlap_minutes']);
    assert_same(263, $longPreShift['out_of_schedule_work_minutes']);
    assert_same(1, $longPreShift['is_early_departure']);

    $late = $calculator->calculate($resolution, $employee, '2026-08-12 10:00:00', '2026-08-12 17:00:00');
    assert_same('present', $late['attendance_state']);
    assert_same(60, $late['late_minutes']);
    assert_same(0, $late['is_early_departure']);

    $lateEarly = $calculator->calculate($resolution, $employee, '2026-08-12 10:00:00', '2026-08-12 15:00:00');
    assert_same('present', $lateEarly['attendance_state']);
    assert_same(60, $lateEarly['late_minutes']);
    assert_same(120, $lateEarly['early_departure_minutes']);

    $after = $calculator->calculate($resolution, $employee, '2026-08-12 18:00:00', '2026-08-12 20:00:00');
    assert_same('out_of_schedule', $after['attendance_state']);
    assert_same(0, $after['scheduled_overlap_minutes']);
    assert_same(120, $after['after_hours_overtime_minutes']);
    assert_same(0, $after['is_early_departure']);

    $overnight = $resolution;
    $overnight['scheduled_start_at'] = '2026-08-12 22:00:00';
    $overnight['scheduled_end_at'] = '2026-08-13 06:00:00';
    $overnightResult = $calculator->calculate($overnight, $employee, '2026-08-12 21:00:00', '2026-08-12 23:00:00');
    assert_same(60, $overnightResult['scheduled_overlap_minutes']);
    assert_same(60, $overnightResult['out_of_schedule_work_minutes']);
});

test('resolved schedules classify on-time and late attendance at exact boundaries', function (): void {
    $calculator = new AttendanceCalculationService();
    $employee = ['overtime_eligible' => 1];
    $cases = [
        ['09:00:00', '17:00:00', '09:15:00', 0],
        ['09:00:00', '17:00:00', '09:30:00', 0],
        ['09:00:00', '17:00:00', '09:31:00', 31],
        ['09:00:00', '17:00:00', '09:50:00', 50],
        ['10:00:00', '18:00:00', '10:40:00', 40],
    ];

    foreach ($cases as [$start, $end, $checkIn, $expectedLateMinutes]) {
        $resolution = [
            'day_type' => 'working_day',
            'scheduled_start_at' => '2026-08-03 ' . $start,
            'scheduled_end_at' => '2026-08-03 ' . $end,
            'break_start_at' => null,
            'break_end_at' => null,
            'break_minutes' => 0,
            'grace_minutes' => 30,
            'expected_regular_minutes' => 480,
            'attendance_policy' => [
                'maximum_attendance_minutes' => 960,
                'early_departure_enabled' => 1,
            ],
            'overtime_policy' => [],
        ];
        $result = $calculator->calculate(
            $resolution,
            $employee,
            '2026-08-03 ' . $checkIn,
            '2026-08-03 ' . $end,
        );

        assert_same('present', $result['attendance_state']);
        assert_same($expectedLateMinutes, $result['late_minutes']);
        assert_same($expectedLateMinutes > 0 ? 1 : 0, $result['is_late']);
    }
});

test('early departure uses resolved scheduled end and its own inclusive grace boundary', function (): void {
    $calculator = new AttendanceCalculationService();
    $employee = ['overtime_eligible' => 1];
    $resolution = [
        'day_type' => 'working_day',
        'scheduled_start_at' => '2026-08-03 09:00:00',
        'scheduled_end_at' => '2026-08-03 17:00:00',
        'break_start_at' => null,
        'break_end_at' => null,
        'break_minutes' => 0,
        'grace_minutes' => 30,
        'expected_regular_minutes' => 480,
        'attendance_policy' => [
            'maximum_attendance_minutes' => 960,
            'early_departure_enabled' => 1,
            'early_departure_grace_minutes' => 10,
        ],
        'overtime_policy' => [],
    ];

    foreach ([
        ['17:00:00', 0],
        ['16:55:00', 0],
        ['16:50:00', 0],
        ['16:49:00', 11],
        ['15:30:00', 90],
    ] as [$checkOut, $expectedEarlyMinutes]) {
        $result = $calculator->calculate($resolution, $employee, '2026-08-03 09:00:00', '2026-08-03 ' . $checkOut);
        assert_same('present', $result['attendance_state']);
        assert_same($expectedEarlyMinutes, $result['early_departure_minutes']);
        assert_same($expectedEarlyMinutes > 0 ? 1 : 0, $result['is_early_departure']);
    }

    $lateAndEarly = $calculator->calculate($resolution, $employee, '2026-08-03 09:45:00', '2026-08-03 16:00:00');
    assert_same('present', $lateAndEarly['attendance_state']);
    assert_same(45, $lateAndEarly['late_minutes']);
    assert_same(60, $lateAndEarly['early_departure_minutes']);
    assert_same(375, $lateAndEarly['actual_work_minutes']);

    $resolution['attendance_policy']['early_departure_enabled'] = 0;
    $disabled = $calculator->calculate($resolution, $employee, '2026-08-03 09:00:00', '2026-08-03 15:30:00');
    assert_same(0, $disabled['is_early_departure']);
    assert_same(0, $disabled['early_departure_minutes']);
});
