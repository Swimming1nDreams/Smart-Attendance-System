<?php
$t = $t ?? static fn (string $key): string => $key;
if (!isset($title)) {
    $title = '';
}
if (!isset($action)) {
    $action = '';
}
if (!isset($errors)) {
    $errors = [];
}
if (!isset($employee)) {
    $employee = ['name' => '', 'email' => '', 'password' => '', 'role' => 'employee', 'department_id' => '', 'position_id' => '', 'status' => 'active', 'staff_code' => '', 'employment_type' => 'full-time', 'join_date' => '', 'expected_daily_work_hours' => '8.00', 'overtime_eligible' => '1', 'send_verification_code' => '1', 'phone' => '', 'address' => ''];
}
if (!isset($departments)) {
    $departments = [];
}
if (!isset($positions)) {
    $positions = [];
}

$isEdit = isset($employee['id']) && (int) $employee['id'] > 0;
$layout = 'layouts/app';
$pageTitle = $title !== '' ? $title : ($isEdit ? $t('employees_module.edit_title') : $t('employees_module.add_title'));
$pageDescription = $isEdit
    ? $t('employees_module.form_edit_description')
    : $t('employees_module.form_create_description');
$pageActions = '<a href="/employees" class="sapas-btn sapas-btn-secondary">' . htmlspecialchars($t('employees_module.back_to_employees'), ENT_QUOTES, 'UTF-8') . '</a>';

$submitLabel = $isEdit ? $t('employees_module.save_changes') : $t('employees_module.create_employee');
$cancelHref = $isEdit && isset($employee['id'])
    ? '/employees/show?id=' . (int) $employee['id']
    : '/employees';
$passwordHelp = $isEdit
    ? $t('employees_module.password_help_edit')
    : $t('employees_module.password_help_create');
$departmentRequired = ($employee['role'] ?? 'employee') === 'department_head';
$isOwnAdministratorAccount = $isEdit
    && (int) ($employee['id'] ?? 0) === (int) ($userId ?? 0)
    && ($employee['role'] ?? '') === 'admin';
$requiredText = $t('common.required');
$requiredIndicator = '<span class="sapas-required-indicator">' . htmlspecialchars($requiredText, ENT_QUOTES, 'UTF-8') . '</span>';
$renderIcon = static function (string $iconName, string $iconClass = 'sapas-icon'): void {
    require __DIR__ . '/../partials/icon.php';
};
?>
<?php if (!empty($errors)): ?>
    <div class="sapas-alert-error sapas-form-error-summary" role="alert">
        <div class="sapas-form-error-heading">
            <span class="sapas-form-error-icon"><?= $renderIcon('warning', 'sapas-icon') ?></span>
            <div>
                <p class="text-base font-semibold text-rose-800"><?= htmlspecialchars($t('common.form_review'), ENT_QUOTES, 'UTF-8') ?></p>
                <p class="mt-1 text-sm text-rose-700"><?= htmlspecialchars($t('employees_module.form_error_help'), ENT_QUOTES, 'UTF-8') ?></p>
            </div>
        </div>
        <ul class="mt-4 list-disc list-inside text-sm text-rose-700">
            <?php foreach ($errors as $error): ?>
                <li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="post" action="<?= htmlspecialchars($action, ENT_QUOTES, 'UTF-8') ?>" class="sapas-employee-form space-y-4" data-employee-form data-password-required="<?= $isEdit ? '0' : '1' ?>" data-email-invalid-message="<?= htmlspecialchars($t('validation.employee_email_invalid'), ENT_QUOTES, 'UTF-8') ?>" data-password-whitespace-message="<?= htmlspecialchars($t('validation.password_whitespace_only'), ENT_QUOTES, 'UTF-8') ?>" data-password-confirmation-message="<?= htmlspecialchars($t('validation.password_confirmation_mismatch'), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token ?? '', ENT_QUOTES, 'UTF-8') ?>">
    <?php if ($isEdit): ?>
        <input type="hidden" name="expected_security_role" value="<?= htmlspecialchars((string) ($employee['role'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="expected_security_status" value="<?= htmlspecialchars((string) ($employee['status'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="expected_security_session_version" value="<?= htmlspecialchars((string) ($employee['session_version'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
    <?php endif; ?>

    <div class="sapas-form-required-note">
        <span class="sapas-form-required-note-icon"><?= $renderIcon('check', 'sapas-icon') ?></span>
        <p><?= htmlspecialchars($t('employees_module.required_fields_help'), ENT_QUOTES, 'UTF-8') ?></p>
    </div>

    <?php if ($isEdit): ?>
        <div class="sapas-alert-warning" role="note">
            <?= htmlspecialchars($t('employees_module.security_change_warning'), ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <div class="sapas-form-section">
        <div class="sapas-form-section-header">
            <h2><?= htmlspecialchars($t('employees_module.account_section'), ENT_QUOTES, 'UTF-8') ?></h2>
            <p><?= htmlspecialchars($t('employees_module.account_help'), ENT_QUOTES, 'UTF-8') ?></p>
        </div>

        <div class="sapas-form-grid">
            <div>
                <label for="name" class="sapas-form-label"><?= htmlspecialchars($t('common.labels.name'), ENT_QUOTES, 'UTF-8') ?><?= $requiredIndicator ?></label>
                <input type="text" id="name" name="name" value="<?= htmlspecialchars($employee['name'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required maxlength="255" autocomplete="name" class="mt-2 sapas-form-control" aria-describedby="name-help name-live-feedback">
                <p id="name-help" class="sapas-field-help"><?= htmlspecialchars($t('employees_module.name_help'), ENT_QUOTES, 'UTF-8') ?></p>
                <p id="name-live-feedback" class="sapas-field-feedback text-rose-600 text-sm mt-1 hidden" aria-live="polite">Letters, spaces, hyphens, periods, and apostrophes are allowed. Numbers are not allowed.</p>
            </div>
            <div>
                <label class="sapas-form-label"><?= htmlspecialchars($t('common.labels.email'), ENT_QUOTES, 'UTF-8') ?><?= $isEdit ? '' : $requiredIndicator ?></label>
                <?php if ($isEdit): ?>
                    <div class="mt-2 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3" aria-describedby="email-identity-help">
                        <p class="break-words font-medium text-slate-900"><?= htmlspecialchars((string) ($employee['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
                        <p id="email-identity-help" class="mt-1 text-sm text-slate-600"><?= htmlspecialchars($t('employees_module.email_identity_help'), ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                <?php else: ?>
                    <input type="email" id="email" name="email" value="<?= htmlspecialchars($employee['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required maxlength="255" autocomplete="email" class="mt-2 sapas-form-control" data-live-email aria-describedby="email-help email-live-feedback">
                    <p id="email-help" class="sapas-field-help"><?= htmlspecialchars($t('employees_module.email_help'), ENT_QUOTES, 'UTF-8') ?></p>
                    <p id="email-live-feedback" class="sapas-field-feedback" data-live-email-feedback aria-live="polite"></p>
                <?php endif; ?>
            </div>
            <div>
                <label for="password" class="sapas-form-label"><?= htmlspecialchars($t('common.labels.password'), ENT_QUOTES, 'UTF-8') ?><?= $isEdit ? '' : $requiredIndicator ?></label>
                <div class="sapas-input-shell sapas-password-shell relative mt-2">
                    <input type="password" id="password" name="password" autocomplete="new-password" <?= $isEdit ? '' : 'required' ?> class="sapas-form-control" data-live-password aria-describedby="password-live-feedback password-live-rules">
                    <button type="button" class="sapas-password-toggle" data-password-toggle data-show-label="<?= htmlspecialchars($t('auth.show_password'), ENT_QUOTES, 'UTF-8') ?>" data-hide-label="<?= htmlspecialchars($t('auth.hide_password'), ENT_QUOTES, 'UTF-8') ?>" aria-controls="password" aria-label="<?= htmlspecialchars($t('auth.show_password'), ENT_QUOTES, 'UTF-8') ?>" aria-pressed="false">
                        <span data-password-show-icon><?= $renderIcon('eye', 'sapas-icon') ?></span>
                        <span data-password-hide-icon hidden><?= $renderIcon('eye-off', 'sapas-icon') ?></span>
                    </button>
                </div>
                <p class="sapas-field-help"><?= htmlspecialchars($passwordHelp . ' ' . $t('employees_module.password_policy_help'), ENT_QUOTES, 'UTF-8') ?></p>
                <p id="password-live-feedback" class="sapas-field-feedback" data-live-password-feedback aria-live="polite"></p>
                <ul id="password-live-rules" class="sapas-live-rule-list" data-live-password-rules>
                    <li data-password-rule="length"><?= htmlspecialchars($t('validation.password_min_length'), ENT_QUOTES, 'UTF-8') ?></li>
                    <li data-password-rule="letter"><?= htmlspecialchars($t('validation.password_letter_required'), ENT_QUOTES, 'UTF-8') ?></li>
                    <li data-password-rule="number"><?= htmlspecialchars($t('validation.password_number_required'), ENT_QUOTES, 'UTF-8') ?></li>
                </ul>
            </div>
            <div>
                <label for="password_confirmation" class="sapas-form-label"><?= htmlspecialchars($t('employees_module.password_confirmation'), ENT_QUOTES, 'UTF-8') ?><?= $isEdit ? '' : $requiredIndicator ?></label>
                <div class="sapas-input-shell sapas-password-shell relative mt-2">
                    <input type="password" id="password_confirmation" name="password_confirmation" autocomplete="new-password" <?= $isEdit ? '' : 'required' ?> class="sapas-form-control" data-live-password-confirmation aria-describedby="password-confirmation-live-feedback">
                    <button type="button" class="sapas-password-toggle" data-password-toggle data-show-label="<?= htmlspecialchars($t('employees_module.show_confirm_password'), ENT_QUOTES, 'UTF-8') ?>" data-hide-label="<?= htmlspecialchars($t('employees_module.hide_confirm_password'), ENT_QUOTES, 'UTF-8') ?>" aria-controls="password_confirmation" aria-label="<?= htmlspecialchars($t('employees_module.show_confirm_password'), ENT_QUOTES, 'UTF-8') ?>" aria-pressed="false">
                        <span data-password-show-icon><?= $renderIcon('eye', 'sapas-icon') ?></span>
                        <span data-password-hide-icon hidden><?= $renderIcon('eye-off', 'sapas-icon') ?></span>
                    </button>
                </div>
                <p class="sapas-field-help"><?= htmlspecialchars($t('employees_module.password_confirmation_help'), ENT_QUOTES, 'UTF-8') ?></p>
                <p id="password-confirmation-live-feedback" class="sapas-field-feedback" data-live-password-confirmation-feedback aria-live="polite"></p>
            </div>
            <div>
                <label for="role" class="sapas-form-label"><?= htmlspecialchars($t('common.labels.role'), ENT_QUOTES, 'UTF-8') ?><?= $requiredIndicator ?></label>
                <select id="role" name="role" required class="mt-2 sapas-form-control">
                    <option value="employee" <?= ($employee['role'] ?? 'employee') === 'employee' ? 'selected' : '' ?> <?= $isOwnAdministratorAccount ? 'disabled' : '' ?>><?= htmlspecialchars($t('roles.employee'), ENT_QUOTES, 'UTF-8') ?></option>
                    <option value="department_head" <?= ($employee['role'] ?? 'employee') === 'department_head' ? 'selected' : '' ?> <?= $isOwnAdministratorAccount ? 'disabled' : '' ?>><?= htmlspecialchars($t('roles.department_head') ?? 'Department Head', ENT_QUOTES, 'UTF-8') ?></option>
                    <option value="admin" <?= ($employee['role'] ?? 'employee') === 'admin' ? 'selected' : '' ?>><?= htmlspecialchars($t('roles.admin'), ENT_QUOTES, 'UTF-8') ?></option>
                </select>
                <p class="sapas-field-help"><?= htmlspecialchars($t('employees_module.role_help'), ENT_QUOTES, 'UTF-8') ?></p>
                <?php if ($isOwnAdministratorAccount): ?>
                    <p class="mt-2 text-sm font-medium text-amber-700"><?= htmlspecialchars($t('employees_module.self_role_protection_help'), ENT_QUOTES, 'UTF-8') ?></p>
                <?php endif; ?>
            </div>
            <div>
                <label for="status" class="sapas-form-label"><?= htmlspecialchars($t('common.labels.status'), ENT_QUOTES, 'UTF-8') ?><?= $requiredIndicator ?></label>
                <select id="status" name="status" required class="mt-2 sapas-form-control">
                    <option value="active" <?= ($employee['status'] ?? 'active') === 'active' ? 'selected' : '' ?>><?= htmlspecialchars($t('statuses.account.active'), ENT_QUOTES, 'UTF-8') ?></option>
                    <option value="inactive" <?= ($employee['status'] ?? 'active') === 'inactive' ? 'selected' : '' ?> <?= $isOwnAdministratorAccount ? 'disabled' : '' ?>><?= htmlspecialchars($t('statuses.account.inactive'), ENT_QUOTES, 'UTF-8') ?></option>
                </select>
                <p class="sapas-field-help"><?= htmlspecialchars($t('employees_module.status_help'), ENT_QUOTES, 'UTF-8') ?></p>
                <?php if ($isOwnAdministratorAccount): ?>
                    <p class="mt-2 text-sm font-medium text-amber-700"><?= htmlspecialchars($t('employees_module.self_deactivation_protection_help'), ENT_QUOTES, 'UTF-8') ?></p>
                <?php endif; ?>
            </div>
            <?php if (!$isEdit): ?>
                <div class="md:col-span-2">
                    <input type="hidden" name="send_verification_code" value="0">
                    <label for="send_verification_code" class="sapas-toggle-row">
                        <input type="checkbox" id="send_verification_code" name="send_verification_code" value="1" <?= ($employee['send_verification_code'] ?? '1') === '1' ? 'checked' : '' ?>>
                        <span>
                            <strong><?= htmlspecialchars($t('employees_module.send_verification_code'), ENT_QUOTES, 'UTF-8') ?></strong>
                            <small><?= htmlspecialchars($t('employees_module.send_verification_code_help'), ENT_QUOTES, 'UTF-8') ?></small>
                        </span>
                    </label>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="sapas-form-section">
        <div class="sapas-form-section-header">
            <h2><?= htmlspecialchars($t('employees_module.organization_section'), ENT_QUOTES, 'UTF-8') ?></h2>
            <p><?= htmlspecialchars($t('employees_module.organization_help'), ENT_QUOTES, 'UTF-8') ?></p>
        </div>

        <div class="sapas-form-grid">
            <div>
                <label for="department_id" class="sapas-form-label"><?= htmlspecialchars($t('common.labels.department'), ENT_QUOTES, 'UTF-8') ?> <span data-department-required-indicator class="sapas-required-indicator <?= $departmentRequired ? '' : 'hidden' ?>"><?= htmlspecialchars($requiredText, ENT_QUOTES, 'UTF-8') ?></span></label>
                <select id="department_id" name="department_id" class="mt-2 sapas-form-control" <?= $departmentRequired ? 'required aria-required="true"' : '' ?> data-department-select>
                    <option value=""><?= htmlspecialchars($t('common.none'), ENT_QUOTES, 'UTF-8') ?></option>
                    <?php foreach ($departments as $department): ?>
                        <option value="<?= (int) $department['id'] ?>" <?= ((int) ($employee['department_id'] ?? 0) === (int) $department['id']) ? 'selected' : '' ?>><?= htmlspecialchars($department['name'], ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="sapas-field-help" data-department-help><?= htmlspecialchars($departmentRequired ? $t('employees_module.department_head_help') : $t('employees_module.department_help'), ENT_QUOTES, 'UTF-8') ?></p>
            </div>
            <div>
                <label for="position_id" class="sapas-form-label"><?= htmlspecialchars($t('common.labels.position'), ENT_QUOTES, 'UTF-8') ?></label>
                <select id="position_id" name="position_id" class="mt-2 sapas-form-control">
                    <option value=""><?= htmlspecialchars($t('common.none'), ENT_QUOTES, 'UTF-8') ?></option>
                    <?php foreach ($positions as $position): ?>
                        <option value="<?= (int) $position['id'] ?>" <?= ((int) ($employee['position_id'] ?? 0) === (int) $position['id']) ? 'selected' : '' ?>><?= htmlspecialchars($position['name'], ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="sapas-field-help"><?= htmlspecialchars($t('employees_module.position_help'), ENT_QUOTES, 'UTF-8') ?></p>
            </div>
            <div>
                <label for="staff_code" class="sapas-form-label"><?= htmlspecialchars($t('employees_module.staff_code'), ENT_QUOTES, 'UTF-8') ?><?= $requiredIndicator ?></label>
                <input type="text" id="staff_code" name="staff_code" value="<?= htmlspecialchars((string) ($employee['staff_code'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" inputmode="numeric" pattern="[0-9]{6}" minlength="6" maxlength="6" autocomplete="off" required class="mt-2 sapas-form-control">
                <p class="sapas-field-help"><?= htmlspecialchars($t('employees_module.staff_code_help'), ENT_QUOTES, 'UTF-8') ?></p>
            </div>
            <div>
                <label for="employment_type" class="sapas-form-label"><?= htmlspecialchars($t('employees_module.employment_type'), ENT_QUOTES, 'UTF-8') ?><?= $requiredIndicator ?></label>
                <select id="employment_type" name="employment_type" required class="mt-2 sapas-form-control">
                    <?php foreach (['full-time', 'part-time', 'intern', 'contract'] as $employmentType): ?>
                        <option value="<?= $employmentType ?>" <?= ($employee['employment_type'] ?? 'full-time') === $employmentType ? 'selected' : '' ?>><?= htmlspecialchars($t('employees_module.employment_types.' . str_replace('-', '_', $employmentType)), ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="join_date" class="sapas-form-label"><?= htmlspecialchars($t('employees_module.join_date'), ENT_QUOTES, 'UTF-8') ?></label>
                <input type="date" id="join_date" name="join_date" value="<?= htmlspecialchars($employee['join_date'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="mt-2 sapas-form-control">
            </div>
        </div>
    </div>

    <div class="sapas-form-section">
        <div class="sapas-form-section-header">
            <h2><?= htmlspecialchars($t('employees_module.work_rules_section'), ENT_QUOTES, 'UTF-8') ?></h2>
            <p><?= htmlspecialchars($t('employees_module.work_rules_help'), ENT_QUOTES, 'UTF-8') ?></p>
        </div>

        <div class="sapas-form-grid">
            <div>
                <label for="expected_daily_work_hours" class="sapas-form-label"><?= htmlspecialchars($t('employees_module.expected_daily_work_hours'), ENT_QUOTES, 'UTF-8') ?><?= $requiredIndicator ?></label>
                <input type="number" id="expected_daily_work_hours" name="expected_daily_work_hours" min="0.5" max="24" step="0.25" required value="<?= htmlspecialchars((string) ($employee['expected_daily_work_hours'] ?? '8.00'), ENT_QUOTES, 'UTF-8') ?>" class="mt-2 sapas-form-control">
                <p class="sapas-field-help"><?= htmlspecialchars($t('employees_module.expected_daily_work_hours_help'), ENT_QUOTES, 'UTF-8') ?></p>
            </div>
            <div>
                <label for="overtime_eligible" class="sapas-form-label"><?= htmlspecialchars($t('employees_module.overtime_eligible'), ENT_QUOTES, 'UTF-8') ?><?= $requiredIndicator ?></label>
                <select id="overtime_eligible" name="overtime_eligible" required class="mt-2 sapas-form-control">
                    <option value="1" <?= (string) ($employee['overtime_eligible'] ?? '1') === '1' ? 'selected' : '' ?>><?= htmlspecialchars($t('common.yes'), ENT_QUOTES, 'UTF-8') ?></option>
                    <option value="0" <?= (string) ($employee['overtime_eligible'] ?? '1') === '0' ? 'selected' : '' ?>><?= htmlspecialchars($t('common.no'), ENT_QUOTES, 'UTF-8') ?></option>
                </select>
            </div>
        </div>
    </div>

    <div class="sapas-form-section">
        <div class="sapas-form-section-header">
            <h2><?= htmlspecialchars($t('employees_module.contact_section'), ENT_QUOTES, 'UTF-8') ?></h2>
            <p><?= htmlspecialchars($t('employees_module.contact_help'), ENT_QUOTES, 'UTF-8') ?></p>
        </div>

        <div class="sapas-form-grid">
            <div>
                <label for="phone" class="sapas-form-label"><?= htmlspecialchars($t('common.labels.phone'), ENT_QUOTES, 'UTF-8') ?></label>
                <input type="tel" id="phone" name="phone" value="<?= htmlspecialchars($employee['phone'] ?? '', ENT_QUOTES, 'UTF-8') ?>" maxlength="50" autocomplete="tel" inputmode="tel" placeholder="09 7XX XXX XXX" class="mt-2 sapas-form-control" aria-describedby="phone-help">
                <p id="phone-help" class="sapas-field-help"><?= htmlspecialchars($t('employees_module.phone_help'), ENT_QUOTES, 'UTF-8') ?></p>
            </div>
            <div>
                <label for="address" class="sapas-form-label"><?= htmlspecialchars($t('common.labels.address'), ENT_QUOTES, 'UTF-8') ?></label>
                <textarea id="address" name="address" rows="3" class="mt-2 sapas-form-control"><?= htmlspecialchars($employee['address'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                <p class="sapas-field-help"><?= htmlspecialchars($t('employees_module.address_help'), ENT_QUOTES, 'UTF-8') ?></p>
            </div>
        </div>
    </div>

    <div class="sapas-form-action-row">
        <a href="<?= htmlspecialchars($cancelHref, ENT_QUOTES, 'UTF-8') ?>" class="sapas-btn sapas-btn-secondary"><?= htmlspecialchars($t('common.actions.cancel'), ENT_QUOTES, 'UTF-8') ?></a>
        <div class="sapas-operation-actions">
            <button type="submit" class="sapas-btn sapas-btn-primary" id="submit-btn"><?= htmlspecialchars($submitLabel, ENT_QUOTES, 'UTF-8') ?></button>
        </div>
    </div>
</form>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const nameInput = document.getElementById('name');
    const nameFeedback = document.getElementById('name-live-feedback');
    const submitBtn = document.getElementById('submit-btn');
    const roleInput = document.getElementById('role');
    const departmentInput = document.querySelector('[data-department-select]');
    const departmentHelp = document.querySelector('[data-department-help]');
    const departmentRequiredIndicator = document.querySelector('[data-department-required-indicator]');

    const syncDepartmentRequirement = () => {
        if (!roleInput || !departmentInput || !departmentHelp) {
            return;
        }

        const required = roleInput.value === 'department_head';
        departmentInput.required = required;
        departmentInput.setAttribute('aria-required', required ? 'true' : 'false');
        if (departmentRequiredIndicator) {
            departmentRequiredIndicator.classList.toggle('hidden', !required);
        }
        departmentHelp.textContent = required
            ? <?= json_encode($t('employees_module.department_head_help'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
            : <?= json_encode($t('employees_module.department_help'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    };

    if (roleInput) {
        roleInput.addEventListener('change', syncDepartmentRequirement);
        syncDepartmentRequirement();
    }

    if (nameInput && nameFeedback && submitBtn) {
        // Regex matching backend logic: /^[\p{L}\p{M} .\x{0027}\x{2019}-]+$/u
        // JS equivalent using unicode property escapes:
        const nameRegex = /^[\p{L}\p{M} .\u0027\u2019-]+$/u;

        nameInput.addEventListener('input', () => {
            const value = nameInput.value;
            if (value.length > 0 && !nameRegex.test(value)) {
                nameInput.classList.add('border-rose-300', 'text-rose-900', 'focus:border-rose-500', 'focus:ring-rose-500');
                nameFeedback.classList.remove('hidden');
                submitBtn.disabled = true;
                submitBtn.classList.add('opacity-50', 'cursor-not-allowed');
            } else {
                nameInput.classList.remove('border-rose-300', 'text-rose-900', 'focus:border-rose-500', 'focus:ring-rose-500');
                nameFeedback.classList.add('hidden');
                submitBtn.disabled = false;
                submitBtn.classList.remove('opacity-50', 'cursor-not-allowed');
            }
        });
    }
});
</script>
