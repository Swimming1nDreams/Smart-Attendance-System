<?php

declare(strict_types=1);

test('workforce policy center has one page header, compact navigation, and structured review summary', function (): void {
    $view = file_get_contents(project_path('views/work_schedules/index.php'));
    $css = file_get_contents(project_path('assets/css/tailwind.css'));

    assert_true(is_string($view));
    assert_true(is_string($css));
    assert_not_contains('<section class="sapas-policy-command">', $view);
    assert_contains('max-w-7xl space-y-4', $view);
    assert_contains('class="sapas-policy-workspace-link', $view);
    assert_not_contains('<small><?= htmlspecialchars($workspaceDescriptions', $view);
    assert_contains('sapas-policy-overview-grid', $view);
    assert_contains('sapas-policy-review-schedule', $view);
    assert_contains('for ($reviewDay = 1; $reviewDay <= 7; $reviewDay++)', $view);
    assert_contains('sapas-policy-review-rules', $view);
    assert_contains('sapas-policy-review-actions', $view);
    assert_not_contains("implode('; ', \$dailyGroups)", $view);
    assert_contains('.sapas-policy-workspace-nav', $css);
    assert_contains('.sapas-policy-review-meta', $css);
});

test('workforce policy grace labels and early-departure guidance are explicit and localized', function (): void {
    $view = file_get_contents(project_path('views/work_schedules/index.php'));
    $english = require project_path('lang/en.php');
    $myanmar = require project_path('lang/my.php');

    assert_true(is_string($view));
    assert_contains("workforce_policy.ux.late_grace_period", $view);
    assert_not_contains("workforce_policy.ux.grace_period", $view);
    assert_contains("workforce_policy.early_departure_grace", $view);
    assert_contains("workforce_policy.early_departure_grace_help", $view);
    assert_same('Late Arrival Grace', $english['workforce_policy']['ux']['late_grace_period'] ?? null);
    assert_same('Early Departure Grace', $english['workforce_policy']['early_departure_grace'] ?? null);
    assert_same(
        'Employees checking out within this many minutes before the scheduled end time are not marked as early departures.',
        $english['workforce_policy']['early_departure_grace_help'] ?? null
    );
    assert_same('နောက်ကျရောက်ရှိမှု ခွင့်ပြုချိန်', $myanmar['workforce_policy']['ux']['late_grace_period'] ?? null);
    assert_true(trim((string) ($myanmar['workforce_policy']['early_departure_grace'] ?? '')) !== '');
    assert_true(trim((string) ($myanmar['workforce_policy']['early_departure_grace_help'] ?? '')) !== '');
    assert_not_contains('Early Departure Grace', $myanmar['workforce_policy']['early_departure_grace'] ?? '');
    assert_not_contains('Employees checking out', $myanmar['workforce_policy']['early_departure_grace_help'] ?? '');
});

test('workforce policy assignment forms expose readable dynamic targets', function (): void {
    $form = file_get_contents(project_path('views/work_schedules/assignment_form.php'));
    $list = file_get_contents(project_path('views/work_schedules/assignments.php'));
    $service = file_get_contents(project_path('src/Services/WorkScheduleAssignmentService.php'));

    assert_true(is_string($form));
    assert_true(is_string($list));
    assert_true(is_string($service));
    assert_contains('data-assignment-target="department"', $form);
    assert_contains('data-assignment-target="employee"', $form);
    assert_contains("\$employee['department_name']", $form);
    assert_contains("' — ' . \$department", $form);
    assert_contains('select.disabled = !isSelected;', $form);
    assert_contains('select.required = isSelected;', $form);
    assert_contains('target.hidden = !isSelected;', $form);
    assert_contains("\$selectedScope === 'department' ? 'required' : 'disabled'", $form);
    assert_contains("\$selectedScope === 'employee' ? 'required' : 'disabled'", $form);
    assert_contains("(int) (\$assignment['department_id'] ?? 0)", $form);
    assert_contains("(int) (\$assignment['employee_id'] ?? 0)", $form);
    assert_contains('workforce_policy.assignments.scope_company', $list);
    assert_contains('d.name AS department_name', $service);
    assert_not_contains("\$errors['scope_id'][] = \$this->messageFor('target_required');", $service);
});
