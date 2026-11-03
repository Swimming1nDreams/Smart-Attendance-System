<?php
$t = $t ?? static fn (string $key): string => $key;
$result = $result ?? ['records' => [], 'total' => 0, 'page' => 1, 'per_page' => 20, 'filters' => []];
$records = $result['records'] ?? [];
$filters = $result['filters'] ?? [];
$options = $options ?? ['policies' => [], 'departments' => [], 'employees' => []];
$page = (int) ($result['page'] ?? 1);
$perPage = (int) ($result['per_page'] ?? 20);
$total = (int) ($result['total'] ?? 0);
$pages = max(1, (int) ceil($total / max(1, $perPage)));
$rowNumberStart = (($page - 1) * max(1, $perPage)) + 1;
$paginationPath = '/admin/work-schedules/assignments';
$paginationQuery = array_filter([
    'scope_type' => $filters['scope_type'] ?? '',
    'department_id' => $filters['department_id'] ?? '',
    'employee_id' => $filters['employee_id'] ?? '',
    'policy_id' => $filters['policy_id'] ?? '',
    'status' => $filters['status'] ?? '',
    'from' => $filters['from'] ?? '',
    'to' => $filters['to'] ?? '',
]);
$paginationQuery['per_page'] = (string) $perPage;
$paginationPageSizeOptions = [20, 50, 100];
$layout = 'layouts/app';
$pageTitle = $t('workforce_policy.assignments.title');
$pageDescription = $t('workforce_policy.assignments.description');
$pageActions = '<div class="sapas-workspace-actions"><a href="/admin/work-schedules" class="sapas-btn sapas-btn-secondary">' . htmlspecialchars($t('work_schedules.back'), ENT_QUOTES, 'UTF-8') . '</a><a href="/admin/work-schedules/assignments/create" class="sapas-btn sapas-btn-primary">' . htmlspecialchars($t('workforce_policy.assignments.create'), ENT_QUOTES, 'UTF-8') . '</a></div>';

$targetLabel = static function (array $row) use ($t): string {
    if (($row['scope_type'] ?? '') === 'company') {
        return $t('workforce_policy.assignments.scope_company');
    }
    if (($row['scope_type'] ?? '') === 'department') {
        return (string) ($row['department_name'] ?? '—');
    }
    $identity = trim((string) ($row['employee_name'] ?? ''));
    $code = trim((string) ($row['staff_code'] ?? ''));
    return $identity . ($code !== '' ? ' (' . $code . ')' : '');
};
?>

<section class="sapas-filter-panel">
    <p class="mb-4 text-sm text-slate-600"><?= htmlspecialchars($t('workforce_policy.assignments.priority_help'), ENT_QUOTES, 'UTF-8') ?></p>
    <form method="GET" action="/admin/work-schedules/assignments" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div>
            <label class="block text-sm font-medium text-slate-700" for="scope-type"><?= htmlspecialchars($t('workforce_policy.assignments.scope'), ENT_QUOTES, 'UTF-8') ?></label>
            <select id="scope-type" name="scope_type" class="mt-1 sapas-form-control">
                <option value=""><?= htmlspecialchars($t('common.all'), ENT_QUOTES, 'UTF-8') ?></option>
                <?php foreach (['company', 'department', 'employee'] as $scope): ?>
                    <option value="<?= $scope ?>" <?= ($filters['scope_type'] ?? '') === $scope ? 'selected' : '' ?>><?= htmlspecialchars($t('workforce_policy.assignments.scope_' . $scope), ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="block text-sm font-medium text-slate-700" for="policy-id"><?= htmlspecialchars($t('workforce_policy.policy_version'), ENT_QUOTES, 'UTF-8') ?></label>
            <select id="policy-id" name="policy_id" class="mt-1 sapas-form-control">
                <option value=""><?= htmlspecialchars($t('common.all'), ENT_QUOTES, 'UTF-8') ?></option>
                <?php foreach ($options['policies'] as $policy): ?>
                    <option value="<?= (int) $policy['id'] ?>" <?= (int) ($filters['policy_id'] ?? 0) === (int) $policy['id'] ? 'selected' : '' ?>><?= htmlspecialchars(\App\Services\BrandingPresenter::policyName($policy['name']), ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="block text-sm font-medium text-slate-700" for="assignment-status"><?= htmlspecialchars($t('common.labels.status'), ENT_QUOTES, 'UTF-8') ?></label>
            <select id="assignment-status" name="status" class="mt-1 sapas-form-control">
                <option value=""><?= htmlspecialchars($t('common.all'), ENT_QUOTES, 'UTF-8') ?></option>
                <?php foreach (['active', 'future', 'historical', 'inactive'] as $status): ?>
                    <option value="<?= $status ?>" <?= ($filters['status'] ?? '') === $status ? 'selected' : '' ?>><?= htmlspecialchars($t('workforce_policy.assignments.status_' . $status), ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="flex items-end gap-2 sm:col-span-2 lg:col-span-1">
            <button type="submit" class="sapas-btn sapas-btn-primary flex-shrink-0"><?= htmlspecialchars($t('common.actions.apply_filters'), ENT_QUOTES, 'UTF-8') ?></button>
            <a href="/admin/work-schedules/assignments" class="sapas-btn sapas-btn-secondary flex-shrink-0"><?= htmlspecialchars($t('common.actions.reset'), ENT_QUOTES, 'UTF-8') ?></a>
        </div>
        <input type="hidden" name="per_page" value="<?= $perPage ?>">
    </form>
</section>

<section class="sapas-report-table-panel mt-5">
    <?php if ($records === []): ?>
        <div class="sapas-report-empty">
            <p class="sapas-report-empty-title"><?= htmlspecialchars($t('workforce_policy.assignments.empty_title'), ENT_QUOTES, 'UTF-8') ?></p>
            <p class="sapas-report-empty-copy"><?= htmlspecialchars($t('workforce_policy.assignments.empty_copy'), ENT_QUOTES, 'UTF-8') ?></p>
        </div>
    <?php else: ?>
        <div class="sapas-data-table-scroll overflow-x-auto">
            <table class="sapas-data-table divide-y divide-slate-200 w-full min-w-[1250px]">
                <colgroup>
                    <col class="w-[60px]">
                    <col class="w-[140px]">
                    <col class="w-[240px]">
                    <col class="w-[200px]">
                    <col class="w-[240px]">
                    <col class="w-[120px]">
                    <col class="w-[100px]">
                    <col class="w-[220px]">
                    <col class="w-[110px]">
                </colgroup>
                <thead>
                    <tr>
                        <th class="sapas-col-row-number text-left align-top"><?= htmlspecialchars($t('common.labels.no'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th class="text-left align-top"><?= htmlspecialchars($t('workforce_policy.assignments.scope'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th class="text-left align-top"><?= htmlspecialchars($t('workforce_policy.assignments.target'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th class="text-left align-top"><?= htmlspecialchars($t('workforce_policy.policy_version'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th class="text-left align-top"><?= htmlspecialchars($t('workforce_policy.assignments.effective_period'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th class="text-center align-top"><?= htmlspecialchars($t('common.labels.status'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th class="text-center align-top"><?= htmlspecialchars($t('workforce_policy.assignments.priority'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th class="text-left align-top"><?= htmlspecialchars($t('workforce_policy.assignments.reason'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th class="text-right align-top"><?= htmlspecialchars($t('common.labels.actions'), ENT_QUOTES, 'UTF-8') ?></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 bg-white">
                    <?php foreach ($records as $index => $row): ?>
                        <tr>
                            <td class="align-top"><span class="sapas-row-number"><?= $rowNumberStart + $index ?></span></td>
                            <td class="align-top break-words"><?= htmlspecialchars($t('workforce_policy.assignments.scope_' . (string) $row['scope_type']), ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="align-top break-words"><span title="<?= htmlspecialchars($targetLabel($row), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($targetLabel($row), ENT_QUOTES, 'UTF-8') ?></span></td>
                            <td class="align-top break-words"><?= htmlspecialchars(\App\Services\BrandingPresenter::policyName($row['policy_name'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="align-top whitespace-nowrap">
                                <span><?= ($row['effective_from'] ?? '') === '1970-01-01' ? htmlspecialchars($t('workforce_policy.assignments.baseline_start') ?: 'Baseline', ENT_QUOTES, 'UTF-8') : htmlspecialchars((string) ($row['effective_from'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></span>
                                <span class="text-slate-400 mx-1">&ndash;</span>
                                <span><?= htmlspecialchars((string) ($row['effective_to'] ?? $t('work_schedules.open_ended')), ENT_QUOTES, 'UTF-8') ?></span>
                            </td>
                            <td class="text-center align-top whitespace-nowrap"><span class="sapas-badge <?= ($row['period_status'] ?? '') === 'active' ? 'sapas-badge-success' : 'sapas-badge-muted' ?>"><?= htmlspecialchars($t('workforce_policy.assignments.status_' . (string) $row['period_status']), ENT_QUOTES, 'UTF-8') ?></span></td>
                            <td class="text-center align-top"><?= (int) ($row['priority'] ?? 0) ?></td>
                            <td class="align-top break-words" title="<?= htmlspecialchars((string) ($row['reason'] ?? '—'), ENT_QUOTES, 'UTF-8') ?>"><?= str_starts_with((string) ($row['reason'] ?? ''), 'Seeded from company') ? htmlspecialchars($t('workforce_policy.assignments.seeded_reason') ?: 'System Baseline', ENT_QUOTES, 'UTF-8') : htmlspecialchars((string) ($row['reason'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="sapas-table-action whitespace-nowrap text-right align-top">
                                <div class="sapas-masterdata-actions flex justify-end gap-2">
                                    <a href="/admin/work-schedules/assignments/edit?id=<?= (int) $row['id'] ?>" class="sapas-btn sapas-btn-secondary"><?= htmlspecialchars($t('common.actions.edit'), ENT_QUOTES, 'UTF-8') ?></a>
                                    <form method="post" action="/admin/work-schedules/assignments/end" class="inline-block">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                        <button type="submit" class="sapas-btn sapas-btn-secondary"><?= htmlspecialchars($t('workforce_policy.assignments.end'), ENT_QUOTES, 'UTF-8') ?></button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/../partials/pagination.php'; ?>
