<?php

declare(strict_types=1);

test('approved leave attendance conflict wording is localized and add attendance stays guarded', function (): void {
    $myanmar = require project_path('lang/my.php');
    $message = (string) $myanmar['validation']['attendance_approved_leave_conflict'];
    assert_contains('တက်ရောက်သည်', $message);
    assert_contains('ပျက်ကွက်သည်', $message);
    assert_not_contains('present', $message);
    assert_not_contains('absent', $message);

    $controller = file_get_contents(project_path('src/Controllers/AttendanceController.php'));
    assert_true(is_string($controller));
    $store = phase4d1_attendance_method_source($controller, 'store');
    assert_contains('$this->ensureAdmin();', $store);
    assert_contains('$this->validateCsrfToken($request);', $store);
    assert_contains('isApprovedLeaveAttendanceConflict', $store);
});

test('admin leave decisions provide audit context before calling transactional service', function (): void {
    $controller = file_get_contents(project_path('src/Controllers/LeaveController.php'));
    assert_true(is_string($controller));

    foreach (['adminApprove', 'adminReject', 'adminCancel'] as $method) {
        $source = phase4d1_attendance_method_source($controller, $method);
        assert_contains('$this->validateCsrfToken($request);', $source);
        assert_contains('$this->auditContext($request)', $source);
    }

    assert_contains('private function auditContext(Request $request): array', $controller);
    assert_contains('\'ip_address\' => (string) $request->getServerParam(\'REMOTE_ADDR\', \'\')', $controller);
    assert_contains('\'user_agent\' => (string) $request->getServerParam(\'HTTP_USER_AGENT\', \'\')', $controller);
});

function phase4d1_attendance_method_source(string $source, string $methodName): string
{
    $start = strpos($source, 'public function ' . $methodName . '(');
    assert_true($start !== false, 'Missing AttendanceController::' . $methodName . '().');
    $next = strpos($source, "\n    public function ", $start + 1);

    return $next === false ? substr($source, $start) : substr($source, $start, $next - $start);
}
