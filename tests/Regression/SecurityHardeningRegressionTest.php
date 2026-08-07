<?php

declare(strict_types=1);

test('security hardening migration adds a safe session version and administrator lock index', function (): void {
    $migration = file_get_contents(project_path('migrations/2026_07_22_000000_add_session_version_to_users.sql'));
    assert_true(is_string($migration));
    assert_contains('session_version INT UNSIGNED NOT NULL DEFAULT 1', $migration);
    assert_contains('idx_users_role_status (role, status)', $migration);
});

test('protected sessions use database-backed validation registered at bootstrap', function (): void {
    $bootstrap = file_get_contents(project_path('public/index.php'));
    $sessions = file_get_contents(project_path('src/Core/SessionManager.php'));
    assert_true(is_string($bootstrap) && is_string($sessions));
    assert_contains('new SessionSecurityService($userModel)', $bootstrap);
    assert_contains('setAuthenticatedSessionValidator', $bootstrap);
    assert_contains("translate('flash.session_invalid')", $sessions);
});

test('access denied route authenticates before rendering without requiring a specific role', function (): void {
    $controller = project_path('src/Controllers/DashboardController.php');

    assert_method_order($controller, 'accessDenied', [
        '$this->sessionManager->requireAuthentication();',
        '$this->renderView(\'errors/access_denied\');',
    ]);
    assert_not_contains('requireRole(', method_source($controller, 'accessDenied'));
});

test('valid authenticated wrong-role users can still render the access denied page', function (): void {
    reset_test_session();
    $session = new \App\Core\SessionManager();
    $session->setAuthenticatedUser(21, 'Wrong Role Employee', 'wrong-role@example.test', 'employee', 1);
    $controller = new \App\Controllers\DashboardController(
        $session,
        new \Tests\Support\FakeAttendance(),
        new \Tests\Support\FakeEmployee(),
        new \Tests\Support\FakeHoliday(),
        new \Tests\Support\FakeLeaveRequest(),
        new \Tests\Support\FakeApplication(),
    );

    ob_start();
    $controller->accessDenied();
    $output = (string) ob_get_clean();

    assert_contains('sapas-access-denied-panel', $output);
    assert_contains('/employee/dashboard', $output);
});

test('own administrator password reset keeps immediate current-browser invalidation in the controller', function (): void {
    assert_method_order(project_path('src/Controllers/EmployeeController.php'), 'resetPassword', [
        "if (\$result['current_session_invalidated'])",
        '$this->sessionManager->invalidateAuthenticatedSession();',
        "header('Location: /login');",
    ]);
});

test('administrator account mutation routes stay POST-only with controller authorization and CSRF checks', function (): void {
    $routes = file_get_contents(project_path('public/index.php'));
    assert_true(is_string($routes));

    foreach (['/employees/create', '/employees/edit', '/employees/deactivate', '/employees/reset-password', '/employees/resend-verification'] as $route) {
        assert_contains("addRoute('POST', '" . $route . "'", $routes);
    }

    $controller = project_path('src/Controllers/EmployeeController.php');
    foreach (['store', 'update', 'deactivate', 'resetPassword', 'resendVerification'] as $method) {
        assert_method_order($controller, $method, [
            '$this->ensureAdmin();',
            '$this->validateCsrfToken($request);',
        ]);
    }
});

test('security-sensitive employee writes increment session version atomically', function (): void {
    $employee = file_get_contents(project_path('src/Models/Employee.php'));
    assert_true(is_string($employee));
    assert_contains('session_version = session_version + 1', $employee);
    assert_not_contains('session_version = :session_version', $employee);
    assert_contains('FOR UPDATE', $employee);
});

test('employee password forms require confirmation and avoid password repopulation', function (): void {
    $form = file_get_contents(project_path('views/employees/form.php'));
    $show = file_get_contents(project_path('views/employees/show.php'));
    $controller = file_get_contents(project_path('src/Controllers/EmployeeController.php'));
    assert_true(is_string($form) && is_string($show) && is_string($controller));
    assert_contains('name="password_confirmation"', $form);
    assert_contains('name="password_confirmation"', $show);
    assert_contains('autocomplete="new-password"', $form);
    assert_contains('autocomplete="new-password"', $show);
    assert_not_contains("'password' => \$password", $controller);
    assert_contains('self_deactivation_protection_help', $form);
    assert_contains('self_role_protection_help', $form);
});
