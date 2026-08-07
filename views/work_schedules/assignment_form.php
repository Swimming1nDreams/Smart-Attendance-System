<?php
$t = $t ?? static fn (string $key): string => $key;
$assignment = $assignment ?? [];
$errors = $errors ?? [];
$options = $options ?? ['policies' => [], 'departments' => [], 'employees' => []];
$action = $action ?? '/admin/work-schedules/assignments';
$layout = 'layouts/app';
$pageTitle = $title ?? $t('workforce_policy.assignments.create_title');
$pageDescription = $t('workforce_policy.assignments.form_description');
$pageActions = '<a href="/admin/work-schedules/assignments" class="sapas-btn sapas-btn-secondary">' . htmlspecialchars($t('workforce_policy.assignments.back'), ENT_QUOTES, 'UTF-8') . '</a>';

$fieldError = static function (string $field) use ($errors): string {
    $messages = $errors[$field] ?? [];
    return $messages === [] ? '' : '<p class="mt-1 text-sm text-red-600">' . htmlspecialchars((string) $messages[0], ENT_QUOTES, 'UTF-8') . '</p>';
};
$employeeLabel = static function (array $employee): string {
    $name = trim((string) ($employee['name'] ?? ''));
    $code = trim((string) ($employee['staff_code'] ?? ''));
    return $name . ($code !== '' ? ' (' . $code . ')' : '');
};
?>

<?php if (($errors['form'] ?? []) !== []): ?>
    <div class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
        <?= htmlspecialchars((string) $errors['form'][0], ENT_QUOTES, 'UTF-8') ?>
    </div>
<?php endif; ?>

<form method="post" action="<?= htmlspecialchars($action, ENT_QUOTES, 'UTF-8') ?>" class="sapas-form-panel">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token ?? '', ENT_QUOTES, 'UTF-8') ?>">

    <div class="grid gap-5 md:grid-cols-2">
        <div>
            <label class="block text-sm font-medium text-slate-700" for="scope-type"><?= htmlspecialchars($t('workforce_policy.assignments.scope'), ENT_QUOTES, 'UTF-8') ?></label>
            <select id="scope-type" name="scope_type" class="mt-1 sapas-form-control">
                <?php foreach (['company', 'department', 'employee'] as $scope): ?>
                    <option value="<?= $scope ?>" <?= ($assignment['scope_type'] ?? 'company') === $scope ? 'selected' : '' ?>><?= htmlspecialchars($t('workforce_policy.assignments.scope_' . $scope), ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
            </select>
            <?= $fieldError('scope_type') ?>
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-700" for="policy-id"><?= htmlspecialchars($t('workforce_policy.policy_version'), ENT_QUOTES, 'UTF-8') ?></label>
            <select id="policy-id" name="work_schedule_template_id" class="mt-1 sapas-form-control" required>
                <option value=""><?= htmlspecialchars($t('workforce_policy.assignments.choose_policy'), ENT_QUOTES, 'UTF-8') ?></option>
                <?php foreach ($options['policies'] as $policy): ?>
                    <option value="<?= (int) $policy['id'] ?>" <?= (int) ($assignment['work_schedule_template_id'] ?? 0) === (int) $policy['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $policy['name'], ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
            </select>
            <?= $fieldError('work_schedule_template_id') ?>
        </div>

        <div data-assignment-target="department">
            <label class="block text-sm font-medium text-slate-700" for="department-id"><?= htmlspecialchars($t('common.labels.department'), ENT_QUOTES, 'UTF-8') ?></label>
            <select id="department-id" name="department_id" class="mt-1 sapas-form-control">
                <option value=""><?= htmlspecialchars($t('workforce_policy.assignments.only_department_scope'), ENT_QUOTES, 'UTF-8') ?></option>
                <?php foreach ($options['departments'] as $department): ?>
                    <option value="<?= (int) $department['id'] ?>" <?= (int) ($assignment['department_id'] ?? 0) === (int) $department['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $department['name'], ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
            </select>
            <?= $fieldError('department_id') ?>
        </div>

        <div data-assignment-target="employee">
            <label class="block text-sm font-medium text-slate-700" for="employee-id"><?= htmlspecialchars($t('common.labels.employee'), ENT_QUOTES, 'UTF-8') ?></label>
            <select id="employee-id" name="employee_id" class="mt-1 sapas-form-control">
                <option value=""><?= htmlspecialchars($t('workforce_policy.assignments.only_employee_scope'), ENT_QUOTES, 'UTF-8') ?></option>
                <?php foreach ($options['employees'] as $employee): ?>
                    <option value="<?= (int) $employee['id'] ?>" <?= (int) ($assignment['employee_id'] ?? 0) === (int) $employee['id'] ? 'selected' : '' ?>><?= htmlspecialchars($employeeLabel($employee), ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
            </select>
            <?= $fieldError('employee_id') ?>
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-700" for="effective-from"><?= htmlspecialchars($t('workforce_policy.assignments.effective_from'), ENT_QUOTES, 'UTF-8') ?></label>
            <input id="effective-from" type="date" name="effective_from" class="mt-1 sapas-form-control" value="<?= htmlspecialchars((string) ($assignment['effective_from'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required>
            <?= $fieldError('effective_from') ?>
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-700" for="effective-to"><?= htmlspecialchars($t('workforce_policy.assignments.effective_to'), ENT_QUOTES, 'UTF-8') ?></label>
            <input id="effective-to" type="date" name="effective_to" class="mt-1 sapas-form-control" value="<?= htmlspecialchars((string) ($assignment['effective_to'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            <?= $fieldError('effective_to') ?>
        </div>

        <label class="flex items-center gap-3 rounded-md border border-slate-200 px-3 py-2 text-sm text-slate-700">
            <input type="checkbox" name="is_active" value="1" <?= (int) ($assignment['is_active'] ?? 1) === 1 ? 'checked' : '' ?>>
            <span><?= htmlspecialchars($t('workforce_policy.assignments.active'), ENT_QUOTES, 'UTF-8') ?></span>
        </label>

        <div class="md:col-span-2">
            <label class="block text-sm font-medium text-slate-700" for="reason"><?= htmlspecialchars($t('workforce_policy.assignments.reason'), ENT_QUOTES, 'UTF-8') ?></label>
            <textarea id="reason" name="reason" rows="3" class="mt-1 sapas-form-control"><?= htmlspecialchars((string) ($assignment['reason'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
            <p class="mt-1 text-sm text-slate-500"><?= htmlspecialchars($t('workforce_policy.assignments.reason_help'), ENT_QUOTES, 'UTF-8') ?></p>
        </div>
    </div>

    <div class="mt-6 flex items-center justify-end gap-3">
        <a href="/admin/work-schedules/assignments" class="sapas-btn sapas-btn-secondary"><?= htmlspecialchars($t('common.actions.cancel'), ENT_QUOTES, 'UTF-8') ?></a>
        <button type="submit" class="sapas-btn sapas-btn-primary"><?= htmlspecialchars($t('common.actions.save'), ENT_QUOTES, 'UTF-8') ?></button>
    </div>
</form>

<script>
(() => {
    const scope = document.getElementById('scope-type');
    const targets = document.querySelectorAll('[data-assignment-target]');
    const syncTargets = () => {
        const selected = scope ? scope.value : 'company';
        targets.forEach((target) => {
            target.hidden = target.dataset.assignmentTarget !== selected;
        });
    };

    if (scope) {
        scope.addEventListener('change', syncTargets);
        syncTargets();
    }
})();
</script>
