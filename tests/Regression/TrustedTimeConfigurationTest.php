<?php

declare(strict_types=1);

test('application and database use the trusted Yangon server timezone', function (): void {
    $appConfig = file_get_contents(project_path('config/config.php'));
    $databaseConfig = file_get_contents(project_path('config/database.php'));
    $databaseSource = file_get_contents(project_path('src/Config/Database.php'));
    $frontController = file_get_contents(project_path('public/index.php'));
    $attendanceController = file_get_contents(project_path('src/Controllers/AttendanceController.php'));
    $environmentExample = file_get_contents(project_path('.env.example'));

    assert_true(is_string($appConfig));
    assert_true(is_string($databaseConfig));
    assert_true(is_string($databaseSource));
    assert_true(is_string($frontController));
    assert_true(is_string($attendanceController));
    assert_true(is_string($environmentExample));

    assert_contains("getenv('APP_TIMEZONE') ?: 'Asia/Yangon'", $appConfig);
    assert_contains("getenv('DB_TIMEZONE') ?: '+06:30'", $databaseConfig);
    assert_contains("date_default_timezone_set(\$appTimezone)", $frontController);
    assert_contains('timezone_identifiers_list()', $frontController);
    assert_contains("SET time_zone = '", $databaseSource);
    assert_contains("'timezone'", $databaseSource);
    assert_contains("date('Y-m-d')", $attendanceController);
    assert_contains("date('H:i:s')", $attendanceController);
    assert_contains('APP_TIMEZONE=Asia/Yangon', $environmentExample);
    assert_contains('DB_TIMEZONE=+06:30', $environmentExample);

    $timezonePosition = strpos($frontController, 'date_default_timezone_set($appTimezone)');
    $applicationPosition = strpos($frontController, '$app = new Application($config)');
    assert_true(is_int($timezonePosition));
    assert_true(is_int($applicationPosition));
    assert_true($timezonePosition < $applicationPosition, 'Application timezone must be set before the database and controllers are created.');
});
