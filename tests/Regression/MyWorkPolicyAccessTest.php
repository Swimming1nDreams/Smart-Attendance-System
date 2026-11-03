<?php

declare(strict_types=1);

/** @return array{exit_code:int,stdout:string,stderr:string} */
function runMyWorkPolicyEmployeeGuardProbe(?string $role): array
{
    if (!function_exists('proc_open')) {
        skip_test('proc_open is required for isolated redirect/exit authorization probes.');
    }

    $authentication = $role === null
        ? ''
        : '$session->setAuthenticatedUser(77, \'Policy Probe\', \'policy-probe@example.test\', ' . var_export($role, true) . ', 1);';
    $code = 'require ' . var_export(project_path('tests/bootstrap.php'), true) . ';'
        . 'reset_test_session();'
        . '$session = new \\App\\Core\\SessionManager();'
        . $authentication
        . '$session->requireAnyRole([\'employee\', \'department_head\']);'
        . 'echo \'MY_WORK_POLICY_ALLOWED\';';

    $process = proc_open(
        [PHP_BINARY, '-d', 'xdebug.mode=off', '-r', $code],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        project_path(),
        null,
        ['bypass_shell' => true],
    );
    assert_true(is_resource($process));

    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    return ['exit_code' => $exitCode, 'stdout' => $stdout, 'stderr' => $stderr];
}

test('my work policy route is self-service and session scoped', function (): void {
    $routes = (string) file_get_contents(project_path('public/index.php'));
    $controller = project_path('src/Controllers/MyWorkPolicyController.php');
    $controllerSource = (string) file_get_contents($controller);

    assert_contains("addRoute('GET', '/my-work-policy'", $routes);
    assert_not_contains("addRoute('POST', '/my-work-policy'", $routes);
    assert_contains('$myWorkPolicyController->index();', $routes);
    assert_method_order($controller, 'index', [
        "requireAnyRole(['employee', 'department_head'])",
        'getUserId()',
        'findById($employeeId)',
        'workScheduleResolver->resolve($employeeId, $businessDate)',
        "renderView('work_policy/my_work_policy'",
    ]);
    assert_not_contains('employee_id', $controllerSource);
    assert_not_contains('getBodyParams', $controllerSource);
    assert_not_contains('->query(', $controllerSource);
    assert_not_contains('->input(', $controllerSource);
});

test('shared self-service guard blocks unauthenticated and admin sessions for my work policy', function (): void {
    foreach ([null, 'admin'] as $role) {
        $probe = runMyWorkPolicyEmployeeGuardProbe($role);
        assert_same(0, $probe['exit_code']);
        assert_same('', $probe['stdout']);
    }

    $employee = runMyWorkPolicyEmployeeGuardProbe('employee');
    assert_same(0, $employee['exit_code']);
    assert_same('MY_WORK_POLICY_ALLOWED', $employee['stdout']);

    $departmentHead = runMyWorkPolicyEmployeeGuardProbe('department_head');
    assert_same(0, $departmentHead['exit_code']);
    assert_same('MY_WORK_POLICY_ALLOWED', $departmentHead['stdout']);
});

test('my work policy navigation and page remain employee-facing and read-only', function (): void {
    $sidebar = (string) file_get_contents(project_path('views/partials/sidebar.php'));
    $dashboard = (string) file_get_contents(project_path('views/dashboard/employee.php'));
    $view = (string) file_get_contents(project_path('views/work_policy/my_work_policy.php'));

    assert_same(2, substr_count($sidebar, "'href' => '/my-work-policy'"));
    assert_contains("['label' => \$t('nav.my_work_policy'), 'href' => '/my-work-policy', 'match' => '/my-work-policy', 'icon' => 'timer']", $sidebar);
    assert_contains('href="/my-work-policy"', $dashboard);
    assert_contains("\$t('work_policy.view_policy')", $dashboard);
    assert_not_contains('<form', $view);
    assert_not_contains('/admin/work-schedules', $view);
    assert_not_contains('assignment_id', $view);
    assert_not_contains('policy_version_id', $view);
    assert_not_contains('template_id', $view);
    assert_not_contains('scope_id', $view);
    assert_not_contains('scope_type', $view);
    assert_contains("\$t('work_policy.today_schedule')", $view);
    assert_contains("\$t('work_policy.weekly_schedule')", $view);
    assert_contains("\$t('work_policy.attendance_rules')", $view);
    assert_contains("\$t('work_policy.overtime_rules')", $view);
});

test('my work policy English and Myanmar localization keys are complete', function (): void {
    $english = require project_path('lang/en.php');
    $myanmar = require project_path('lang/my.php');
    $keys = [
        'title', 'description', 'current_policy', 'today_schedule', 'weekly_schedule',
        'attendance_rules', 'overtime_rules', 'assignment_source', 'working_day',
        'non_working_day', 'grace_period', 'expected_work', 'break', 'overtime_eligible',
        'approval', 'minimum_overtime', 'no_policy_message', 'view_policy',
    ];

    assert_same('My Work Policy', $english['nav']['my_work_policy'] ?? null);
    assert_true(isset($myanmar['nav']['my_work_policy']));
    foreach ($keys as $key) {
        assert_true(isset($english['work_policy'][$key]), 'Missing English work-policy key: ' . $key);
        assert_true(isset($myanmar['work_policy'][$key]), 'Missing Myanmar work-policy key: ' . $key);
    }
    foreach (['employee', 'department', 'company', 'compatibility'] as $scope) {
        assert_true(isset($english['work_policy']['source'][$scope]));
        assert_true(isset($myanmar['work_policy']['source'][$scope]));
    }
});
