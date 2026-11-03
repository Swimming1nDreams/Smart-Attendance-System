<?php

declare(strict_types=1);

test('workforce policy center routes are admin-only post-protected and integrated into attendance', function (): void {
    $routes = file_get_contents(project_path('public/index.php'));
    $companyController = file_get_contents(project_path('src/Controllers/CompanyProfileController.php'));
    $scheduleController = file_get_contents(project_path('src/Controllers/WorkScheduleController.php'));
    $attendancePolicy = file_get_contents(project_path('src/Services/AttendancePolicyService.php'));
    $attendanceOperations = file_get_contents(project_path('src/Services/AttendanceOperationService.php'));

    assert_contains("addRoute('GET', '/admin/company-profile'", (string) $routes);
    assert_contains("addRoute('POST', '/admin/company-profile/update'", (string) $routes);
    assert_contains("addRoute('GET', '/admin/work-schedules'", (string) $routes);
    assert_contains("addRoute('POST', '/admin/work-schedules/toggle-status'", (string) $routes);
    foreach ([
        "addRoute('GET', '/admin/work-schedules/assignments'",
        "addRoute('POST', '/admin/work-schedules/assignments'",
        "addRoute('POST', '/admin/work-schedules/assignments/end'",
        "addRoute('POST', '/admin/work-schedules/assignments/remove-employee-override'",
        "addRoute('GET', '/admin/overtime'",
        "addRoute('POST', '/admin/overtime/approve'",
        "addRoute('POST', '/admin/overtime/reject'",
        "addRoute('POST', '/admin/overtime/adjust'",
        "addRoute('POST', '/admin/overtime/reset-pending'",
    ] as $routeNeedle) {
        assert_contains($routeNeedle, (string) $routes);
    }
    assert_same(3, substr_count((string) $companyController, '$this->ensureAdmin();'));
    assert_true(substr_count((string) $scheduleController, '$this->ensureAdmin();') >= 16);
    assert_same(1, substr_count((string) $companyController, '$this->validateCsrfToken($request);'));
    assert_true(substr_count((string) $scheduleController, '$this->validateCsrfToken($request);') >= 15);

    assert_contains('attendance_state', (string) $attendancePolicy);
    assert_contains('WorkScheduleResolverService', (string) $attendanceOperations);
    assert_contains('AttendanceCalculationService', (string) $attendanceOperations);
});

test('workforce policy center UI navigation localization and docs are present', function (): void {
    $sidebar = file_get_contents(project_path('views/partials/sidebar.php'));
    $policyCenter = file_get_contents(project_path('views/work_schedules/index.php'));
    $workForm = file_get_contents(project_path('views/work_schedules/form.php'));
    $companyProfile = file_get_contents(project_path('views/company_profile/show.php'));
    $scheduleController = file_get_contents(project_path('src/Controllers/WorkScheduleController.php'));
    $spec = file_get_contents(project_path('docs/COMPANY_SCHEDULE_ATTENDANCE_POLICY_SPEC.md'));
    $readme = file_get_contents(project_path('README.md'));
    $map = file_get_contents(project_path('DEVELOPMENT_MAP.md'));
    $english = require project_path('lang/en.php');
    $myanmar = require project_path('lang/my.php');
    $editableJavascript = file_get_contents(project_path('assets/js/main.js'));
    $runtimeJavascript = file_get_contents(project_path('public/assets/js/main.js'));

    assert_contains("nav.workforce_policy_center", (string) $sidebar);
    assert_not_contains("nav.company_profile", (string) $sidebar);
    assert_contains("section=overview", (string) $policyCenter);
    assert_contains("section=work-rules", (string) $policyCenter);
    assert_contains('/admin/work-schedules/assignments', (string) $policyCenter);
    assert_contains('/admin/overtime', (string) $policyCenter);
    assert_contains("section=history", (string) $policyCenter);
    assert_contains("section=organization", (string) $policyCenter);
    assert_contains('workforce_policy.ux.create_new_work_policy', (string) $policyCenter);
    assert_contains('workforce_policy.ux.change_work_rules', (string) $policyCenter);
    assert_contains('workforce_policy.ux.work_rules_guide', (string) $policyCenter);
    assert_contains('workforce_policy.ux.draft_flow_help', (string) $policyCenter);
    assert_contains('workforce_policy.ux.locked_flow_help', (string) $policyCenter);
    assert_contains('sapas-policy-workspace-nav', (string) $policyCenter);
    assert_contains('sapas-policy-flow-panel', (string) $policyCenter);
    assert_contains('sapas-policy-step-card', (string) $policyCenter);
    assert_contains('sapas-policy-state-panel', (string) $policyCenter);
    assert_contains('sapas-policy-day-card', (string) $policyCenter);
    assert_contains('sapas-policy-switch-control', (string) $policyCenter);
    assert_contains('sapas-policy-toggle-card', (string) $policyCenter);
    assert_not_contains('&mdash;', (string) $policyCenter);
    assert_contains("\$dash = '—';", (string) $policyCenter);
    assert_contains('data-working-toggle-label', (string) $policyCenter);
    assert_contains("workforce_policy.ux.non_working", (string) $policyCenter);
    assert_contains('source_mode=existing&source_policy_id=', (string) $policyCenter);
    assert_contains('workforce_policy.ux.next_day', (string) $policyCenter);
    assert_contains('workforce_policy.ux.assignment_note', (string) $policyCenter);
    assert_contains('array_merge($overrides,', (string) $scheduleController);
    assert_not_contains('workforce_policy.clone_as_new', (string) $policyCenter);
    assert_not_contains('section=versions', (string) $policyCenter);
    assert_contains('data-work-schedule-row', (string) $workForm);
    assert_contains('data-working-toggle-label', (string) $workForm);
    assert_contains("\$identityValue('company_name'", (string) $companyProfile);
    assert_contains("\$identityValue('short_name'", (string) $companyProfile);
    assert_contains("\$organizationValue(\$key", (string) $policyCenter);
    assert_contains('days[<?= $day ?>][day_present]', (string) $workForm);
    assert_contains('Workforce Policy Center', (string) $spec);
    assert_contains('Workforce Policy Center', (string) $readme);
    assert_contains('Seeded default presentation values', (string) $readme);
    assert_contains('- Company: `Office Attendance and Analytic System`', (string) $readme);
    assert_contains('- Short name: `OAAS`', (string) $readme);
    assert_contains('Workforce Policy Center', (string) $map);
    assert_same('Workforce Policy Center', $english['nav']['workforce_policy_center'] ?? null);
    assert_true(isset($myanmar['nav']['workforce_policy_center']));
    assert_true(isset($myanmar['nav']['company_profile']));
    assert_same('Working', $english['workforce_policy']['ux']['working'] ?? null);
    assert_same('Non-working', $english['workforce_policy']['ux']['non_working'] ?? null);
    assert_true(isset($myanmar['workforce_policy']['ux']['non_working']));
    assert_contains('workingToggleLabel.textContent = toggle.checked ? onLabel : offLabel;', (string) $editableJavascript);
    assert_same($editableJavascript, $runtimeJavascript, 'Editable and served JavaScript must remain identical.');
    assert_true(isset($english['audit_logs']['actions']['work_schedule']['created']));
    assert_true(isset($myanmar['audit_logs']['actions']['work_schedule']['created']));
    assert_true(isset($english['workforce_policy']['assignments']['priority_help']));
    assert_true(isset($myanmar['workforce_policy']['assignments']['priority_help']));
    foreach ([
        'admin_workspace',
        'advanced_help',
        'workspace_overview_help',
        'workspace_work_rules_help',
        'workspace_assignments_help',
        'workspace_overtime_help',
        'work_rules_guide',
        'edit_day_settings',
        'draft_flow_help',
        'locked_flow_help',
        'basic_setup_help',
        'weekly_schedule_help',
        'attendance_rules_help',
        'overtime_rules_help',
        'schedule_help',
    ] as $uxKey) {
        assert_true(isset($english['workforce_policy']['ux'][$uxKey]), 'Missing English UX key: ' . $uxKey);
        assert_true(isset($myanmar['workforce_policy']['ux'][$uxKey]), 'Missing Myanmar UX key: ' . $uxKey);
    }

    $css = file_get_contents(project_path('assets/css/tailwind.css'));
    assert_contains('.sapas-policy-command', (string) $css);
    assert_contains('.sapas-policy-workspace-link', (string) $css);
    assert_contains('.sapas-policy-step-card', (string) $css);
    assert_contains('.sapas-policy-day-card', (string) $css);
    assert_contains('.sapas-policy-toggle-card', (string) $css);
});
