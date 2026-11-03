<?php
$t = $t ?? static fn (string $key): string => $key;
$title = $title ?? $t('absence.title');
$isTeamViewer = isset($isTeamViewer) ? (bool) $isTeamViewer : false;
$canManageAttendance = isset($canManageAttendance) ? (bool) $canManageAttendance : false;
$departmentScopeLocked = isset($departmentScopeLocked) ? (bool) $departmentScopeLocked : false;
$records = $records ?? [];
$search = $search ?? '';
$selectedEmployeeId = $selectedEmployeeId ?? null;
$departmentId = isset($departmentId) && is_int($departmentId) && $departmentId > 0 ? $departmentId : null;
$fromDate = $fromDate ?? '';
$toDate = $toDate ?? '';
$page = $page ?? 1;
$perPage = $perPage ?? 20;
$total = $total ?? 0;
$departments = $departments ?? [];
$employees = $employees ?? [];
$errors = $errors ?? [];

$layout = 'layouts/app';
$pageDescription = $isTeamViewer ? $t('absence.team_description') : $t('absence.employee_description');
$pageActions = !$isTeamViewer
    ? '<a href="/employee/dashboard" class="sapas-btn sapas-btn-secondary">' . htmlspecialchars($t('attendance.back_to_dashboard'), ENT_QUOTES, 'UTF-8') . '</a>'
    : '';

function absenceDateValue(string $date, string $englishFormat = 'M j, Y'): string
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if ($parsed === false) {
        return $date;
    }
    $localization = \App\Core\Localization::current();
    if ($localization->locale() !== 'my') {
        return $parsed->format($englishFormat);
    }
    $month = $localization->translate('analytics.months.' . $parsed->format('m'));
    return $month === 'analytics.months.' . $parsed->format('m')
        ? $parsed->format('Y-m-d')
        : ltrim($parsed->format('d'), '0') . ' ' . $month . ' ' . $parsed->format('Y');
}

function absenceWeekdayValue(string $date): string
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if ($parsed === false) {
        return '';
    }
    $localization = \App\Core\Localization::current();
    if ($localization->locale() !== 'my') {
        return $parsed->format('l');
    }
    $key = 'work_schedules.weekdays.' . $parsed->format('N');
    $weekday = $localization->translate($key);
    return $weekday === $key ? $parsed->format('Y-m-d') : $weekday;
}

function absenceTemplate(string $key, array $values): string
{
    $template = \App\Core\Localization::current()->translate($key);
    $replacements = [];
    foreach ($values as $name => $value) {
        $replacements[':' . (string) $name] = (string) $value;
    }
    return strtr($template, $replacements);
}

$pages = max(1, (int) ceil($total / max(1, (int) $perPage)));
$visibleCount = count($records);
$rowNumberStart = ((max(1, (int) $page) - 1) * max(1, (int) $perPage)) + 1;
$departmentNames = [];
foreach ($departments as $department) {
    $departmentNames[(int) $department['id']] = (string) $department['name'];
}
$employeeNames = [];
foreach ($employees as $employee) {
    $employeeNames[(int) $employee['id']] = (string) $employee['name'];
}
$hasFilters = $departmentId !== null || $selectedEmployeeId !== null || $fromDate !== '' || $toDate !== '';
$activeFilters = [];
if ($departmentId !== null) {
    $activeFilters[] = $t('common.labels.department') . ': ' . ($departmentNames[$departmentId] ?? ('ID #' . $departmentId));
}
if ($selectedEmployeeId !== null) {
    $activeFilters[] = $t('common.labels.employee') . ': ' . ($employeeNames[$selectedEmployeeId] ?? ('ID #' . $selectedEmployeeId));
}
if ($fromDate !== '') {
    $activeFilters[] = $t('common.labels.date_from') . ': ' . absenceDateValue($fromDate);
}
if ($toDate !== '') {
    $activeFilters[] = $t('common.labels.date_to') . ': ' . absenceDateValue($toDate);
}
$paginationQuery = [];
if ($isTeamViewer && $departmentId !== null) {
    $paginationQuery['department_id'] = (string) $departmentId;
}
if ($isTeamViewer && $selectedEmployeeId !== null) {
    $paginationQuery['employee_id'] = (string) $selectedEmployeeId;
}
if ($fromDate !== '') {
    $paginationQuery['from'] = $fromDate;
}
if ($toDate !== '') {
    $paginationQuery['to'] = $toDate;
}
$paginationQuery['per_page'] = (string) $perPage;
$paginationPageSizeOptions = [20, 50, 100];
?>
<?php if ($errors !== []): ?>
    <div class="mb-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
        <?php foreach ($errors as $error): ?><p><?= htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8') ?></p><?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="sapas-report-table-panel">
    <div class="sapas-report-table-header">
        <div class="sapas-filter-header flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
            <div>
                <h2 class="text-xl font-semibold"><?= htmlspecialchars($isTeamViewer ? $t('absence.team_filters') : $t('absence.employee_filters'), ENT_QUOTES, 'UTF-8') ?></h2>
                <p class="mt-1 text-sm text-slate-500"><?= htmlspecialchars($isTeamViewer ? $t('absence.team_filters_help') : $t('absence.employee_filters_help'), ENT_QUOTES, 'UTF-8') ?></p>
            </div>
            <a href="/absence" class="sapas-btn sapas-btn-secondary sapas-filter-reset"><?= htmlspecialchars($t('common.actions.reset'), ENT_QUOTES, 'UTF-8') ?></a>
        </div>

        <form method="get" action="/absence" class="sapas-report-filter-grid sapas-polish-filter-grid is-four">
            <input type="hidden" name="per_page" value="<?= (int) $perPage ?>">
            <?php if ($isTeamViewer): ?>
                <div>
                    <label for="absence_department_id" class="block text-sm font-medium text-slate-700"><?= htmlspecialchars($t('common.labels.department'), ENT_QUOTES, 'UTF-8') ?></label>
                    <?php if ($departmentScopeLocked): ?>
                        <input type="hidden" name="department_id" value="<?= (int) ($departmentId ?? 0) ?>">
                        <select id="absence_department_id" class="mt-2 sapas-form-control" disabled aria-disabled="true">
                            <?php foreach ($departments as $department): ?><option selected><?= htmlspecialchars((string) $department['name'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <select id="absence_department_id" name="department_id" class="mt-2 sapas-form-control" onchange="if (this.form.elements['employee_id']) { this.form.elements['employee_id'].value = ''; } this.form.submit()">
                            <option value=""><?= htmlspecialchars($t('reports_module.all_departments'), ENT_QUOTES, 'UTF-8') ?></option>
                            <?php foreach ($departments as $department): ?>
                                <option value="<?= (int) $department['id'] ?>" <?= $departmentId === (int) $department['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $department['name'], ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                </div>
                <div>
                    <label for="absence_employee_id" class="block text-sm font-medium text-slate-700"><?= htmlspecialchars($t('common.labels.employee'), ENT_QUOTES, 'UTF-8') ?></label>
                    <select id="absence_employee_id" name="employee_id" class="mt-2 sapas-form-control">
                        <option value=""><?= htmlspecialchars($t('reports_module.all_employees'), ENT_QUOTES, 'UTF-8') ?></option>
                        <?php foreach ($employees as $employee): ?>
                            <?php
                            $name = trim((string) ($employee['name'] ?? ''));
                            $staffCode = trim((string) ($employee['staff_code'] ?? ''));
                            $label = $staffCode !== '' ? $name . ' (' . $staffCode . ')' : $name;
                            ?>
                            <option value="<?= (int) $employee['id'] ?>" <?= $selectedEmployeeId === (int) $employee['id'] ? 'selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            <div>
                <label class="block text-sm font-medium text-slate-700"><?= htmlspecialchars($t('common.labels.date_from'), ENT_QUOTES, 'UTF-8') ?></label>
                <input type="date" name="from" value="<?= htmlspecialchars((string) $fromDate, ENT_QUOTES, 'UTF-8') ?>" class="mt-2 sapas-form-control">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700"><?= htmlspecialchars($t('common.labels.date_to'), ENT_QUOTES, 'UTF-8') ?></label>
                <input type="date" name="to" value="<?= htmlspecialchars((string) $toDate, ENT_QUOTES, 'UTF-8') ?>" class="mt-2 sapas-form-control">
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
                            <?php foreach ($activeFilters as $filter): ?><span class="sapas-chip sapas-chip-info"><?= htmlspecialchars($filter, ENT_QUOTES, 'UTF-8') ?></span><?php endforeach; ?>
                        </div>
                    </div>
                    <p class="text-sm text-slate-600"><?= htmlspecialchars(absenceTemplate($total === 1 ? 'absence.matching_summary' : 'absence.matching_summary_plural', ['count' => (string) $total]), ENT_QUOTES, 'UTF-8') ?></p>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($records === []): ?>
        <div class="sapas-report-empty sapas-polish-empty-state">
            <p class="sapas-report-empty-title"><?= htmlspecialchars($t('absence.empty_title'), ENT_QUOTES, 'UTF-8') ?></p>
            <p class="sapas-report-empty-copy"><?= htmlspecialchars($isTeamViewer ? $t('absence.empty_team_help') : $t('absence.empty_employee_help'), ENT_QUOTES, 'UTF-8') ?></p>
        </div>
    <?php else: ?>
        <div class="sapas-report-table-header sapas-data-table-summary">
            <div class="sapas-report-meta-grid sapas-data-table-summary-grid">
                <div><p class="text-sm font-medium text-slate-500"><?= htmlspecialchars($t('common.showing'), ENT_QUOTES, 'UTF-8') ?></p><p class="mt-1 text-lg font-semibold text-slate-900"><?= htmlspecialchars(absenceTemplate($visibleCount === 1 ? 'absence.records_on_page' : 'absence.records_on_page_plural', ['count' => (string) $visibleCount]), ENT_QUOTES, 'UTF-8') ?></p></div>
                <div><p class="text-sm font-medium text-slate-500"><?= htmlspecialchars($t('reports.total_matches'), ENT_QUOTES, 'UTF-8') ?></p><p class="mt-1 text-lg font-semibold text-slate-900"><?= htmlspecialchars(absenceTemplate($total === 1 ? 'absence.total_records' : 'absence.total_records_plural', ['count' => (string) $total]), ENT_QUOTES, 'UTF-8') ?></p></div>
                <div><p class="text-sm font-medium text-slate-500"><?= htmlspecialchars($t('reports_module.pages'), ENT_QUOTES, 'UTF-8') ?></p><p class="mt-1 text-lg font-semibold text-slate-900"><?= htmlspecialchars($t('reports.page'), ENT_QUOTES, 'UTF-8') ?> <?= (int) $page ?> / <?= (int) $pages ?></p></div>
            </div>
        </div>

        <div class="overflow-x-auto sapas-data-table-scroll sapas-polish-table-scroll">
            <table class="min-w-full divide-y divide-slate-200 sapas-data-table sapas-attendance-table">
                <thead class="bg-slate-50"><tr>
                    <th class="px-3 py-2 text-left text-sm font-medium text-slate-700"><?= htmlspecialchars($t('common.labels.no'), ENT_QUOTES, 'UTF-8') ?></th>
                    <th class="px-3 py-2 text-left text-sm font-medium text-slate-700"><?= htmlspecialchars($t('common.labels.date'), ENT_QUOTES, 'UTF-8') ?></th>
                    <?php if ($isTeamViewer): ?><th class="px-3 py-2 text-left text-sm font-medium text-slate-700"><?= htmlspecialchars($t('common.labels.employee'), ENT_QUOTES, 'UTF-8') ?></th><?php endif; ?>
                    <th class="px-3 py-2 text-left text-sm font-medium text-slate-700"><?= htmlspecialchars($t('common.labels.status'), ENT_QUOTES, 'UTF-8') ?></th>
                    <th class="px-3 py-2 text-left text-sm font-medium text-slate-700"><?= htmlspecialchars($t('attendance.remarks'), ENT_QUOTES, 'UTF-8') ?></th>
                    <?php if ($canManageAttendance): ?><th class="px-3 py-2 text-left text-sm font-medium text-slate-700"><?= htmlspecialchars($t('common.labels.actions'), ENT_QUOTES, 'UTF-8') ?></th><?php endif; ?>
                </tr></thead>
                <tbody class="divide-y divide-slate-200 bg-white">
                    <?php foreach ($records as $index => $record): ?>
                        <tr>
                            <td class="px-3 py-2.5 text-sm text-slate-800 whitespace-nowrap"><span class="sapas-row-number"><?= $rowNumberStart + (int) $index ?></span></td>
                            <td class="px-3 py-2.5 text-sm text-slate-800 whitespace-nowrap"><p class="font-medium text-slate-900"><?= htmlspecialchars(absenceDateValue((string) $record['attendance_date']), ENT_QUOTES, 'UTF-8') ?></p><p class="text-xs text-slate-500"><?= htmlspecialchars(absenceWeekdayValue((string) $record['attendance_date']), ENT_QUOTES, 'UTF-8') ?></p></td>
                            <?php if ($isTeamViewer): ?>
                                <?php $name = trim((string) ($record['employee_name'] ?? '')); $code = trim((string) ($record['staff_code'] ?? '')); $identity = $code !== '' ? $name . ' (' . $code . ')' : $name; ?>
                                <td class="px-3 py-2.5 text-sm text-slate-800"><p class="font-medium text-slate-900"><?= htmlspecialchars($identity, ENT_QUOTES, 'UTF-8') ?></p><p class="text-xs text-slate-500"><?= htmlspecialchars((string) ($record['employee_email'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p></td>
                            <?php endif; ?>
                            <td class="px-3 py-2.5 text-sm"><span class="sapas-badge sapas-badge-danger"><?= htmlspecialchars($t('statuses.attendance.absent'), ENT_QUOTES, 'UTF-8') ?></span></td>
                            <?php $remarks = trim((string) ($record['remarks'] ?? '')); ?>
                            <td class="px-3 py-2.5 text-sm text-slate-600 max-w-[220px] truncate" title="<?= htmlspecialchars($remarks, ENT_QUOTES, 'UTF-8') ?>"><?= $remarks !== '' ? htmlspecialchars($remarks, ENT_QUOTES, 'UTF-8') : '—' ?></td>
                            <?php if ($canManageAttendance): ?><td class="px-3 py-2.5 text-sm font-medium sapas-table-action"><a href="/attendance/edit?id=<?= (int) $record['id'] ?>" class="sapas-btn sapas-btn-secondary sapas-btn-spotlight whitespace-nowrap"><?= htmlspecialchars($t('attendance.edit_record'), ENT_QUOTES, 'UTF-8') ?></a></td><?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php $paginationPath = '/absence'; require __DIR__ . '/../partials/pagination.php'; ?>
    <?php endif; ?>
</div>
