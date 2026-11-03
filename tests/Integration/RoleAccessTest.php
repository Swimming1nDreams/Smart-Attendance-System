<?php

declare(strict_types=1);

test('role contract delegates only scoped attendance and leave mutations to department heads', function (): void {
    $employeeController = project_path('src/Controllers/EmployeeController.php');
    $attendanceController = project_path('src/Controllers/AttendanceController.php');
    $leaveController = project_path('src/Controllers/LeaveController.php');

    foreach (['create', 'store', 'show', 'edit', 'update', 'deactivate'] as $method) {
        assert_method_contains($employeeController, $method, 'ensureAdmin()');
    }

    foreach (['create', 'store', 'edit', 'update'] as $method) {
        assert_method_contains($attendanceController, $method, 'ensureAdminOrDepartmentHead()');
    }
    assert_method_contains($attendanceController, 'delete', 'ensureAdmin()');

    foreach (['adminShow', 'adminApprove', 'adminReject', 'adminCancel'] as $method) {
        assert_method_contains($leaveController, $method, 'ensureAdminOrDepartmentHead()');
    }
    assert_method_contains($leaveController, 'canManageLeaveRequester', 'canManageUserInScope');
});

test('role contract restricts department-head team reads to the authenticated department', function (): void {
    $masterDataController = project_path('src/Controllers/MasterDataController.php');

    assert_method_contains($masterDataController, 'ensureAdminOrDepartmentHead', "\$role !== 'admin' && \$role !== 'department_head'");
    assert_method_order($masterDataController, 'resolveScopeFilters', [
        "\$role === 'department_head'",
        "\$this->sessionManager->getUserDepartmentId()",
        "\$reqDeptId !== \$filters['department_id']",
        'validation.unauthorized_department',
    ]);
    assert_method_contains($masterDataController, 'normalizeEmployeeForDepartment', "(int) (\$employee['department_id'] ?? 0) !== \$departmentId");
});

test('department heads retain self-service while delegated mutations stay department scoped', function (): void {
    $attendanceController = project_path('src/Controllers/AttendanceController.php');
    $leaveController = project_path('src/Controllers/LeaveController.php');
    $workPolicyController = project_path('src/Controllers/MyWorkPolicyController.php');

    foreach (['clockIn', 'clockOut'] as $method) {
        assert_method_contains($attendanceController, $method, "requireAnyRole(['employee', 'department_head'])");
    }

    foreach (['index', 'create', 'show', 'cancel', 'store'] as $method) {
        assert_method_contains($leaveController, $method, "requireAnyRole(['employee', 'department_head'])");
    }

    assert_method_contains($workPolicyController, 'index', "requireAnyRole(['employee', 'department_head'])");
    assert_method_contains($attendanceController, 'store', 'denyOutOfScopeManagement()');
    assert_method_contains($attendanceController, 'update', 'denyOutOfScopeManagement()');
});

test('authenticated role validation recognizes exactly the current three roles', function (): void {
    $securityService = (string) file_get_contents(project_path('src/Services/SessionSecurityService.php'));

    assert_contains("AUTHENTICATED_ROLES = ['admin', 'department_head', 'employee']", $securityService);
    assert_contains("\$databaseRole === 'department_head'", $securityService);
    assert_contains('$databaseDepartmentId <= 0', $securityService);
});
