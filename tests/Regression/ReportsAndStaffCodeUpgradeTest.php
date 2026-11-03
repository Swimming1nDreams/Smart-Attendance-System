<?php

declare(strict_types=1);

test('report services enforce department scope and expose a dedicated absence export', function (): void {
    $filters = file_get_contents(project_path('src/Services/ReportFilterService.php'));
    $employeeReport = file_get_contents(project_path('src/Services/EmployeeAttendanceReportService.php'));
    $leaveReport = file_get_contents(project_path('src/Services/LeaveReportService.php'));
    $routes = file_get_contents(project_path('public/index.php'));
    $absenceView = file_get_contents(project_path('views/reports/absence.php'));

    foreach ([$filters, $employeeReport, $leaveReport, $routes, $absenceView] as $source) {
        assert_true(is_string($source));
    }

    assert_contains('$this->resolveDepartment($filters, $input);', $filters);
    assert_contains("'validation.unauthorized_employee'", $filters);
    assert_contains("\$filters['department_id'] ?? null", $leaveReport);
    assert_contains("'employee'", $leaveReport);
    assert_contains('$departmentId, \'absent\'', $employeeReport);
    assert_contains("addRoute('GET', '/admin/reports/absence'", $routes);
    assert_contains("addRoute('GET', '/admin/reports/absence/pdf'", $routes);
    assert_contains("addRoute('GET', '/admin/reports/absence/excel'", $routes);
    assert_contains('/admin/reports/absence/pdf', $absenceView);
    assert_contains('/admin/reports/absence/excel', $absenceView);
});

test('staff code writes and demo seeds require six administrator-supplied digits', function (): void {
    $validation = file_get_contents(project_path('src/Services/EmployeeValidationService.php'));
    $form = file_get_contents(project_path('views/employees/form.php'));
    $demoSeed = file_get_contents(project_path('seeds/seed_demo_data.php'));
    $attendanceSeed = file_get_contents(project_path('scripts/seed_phase2a_attendance_demo.php'));
    $audit = file_get_contents(project_path('scripts/audit_staff_codes.php'));

    foreach ([$validation, $form, $demoSeed, $attendanceSeed, $audit] as $source) {
        assert_true(is_string($source));
    }

    assert_contains("preg_match('/\\A[0-9]{6}\\z/D', \$staffCode) !== 1", $validation);
    assert_not_contains('$staffCode === $currentStaffCode', $validation);
    assert_contains('pattern="[0-9]{6}" minlength="6" maxlength="6"', $form);
    assert_contains("sprintf('%06d', \$staffCodeCounter)", $demoSeed);
    foreach (range(31, 38) as $code) {
        assert_contains(sprintf("'%06d'", $code), $attendanceSeed);
    }
    assert_contains('No code was changed automatically.', $audit);
});
