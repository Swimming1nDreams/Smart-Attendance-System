<?php
$t = $t ?? static fn (string $key): string => $key;
if (!isset($title)) {
    $title = $t('reports_module.employee_attendance_detail');
}
if (!isset($errors)) {
    $errors = [];
}
if (!isset($records)) {
    $records = [];
}
if (!isset($approvedLeaveRequests)) {
    $approvedLeaveRequests = [];
}
if (!isset($approvedLeaveByDate)) {
    $approvedLeaveByDate = [];
}
if (!isset($selectedEmployee)) {
    $selectedEmployee = null;
}
if (!isset($employees)) {
    $employees = [];
}
if (!isset($filters)) {
    $filters = [
        'employee_id' => null,
        'date_from' => '',
        'date_to' => '',
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
$pageTitle = $pageTitle ?? $t('reports_module.employee_attendance_detail');
$pageDescription = $pageDescription ?? $t('reports_module.employee_attendance_detail_description');
$pageActions = '<a href="/admin/reports" class="sapas-btn sapas-btn-secondary">' . htmlspecialchars($t('reports_module.back_to_reports'), ENT_QUOTES, 'UTF-8') . '</a>';

$pages = max(1, (int) ceil($totalRecords / $perPage));
$visibleCount = count($records);
$rowNumberStart = ((max(1, (int) $page) - 1) * max(1, (int) $perPage)) + 1;
$hasEmployee = $selectedEmployee !== null;
$hasAnyContext = !empty($records) || !empty($approvedLeaveRequests);
$paginationQuery = array_filter([
    'employee_id' => $filters['employee_id'] !== null ? (string) $filters['employee_id'] : '',
    'date_from' => (string) ($filters['date_from'] ?? ''),
    'date_to' => (string) ($filters['date_to'] ?? ''),
], static fn (string $value): bool => $value !== '');
$pdfExportUrl = '/admin/reports/employee-attendance-detail/pdf' . ($paginationQuery === [] ? '' : '?' . http_build_query($paginationQuery));
$excelExportUrl = '/admin/reports/employee-attendance-detail/excel' . ($paginationQuery === [] ? '' : '?' . http_build_query($paginationQuery));
$paginationQuery['per_page'] = (string) $perPage;
$paginationPageSizeOptions = [20, 50, 100];
$pageActions = '<div class="sapas-workspace-actions sapas-export-actions"><a href="/admin/reports" class="sapas-btn sapas-btn-secondary">' . htmlspecialchars($t('reports_module.back_to_reports'), ENT_QUOTES, 'UTF-8') . '</a><a href="' . htmlspecialchars($excelExportUrl, ENT_QUOTES, 'UTF-8') . '" class="sapas-btn sapas-btn-success">' . htmlspecialchars($t('common.actions.export_excel'), ENT_QUOTES, 'UTF-8') . '</a><a href="' . htmlspecialchars($pdfExportUrl, ENT_QUOTES, 'UTF-8') . '" class="sapas-btn sapas-btn-danger">' . htmlspecialchars($t('common.actions.export_pdf'), ENT_QUOTES, 'UTF-8') . '</a></div>';

function employeeDetailStatusLabel(string $status): string
{
    return \App\Core\Localization::current()->translate('statuses.attendance.' . $status);
}

function employeeDetailStatusClasses(string $status): string
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

function employeeDetailLeaveTypeLabel(string $leaveType): string
{
    return \App\Core\Localization::current()->translate('leave.types.' . $leaveType);
}

$employeeNames = [];
foreach ($employees as $employee) {
    $employeeNames[(int) $employee['id']] = (string) $employee['name'];
}

$activeFilters = [];
if (($filters['employee_id'] ?? null) !== null) {
    $activeEmployeeId = (int) $filters['employee_id'];
    $activeFilters[] = $t('common.labels.employee') . ': ' . ($employeeNames[$activeEmployeeId] ?? ('ID #' . $activeEmployeeId));
}
if (($filters['date_from'] ?? '') !== '') {
    $activeFilters[] = $t('common.labels.date_from') . ': ' . (string) $filters['date_from'];
}
if (($filters['date_to'] ?? '') !== '') {
    $activeFilters[] = $t('common.labels.date_to') . ': ' . (string) $filters['date_to'];
}
?>
<div class="sapas-workspace-stack">
    <?php if (!empty($errors)): ?>
        <div class="sapas-alert-warning">
            <p class="font-medium"><?= htmlspecialchars($t('reports_module.review_filters'), ENT_QUOTES, 'UTF-8') ?></p>
            <ul class="mt-2 list-disc space-y-1 pl-5 text-sm">
                <?php foreach ($errors as $error): ?>
                    <li><?= htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8') ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="sapas-report-filter-panel">
        <form method="get" action="/admin/reports/employee-attendance-detail" class="sapas-report-filter-grid sapas-polish-filter-grid is-four">
            <input type="hidden" name="per_page" value="<?= $perPage ?>">
            <div>
                <label for="employee_id" class="block text-sm font-medium text-slate-700"><?= htmlspecialchars($t('common.labels.employee'), ENT_QUOTES, 'UTF-8') ?></label>
                <select id="employee_id" name="employee_id" required class="sapas-form-control mt-2">
                    <option value=""><?= htmlspecialchars($t('reports_module.choose_employee'), ENT_QUOTES, 'UTF-8') ?></option>
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
            <div>
                <label for="date_from" class="block text-sm font-medium text-slate-700"><?= htmlspecialchars($t('common.labels.date_from'), ENT_QUOTES, 'UTF-8') ?></label>
                <input type="date" id="date_from" name="date_from" value="<?= htmlspecialchars((string) ($filters['date_from'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" class="sapas-form-control mt-2">
            </div>
            <div>
                <label for="date_to" class="block text-sm font-medium text-slate-700"><?= htmlspecialchars($t('common.labels.date_to'), ENT_QUOTES, 'UTF-8') ?></label>
                <input type="date" id="date_to" name="date_to" value="<?= htmlspecialchars((string) ($filters['date_to'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" class="sapas-form-control mt-2">
            </div>
            <div class="sapas-filter-actions flex items-end">
                <button type="submit" class="sapas-btn sapas-btn-primary w-full"><?= htmlspecialchars($t('common.actions.apply_filters'), ENT_QUOTES, 'UTF-8') ?></button>
                <a href="/admin/reports/employee-attendance-detail" class="sapas-btn sapas-btn-secondary"><?= htmlspecialchars($t('common.actions.reset'), ENT_QUOTES, 'UTF-8') ?></a>
            </div>
        </form>

        <div class="sapas-report-active-filters">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <p class="text-sm font-medium text-slate-500"><?= htmlspecialchars($t('common.labels.active_filters'), ENT_QUOTES, 'UTF-8') ?></p>
                    <div class="mt-2 flex flex-wrap gap-2">
                        <?php if (empty($activeFilters)): ?>
                            <span class="sapas-chip sapas-chip-muted"><?= htmlspecialchars($t('reports_module.choose_employee'), ENT_QUOTES, 'UTF-8') ?></span>
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

    <?php if (!$hasEmployee): ?>
        <div class="sapas-report-empty sapas-polish-empty-state">
            <p class="sapas-report-empty-title"><?= htmlspecialchars($t('reports_module.choose_employee_prompt'), ENT_QUOTES, 'UTF-8') ?></p>
            <p class="sapas-report-empty-copy"><?= htmlspecialchars($t('reports_module.employee_filter_required_help'), ENT_QUOTES, 'UTF-8') ?></p>
        </div>
    <?php endif; ?>

    <?php if ($hasEmployee): ?>
        <div class="sapas-workspace-card">
            <div class="sapas-report-meta-grid">
                <div>
                    <p class="sapas-report-meta-label"><?= htmlspecialchars($t('common.labels.employee'), ENT_QUOTES, 'UTF-8') ?></p>
                    <?php
                    $selectedName = trim((string) ($selectedEmployee['name'] ?? ''));
                    $selectedCode = trim((string) ($selectedEmployee['staff_code'] ?? ''));
                    $selectedIdentity = $selectedCode !== '' ? $selectedName . ' (' . $selectedCode . ')' : $selectedName;
                    ?>
                    <p class="sapas-report-meta-value" title="<?= htmlspecialchars($selectedIdentity, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($selectedIdentity, ENT_QUOTES, 'UTF-8') ?></p>
                    <p class="mt-1 text-sm text-slate-500"><?= htmlspecialchars((string) $selectedEmployee['email'], ENT_QUOTES, 'UTF-8') ?></p>
                </div>
                <div>
                    <p class="sapas-report-meta-label"><?= htmlspecialchars($t('common.labels.department'), ENT_QUOTES, 'UTF-8') ?></p>
                    <p class="sapas-report-meta-value"><?= htmlspecialchars((string) ($selectedEmployee['department_name'] ?? $t('reports_module.no_department')), ENT_QUOTES, 'UTF-8') ?></p>
                </div>
                <div>
                    <p class="sapas-report-meta-label"><?= htmlspecialchars($t('reports_module.date_range'), ENT_QUOTES, 'UTF-8') ?></p>
                    <p class="sapas-report-meta-value">
                        <?= htmlspecialchars((string) ($filters['date_from'] ?: $t('reports_module.any_start')), ENT_QUOTES, 'UTF-8') ?>
                        <?= htmlspecialchars($t('analytics_module.date_range_separator'), ENT_QUOTES, 'UTF-8') ?>
                        <?= htmlspecialchars((string) ($filters['date_to'] ?: $t('reports_module.any_end')), ENT_QUOTES, 'UTF-8') ?>
                    </p>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($hasEmployee && !$hasAnyContext && empty($errors)): ?>
        <div class="sapas-report-empty sapas-polish-empty-state">
            <p class="sapas-report-empty-title"><?= htmlspecialchars($t('reports_module.no_attendance_or_leave'), ENT_QUOTES, 'UTF-8') ?></p>
            <p class="sapas-report-empty-copy"><?= htmlspecialchars($t('common.empty.try_adjusting_filters'), ENT_QUOTES, 'UTF-8') ?></p>
        </div>
    <?php endif; ?>

    <?php if (!empty($records)): ?>
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

            <div class="sapas-data-table-scroll overflow-x-auto">
                <table class="sapas-data-table sapas-report-employee-attendance-table divide-y divide-slate-200">
                    <thead>
                        <tr>
                            <th class="sapas-col-row-number"><?= htmlspecialchars($t('common.labels.no'), ENT_QUOTES, 'UTF-8') ?></th>
                            <th class="sapas-col-date"><?= htmlspecialchars($t('common.labels.date'), ENT_QUOTES, 'UTF-8') ?></th>
                            <th class="sapas-col-status"><?= htmlspecialchars($t('common.labels.status'), ENT_QUOTES, 'UTF-8') ?></th>
                            <th class="sapas-col-time"><?= htmlspecialchars($t('common.labels.clock_in'), ENT_QUOTES, 'UTF-8') ?></th>
                            <th class="sapas-col-time"><?= htmlspecialchars($t('common.labels.clock_out'), ENT_QUOTES, 'UTF-8') ?></th>
                            <th class="sapas-col-number"><?= htmlspecialchars($t('common.labels.late'), ENT_QUOTES, 'UTF-8') ?></th>
                            <th class="sapas-col-duration"><?= htmlspecialchars($t('common.labels.hours'), ENT_QUOTES, 'UTF-8') ?></th>
                            <th class="sapas-col-overtime"><?= htmlspecialchars($t('common.labels.overtime'), ENT_QUOTES, 'UTF-8') ?></th>
                            <th class="sapas-col-report-leave"><?= htmlspecialchars($t('common.labels.approved_leave'), ENT_QUOTES, 'UTF-8') ?></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 bg-white">
                        <?php foreach ($records as $index => $record): ?>
                            <?php
                            $attendanceDate = (string) $record['attendance_date'];
                            $leaveContexts = $approvedLeaveByDate[$attendanceDate] ?? [];
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
                                    <span class="<?= employeeDetailStatusClasses((string) ($record['attendance_display_status'] ?? $record['status'])) ?>">
                                        <?= htmlspecialchars(employeeDetailStatusLabel((string) ($record['attendance_display_status'] ?? $record['status'])), ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                </td>
                                <td><span class="sapas-table-value-nowrap text-sm text-slate-600"><?= htmlspecialchars((string) ($record['clock_in_time'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></span></td>
                                <td><span class="sapas-table-value-nowrap text-sm text-slate-600"><?= htmlspecialchars((string) ($record['clock_out_time'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></span></td>
                                <td><span class="sapas-table-number text-sm text-slate-600"><?= htmlspecialchars($record['late_duration'] ?? '0 min', ENT_QUOTES, 'UTF-8') ?></span></td>
                                <td><span class="sapas-table-value-nowrap text-sm text-slate-600"><?= htmlspecialchars((string) ($record['working_duration'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></span></td>
                                <td><span class="sapas-table-value-nowrap text-sm text-slate-600"><?= htmlspecialchars((string) ($record['overtime_duration'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></span></td>
                                <td>
                                    <?php if (empty($leaveContexts)): ?>
                                        <span class="text-sm text-slate-400"><?= htmlspecialchars($t('common.none'), ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php else: ?>
                                        <div class="sapas-report-leave-contexts">
                                            <?php foreach ($leaveContexts as $leaveContext): ?>
                                                <span class="sapas-badge sapas-badge-info">
                                                    <?= htmlspecialchars(employeeDetailLeaveTypeLabel((string) $leaveContext['leave_type']), ENT_QUOTES, 'UTF-8') ?>
                                                </span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php $paginationPath = '/admin/reports/employee-attendance-detail'; ?>
            <?php require __DIR__ . '/../partials/pagination.php'; ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($approvedLeaveRequests)): ?>
        <div class="sapas-report-table-panel">
            <div class="sapas-report-table-header">
                <h2 class="sapas-workspace-card-title"><?= htmlspecialchars($t('common.labels.approved_leave'), ENT_QUOTES, 'UTF-8') ?></h2>
                <p class="sapas-workspace-card-copy"><?= htmlspecialchars($t('reports_module.approved_leave_context_help'), ENT_QUOTES, 'UTF-8') ?></p>
            </div>
            <div class="sapas-data-table-scroll overflow-x-auto">
                <table class="sapas-data-table sapas-report-leave-context-table divide-y divide-slate-200">
                    <thead>
                        <tr>
                            <th class="sapas-col-row-number"><?= htmlspecialchars($t('common.labels.no'), ENT_QUOTES, 'UTF-8') ?></th>
                            <th class="sapas-col-leave-type"><?= htmlspecialchars($t('common.labels.type'), ENT_QUOTES, 'UTF-8') ?></th>
                            <th class="sapas-col-leave-dates"><?= htmlspecialchars($t('leave_module.dates'), ENT_QUOTES, 'UTF-8') ?></th>
                            <th class="sapas-col-duration"><?= htmlspecialchars($t('common.labels.duration'), ENT_QUOTES, 'UTF-8') ?></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 bg-white">
                        <?php foreach ($approvedLeaveRequests as $index => $leaveRequest): ?>
                            <tr>
                                <td><span class="sapas-row-number"><?= (int) $index + 1 ?></span></td>
                                <td><span class="sapas-leave-type-chip"><?= htmlspecialchars(employeeDetailLeaveTypeLabel((string) ($leaveRequest['leave_type'] ?? '')), ENT_QUOTES, 'UTF-8') ?></span></td>
                                <td>
                                    <p class="sapas-leave-date-range">
                                        <span><?= htmlspecialchars((string) ($leaveRequest['start_date'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></span>
                                        <span class="sapas-leave-date-separator" aria-hidden="true">→</span>
                                        <span><?= htmlspecialchars((string) ($leaveRequest['end_date'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></span>
                                    </p>
                                </td>
                                <td><span class="sapas-table-value-nowrap text-sm text-slate-600"><?= (int) ($leaveRequest['duration_days'] ?? 0) ?> <?= htmlspecialchars($t((int) ($leaveRequest['duration_days'] ?? 0) === 1 ? 'common.units.day' : 'common.units.days'), ENT_QUOTES, 'UTF-8') ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>
