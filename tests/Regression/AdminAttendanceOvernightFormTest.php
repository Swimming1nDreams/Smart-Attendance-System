<?php

declare(strict_types=1);

test('admin attendance form exposes overnight guidance while server policy owns next-day inference', function (): void {
    $controller = file_get_contents(project_path('src/Controllers/AttendanceController.php'));
    $form = file_get_contents(project_path('views/attendance/form.php'));
    $english = require project_path('lang/en.php');
    $myanmar = require project_path('lang/my.php');

    assert_true(is_string($controller));
    assert_true(is_string($form));
    assert_contains('$clockOutTime === $clockInTime', $controller);
    assert_not_contains('$clockOutTime <= $clockInTime', $controller);
    assert_contains('attendance.overnight_time_help', $form);
    assert_contains('assigned overnight shift', $english['attendance']['overnight_time_help']);
    assert_contains('နောက်နေ့', $myanmar['attendance']['overnight_time_help']);
});

test('manager attendance overnight mutations remain role and csrf guarded', function (): void {
    $controller = file_get_contents(project_path('src/Controllers/AttendanceController.php'));
    assert_true(is_string($controller));

    foreach (['store', 'update'] as $methodName) {
        $method = phase4c3_attendance_method_source($controller, $methodName);
        $adminPosition = strpos($method, '$this->ensureAdminOrDepartmentHead();');
        $csrfPosition = strpos($method, '$this->validateCsrfToken($request);');

        assert_true($adminPosition !== false, $methodName . ' should require manager authorization.');
        assert_true($csrfPosition !== false, $methodName . ' should validate CSRF.');
        assert_true($adminPosition < $csrfPosition, $methodName . ' should authorize before CSRF handling.');
    }
});

function phase4c3_attendance_method_source(string $source, string $methodName): string
{
    $start = strpos($source, 'public function ' . $methodName . '(');
    assert_true($start !== false, 'Missing AttendanceController::' . $methodName . '().');
    $next = strpos($source, "\n    public function ", $start + 1);

    return $next === false ? substr($source, $start) : substr($source, $start, $next - $start);
}
