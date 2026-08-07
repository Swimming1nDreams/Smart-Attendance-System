<?php

declare(strict_types=1);

test('responsive shell accessibility and remaining UI labels are hardened', function (): void {
    $appLayout = file_get_contents(project_path('views/layouts/app.php'));
    $guestLayout = file_get_contents(project_path('views/layouts/guest.php'));
    $login = file_get_contents(project_path('views/auth/login.php'));
    $accessDenied = file_get_contents(project_path('views/errors/access_denied.php'));
    $dashboard = file_get_contents(project_path('views/dashboard/admin.php'));
    $javascript = file_get_contents(project_path('assets/js/main.js'));
    $css = file_get_contents(project_path('assets/css/tailwind.css'));
    $english = require project_path('lang/en.php');
    $myanmar = require project_path('lang/my.php');

    foreach ([$appLayout, $guestLayout, $login, $accessDenied, $dashboard, $javascript, $css] as $content) {
        assert_true(is_string($content), 'Responsive/accessibility presentation files should be readable.');
    }

    assert_contains('data-scrollable-table-label', $appLayout);
    assert_contains('data-scrollable-table-label', $guestLayout);
    assert_contains('role="alert" aria-live="assertive"', $login);
    assert_contains('aria-describedby="login-errors"', $login);

    assert_contains("\$layout = 'layouts/app';", $accessDenied);
    assert_contains("errors.access_denied.title", $accessDenied);
    assert_not_contains('You are not authorized to view this page.', $accessDenied);
    assert_contains("dashboard.admin.view_all_departments", $dashboard);
    assert_not_contains('>View All Departments<', $dashboard);

    assert_contains("sidebar.inert = !available", $javascript);
    assert_contains("event.key !== 'Tab'", $javascript);
    assert_contains('initializeScrollableTables', $javascript);
    assert_contains("wrapper.tabIndex = 0", $javascript);
    assert_contains('.sapas-data-table-scroll.is-scrollable:focus-visible', $css);
    assert_contains('.sapas-access-denied-panel', $css);
    assert_contains('width: auto;', $css);

    assert_same('Scrollable data table', $english['layout']['scrollable_table'] ?? null);
    assert_true(isset($myanmar['layout']['scrollable_table']), 'Myanmar scrollable-table label should exist.');
    assert_same('View All Departments', $english['dashboard']['admin']['view_all_departments'] ?? null);
    assert_true(isset($myanmar['dashboard']['admin']['view_all_departments']), 'Myanmar department action should exist.');
    assert_same('Access Denied', $english['errors']['access_denied']['title'] ?? null);
    assert_true(isset($myanmar['errors']['access_denied']['title']), 'Myanmar access-denied translation should exist.');
    assert_true($english['errors']['access_denied']['title'] !== $myanmar['errors']['access_denied']['title']);
    assert_true($english['errors']['access_denied']['message'] !== $myanmar['errors']['access_denied']['message']);
    assert_true($english['errors']['access_denied']['back_to_dashboard'] !== $myanmar['errors']['access_denied']['back_to_dashboard']);
});
