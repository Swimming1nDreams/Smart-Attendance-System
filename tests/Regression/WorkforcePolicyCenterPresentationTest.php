<?php

declare(strict_types=1);

test('workforce policy center has one page header, compact navigation, and structured review summary', function (): void {
    $view = file_get_contents(project_path('views/work_schedules/index.php'));
    $css = file_get_contents(project_path('assets/css/tailwind.css'));

    assert_true(is_string($view));
    assert_true(is_string($css));
    assert_not_contains('<section class="sapas-policy-command">', $view);
    assert_contains('max-w-7xl space-y-4', $view);
    assert_contains('class="sapas-policy-workspace-link', $view);
    assert_not_contains('<small><?= htmlspecialchars($workspaceDescriptions', $view);
    assert_contains('sapas-policy-overview-grid', $view);
    assert_contains('sapas-policy-review-schedule', $view);
    assert_contains('for ($reviewDay = 1; $reviewDay <= 7; $reviewDay++)', $view);
    assert_contains('sapas-policy-review-rules', $view);
    assert_contains('sapas-policy-review-actions', $view);
    assert_not_contains("implode('; ', \$dailyGroups)", $view);
    assert_contains('.sapas-policy-workspace-nav', $css);
    assert_contains('.sapas-policy-review-meta', $css);
});
