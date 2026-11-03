<?php

declare(strict_types=1);

use App\Models\Attendance;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Services\AbsenceReportService;
use App\Services\DateValidationService;
use App\Services\LeaveReportService;
use App\Services\ReportFilterService;

test('database integration: attendance summary and exception report filters use seeded records', function (): void {
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $attendance = new Attendance($database->pdo());

    $attendance->create($ids['employee_id'], '2026-11-02', '09:05:00', '17:00:00', 7.92, 'present', 0, null);
    $attendance->create($ids['employee_id'], '2026-11-03', '10:00:00', '17:00:00', 7.00, 'present', 60, null);
    $attendance->create($ids['other_employee_id'], '2026-11-04', null, null, 0.00, 'absent', 0, null);

    $totals = $attendance->getAttendanceSummaryReportTotals('2026-11-01', '2026-11-30', $ids['department_id'], null);
    assert_same(2, $totals['total_records']);
    assert_same(2, $totals['present_count']);
    assert_same(1, $totals['late_count']);
    assert_same(0, $totals['absent_count']);
    assert_same(895, $totals['total_work_minutes']);

    assert_same(1, $attendance->countLateAbsentExceptionReportRecords('2026-11-01', '2026-11-30', null, null, 'all'));
    assert_same(1, $attendance->countLateAbsentExceptionReportRecords('2026-11-01', '2026-11-30', null, null, 'late'));
    assert_same(0, $attendance->countLateAbsentExceptionReportRecords('2026-11-01', '2026-11-30', null, null, 'absent'));
});

test('database integration: leave request report filters respect status type and date overlap', function (): void {
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $leaveRequests = new LeaveRequest($database->pdo());

    $approvedId = $leaveRequests->create($ids['employee_id'], 'medical', '2026-12-05', '2026-12-07', 3, 'Medical', 'approved');
    $leaveRequests->create($ids['employee_id'], 'casual', '2026-12-10', '2026-12-10', 1, 'Casual', 'pending');
    $leaveRequests->create($ids['other_employee_id'], 'medical', '2027-01-01', '2027-01-01', 1, 'Other range', 'approved');

    $matches = $leaveRequests->findAllRequestsForAdminListing(null, 'approved', 'medical', '', '2026-12-06', '2026-12-06', 1, 20);

    assert_same(1, count($matches));
    assert_same($approvedId, (int) $matches[0]['id']);
    assert_same(1, $leaveRequests->countAllRequestsForAdminListing(null, 'approved', 'medical', '', '2026-12-06', '2026-12-06'));
});

test('database integration: department scoped reports reject cross-department employees and rows', function (): void {
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $pdo = $database->pdo();
    $pdo->exec("INSERT INTO departments (name, description) VALUES ('Finance', 'Second report scope')");
    $otherDepartmentId = (int) $pdo->lastInsertId();
    $statement = $pdo->prepare(
        "INSERT INTO users (name, email, password, role, department_id, status, staff_code, email_verified_at)
         VALUES ('Finance Employee', 'finance.report@sapas.test', :password, 'employee', :department_id, 'active', '998877', NOW())"
    );
    $statement->execute([':password' => password_hash('Password123!', PASSWORD_DEFAULT), ':department_id' => $otherDepartmentId]);
    $otherEmployeeId = (int) $pdo->lastInsertId();

    $leaveRequests = new LeaveRequest($pdo);
    $ownLeaveId = $leaveRequests->create($ids['employee_id'], 'casual', '2026-12-14', '2026-12-14', 1, 'Own scope', 'approved');
    $leaveRequests->create($otherEmployeeId, 'casual', '2026-12-14', '2026-12-14', 1, 'Other scope', 'approved');
    $leaveResult = (new LeaveReportService($leaveRequests))->generate([
        'date_from' => '2026-12-01', 'date_to' => '2026-12-31', 'department_id' => $ids['department_id'],
        'employee_id' => null, 'status' => null, 'leave_type' => null, 'page' => 1, 'errors' => [],
    ], 20, true);
    assert_same(1, $leaveResult['total_records']);
    assert_same($ownLeaveId, (int) $leaveResult['records'][0]['id']);

    $filterService = new ReportFilterService(new DateValidationService(), new Employee($pdo), new Department($pdo));
    $crossDepartment = $filterService->employeeAttendance([
        'department_id' => $ids['department_id'], 'employee_id' => $otherEmployeeId,
    ]);
    assert_same(null, $crossDepartment['employee_id']);
    assert_true($crossDepartment['errors'] !== []);

    $attendance = new Attendance($pdo);
    $attendance->create($ids['employee_id'], '2026-12-15', null, null, 0.0, 'absent', 0, 'Own scope');
    $attendance->create($otherEmployeeId, '2026-12-15', null, null, 0.0, 'absent', 0, 'Other scope');
    $absenceResult = (new AbsenceReportService($attendance))->generate([
        'date_from' => '2026-12-01', 'date_to' => '2026-12-31', 'department_id' => $ids['department_id'],
        'employee_id' => null, 'page' => 1, 'errors' => [],
    ], 20, true);
    assert_same(1, $absenceResult['total_records']);
    assert_same($ids['employee_id'], (int) $absenceResult['records'][0]['user_id']);
});
