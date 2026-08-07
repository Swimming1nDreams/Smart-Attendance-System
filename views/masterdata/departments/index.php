<?php
$t = $t ?? static fn (string $key): string => $key;
$search = $search ?? '';
$departments = $departments ?? [];

$layout = 'layouts/app';
$pageTitle = $t('masterdata.departments.title');
$pageDescription = $t('masterdata.departments_description');
$pageActions = '<a href="/departments/create" class="sapas-btn sapas-btn-primary">' . htmlspecialchars($t('masterdata.departments.add'), ENT_QUOTES, 'UTF-8') . '</a> '
    . '<a href="/admin/dashboard" class="sapas-btn sapas-btn-secondary">' . htmlspecialchars($t('masterdata.back_to_dashboard'), ENT_QUOTES, 'UTF-8') . '</a>';

$hasFilters = $search !== '';
$visibleCount = count($departments);
$title = $title ?? $pageTitle;
?>
<div class="sapas-report-table-panel">
    <div class="sapas-report-table-header sapas-masterdata-filter-panel">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h2 class="text-xl font-semibold text-slate-900"><?= htmlspecialchars($t('masterdata.departments.filters'), ENT_QUOTES, 'UTF-8') ?></h2>
                <p class="mt-1 text-sm text-slate-500"><?= htmlspecialchars($t('masterdata.departments.filters_help'), ENT_QUOTES, 'UTF-8') ?></p>
            </div>
            <?php if ($hasFilters): ?>
                <a href="/departments" class="sapas-btn sapas-btn-secondary"><?= htmlspecialchars($t('common.actions.reset'), ENT_QUOTES, 'UTF-8') ?></a>
            <?php endif; ?>
        </div>

        <form method="get" action="/departments" class="sapas-masterdata-search-form">
            <div class="min-w-0 flex-1">
                <label for="search" class="block text-sm font-medium text-slate-700"><?= htmlspecialchars($t('common.actions.search'), ENT_QUOTES, 'UTF-8') ?></label>
                <input
                    type="search"
                    id="search"
                    name="search"
                    value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>"
                    placeholder="<?= htmlspecialchars($t('masterdata.departments.placeholder'), ENT_QUOTES, 'UTF-8') ?>"
                    class="mt-2 sapas-form-control"
                >
            </div>
            <button type="submit" class="sapas-btn sapas-btn-primary w-full sm:w-auto"><?= htmlspecialchars($t('common.actions.apply_filters'), ENT_QUOTES, 'UTF-8') ?></button>
        </form>

        <?php if ($hasFilters): ?>
            <div class="sapas-report-active-filters">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p class="text-sm font-medium text-slate-500"><?= htmlspecialchars($t('common.labels.active_filters'), ENT_QUOTES, 'UTF-8') ?></p>
                        <div class="mt-2 flex flex-wrap gap-2">
                            <span class="sapas-chip sapas-chip-info"><?= htmlspecialchars($t('common.actions.search'), ENT_QUOTES, 'UTF-8') ?>: <?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                    </div>
                    <p class="text-sm text-slate-600"><?= $visibleCount ?> <?= htmlspecialchars($t('masterdata.matching_departments'), ENT_QUOTES, 'UTF-8') ?></p>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <?php if (empty($departments)): ?>
        <div class="sapas-report-empty">
            <p class="sapas-report-empty-title"><?= htmlspecialchars($t('masterdata.departments.empty'), ENT_QUOTES, 'UTF-8') ?></p>
            <p class="sapas-report-empty-copy"><?= htmlspecialchars($hasFilters ? $t('common.empty.try_broader_search') : $t('masterdata.departments.empty_create'), ENT_QUOTES, 'UTF-8') ?></p>
        </div>
    <?php else: ?>
        <div class="sapas-report-table-header sapas-data-table-summary">
            <div class="sapas-report-meta-grid sapas-data-table-summary-grid">
                <div>
                    <p class="sapas-report-meta-label"><?= htmlspecialchars($t('common.showing'), ENT_QUOTES, 'UTF-8') ?></p>
                    <p class="sapas-report-meta-value"><?= $visibleCount ?> <?= htmlspecialchars($t('masterdata.matching_departments'), ENT_QUOTES, 'UTF-8') ?></p>
                </div>
                <div>
                    <p class="sapas-report-meta-label"><?= htmlspecialchars($t('common.scope'), ENT_QUOTES, 'UTF-8') ?></p>
                    <p class="sapas-report-meta-value"><?= htmlspecialchars($hasFilters ? $t('common.filtered_results') : $t('masterdata.all_departments'), ENT_QUOTES, 'UTF-8') ?></p>
                </div>
            </div>
        </div>

        <div class="sapas-data-table-scroll overflow-x-auto">
            <table class="sapas-data-table sapas-masterdata-table sapas-department-table divide-y divide-slate-200">
                <thead>
                    <tr>
                        <th class="sapas-col-row-number"><?= htmlspecialchars($t('common.labels.no'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th class="sapas-col-master-name"><?= htmlspecialchars($t('common.labels.department'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th class="sapas-col-master-description"><?= htmlspecialchars($t('masterdata.description'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th class="sapas-col-master-actions"><?= htmlspecialchars($t('common.labels.actions'), ENT_QUOTES, 'UTF-8') ?></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 bg-white">
                    <?php foreach ($departments as $index => $department): ?>
                        <?php
                        $departmentName = trim((string) ($department['name'] ?? ''));
                        $description = trim((string) ($department['description'] ?? ''));
                        ?>
                        <tr>
                            <td><span class="sapas-row-number"><?= (int) $index + 1 ?></span></td>
                            <td>
                                <p class="sapas-masterdata-name" title="<?= htmlspecialchars($departmentName, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($departmentName, ENT_QUOTES, 'UTF-8') ?></p>
                            </td>
                            <td>
                                <p class="sapas-masterdata-description" title="<?= htmlspecialchars($description !== '' ? $description : '—', ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($description !== '' ? $description : '—', ENT_QUOTES, 'UTF-8') ?></p>
                            </td>
                            <td class="sapas-table-action">
                                <div class="sapas-masterdata-actions">
                                    <a href="/departments/edit?id=<?= (int) $department['id'] ?>" class="sapas-btn sapas-btn-secondary sapas-btn-spotlight"><?= htmlspecialchars($t('common.actions.edit'), ENT_QUOTES, 'UTF-8') ?></a>
                                    <form method="post" action="/departments/delete?id=<?= (int) $department['id'] ?>" onsubmit="return confirm('<?= htmlspecialchars($t('common.confirm.delete_department'), ENT_QUOTES, 'UTF-8') ?>');">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                        <button type="submit" class="sapas-btn sapas-btn-danger-quiet"><?= htmlspecialchars($t('common.actions.delete'), ENT_QUOTES, 'UTF-8') ?></button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
