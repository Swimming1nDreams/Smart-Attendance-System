<?php

declare(strict_types=1);

test('focused login and compact attendance overview presentation are enabled', function (): void {
    $loginView = file_get_contents(project_path('views/auth/login.php'));
    $guestLayout = file_get_contents(project_path('views/layouts/guest.php'));
    $brand = file_get_contents(project_path('views/partials/brand.php'));
    $sidebar = file_get_contents(project_path('views/partials/sidebar.php'));
    $adminDashboard = file_get_contents(project_path('views/dashboard/admin.php'));
    $css = file_get_contents(project_path('assets/css/tailwind.css'));
    $english = require project_path('lang/en.php');
    $myanmar = require project_path('lang/my.php');

    assert_true(is_string($loginView), 'Login view should be readable.');
    assert_true(is_string($guestLayout), 'Guest layout should be readable.');
    assert_true(is_string($brand), 'Shared brand partial should be readable.');
    assert_true(is_string($sidebar), 'Sidebar should be readable.');
    assert_true(is_string($adminDashboard), 'Admin dashboard should be readable.');
    assert_true(is_string($css), 'Tailwind source should be readable.');

    assert_contains('$showGuestVisual = true;', $loginView);
    assert_contains('$guestCompactVisual = true;', $loginView);
    assert_contains("'icon' => 'clock'", $loginView);
    assert_contains("'icon' => 'leave'", $loginView);
    assert_contains("'icon' => 'analytics'", $loginView);
    assert_contains("\$guestFrameVariant = 'focused-login';", $loginView);
    assert_contains("\$guestDescription = \$t('app.official_name');", $loginView);
    assert_contains("\$title = \$t('app.official_name');", $loginView);
    assert_contains("\$guestBrandWordmark = \$t('app.acronym');", $loginView);
    assert_contains('sapas-brand-mark', $brand);
    assert_contains("\$t('app.official_name')", $brand);
    assert_contains("\$brandWordmark = \$t('app.acronym');", $sidebar);
    assert_contains("\$brandSubtitle = \$t('app.sidebar_subtitle');", $sidebar);
    assert_contains("\$t('app.acronym')", $guestLayout);
    assert_not_contains('auth.secure_sign_in', $loginView);
    assert_contains('auth.welcome_back', $loginView);
    assert_contains('auth.login_visual_title', $loginView);
    assert_contains('auth.login_form_intro', $loginView);
    assert_not_contains('auth.security_reassurance', $loginView);
    assert_contains('autocomplete="username"', $loginView);
    assert_contains('autocomplete="current-password"', $loginView);
    assert_contains('is-focused-login', $guestLayout);
    assert_contains('.sapas-guest-frame.is-focused-login', $css);
    assert_contains('.sapas-login-submit', $css);
    assert_contains("'copy' => ''", $loginView);
    assert_contains('@media (max-width: 1023px)', $css);
    assert_true(
        (bool) preg_match('/\.sapas-guest-frame\.is-focused-login\s+\.sapas-guest-form-panel\s*\{[^}]*order:\s*1;/s', $css),
        'Focused login should present the form before the promotional panel on narrow screens.'
    );
    assert_true(
        (bool) preg_match('/@media\s*\(min-width:\s*1024px\)\s*\{.*?\.sapas-guest-frame\.is-focused-login\s+\.sapas-guest-form-panel\s*\{[^}]*order:\s*2;/s', $css),
        'Focused login should restore the two-panel desktop order at large widths.'
    );
    assert_contains(':lang(my) .sapas-brand-subtitle', $css);

    assert_contains('sapas-dashboard-insight-grid', $adminDashboard);
    assert_contains('array_slice($departmentAttendance, 0, 3)', $adminDashboard);
    assert_contains('$pageDescription = $t(\'dashboard.admin.description\') . \' - \' . $today;', $adminDashboard);
    assert_contains('align-items: stretch;', $css);
    assert_contains('height: 100%;', $css);
    assert_contains('.sapas-dashboard-department-panel', $css);
    assert_contains('UI/UX Polish Sprint 1', $css);
    assert_contains("renderIcon('analytics'", $adminDashboard);
    assert_contains("renderIcon('building'", $adminDashboard);

    assert_same('Welcome back', $english['auth']['welcome_back'] ?? null);
    assert_same('Smart Attendance and Performance Analytics System', $english['app']['official_name'] ?? null);
    assert_same('SAPAS', $english['app']['name'] ?? null);
    assert_same('SAPAS', $english['app']['acronym'] ?? null);
    assert_same('Workforce Analytics', $english['app']['sidebar_subtitle'] ?? null);
    assert_same('Log in', $english['auth']['login_button'] ?? null);
    assert_same('Your session has expired. Please log in again.', $english['flash']['session_expired'] ?? null);
    assert_true(isset($myanmar['auth']['welcome_back']), 'Myanmar login heading should exist.');
    assert_same('SAPAS', $myanmar['app']['acronym'] ?? null);
    assert_same('SAPAS', $myanmar['app']['name'] ?? null);
    assert_same('စမတ် အလုပ်တက်ရောက်မှုနှင့် စွမ်းဆောင်ရည်ခွဲခြမ်းစိတ်ဖြာရေးစနစ်', $myanmar['app']['official_name'] ?? null);
    assert_same('စမတ် အလုပ်တက်ရောက်မှုနှင့် စွမ်းဆောင်ရည်ခွဲခြမ်းစိတ်ဖြာရေးစနစ်', $myanmar['app']['title_suffix'] ?? null);
    assert_true(isset($myanmar['app']['sidebar_subtitle']), 'Myanmar sidebar subtitle should exist.');
    assert_not_contains('View All Departments', $myanmar['dashboard']['admin']['view_all_departments'] ?? '');
});
