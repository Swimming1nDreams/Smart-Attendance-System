<?php
$t = $t ?? static fn (string $key): string => $key;
if (!isset($title)) {
    $title = $t('reports_module.attendance_summary');
}
if (!isset($errors)) {
    $errors = [];
}
if (!isset($records)) {
    $records = [];
}
if (!isset($totals)) {
    $totals = [
        'total_records' => 0,
        'present_count' => 0,
        'late_count' => 0,
        'absent_count' => 0,
        'total_work_minutes' => 0,
        'total_work_duration' => strtr($t('common.duration_formats.minutes'), ['{minutes}' => '0']),
        'total_overtime_minutes' => 0,
    ];
}
if (!isset($departments)) {
    $departments = [];
}
if (!isset($employees)) {
    $employees = [];
}
if (!isset($filters)) {
    $filters = [
        'date_from' => '',
        'date_to' => '',
        'department_id' => null,
        'employee_id' => null,
    ];
}
if (!isset($page)) {
    $page = 1;
}
if (!isset($perPage)) {
    $perPage = 20;
}
if (!isset($totalRecords)) {
    $totalRecords = 0;
}

$layout = 'layouts/app';
$pageTitle = $pageTitle ?? $t('reports_module.attendance_summary');
$pageDescription = $pageDescription ?? $t('reports_module.attendance_summary_description');
$pageActions = '<a href="/admin/reports" class="sapas-btn sapas-btn-secondary">' . htmlspecialchars($t('reports_module.back_to_reports'), ENT_QUOTES, 'UTF-8') . '</a>';

$pages = max(1, (int) ceil($totalRecords / $perPage));
$visibleCount = count($records);
$rowNumberStart = ((max(1, (int) $page) - 1) * max(1, (int) $perPage)) + 1;
$paginationQuery = array_filter([
    'date_from' => (string) ($filters['date_from'] ?? ''),
    'date_to' => (string) ($filters['date_to'] ?? ''),
    'department_id' => $filters['department_id'] !== null ? (string) $filters['department_id'] : '',
    'employee_id' => $filters['employee_id'] !== null ? (string) $filters['employee_id'] : '',
], static fn (string $value): bool => $value !== '');
$pdfExportUrl = '/admin/reports/attendance-summary/pdf' . ($paginationQuery === [] ? '' : '?' . http_build_query($paginationQuery));
$excelExportUrl = '/admin/reports/attendance-summary/excel' . ($paginationQuery === [] ? '' : '?' . http_build_query($paginationQuery));
$paginationQuery['per_page'] = (string) $perPage;
$paginationPageSizeOptions = [20, 50, 100];
$pageActions = '<div class="sapas-workspace-actions sapas-export-actions"><a href="/admin/reports" class="sapas-btn sapas-btn-secondary">' . htmlspecialchars($t('reports_module.back_to_reports'), ENT_QUOTES, 'UTF-8') . '</a><a href="' . htmlspecialchars($excelExportUrl, ENT_QUOTES, 'UTF-8') . '" class="sapas-btn sapas-btn-success">' . htmlspecialchars($t('common.actions.export_excel'), ENT_QUOTES, 'UTF-8') . '</a><a href="' . htmlspecialchars($pdfExportUrl, ENT_QUOTES, 'UTF-8') . '" class="sapas-btn sapas-btn-danger">' . htmlspecialchars($t('reports.export_pdf'), ENT_QUOTES, 'UTF-8') . '</a></div>';

function reportAttendanceStatusLabel(string $status): string
{
    return \App\Core\Localization::current()->translate('statuses.attendance.' . $status);
}

function reportAttendanceStatusClasses(string $status): string
{
    return match ($status) {
        'present' => 'sapas-badge sapas-badge-success',
        'late' => 'sapas-badge sapas-badge-warning',
        'absent' => 'sapas-badge sapas-badge-danger',
        'holiday' => 'sapas-badge sapas-badge-info',
        'weekend' => 'sapas-badge sapas-badge-neutral',
        default => 'sapas-badge sapas-badge-neutral',
    };
}

function reportOvertimeMinutes(mixed $minutes): string
{
    $minutes = max(0, (int) $minutes);

    if ($minutes === 0) {
        return '0';
    }

    $hours = intdiv($minutes, 60);
    $remainingMinutes = $minutes % 60;

    if ($hours === 0) {
        return (string) $minutes;
    }

    return $remainingMinutes === 0
        ? $hours . 'h'
        : $hours . 'h ' . $remainingMinutes . 'm';
}

function reportChartWidth(float $value, float $max): string
{
    if ($max <= 0 || $value <= 0) {
        return '0%';
    }

    return number_format(min(100, max(2, ($value / $max) * 100)), 2, '.', '') . '%';
}

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
    $departmentId = (int) $filters['department_id'];
    $activeFilters[] = $t('common.labels.department') . ': ' . ($departmentNames[$departmentId] ?? ('ID #' . $departmentId));
}
if (($filters['employee_id'] ?? null) !== null) {
    $employeeId = (int) $filters['employee_id'];
    $activeFilters[] = $t('common.labels.employee') . ': ' . ($employeeNames[$employeeId] ?? ('ID #' . $employeeId));
}

$attendanceChartRows = [
    [
        'label' => $t('reports_module.on_time_present'),
        'value' => max(0, (int) ($totals['present_count'] ?? 0) - (int) ($totals['late_count'] ?? 0)),
        'class' => 'is-emerald',
    ],
    [
        'label' => $t('reports_module.late_present'),
        'value' => (int) ($totals['late_count'] ?? 0),
        'class' => 'is-amber',
    ],
    [
        'label' => $t('statuses.attendance.absent'),
        'value' => (int) ($totals['absent_count'] ?? 0),
        'class' => 'is-rose',
    ],
];
$attendanceTotal = max(1, (int) ($totals['present_count'] ?? 0) + (int) ($totals['absent_count'] ?? 0));
$attendanceChartMax = max(1, ...array_map(static fn (array $row): int => (int) $row['value'], $attendanceChartRows));
$presentRate = ((int) ($totals['present_count'] ?? 0) / $attendanceTotal) * 100;
$exceptionRate = (((int) ($totals['late_count'] ?? 0) + (int) ($totals['absent_count'] ?? 0)) / $attendanceTotal) * 100;
$hoursPerRecord = ((int) ($totals['total_records'] ?? 0)) > 0
    ? (((int) ($totals['total_work_minutes'] ?? 0) / 60) / max(1, (int) ($totals['total_records'] ?? 0)))
    : 0.0;
?>
<div class="sapas-workspace-stack">
    <?php if (!empty($errors)): ?>
        <div class="sapas-alert-warning">
            <p class="font-medium"><?= htmlspecialchars($t('reports_module.filter_error'), ENT_QUOTES, 'UTF-8') ?></p>
            <ul class="mt-2 list-disc space-y-1 pl-5 text-sm">
                <?php foreach ($errors as $error): ?>
                    <li><?= htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8') ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <section class="sapas-report-insight-panel">
        <div>
            <p class="sapas-workspace-eyebrow"><?= htmlspecialchars($t('reports_module.current_scope'), ENT_QUOTES, 'UTF-8') ?></p>
            <h2 class="sapas-workspace-card-title"><?= htmlspecialchars($t('reports_module.attendance_signals_title'), ENT_QUOTES, 'UTF-8') ?></h2>
            <p class="sapas-workspace-card-copy"><?= htmlspecialchars($t('reports_module.attendance_signals_help'), ENT_QUOTES, 'UTF-8') ?></p>
        </div>
        <div class="sapas-report-insight-stats">
            <div class="sapas-report-insight-stat is-emerald">
                <p class="sapas-report-summary-label"><?= htmlspecialchars($t('reports_module.present_rate'), ENT_QUOTES, 'UTF-8') ?></p>
                <p class="sapas-report-summary-value"><?= number_format($presentRate, 1) ?>%</p>
            </div>
            <div class="sapas-report-insight-stat is-amber">
                <p class="sapas-report-summary-label"><?= htmlspecialchars($t('reports_module.exception_rate'), ENT_QUOTES, 'UTF-8') ?></p>
                <p class="sapas-report-summary-value"><?= number_format($exceptionRate, 1) ?>%</p>
            </div>
            <div class="sapas-report-insight-stat">
                <p class="sapas-report-summary-label"><?= htmlspecialchars($t('reports_module.average_hours_per_record'), ENT_QUOTES, 'UTF-8') ?></p>
                <p class="sapas-report-summary-value"><?= number_format($hoursPerRecord, 1) ?></p>
            </div>
        </div>
    </section>

    <div class="sapas-report-filter-panel">
        <form method="get" action="/admin/reports/attendance-summary" class="sapas-report-filter-grid sapas-polish-filter-grid is-five">
            <input type="hidden" name="per_page" value="<?= $perPage ?>">
            <div>
                <label for="date_from" class="block text-sm font-medium text-slate-700"><?= htmlspecialchars($t('common.labels.date_from'), ENT_QUOTES, 'UTF-8') ?></label>
                <input type="date" id="date_from" name="date_from" value="<?= htmlspecialchars((string) ($filters['date_from'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" class="sapas-form-control mt-2">
            </div>
            <div>
                <label for="date_to" class="block text-sm font-medium text-slate-700"><?= htmlspecialchars($t('common.labels.date_to'), ENT_QUOTES, 'UTF-8') ?></label>
                <input type="date" id="date_to" name="date_to" value="<?= htmlspecialchars((string) ($filters['date_to'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" class="sapas-form-control mt-2">
            </div>
            <div>
                <label for="department_id" class="block text-sm font-medium text-slate-700"><?= htmlspecialchars($t('common.labels.department'), ENT_QUOTES, 'UTF-8') ?></label>
                <select id="department_id" name="department_id" class="sapas-form-control mt-2">
                    <option value=""><?= htmlspecialchars($t('reports_module.all_departments'), ENT_QUOTES, 'UTF-8') ?></option>
                    <?php foreach ($departments as $department): ?>
                        <option value="<?= (int) $department['id'] ?>" <?= (int) ($filters['department_id'] ?? 0) === (int) $department['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars((string) $department['name'], ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="employee_id" class="block text-sm font-medium text-slate-700"><?= htmlspecialchars($t('common.labels.employee'), ENT_QUOTES, 'UTF-8') ?></label>
                <select id="employee_id" name="employee_id" class="sapas-form-control mt-2">
                    <option value=""><?= htmlspecialchars($t('reports_module.all_employees'), ENT_QUOTES, 'UTF-8') ?></option>
                    <?php foreach ($employees as $employee): ?>
                        <option value="<?= (int) $employee['id'] ?>" <?= (int) ($filters['employee_id'] ?? 0) === (int) $employee['id'] ? 'selected' : '' ?>>
                            <?php
                            $employeeName = trim((string) ($employee['name'] ?? ''));
                            $staffCode = trim((string) ($employee['staff_code'] ?? ''));
                            $employeeIdentity = $staffCode !== '' ? $employeeName . ' (' . $staffCode . ')' : $employeeName;
                            $employeeEmail = trim((string) ($employee['email'] ?? ''));
                            ?>
                            <?= htmlspecialchars($employeeEmail !== '' ? $employeeIdentity . ' — ' . $employeeEmail : $employeeIdentity, ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="sapas-filter-actions flex items-end gap-2">
                <button type="submit" class="sapas-btn sapas-btn-primary w-full"><?= htmlspecialchars($t('common.actions.apply_filters'), ENT_QUOTES, 'UTF-8') ?></button>
                <a href="/admin/reports/attendance-summary" class="sapas-btn sapas-btn-secondary"><?= htmlspecialchars($t('common.actions.reset'), ENT_QUOTES, 'UTF-8') ?></a>
            </div>
        </form>

        <div class="sapas-report-active-filters">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <p class="text-sm font-medium text-slate-500"><?= htmlspecialchars($t('reports.active_filters'), ENT_QUOTES, 'UTF-8') ?></p>
                    <div class="mt-2 flex flex-wrap gap-2">
                        <?php if (empty($activeFilters)): ?>
                            <span class="sapas-chip sapas-chip-muted"><?= htmlspecialchars($t('attendance.title'), ENT_QUOTES, 'UTF-8') ?></span>
                        <?php else: ?>
                            <?php foreach ($activeFilters as $filter): ?>
                                <span class="sapas-chip sapas-chip-info"><?= htmlspecialchars($filter, ENT_QUOTES, 'UTF-8') ?></span>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <p class="text-sm text-slate-600"><?= (int) $totalRecords ?> <?= htmlspecialchars($t('reports_module.matching_records'), ENT_QUOTES, 'UTF-8') ?></p>
            </div>
        </div>
    </div>

    <div class="sapas-report-summary-grid">
        <div class="sapas-report-summary-card">
            <p class="sapas-report-summary-label"><?= htmlspecialchars($t('reports.total_records'), ENT_QUOTES, 'UTF-8') ?></p>
            <p class="sapas-report-summary-value text-sky-600"><?= (int) ($totals['total_records'] ?? 0) ?></p>
        </div>
        <div class="sapas-report-summary-card">
            <p class="sapas-report-summary-label"><?= htmlspecialchars($t('reports.total_attended_including_late'), ENT_QUOTES, 'UTF-8') ?></p>
            <p class="sapas-report-summary-value text-emerald-600"><?= (int) ($totals['present_count'] ?? 0) ?></p>
        </div>
        <div class="sapas-report-summary-card">
            <p class="sapas-report-summary-label"><?= htmlspecialchars($t('common.labels.late'), ENT_QUOTES, 'UTF-8') ?></p>
            <p class="sapas-report-summary-value text-amber-600"><?= (int) ($totals['late_count'] ?? 0) ?></p>
        </div>
        <div class="sapas-report-summary-card">
            <p class="sapas-report-summary-label"><?= htmlspecialchars($t('statuses.attendance.absent'), ENT_QUOTES, 'UTF-8') ?></p>
            <p class="sapas-report-summary-value text-rose-600"><?= (int) ($totals['absent_count'] ?? 0) ?></p>
        </div>
        <div class="sapas-report-summary-card">
            <p class="sapas-report-summary-label"><?= htmlspecialchars($t('reports.total_work_duration'), ENT_QUOTES, 'UTF-8') ?></p>
            <p class="sapas-report-summary-value text-indigo-600"><?= htmlspecialchars((string) ($totals['total_work_duration'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
        </div>
        <div class="sapas-report-summary-card">
            <p class="sapas-report-summary-label"><?= htmlspecialchars($t('common.labels.overtime'), ENT_QUOTES, 'UTF-8') ?></p>
            <p class="sapas-report-summary-value text-amber-600"><?= htmlspecialchars(reportOvertimeMinutes($totals['total_overtime_minutes'] ?? 0), ENT_QUOTES, 'UTF-8') ?></p>
        </div>
    </div>

    <section class="sapas-chart-card sapas-report-visual-card">
        <div class="sapas-chart-card-header">
            <div>
                <h2 class="sapas-chart-title"><?= htmlspecialchars($t('reports_module.chart_attendance_composition'), ENT_QUOTES, 'UTF-8') ?></h2>
                <p class="sapas-chart-copy"><?= htmlspecialchars($t('reports_module.chart_attendance_composition_help'), ENT_QUOTES, 'UTF-8') ?></p>
            </div>
            <span class="sapas-chip sapas-chip-info"><?= htmlspecialchars($t('reports_module.chart_visual_summary'), ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <div class="sapas-report-distribution" aria-hidden="true">
            <?php foreach ($attendanceChartRows as $row): ?>
                <span class="sapas-report-distribution-segment <?= htmlspecialchars((string) $row['class'], ENT_QUOTES, 'UTF-8') ?>" style="width: <?= reportChartWidth((float) $row['value'], (float) $attendanceTotal) ?>"></span>
            <?php endforeach; ?>
        </div>
        <div class="sapas-chart-list">
            <?php foreach ($attendanceChartRows as $row): ?>
                <div class="sapas-chart-row">
                    <div class="sapas-chart-row-meta">
                        <span class="sapas-chart-row-label"><?= htmlspecialchars((string) $row['label'], ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="sapas-chart-row-value"><?= (int) $row['value'] ?></span>
                    </div>
                    <div class="sapas-chart-track">
                        <div class="sapas-chart-bar <?= htmlspecialchars((string) $row['class'], ENT_QUOTES, 'UTF-8') ?>" style="width: <?= reportChartWidth((float) $row['value'], (float) $attendanceChartMax) ?>"></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <div class="sapas-report-table-panel">
        <div class="sapas-report-table-header sapas-data-table-summary">
            <div class="sapas-report-meta-grid sapas-data-table-summary-grid">
                <div>
                    <p class="sapas-report-meta-label"><?= htmlspecialchars($t('reports_module.showing'), ENT_QUOTES, 'UTF-8') ?></p>
                    <p class="sapas-report-meta-value"><?= $visibleCount ?> <?= htmlspecialchars($t('reports_module.records_on_page'), ENT_QUOTES, 'UTF-8') ?></p>
                </div>
                <div>
                    <p class="sapas-report-meta-label"><?= htmlspecialchars($t('reports.total_matches'), ENT_QUOTES, 'UTF-8') ?></p>
                    <p class="sapas-report-meta-value"><?= (int) $totalRecords ?> <?= htmlspecialchars($t('reports_module.matching_records'), ENT_QUOTES, 'UTF-8') ?></p>
                </div>
                <div>
                    <p class="sapas-report-meta-label"><?= htmlspecialchars($t('reports_module.pages'), ENT_QUOTES, 'UTF-8') ?></p>
                    <p class="sapas-report-meta-value"><?= htmlspecialchars($t('reports.page'), ENT_QUOTES, 'UTF-8') ?> <?= (int) $page ?> of <?= (int) $pages ?></p>
                </div>
            </div>
        </div>

        <?php if (empty($records) && empty($errors)): ?>
            <div class="sapas-report-empty sapas-polish-empty-state">
                <p class="sapas-report-empty-title"><?= htmlspecialchars($t('attendance.empty_title'), ENT_QUOTES, 'UTF-8') ?></p>
                <p class="sapas-report-empty-copy"><?= htmlspecialchars($t('common.empty.try_adjusting_filters'), ENT_QUOTES, 'UTF-8') ?></p>
            </div>
        <?php elseif (!empty($records)): ?>
            <div class="sapas-data-table-scroll overflow-x-auto">
                <table class="sapas-data-table sapas-report-attendance-table divide-y divide-slate-200">
                    <thead>
                        <tr>
                            <th class="sapas-col-row-number"><?= htmlspecialchars($t('common.labels.no'), ENT_QUOTES, 'UTF-8') ?></th>
                            <th class="sapas-col-date"><?= htmlspecialchars($t('common.labels.date'), ENT_QUOTES, 'UTF-8') ?></th>
                            <th class="sapas-col-employee"><?= htmlspecialchars($t('common.labels.employee'), ENT_QUOTES, 'UTF-8') ?></th>
                            <th class="sapas-col-department"><?= htmlspecialchars($t('common.labels.department'), ENT_QUOTES, 'UTF-8') ?></th>
                            <th class="sapas-col-status"><?= htmlspecialchars($t('common.labels.status'), ENT_QUOTES, 'UTF-8') ?></th>
                            <th class="sapas-col-time"><?= htmlspecialchars($t('dashboard.common.clock_in_time'), ENT_QUOTES, 'UTF-8') ?></th>
                            <th class="sapas-col-time"><?= htmlspecialchars($t('dashboard.common.clock_out_time'), ENT_QUOTES, 'UTF-8') ?></th>
                            <th class="sapas-col-number"><?= htmlspecialchars($t('common.labels.late'), ENT_QUOTES, 'UTF-8') ?></th>
                            <th class="sapas-col-duration"><?= htmlspecialchars($t('common.labels.hours'), ENT_QUOTES, 'UTF-8') ?></th>
                            <th class="sapas-col-overtime"><?= htmlspecialchars($t('common.labels.overtime'), ENT_QUOTES, 'UTF-8') ?></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 bg-white">
                        <?php foreach ($records as $index => $record): ?>
                            <?php
                            $employeeName = trim((string) ($record['employee_name'] ?? ''));
                            $employeeEmail = trim((string) ($record['employee_email'] ?? ''));
                            $staffCode = trim((string) ($record['staff_code'] ?? ''));
                            $employeeIdentity = $staffCode !== '' ? $employeeName . ' (' . $staffCode . ')' : $employeeName;
                            $departmentName = trim((string) ($record['department_name'] ?? $t('reports_module.no_department')));
                            $attendanceDate = (string) ($record['attendance_date'] ?? '');
                            ?>
                            <tr>
                                <td><span class="sapas-row-number"><?= $rowNumberStart + (int) $index ?></span></td>
                                <td>
                                    <div class="sapas-date-cell">
                                        <p class="sapas-table-value-nowrap font-medium text-slate-900"><?= htmlspecialchars(date('M j, Y', strtotime($attendanceDate)), ENT_QUOTES, 'UTF-8') ?></p>
                                        <p class="text-xs text-slate-500"><?= htmlspecialchars(date('l', strtotime($attendanceDate)), ENT_QUOTES, 'UTF-8') ?></p>
                                    </div>
                                </td>
                                <td>
                                    <div class="sapas-person-cell">
                                        <div class="sapas-person-cell-head">
                                            <p class="sapas-person-name font-semibold text-slate-900" title="<?= htmlspecialchars($employeeIdentity, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($employeeIdentity, ENT_QUOTES, 'UTF-8') ?></p>
                                        </div>
                                        <p class="sapas-person-email" title="<?= htmlspecialchars($employeeEmail, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($employeeEmail, ENT_QUOTES, 'UTF-8') ?></p>
                                    </div>
                                </td>
                                <td><p class="sapas-table-text-cell" title="<?= htmlspecialchars($departmentName, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($departmentName, ENT_QUOTES, 'UTF-8') ?></p></td>
                                <td>
                                    <span class="<?= reportAttendanceStatusClasses((string) ($record['attendance_display_status'] ?? $record['status'])) ?>">
                                        <?= htmlspecialchars(reportAttendanceStatusLabel((string) ($record['attendance_display_status'] ?? $record['status'])), ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                </td>
                                <td><span class="sapas-table-value-nowrap text-sm text-slate-600"><?= htmlspecialchars((string) ($record['clock_in_time'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></span></td>
                                <td><span class="sapas-table-value-nowrap text-sm text-slate-600"><?= htmlspecialchars((string) ($record['clock_out_time'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></span></td>
                                <td><span class="sapas-table-number text-sm text-slate-600"><?= htmlspecialchars($record['late_duration'] ?? '0 min', ENT_QUOTES, 'UTF-8') ?></span></td>
                                <td><span class="sapas-table-value-nowrap text-sm text-slate-600"><?= htmlspecialchars((string) ($record['working_duration'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></span></td>
                                <td><span class="sapas-table-value-nowrap text-sm text-slate-600"><?= htmlspecialchars((string) ($record['overtime_duration'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php $paginationPath = '/admin/reports/attendance-summary'; ?>
            <?php require __DIR__ . '/../partials/pagination.php'; ?>
        <?php endif; ?>
    </div>
</div>
