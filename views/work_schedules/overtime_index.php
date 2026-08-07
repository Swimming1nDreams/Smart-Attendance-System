<?php
$t = $t ?? static fn (string $key): string => $key;
$result = $result ?? ['records' => [], 'total' => 0, 'page' => 1, 'per_page' => 20, 'filters' => []];
$records = $result['records'] ?? [];
$filters = $result['filters'] ?? [];
$options = $options ?? ['policies' => [], 'departments' => [], 'employees' => []];
$page = (int) ($result['page'] ?? 1);
$perPage = (int) ($result['per_page'] ?? 20);
$total = (int) ($result['total'] ?? 0);
$pages = $total > 0 ? max(1, (int) ceil($total / max(1, $perPage))) : 0;
$rowNumberStart = (($page - 1) * max(1, $perPage)) + 1;
$paginationPath = '/admin/overtime';
$paginationQuery = array_filter([
    'from' => $filters['from'] ?? '',
    'to' => $filters['to'] ?? '',
    'employee_id' => $filters['employee_id'] ?? '',
    'department_id' => $filters['department_id'] ?? '',
    'day_type' => $filters['day_type'] ?? '',
    'approval_status' => $filters['approval_status'] ?? '',
    'policy_id' => $filters['policy_id'] ?? '',
]);
$paginationQuery['per_page'] = (string) $perPage;
$paginationPageSizeOptions = $total > 0 ? [20, 50, 100] : [];
$paginationTotalRecords = $total;
$layout = 'layouts/app';
$pageTitle = $t('workforce_policy.overtime.title');
$pageDescription = $t('workforce_policy.overtime.description');
$pageActions = '<a href="/admin/work-schedules" class="sapas-btn sapas-btn-secondary">' . htmlspecialchars($t('work_schedules.back'), ENT_QUOTES, 'UTF-8') . '</a>';
$minutes = static fn (mixed $value): string => $value === null || $value === '' ? '—' : ((int) $value . 'm');
$hasActiveFilters = array_filter($filters, static fn (mixed $value): bool => $value !== null && $value !== '') !== [];
$statusBadgeClass = static function (string $status): string {
    return match ($status) {
        'pending' => 'sapas-badge-warning',
        'approved' => 'sapas-badge-success',
        'rejected' => 'sapas-badge-danger',
        default => 'sapas-badge-neutral',
    };
};
$employeeLabel = static function (array $row): string {
    $name = trim((string) ($row['employee_name'] ?? ''));
    $code = trim((string) ($row['staff_code'] ?? ''));
    return $name . ($code !== '' ? ' (' . $code . ')' : '');
};
?>

<section class="sapas-filter-panel sapas-polish-filter-panel">
    <h2 class="mb-3 text-sm font-semibold text-slate-800"><?= htmlspecialchars($t('workforce_policy.overtime.filters'), ENT_QUOTES, 'UTF-8') ?></h2>
    <form method="GET" action="/admin/overtime" class="grid gap-4 md:grid-cols-4 sapas-polish-filter-grid">
        <div>
            <label class="block text-sm font-medium text-slate-700" for="from"><?= htmlspecialchars($t('common.labels.date_from'), ENT_QUOTES, 'UTF-8') ?></label>
            <input id="from" type="date" name="from" class="mt-1 sapas-form-control" value="<?= htmlspecialchars((string) ($filters['from'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div>
            <label class="block text-sm font-medium text-slate-700" for="to"><?= htmlspecialchars($t('common.labels.date_to'), ENT_QUOTES, 'UTF-8') ?></label>
            <input id="to" type="date" name="to" class="mt-1 sapas-form-control" value="<?= htmlspecialchars((string) ($filters['to'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div>
            <label class="block text-sm font-medium text-slate-700" for="approval-status"><?= htmlspecialchars($t('workforce_policy.overtime.approval_status'), ENT_QUOTES, 'UTF-8') ?></label>
            <select id="approval-status" name="approval_status" class="mt-1 sapas-form-control">
                <option value=""><?= htmlspecialchars($t('common.all'), ENT_QUOTES, 'UTF-8') ?></option>
                <?php foreach (['pending', 'approved', 'rejected', 'not_required'] as $status): ?>
                    <option value="<?= $status ?>" <?= ($filters['approval_status'] ?? '') === $status ? 'selected' : '' ?>><?= htmlspecialchars($t('workforce_policy.overtime.status_' . $status), ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="sapas-filter-actions flex items-end gap-2">
            <button type="submit" class="sapas-btn sapas-btn-primary"><?= htmlspecialchars($t('common.actions.apply_filters'), ENT_QUOTES, 'UTF-8') ?></button>
            <?php if ($hasActiveFilters): ?>
                <a href="/admin/overtime" class="sapas-btn sapas-btn-secondary"><?= htmlspecialchars($t('common.actions.reset'), ENT_QUOTES, 'UTF-8') ?></a>
            <?php endif; ?>
        </div>
        <input type="hidden" name="per_page" value="<?= $perPage ?>">
    </form>
</section>

<section class="sapas-report-table-panel mt-5 sapas-overtime-panel">
    <?php if ($records === []): ?>
        <div class="sapas-report-empty sapas-polish-empty-state">
            <p class="sapas-report-empty-title"><?= htmlspecialchars($t('workforce_policy.overtime.empty_title'), ENT_QUOTES, 'UTF-8') ?></p>
            <p class="sapas-report-empty-copy"><?= htmlspecialchars($t('workforce_policy.overtime.empty_copy'), ENT_QUOTES, 'UTF-8') ?></p>
        </div>
    <?php else: ?>
        <div class="sapas-overtime-desktop-table">
            <table class="sapas-data-table sapas-overtime-table divide-y divide-slate-200">
                <thead>
                    <tr>
                        <th><?= htmlspecialchars($t('common.labels.date'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th><?= htmlspecialchars($t('common.labels.employee'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th><?= htmlspecialchars($t('workforce_policy.overtime.work_context'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th><?= htmlspecialchars($t('workforce_policy.overtime.breakdown'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th><?= htmlspecialchars($t('workforce_policy.overtime.calculated'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th><?= htmlspecialchars($t('workforce_policy.overtime.approval_status'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th><?= htmlspecialchars($t('common.labels.actions'), ENT_QUOTES, 'UTF-8') ?></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 bg-white">
                    <?php foreach ($records as $row): ?>
                        <?php $approvalStatus = (string) ($row['overtime_approval_status'] ?? 'not_required'); ?>
                        <tr>
                            <td class="sapas-overtime-date-cell">
                                <strong><?= htmlspecialchars((string) ($row['attendance_date'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></strong>
                            </td>
                            <td>
                                <div class="sapas-overtime-employee">
                                    <strong title="<?= htmlspecialchars($employeeLabel($row), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($employeeLabel($row), ENT_QUOTES, 'UTF-8') ?></strong>
                                    <small><?= htmlspecialchars((string) ($row['department_name'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></small>
                                </div>
                            </td>
                            <td>
                                <div class="sapas-overtime-context">
                                    <span class="sapas-badge sapas-badge-neutral"><?= htmlspecialchars($t('workforce_policy.day_types.' . (string) ($row['day_type'] ?? 'working_day')), ENT_QUOTES, 'UTF-8') ?></span>
                                    <small><?= htmlspecialchars((string) ($row['policy_name'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></small>
                                </div>
                            </td>
                            <td>
                                <dl class="sapas-overtime-breakdown">
                                    <div><dt><?= htmlspecialchars($t('workforce_policy.overtime.early'), ENT_QUOTES, 'UTF-8') ?></dt><dd><?= htmlspecialchars($minutes($row['early_overtime_minutes'] ?? null), ENT_QUOTES, 'UTF-8') ?></dd></div>
                                    <div><dt><?= htmlspecialchars($t('workforce_policy.overtime.after_hours'), ENT_QUOTES, 'UTF-8') ?></dt><dd><?= htmlspecialchars($minutes($row['after_hours_overtime_minutes'] ?? null), ENT_QUOTES, 'UTF-8') ?></dd></div>
                                    <div><dt><?= htmlspecialchars($t('workforce_policy.overtime.non_working'), ENT_QUOTES, 'UTF-8') ?></dt><dd><?= htmlspecialchars($minutes($row['non_working_day_overtime_minutes'] ?? null), ENT_QUOTES, 'UTF-8') ?></dd></div>
                                    <div><dt><?= htmlspecialchars($t('workforce_policy.overtime.holiday'), ENT_QUOTES, 'UTF-8') ?></dt><dd><?= htmlspecialchars($minutes($row['holiday_overtime_minutes'] ?? null), ENT_QUOTES, 'UTF-8') ?></dd></div>
                                </dl>
                            </td>
                            <td class="sapas-overtime-total-cell">
                                <strong><?= htmlspecialchars($minutes($row['calculated_overtime_minutes'] ?? null), ENT_QUOTES, 'UTF-8') ?></strong>
                            </td>
                            <td>
                                <div class="sapas-overtime-review-state">
                                    <span class="sapas-badge <?= $statusBadgeClass($approvalStatus) ?>"><?= htmlspecialchars($t('workforce_policy.overtime.status_' . $approvalStatus), ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php if (($row['approved_overtime_minutes'] ?? null) !== null): ?>
                                        <small><?= htmlspecialchars($t('workforce_policy.overtime.approved'), ENT_QUOTES, 'UTF-8') ?>: <?= htmlspecialchars($minutes($row['approved_overtime_minutes']), ENT_QUOTES, 'UTF-8') ?></small>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="sapas-table-action"><a href="/admin/overtime/show?id=<?= (int) $row['id'] ?>" class="sapas-btn sapas-btn-secondary"><?= htmlspecialchars($t('common.actions.view_details'), ENT_QUOTES, 'UTF-8') ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="sapas-overtime-mobile-list" aria-label="<?= htmlspecialchars($t('workforce_policy.overtime.title'), ENT_QUOTES, 'UTF-8') ?>">
            <?php foreach ($records as $row): ?>
                <?php $approvalStatus = (string) ($row['overtime_approval_status'] ?? 'not_required'); ?>
                <article class="sapas-overtime-mobile-card">
                    <div class="sapas-overtime-mobile-head">
                        <div>
                            <p><?= htmlspecialchars((string) ($row['attendance_date'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></p>
                            <h3><?= htmlspecialchars($employeeLabel($row), ENT_QUOTES, 'UTF-8') ?></h3>
                            <small><?= htmlspecialchars((string) ($row['department_name'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></small>
                        </div>
                        <strong><?= htmlspecialchars($minutes($row['calculated_overtime_minutes'] ?? null), ENT_QUOTES, 'UTF-8') ?></strong>
                    </div>
                    <div class="sapas-overtime-mobile-context">
                        <span class="sapas-badge sapas-badge-neutral"><?= htmlspecialchars($t('workforce_policy.day_types.' . (string) ($row['day_type'] ?? 'working_day')), ENT_QUOTES, 'UTF-8') ?></span>
                        <span><?= htmlspecialchars((string) ($row['policy_name'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                    <dl class="sapas-overtime-breakdown">
                        <div><dt><?= htmlspecialchars($t('workforce_policy.overtime.early'), ENT_QUOTES, 'UTF-8') ?></dt><dd><?= htmlspecialchars($minutes($row['early_overtime_minutes'] ?? null), ENT_QUOTES, 'UTF-8') ?></dd></div>
                        <div><dt><?= htmlspecialchars($t('workforce_policy.overtime.after_hours'), ENT_QUOTES, 'UTF-8') ?></dt><dd><?= htmlspecialchars($minutes($row['after_hours_overtime_minutes'] ?? null), ENT_QUOTES, 'UTF-8') ?></dd></div>
                        <div><dt><?= htmlspecialchars($t('workforce_policy.overtime.non_working'), ENT_QUOTES, 'UTF-8') ?></dt><dd><?= htmlspecialchars($minutes($row['non_working_day_overtime_minutes'] ?? null), ENT_QUOTES, 'UTF-8') ?></dd></div>
                        <div><dt><?= htmlspecialchars($t('workforce_policy.overtime.holiday'), ENT_QUOTES, 'UTF-8') ?></dt><dd><?= htmlspecialchars($minutes($row['holiday_overtime_minutes'] ?? null), ENT_QUOTES, 'UTF-8') ?></dd></div>
                    </dl>
                    <div class="sapas-overtime-mobile-footer">
                        <span class="sapas-badge <?= $statusBadgeClass($approvalStatus) ?>"><?= htmlspecialchars($t('workforce_policy.overtime.status_' . $approvalStatus), ENT_QUOTES, 'UTF-8') ?></span>
                        <a href="/admin/overtime/show?id=<?= (int) $row['id'] ?>" class="sapas-btn sapas-btn-secondary"><?= htmlspecialchars($t('common.actions.view_details'), ENT_QUOTES, 'UTF-8') ?></a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/../partials/pagination.php'; ?>
