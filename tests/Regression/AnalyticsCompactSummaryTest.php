<?php

declare(strict_types=1);

test('analytics period summary uses three equal compact cards instead of the oversized hero', function (): void {
    $view = file_get_contents(project_path('views/analytics/index.php'));
    $css = file_get_contents(project_path('assets/css/tailwind.css'));

    assert_true(is_string($view));
    assert_true(is_string($css));
    assert_contains('sapas-analytics-summary-grid', $view);
    assert_contains('sapas-analytics-summary-card is-primary', $view);
    assert_contains('sapas-analytics-summary-card', $view);
    assert_not_contains('<section class="sapas-workspace-hero sapas-analytics-hero">', $view);
    assert_contains('grid-template-columns: repeat(3, minmax(0, 1fr));', $css);
    assert_contains('.sapas-analytics-summary-card', $css);
});
