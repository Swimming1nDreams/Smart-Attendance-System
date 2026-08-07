<?php

declare(strict_types=1);

use App\Services\AttendancePolicyException;
use App\Services\AttendancePolicyService;

test('attendance grace waiver is inclusive and late minutes start at shift start', function (): void {
    $policy = new AttendancePolicyService();

    assert_same(0, $policy->calculateLateMinutes('09:29:00'));
    assert_same(0, $policy->calculateLateMinutes('09:30:00'));
    assert_same(31, $policy->calculateLateMinutes('09:31:00'));
});

test('employee expected hours normalize once to integer minutes with safe fallback', function (): void {
    $policy = new AttendancePolicyService();

    assert_same(420, $policy->expectedWorkMinutes(7));
    assert_same(480, $policy->expectedWorkMinutes(8));
    assert_same(450, $policy->expectedWorkMinutes('7.5'));
    assert_same(480, $policy->expectedWorkMinutes(null));
    assert_same(480, $policy->expectedWorkMinutes('invalid'));
    assert_same(480, $policy->expectedWorkMinutes(0));
    assert_same(480, $policy->expectedWorkMinutes(25));
});

test('duration and schedule-boundary overtime use exact minutes and employee expectation', function (): void {
    $policy = new AttendancePolicyService();
    $minutes = $policy->actualWorkMinutes('09:00:00', '16:30:00');

    assert_same(450, $minutes);
    assert_same(7.5, $policy->workingHours($minutes));
    assert_same(30, $policy->calculateOvertimeMinutes('present', $minutes, 7, '2026-07-22', false, false, true, '09:00:00', '16:30:00'));
    assert_same(0, $policy->calculateOvertimeMinutes('present', $minutes, 8, '2026-07-22', false, false, true, '09:00:00', '16:30:00'));
    assert_same('7h 30m', $policy->formatDuration($minutes));
    assert_same('45m', $policy->formatDuration(45));
    assert_same('<1m', $policy->formatDuration(0));

    $myTranslations = require project_path('lang/my.php');
    assert_same('16h 18m', $policy->formatDuration(978));
    assert_not_contains('16.30', $policy->formatDuration(978));
    assert_same('16 နာရီ 18 မိနစ်', $policy->formatDuration(978, $myTranslations['common']['duration_formats']));
});

test('status derivation preserves special days and prevents contradictory weekday rows', function (): void {
    $policy = new AttendancePolicyService();

    assert_same('weekend', $policy->deriveStatus('2026-08-09', 'present', '10:00:00', '12:00:00'));
    assert_same('holiday', $policy->deriveStatus('2026-08-10', 'present', '10:00:00', '12:00:00', true));
    assert_same('present', $policy->deriveStatus('2026-08-10', 'absent', '09:00:00', '17:00:00'));
    assert_same('absent', $policy->deriveStatus('2026-08-10', 'present', null, null));
    assert_same('present', $policy->deriveStatus('2026-08-10', 'present', '09:00:00', null));
});

test('normal-day overtime follows scheduled boundaries instead of total duration minus expectation', function (): void {
    $policy = new AttendancePolicyService();

    $earlyAndEarlyLeave = $policy->actualWorkMinutes('07:00:00', '15:30:00');
    assert_same(510, $earlyAndEarlyLeave);
    assert_same(120, $policy->calculateOvertimeMinutes('present', $earlyAndEarlyLeave, 8, '2026-07-22', false, false, true, '07:00:00', '15:30:00'));

    $bothSides = $policy->actualWorkMinutes('08:00:00', '18:00:00');
    assert_same(120, $policy->calculateOvertimeMinutes('present', $bothSides, 8, '2026-07-22', false, false, true, '08:00:00', '18:00:00'));

    $lateOnly = $policy->actualWorkMinutes('10:00:00', '18:00:00');
    assert_same(60, $policy->calculateOvertimeMinutes('present', $lateOnly, 8, '2026-07-22', false, false, true, '10:00:00', '18:00:00'));

    $earlyOnly = $policy->actualWorkMinutes('08:00:00', '16:00:00');
    assert_same(60, $policy->calculateOvertimeMinutes('present', $earlyOnly, 8, '2026-07-22', false, false, true, '08:00:00', '16:00:00'));
});

test('incomplete and reversed attendance never invents completed duration', function (): void {
    $policy = new AttendancePolicyService();

    assert_null($policy->actualWorkMinutes('09:00:00', null));
    assert_null($policy->calculateOvertimeMinutes('present', null, 8, '2026-07-22'));
    assert_same('—', $policy->formatDuration(null));

    $enriched = $policy->enrichRecord([
        'attendance_state' => 'incomplete',
        'day_type' => 'working_day',
        'status' => 'present',
        'clock_in_time' => '22:00:00',
        'clock_out_time' => null,
        'regular_minutes' => null,
    ]);
    assert_same('incomplete', $enriched['attendance_display_status']);
    assert_same($policy->formatDuration(null), $enriched['working_duration']);

    $thrown = false;
    try {
        $policy->actualWorkMinutes('17:00:00', '09:00:00');
    } catch (AttendancePolicyException $exception) {
        $thrown = $exception->getMessage() === 'clock_out_before_clock_in';
    }
    assert_true($thrown);
});

test('special-day work counts all completed minutes and policy exclusions remain enforced', function (): void {
    $policy = new AttendancePolicyService();

    assert_same(0, $policy->calculateOvertimeMinutes('present', 300, 8, '2026-07-22', false, false, true, '09:00:00', '14:00:00'));
    assert_same(600, $policy->calculateOvertimeMinutes('weekend', 600, 8, '2026-07-25', false, false, true, '08:00:00', '18:00:00'));
    assert_same(600, $policy->calculateOvertimeMinutes('holiday', 600, 8, '2026-07-22', true, false, true, '08:00:00', '18:00:00'));
    assert_same(0, $policy->calculateOvertimeMinutes('present', 600, 8, '2026-07-22', false, true, true, '08:00:00', '18:00:00'));
    assert_same(0, $policy->calculateOvertimeMinutes('present', 600, 8, '2026-07-22', false, false, false, '08:00:00', '18:00:00'));
    assert_same(0, $policy->calculateOvertimeMinutes('absent', 600, 8, '2026-07-22', false, false, true, '08:00:00', '18:00:00'));
});
