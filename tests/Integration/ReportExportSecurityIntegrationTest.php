<?php

declare(strict_types=1);

use App\Controllers\ReportsController;
use App\Core\Localization;
use App\Core\Request;
use App\Core\SessionManager;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Services\AttendanceExceptionReportService;
use App\Services\AttendanceSummaryReportService;
use App\Services\AbsenceReportService;
use App\Services\DateValidationService;
use App\Services\EmployeeAttendanceReportService;
use App\Services\LeaveReportService;
use App\Services\ReportExcelExportService;
use App\Services\ReportFilterService;
use App\Services\ReportPdfExportService;
use Tests\Support\FakeApplication;

/** @template T of object @param class-string<T> $class @return T */
function reportSecurityUninitialized(string $class): object
{
    return (new ReflectionClass($class))->newInstanceWithoutConstructor();
}

test('valid administrator can load the real reports landing page', function (): void {
    reset_test_session();
    Localization::setCurrent(new Localization(project_path('lang')));
    $_SERVER['REQUEST_URI'] = '/admin/reports';

    $session = new SessionManager();
    $session->setAuthenticatedUser(1, 'Report Admin', 'report-admin@example.test', 'admin', 1);
    $controller = new ReportsController(
        reportSecurityUninitialized(ReportFilterService::class),
        reportSecurityUninitialized(AttendanceSummaryReportService::class),
        reportSecurityUninitialized(EmployeeAttendanceReportService::class),
        reportSecurityUninitialized(LeaveReportService::class),
        reportSecurityUninitialized(AttendanceExceptionReportService::class),
        reportSecurityUninitialized(AbsenceReportService::class),
        reportSecurityUninitialized(Employee::class),
        reportSecurityUninitialized(Department::class),
        new ReportPdfExportService(),
        new ReportExcelExportService(),
        $session,
        new FakeApplication(),
    );

    ob_start();
    $controller->index(new Request(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/reports']));
    $output = (string) ob_get_clean();

    assert_contains('/admin/reports/attendance-summary', $output);
    assert_contains('/admin/reports/employee-attendance-detail', $output);
    assert_contains('/admin/reports/leave-requests', $output);
    assert_contains('/admin/reports/absence', $output);
    assert_contains('/admin/reports/late-absent-exceptions', $output);
});

test('valid administrator filters still resolve and generate the intended leave report data', function (): void {
    $database = TestDatabase::connect();
    $ids = $database->resetAndSeed();
    $pdo = $database->pdo();
    Localization::setCurrent(new Localization(project_path('lang')));

    reset_test_session();
    $session = new SessionManager();
    $session->setAuthenticatedUser($ids['admin_id'], 'Integration Admin', 'admin.integration@sapas.test', 'admin', 1);
    $session->requireRole('admin');

    $leaveRequests = new LeaveRequest($pdo);
    $leaveRequests->create(
        $ids['employee_id'],
        'casual',
        '2026-08-01',
        '2026-08-01',
        1,
        'Filtered report security fixture',
        'approved',
    );
    $filters = new ReportFilterService(new DateValidationService(), new Employee($pdo), new Department($pdo));

    $attendanceFilters = $filters->attendanceSummary([
        'employee_id' => (string) $ids['employee_id'],
        'department_id' => (string) $ids['department_id'],
        'date_from' => '2026-08-01',
        'date_to' => '2026-08-31',
    ]);
    assert_same([], $attendanceFilters['errors']);
    assert_same($ids['employee_id'], $attendanceFilters['employee_id']);
    assert_same($ids['department_id'], $attendanceFilters['department_id']);

    $leaveFilters = $filters->leaveRequests([
        'employee_id' => (string) $ids['employee_id'],
        'date_from' => '2026-08-01',
        'date_to' => '2026-08-31',
        'status' => 'approved',
        'leave_type' => 'casual',
    ]);
    $result = (new LeaveReportService($leaveRequests))->generate($leaveFilters, 20, true);

    assert_same([], $leaveFilters['errors']);
    assert_same(1, $result['total_records']);
    assert_same($ids['employee_id'], (int) $result['records'][0]['user_id']);
    assert_same('approved', $result['records'][0]['status']);
    assert_same('casual', $result['records'][0]['leave_type']);

    $exceptionFilters = $filters->attendanceExceptions(['exception_type' => 'late']);
    assert_same([], $exceptionFilters['errors']);
    assert_same('late', $exceptionFilters['exception_type']);

    $analyticsFilters = $filters->analytics([
        'month' => '2026-08',
        'employee_id' => (string) $ids['employee_id'],
        'department_id' => (string) $ids['department_id'],
    ]);
    assert_same([], $analyticsFilters['errors']);
    assert_same('2026-08', $analyticsFilters['month']);
});
