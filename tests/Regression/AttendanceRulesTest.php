<?php

declare(strict_types=1);

use App\Controllers\AttendanceController;
use App\Core\SessionManager;
use Tests\Support\FakeApplication;
use Tests\Support\FakeAttendance;
use Tests\Support\FakeEmployee;
use Tests\Support\FakeHoliday;
use Tests\Support\FakeLeaveRequest;

function make_attendance_controller(bool $hasApprovedLeave = false, array $holidays = []): AttendanceController
{
    reset_test_session();

    return new AttendanceController(
        new FakeAttendance(),
        new FakeHoliday($holidays),
        new FakeEmployee([
            11 => ['id' => 11, 'role' => 'employee', 'status' => 'active', 'name' => 'Employee One'],
        ]),
        new FakeLeaveRequest([], $hasApprovedLeave),
        new SessionManager(),
        new FakeApplication()
    );
}

test('attendance status resolution blocks holidays and weekends before normal present days', function (): void {
    $controller = make_attendance_controller(false, ['2026-07-02']);

    assert_same('holiday', call_private($controller, 'resolveAttendanceStatus', ['2026-07-02']));
    assert_same('weekend', call_private($controller, 'resolveAttendanceStatus', ['2026-07-04']));
    assert_same('present', call_private($controller, 'resolveAttendanceStatus', ['2026-07-06']));
});

test('approved leave conflicts block present or absent attendance rows only', function (): void {
    $controller = make_attendance_controller(true);

    assert_true(call_private($controller, 'isApprovedLeaveAttendanceConflict', [11, '2026-07-06', 'present']));
    assert_true(call_private($controller, 'isApprovedLeaveAttendanceConflict', [11, '2026-07-06', 'absent']));
    assert_false(call_private($controller, 'isApprovedLeaveAttendanceConflict', [11, '2026-07-06', 'holiday']));
    assert_false(call_private($controller, 'isApprovedLeaveAttendanceConflict', [11, '', 'present']));
});

test('admin add attendance rejects equal same-day check in and check out times', function (): void {
    $controller = make_attendance_controller();

    $errors = call_private($controller, 'validateAttendanceData', [
        11,
        '2026-07-06',
        '09:00',
        '09:00',
        '09:00:00',
        '09:00:00',
        'present',
    ]);

    assert_contains('Check Out time must be after Check In time.', implode("\n", $errors));
});

test('workforce seed uses the configured safe demo domain and covers attendance through July 21', function (): void {
    $source = file_get_contents(project_path('seeds/seed_demo_data.php'));

    assert_true($source !== false);
    assert_contains("SAPAS_DEMO_EMAIL_DOMAIN", $source);
    assert_contains("'silveroakhr.com'", $source);
    assert_contains("buildSeedDates('2026-06-01', '2026-07-21')", $source);
    assert_not_contains('$namedEmployees', $source);
    assert_not_contains('@gmail.com', $source);
    assert_not_contains('@sapas.local', $source);
});


test('employee check-in persists resolved weekend or holiday status instead of forcing present', function (): void {
    $source = file_get_contents(project_path('src/Services/AttendanceOperationService.php'));
    assert_true(is_string($source));

    $start = strpos($source, 'public function clockIn(');
    $end = strpos($source, 'public function clockOut(', $start === false ? 0 : $start);
    assert_true($start !== false && $end !== false, 'AttendanceOperationService clock-in method should be inspectable.');

    $clockInMethod = substr($source, $start, $end - $start);
    assert_contains('$calculation = $this->deriveAuthoritativeValues($employee, $attendanceDate, $clockInTime, null, \'present\');', $clockInMethod);
    assert_contains('$status = (string) $calculation[\'status\'];', $clockInMethod);
    assert_contains('$lateMinutes = (int) $calculation[\'late_minutes\'];', $clockInMethod);
    assert_contains('$this->insertAttendance($userId, $attendanceDate, $clockInTime, null, 0.0, $status, $lateMinutes, null, $calculation);', $clockInMethod);
    assert_contains("':status' => \$status", $clockInMethod);
    assert_not_contains("':status' => 'present'", $clockInMethod);
});
