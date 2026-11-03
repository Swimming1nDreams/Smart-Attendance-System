<?php

declare(strict_types=1);

test('add employee form exposes scoped account, employment, and work-rule options', function (): void {
    $view = file_get_contents(project_path('views/employees/form.php'));
    $controller = file_get_contents(project_path('src/Controllers/EmployeeController.php'));
    $validationService = file_get_contents(project_path('src/Services/EmployeeValidationService.php'));

    assert_true($view !== false && $controller !== false && $validationService !== false);

    foreach (['send_verification_code', 'employment_type', 'join_date', 'expected_daily_work_hours', 'overtime_eligible'] as $field) {
        assert_contains('name="' . $field . '"', $view);
    }

    assert_contains('id="staff_code"', $view);
    assert_contains('name="staff_code"', $view);
    assert_contains('pattern="[0-9]{6}"', $view);
    assert_contains('inputmode="numeric"', $view);
    assert_not_contains('readonly aria-readonly="true"', $view);

    assert_contains('\'send_verification_code\' => $sendVerificationCode', $validationService);
    assert_contains('$this->employeeValidationService->validate(', $controller);
    assert_not_contains('salary', strtolower($view));
    assert_not_contains('payroll', strtolower($view));
});

test('add employee form marks required select controls as required', function (): void {
    $view = file_get_contents(project_path('views/employees/form.php'));
    assert_true(is_string($view));

    foreach (['role', 'status', 'employment_type', 'overtime_eligible'] as $field) {
        assert_contains('id="' . $field . '" name="' . $field . '" required', $view);
    }
});

test('employee creation rejects missing select values and crafted invalid lookup ids', function (): void {
    $controller = file_get_contents(project_path('src/Controllers/EmployeeController.php'));
    $validationService = file_get_contents(project_path('src/Services/EmployeeValidationService.php'));
    assert_true(is_string($controller));
    assert_true(is_string($validationService));

    $storePosition = strpos($controller, 'public function store');
    assert_true($storePosition !== false, 'Employee store action should exist.');
    $storeMethod = substr($controller, $storePosition, 1800);

    assert_contains('$this->employeeValidationService->validate($request->getBodyParams())', $storeMethod);
    assert_contains('$input[\'role\'] ?? \'\'', $validationService);
    assert_contains('$input[\'status\'] ?? \'\'', $validationService);
    assert_contains('$input[\'employment_type\'] ?? \'\'', $validationService);
    assert_contains('normalizeOptionalInt', $validationService);
    assert_contains('$errors[] = \'department_invalid\'', $validationService);
    assert_contains('$errors[] = \'position_invalid\'', $validationService);
    assert_contains('filter_var($value, FILTER_VALIDATE_INT)', $validationService);
});

test('employee create and edit require explicit expected hours and overtime eligibility values', function (): void {
    $validationService = file_get_contents(project_path('src/Services/EmployeeValidationService.php'));
    $english = file_get_contents(project_path('lang/en.php'));
    $myanmar = file_get_contents(project_path('lang/my.php'));
    assert_true(is_string($validationService));
    assert_true(is_string($english));
    assert_true(is_string($myanmar));

    assert_contains('$input[\'expected_daily_work_hours\'] ?? \'\'', $validationService);
    assert_contains('$input[\'overtime_eligible\'] ?? null', $validationService);
    assert_contains('!is_numeric($expectedDailyWorkHoursValue)', $validationService);
    assert_contains('$errors[] = \'employee_overtime_eligible_invalid\'', $validationService);
    assert_contains('employee_overtime_eligible_invalid', $english);
    assert_contains('employee_overtime_eligible_invalid', $myanmar);
});

test('employee edit form posts expected security state for role and status changes', function (): void {
    $view = file_get_contents(project_path('views/employees/form.php'));
    $controller = file_get_contents(project_path('src/Controllers/EmployeeController.php'));
    $service = file_get_contents(project_path('src/Services/AccountManagementService.php'));
    assert_true(is_string($view));
    assert_true(is_string($controller));
    assert_true(is_string($service));

    foreach (['expected_security_role', 'expected_security_status', 'expected_security_session_version'] as $field) {
        assert_contains('name="' . $field . '"', $view);
        assert_contains('$request->getBodyParams()[\'' . $field . '\']', $controller);
    }

    assert_contains('AccountSecurityException::STALE_SECURITY_STATE', $service);
    assert_contains('flash.employee_security_state_changed', $controller);
});

test('employee details use em dash for unavailable optional values', function (): void {
    $view = file_get_contents(project_path('views/employees/show.php'));
    assert_true(is_string($view));

    assert_contains('$emptyValue = \'—\';', $view);
    assert_contains('$displayOptional($profile[\'phone\'] ?? null)', $view);
    assert_contains('$displayOptional($profile[\'address\'] ?? null)', $view);
    assert_not_contains('?? \'-\'', $view);
});

test('employee details password reset posts expected session version and clear reset copy', function (): void {
    $view = file_get_contents(project_path('views/employees/show.php'));
    $controller = file_get_contents(project_path('src/Controllers/EmployeeController.php'));
    $english = file_get_contents(project_path('lang/en.php'));
    $myanmar = file_get_contents(project_path('lang/my.php'));
    assert_true(is_string($view));
    assert_true(is_string($controller));
    assert_true(is_string($english));
    assert_true(is_string($myanmar));

    assert_contains('name="expected_session_version"', $view);
    assert_contains('common.confirm.reset_employee_password', $view);
    assert_contains('$request->getBodyParams()[\'expected_session_version\']', $controller);
    assert_contains('sendForAdminResend', $controller);
    assert_contains('employee.verification_resent', $controller);
    assert_contains('employee_verification_resend_cooldown', $english);
    assert_contains('employee_verification_resend_cooldown', $myanmar);
});

test('employee account management is wired with audit logging in the application entrypoint', function (): void {
    $entrypoint = file_get_contents(project_path('public/index.php'));
    assert_true(is_string($entrypoint));

    $servicePosition = strpos($entrypoint, '$accountManagementService = new AccountManagementService(');
    assert_true($servicePosition !== false, 'Account management service should be wired.');

    $serviceWiring = substr($entrypoint, $servicePosition, 360);
    assert_contains('$auditLogService,', $serviceWiring);

    $auditPosition = strpos($entrypoint, '$auditLogService = new AuditLogService(');
    assert_true($auditPosition !== false && $auditPosition < $servicePosition, 'Audit service should be created before account management wiring.');

    $controllerPosition = strpos($entrypoint, '$employeeController = new EmployeeController(');
    assert_true($controllerPosition !== false, 'Employee controller should be wired.');
    $controllerWiring = substr($entrypoint, $controllerPosition, 420);
    assert_contains('$employeeValidationService', $controllerWiring);
    assert_contains('$auditLogService', $controllerWiring);
});
