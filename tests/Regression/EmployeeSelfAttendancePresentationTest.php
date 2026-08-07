<?php

declare(strict_types=1);

test('employee dashboard self-attendance actions stay server-authoritative and show missing checkout context', function (): void {
    $view = file_get_contents(project_path('views/dashboard/employee.php'));
    $english = require project_path('lang/en.php');
    $myanmar = require project_path('lang/my.php');

    assert_true(is_string($view), 'Employee dashboard view should be readable.');
    assert_contains('action="/attendance/clock-in"', $view);
    assert_contains('action="/attendance/clock-out"', $view);
    assert_contains('name="csrf_token"', $view);
    assert_contains("attendance.missing_check_out", $view);
    assert_contains("is_missing_check_out", $view);
    assert_contains('$clockInBlockReason', $view);
    assert_contains('dashboard.employee.no_clock_actions_holiday', $view);
    assert_contains('dashboard.employee.no_clock_actions_non_working_day', $view);
    assert_contains('dashboard.employee.no_clock_actions_profile', $view);

    foreach ([
        'employee_id',
        'user_id',
        'attendance_date',
        'business_date',
        'clock_in_time',
        'clock_out_time',
        'check_in_at',
        'check_out_at',
        'status',
        'attendance_state',
        'day_type',
        'late_minutes',
        'early_departure_minutes',
        'worked_minutes',
        'overtime_minutes',
        'policy_id',
        'policy_version',
        'calculation_version',
    ] as $field) {
        assert_not_contains('name="' . $field . '"', $view);
    }

    $englishValues = [
        $english['dashboard']['common']['clock_in'] ?? '',
        $english['dashboard']['common']['clock_out'] ?? '',
        $english['dashboard']['common']['clock_in_time'] ?? '',
        $english['dashboard']['common']['clock_out_time'] ?? '',
        $english['dashboard']['common']['clock_out_pending'] ?? '',
        $english['attendance']['missing_check_out'] ?? '',
        $english['flash']['attendance_clock_in_success'] ?? '',
        $english['flash']['attendance_clock_in_failed'] ?? '',
        $english['flash']['attendance_clock_out_success'] ?? '',
        $english['flash']['attendance_clock_out_failed'] ?? '',
        $english['flash']['attendance_clock_out_required'] ?? '',
        $english['dashboard']['employee']['clock_blocked_holiday'] ?? '',
        $english['dashboard']['employee']['clock_blocked_non_working_day'] ?? '',
        $english['attendance']['no_clock_actions_profile'] ?? '',
        $english['validation']['attendance_holiday_check_in_blocked'] ?? '',
        $english['validation']['attendance_non_working_day_check_in_blocked'] ?? '',
        $english['validation']['attendance_employee_profile_missing'] ?? '',
    ];

    foreach ($englishValues as $value) {
        assert_not_contains('Check-in', $value);
        assert_not_contains('check-in', $value);
        assert_not_contains('Check-out', $value);
        assert_not_contains('check-out', $value);
    }

    assert_not_contains('present', $myanmar['attendance']['missing_check_out'] ?? '');
    assert_not_contains('incomplete', $myanmar['attendance']['missing_check_out'] ?? '');
    assert_not_contains('check_in', $myanmar['attendance']['missing_check_out'] ?? '');
    assert_not_contains('check_out', $myanmar['attendance']['missing_check_out'] ?? '');
    assert_not_contains('Your employee profile', $myanmar['attendance']['no_clock_actions_profile'] ?? '');
    assert_not_contains('company holidays', $myanmar['validation']['attendance_holiday_check_in_blocked'] ?? '');
});

test('employee attendance list uses centralized self check-in block reason for action state', function (): void {
    $controller = file_get_contents(project_path('src/Controllers/AttendanceController.php'));
    $dashboard = file_get_contents(project_path('src/Controllers/DashboardController.php'));
    $attendanceView = file_get_contents(project_path('views/attendance/index.php'));

    assert_true(is_string($controller));
    assert_true(is_string($dashboard));
    assert_true(is_string($attendanceView));
    assert_contains('$this->attendanceOperations->selfClockInBlockReason', $controller);
    assert_contains('$this->attendanceOperations->selfClockInBlockReason', $dashboard);
    assert_contains('$clockInBlockReason === null', $controller);
    assert_contains('$clockInBlockReason === null', $dashboard);
    assert_contains('attendance.no_clock_actions_holiday', $attendanceView);
    assert_contains('attendance.no_clock_actions_non_working_day', $attendanceView);
    assert_contains('attendance.no_clock_actions_profile', $attendanceView);
});
