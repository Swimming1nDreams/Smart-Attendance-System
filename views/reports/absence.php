<?php
$t = $t ?? static fn (string $key): string => $key;
$title = $title ?? $t('reports_module.absence_report');
$errors = $errors ?? [];
$records = $records ?? [];
$departments = $departments ?? [];
$employees = $employees ?? [];
$filters = $filters ?? ['date_from' => '', 'date_to' => '', 'department_id' => null, 'employee_id' => null];
$page = $page ?? 1;
$perPage = $perPage ?? 20;
$totalRecords = $totalRecords ?? 0;
$affectedEmployees = $affectedEmployees ?? 0;
$departmentScopeLocked = isset($departmentScopeLocked) && $departmentScopeLocked;

$layout = 'layouts/app';
$pageTitle = $pageTitle ?? $t('reports_module.absence_report');
$pageDescription = $pageDescription ?? $t('reports_module.absence_report_description');
$pages = max(1, (int) ceil($totalRecords / max(1, $perPage)));
$visibleCount = count($records);
$rowNumberStart = ((max(1, (int) $page) - 1) * max(1, (int) $perPage)) + 1;
$paginationQuery = array_filter([
    'date_from' => (string) ($filters['date_from'] ?? ''),
    'date_to' => (string) ($filters['date_to'] ?? ''),
    'department_id' => ($filters['department_id'] ?? null) !== null ? (string) $filters['department_id'] : '',
    'employee_id' => ($filters['employee_id'] ?? null) !== null ? (string) $filters['employee_id'] : '',
], static fn (string $value): bool => $value !== '');
$pdfExportUrl = '/admin/reports/absence/pdf' . ($paginationQuery === [] ? '' : '?' . http_build_query($paginationQuery));
$excelExportUrl = '/admin/reports/absence/excel' . ($paginationQuery === [] ? '' : '?' . http_build_query($paginationQuery));
$paginationQuery['per_page'] = (string) $perPage;
$paginationPageSizeOptions = [20, 50, 100];
$pageActions = '<div class="sapas-workspace-actions sapas-export-actions"><a href="/admin/reports" class="sapas-btn sapas-btn-secondary">' . htmlspecialchars($t('reports_module.back_to_reports'), ENT_QUOTES, 'UTF-8') . '</a><a href="' . htmlspecialchars($excelExportUrl, ENT_QUOTES, 'UTF-8') . '" class="sapas-btn sapas-btn-success">' . htmlspecialchars($t('common.actions.export_excel'), ENT_QUOTES, 'UTF-8') . '</a><a href="' . htmlspecialchars($pdfExportUrl, ENT_QUOTES, 'UTF-8') . '" class="sapas-btn sapas-btn-danger">' . htmlspecialchars($t('common.actions.export_pdf'), ENT_QUOTES, 'UTF-8') . '</a></div>';

$departmentNames = [];
foreach ($departments as $department) {
    $departmentNames[(int) $department['id']] = (string) $department['name'];
}
$employeeNames = [];
foreach ($employees as $employee) {
    $employeeNames[(int) $employee['id']] = (string) $employee['name'];
}
$activeFilters = [];
if (($filters['date_from'] ?? '') !== '') {
    $activeFilters[] = $t('common.labels.date_from') . ': ' . (string) $filters['date_from'];
}
if (($filters['date_to'] ?? '') !== '') {
    $activeFilters[] = $t('common.labels.date_to') . ': ' . (string) $filters['date_to'];
}
if (($filters['department_id'] ?? null) !== null) {
    $id = (int) $filters['department_id'];
    $activeFilters[] = $t('common.labels.department') . ': ' . ($departmentNames[$id] ?? ('ID #' . $id));
}
if (($filters['employee_id'] ?? null) !== null) {
    $id = (int) $filters['employee_id'];
    $activeFilters[] = $t('common.labels.employee') . ': ' . ($employeeNames[$id] ?? ('ID #' . $id));
}
?>
<div class="sapas-workspace-stack">
    <?php if ($errors !== []): ?>
        <div class="sapas-alert-warning">
            <p class="font-medium"><?= htmlspecialchars($t('reports_module.filter_error'), ENT_QUOTES, 'UTF-8') ?></p>
            <ul class="mt-2 list-disc space-y-1 pl-5 text-sm">
                <?php foreach ($errors as $error): ?><li><?= htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8') ?></li><?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <section class="sapas-report-insight-panel">
        <div>
            <p class="sapas-workspace-eyebrow"><?= htmlspecialchars($t('reports_module.current_scope'), ENT_QUOTES, 'UTF-8') ?></p>
            <h2 class="sapas-workspace-card-title"><?= htmlspecialchars($t('reports_module.absence_signals_title'), ENT_QUOTES, 'UTF-8') ?></h2>
            <p class="sapas-workspace-card-copy"><?= htmlspecialchars($t('reports_module.absence_signals_help'), ENT_QUOTES, 'UTF-8') ?></p>
        </div>
        <div class="sapas-report-insight-stats">
            <div class="sapas-report-insight-stat is-rose">
                <p class="sapas-report-summary-label"><?= htmlspecialchars($t('reports.total_absence_days'), ENT_QUOTES, 'UTF-8') ?></p>
                <p class="sapas-report-summary-value"><?= (int) $totalRecords ?></p>
            </div>
            <div class="sapas-report-insight-stat is-amber">
                <p class="sapas-report-summary-label"><?= htmlspecialchars($t('reports.affected_employees'), ENT_QUOTES, 'UTF-8') ?></p>
                <p class="sapas-report-summary-value"><?= (int) $affectedEmployees ?></p>
            </div>
        </div>
    </section>

    <div class="sapas-report-filter-panel">
        <form method="get" action="/admin/reports/absence" class="sapas-report-filter-grid sapas-polish-filter-grid is-five">
            <input type="hidden" name="per_page" value="<?= $perPage ?>">
            <div><label for="date_from" class="block text-sm font-medium text-slate-700"><?= htmlspecialchars($t('common.labels.date_from'), ENT_QUOTES, 'UTF-8') ?></label><input type="date" id="date_from" name="date_from" value="<?= htmlspecialchars((string) ($filters['date_from'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" class="sapas-form-control mt-2"></div>
            <div><label for="date_to" class="block text-sm font-medium text-slate-700"><?= htmlspecialchars($t('common.labels.date_to'), ENT_QUOTES, 'UTF-8') ?></label><input type="date" id="date_to" name="date_to" value="<?= htmlspecialchars((string) ($filters['date_to'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" class="sapas-form-control mt-2"></div>
            <div>
                <label for="department_id" class="block text-sm font-medium text-slate-700"><?= htmlspecialchars($t('common.labels.department'), ENT_QUOTES, 'UTF-8') ?></label>
                <?php if ($departmentScopeLocked): ?><input type="hidden" name="department_id" value="<?= (int) ($filters['department_id'] ?? 0) ?>"><?php endif; ?>
                <select id="department_id" name="<?= $departmentScopeLocked ? '' : 'department_id' ?>" class="sapas-form-control mt-2" <?= $departmentScopeLocked ? 'disabled aria-disabled="true"' : '' ?>>
                    <?php if (!$departmentScopeLocked): ?><option value=""><?= htmlspecialchars($t('reports_module.all_departments'), ENT_QUOTES, 'UTF-8') ?></option><?php endif; ?>
                    <?php foreach ($departments as $department): ?><option value="<?= (int) $department['id'] ?>" <?= (int) ($filters['department_id'] ?? 0) === (int) $department['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $department['name'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="employee_id" class="block text-sm font-medium text-slate-700"><?= htmlspecialchars($t('common.labels.employee'), ENT_QUOTES, 'UTF-8') ?></label>
                <select id="employee_id" name="employee_id" class="sapas-form-control mt-2">
                    <option value=""><?= htmlspecialchars($t('reports_module.all_employees'), ENT_QUOTES, 'UTF-8') ?></option>
                    <?php foreach ($employees as $employee): ?>
                        <?php $name = trim((string) ($employee['name'] ?? '')); $code = trim((string) ($employee['staff_code'] ?? '')); $label = $code !== '' ? $name . ' (' . $code . ')' : $name; ?>
                        <option value="<?= (int) $employee['id'] ?>" <?= (int) ($filters['employee_id'] ?? 0) === (int) $employee['id'] ? 'selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="sapas-filter-actions flex items-end gap-2"><button type="submit" class="sapas-btn sapas-btn-primary w-full"><?= htmlspecialchars($t('common.actions.apply_filters'), ENT_QUOTES, 'UTF-8') ?></button><a href="/admin/reports/absence" class="sapas-btn sapas-btn-secondary"><?= htmlspecialchars($t('common.actions.reset'), ENT_QUOTES, 'UTF-8') ?></a></div>
        </form>
        <div class="sapas-report-active-filters"><div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"><div><p class="text-sm font-medium text-slate-500"><?= htmlspecialchars($t('reports.active_filters'), ENT_QUOTES, 'UTF-8') ?></p><div class="mt-2 flex flex-wrap gap-2"><?php if ($activeFilters === []): ?><span class="sapas-chip sapas-chip-muted"><?= htmlspecialchars($t('reports.all_records'), ENT_QUOTES, 'UTF-8') ?></span><?php else: ?><?php foreach ($activeFilters as $filter): ?><span class="sapas-chip sapas-chip-info"><?= htmlspecialchars($filter, ENT_QUOTES, 'UTF-8') ?></span><?php endforeach; ?><?php endif; ?></div></div><p class="text-sm text-slate-600"><?= (int) $totalRecords ?> <?= htmlspecialchars($t('reports_module.matching_absences'), ENT_QUOTES, 'UTF-8') ?></p></div></div>
    </div>

    <div class="sapas-report-table-panel">
        <div class="sapas-report-table-header sapas-data-table-summary"><div class="sapas-report-meta-grid sapas-data-table-summary-grid"><div><p class="sapas-report-meta-label"><?= htmlspecialchars($t('reports_module.showing'), ENT_QUOTES, 'UTF-8') ?></p><p class="sapas-report-meta-value"><?= $visibleCount ?> <?= htmlspecialchars($t('reports_module.absences_on_page'), ENT_QUOTES, 'UTF-8') ?></p></div><div><p class="sapas-report-meta-label"><?= htmlspecialchars($t('reports.total_matches'), ENT_QUOTES, 'UTF-8') ?></p><p class="sapas-report-meta-value"><?= (int) $totalRecords ?> <?= htmlspecialchars($t('reports_module.matching_absences'), ENT_QUOTES, 'UTF-8') ?></p></div><div><p class="sapas-report-meta-label"><?= htmlspecialchars($t('reports_module.pages'), ENT_QUOTES, 'UTF-8') ?></p><p class="sapas-report-meta-value"><?= htmlspecialchars($t('reports.page'), ENT_QUOTES, 'UTF-8') ?> <?= (int) $page ?> of <?= (int) $pages ?></p></div></div></div>
        <?php if ($records === [] && $errors === []): ?>
            <div class="sapas-report-empty sapas-polish-empty-state"><p class="sapas-report-empty-title"><?= htmlspecialchars($t('absence.empty_title'), ENT_QUOTES, 'UTF-8') ?></p><p class="sapas-report-empty-copy"><?= htmlspecialchars($t('absence.empty_team_help'), ENT_QUOTES, 'UTF-8') ?></p></div>
        <?php elseif ($records !== []): ?>
            <div class="sapas-data-table-scroll overflow-x-auto">
                <table class="sapas-data-table divide-y divide-slate-200"><thead><tr><th class="sapas-col-row-number"><?= htmlspecialchars($t('common.labels.no'), ENT_QUOTES, 'UTF-8') ?></th><th class="sapas-col-employee"><?= htmlspecialchars($t('common.labels.employee'), ENT_QUOTES, 'UTF-8') ?></th><th class="sapas-col-date"><?= htmlspecialchars($t('common.labels.date'), ENT_QUOTES, 'UTF-8') ?></th><th class="sapas-col-status"><?= htmlspecialchars($t('common.labels.status'), ENT_QUOTES, 'UTF-8') ?></th><th><?= htmlspecialchars($t('attendance.remarks'), ENT_QUOTES, 'UTF-8') ?></th></tr></thead>
                    <tbody class="divide-y divide-slate-200 bg-white">
                    <?php foreach ($records as $index => $record): ?>
                        <?php $name = trim((string) ($record['employee_name'] ?? '')); $code = trim((string) ($record['staff_code'] ?? '')); $identity = $code !== '' ? $name . ' (' . $code . ')' : $name; $date = (string) ($record['attendance_date'] ?? ''); $timestamp = strtotime($date); ?>
                        <tr><td><span class="sapas-row-number"><?= $rowNumberStart + (int) $index ?></span></td><td><div class="sapas-person-cell"><p class="sapas-person-name font-semibold text-slate-900"><?= htmlspecialchars($identity, ENT_QUOTES, 'UTF-8') ?></p><p class="sapas-person-email"><?= htmlspecialchars((string) ($record['employee_email'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p><p class="sapas-person-email"><?= htmlspecialchars((string) ($record['department_name'] ?? $t('reports_module.no_department')), ENT_QUOTES, 'UTF-8') ?></p></div></td><td><div class="sapas-date-cell"><p class="font-medium text-slate-900"><?= htmlspecialchars($timestamp !== false ? date('M j, Y', $timestamp) : $date, ENT_QUOTES, 'UTF-8') ?></p><p class="text-xs text-slate-500"><?= htmlspecialchars($timestamp !== false ? date('l', $timestamp) : '', ENT_QUOTES, 'UTF-8') ?></p></div></td><td><span class="sapas-badge sapas-badge-danger"><?= htmlspecialchars($t('statuses.attendance.absent'), ENT_QUOTES, 'UTF-8') ?></span></td><td><span class="text-sm text-slate-600"><?= htmlspecialchars(trim((string) ($record['remarks'] ?? '')) ?: $t('common.none'), ENT_QUOTES, 'UTF-8') ?></span></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php $paginationPath = '/admin/reports/absence'; require __DIR__ . '/../partials/pagination.php'; ?>
        <?php endif; ?>
    </div>
</div>
