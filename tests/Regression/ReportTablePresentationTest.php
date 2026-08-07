<?php

declare(strict_types=1);

test('report detail pages use the compact data-table presentation', function (): void {
    $attendance = file_get_contents(project_path('views/reports/attendance_summary.php'));
    $employee = file_get_contents(project_path('views/reports/employee_attendance_detail.php'));
    $leave = file_get_contents(project_path('views/reports/leave_requests.php'));
    $exceptions = file_get_contents(project_path('views/reports/late_absent_exceptions.php'));
    $css = file_get_contents(project_path('assets/css/tailwind.css'));

    assert_true(is_string($attendance), 'Attendance summary report should be readable.');
    assert_true(is_string($employee), 'Employee attendance report should be readable.');
    assert_true(is_string($leave), 'Leave report should be readable.');
    assert_true(is_string($exceptions), 'Exception report should be readable.');
    assert_true(is_string($css), 'Tailwind source should be readable.');

    assert_contains('sapas-report-attendance-table', $attendance);
    assert_contains('sapas-report-employee-attendance-table', $employee);
    assert_contains('sapas-report-leave-context-table', $employee);
    assert_contains('sapas-report-leave-table', $leave);
    assert_contains('sapas-report-exception-table', $exceptions);

    foreach ([$attendance, $employee, $leave, $exceptions] as $view) {
        assert_contains('sapas-data-table-scroll', $view);
        assert_contains('sapas-data-table', $view);
    }

    assert_contains('sapas-person-cell', $attendance);
    assert_contains('sapas-person-cell', $leave);
    assert_contains('sapas-person-cell', $exceptions);
    assert_contains('sapas-data-table-summary-grid', $attendance);
    assert_contains('sapas-data-table-summary-grid', $employee);
    assert_contains('sapas-data-table-summary-grid', $leave);
    assert_contains('sapas-data-table-summary-grid', $exceptions);
    assert_contains('.sapas-report-attendance-table', $css);
    assert_contains('.sapas-report-leave-contexts', $css);
});
