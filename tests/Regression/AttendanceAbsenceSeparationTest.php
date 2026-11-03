<?php

declare(strict_types=1);

test('attendance listings exclude absent records while the absence page selects only absent records', function (): void {
    $attendanceController = file_get_contents(project_path('src/Controllers/AttendanceController.php'));
    $absenceController = file_get_contents(project_path('src/Controllers/AbsenceController.php'));
    $routes = file_get_contents(project_path('public/index.php'));

    assert_true(is_string($attendanceController));
    assert_true(is_string($absenceController));
    assert_true(is_string($routes));

    assert_true(substr_count($attendanceController, ", 'absent');") >= 4);
    assert_contains("if ((\$todayRecord['status'] ?? null) === 'absent')", $attendanceController);
    assert_contains("null, 'absent'", $absenceController);
    assert_contains("addRoute('GET', '/attendance'", $routes);
    assert_contains("addRoute('GET', '/absence'", $routes);
});
