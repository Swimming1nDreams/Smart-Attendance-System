<?php

declare(strict_types=1);

test('shared pagination keeps first and last pages around the current-page window', function (): void {
    $html = render_pagination_enhancement([
        'pages' => 26,
        'page' => 8,
        'perPage' => 50,
        'total' => 1300,
        'paginationPath' => '/attendance',
        'paginationQuery' => ['search' => 'Ada', 'per_page' => '50'],
        'paginationPageSizeOptions' => [20, 50, 100],
    ]);

    assert_contains('Showing 351–400 of 1300 records', $html);
    assert_contains('Page 8 of 26', $html);
    assert_contains('/attendance?search=Ada&amp;per_page=50&amp;page=1', $html);
    assert_contains('/attendance?search=Ada&amp;per_page=50&amp;page=7', $html);
    assert_contains('aria-current="page"', $html);
    assert_contains('/attendance?search=Ada&amp;per_page=50&amp;page=9', $html);
    assert_contains('/attendance?search=Ada&amp;per_page=50&amp;page=26', $html);
    assert_same(2, substr_count($html, 'sapas-pagination-ellipsis'));
    assert_not_contains('>6</a>', $html);
    assert_not_contains('>10</a>', $html);
});

test('attendance pagination exposes only approved page sizes and resets to page one', function (): void {
    $controllerSource = file_get_contents(project_path('src/Controllers/AttendanceController.php'));
    $viewSource = file_get_contents(project_path('views/attendance/index.php'));
    assert_true(is_string($controllerSource));
    assert_true(is_string($viewSource));

    assert_contains("\$this->queryScalar(\$request, 'per_page', 20)", $controllerSource);
    assert_contains('in_array($requestedPerPage, [20, 50, 100], true)', $controllerSource);
    assert_contains("\$paginationQuery['per_page'] = (string) \$perPage", $viewSource);
    assert_contains('$paginationPageSizeOptions = [20, 50, 100]', $viewSource);

    $html = render_pagination_enhancement([
        'pages' => 5,
        'page' => 2,
        'perPage' => 50,
        'total' => 220,
        'paginationPath' => '/attendance',
        'paginationQuery' => ['from' => '2026-07-01', 'per_page' => '50'],
        'paginationPageSizeOptions' => [20, 50, 100],
    ]);

    assert_contains('name="page" value="1"', $html);
    assert_contains('name="from"', $html);
    assert_contains('value="2026-07-01"', $html);
    assert_contains('<option value="20"', $html);
    assert_contains('<option value="50" selected', $html);
    assert_contains('<option value="100"', $html);
});

test('attendance list clamps invalid high pages before loading records', function (): void {
    $controllerSource = file_get_contents(project_path('src/Controllers/AttendanceController.php'));
    assert_true(is_string($controllerSource));

    assert_contains('private function clampPage(int $page, int $perPage, int $total): int', $controllerSource);
    assert_contains('$page = $this->clampPage($page, $perPage, $total);', $controllerSource);

    $adminCount = '$total = $this->attendanceModel->countAll($selectedEmployeeId, $search, $fromDate, $toDate);';
    $adminFind = '$records = $this->attendanceModel->findAll($selectedEmployeeId, $search, $fromDate, $toDate, $page, $perPage);';
    assert_true(strpos($controllerSource, $adminCount) < strpos($controllerSource, $adminFind), 'Admin attendance list should count and clamp before querying page rows.');

    $employeeCount = '$total = $this->attendanceModel->countAll($userId, \'\', $fromDate, $toDate);';
    $employeeFind = '$records = $this->attendanceModel->findAll($userId, \'\', $fromDate, $toDate, $page, $perPage);';
    assert_true(strpos($controllerSource, $employeeCount) < strpos($controllerSource, $employeeFind), 'Employee attendance list should count and clamp before querying page rows.');
});

test('attendance list ignores non-scalar query parameters before casting or trimming', function (): void {
    $controllerSource = file_get_contents(project_path('src/Controllers/AttendanceController.php'));
    assert_true(is_string($controllerSource));

    assert_contains('private function queryScalar(Request $request, string $key, mixed $default = null): mixed', $controllerSource);
    foreach (['page', 'per_page', 'search', 'from', 'to', 'employee_id'] as $parameter) {
        assert_contains("\$this->queryScalar(\$request, '{$parameter}'", $controllerSource);
    }
    assert_contains('return is_scalar($value) ? $value : $default;', $controllerSource);
});

/** @param array<string, mixed> $variables */
function render_pagination_enhancement(array $variables): string
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
