<?php

declare(strict_types=1);

test('phase 3 report and analytics controllers delegate calculations to focused services', function (): void {
    $reports = file_get_contents(project_path('src/Controllers/ReportsController.php'));
    $analytics = file_get_contents(project_path('src/Controllers/AnalyticsController.php'));
    assert_true(is_string($reports) && is_string($analytics));
    foreach (['AttendanceSummaryReportService', 'EmployeeAttendanceReportService', 'LeaveReportService', 'AttendanceExceptionReportService', 'AbsenceReportService', 'ReportFilterService', 'ReportExcelExportService'] as $service) {
        assert_contains($service, $reports);
    }
    assert_contains('AnalyticsService', $analytics);
    assert_not_contains('foreach ($employees', $analytics);
    assert_not_contains('calculateLateMinutes', $reports);
    assert_not_contains('actualWorkMinutes', $reports);
});

test('phase 3 analytics and report retrieval use bounded batch calls instead of row queries', function (): void {
    $analytics = file_get_contents(project_path('src/Services/AnalyticsService.php'));
    $context = file_get_contents(project_path('src/Services/AttendanceReportContextService.php'));
    assert_true(is_string($analytics) && is_string($context));
    assert_same(1, substr_count($analytics, 'findAnalyticsScope('));
    assert_same(1, substr_count($analytics, 'findForUsersInRange('));
    assert_same(1, substr_count($analytics, 'findApprovedForUsersInRange('));
    assert_same(1, substr_count($analytics, 'findInRange('));
    assert_contains('findApprovedForUsersInRange', $context);
    assert_contains('findInRange', $context);
    assert_not_contains('findById(', $context);
});

test('phase 3 migration retains staff-code uniqueness while account creation requires admin supplied six digits', function (): void {
    $migration = file_get_contents(project_path('migrations/2026_07_22_010000_add_phase3_audit_staff_and_indexes.sql'));
    assert_true(is_string($migration));
    foreach (['idx_attendance_date_status_user', 'idx_leave_status_user_range', 'idx_users_department_role_status', 'idx_audit_logs_actor_created', 'idx_audit_logs_entity'] as $index) {
        assert_contains($index, $migration);
    }
    assert_contains('uq_users_staff_code', file_get_contents(project_path('migrations/2026_07_13_000000_add_verification_codes_and_employee_options.sql')));
    $accountService = file_get_contents(project_path('src/Services/AccountManagementService.php'));
    $validationService = file_get_contents(project_path('src/Services/EmployeeValidationService.php'));
    assert_contains("preg_match('/\\A[0-9]{6}\\z/D'", (string) $accountService);
    assert_contains('employee_staff_code_format', (string) $validationService);
    assert_not_contains('nextStaffCode', (string) $accountService);
});

test('phase 3 credential output is ignored and release packaging excludes private storage', function (): void {
    $ignore = file_get_contents(project_path('.gitignore'));
    $package = file_get_contents(project_path('scripts/package-release.php'));
    $readme = file_get_contents(project_path('README.md'));
    assert_contains('storage/private/', (string) $ignore);
    assert_contains('storage/private', (string) $package);
    assert_not_contains('Temporary Password |', (string) $readme);
    assert_not_contains('Aq!', (string) $readme);
});

test('phase 3 audit UI is admin-only and all required operation families emit audit actions', function (): void {
    $controller = file_get_contents(project_path('src/Controllers/AuditLogController.php'));
    $routes = file_get_contents(project_path('public/index.php'));
    assert_same(2, substr_count((string) $controller, '$this->ensureAdmin();'));
    assert_contains("addRoute('GET', '/admin/audit-logs'", (string) $routes);
    assert_contains("addRoute('GET', '/admin/audit-logs/show'", (string) $routes);
    assert_not_contains("addRoute('POST', '/admin/audit-logs", (string) $routes);

    $sources = implode("\n", array_map(static fn (string $path): string => (string) file_get_contents(project_path($path)), [
        'src/Services/AccountManagementService.php', 'src/Services/AttendanceOperationService.php',
        'src/Services/LeaveOperationService.php', 'src/Controllers/AuthController.php',
        'src/Controllers/DepartmentController.php', 'src/Controllers/PositionController.php',
        'src/Controllers/HolidayController.php', 'src/Controllers/ReportsController.php',
    ]));
    foreach (['employee.created', 'employee.updated', 'employee.role_changed', 'employee.password_reset',
        'security.session_invalidated', 'attendance.created', 'attendance.updated', 'leave.approved', 'leave.rejected',
        'leave.cancelled_by_manager', 'leave.cancelled_by_employee', 'department.created', 'position.updated',
        'holiday.deleted', 'report.pdf_exported', 'report.excel_exported', 'auth.admin_login_succeeded', 'auth.failed_threshold'] as $action) {
        assert_contains($action, $sources);
    }
});
