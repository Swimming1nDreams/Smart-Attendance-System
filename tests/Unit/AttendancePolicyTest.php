<?php

declare(strict_types=1);

use App\Core\Localization;
use App\Services\AttendancePolicyException;
use App\Services\AttendancePolicyService;

test('attendance grace waiver is inclusive and late minutes start at shift start', function (): void {
    $policy = new AttendancePolicyService();

    assert_same(0, $policy->calculateLateMinutes('09:29:00'));
    assert_same(0, $policy->calculateLateMinutes('09:30:00'));
    assert_same(31, $policy->calculateLateMinutes('09:31:00'));
});

test('derived absence begins strictly after resolved start plus sixty minutes', function (): void {
    $policy = new AttendancePolicyService();
    $timezone = 'Asia/Yangon';
    $date = '2026-08-03';

    assert_same('pending', $policy->deriveMissingAttendanceStatus(
        $date,
        true,
        false,
        false,
        $date . ' 09:00:00',
        new DateTimeImmutable($date . ' 10:00:00', new DateTimeZone($timezone)),
        $timezone,
    ));
    assert_same('absent', $policy->deriveMissingAttendanceStatus(
        $date,
        true,
        false,
        false,
        $date . ' 09:00:00',
        new DateTimeImmutable($date . ' 10:00:01', new DateTimeZone($timezone)),
        $timezone,
    ));
    assert_same('pending', $policy->deriveMissingAttendanceStatus(
        $date,
        true,
        false,
        false,
        $date . ' 10:00:00',
        new DateTimeImmutable($date . ' 11:00:00', new DateTimeZone($timezone)),
        $timezone,
    ));
    assert_same('absent', $policy->deriveMissingAttendanceStatus(
        $date,
        true,
        false,
        false,
        $date . ' 10:00:00',
        new DateTimeImmutable($date . ' 11:00:01', new DateTimeZone($timezone)),
        $timezone,
    ));

    $afterThreshold = new DateTimeImmutable($date . ' 12:00:00', new DateTimeZone($timezone));
    assert_same('leave', $policy->deriveMissingAttendanceStatus($date, true, false, true, $date . ' 09:00:00', $afterThreshold, $timezone));
    assert_same('holiday', $policy->deriveMissingAttendanceStatus($date, true, true, false, $date . ' 09:00:00', $afterThreshold, $timezone));
    assert_same('weekend', $policy->deriveMissingAttendanceStatus($date, false, false, false, null, $afterThreshold, $timezone));
    assert_same('upcoming', $policy->deriveMissingAttendanceStatus('2026-08-04', true, false, false, '2026-08-04 09:00:00', $afterThreshold, $timezone));
    assert_same('absent', $policy->deriveMissingAttendanceStatus('2026-08-02', true, false, false, '2026-08-02 23:30:00', $afterThreshold, $timezone));
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
    reset_test_session();
    $_SESSION['locale'] = 'en';
    Localization::setCurrent(new Localization(project_path('lang')));

    $policy = new AttendancePolicyService();
    $minutes = $policy->actualWorkMinutes('09:00:00', '16:30:00');

    assert_same(450, $minutes);
    assert_same(7.5, $policy->workingHours($minutes));
    assert_same(30, $policy->calculateOvertimeMinutes('present', $minutes, 7, '2026-07-22', false, false, true, '09:00:00', '16:30:00'));
    assert_same(0, $policy->calculateOvertimeMinutes('present', $minutes, 8, '2026-07-22', false, false, true, '09:00:00', '16:30:00'));
    assert_same('7 hr 30 min', $policy->formatDuration($minutes));
    assert_same('45 min', $policy->formatDuration(45));
    assert_same('0 min', $policy->formatDuration(0));

    $myTranslations = require project_path('lang/my.php');
    assert_same('16 hr 18 min', $policy->formatDuration(978));
    assert_not_contains('16.30', $policy->formatDuration(978));
    assert_same('16 နာရီ 18 မိနစ်', $policy->formatDuration(978, $myTranslations['common']['duration_formats']));
});

test('application attendance durations use the active English or Myanmar units', function (): void {
    reset_test_session();
    $_SESSION['locale'] = 'en';
    Localization::setCurrent(new Localization(project_path('lang')));
    $policy = new AttendancePolicyService();

    assert_same('8 hr 10 min', $policy->formatDuration(490));
    assert_same('0 min', $policy->formatDuration(0));

    $_SESSION['locale'] = 'my';
    Localization::setCurrent(new Localization(project_path('lang')));
    assert_same('8 နာရီ 10 မိနစ်', $policy->formatDuration(490));
    assert_same('0 မိနစ်', $policy->formatDuration(0));

    $_SESSION['locale'] = 'en';
    Localization::setCurrent(new Localization(project_path('lang')));
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
    assert_same(0, $policy->actualWorkMinutes('04:37:18', '04:37:19'));

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

    $subMinute = $policy->enrichRecord([
        'attendance_state' => 'present',
        'day_type' => 'working_day',
        'status' => 'present',
        'clock_in_time' => '04:37:18',
        'clock_out_time' => '04:37:19',
        'check_in_at' => '2026-08-12 04:37:18',
        'check_out_at' => '2026-08-12 04:37:19',
        'regular_minutes' => 0,
    ]);
    assert_same(0, $subMinute['actual_work_minutes']);
    assert_same('0 min', $subMinute['working_duration']);

    $thrown = false;
    try {
        $policy->actualWorkMinutes('17:00:00', '09:00:00');
    } catch (AttendancePolicyException $exception) {
        $thrown = $exception->getMessage() === 'clock_out_before_clock_in';
    }
    assert_true($thrown);

    $equalThrown = false;
    try {
        $policy->actualWorkMinutes('04:37:18', '04:37:18');
    } catch (AttendancePolicyException $exception) {
        $equalThrown = $exception->getMessage() === 'clock_out_before_clock_in';
    }
    assert_true($equalThrown);
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

test('overtime read model keeps calculated pending and approved credit distinct', function (): void {
    $policy = new AttendancePolicyService();
    $pending = $policy->enrichRecord([
        'attendance_state' => 'out_of_schedule',
        'day_type' => 'working_day',
        'status' => 'present',
        'clock_in_time' => '04:37:00',
        'clock_out_time' => '06:47:00',
        'check_in_at' => '2026-08-12 04:37:00',
        'check_out_at' => '2026-08-12 06:47:00',
        'regular_minutes' => 0,
        'calculated_overtime_minutes' => 130,
        'approved_overtime_minutes' => 0,
        'overtime_approval_status' => 'pending',
    ]);
    assert_same(0, $pending['overtime_minutes']);
    assert_same('2 hr 10 min', $pending['calculated_overtime_duration']);
    assert_same('0 min', $pending['approved_overtime_duration']);
    assert_same('out_of_schedule', $pending['attendance_display_status']);

    $pending['approved_overtime_minutes'] = 90;
    $pending['overtime_approval_status'] = 'approved';
    $approved = $policy->enrichRecord($pending);
    assert_same(90, $approved['overtime_minutes']);
    assert_same('1 hr 30 min', $approved['approved_overtime_duration']);
});
