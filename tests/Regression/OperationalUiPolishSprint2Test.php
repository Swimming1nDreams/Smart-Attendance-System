<?php

declare(strict_types=1);

test('sprint two keeps operational tables and filters on shared presentation hooks', function (): void {
    $views = [
        file_get_contents(project_path('views/employees/index.php')),
        file_get_contents(project_path('views/attendance/index.php')),
        file_get_contents(project_path('views/leave/index.php')),
        file_get_contents(project_path('views/leave/admin_index.php')),
    ];

    foreach ($views as $view) {
        assert_true(is_string($view));
        assert_contains('sapas-polish-filter-grid', $view);
        assert_contains('sapas-polish-empty-state', $view);
    }

    assert_contains('sapas-polish-table-scroll', $views[0]);
    assert_contains('sapas-polish-table-scroll', $views[1]);
    assert_contains('sapas-polish-table-scroll', $views[2]);
    assert_contains('sapas-polish-table-scroll', $views[3]);
});

test('sprint two standardizes visible operational action meanings', function (): void {
    $attendance = file_get_contents(project_path('views/attendance/index.php'));
    $employee = file_get_contents(project_path('views/employees/show.php'));
    $leaveIndex = file_get_contents(project_path('views/leave/index.php'));
    $leaveReview = file_get_contents(project_path('views/leave/admin_show.php'));
    $overtimeReview = file_get_contents(project_path('views/work_schedules/overtime_show.php'));

    assert_contains('sapas-btn sapas-btn-success w-full disabled:', $attendance);
    assert_contains('sapas-btn sapas-btn-success', $employee);
    assert_contains('sapas-btn sapas-btn-danger-quiet', $leaveIndex);
    assert_contains('sapas-btn sapas-btn-success w-full', $leaveReview);
    assert_contains('sapas-btn sapas-btn-danger w-full', $leaveReview);
    assert_contains('sapas-btn sapas-btn-success', $overtimeReview);

    assert_not_contains('bg-emerald-600 text-white hover:bg-emerald-700', $attendance);
    assert_not_contains('bg-rose-600 text-white hover:bg-rose-700', $leaveReview);
});

test('report filters expose consistent reset and export action groups', function (): void {
    $reportRoutes = [
        'views/reports/attendance_summary.php' => '/admin/reports/attendance-summary',
        'views/reports/employee_attendance_detail.php' => '/admin/reports/employee-attendance-detail',
        'views/reports/leave_requests.php' => '/admin/reports/leave-requests',
        'views/reports/late_absent_exceptions.php' => '/admin/reports/late-absent-exceptions',
    ];

    foreach ($reportRoutes as $viewPath => $resetRoute) {
        $view = file_get_contents(project_path($viewPath));
        assert_true(is_string($view));
        assert_contains('sapas-export-actions', $view);
        assert_contains('sapas-polish-filter-grid', $view);
        assert_contains('sapas-filter-actions', $view);
        assert_contains('href="' . $resetRoute . '" class="sapas-btn sapas-btn-secondary"', $view);
        assert_contains('sapas-polish-empty-state', $view);
    }
});

test('employee leave summaries and excel actions remain bilingual safe', function (): void {
    $leaveView = file_get_contents(project_path('views/leave/index.php'));
    $english = require project_path('lang/en.php');
    $myanmar = require project_path('lang/my.php');

    assert_contains("leaveTemplate('leave_module.page_summary'", $leaveView);
    assert_contains('leave_module.matching_requests_summary', $leaveView);
    assert_not_contains('matching request<?=', $leaveView);
    assert_not_contains(' on this page</p>', $leaveView);
    assert_not_contains(' of <?= $pages', $leaveView);

    assert_same('Export Excel', $english['common']['actions']['export_excel']);
    assert_not_contains('Export Excel', $myanmar['common']['actions']['export_excel']);
    assert_not_contains('No attendance or approved leave context', $myanmar['reports']['excel_empty_employee_detail']);
});

test('sprint two css preserves responsive overflow, focus, and usable controls', function (): void {
    $css = file_get_contents(project_path('assets/css/tailwind.css'));
    assert_true(is_string($css));

    assert_contains('UI/UX Polish Sprint 2', $css);
    assert_contains('.sapas-polish-table-scroll', $css);
    assert_contains('touch-action: pan-x pan-y;', $css);
    assert_contains('.sapas-data-table tbody tr:focus-within', $css);
    assert_contains('.sapas-filter-actions', $css);
    assert_contains('.sapas-export-actions', $css);
    assert_contains('.sapas-badge-success::before', $css);
    assert_contains('min-height: 2.25rem;', $css);
});
