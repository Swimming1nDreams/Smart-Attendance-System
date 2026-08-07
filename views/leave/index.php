<?php
$t = $t ?? static fn (string $key): string => $key;
if (!isset($title)) {
    $title = $t('leave_module.my_title');
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
    $filters = ['status' => '', 'leave_type' => '', 'from' => '', 'to' => ''];
}

$layout = 'layouts/app';
$pageTitle = $title;
$pageDescription = $t('leave_module.my_description');
$pageActions = '<a href="/leave/create" class="sapas-btn sapas-btn-primary">' . htmlspecialchars($t('leave_module.request_leave'), ENT_QUOTES, 'UTF-8') . '</a>';
$visibleCount = count($leaveRequests);
$pages = max(1, (int) ceil($totalRequests / $perPage));
$rowNumberStart = ((max(1, (int) $page) - 1) * max(1, (int) $perPage)) + 1;
$statusFilter = (string) ($filters['status'] ?? '');
$leaveTypeFilter = (string) ($filters['leave_type'] ?? '');
$fromFilter = (string) ($filters['from'] ?? '');
$toFilter = (string) ($filters['to'] ?? '');
$hasFilters = $statusFilter !== '' || $leaveTypeFilter !== '' || $fromFilter !== '' || $toFilter !== '';
$paginationQuery = array_filter([
    'status' => $statusFilter,
    'leave_type' => $leaveTypeFilter,
    'from' => $fromFilter,
    'to' => $toFilter,
], static fn (string $value): bool => $value !== '');
$paginationQuery['per_page'] = (string) $perPage;
$paginationPageSizeOptions = [20, 50, 100];

function leaveStatusLabel(string $status): string
{
    return \App\Core\Localization::current()->translate('statuses.leave.' . $status);
}

function leaveStatusBadgeClass(string $status): string
{
    return match ($status) {
        'pending' => 'sapas-badge sapas-badge-warning',
        'approved' => 'sapas-badge sapas-badge-success',
        'rejected' => 'sapas-badge sapas-badge-danger',
        'cancelled' => 'sapas-badge sapas-badge-neutral',
        default => 'sapas-badge sapas-badge-neutral',
    };
}

function leaveTemplate(string $key, array $values): string
{
    $template = \App\Core\Localization::current()->translate($key);
    $replacements = [];

    foreach ($values as $name => $value) {
        $replacements[':' . (string) $name] = (string) $value;
    }

    return strtr($template, $replacements);
}

function leaveTypeLabel(string $leaveType): string
{
    return \App\Core\Localization::current()->translate('leave.types.' . $leaveType);
}

function leavePreview(string $value, int $limit = 56): string
{
    $value = trim($value);

    if ($value === '') {
        return '-';
    }

    return mb_strlen($value) > $limit ? mb_substr($value, 0, $limit - 3) . '...' : $value;
}

$activeFilters = [];
if ($statusFilter !== '') {
    $activeFilters[] = $t('common.labels.status') . ': ' . leaveStatusLabel($statusFilter);
}
if ($leaveTypeFilter !== '') {
    $activeFilters[] = $t('common.labels.type') . ': ' . leaveTypeLabel($leaveTypeFilter);
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
                <h2 class="text-xl font-semibold"><?= htmlspecialchars($t('leave_module.history_filters_title'), ENT_QUOTES, 'UTF-8') ?></h2>
                <p class="mt-1 text-sm text-slate-500"><?= htmlspecialchars($t('leave_module.history_filters_help'), ENT_QUOTES, 'UTF-8') ?></p>
            </div>
            <a href="/leave" class="sapas-btn sapas-btn-secondary sapas-filter-reset"><?= htmlspecialchars($t('common.actions.reset'), ENT_QUOTES, 'UTF-8') ?></a>
        </div>

        <form method="get" action="/leave" class="mt-6 sapas-report-filter-grid sapas-polish-filter-grid is-five">
            <input type="hidden" name="per_page" value="<?= $perPage ?>">
            <div>
                <label class="block text-sm font-medium text-slate-700"><?= htmlspecialchars($t('common.labels.status'), ENT_QUOTES, 'UTF-8') ?></label>
                <select name="status" class="mt-2 sapas-form-control">
                    <option value=""><?= htmlspecialchars($t('leave_module.all_statuses'), ENT_QUOTES, 'UTF-8') ?></option>
                    <?php foreach (['pending', 'approved', 'rejected', 'cancelled'] as $statusOption): ?>
                        <option value="<?= htmlspecialchars($statusOption, ENT_QUOTES, 'UTF-8') ?>" <?= $statusFilter === $statusOption ? 'selected' : '' ?>><?= htmlspecialchars(leaveStatusLabel($statusOption), ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700"><?= htmlspecialchars($t('common.labels.type'), ENT_QUOTES, 'UTF-8') ?></label>
                <select name="leave_type" class="mt-2 sapas-form-control">
                    <option value=""><?= htmlspecialchars($t('leave_module.all_types'), ENT_QUOTES, 'UTF-8') ?></option>
                    <?php foreach (['casual', 'medical', 'unpaid', 'other'] as $typeOption): ?>
                        <option value="<?= htmlspecialchars($typeOption, ENT_QUOTES, 'UTF-8') ?>" <?= $leaveTypeFilter === $typeOption ? 'selected' : '' ?>><?= htmlspecialchars(leaveTypeLabel($typeOption), ENT_QUOTES, 'UTF-8') ?></option>
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
            <div class="sapas-filter-actions flex items-end">
                <button type="submit" class="sapas-btn sapas-btn-primary w-full"><?= htmlspecialchars($t('common.actions.apply_filters'), ENT_QUOTES, 'UTF-8') ?></button>
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
                    <p class="text-sm text-slate-600"><?= htmlspecialchars(leaveTemplate($totalRequests === 1 ? 'leave_module.matching_requests_summary' : 'leave_module.matching_requests_summary_plural', ['count' => (string) $totalRequests]), ENT_QUOTES, 'UTF-8') ?></p>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <?php if (empty($leaveRequests)): ?>
        <div class="sapas-report-empty sapas-polish-empty-state">
            <p class="sapas-report-empty-title"><?= htmlspecialchars($t('leave_module.empty_title'), ENT_QUOTES, 'UTF-8') ?></p>
            <p class="sapas-report-empty-copy"><?= htmlspecialchars($hasFilters ? $t('leave_module.empty_filtered') : $t('leave_module.empty_employee'), ENT_QUOTES, 'UTF-8') ?></p>
            <?php if (!$hasFilters): ?>
                <a href="/leave/create" class="mt-4 sapas-btn sapas-btn-primary"><?= htmlspecialchars($t('leave_module.request_leave'), ENT_QUOTES, 'UTF-8') ?></a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="sapas-report-table-header sapas-data-table-summary">
            <div class="sapas-report-meta-grid sapas-data-table-summary-grid">
                <div>
                    <p class="text-sm font-medium text-slate-500"><?= htmlspecialchars($t('reports_module.showing'), ENT_QUOTES, 'UTF-8') ?></p>
                    <p class="mt-1 text-lg font-semibold text-slate-900"><?= htmlspecialchars(leaveTemplate($visibleCount === 1 ? 'leave_module.requests_on_page_summary' : 'leave_module.requests_on_page_summary_plural', ['count' => (string) $visibleCount]), ENT_QUOTES, 'UTF-8') ?></p>
                </div>
                <div>
                    <p class="text-sm font-medium text-slate-500"><?= htmlspecialchars($t('reports.total_matches'), ENT_QUOTES, 'UTF-8') ?></p>
                    <p class="mt-1 text-lg font-semibold text-slate-900"><?= htmlspecialchars(leaveTemplate($totalRequests === 1 ? 'leave_module.total_requests_summary' : 'leave_module.total_requests_summary_plural', ['count' => (string) $totalRequests]), ENT_QUOTES, 'UTF-8') ?></p>
                </div>
                <div>
                    <p class="text-sm font-medium text-slate-500"><?= htmlspecialchars($t('reports.page'), ENT_QUOTES, 'UTF-8') ?></p>
                    <p class="mt-1 text-lg font-semibold text-slate-900"><?= htmlspecialchars(leaveTemplate('leave_module.page_summary', ['page' => (string) $page, 'pages' => (string) $pages]), ENT_QUOTES, 'UTF-8') ?></p>
                </div>
            </div>
        </div>

        <div class="sapas-data-table-scroll sapas-polish-table-scroll overflow-x-auto">
            <table class="sapas-data-table sapas-leave-employee-table divide-y divide-slate-200">
                <thead class="bg-slate-50">
                    <tr>
                        <th class="sapas-col-row-number"><?= htmlspecialchars($t('common.labels.no'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th class="sapas-col-leave-type"><?= htmlspecialchars($t('common.labels.type'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th class="sapas-col-leave-dates"><?= htmlspecialchars($t('leave_module.dates'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th class="sapas-col-number"><?= htmlspecialchars($t('leave_module.days'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th class="sapas-col-status"><?= htmlspecialchars($t('common.labels.status'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th class="sapas-col-leave-reason"><?= htmlspecialchars($t('leave_module.reason'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th class="sapas-col-leave-actions"><?= htmlspecialchars($t('common.actions.view'), ENT_QUOTES, 'UTF-8') ?></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 bg-white">
                    <?php foreach ($leaveRequests as $index => $leaveRequest): ?>
                        <?php
                        $reason = trim((string) ($leaveRequest['reason'] ?? ''));
                        $status = (string) ($leaveRequest['status'] ?? 'pending');
                        $canCancel = $status === 'pending';
                        $submittedAt = trim((string) ($leaveRequest['created_at'] ?? ''));
                        $submittedParts = preg_split('/\s+/', $submittedAt, 2) ?: [];
                        $submittedDate = (string) ($submittedParts[0] ?? '');
                        $submittedTime = (string) ($submittedParts[1] ?? '');
                        ?>
                        <tr>
                            <td><span class="sapas-row-number"><?= $rowNumberStart + (int) $index ?></span></td>
                            <td><span class="sapas-leave-type-chip"><?= htmlspecialchars(leaveTypeLabel((string) ($leaveRequest['leave_type'] ?? '')), ENT_QUOTES, 'UTF-8') ?></span></td>
                            <td>
                                <div class="sapas-leave-date-cell">
                                    <p class="sapas-leave-date-range">
                                        <span><?= htmlspecialchars((string) ($leaveRequest['start_date'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                                        <span class="sapas-leave-date-separator" aria-hidden="true">→</span>
                                        <span><?= htmlspecialchars((string) ($leaveRequest['end_date'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                                    </p>
                                    <p class="sapas-table-text-cell" title="<?= htmlspecialchars($submittedAt, ENT_QUOTES, 'UTF-8') ?>">
                                        <?= htmlspecialchars($t('leave_module.submitted'), ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($submittedDate, ENT_QUOTES, 'UTF-8') ?><?= $submittedTime !== '' ? ' · ' . htmlspecialchars($submittedTime, ENT_QUOTES, 'UTF-8') : '' ?>
                                    </p>
                                </div>
                            </td>
                            <td><span class="sapas-table-number"><?= (int) ($leaveRequest['duration_days'] ?? 0) ?></span></td>
                            <td><span class="<?= leaveStatusBadgeClass($status) ?>"><?= htmlspecialchars(leaveStatusLabel($status), ENT_QUOTES, 'UTF-8') ?></span></td>
                            <td><p class="sapas-leave-reason" title="<?= htmlspecialchars($reason, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(leavePreview($reason), ENT_QUOTES, 'UTF-8') ?></p></td>
                            <td class="sapas-table-action">
                                <div class="sapas-leave-actions">
                                    <a href="/leave/show?id=<?= (int) ($leaveRequest['id'] ?? 0) ?>" class="sapas-btn sapas-btn-secondary"><?= htmlspecialchars($t('common.actions.view_details'), ENT_QUOTES, 'UTF-8') ?></a>
                                    <?php if ($canCancel): ?>
                                        <form method="post" action="/leave/cancel" onsubmit="return confirm('<?= htmlspecialchars($t('leave_module.confirm_cancel_pending'), ENT_QUOTES, 'UTF-8') ?>');">
                                            <input type="hidden" name="id" value="<?= (int) ($leaveRequest['id'] ?? 0) ?>">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($csrf_token ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                            <button type="submit" class="sapas-btn sapas-btn-danger-quiet"><?= htmlspecialchars($t('leave_module.cancel_request'), ENT_QUOTES, 'UTF-8') ?></button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php $paginationPath = '/leave'; ?>
        <?php require __DIR__ . '/../partials/pagination.php'; ?>
    <?php endif; ?>
</div>
