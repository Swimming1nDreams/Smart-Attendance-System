<?php

declare(strict_types=1);

use App\Core\SessionManager;
use App\Services\AuthService;
use Tests\Support\FakeUser;

test('inactive users cannot log in', function (): void {
    reset_test_session();

    $sessionManager = new SessionManager();
    $authService = new AuthService(
        new FakeUser([
            'id' => 5,
            'name' => 'Inactive Employee',
            'email' => 'inactive@example.test',
            'password' => 'hash',
            'role' => 'employee',
            'status' => 'inactive',
        ]),
        $sessionManager
    );

    assert_false($authService->login('inactive@example.test', 'secret'));
    assert_false($sessionManager->isAuthenticated(), 'Inactive login must not authenticate a session.');
});

test('active users can establish and invalidate an authenticated session', function (): void {
    reset_test_session();

    $sessionManager = new SessionManager();
    $authService = new AuthService(
        new FakeUser([
            'id' => 7,
            'name' => 'Active Employee',
            'email' => 'active@example.test',
            'password' => 'hash',
            'role' => 'employee',
            'status' => 'active',
        ]),
        $sessionManager
    );

    assert_true($authService->login('active@example.test', 'secret'));
    assert_true($sessionManager->isAuthenticated());
    assert_same(7, $sessionManager->getUserId());
    assert_same('employee', $sessionManager->getUserRole());

    $sessionManager->generateCsrfToken();
    $sessionManager->invalidateAuthenticatedSession();

    assert_false($sessionManager->isAuthenticated(), 'Invalidating an inactive account session must clear auth.');
    assert_null($sessionManager->getUserId());
    assert_false($sessionManager->validateCsrfToken('not-the-token'), 'CSRF token should be cleared with auth session.');
});

test('csrf tokens require an exact current token match', function (): void {
    reset_test_session();

    $sessionManager = new SessionManager();
    $token = $sessionManager->generateCsrfToken();

    assert_true($sessionManager->validateCsrfToken($token));
    assert_false($sessionManager->validateCsrfToken(null));
    assert_false($sessionManager->validateCsrfToken('wrong-token'));
});

test('pending verification email can be stored and cleared', function (): void {
    reset_test_session();

    $sessionManager = new SessionManager();

    assert_null($sessionManager->getPendingVerificationEmail());

    $sessionManager->setPendingVerificationEmail(' employee@example.test ');

    assert_same('employee@example.test', $sessionManager->getPendingVerificationEmail());

    $sessionManager->clearPendingVerificationEmail();

    assert_null($sessionManager->getPendingVerificationEmail());
});

test('structural guard: csrf failures use an internal safe redirect fallback', function (): void {
    $controllerSource = file_get_contents(project_path('src/Core/Controller.php'));
    assert_true(is_string($controllerSource), 'Controller.php should be readable.');

    assert_contains('getSafeCsrfFailureRedirect()', $controllerSource);
    assert_not_contains('HTTP_REFERER', $controllerSource, 'CSRF failure must not redirect to a raw referer.');
});

test('structural guard: logout is registered as a POST route only', function (): void {
    $routes = file_get_contents(project_path('public/index.php'));
    assert_true(is_string($routes), 'public/index.php should be readable.');

    assert_contains("\$router->addRoute('POST', '/logout'", $routes);
    assert_not_contains("\$router->addRoute('GET', '/logout'", $routes);
});

test('structural guard: inactive employee redirects call the shared session invalidation helper', function (): void {
    assert_method_contains(project_path('src/Controllers/AttendanceController.php'), 'ensureActiveEmployee', 'redirectInactiveAuthenticatedUser()');
    assert_method_contains(project_path('src/Controllers/LeaveController.php'), 'ensureActiveEmployee', 'redirectInactiveAuthenticatedUser()');

    $controllerSource = file_get_contents(project_path('src/Core/Controller.php'));
    assert_true(is_string($controllerSource), 'Controller.php should be readable.');
    assert_method_contains(project_path('src/Core/Controller.php'), 'redirectInactiveAuthenticatedUser', 'invalidateAuthenticatedSession()');
});
