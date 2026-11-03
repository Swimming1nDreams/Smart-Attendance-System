<?php

declare(strict_types=1);

test('large list controllers validate the shared 20 50 100 page sizes', function (): void {
    $employeeController = file_get_contents(project_path('src/Controllers/EmployeeController.php'));
    $leaveController = file_get_contents(project_path('src/Controllers/LeaveController.php'));
    $auditController = file_get_contents(project_path('src/Controllers/AuditLogController.php'));
    $reportController = file_get_contents(project_path('src/Controllers/ReportsController.php'));
    $filterService = file_get_contents(project_path('src/Services/ReportFilterService.php'));

    foreach ([$employeeController, $leaveController, $auditController, $reportController, $filterService] as $source) {
        assert_true(is_string($source));
    }

    assert_contains("request->query('per_page', 20)", $employeeController);
    assert_contains('in_array($requestedPerPage, [20, 50, 100], true)', $employeeController);
    assert_same(2, substr_count($leaveController, "request->query('per_page', 20)"));
    assert_same(2, substr_count($leaveController, 'in_array($requestedPerPage, [20, 50, 100], true)'));
    assert_contains("\$filters['per_page'] ?? 20", $auditController);
    assert_contains("\$input['per_page'] ?? 20", $filterService);
    assert_contains("'per_page' => \$perPage", $filterService);
    assert_same(5, substr_count($reportController, "(int) (\$filters['per_page'] ?? 20)"));
});

test('employees leave audit and report views expose the shared page-size selector', function (): void {
    $views = [
        'views/employees/index.php',
        'views/leave/index.php',
        'views/leave/admin_index.php',
        'views/audit_logs/index.php',
        'views/reports/attendance_summary.php',
        'views/reports/employee_attendance_detail.php',
        'views/reports/leave_requests.php',
        'views/reports/absence.php',
        'views/reports/late_absent_exceptions.php',
    ];

    foreach ($views as $view) {
        $source = file_get_contents(project_path($view));
        assert_true(is_string($source));
        assert_contains('$paginationPageSizeOptions = [20, 50, 100]', $source);
        assert_contains("\$paginationQuery['per_page'] = (string) \$perPage", $source);
        assert_contains('name="per_page" value="<?= $perPage ?>"', $source);
    }
});

test('shared pagination preserves filters and selected page size while resetting size changes to page one', function (): void {
    $html = render_flexible_pagination_rollout([
        'pages' => 12,
        'page' => 6,
        'perPage' => 100,
        'total' => 1150,
        'paginationPath' => '/employees',
        'paginationQuery' => ['search' => 'support', 'status' => 'active', 'per_page' => '100'],
        'paginationPageSizeOptions' => [20, 50, 100],
    ]);

    assert_contains('/employees?search=support&amp;status=active&amp;per_page=100&amp;page=1', $html);
    assert_contains('/employees?search=support&amp;status=active&amp;per_page=100&amp;page=5', $html);
    assert_contains('/employees?search=support&amp;status=active&amp;per_page=100&amp;page=7', $html);
    assert_contains('/employees?search=support&amp;status=active&amp;per_page=100&amp;page=12', $html);
    assert_contains('name="page" value="1"', $html);
    assert_contains('name="search"', $html);
    assert_contains('value="support"', $html);
    assert_contains('<option value="100" selected', $html);
});

/** @param array<string, mixed> $variables */
function render_flexible_pagination_rollout(array $variables): string
{
    extract($variables, EXTR_SKIP);
    $translations = [
        'common.pagination.label' => 'Pagination',
        'common.pagination.summary' => 'Page %1$d of %2$d',
        'common.pagination.records_summary' => 'Showing %1$d–%2$d of %3$d records',
        'common.pagination.rows_per_page' => 'Rows per page',
        'common.pagination.previous' => 'Previous page',
        'common.pagination.next' => 'Next page',
    ];
    $t = static fn (string $key): string => $translations[$key] ?? $key;

    ob_start();
    require project_path('views/partials/pagination.php');
    return (string) ob_get_clean();
}
