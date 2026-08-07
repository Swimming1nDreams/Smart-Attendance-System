<?php

declare(strict_types=1);

test('holiday index is authenticated read-only access while mutations remain admin-only', function (): void {
    $controller = project_path('src/Controllers/HolidayController.php');

    assert_method_contains($controller, 'index', '$this->sessionManager->requireAuthentication();');

    foreach (['create', 'store', 'edit', 'update', 'delete'] as $method) {
        assert_method_contains($controller, $method, '$this->ensureAdmin();');
    }
});

test('employee navigation exposes holidays and the holiday view hides management actions by role', function (): void {
    $sidebar = file_get_contents(project_path('views/partials/sidebar.php'));
    $view = file_get_contents(project_path('views/masterdata/holidays/index.php'));

    assert_true(is_string($sidebar), 'Employee sidebar should be readable.');
    assert_true(is_string($view), 'Holiday index view should be readable.');
    assert_contains("['label' => \$t('nav.holidays'), 'href' => '/holidays'", $sidebar);
    assert_contains("\$isAdmin = (\$userRole ?? 'employee') === 'admin';", $view);
    assert_contains('<?php if ($isAdmin): ?>', $view);
});
