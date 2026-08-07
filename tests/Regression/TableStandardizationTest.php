<?php

declare(strict_types=1);

test('user-facing tables have a localized No column and left-aligned cells', function (): void {
    $tableViews = [
        'views/audit_logs/index.php',
        'views/reports/leave_requests.php',
        'views/analytics/index.php',
        'views/attendance/index.php',
        'views/reports/late_absent_exceptions.php',
        'views/employees/index.php',
        'views/reports/attendance_summary.php',
        'views/dashboard/employee.php',
        'views/reports/employee_attendance_detail.php',
        'views/leave/admin_index.php',
        'views/masterdata/holidays/index.php',
        'views/leave/index.php',
        'views/masterdata/positions/index.php',
        'views/masterdata/departments/index.php',
    ];

    foreach ($tableViews as $path) {
        $source = file_get_contents(project_path($path));
        assert_true(is_string($source), $path . ' should be readable.');
        $tableCount = substr_count($source, '<table');
        assert_true($tableCount > 0, $path . ' should contain at least one table.');
        assert_true(substr_count($source, "common.labels.no") >= $tableCount, $path . ' should label each table with a No column.');
        assert_true(substr_count($source, 'sapas-row-number') >= $tableCount, $path . ' should render row numbers.');
        assert_false((bool) preg_match('/<t[hd][^>]*(text-right|text-center)/', $source), $path . ' table cells should not set right or center alignment.');
    }

    $css = file_get_contents(project_path('assets/css/tailwind.css'));
    assert_true(is_string($css), 'Tailwind source should be readable.');
    assert_contains('.sapas-data-table .sapas-col-row-number', $css);
    assert_contains('.sapas-row-number', $css);
    assert_contains('text-align: left;', $css);
});

test('paginated table row numbers use the filtered page offset', function (): void {
    $paginatedViews = [
        'views/audit_logs/index.php',
        'views/reports/leave_requests.php',
        'views/attendance/index.php',
        'views/reports/late_absent_exceptions.php',
        'views/employees/index.php',
        'views/reports/attendance_summary.php',
        'views/reports/employee_attendance_detail.php',
        'views/leave/admin_index.php',
        'views/leave/index.php',
    ];

    $formula = '$rowNumberStart = ((max(1, (int) $page) - 1) * max(1, (int) $perPage)) + 1;';
    foreach ($paginatedViews as $path) {
        $source = file_get_contents(project_path($path));
        assert_true(is_string($source), $path . ' should be readable.');
        assert_contains($formula, $source, $path . ' should calculate numbers from page and page size.');
        assert_contains('$rowNumberStart + (int) $index', $source, $path . ' should continue numbering within the page.');
    }
});

test('No label is localized and exports remain unnumbered', function (): void {
    $english = require project_path('lang/en.php');
    $myanmar = require project_path('lang/my.php');

    assert_same('No', $english['common']['labels']['no'] ?? null);
    assert_true(isset($myanmar['common']['labels']['no']), 'Myanmar No label should exist.');

    $reportsController = file_get_contents(project_path('src/Controllers/ReportsController.php'));
    assert_true(is_string($reportsController), 'Reports controller should be readable.');
    assert_not_contains("common.labels.no", $reportsController, 'PDF and Excel exports should not gain numbered rows.');
});
