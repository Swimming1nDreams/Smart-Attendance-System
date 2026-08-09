<?php
$t = $t ?? static fn (string $key): string => $key;
if (!isset($search)) {
    $search = '';
}
if (!isset($employees)) {
    $employees = [];
}
if (!isset($departments)) {
    $departments = [];
}
if (!isset($positions)) {
    $positions = [];
}
if (!isset($departmentId)) {
    $departmentId = null;
}
if (!isset($positionId)) {
    $positionId = null;
}
if (!isset($status)) {
    $status = '';
}
if (!isset($page)) {
    $page = 1;
}
if (!isset($perPage)) {
    $perPage = 20;
}
if (!isset($total)) {
    $total = 0;
}

$layout = 'layouts/app';
$title = $t('employees_module.title');
$pageDescription = $t('employees_module.filters_help');
$pageActions = '<a href="/employees/create" class="sapas-btn sapas-btn-primary">' . htmlspecialchars($t('employees_module.add_employee'), ENT_QUOTES, 'UTF-8') . '</a>';

$pages = max(1, (int) ceil($total / $perPage));
$visibleCount = count($employees);
$hasFilters = $search !== '' || $departmentId !== null || $positionId !== null || $status !== '';
$rowNumberStart = ((max(1, (int) $page) - 1) * max(1, (int) $perPage)) + 1;

$departmentNames = [];
foreach ($departments as $department) {
    $departmentNames[(int) $department['id']] = (string) $department['name'];
}

$positionNames = [];
foreach ($positions as $position) {
    $positionNames[(int) $position['id']] = (string) $position['name'];
}

$activeFilters = [];
if ($search !== '') {
    $activeFilters[] = $t('common.actions.search') . ': ' . $search;
}
if ($departmentId !== null && isset($departmentNames[(int) $departmentId])) {
    $activeFilters[] = $t('common.labels.department') . ': ' . $departmentNames[(int) $departmentId];
}
if ($positionId !== null && isset($positionNames[(int) $positionId])) {
    $activeFilters[] = $t('common.labels.position') . ': ' . $positionNames[(int) $positionId];
}
if ($status !== '') {
    $activeFilters[] = $t('common.labels.status') . ': ' . $t('statuses.account.' . $status);
}

$paginationQuery = [];
if ($search !== '') {
    $paginationQuery['search'] = $search;
}
if ($departmentId !== null) {
    $paginationQuery['department_id'] = (string) $departmentId;
}
if ($positionId !== null) {
    $paginationQuery['position_id'] = (string) $positionId;
}
if ($status !== '') {
    $paginationQuery['status'] = $status;
}
$paginationQuery['per_page'] = (string) $perPage;
$paginationPageSizeOptions = [20, 50, 100];

function employeeRoleClasses(string $role): string
{
    return match ($role) {
        'admin' => 'sapas-badge sapas-badge-info',
        'employee' => 'sapas-badge sapas-badge-neutral',
        default => 'sapas-badge sapas-badge-neutral',
    };
}

function employeeStatusClasses(string $status): string
{
    return match ($status) {
        'active' => 'sapas-badge sapas-badge-success',
        'inactive' => 'sapas-badge sapas-badge-danger',
        default => 'sapas-badge sapas-badge-neutral',
    };
}
?>
<div class="sapas-report-table-panel">
    <div class="sapas-report-table-header">
        <div class="sapas-filter-header flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
            <div>
                <h2 class="text-xl font-semibold"><?= htmlspecialchars($t('employees_module.filters_title'), ENT_QUOTES, 'UTF-8') ?></h2>
                <p class="mt-1 text-sm text-slate-500"><?= htmlspecialchars($t('employees_module.filters_help'), ENT_QUOTES, 'UTF-8') ?></p>
            </div>
            <a href="/employees" class="sapas-btn sapas-btn-secondary sapas-filter-reset"><?= htmlspecialchars($t('common.actions.reset'), ENT_QUOTES, 'UTF-8') ?></a>
        </div>

        <form method="get" action="/employees" class="sapas-report-filter-grid sapas-polish-filter-grid is-five">
            <input type="hidden" name="per_page" value="<?= $perPage ?>">
            <div>
                <label class="block text-sm font-medium text-slate-700"><?= htmlspecialchars($t('common.actions.search'), ENT_QUOTES, 'UTF-8') ?></label>
                <input type="search" name="search" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>" placeholder="<?= htmlspecialchars($t('employees_module.search_placeholder'), ENT_QUOTES, 'UTF-8') ?>" class="mt-2 sapas-form-control">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700"><?= htmlspecialchars($t('common.labels.department'), ENT_QUOTES, 'UTF-8') ?></label>
                <select name="department_id" class="mt-2 sapas-form-control">
                    <option value=""><?= htmlspecialchars($t('common.all') . ' ' . $t('nav.departments'), ENT_QUOTES, 'UTF-8') ?></option>
                    <?php foreach ($departments as $department): ?>
                        <option value="<?= (int) $department['id'] ?>" <?= ((int) $departmentId === (int) $department['id']) ? 'selected' : '' ?>><?= htmlspecialchars($department['name'], ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700"><?= htmlspecialchars($t('common.labels.position'), ENT_QUOTES, 'UTF-8') ?></label>
                <select name="position_id" class="mt-2 sapas-form-control">
                    <option value=""><?= htmlspecialchars($t('common.all') . ' ' . $t('nav.positions'), ENT_QUOTES, 'UTF-8') ?></option>
                    <?php foreach ($positions as $position): ?>
                        <option value="<?= (int) $position['id'] ?>" <?= ((int) $positionId === (int) $position['id']) ? 'selected' : '' ?>><?= htmlspecialchars($position['name'], ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700"><?= htmlspecialchars($t('common.labels.status'), ENT_QUOTES, 'UTF-8') ?></label>
                <select name="status" class="mt-2 sapas-form-control">
                    <option value=""><?= htmlspecialchars($t('employees_module.all_statuses'), ENT_QUOTES, 'UTF-8') ?></option>
                    <option value="active" <?= $status === 'active' ? 'selected' : '' ?>><?= htmlspecialchars($t('statuses.account.active'), ENT_QUOTES, 'UTF-8') ?></option>
                    <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>><?= htmlspecialchars($t('statuses.account.inactive'), ENT_QUOTES, 'UTF-8') ?></option>
                </select>
            </div>
            <div class="sapas-filter-actions flex items-end gap-2">
                <button type="submit" class="sapas-btn sapas-btn-primary w-full sm:w-auto"><?= htmlspecialchars($t('common.actions.apply_filters'), ENT_QUOTES, 'UTF-8') ?></button>
            </div>
        </form>

        <?php if ($hasFilters): ?>
            <div class="sapas-report-active-filters">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                    <div>
                        <p class="text-sm font-medium text-slate-500"><?= htmlspecialchars($t('common.labels.active_filters'), ENT_QUOTES, 'UTF-8') ?></p>
                        <div class="mt-2 flex flex-wrap gap-2">
                            <?php foreach ($activeFilters as $filter): ?>
                                <span class="sapas-chip sapas-chip-info"><?= htmlspecialchars($filter, ENT_QUOTES, 'UTF-8') ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <p class="text-sm text-slate-600"><?= $total ?> <?= htmlspecialchars($t('employees_module.matching_employees'), ENT_QUOTES, 'UTF-8') ?></p>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <?php if (empty($employees)): ?>
        <div class="sapas-report-empty sapas-polish-empty-state">
            <p class="sapas-report-empty-title"><?= htmlspecialchars($t('employees_module.empty_title'), ENT_QUOTES, 'UTF-8') ?></p>
            <p class="sapas-report-empty-copy"><?= htmlspecialchars($hasFilters ? $t('employees_module.empty_filtered') : $t('employees_module.empty_create'), ENT_QUOTES, 'UTF-8') ?></p>
        </div>
    <?php else: ?>
        <div class="sapas-report-table-header sapas-data-table-summary">
            <div class="sapas-report-meta-grid sapas-data-table-summary-grid">
                <div>
                    <p class="text-sm font-medium text-slate-500"><?= htmlspecialchars($t('common.showing'), ENT_QUOTES, 'UTF-8') ?></p>
                    <p class="mt-1 text-lg font-semibold text-slate-900"><?= $visibleCount ?> <?= htmlspecialchars($t('employees_module.employees_on_page'), ENT_QUOTES, 'UTF-8') ?></p>
                </div>
                <div>
                    <p class="text-sm font-medium text-slate-500"><?= htmlspecialchars($t('reports.total_matches'), ENT_QUOTES, 'UTF-8') ?></p>
                    <p class="mt-1 text-lg font-semibold text-slate-900"><?= $total ?> <?= htmlspecialchars($t('employees_module.matching_employees'), ENT_QUOTES, 'UTF-8') ?></p>
                </div>
                <div>
                    <p class="text-sm font-medium text-slate-500"><?= htmlspecialchars($t('reports_module.pages'), ENT_QUOTES, 'UTF-8') ?></p>
                    <p class="mt-1 text-lg font-semibold text-slate-900"><?= htmlspecialchars($t('reports.page'), ENT_QUOTES, 'UTF-8') ?> <?= $page ?> / <?= $pages ?></p>
                </div>
            </div>
        </div>

        <div class="sapas-data-table-scroll sapas-polish-table-scroll overflow-x-auto">
            <table class="sapas-data-table sapas-employee-table divide-y divide-slate-200">
                <thead class="bg-slate-50">
                    <tr>
                        <th class="sapas-col-row-number whitespace-nowrap px-4 py-3 text-left text-sm font-medium text-slate-700"><?= htmlspecialchars($t('common.labels.no'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th class="sapas-col-employee whitespace-nowrap px-4 py-3 text-left text-sm font-medium text-slate-700"><?= htmlspecialchars($t('common.labels.employee'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th class="sapas-col-employee-department whitespace-nowrap px-4 py-3 text-left text-sm font-medium text-slate-700"><?= htmlspecialchars($t('common.labels.department'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th class="sapas-col-employee-position whitespace-nowrap px-4 py-3 text-left text-sm font-medium text-slate-700"><?= htmlspecialchars($t('common.labels.position'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th class="sapas-col-employee-role whitespace-nowrap px-4 py-3 text-left text-sm font-medium text-slate-700"><?= htmlspecialchars($t('common.labels.role'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th class="sapas-col-employee-status whitespace-nowrap px-4 py-3 text-left text-sm font-medium text-slate-700"><?= htmlspecialchars($t('common.labels.status'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th class="sapas-col-employee-verification whitespace-nowrap px-4 py-3 text-left text-sm font-medium text-slate-700"><?= htmlspecialchars($t('employees_module.email_verification'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th class="sapas-col-employee-actions whitespace-nowrap px-4 py-3 text-left text-sm font-medium text-slate-700"><?= htmlspecialchars($t('common.labels.actions'), ENT_QUOTES, 'UTF-8') ?></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 bg-white">
                    <?php foreach ($employees as $index => $employee): ?>
                        <?php
                        $isOwnAdministratorAccount = (int) $employee['id'] === (int) ($userId ?? 0) && ($employee['role'] ?? '') === 'admin';
                        $employeeName = trim((string) ($employee['name'] ?? ''));
                        $staffCode = trim((string) ($employee['staff_code'] ?? ''));
                        $employeeIdentity = $staffCode !== '' ? $employeeName . ' (' . $staffCode . ')' : $employeeName;
                        $employeeEmail = trim((string) ($employee['email'] ?? ''));
                        $departmentName = trim((string) ($employee['department_name'] ?? ''));
                        $positionName = trim((string) ($employee['position_name'] ?? ''));
                        $role = (string) ($employee['role'] ?? 'employee');
                        $accountStatus = (string) ($employee['status'] ?? 'inactive');
                        $isEmailVerified = ($employee['email_verified_at'] ?? null) !== null;
                        $deactivateConfirm = sprintf($t('common.confirm.deactivate_employee'), $employeeName);
                        ?>
                        <tr>
                            <td class="px-4 py-4 text-sm text-slate-600 whitespace-nowrap"><span class="sapas-row-number"><?= $rowNumberStart + (int) $index ?></span></td>
                            <td class="px-4 py-4 text-sm text-slate-600">
                                    <div class="sapas-person-cell">
                                        <div class="sapas-person-cell-head">
                                            <p class="sapas-person-name font-semibold text-slate-900" title="<?= htmlspecialchars($employeeIdentity, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($employeeIdentity, ENT_QUOTES, 'UTF-8') ?></p>
                                        </div>
                                        <p class="sapas-person-email" title="<?= htmlspecialchars($employeeEmail, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($employeeEmail, ENT_QUOTES, 'UTF-8') ?></p>
                                    </div>
                                </td>
                            <td class="px-4 py-4 text-sm text-slate-600 whitespace-nowrap"><p class="sapas-table-text-cell" title="<?= htmlspecialchars($departmentName !== '' ? $departmentName : '—', ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($departmentName !== '' ? $departmentName : '—', ENT_QUOTES, 'UTF-8') ?></p></td>
                            <td class="px-4 py-4 text-sm text-slate-600 whitespace-nowrap"><p class="sapas-table-text-cell" title="<?= htmlspecialchars($positionName !== '' ? $positionName : '—', ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($positionName !== '' ? $positionName : '—', ENT_QUOTES, 'UTF-8') ?></p></td>
                            <td class="px-4 py-4 text-sm text-slate-600 whitespace-nowrap"><span class="<?= employeeRoleClasses($role) ?>"><?= htmlspecialchars($t('roles.' . $role), ENT_QUOTES, 'UTF-8') ?></span></td>
                            <td class="px-4 py-4 text-sm text-slate-600 whitespace-nowrap"><span class="<?= employeeStatusClasses($accountStatus) ?>"><?= htmlspecialchars($t('statuses.account.' . $accountStatus), ENT_QUOTES, 'UTF-8') ?></span></td>
                            <td class="px-4 py-4 text-sm text-slate-600 whitespace-nowrap"><span class="<?= $isEmailVerified ? 'sapas-badge sapas-badge-success' : 'sapas-badge sapas-badge-warning' ?>"><?= htmlspecialchars($isEmailVerified ? $t('employees_module.email_verified') : $t('employees_module.email_unverified'), ENT_QUOTES, 'UTF-8') ?></span></td>
                            <td class="px-4 py-4 text-sm font-medium sapas-table-action">
                                <div class="sapas-employee-actions">
                                    <a href="/employees/show?id=<?= (int) $employee['id'] ?>" class="sapas-btn sapas-btn-primary"><?= htmlspecialchars($t('common.actions.view'), ENT_QUOTES, 'UTF-8') ?></a>
                                    <a href="/employees/edit?id=<?= (int) $employee['id'] ?>" class="sapas-btn sapas-btn-secondary sapas-btn-spotlight"><?= htmlspecialchars($t('common.actions.edit'), ENT_QUOTES, 'UTF-8') ?></a>
                                    <?php if ($accountStatus === 'active' && !$isOwnAdministratorAccount): ?>
                                        <form method="post" action="/employees/deactivate?id=<?= (int) $employee['id'] ?>" onsubmit="return confirm('<?= htmlspecialchars($deactivateConfirm, ENT_QUOTES, 'UTF-8') ?>');">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                            <button type="submit" class="sapas-btn sapas-btn-danger-quiet"><?= htmlspecialchars($t('employees_module.deactivate_employee'), ENT_QUOTES, 'UTF-8') ?></button>
                                        </form>
                                    <?php elseif ($isOwnAdministratorAccount): ?>
                                        <span class="sapas-chip sapas-chip-muted" title="<?= htmlspecialchars($t('employees_module.self_deactivation_protection_help'), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($t('employees_module.current_admin_account'), ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php $paginationPath = '/employees'; ?>
        <?php require __DIR__ . '/../partials/pagination.php'; ?>
    <?php endif; ?>
</div>
