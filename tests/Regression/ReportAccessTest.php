<?php

declare(strict_types=1);

/** @return array<string, string> */
function reportAdminRouteActions(): array
{
    return [
        '/admin/reports' => 'index',
        '/admin/reports/attendance-summary' => 'attendanceSummary',
        '/admin/reports/attendance-summary/pdf' => 'attendanceSummaryPdf',
        '/admin/reports/attendance-summary/excel' => 'attendanceSummaryExcel',
        '/admin/reports/employee-attendance-detail' => 'employeeAttendanceDetail',
        '/admin/reports/employee-attendance-detail/pdf' => 'employeeAttendanceDetailPdf',
        '/admin/reports/employee-attendance-detail/excel' => 'employeeAttendanceDetailExcel',
        '/admin/reports/leave-requests' => 'leaveRequests',
        '/admin/reports/leave-requests/pdf' => 'leaveRequestsPdf',
        '/admin/reports/leave-requests/excel' => 'leaveRequestsExcel',
        '/admin/reports/late-absent-exceptions' => 'lateAbsentExceptions',
        '/admin/reports/late-absent-exceptions/pdf' => 'lateAbsentExceptionsPdf',
        '/admin/reports/late-absent-exceptions/excel' => 'lateAbsentExceptionsExcel',
        '/admin/analytics' => 'index',
    ];
}

/** @return array{exit_code:int,stdout:string,stderr:string} */
function runReportAdminGuardProbe(?string $role): array
{
    if (!function_exists('proc_open')) {
        skip_test('proc_open is required for isolated redirect/exit authorization probes.');
    }

    static $results = [];
    $key = $role ?? 'unauthenticated';
    if (isset($results[$key])) {
        return $results[$key];
    }

    $authentication = $role === null
        ? ''
        : '$session->setAuthenticatedUser(77, \'Report Probe\', \'report-probe@example.test\', '
            . var_export($role, true) . ', 1);';
    $code = 'require ' . var_export(project_path('tests/bootstrap.php'), true) . ';'
        . 'reset_test_session();'
        . '$session = new \\App\\Core\\SessionManager();'
        . $authentication
        . '$session->requireRole(\'admin\');'
        . 'echo \'REPORT_ADMIN_GUARD_ALLOWED\';';

    $process = proc_open(
        [PHP_BINARY, '-d', 'xdebug.mode=off', '-r', $code],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        project_path(),
        null,
        ['bypass_shell' => true],
    );
    assert_true(is_resource($process), 'The isolated report authorization probe should start.');

    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    return $results[$key] = ['exit_code' => $exitCode, 'stdout' => $stdout, 'stderr' => $stderr];
}

test('structural guard: all report routes are admin-prefixed and no employee report route is registered', function (): void {
    $routes = file_get_contents(project_path('public/index.php'));
    assert_true(is_string($routes), 'public/index.php should be readable.');

    foreach (reportAdminRouteActions() as $route => $action) {
        assert_contains($route, $routes, sprintf('Expected report route %s to be registered.', $route));
        $controller = $route === '/admin/analytics' ? '$analyticsController' : '$reportsController';
        assert_contains(
            $controller . '->' . $action . '($request);',
            $routes,
            sprintf('Expected %s to dispatch to the protected %s action.', $route, $action),
        );
    }

    assert_not_contains('/employee/reports', $routes, 'Reports V1 must not expose employee self-service report routes.');
    assert_not_contains('/employee/analytics', $routes, 'Analytics must not expose employee self-service routes.');
});

test('runtime admin guard denies unauthenticated and employee sessions while allowing administrators', function (): void {
    foreach ([null, 'employee'] as $role) {
        $probe = runReportAdminGuardProbe($role);
        assert_same(0, $probe['exit_code'], 'A denied authorization redirect should exit normally.');
        assert_same('', $probe['stdout'], sprintf('%s sessions must stop before protected report output.', $role ?? 'Unauthenticated'));
    }

    $admin = runReportAdminGuardProbe('admin');
    assert_same(0, $admin['exit_code'], 'The administrator authorization probe should exit normally.');
    assert_same('REPORT_ADMIN_GUARD_ALLOWED', $admin['stdout'], 'A valid administrator should pass the shared report guard.');
});

test('structural guard: report pages and PDF or Excel exports call admin authorization before rendering/exporting', function (): void {
    $file = project_path('src/Controllers/ReportsController.php');

    foreach ([
        'index',
        'attendanceSummary',
        'attendanceSummaryPdf',
        'attendanceSummaryExcel',
        'employeeAttendanceDetail',
        'employeeAttendanceDetailPdf',
        'employeeAttendanceDetailExcel',
        'leaveRequests',
        'leaveRequestsPdf',
        'leaveRequestsExcel',
        'lateAbsentExceptions',
        'lateAbsentExceptionsPdf',
        'lateAbsentExceptionsExcel',
    ] as $method) {
        assert_method_contains($file, $method, '$this->ensureAdminOrDepartmentHead();');
    }
});

test('tampered report filters remain unreachable until after admin authorization', function (): void {
    $file = project_path('src/Controllers/ReportsController.php');

    foreach ([
        'attendanceSummary' => 'attendanceSummary',
        'attendanceSummaryPdf' => 'attendanceSummary',
        'attendanceSummaryExcel' => 'attendanceSummary',
        'employeeAttendanceDetail' => 'employeeAttendance',
        'employeeAttendanceDetailPdf' => 'employeeAttendance',
        'employeeAttendanceDetailExcel' => 'employeeAttendance',
        'leaveRequests' => 'leaveRequests',
        'leaveRequestsPdf' => 'leaveRequests',
        'leaveRequestsExcel' => 'leaveRequests',
        'lateAbsentExceptions' => 'attendanceExceptions',
        'lateAbsentExceptionsPdf' => 'attendanceExceptions',
        'lateAbsentExceptionsExcel' => 'attendanceExceptions',
    ] as $method => $filterMethod) {
        assert_method_order($file, $method, [
            '$this->ensureAdminOrDepartmentHead();',
            '$input = array_merge($request->getQueryParams(), $this->resolveScopeFilters($request));',
            '$this->filters->' . $filterMethod . '($input)',
        ]);
    }

    $employee = runReportAdminGuardProbe('employee');
    assert_same('', $employee['stdout'], 'Employee sessions must be terminated before employee_id, department_id, date, status, leave type, or exception filters are resolved.');
});

test('tampered analytics month department and employee filters remain behind admin authorization', function (): void {
    $routes = file_get_contents(project_path('public/index.php'));
    assert_true(is_string($routes), 'public/index.php should be readable.');
    assert_contains("addRoute('GET', '/admin/analytics'", $routes);
    assert_not_contains("addRoute('GET', '/employee/analytics'", $routes);

    assert_method_order(project_path('src/Controllers/AnalyticsController.php'), 'index', [
        '$this->ensureAdminOrDepartmentHead();',
        '$input = array_merge($request->getQueryParams(), $this->resolveScopeFilters($request));',
        '$this->filters->analytics($input)',
        '$this->analytics->generate($filters)',
    ]);

    $employee = runReportAdminGuardProbe('employee');
    assert_same('', $employee['stdout'], 'Employee sessions must be terminated before analytics month, department_id, or employee_id filters are resolved.');
});

test('successful report exports preserve existing best-effort audit events', function (): void {
    $controller = project_path('src/Controllers/ReportsController.php');

    assert_method_order($controller, 'streamAndAudit', [
        '$this->pdf->stream(',
        '$this->audit?->record(',
        "'report.pdf_exported'",
    ]);
    assert_method_order($controller, 'streamExcelAndAudit', [
        '$this->excel->stream(',
        '$this->audit?->record(',
        "'report.excel_exported'",
    ]);
    assert_method_contains($controller, 'streamAndAudit', 'false);');
    assert_method_contains($controller, 'streamExcelAndAudit', 'false);');
});

test('current report downloads use the OAAS filename prefix', function (): void {
    $controller = file_get_contents(project_path('src/Controllers/ReportsController.php'));
    $pdfExporter = file_get_contents(project_path('src/Services/ReportPdfExportService.php'));
    assert_true(is_string($controller), 'ReportsController should be readable.');
    assert_true(is_string($pdfExporter), 'ReportPdfExportService should be readable.');

    foreach ([
        'oaas_attendance_summary_',
        'oaas_employee_attendance_detail_',
        'oaas_leave_requests_',
        'oaas_late_absent_exceptions_',
    ] as $prefix) {
        assert_contains($prefix, $controller);
    }

    assert_not_contains("'sapas_", $controller);
    assert_contains('oaas_report.pdf', $pdfExporter);
    assert_not_contains('sapas_report.pdf', $pdfExporter);
});
