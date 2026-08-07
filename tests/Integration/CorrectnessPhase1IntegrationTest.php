<?php

declare(strict_types=1);

use App\Services\AnalyticsService;
use App\Services\AttendancePolicyService;
use App\Models\User;
use App\Models\Attendance;
use App\Models\Department;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Services\WorkScheduleResolverService;
use App\Services\AttendanceOperationService;

use App\Models\Employee;

test('phase 1: duration formatting uses hr min format', function (): void {
    $policy = new AttendancePolicyService();
    
    assert_same('0 min', $policy->formatDuration(0));
    assert_same('5 min', $policy->formatDuration(5));
    assert_same('59 min', $policy->formatDuration(59));
    assert_same('1 hr', $policy->formatDuration(60));
    assert_same('1 hr 1 min', $policy->formatDuration(61));
    assert_same('1 hr 30 min', $policy->formatDuration(90));
    assert_same('4 hr 7 min', $policy->formatDuration(247));
});

test('phase 1: missing attendance on past working day is derived absent', function (): void {
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $pdo = $database->pdo();
    
    $analytics = new AnalyticsService(
        new Employee($pdo),
        new Attendance($pdo, new AttendancePolicyService()),
        new Holiday($pdo),
        new LeaveRequest($pdo),
        new AttendancePolicyService(),
        new WorkScheduleResolverService($pdo)
    );
    
    $from = date('Y-m-d', strtotime('-5 days'));
    $to = date('Y-m-d');
    
    $result = $analytics->generate([
        'date_from' => $from,
        'date_to' => $to,
        'employee_id' => $ids['employee_id']
    ]);
    
    $employeeStats = $result['employees'][0];
    
    assert_true($employeeStats['absent_days'] > 0);
    assert_same(0.0, $employeeStats['attendance_percentage']);
});

test('phase 1: attendance percentage calculates correctly', function (): void {
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $pdo = $database->pdo();
    $policy = new AttendancePolicyService();
    $operations = new AttendanceOperationService($pdo, $policy);
    
    $analytics = new AnalyticsService(
        new Employee($pdo),
        new Attendance($pdo, $policy),
        new Holiday($pdo),
        new LeaveRequest($pdo),
        $policy,
        new WorkScheduleResolverService($pdo)
    );
    
    $today = date('Y-m-d');
    $operations->createRecord($ids['employee_id'], $today, '09:00:00', '17:00:00', 'present', null);
    
    $from = date('Y-m-d', strtotime('-4 days'));
    $result = $analytics->generate([
        'date_from' => $from,
        'date_to' => $today,
        'employee_id' => $ids['employee_id']
    ]);
    
    $employeeStats = $result['employees'][0];
    
    assert_same(1, $employeeStats['present_days']);
    assert_true($employeeStats['absent_days'] > 0);
    
    $expected = $employeeStats['present_days'] + $employeeStats['absent_days'];
    $expectedPercentage = round(($employeeStats['present_days'] / $expected) * 100, 1);
    
    assert_same($expectedPercentage, $employeeStats['attendance_percentage']);
});

test('phase 1: display status logic for incomplete vs late vs checked in', function (): void {
    $policy = new AttendancePolicyService();
    $now = new DateTimeImmutable();
    $today = $now->format('Y-m-d');
    $yesterday = $now->modify('-1 day')->format('Y-m-d');
    
    // E: Late check-in without checkout today displays Late
    $lateTodayRecord = $policy->enrichRecord([
        'attendance_date' => $today,
        'clock_in_time' => '10:00:00',
        'clock_out_time' => null,
        'attendance_state' => 'incomplete',
        'is_late' => 1,
        'late_minutes' => 60,
    ]);
    assert_same('late', $lateTodayRecord['attendance_display_status']);
    
    // F: On-time check-in without checkout today displays checked_in
    // If check-in was 100 minutes ago (not overdue)
    $recentCheckIn = $now->modify('-100 minutes')->format('H:i:s');
    $onTimeRecentRecord = $policy->enrichRecord([
        'attendance_date' => $today,
        'clock_in_time' => $recentCheckIn,
        'clock_out_time' => null,
        'attendance_state' => 'incomplete',
        'is_late' => 0,
        'late_minutes' => 0,
    ]);
    assert_same('checked_in', $onTimeRecentRecord['attendance_display_status']);
    
    // F2: On-time check-in without checkout today, but overdue (>600 mins ago)
    $overdueCheckIn = $now->modify('-605 minutes')->format('H:i:s');
    $onTimeOverdueRecord = $policy->enrichRecord([
        'attendance_date' => $today,
        'clock_in_time' => $overdueCheckIn,
        'clock_out_time' => null,
        'attendance_state' => 'incomplete',
        'is_late' => 0,
        'late_minutes' => 0,
    ]);
    // If it crosses midnight, attendance_date might not be $today anymore, but let's assume it doesn't matter
    // for the logic if we just pass $today. Wait, if $now is 01:00 AM, -605 mins is yesterday 14:55.
    // So $today . ' ' . 14:55 is created. Then +600 mins is $today 00:55. Now (01:00) > 00:55. True!
    assert_same('incomplete', $onTimeOverdueRecord['attendance_display_status']);
    
    // G: Previous date check-in without checkout displays incomplete
    $incompleteYesterdayRecord = $policy->enrichRecord([
        'attendance_date' => $yesterday,
        'clock_in_time' => '09:00:00',
        'clock_out_time' => null,
        'attendance_state' => 'incomplete',
        'is_late' => 0,
        'late_minutes' => 0,
    ]);
    assert_same('incomplete', $incompleteYesterdayRecord['attendance_display_status']);
});

test('phase 1: derive missing attendance status helper', function (): void {
    $policy = new AttendancePolicyService();
    
    $today = date('Y-m-d');
    $past = date('Y-m-d', strtotime('-1 day'));
    $future = date('Y-m-d', strtotime('+1 day'));
    
    assert_same('upcoming', $policy->deriveMissingAttendanceStatus($future, true, false, false));
    assert_same('leave', $policy->deriveMissingAttendanceStatus($past, true, false, true));
    assert_same('holiday', $policy->deriveMissingAttendanceStatus($past, true, true, false));
    assert_same('weekend', $policy->deriveMissingAttendanceStatus($past, false, false, false));
    assert_same('absent', $policy->deriveMissingAttendanceStatus($past, true, false, false));
});
