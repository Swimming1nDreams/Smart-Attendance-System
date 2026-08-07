<?php

declare(strict_types=1);

test('employee form keeps live feedback lightweight while backend validation stays authoritative', function (): void {
    $view = file_get_contents(project_path('views/employees/form.php'));
    $controller = file_get_contents(project_path('src/Controllers/EmployeeController.php'));
    $service = file_get_contents(project_path('src/Services/EmployeeValidationService.php'));
    $javascript = file_get_contents(project_path('assets/js/main.js'));
    $english = require project_path('lang/en.php');
    $myanmar = require project_path('lang/my.php');

    assert_true(is_string($view));
    assert_true(is_string($controller));
    assert_true(is_string($service));
    assert_true(is_string($javascript));

    assert_contains('data-employee-form', $view);
    assert_contains('sapas-employee-form', $view);
    assert_contains('sapas-form-error-summary', $view);
    assert_contains('employees_module.required_fields_help', $view);
    assert_contains('employees_module.role_help', $view);
    assert_contains('employees_module.status_help', $view);
    assert_contains('employees_module.department_help', $view);
    assert_contains('employees_module.position_help', $view);
    assert_contains('employees_module.address_help', $view);
    assert_contains('data-live-email', $view);
    assert_contains('data-live-password', $view);
    assert_contains('data-live-password-confirmation', $view);
    assert_contains('data-password-rule="length"', $view);
    assert_contains('data-password-rule="letter"', $view);
    assert_contains('data-password-rule="number"', $view);
    assert_not_contains('data-password-rule="common"', $view);
    assert_contains('aria-live="polite"', $view);

    assert_contains('initializeEmployeeLiveValidation', $javascript);
    assert_not_contains("'password123'", $javascript);
    assert_not_contains("'admin12345'", $javascript);
    assert_contains('confirmation.value === password.value', $javascript);
    assert_contains('event.preventDefault()', $javascript);

    assert_contains('$this->employeeValidationService->validate(', $controller);
    assert_not_contains('validateEmployeeData', $controller);
    assert_contains('$this->passwordPolicy->validate(', $service);
    assert_contains("'09123456789'", $service);
    assert_contains('type="tel"', $view);
    assert_contains('maxlength="50"', $view);
    assert_contains('09, +959, or 00959', $english['employees_module']['phone_help'] ?? '');
    assert_true(isset($english['employees_module']['required_fields_help']));
    assert_true(isset($myanmar['employees_module']['required_fields_help']));
    assert_contains('09', $myanmar['employees_module']['phone_help'] ?? '');
    assert_true(isset($english['validation']['employee_phone_invalid']));
    assert_true(isset($myanmar['validation']['employee_phone_invalid']));
});
