<?php

declare(strict_types=1);

test('attendance and analytics tables use the compact data-table presentation', function (): void {
    $attendanceView = file_get_contents(project_path('views/attendance/index.php'));
    $analyticsView = file_get_contents(project_path('views/analytics/index.php'));
    $css = file_get_contents(project_path('assets/css/tailwind.css'));

    assert_true(is_string($attendanceView), 'Attendance view should be readable.');
    assert_true(is_string($analyticsView), 'Analytics view should be readable.');
    assert_true(is_string($css), 'Tailwind source should be readable.');

    assert_contains('sapas-attendance-table', $attendanceView);
    assert_contains('sapas-col-attendance-late', $attendanceView);
    assert_contains('sapas-col-attendance-work-duration', $attendanceView);
    assert_contains('sapas-col-attendance-time', $attendanceView);
    assert_contains('sapas-col-attendance-status', $attendanceView);
    assert_contains('sapas-attendance-status-cell', $attendanceView);
    assert_contains('sapas-analytics-table', $analyticsView);
    assert_contains('sapas-person-cell', $attendanceView);
    assert_contains('sapas-person-cell', $analyticsView);
    assert_contains('sapas-data-table-scroll', $attendanceView);
    assert_contains("mb_strlen(\$trimmed, 'UTF-8')", $attendanceView);
    assert_contains("mb_substr(\$trimmed, 0, \$limit - 1, 'UTF-8')", $attendanceView);
    assert_contains('title="<?= htmlspecialchars($rem, ENT_QUOTES, \'UTF-8\') ?>"', $attendanceView);
    assert_contains('sapas-data-table-scroll', $analyticsView);
    assert_contains('.sapas-data-table th', $css);
    assert_contains('overflow-wrap: normal;', $css);
    assert_contains('.sapas-attendance-table .sapas-col-attendance-late', $css);
    assert_contains('.sapas-attendance-table .sapas-col-attendance-work-duration', $css);
    assert_contains('.sapas-attendance-table .sapas-col-attendance-time', $css);
    assert_contains('.sapas-attendance-table .sapas-col-attendance-status', $css);
    assert_contains('.sapas-attendance-table .sapas-attendance-status-cell', $css);
    assert_contains('white-space:nowrap', file_get_contents(project_path('public/assets/css/tailwind.css')) ?: '');
});
