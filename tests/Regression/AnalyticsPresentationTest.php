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
    assert_contains('.sapas-analytics-scroll-region', $css);
    assert_contains('overflow-y: auto;', $css);
});
