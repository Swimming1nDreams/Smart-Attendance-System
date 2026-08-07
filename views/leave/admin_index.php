<?php
$t = $t ?? static fn (string $key): string => $key;
if (!isset($title)) {
    $title = $t('leave_module.admin_title');
}
if (!isset($leaveRequests)) {
    $leaveRequests = [];
}
if (!isset($totalRequests)) {
    $totalRequests = 0;
}
if (!isset($page)) {
    $page = 1;
}
if (!isset($perPage)) {
    $perPage = 20;
}
if (!isset($filters)) {
    $filters = ['status' => '', 'leave_type' => '', 'search' => '', 'from' => '', 'to' => ''];
}

$layout = 'layouts/app';
$pageTitle = $title;
$pageDescription = $t('leave_module.admin_description');
$pages = max(1, (int) ceil($totalRequests / $perPage));
$visibleCount = count($leaveRequests);
$rowNumberStart = ((max(1, (int) $page) - 1) * max(1, (int) $perPage)) + 1;
$statusFilter = (string) ($filters['status'] ?? '');
$leaveTypeFilter = (string) ($filters['leave_type'] ?? '');
$searchFilter = (string) ($filters['search'] ?? '');
$fromFilter = (string) ($filters['from'] ?? '');
$toFilter = (string) ($filters['to'] ?? '');
$hasFilters = $statusFilter !== '' || $leaveTypeFilter !== '' || $searchFilter !== '' || $fromFilter !== '' || $toFilter !== '';
$paginationQuery = array_filter([
    'status' => $statusFilter,
    'leave_type' => $leaveTypeFilter,
    'search' => $searchFilter,
    'from' => $fromFilter,
    'to' => $toFilter,
], static fn (string $value): bool => $value !== '');
$paginationQuery['per_page'] = (string) $perPage;
$paginationPageSizeOptions = [20, 50, 100];

function adminLeaveStatusLabel(string $status): string
{
    return \App\Core\Localization::current()->translate('statuses.leave.' . $status);
}

function adminLeaveStatusClasses(string $status): string
{
    return match ($status) {
        'pending' => 'sapas-badge sapas-badge-warning',
        'approved' => 'sapas-badge sapas-badge-success',
        'rejected' => 'sapas-badge sapas-badge-danger',
        'cancelled' => 'sapas-badge sapas-badge-neutral',
        default => 'sapas-badge sapas-badge-neutral',
    };
}

function adminLeaveTypeLabel(string $leaveType): string
{
    return \App\Core\Localization::current()->translate('leave.types.' . $leaveType);
}

function adminLeaveActionHint(string $status): string
{
    $localization = \App\Core\Localization::current();

    return match ($status) {
        'pending' => $localization->translate('leave_module.next_step_needs_review'),
        'approved' => $localization->translate('statuses.leave.approved'),
        'rejected', 'cancelled' => $localization->translate('leave_module.next_step_closed'),
        default => $localization->translate('leave_module.next_step_review'),
    };
}

function adminLeaveTemplate(string $key, array $values): string
{
    $template = \App\Core\Localization::current()->translate($key);
    $replacements = [];

    foreach ($values as $name => $value) {
        $replacements[':' . (string) $name] = (string) $value;
    }

    return strtr($template, $replacements);
}

$activeFilters = [];
if ($searchFilter !== '') {
    $activeFilters[] = $t('common.labels.employee') . ': ' . $searchFilter;
}
if ($statusFilter !== '') {
    $activeFilters[] = $t('common.labels.status') . ': ' . adminLeaveStatusLabel($statusFilter);
}
if ($leaveTypeFilter !== '') {
    $activeFilters[] = $t('common.labels.type') . ': ' . adminLeaveTypeLabel($leaveTypeFilter);
}
if ($fromFilter !== '') {
    $activeFilters[] = $t('common.labels.date_from') . ': ' . $fromFilter;
}
if ($toFilter !== '') {
    $activeFilters[] = $t('common.labels.date_to') . ': ' . $toFilter;
}
?>
<?php if (!empty($errors)): ?>
    <div class="mb-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
        <?php foreach ($errors as $error): ?><p><?= htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8') ?></p><?php endforeach; ?>
    </div>
<?php endif; ?>
<div class="sapas-report-table-panel">
    <div class="sapas-report-table-header">
        <div class="sapas-filter-header flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="text-xl font-semibold"><?= htmlspecialchars($t('leave_module.filters_title'), ENT_QUOTES, 'UTF-8') ?></h2>
                <p class="mt-1 text-sm text-slate-500"><?= htmlspecialchars($t('leave_module.filters_help'), ENT_QUOTES, 'UTF-8') ?></p>
            </div>
            <a href="/admin/leave" class="sapas-btn sapas-btn-secondary sapas-filter-reset"><?= htmlspecialchars($t('common.actions.reset'), ENT_QUOTES, 'UTF-8') ?></a>
        </div>

        <form method="get" action="/admin/leave" class="mt-6 grid gap-4 xl:grid-cols-6 sapas-polish-filter-grid">
            <input type="hidden" name="per_page" value="<?= $perPage ?>">
            <div class="xl:col-span-2">
                <label class="block text-sm font-medium text-slate-700"><?= htmlspecialchars($t('common.labels.employee'), ENT_QUOTES, 'UTF-8') ?></label>
                <input type="search" name="search" value="<?= htmlspecialchars($searchFilter, ENT_QUOTES, 'UTF-8') ?>" placeholder="<?= htmlspecialchars($t('leave_module.employee_placeholder'), ENT_QUOTES, 'UTF-8') ?>" class="mt-2 sapas-form-control">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700"><?= htmlspecialchars($t('common.labels.status'), ENT_QUOTES, 'UTF-8') ?></label>
                <select name="status" class="mt-2 sapas-form-control">
                    <option value=""><?= htmlspecialchars($t('leave_module.all_statuses'), ENT_QUOTES, 'UTF-8') ?></option>
                    <?php foreach (['pending', 'approved', 'rejected', 'cancelled'] as $statusOption): ?>
                        <option value="<?= htmlspecialchars($statusOption, ENT_QUOTES, 'UTF-8') ?>" <?= $statusFilter === $statusOption ? 'selected' : '' ?>><?= htmlspecialchars(adminLeaveStatusLabel($statusOption), ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700"><?= htmlspecialchars($t('common.labels.type'), ENT_QUOTES, 'UTF-8') ?></label>
                <select name="leave_type" class="mt-2 sapas-form-control">
                    <option value=""><?= htmlspecialchars($t('leave_module.all_types'), ENT_QUOTES, 'UTF-8') ?></option>
                    <?php foreach (['casual', 'medical', 'unpaid', 'other'] as $typeOption): ?>
                        <option value="<?= htmlspecialchars($typeOption, ENT_QUOTES, 'UTF-8') ?>" <?= $leaveTypeFilter === $typeOption ? 'selected' : '' ?>><?= htmlspecialchars(adminLeaveTypeLabel($typeOption), ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700"><?= htmlspecialchars($t('common.labels.date_from'), ENT_QUOTES, 'UTF-8') ?></label>
                <input type="date" name="from" value="<?= htmlspecialchars($fromFilter, ENT_QUOTES, 'UTF-8') ?>" class="mt-2 sapas-form-control">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700"><?= htmlspecialchars($t('common.labels.date_to'), ENT_QUOTES, 'UTF-8') ?></label>
                <input type="date" name="to" value="<?= htmlspecialchars($toFilter, ENT_QUOTES, 'UTF-8') ?>" class="mt-2 sapas-form-control">
            </div>
            <div class="sapas-filter-actions xl:col-span-6 flex justify-end">
                <button type="submit" class="sapas-btn sapas-btn-primary"><?= htmlspecialchars($t('common.actions.apply_filters'), ENT_QUOTES, 'UTF-8') ?></button>
            </div>
        </form>

        <?php if ($hasFilters): ?>
            <div class="sapas-report-active-filters">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex flex-wrap gap-2">
                        <?php foreach ($activeFilters as $filter): ?>
                            <span class="sapas-chip sapas-chip-info"><?= htmlspecialchars($filter, ENT_QUOTES, 'UTF-8') ?></span>
                        <?php endforeach; ?>
                    </div>
                    <p class="text-sm text-slate-600"><?= htmlspecialchars(adminLeaveTemplate($totalRequests === 1 ? 'leave_module.matching_requests_summary' : 'leave_module.matching_requests_summary_plural', ['count' => (string) $totalRequests]), ENT_QUOTES, 'UTF-8') ?></p>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <?php if (empty($leaveRequests)): ?>
        <div class="sapas-report-empty sapas-polish-empty-state">
            <p class="sapas-report-empty-title"><?= htmlspecialchars($t('leave_module.empty_title'), ENT_QUOTES, 'UTF-8') ?></p>
            <p class="sapas-report-empty-copy"><?= htmlspecialchars($hasFilters ? $t('leave_module.empty_filtered') : $t('leave_module.empty_admin'), ENT_QUOTES, 'UTF-8') ?></p>
        </div>
    <?php else: ?>
        <div class="sapas-report-table-header sapas-data-table-summary">
            <div class="sapas-report-meta-grid sapas-data-table-summary-grid">
                <div>
                    <p class="text-sm font-medium text-slate-500"><?= htmlspecialchars($t('reports_module.showing'), ENT_QUOTES, 'UTF-8') ?></p>
                    <p class="mt-1 text-lg font-semibold text-slate-900"><?= htmlspecialchars(adminLeaveTemplate($visibleCount === 1 ? 'leave_module.requests_on_page_summary' : 'leave_module.requests_on_page_summary_plural', ['count' => (string) $visibleCount]), ENT_QUOTES, 'UTF-8') ?></p>
                </div>
                <div>
                    <p class="text-sm font-medium text-slate-500"><?= htmlspecialchars($t('reports.total_matches'), ENT_QUOTES, 'UTF-8') ?></p>
                    <p class="mt-1 text-lg font-semibold text-slate-900"><?= htmlspecialchars(adminLeaveTemplate($totalRequests === 1 ? 'leave_module.total_requests_summary' : 'leave_module.total_requests_summary_plural', ['count' => (string) $totalRequests]), ENT_QUOTES, 'UTF-8') ?></p>
                </div>
                <div>
                    <p class="text-sm font-medium text-slate-500"><?= htmlspecialchars($t('reports_module.pages'), ENT_QUOTES, 'UTF-8') ?></p>
                    <p class="mt-1 text-lg font-semibold text-slate-900"><?= htmlspecialchars(adminLeaveTemplate('leave_module.page_summary', ['page' => (string) $page, 'pages' => (string) $pages]), ENT_QUOTES, 'UTF-8') ?></p>
                </div>
            </div>
        </div>

        <div class="sapas-data-table-scroll sapas-polish-table-scroll overflow-x-auto">
            <table class="sapas-data-table sapas-leave-admin-table divide-y divide-slate-200">
                <thead class="bg-slate-50">
                    <tr>
                        <th class="sapas-col-row-number"><?= htmlspecialchars($t('common.labels.no'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th class="sapas-col-employee"><?= htmlspecialchars($t('common.labels.employee'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th class="sapas-col-leave-type"><?= htmlspecialchars($t('common.labels.type'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th class="sapas-col-leave-dates"><?= htmlspecialchars($t('leave_module.dates'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th class="sapas-col-number"><?= htmlspecialchars($t('leave_module.days'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th class="sapas-col-status"><?= htmlspecialchars($t('common.labels.status'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th class="sapas-col-leave-submitted"><?= htmlspecialchars($t('common.labels.submitted'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th class="sapas-col-leave-step"><?= htmlspecialchars($t('common.labels.next_step'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th class="sapas-col-action"><?= htmlspecialchars($t('common.labels.actions'), ENT_QUOTES, 'UTF-8') ?></th>
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
                        $nextStep = adminLeaveActionHint($status);
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
                            <td><span class="sapas-leave-type-chip"><?= htmlspecialchars(adminLeaveTypeLabel((string) ($leaveRequest['leave_type'] ?? '')), ENT_QUOTES, 'UTF-8') ?></span></td>
                            <td>
                                <div class="sapas-leave-date-cell">
                                    <p class="sapas-leave-date-range">
                                        <span><?= htmlspecialchars((string) ($leaveRequest['start_date'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                                        <span class="sapas-leave-date-separator" aria-hidden="true">→</span>
                                        <span><?= htmlspecialchars((string) ($leaveRequest['end_date'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                                    </p>
                                </div>
                            </td>
                            <td><span class="sapas-table-number"><?= (int) ($leaveRequest['duration_days'] ?? 0) ?></span></td>
                            <td><span class="<?= adminLeaveStatusClasses($status) ?>"><?= htmlspecialchars(adminLeaveStatusLabel($status), ENT_QUOTES, 'UTF-8') ?></span></td>
                            <td>
                                <div class="sapas-date-cell">
                                    <p class="sapas-table-value-nowrap font-medium text-slate-800"><?= htmlspecialchars($submittedDate, ENT_QUOTES, 'UTF-8') ?></p>
                                    <?php if ($submittedTime !== ''): ?>
                                        <p class="text-xs text-slate-500"><?= htmlspecialchars($submittedTime, ENT_QUOTES, 'UTF-8') ?></p>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td><p class="sapas-leave-guidance" title="<?= htmlspecialchars($nextStep, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($nextStep, ENT_QUOTES, 'UTF-8') ?></p></td>
                            <td class="sapas-table-action">
                                <a href="/admin/leave/show?id=<?= (int) ($leaveRequest['id'] ?? 0) ?>" class="sapas-btn sapas-btn-secondary"><?= htmlspecialchars($t('common.actions.view_details'), ENT_QUOTES, 'UTF-8') ?></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php $paginationPath = '/admin/leave'; ?>
        <?php require __DIR__ . '/../partials/pagination.php'; ?>
    <?php endif; ?>
</div>
