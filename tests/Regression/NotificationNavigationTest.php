<?php

declare(strict_types=1);

test('opening the notification center from primary navigation acknowledges unread notifications', function (): void {
    $topbar = file_get_contents(project_path('views/partials/topbar.php'));
    $dashboard = file_get_contents(project_path('views/dashboard/admin.php'));
    $routes = file_get_contents(project_path('public/index.php'));

    assert_true(is_string($topbar), 'views/partials/topbar.php should be readable.');
    assert_true(is_string($dashboard), 'views/dashboard/admin.php should be readable.');
    assert_true(is_string($routes), 'public/index.php should be readable.');

    assert_contains('method="post" action="/notifications/read-all"', $topbar);
    assert_contains('method="post" action="/notifications/read-all"', $dashboard);
    assert_contains('name="csrf_token"', $topbar);
    assert_contains('name="csrf_token"', $dashboard);
    assert_contains("addRoute('POST', '/notifications/read-all'", $routes);
});
