<?php
$t = $t ?? static fn (string $key): string => $key;
if (!isset($employee)) {
    $employee = [];
}
if (!isset($profile)) {
    $profile = null;
}

$employeeId = (int) ($employee['id'] ?? 0);
$employeeName = (string) ($employee['name'] ?? 'Employee');
$employeeEmail = (string) ($employee['email'] ?? '');
$role = (string) ($employee['role'] ?? 'employee');
$status = (string) ($employee['status'] ?? 'inactive');
$emptyValue = '—';
$displayOptional = static fn (mixed $value): string => trim((string) ($value ?? '')) !== '' ? (string) $value : $emptyValue;
$departmentName = $displayOptional($employee['department_name'] ?? null);
$positionName = $displayOptional($employee['position_name'] ?? null);
$isActive = $status === 'active';
$isEmailVerified = ($employee['email_verified_at'] ?? null) !== null;
$isOwnAdministratorAccount = $employeeId === (int) ($userId ?? 0) && $role === 'admin';
$deactivateConfirm = sprintf($t('common.confirm.deactivate_employee'), $employeeName);
$resetPasswordConfirm = sprintf($t('common.confirm.reset_employee_password'), $employeeName);

$layout = 'layouts/app';
$pageTitle = $t('employees_module.details_title');
$pageDescription = $t('employees_module.details_description');
$pageActions = '<a href="/employees/edit?id=' . $employeeId . '" class="sapas-btn sapas-btn-primary sapas-btn-spotlight">' . htmlspecialchars($t('employees_module.edit_employee'), ENT_QUOTES, 'UTF-8') . '</a>'
    . '<a href="/employees" class="sapas-btn sapas-btn-secondary">' . htmlspecialchars($t('employees_module.back_to_employees'), ENT_QUOTES, 'UTF-8') . '</a>';

$roleBadgeClass = $role === 'admin'
    ? 'sapas-badge bg-sky-50 text-sky-700'
    : 'sapas-badge bg-slate-100 text-slate-700';
$statusBadgeClass = $isActive
    ? 'sapas-badge bg-emerald-50 text-emerald-700'
    : 'sapas-badge bg-rose-50 text-rose-700';
?>
<div class="space-y-6">
    <div class="sapas-workspace-card">
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-6">
            <div class="flex flex-col items-center gap-5 text-center sm:flex-row sm:text-left">
                <?php
                $avatarName = $employeeName;
                $avatarPhotoUrl = (string) ($profilePhotoUrl ?? '');
                $avatarClass = 'h-24 w-24 border-4 border-white shadow-md ring-1 ring-slate-200';
                $avatarInitialsClass = 'text-2xl';
                $avatarAlt = sprintf($t('profile.avatar_alt'), $employeeName);
                $avatarFallbackLabel = sprintf($t('profile.avatar_fallback'), $employeeName);
                require __DIR__ . '/../partials/avatar.php';
                unset($avatarName, $avatarPhotoUrl, $avatarClass, $avatarInitialsClass, $avatarAlt, $avatarFallbackLabel);
                ?>
                <div>
                    <p class="text-sm font-medium text-slate-500"><?= htmlspecialchars($t('employees_module.summary'), ENT_QUOTES, 'UTF-8') ?></p>
                    <h2 class="mt-2 text-3xl font-semibold"><?= htmlspecialchars($employeeName, ENT_QUOTES, 'UTF-8') ?></h2>
                    <p class="mt-1 text-slate-600"><?= htmlspecialchars($employeeEmail, ENT_QUOTES, 'UTF-8') ?></p>
                    <div class="mt-4 flex flex-wrap justify-center gap-2 sm:justify-start">
                        <span class="<?= $roleBadgeClass ?>"><?= htmlspecialchars($t('roles.' . $role), ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="<?= $statusBadgeClass ?>"><?= htmlspecialchars($t('statuses.account.' . $status), ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="<?= $isEmailVerified ? 'sapas-badge bg-emerald-50 text-emerald-700' : 'sapas-badge bg-amber-50 text-amber-800' ?>"><?= htmlspecialchars($isEmailVerified ? $t('employees_module.email_verified') : $t('employees_module.email_unverified'), ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="sapas-badge bg-slate-100 text-slate-700"><?= htmlspecialchars($t('common.labels.department'), ENT_QUOTES, 'UTF-8') ?>: <?= htmlspecialchars($departmentName, ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="sapas-badge bg-slate-100 text-slate-700"><?= htmlspecialchars($t('common.labels.position'), ENT_QUOTES, 'UTF-8') ?>: <?= htmlspecialchars($positionName, ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                </div>
            </div>

            <div class="sapas-operation-detail">
                <p class="text-sm font-medium text-slate-500"><?= htmlspecialchars($t('employees_module.current_access'), ENT_QUOTES, 'UTF-8') ?></p>
                <p class="mt-1 text-lg font-semibold text-slate-900"><?= htmlspecialchars($isActive ? $t('employees_module.active_account') : $t('employees_module.inactive_account'), ENT_QUOTES, 'UTF-8') ?></p>
                <p class="mt-1 text-sm text-slate-600">
                    <?= htmlspecialchars($isActive ? $t('employees_module.active_account_help') : $t('employees_module.inactive_account_help'), ENT_QUOTES, 'UTF-8') ?>
                </p>
            </div>
        </div>
    </div>

    <div class="grid gap-6 md:grid-cols-2">
        <div class="sapas-workspace-card">
            <h2 class="text-xl font-semibold"><?= htmlspecialchars($t('employees_module.account_section'), ENT_QUOTES, 'UTF-8') ?></h2>
            <p class="mt-1 text-sm text-slate-500"><?= htmlspecialchars($t('employees_module.account_detail_help'), ENT_QUOTES, 'UTF-8') ?></p>

            <div class="mt-6 space-y-4">
                <div>
                    <p class="text-sm font-medium text-slate-500"><?= htmlspecialchars($t('common.labels.name'), ENT_QUOTES, 'UTF-8') ?></p>
                    <p class="mt-1 text-lg text-slate-900"><?= htmlspecialchars($employeeName, ENT_QUOTES, 'UTF-8') ?></p>
                </div>
                <div>
                    <p class="text-sm font-medium text-slate-500"><?= htmlspecialchars($t('common.labels.email'), ENT_QUOTES, 'UTF-8') ?></p>
                    <p class="mt-1 text-lg text-slate-900"><?= htmlspecialchars($employeeEmail, ENT_QUOTES, 'UTF-8') ?></p>
                </div>
                <div>
                    <p class="text-sm font-medium text-slate-500"><?= htmlspecialchars($t('common.labels.role'), ENT_QUOTES, 'UTF-8') ?></p>
                    <p class="mt-1 text-lg text-slate-900"><?= htmlspecialchars($t('roles.' . $role), ENT_QUOTES, 'UTF-8') ?></p>
                </div>
                <div>
                    <p class="text-sm font-medium text-slate-500"><?= htmlspecialchars($t('common.labels.status'), ENT_QUOTES, 'UTF-8') ?></p>
                    <p class="mt-1 text-lg text-slate-900"><?= htmlspecialchars($t('statuses.account.' . $status), ENT_QUOTES, 'UTF-8') ?></p>
                </div>
                <div>
                    <p class="text-sm font-medium text-slate-500"><?= htmlspecialchars($t('employees_module.email_verification'), ENT_QUOTES, 'UTF-8') ?></p>
                    <p class="mt-1 text-lg text-slate-900"><?= htmlspecialchars($isEmailVerified ? $t('employees_module.email_verified') : $t('employees_module.email_unverified'), ENT_QUOTES, 'UTF-8') ?></p>
                </div>
            </div>
        </div>

        <div class="sapas-workspace-card">
            <h2 class="text-xl font-semibold"><?= htmlspecialchars($t('employees_module.organization_section'), ENT_QUOTES, 'UTF-8') ?></h2>
            <p class="mt-1 text-sm text-slate-500"><?= htmlspecialchars($t('employees_module.organization_detail_help'), ENT_QUOTES, 'UTF-8') ?></p>

            <div class="mt-6 space-y-4">
                <div>
                    <p class="text-sm font-medium text-slate-500"><?= htmlspecialchars($t('common.labels.department'), ENT_QUOTES, 'UTF-8') ?></p>
                    <p class="mt-1 text-lg text-slate-900"><?= htmlspecialchars($departmentName, ENT_QUOTES, 'UTF-8') ?></p>
                </div>
                <div>
                    <p class="text-sm font-medium text-slate-500"><?= htmlspecialchars($t('common.labels.position'), ENT_QUOTES, 'UTF-8') ?></p>
                    <p class="mt-1 text-lg text-slate-900"><?= htmlspecialchars($positionName, ENT_QUOTES, 'UTF-8') ?></p>
                </div>
                <div>
                    <p class="text-sm font-medium text-slate-500"><?= htmlspecialchars($t('employees_module.staff_code'), ENT_QUOTES, 'UTF-8') ?></p>
                    <p class="mt-1 text-lg text-slate-900"><?= htmlspecialchars($displayOptional($employee['staff_code'] ?? null), ENT_QUOTES, 'UTF-8') ?></p>
                </div>
                <div>
                    <p class="text-sm font-medium text-slate-500"><?= htmlspecialchars($t('employees_module.employment_type'), ENT_QUOTES, 'UTF-8') ?></p>
                    <p class="mt-1 text-lg text-slate-900"><?= htmlspecialchars($t('employees_module.employment_types.' . str_replace('-', '_', (string) ($employee['employment_type'] ?? 'full-time'))), ENT_QUOTES, 'UTF-8') ?></p>
                </div>
                <div>
                    <p class="text-sm font-medium text-slate-500"><?= htmlspecialchars($t('employees_module.join_date'), ENT_QUOTES, 'UTF-8') ?></p>
                    <p class="mt-1 text-lg text-slate-900"><?= htmlspecialchars($displayOptional($employee['join_date'] ?? null), ENT_QUOTES, 'UTF-8') ?></p>
                </div>
            </div>
        </div>
    </div>

    <div class="sapas-workspace-card">
        <h2 class="text-xl font-semibold"><?= htmlspecialchars($t('employees_module.work_rules_section'), ENT_QUOTES, 'UTF-8') ?></h2>
        <p class="mt-1 text-sm text-slate-500"><?= htmlspecialchars($t('employees_module.work_rules_help'), ENT_QUOTES, 'UTF-8') ?></p>
        <div class="mt-6 grid gap-6 md:grid-cols-2">
            <div>
                <p class="text-sm font-medium text-slate-500"><?= htmlspecialchars($t('employees_module.expected_daily_work_hours'), ENT_QUOTES, 'UTF-8') ?></p>
                <p class="mt-1 text-lg text-slate-900"><?= htmlspecialchars((string) ($employee['expected_daily_work_hours'] ?? '8.00'), ENT_QUOTES, 'UTF-8') ?></p>
            </div>
            <div>
                <p class="text-sm font-medium text-slate-500"><?= htmlspecialchars($t('employees_module.overtime_eligible'), ENT_QUOTES, 'UTF-8') ?></p>
                <p class="mt-1 text-lg text-slate-900"><?= htmlspecialchars((int) ($employee['overtime_eligible'] ?? 1) === 1 ? $t('common.yes') : $t('common.no'), ENT_QUOTES, 'UTF-8') ?></p>
            </div>
        </div>
    </div>

    <div class="sapas-workspace-card bg-slate-50 border border-slate-200">
        <h2 class="text-xl font-semibold"><?= htmlspecialchars($t('employees_module.work_policy') ?? 'Current Work Policy', ENT_QUOTES, 'UTF-8') ?></h2>
            <p class="mt-1 text-sm text-slate-500"><?= htmlspecialchars($t('employees_module.work_policy_help'), ENT_QUOTES, 'UTF-8') ?></p>
        <?php if (!empty($resolvedWorkPolicy['available'])): ?>
            <div class="mt-6 grid gap-6 md:grid-cols-4">
                <div>
                    <p class="text-sm font-medium text-slate-500"><?= htmlspecialchars($t('employees_module.policy_name'), ENT_QUOTES, 'UTF-8') ?></p>
                    <p class="mt-1 text-lg text-slate-900"><?= htmlspecialchars($resolvedWorkPolicy['name'], ENT_QUOTES, 'UTF-8') ?></p>
                </div>
                <div>
                    <p class="text-sm font-medium text-slate-500"><?= htmlspecialchars($t('employees_module.policy_source'), ENT_QUOTES, 'UTF-8') ?></p>
                    <p class="mt-1 text-lg text-slate-900"><?= htmlspecialchars($resolvedWorkPolicy['source_label'], ENT_QUOTES, 'UTF-8') ?></p>
                </div>
                <div>
                    <p class="text-sm font-medium text-slate-500"><?= htmlspecialchars($t('employees_module.today_schedule'), ENT_QUOTES, 'UTF-8') ?></p>
                    <p class="mt-1 text-lg text-slate-900"><?= htmlspecialchars($resolvedWorkPolicy['schedule_label'], ENT_QUOTES, 'UTF-8') ?></p>
                </div>
                <div>
                    <p class="text-sm font-medium text-slate-500"><?= htmlspecialchars($t('employees_module.grace_period'), ENT_QUOTES, 'UTF-8') ?></p>
                    <p class="mt-1 text-lg text-slate-900"><?= htmlspecialchars($resolvedWorkPolicy['grace_label'], ENT_QUOTES, 'UTF-8') ?></p>
                </div>
            </div>
        <?php else: ?>
            <p class="mt-6 text-sm text-slate-600"><?= htmlspecialchars($t('employees_module.no_current_work_policy'), ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
    </div>

    <div class="sapas-workspace-card">
        <h2 class="text-xl font-semibold"><?= htmlspecialchars($t('employees_module.contact_section'), ENT_QUOTES, 'UTF-8') ?></h2>
        <p class="mt-1 text-sm text-slate-500"><?= htmlspecialchars($t('employees_module.contact_detail_help'), ENT_QUOTES, 'UTF-8') ?></p>

        <div class="mt-6 grid gap-6 md:grid-cols-2">
            <div>
                <p class="text-sm font-medium text-slate-500"><?= htmlspecialchars($t('common.labels.phone'), ENT_QUOTES, 'UTF-8') ?></p>
                <p class="mt-1 text-lg text-slate-900"><?= htmlspecialchars($displayOptional($profile['phone'] ?? null), ENT_QUOTES, 'UTF-8') ?></p>
            </div>
            <div>
                <p class="text-sm font-medium text-slate-500"><?= htmlspecialchars($t('common.labels.address'), ENT_QUOTES, 'UTF-8') ?></p>
                <p class="mt-1 text-lg text-slate-900"><?= htmlspecialchars($displayOptional($profile['address'] ?? null), ENT_QUOTES, 'UTF-8') ?></p>
            </div>
        </div>
    </div>

    <div class="sapas-workspace-card">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h2 class="text-xl font-semibold"><?= htmlspecialchars($t('employees_module.admin_actions'), ENT_QUOTES, 'UTF-8') ?></h2>
                <p class="mt-1 text-sm text-slate-500"><?= htmlspecialchars($t('employees_module.admin_actions_help'), ENT_QUOTES, 'UTF-8') ?></p>
            </div>
            <div class="sapas-operation-actions">
                <a href="/employees/edit?id=<?= $employeeId ?>" class="sapas-btn sapas-btn-primary sapas-btn-spotlight"><?= htmlspecialchars($t('employees_module.edit_employee'), ENT_QUOTES, 'UTF-8') ?></a>
                <?php if ($isActive && !$isOwnAdministratorAccount): ?>
                    <form method="post" action="/employees/deactivate?id=<?= $employeeId ?>" onsubmit="return confirm('<?= htmlspecialchars($deactivateConfirm, ENT_QUOTES, 'UTF-8') ?>');">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token ?? '', ENT_QUOTES, 'UTF-8') ?>">
                        <button type="submit" class="sapas-btn sapas-btn-danger-quiet"><?= htmlspecialchars($t('employees_module.deactivate_employee'), ENT_QUOTES, 'UTF-8') ?></button>
                    </form>
                <?php elseif ($isOwnAdministratorAccount): ?>
                    <div class="sapas-chip sapas-chip-muted"><?= htmlspecialchars($t('employees_module.self_deactivation_protection_help'), ENT_QUOTES, 'UTF-8') ?></div>
                <?php else: ?>
                    <div class="sapas-chip sapas-chip-muted"><?= htmlspecialchars($t('employees_module.account_already_inactive'), ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
                <?php if ($isActive && !$isEmailVerified): ?>
                    <form method="post" action="/employees/resend-verification?id=<?= $employeeId ?>">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token ?? '', ENT_QUOTES, 'UTF-8') ?>">
                        <button type="submit" class="sapas-btn sapas-btn-secondary"><?= htmlspecialchars($t('employees_module.resend_verification'), ENT_QUOTES, 'UTF-8') ?></button>
                    </form>
                <?php elseif (!$isEmailVerified): ?>
                    <div class="sapas-chip sapas-chip-muted"><?= htmlspecialchars($t('employees_module.verification_requires_active_account'), ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="sapas-workspace-card border-rose-200">
        <h2 class="text-xl font-semibold"><?= htmlspecialchars($t('employees_module.security'), ENT_QUOTES, 'UTF-8') ?></h2>
        <p class="mt-1 text-sm text-slate-500"><?= htmlspecialchars($t('employees_module.security_help'), ENT_QUOTES, 'UTF-8') ?></p>

        <form method="post" action="/employees/reset-password?id=<?= $employeeId ?>" class="mt-6 space-y-4" onsubmit="return confirm('<?= htmlspecialchars($resetPasswordConfirm, ENT_QUOTES, 'UTF-8') ?>');">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token ?? '', ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="expected_session_version" value="<?= htmlspecialchars((string) ($employee['session_version'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label for="password" class="block text-sm font-medium text-slate-700"><?= htmlspecialchars($t('employees_module.new_password'), ENT_QUOTES, 'UTF-8') ?></label>
                    <p class="mt-1 text-sm text-slate-500"><?= htmlspecialchars($t('employees_module.password_policy_help'), ENT_QUOTES, 'UTF-8') ?></p>
                    <input type="password" id="password" name="password" autocomplete="new-password" class="mt-2 sapas-form-control" required>
                </div>
                <div>
                    <label for="password_confirmation" class="block text-sm font-medium text-slate-700"><?= htmlspecialchars($t('employees_module.password_confirmation'), ENT_QUOTES, 'UTF-8') ?></label>
                    <p class="mt-1 text-sm text-slate-500"><?= htmlspecialchars($t('employees_module.password_confirmation_help'), ENT_QUOTES, 'UTF-8') ?></p>
                    <input type="password" id="password_confirmation" name="password_confirmation" autocomplete="new-password" class="mt-2 sapas-form-control" required>
                </div>
            </div>
            <p class="text-sm text-slate-500"><?= htmlspecialchars($t('employees_module.password_reset_session_help'), ENT_QUOTES, 'UTF-8') ?></p>
            <div class="flex justify-end">
                <button type="submit" class="sapas-btn sapas-btn-success"><?= htmlspecialchars($t('employees_module.reset_password'), ENT_QUOTES, 'UTF-8') ?></button>
            </div>
        </form>
    </div>
</div>
