<?php

declare(strict_types=1);

test('operational tables and filters use compact controls', function (): void {
    $employees = file_get_contents(project_path('views/employees/index.php'));
    $attendance = file_get_contents(project_path('views/attendance/index.php'));
    $audit = file_get_contents(project_path('views/audit_logs/index.php'));
    $css = file_get_contents(project_path('assets/css/tailwind.css'));

    foreach ([$employees, $attendance, $audit, $css] as $content) {
        assert_true(is_string($content), 'Compact-control presentation files should be readable.');
    }

    assert_contains('sapas-btn sapas-btn-primary w-full sm:w-auto', $employees);
    assert_contains('sapas-btn sapas-btn-primary w-full sm:w-auto', $attendance);
    assert_contains('sapas-audit-search-field xl:col-span-2', $audit);
    assert_contains('.sapas-data-table .sapas-table-action .sapas-btn', $css);
    assert_contains('@apply w-auto justify-center px-2.5', $css);
    assert_contains('.sapas-audit-search-field', $css);
});

test('notifications use a compact readable activity list', function (): void {
    $view = file_get_contents(project_path('views/notifications/index.php'));
    $english = file_get_contents(project_path('lang/en.php'));
    $myanmar = file_get_contents(project_path('lang/my.php'));
    $css = file_get_contents(project_path('assets/css/tailwind.css'));

    foreach ([$view, $english, $myanmar, $css] as $content) {
        assert_true(is_string($content), 'Notification presentation files should be readable.');
    }

    assert_contains('sapas-notifications-panel', $view);
    assert_contains('sapas-notification-heading', $view);
    assert_contains('sapas-notification-actions', $view);
    assert_contains('notifications.recent_count', $view);
    assert_contains("'recent_count' => 'recent notifications'", $english);
    assert_contains("'recent_count' => 'မကြာသေးမီ အသိပေးချက်များ'", $myanmar);
    assert_contains('.sapas-notification-actions .sapas-btn', $css);
});
