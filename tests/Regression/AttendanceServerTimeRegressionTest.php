<?php

declare(strict_types=1);

test('employee self check-in uses server time and authenticated session identity only', function (): void {
    $controller = project_path('src/Controllers/AttendanceController.php');
    $source = method_source($controller, 'clockIn');

    assert_method_order($controller, 'clockIn', [
        '$this->sessionManager->requireRole(\'employee\')',
        '$this->validateCsrfToken($request)',
        '$userId = $this->sessionManager->getUserId()',
        '$this->ensureActiveEmployee($userId)',
        '$today = date(\'Y-m-d\')',
        '->clockIn($userId, $today, date(\'H:i:s\'), $userId',
    ]);

    assert_not_contains('getBodyParams()', $source, 'Self check-in must not read client-submitted attendance data.');
    assert_not_contains('$request->query(', $source, 'Self check-in must not read client-submitted attendance data from the query string.');

    foreach (['employee_id', 'attendance_date', 'clock_in_time', 'status'] as $field) {
        assert_not_contains("['" . $field . "']", $source, sprintf('Self check-in must ignore client-submitted %s.', $field));
    }
});

test('employee self check-out uses server time and authenticated session identity only', function (): void {
    $controller = project_path('src/Controllers/AttendanceController.php');
    $source = method_source($controller, 'clockOut');

    assert_method_order($controller, 'clockOut', [
        '$this->sessionManager->requireRole(\'employee\')',
        '$this->validateCsrfToken($request)',
        '$userId = $this->sessionManager->getUserId()',
        '$this->ensureActiveEmployee($userId)',
        '$today = date(\'Y-m-d\')',
        '->clockOut($userId, $today, date(\'H:i:s\'), $userId',
    ]);

    assert_not_contains('getBodyParams()', $source, 'Self check-out must not read client-submitted attendance data.');
    assert_not_contains('$request->query(', $source, 'Self check-out must not read client-submitted attendance data from the query string.');

    foreach (['employee_id', 'attendance_date', 'clock_out_time', 'status'] as $field) {
        assert_not_contains("['" . $field . "']", $source, sprintf('Self check-out must ignore client-submitted %s.', $field));
    }
});

test('employee self-attendance forms submit only the CSRF token', function (): void {
    foreach (['views/dashboard/employee.php', 'views/attendance/index.php'] as $viewPath) {
        $view = file_get_contents(project_path($viewPath));
        assert_true(is_string($view), sprintf('%s should be readable.', $viewPath));

        foreach (['clock-in', 'clock-out'] as $action) {
            $form = attendance_self_action_form($view, '/attendance/' . $action, $viewPath);
            preg_match_all('/\bname="([^"]+)"/', $form, $matches);
            assert_same(['csrf_token'], array_values(array_unique($matches[1] ?? [])), sprintf('%s %s form should submit only the CSRF token.', $viewPath, $action));

            foreach ([
                'employee_id',
                'attendance_date',
                'clock_in_time',
                'clock_out_time',
                'status',
                'remarks',
                'working_hours',
                'late_minutes',
                'overtime_minutes',
                'attendance_state',
                'calculation_version',
            ] as $field) {
                assert_not_contains('name="' . $field . '"', $form, sprintf('%s %s form must not submit %s.', $viewPath, $action, $field));
            }
        }
    }
});

test('administrator attendance corrections remain manual role-protected and CSRF-protected', function (): void {
    $controller = project_path('src/Controllers/AttendanceController.php');

    assert_method_contains($controller, 'create', '$this->ensureAdmin();');
    assert_method_contains($controller, 'edit', '$this->ensureAdmin();');

    assert_method_order($controller, 'store', [
        '$this->ensureAdmin();',
        '$this->validateCsrfToken($request);',
        '$request->getBodyParams()[\'employee_id\']',
        '$request->getBodyParams()[\'attendance_date\']',
        '$request->getBodyParams()[\'clock_in_time\']',
        '$request->getBodyParams()[\'clock_out_time\']',
        '$request->getBodyParams()[\'status\']',
        '$request->getBodyParams()[\'remarks\']',
        '->createRecord($employeeId, $attendanceDate, $clockInTime, $clockOutTime, $status',
    ]);

    assert_method_order($controller, 'update', [
        '$this->ensureAdmin();',
        '$this->validateCsrfToken($request);',
        '$request->getBodyParams()[\'employee_id\']',
        '$request->getBodyParams()[\'attendance_date\']',
        '$request->getBodyParams()[\'clock_in_time\']',
        '$request->getBodyParams()[\'clock_out_time\']',
        '$request->getBodyParams()[\'status\']',
        '$request->getBodyParams()[\'remarks\']',
        '$request->getBodyParams()[\'attendance_freshness\']',
        '->updateRecord($id, $employeeId, $attendanceDate, $clockInTime, $clockOutTime, $status',
    ]);

    $form = file_get_contents(project_path('views/attendance/form.php'));
    assert_true(is_string($form), 'Administrator attendance form should be readable.');
    foreach (['csrf_token', 'employee_id', 'attendance_date', 'clock_in_time', 'clock_out_time', 'status', 'remarks'] as $field) {
        assert_contains('name="' . $field . '"', $form, sprintf('Administrator attendance form should preserve %s correction support.', $field));
    }
    assert_contains('name="attendance_freshness"', $form, 'Administrator attendance edits should preserve freshness protection.');
});

function attendance_self_action_form(string $view, string $action, string $viewPath): string
{
    $pattern = '/<form\b[^>]*action="' . preg_quote($action, '/') . '"[^>]*>(.*?)<\/form>/s';
    if (preg_match($pattern, $view, $matches) !== 1) {
        throw new RuntimeException(sprintf('Expected %s form in %s.', $action, $viewPath));
    }

    return $matches[0];
}
