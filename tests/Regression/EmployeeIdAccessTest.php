<?php

declare(strict_types=1);

use App\Core\SessionManager;

test('employee management routes keep all list create detail and mutation actions behind the admin guard', function (): void {
    $controller = project_path('src/Controllers/EmployeeController.php');

    foreach (['index', 'create', 'store', 'show', 'edit', 'update', 'deactivate', 'resetPassword', 'resendVerification'] as $method) {
        assert_method_contains($controller, $method, '$this->ensureAdmin();');
    }

    foreach (['show', 'edit', 'update', 'deactivate', 'resetPassword', 'resendVerification'] as $method) {
        assert_method_order($controller, $method, [
            '$this->ensureAdmin();',
            '$request->query(\'id\', 0)',
            '$this->employeeModel->findById($id)',
        ]);
    }
});

test('employee management route registrations preserve read and mutation HTTP methods', function (): void {
    $routes = file_get_contents(project_path('public/index.php'));
    assert_true(is_string($routes), 'public/index.php should be readable.');

    foreach (['/employees', '/employees/create', '/employees/show', '/employees/edit'] as $route) {
        assert_contains("addRoute('GET', '" . $route . "'", $routes);
    }

    foreach (['/employees/create', '/employees/edit', '/employees/deactivate', '/employees/reset-password', '/employees/resend-verification'] as $route) {
        assert_contains("addRoute('POST', '" . $route . "'", $routes);
    }

    assert_not_contains("addRoute('GET', '/employees/deactivate'", $routes);
    assert_not_contains("addRoute('GET', '/employees/reset-password'", $routes);
    assert_not_contains("addRoute('GET', '/employees/resend-verification'", $routes);
    assert_not_contains("/employees/delete", $routes, 'Employee accounts are deactivated; no delete endpoint should appear.');
});

test('admin authorization succeeds through the same role helper used by employee management routes', function (): void {
    reset_test_session();

    $sessionManager = new SessionManager();
    $sessionManager->setAuthenticatedUser(1, 'Admin User', 'admin@example.test', 'admin', 1);
    $sessionManager->requireRole('admin');

    assert_true($sessionManager->isAuthenticated());
    assert_same('admin', $sessionManager->getUserRole());
    assert_method_order(project_path('src/Core/SessionManager.php'), 'requireRole', [
        '$this->requireAuthentication();',
        '$this->getUserRole() !== $role',
        "header('Location: /access-denied')",
    ]);
});

test('attendance employee_id filters are admin-only and employee history uses the authenticated session user', function (): void {
    $controller = project_path('src/Controllers/AttendanceController.php');

    assert_method_order($controller, 'index', [
        '$this->sessionManager->requireAuthentication();',
        '$this->queryScalar($request, \'employee_id\')',
        'elseif ($role === \'admin\')',
        '$this->attendanceModel->countAll($selectedEmployeeId',
    ]);
    assert_method_contains($controller, 'index', '$this->attendanceModel->countAll($userId, \'\', $fromDate, $toDate)');
    assert_method_contains($controller, 'index', '$this->attendanceModel->findAll($userId, \'\', $fromDate, $toDate');
    assert_method_contains($controller, 'index', '\'selectedEmployeeId\' => $role === \'admin\' ? $selectedEmployeeId : null');
});

test('employee clock actions ignore client controlled employee identity and use the session user', function (): void {
    $controller = project_path('src/Controllers/AttendanceController.php');

    foreach (['clockIn', 'clockOut'] as $method) {
        $source = method_source($controller, $method);
        assert_method_order($controller, $method, [
            '$this->sessionManager->requireRole(\'employee\')',
            '$userId = $this->sessionManager->getUserId()',
            '$this->ensureActiveEmployee($userId)',
            $method === 'clockIn' ? '->clockIn($userId' : '->clockOut($userId',
        ]);
        assert_not_contains('employee_id', $source, sprintf('%s must not accept employee_id from the request.', $method));
        assert_not_contains('user_id', $source, sprintf('%s must not accept user_id from the request.', $method));
        assert_not_contains('getBodyParams()', $source, sprintf('%s identity must come from session, not POST data.', $method));
    }
});
