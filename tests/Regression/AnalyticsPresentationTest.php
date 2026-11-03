<?php

declare(strict_types=1);

test('analytics averages exclude missing records while rankings label those employees truthfully', function (): void {
    $service = file_get_contents(project_path('src/Services/AnalyticsService.php'));
    $view = file_get_contents(project_path('views/analytics/index.php'));

    assert_true(is_string($service), 'AnalyticsService.php should be readable.');
    assert_true(is_string($view), 'views/analytics/index.php should be readable.');
    assert_contains("\$summary['employees_with_attendance'] > 0", $service);
    assert_contains("\$attendancePercentSum / \$summary['employees_with_attendance']", $service);
    assert_contains("'recorded_days' => \$recordedDays", $service);
    assert_contains('$rankingRows = $employeeAnalytics;', $view);
    assert_contains('analytics_module.no_records_badge', $view);
    assert_contains("analytics_module.no_records_help", $view);
});

test('analytics page no longer renders the retired specialist chart set', function (): void {
    $view = file_get_contents(project_path('views/analytics/index.php'));

    assert_true(is_string($view), 'views/analytics/index.php should be readable.');

    foreach (['sapas-heatmap', 'sapas-bullet', 'sapas-box-plot', 'sapas-scatter'] as $retiredClass) {
        assert_not_contains($retiredClass, $view);
    }
});

test('analytics employee rankings and review cards render complete scrollable lists', function (): void {
    $service = file_get_contents(project_path('src/Services/AnalyticsService.php'));
    $view = file_get_contents(project_path('views/analytics/index.php'));
    $css = file_get_contents(project_path('assets/css/tailwind.css'));

    assert_true(is_string($service), 'AnalyticsService.php should be readable.');
    assert_true(is_string($view), 'views/analytics/index.php should be readable.');
    assert_true(is_string($css), 'assets/css/tailwind.css should be readable.');

    assert_contains('findAnalyticsScope', $service);
    assert_contains("'employee_analytics' => \$employeeAnalytics", $service);
    assert_not_contains('array_slice($rankingRows', $view);
    assert_not_contains('array_slice($attentionRows', $view);
    assert_contains('sapas-analytics-scroll-region', $view);
    assert_contains('sapas-analytics-review-list', $view);
    assert_contains('sapas-analytics-review-identity', $view);
    assert_contains('.sapas-analytics-scroll-region', $css);
    assert_contains('.sapas-analytics-review-list', $css);
    assert_contains('overflow-y: auto;', $css);
});

test('analytics recognition copy and layout stay localized and separate from the employee table', function (): void {
    $english = require project_path('lang/en.php');
    $myanmar = require project_path('lang/my.php');
    $view = file_get_contents(project_path('views/analytics/index.php'));
    $css = file_get_contents(project_path('assets/css/tailwind.css'));

    assert_true(is_string($view), 'views/analytics/index.php should be readable.');
    assert_true(is_string($css), 'assets/css/tailwind.css should be readable.');
    assert_same('100% Attendance Recognition', $english['analytics_module']['recognition_title'] ?? null);
    assert_same(
        'Employees with 100% attendance for the selected period.',
        $english['analytics_module']['recognition_help'] ?? null,
    );

    foreach (['top_performers', 'recognition_title', 'recognition_help', 'no_recognition_title', 'no_recognition_help'] as $key) {
        assert_true(trim((string) ($english['analytics_module'][$key] ?? '')) !== '', 'English recognition key should be populated: ' . $key);
        assert_true(trim((string) ($myanmar['analytics_module'][$key] ?? '')) !== '', 'Myanmar recognition key should be populated: ' . $key);
        assert_not_contains('analytics_module.', (string) $myanmar['analytics_module'][$key]);
    }

    assert_not_contains('Perfect Attendance', $view);
    assert_contains("\$t('analytics_module.recognition_title')", $view);
    assert_contains("\$t('analytics_module.recognition_help')", $view);
    assert_contains('sapas-analytics-recognition-panel', $view);
    assert_contains('sapas-analytics-recognition-list', $view);
    assert_contains('sapas-analytics-recognition-name', $view);
    assert_contains('sapas-analytics-recognition-context', $view);
    assert_contains('sapas-analytics-recognition-score', $view);
    assert_not_contains('class="truncate', $view);
    assert_contains('sapas-col-approved-leave', $view);
    assert_contains('sapas-col-late', $view);
    assert_contains('min-width: 84rem;', $css);
    assert_contains('.sapas-analytics-table .sapas-col-approved-leave', $css);
    assert_contains('width: 9rem;', $css);
    assert_contains('.sapas-analytics-table .sapas-col-late', $css);
    assert_contains('width: 5.5rem;', $css);

    $tableStart = strpos($view, '<table class="min-w-full divide-y divide-slate-200 sapas-data-table sapas-analytics-table">');
    assert_true($tableStart !== false, 'Analytics employee table should be present.');
    $tableEnd = strpos($view, '</table>', (int) $tableStart);
    assert_true($tableEnd !== false, 'Analytics employee table should be complete.');
    $employeeTable = substr($view, (int) $tableStart, ((int) $tableEnd - (int) $tableStart) + strlen('</table>'));
    assert_not_contains('has_100_percent_attendance', $employeeTable);
    assert_not_contains('text-amber', $employeeTable);
});
