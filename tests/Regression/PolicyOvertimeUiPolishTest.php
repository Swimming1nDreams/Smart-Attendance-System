<?php

declare(strict_types=1);

test('overtime review groups columns and avoids a horizontal-scroll table', function (): void {
    $view = file_get_contents(project_path('views/work_schedules/overtime_index.php'));
    $css = file_get_contents(project_path('assets/css/tailwind.css'));
    assert_true(is_string($view));
    assert_true(is_string($css));
    assert_contains('sapas-overtime-desktop-table', $view);
    assert_contains('sapas-overtime-mobile-list', $view);
    assert_contains('sapas-overtime-breakdown', $view);
    assert_not_contains('sapas-data-table-scroll overflow-x-auto', $view);
    assert_contains('.sapas-overtime-table', $css);
    assert_contains('table-layout: fixed;', $css);
    assert_contains('.sapas-overtime-mobile-card', $css);
});

test('policy overview keeps operational metrics inside one cohesive panel', function (): void {
    $view = file_get_contents(project_path('views/work_schedules/index.php'));
    $css = file_get_contents(project_path('assets/css/tailwind.css'));
    assert_true(is_string($view));
    assert_true(is_string($css));
    assert_contains('sapas-policy-kpi-strip', $view);
    assert_contains('sapas-policy-kpi-item', $view);
    assert_not_contains('<section class="grid gap-4 md:grid-cols-3">', $view);
    assert_contains('.sapas-policy-kpi-strip', $css);
});

test('application scroll surfaces stay usable without visible scrollbar chrome', function (): void {
    $css = file_get_contents(project_path('assets/css/tailwind.css'));
    assert_true(is_string($css));
    assert_contains('scrollbar-width: none;', $css);
    assert_contains('.sapas-main::-webkit-scrollbar', $css);
    assert_contains('.sapas-sidebar::-webkit-scrollbar', $css);
});
