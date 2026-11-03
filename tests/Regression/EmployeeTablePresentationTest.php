<?php

declare(strict_types=1);

test('employee list uses the compact data-table presentation', function (): void {
    $view = file_get_contents(project_path('views/employees/index.php'));
    $css = file_get_contents(project_path('assets/css/tailwind.css'));

    assert_true(is_string($view), 'Employee list view should be readable.');
    assert_true(is_string($css), 'Tailwind source should be readable.');

    assert_contains('sapas-employee-table', $view);
    assert_contains('sapas-data-table-scroll', $view);
    assert_contains('sapas-data-table-summary', $view);
    assert_contains('sapas-person-cell', $view);
    assert_contains('sapas-employee-actions', $view);
    assert_contains('.sapas-employee-table .sapas-col-employee-actions', $css);
    assert_contains('.sapas-employee-actions', $css);
    assert_contains('.sapas-employee-table .sapas-person-name', $css);
    assert_contains('.sapas-employee-table .sapas-person-email', $css);
    assert_contains('.sapas-employee-table .sapas-table-text-cell', $css);
    assert_contains('width: 18rem;', $css);
    assert_contains('width: 12rem;', $css);
    assert_contains('width: 13rem;', $css);
    assert_contains('width: 15rem;', $css);
});

test('employee list query handling is normalized before rendering', function (): void {
    $controller = file_get_contents(project_path('src/Controllers/EmployeeController.php'));
    $view = file_get_contents(project_path('views/employees/index.php'));
    $english = file_get_contents(project_path('lang/en.php'));

    assert_true(is_string($controller), 'Employee controller should be readable.');
    assert_true(is_string($view), 'Employee list view should be readable.');
    assert_true(is_string($english), 'English translations should be readable.');

    assert_contains("in_array(\$status, ['active', 'inactive'], true)", $controller);
    assert_contains('$requestedPage = max(1, (int) $request->query(\'page\', 1));', $controller);
    assert_contains('$total = $this->employeeModel->countAll($search, $departmentId, $positionId, $status, $role);', $controller);
    assert_contains('$page = min($requestedPage, $pages);', $controller);
    assert_contains('ORDER BY u.created_at DESC, u.id DESC LIMIT :limit OFFSET :offset', file_get_contents(project_path('src/Models/Employee.php')));
    assert_true(
        strpos($controller, '$total = $this->employeeModel->countAll($search, $departmentId, $positionId, $status, $role);')
            < strpos($controller, '$employees = $this->employeeModel->findAll($search, $departmentId, $positionId, $status, $page, $perPage, $role);'),
        'Employee list should clamp excessive page numbers before fetching rows.'
    );

    assert_contains("employees_module.all_statuses", $view);
    assert_contains("employees_module.all_roles", $view);
    assert_contains("u.role = :role", file_get_contents(project_path('src/Models/Employee.php')));
    assert_not_contains("leave_module.all_statuses", $view);
    assert_contains('Name, email, or staff code', $english);
});
