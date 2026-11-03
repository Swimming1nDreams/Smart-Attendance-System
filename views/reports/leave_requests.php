<?php
$t = $t ?? static fn (string $key): string => $key;
if (!isset($title)) {
    $title = $t('reports_module.leave_request');
}
if (!isset($errors)) {
    $errors = [];
}
if (!isset($leaveRequests)) {
    $leaveRequests = [];
}
if (!isset($employees)) {
    $employees = [];
}
if (!isset($departments)) {
    $departments = [];
}
$departmentScopeLocked = isset($departmentScopeLocked) && $departmentScopeLocked;
if (!isset($filters)) {
    $filters = [
        'date_from' => '',
        'date_to' => '',
        'department_id' => null,
        'employee_id' => null,
        'status' => null,
        'leave_type' => null,
    ];
}
if (!isset($page)) {
    $page = 1;
}
if (!isset($perPage)) {
    $perPage = 20;
}
if (!isset($totalRequests)) {
    $totalRequests = 0;
}

$layout = 'layouts/app';
$pageTitle = $pageTitle ?? $t('reports_module.leave_request');
$pageDescription = $pageDescription ?? $t('reports_module.leave_request_description');
$pageActions = '<a href="/admin/reports" class="sapas-btn sapas-btn-secondary">' . htmlspecialchars($t('reports_module.back_to_reports'), ENT_QUOTES, 'UTF-8') . '</a>';

$pages = max(1, (int) ceil($totalRequests / $perPage));
$visibleCount = count($leaveRequests);
$rowNumberStart = ((max(1, (int) $page) - 1) * max(1, (int) $perPage)) + 1;
$paginationQuery = array_filter([
    'date_from' => (string) ($filters['date_from'] ?? ''),
    'date_to' => (string) ($filters['date_to'] ?? ''),
    'department_id' => ($filters['department_id'] ?? null) !== null ? (string) $filters['department_id'] : '',
    'employee_id' => ($filters['employee_id'] ?? null) !== null ? (string) $filters['employee_id'] : '',
    'status' => ($filters['status'] ?? null) !== null ? (string) $filters['status'] : '',
    'leave_type' => ($filters['leave_type'] ?? null) !== null ? (string) $filters['leave_type'] : '',
], static fn (string $value): bool => $value !== '');
$pdfExportUrl = '/admin/reports/leave-requests/pdf' . ($paginationQuery === [] ? '' : '?' . http_build_query($paginationQuery));
$excelExportUrl = '/admin/reports/leave-requests/excel' . ($paginationQuery === [] ? '' : '?' . http_build_query($paginationQuery));
$paginationQuery['per_page'] = (string) $perPage;
$paginationPageSizeOptions = [20, 50, 100];
$pageActions = '<div class="sapas-workspace-actions sapas-export-actions"><a href="/admin/reports" class="sapas-btn sapas-btn-secondary">' . htmlspecialchars($t('reports_module.back_to_reports'), ENT_QUOTES, 'UTF-8') . '</a><a href="' . htmlspecialchars($excelExportUrl, ENT_QUOTES, 'UTF-8') . '" class="sapas-btn sapas-btn-success">' . htmlspecialchars($t('common.actions.export_excel'), ENT_QUOTES, 'UTF-8') . '</a><a href="' . htmlspecialchars($pdfExportUrl, ENT_QUOTES, 'UTF-8') . '" class="sapas-btn sapas-btn-danger">' . htmlspecialchars($t('common.actions.export_pdf'), ENT_QUOTES, 'UTF-8') . '</a></div>';

function reportLeaveStatusLabel(string $status): string
{
    return \App\Core\Localization::current()->translate('statuses.leave.' . $status);
}

function reportLeaveStatusClasses(string $status): string
{
    return match ($status) {
        'pending' => 'sapas-badge sapas-badge-warning',
        'approved' => 'sapas-badge sapas-badge-success',
        'rejected' => 'sapas-badge sapas-badge-danger',
        'cancelled' => 'sapas-badge sapas-badge-neutral',
        default => 'sapas-badge sapas-badge-neutral',
    };
}

function reportLeaveTypeLabel(string $leaveType): string
{
    return \App\Core\Localization::current()->translate('leave.types.' . $leaveType);
}

function reportLeaveCancelReasonLabel(string $cancelReason): string
{
    $localization = \App\Core\Localization::current();

    return match ($cancelReason) {
        'leave.cancel_reasons.admin', 'Cancelled by admin' => $localization->translate('leave.cancel_reasons.admin'),
        'leave.cancel_reasons.department_head', 'Cancelled by department head' => $localization->translate('leave.cancel_reasons.department_head'),
        'leave.cancel_reasons.employee', 'Cancelled by employee' => $localization->translate('leave.cancel_reasons.employee'),
        default => $cancelReason,
    };
}

function reportLeaveNotePreview(?string $reviewNote, ?string $cancelReason): string
{
    $note = trim((string) ($cancelReason ?? ''));

    if ($note !== '') {
        $note = reportLeaveCancelReasonLabel($note);
    }

    if ($note === '') {
        $note = trim((string) ($reviewNote ?? ''));
    }

    if ($note === '') {
        return '-';
    }

    return strlen($note) > 120 ? substr($note, 0, 117) . '...' : $note;
}

$employeeNames = [];
foreach ($employees as $employee) {
    $employeeNames[(int) $employee['id']] = (string) $employee['name'];
}
$departmentNames = [];
foreach ($departments as $department) {
    $departmentNames[(int) $department['id']] = (string) $department['name'];
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
if (($filters['status'] ?? null) !== null) {
    $activeFilters[] = $t('common.labels.status') . ': ' . reportLeaveStatusLabel((string) $filters['status']);
}
if (($filters['leave_type'] ?? null) !== null) {
    $activeFilters[] = $t('common.labels.type') . ': ' . reportLeaveTypeLabel((string) $filters['leave_type']);
}
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

    <div class="sapas-report-filter-panel">
        <form method="get" action="/admin/reports/leave-requests" class="sapas-report-filter-grid sapas-polish-filter-grid is-five">
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
                <?php if ($departmentScopeLocked): ?><input type="hidden" name="department_id" value="<?= (int) ($filters['department_id'] ?? 0) ?>"><?php endif; ?>
                <select id="department_id" name="<?= $departmentScopeLocked ? '' : 'department_id' ?>" class="sapas-form-control mt-2" <?= $departmentScopeLocked ? 'disabled aria-disabled="true"' : '' ?>>
                    <?php if (!$departmentScopeLocked): ?><option value=""><?= htmlspecialchars($t('reports_module.all_departments'), ENT_QUOTES, 'UTF-8') ?></option><?php endif; ?>
                    <?php foreach ($departments as $department): ?>
                        <option value="<?= (int) $department['id'] ?>" <?= (int) ($filters['department_id'] ?? 0) === (int) $department['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $department['name'], ENT_QUOTES, 'UTF-8') ?></option>
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
            <div>
                <label for="status" class="block text-sm font-medium text-slate-700"><?= htmlspecialchars($t('common.labels.status'), ENT_QUOTES, 'UTF-8') ?></label>
                <select id="status" name="status" class="sapas-form-control mt-2">
                    <option value=""><?= htmlspecialchars($t('leave_module.all_statuses'), ENT_QUOTES, 'UTF-8') ?></option>
                    <?php foreach (['pending', 'approved', 'rejected', 'cancelled'] as $statusOption): ?>
                        <option value="<?= htmlspecialchars($statusOption, ENT_QUOTES, 'UTF-8') ?>" <?= (string) ($filters['status'] ?? '') === $statusOption ? 'selected' : '' ?>>
                            <?= htmlspecialchars(reportLeaveStatusLabel($statusOption), ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="leave_type" class="block text-sm font-medium text-slate-700"><?= htmlspecialchars($t('common.labels.type'), ENT_QUOTES, 'UTF-8') ?></label>
                <select id="leave_type" name="leave_type" class="sapas-form-control mt-2">
                    <option value=""><?= htmlspecialchars($t('leave_module.all_types'), ENT_QUOTES, 'UTF-8') ?></option>
                    <?php foreach (['casual', 'medical', 'unpaid', 'other'] as $typeOption): ?>
                        <option value="<?= htmlspecialchars($typeOption, ENT_QUOTES, 'UTF-8') ?>" <?= (string) ($filters['leave_type'] ?? '') === $typeOption ? 'selected' : '' ?>>
                            <?= htmlspecialchars(reportLeaveTypeLabel($typeOption), ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="sapas-filter-actions xl:col-span-5 flex justify-end">
                <button type="submit" class="sapas-btn sapas-btn-primary"><?= htmlspecialchars($t('common.actions.apply_filters'), ENT_QUOTES, 'UTF-8') ?></button>
                <a href="/admin/reports/leave-requests" class="sapas-btn sapas-btn-secondary"><?= htmlspecialchars($t('common.actions.reset'), ENT_QUOTES, 'UTF-8') ?></a>
            </div>
        </form>

        <div class="sapas-report-active-filters">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <p class="text-sm font-medium text-slate-500"><?= htmlspecialchars($t('common.labels.active_filters'), ENT_QUOTES, 'UTF-8') ?></p>
                    <div class="mt-2 flex flex-wrap gap-2">
                        <?php if (empty($activeFilters)): ?>
                            <span class="sapas-chip sapas-chip-muted"><?= htmlspecialchars($t('reports_module.all_leave_requests'), ENT_QUOTES, 'UTF-8') ?></span>
                        <?php else: ?>
                            <?php foreach ($activeFilters as $filter): ?>
                                <span class="sapas-chip sapas-chip-info"><?= htmlspecialchars($filter, ENT_QUOTES, 'UTF-8') ?></span>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                    <p class="text-sm text-slate-600"><?= (int) $totalRequests ?> <?= htmlspecialchars($t('reports_module.matching_requests'), ENT_QUOTES, 'UTF-8') ?></p>
            </div>
        </div>
    </div>

    <div class="sapas-report-table-panel">
        <div class="sapas-report-table-header sapas-data-table-summary">
            <div class="sapas-report-meta-grid sapas-data-table-summary-grid">
                <div>
                    <p class="sapas-report-meta-label"><?= htmlspecialchars($t('reports_module.showing'), ENT_QUOTES, 'UTF-8') ?></p>
                    <p class="sapas-report-meta-value"><?= $visibleCount ?> <?= htmlspecialchars($t('reports_module.requests_on_page'), ENT_QUOTES, 'UTF-8') ?></p>
                </div>
                <div>
                    <p class="sapas-report-meta-label"><?= htmlspecialchars($t('reports.total_matches'), ENT_QUOTES, 'UTF-8') ?></p>
                    <p class="sapas-report-meta-value"><?= (int) $totalRequests ?> <?= htmlspecialchars($t('reports_module.matching_requests'), ENT_QUOTES, 'UTF-8') ?></p>
                </div>
                <div>
                    <p class="sapas-report-meta-label"><?= htmlspecialchars($t('reports_module.pages'), ENT_QUOTES, 'UTF-8') ?></p>
                    <p class="sapas-report-meta-value"><?= htmlspecialchars($t('reports.page'), ENT_QUOTES, 'UTF-8') ?> <?= (int) $page ?> of <?= (int) $pages ?></p>
                </div>
            </div>
        </div>

        <?php if (empty($leaveRequests) && empty($errors)): ?>
            <div class="sapas-report-empty sapas-polish-empty-state">
                <p class="sapas-report-empty-title"><?= htmlspecialchars($t('reports_module.no_leave_requests'), ENT_QUOTES, 'UTF-8') ?></p>
                <p class="sapas-report-empty-copy"><?= htmlspecialchars($t('common.empty.try_adjusting_filters'), ENT_QUOTES, 'UTF-8') ?></p>
            </div>
        <?php elseif (!empty($leaveRequests)): ?>
            <div class="sapas-data-table-scroll overflow-x-auto">
                <table class="sapas-data-table sapas-report-leave-table divide-y divide-slate-200">
                    <thead>
                        <tr>
                            <th class="sapas-col-row-number"><?= htmlspecialchars($t('common.labels.no'), ENT_QUOTES, 'UTF-8') ?></th>
                            <th class="sapas-col-employee"><?= htmlspecialchars($t('common.labels.employee'), ENT_QUOTES, 'UTF-8') ?></th>
                            <th class="sapas-col-leave-type"><?= htmlspecialchars($t('common.labels.type'), ENT_QUOTES, 'UTF-8') ?></th>
                            <th class="sapas-col-leave-dates"><?= htmlspecialchars($t('leave_module.dates'), ENT_QUOTES, 'UTF-8') ?></th>
                            <th class="sapas-col-number"><?= htmlspecialchars($t('common.labels.duration'), ENT_QUOTES, 'UTF-8') ?></th>
                            <th class="sapas-col-status"><?= htmlspecialchars($t('common.labels.status'), ENT_QUOTES, 'UTF-8') ?></th>
                            <th class="sapas-col-leave-submitted"><?= htmlspecialchars($t('common.labels.submitted'), ENT_QUOTES, 'UTF-8') ?></th>
                            <th class="sapas-col-leave-reason"><?= htmlspecialchars($t('common.labels.reason'), ENT_QUOTES, 'UTF-8') ?></th>
                            <th class="sapas-col-report-note"><?= htmlspecialchars($t('reports_module.review_cancel_note'), ENT_QUOTES, 'UTF-8') ?></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 bg-white">
                        <?php foreach ($leaveRequests as $index => $leaveRequest): ?>
                            <?php
                            $status = (string) ($leaveRequest['status'] ?? 'pending');
                            $employeeName = trim((string) ($leaveRequest['employee_name'] ?? ''));
                            $employeeEmail = trim((string) ($leaveRequest['employee_email'] ?? ''));
                            $staffCode = trim((string) ($leaveRequest['staff_code'] ?? ''));
                            $employeeIdentity = $staffCode !== '' ? $employeeName . ' (' . $staffCode . ')' : $employeeName;
                            $submittedAt = trim((string) ($leaveRequest['created_at'] ?? ''));
                            $submittedParts = preg_split('/\s+/', $submittedAt, 2) ?: [];
                            $submittedDate = (string) ($submittedParts[0] ?? '');
                            $submittedTime = (string) ($submittedParts[1] ?? '');
                            $requestReason = trim((string) ($leaveRequest['reason'] ?? ''));
                            $requestReason = $requestReason !== '' ? $requestReason : $t('leave_module.no_reason_provided');
                            $notePreview = reportLeaveNotePreview($leaveRequest['review_note'] ?? null, $leaveRequest['cancel_reason'] ?? null);
                            ?>
                            <tr>
                                <td><span class="sapas-row-number"><?= $rowNumberStart + (int) $index ?></span></td>
                                <td>
                                    <div class="sapas-person-cell">
                                        <div class="sapas-person-cell-head">
                                            <p class="sapas-person-name font-semibold text-slate-900" title="<?= htmlspecialchars($employeeIdentity, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($employeeIdentity, ENT_QUOTES, 'UTF-8') ?></p>
                                        </div>
                                        <p class="sapas-person-email" title="<?= htmlspecialchars($employeeEmail, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($employeeEmail, ENT_QUOTES, 'UTF-8') ?></p>
                                    </div>
                                </td>
                                <td>
                                    <span class="sapas-leave-type-chip"><?= htmlspecialchars(reportLeaveTypeLabel((string) ($leaveRequest['leave_type'] ?? '')), ENT_QUOTES, 'UTF-8') ?></span>
                                    <p class="mt-2 text-xs text-slate-500"><?= htmlspecialchars(\App\Services\LeaveReasonCatalog::label((string) ($leaveRequest['reason_category'] ?? ''), $t), ENT_QUOTES, 'UTF-8') ?></p>
                                </td>
                                <td>
                                    <p class="sapas-leave-date-range">
                                        <span><?= htmlspecialchars((string) ($leaveRequest['start_date'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                                        <span class="sapas-leave-date-separator" aria-hidden="true">→</span>
                                        <span><?= htmlspecialchars((string) ($leaveRequest['end_date'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                                    </p>
                                </td>
                                <td><span class="sapas-table-number text-sm text-slate-700"><?= (int) ($leaveRequest['duration_days'] ?? 0) ?></span></td>
                                <td><span class="<?= reportLeaveStatusClasses($status) ?>"><?= htmlspecialchars(reportLeaveStatusLabel($status), ENT_QUOTES, 'UTF-8') ?></span></td>
                                <td>
                                    <div class="sapas-date-cell">
                                        <p class="sapas-table-value-nowrap font-medium text-slate-800"><?= htmlspecialchars($submittedDate, ENT_QUOTES, 'UTF-8') ?></p>
                                        <?php if ($submittedTime !== ''): ?>
                                            <p class="text-xs text-slate-500"><?= htmlspecialchars($submittedTime, ENT_QUOTES, 'UTF-8') ?></p>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td><p class="sapas-leave-reason" title="<?= htmlspecialchars($requestReason, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(mb_strlen($requestReason) > 120 ? mb_substr($requestReason, 0, 117) . '...' : $requestReason, ENT_QUOTES, 'UTF-8') ?></p></td>
                                <td><p class="sapas-leave-reason" title="<?= htmlspecialchars($notePreview, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($notePreview, ENT_QUOTES, 'UTF-8') ?></p></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php $paginationPath = '/admin/reports/leave-requests'; ?>
            <?php require __DIR__ . '/../partials/pagination.php'; ?>
        <?php endif; ?>
    </div>
</div>
