<?php

declare(strict_types=1);

test('admin and employee leave tables use the compact data-table presentation', function (): void {
    $adminView = file_get_contents(project_path('views/leave/admin_index.php'));
    $employeeView = file_get_contents(project_path('views/leave/index.php'));
    $css = file_get_contents(project_path('assets/css/tailwind.css'));

    assert_true(is_string($adminView), 'Admin leave view should be readable.');
    assert_true(is_string($employeeView), 'Employee leave view should be readable.');
    assert_true(is_string($css), 'Tailwind source should be readable.');

    assert_contains('sapas-leave-admin-table', $adminView);
    assert_contains('sapas-leave-employee-table', $employeeView);
    assert_contains('sapas-data-table-scroll', $adminView);
    assert_contains('sapas-data-table-scroll', $employeeView);
    assert_contains('sapas-person-cell', $adminView);
    assert_contains('sapas-leave-actions', $employeeView);
    assert_contains('.sapas-leave-date-range', $css);
    assert_contains('.sapas-leave-reason', $css);
});
