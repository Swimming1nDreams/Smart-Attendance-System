<?php
$t = $t ?? static fn (string $key): string => $key;
$layout = 'layouts/app';
$title = $t('work_policy.title');
$pageTitle = $t('work_policy.title');
$pageDescription = $t('work_policy.description');
$pageIcon = 'timer';
$policy = is_array($policy ?? null) ? $policy : ['available' => false];

$renderIcon = static function (string $iconName, string $iconClass = 'sapas-icon'): void {
    require __DIR__ . '/../partials/icon.php';
};
?>
<div class="space-y-6">
    <?php if (empty($policy['available'])): ?>
        <section class="sapas-empty-state" aria-labelledby="work-policy-empty-title">
            <span class="sapas-empty-state-icon" aria-hidden="true"><?= $renderIcon('timer', 'sapas-icon') ?></span>
            <h2 id="work-policy-empty-title" class="mt-4 text-lg font-semibold text-slate-900"><?= htmlspecialchars($t('work_policy.no_policy_title'), ENT_QUOTES, 'UTF-8') ?></h2>
            <p class="mt-2 text-sm leading-6 text-slate-600"><?= htmlspecialchars($t('work_policy.no_policy_message'), ENT_QUOTES, 'UTF-8') ?></p>
        </section>
    <?php else: ?>
        <section class="sapas-card p-5 sm:p-6" aria-labelledby="current-policy-title">
            <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
                <div class="min-w-0">
                    <p class="sapas-section-eyebrow"><?= htmlspecialchars($t('work_policy.current_policy'), ENT_QUOTES, 'UTF-8') ?></p>
                    <h2 id="current-policy-title" class="mt-2 text-2xl font-semibold tracking-tight text-slate-950 sm:text-3xl"><?= htmlspecialchars((string) $policy['name'], ENT_QUOTES, 'UTF-8') ?></h2>
                    <div class="mt-4 flex flex-wrap items-center gap-2">
                        <span class="sapas-badge sapas-badge-success"><?= htmlspecialchars((string) $policy['status_label'], ENT_QUOTES, 'UTF-8') ?></span>
                        <?php if (!empty($policy['timezone'])): ?>
                            <span class="sapas-badge sapas-badge-neutral"><?= htmlspecialchars((string) $policy['timezone'], ENT_QUOTES, 'UTF-8') ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="w-full rounded-xl border border-slate-200 bg-slate-50 p-4 lg:max-w-sm">
                    <p class="text-xs font-semibold uppercase tracking-wider text-slate-500"><?= htmlspecialchars($t('work_policy.assignment_source'), ENT_QUOTES, 'UTF-8') ?></p>
                    <p class="mt-2 text-sm font-semibold leading-6 text-slate-900"><?= htmlspecialchars((string) $policy['source_label'], ENT_QUOTES, 'UTF-8') ?></p>
                    <?php if (!empty($policy['source_context'])): ?>
                        <p class="mt-1 text-sm text-slate-600"><?= htmlspecialchars((string) $policy['source_context'], ENT_QUOTES, 'UTF-8') ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <?php $today = is_array($policy['today'] ?? null) ? $policy['today'] : []; ?>
        <section class="rounded-2xl border border-sky-200 bg-sky-50/70 p-5 shadow-sm sm:p-6" aria-labelledby="today-schedule-title">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <p class="sapas-section-eyebrow text-sky-700"><?= htmlspecialchars($t('work_policy.today_schedule'), ENT_QUOTES, 'UTF-8') ?></p>
                    <h2 id="today-schedule-title" class="mt-2 text-2xl font-semibold text-slate-950"><?= htmlspecialchars((string) ($today['schedule_label'] ?? $t('common.not_available')), ENT_QUOTES, 'UTF-8') ?></h2>
                    <p class="mt-2 text-sm text-slate-600"><?= htmlspecialchars((string) ($today['weekday'] ?? ''), ENT_QUOTES, 'UTF-8') ?><?php if (!empty($today['date'])): ?> · <?= htmlspecialchars((string) $today['date'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?></p>
                    <?php if (!empty($today['holiday_name'])): ?>
                        <p class="mt-2 text-sm font-medium text-sky-800"><?= htmlspecialchars((string) $today['holiday_name'], ENT_QUOTES, 'UTF-8') ?></p>
                    <?php endif; ?>
                </div>
                <span class="<?= !empty($today['is_holiday']) ? 'sapas-badge sapas-badge-info' : (!empty($today['is_working_day']) ? 'sapas-badge sapas-badge-success' : 'sapas-badge sapas-badge-neutral') ?>">
                    <?= htmlspecialchars((string) ($today['day_status_label'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                </span>
            </div>

            <?php if (!empty($today['is_working_day'])): ?>
                <dl class="mt-6 grid gap-3 sm:grid-cols-3">
                    <div class="rounded-xl border border-sky-100 bg-white/80 p-4">
                        <dt class="text-xs font-semibold uppercase tracking-wider text-slate-500"><?= htmlspecialchars($t('work_policy.grace_period'), ENT_QUOTES, 'UTF-8') ?></dt>
                        <dd class="mt-2 text-base font-semibold text-slate-900"><?= htmlspecialchars((string) ($today['grace_label'] ?? $t('common.none')), ENT_QUOTES, 'UTF-8') ?></dd>
                    </div>
                    <div class="rounded-xl border border-sky-100 bg-white/80 p-4">
                        <dt class="text-xs font-semibold uppercase tracking-wider text-slate-500"><?= htmlspecialchars($t('work_policy.expected_work'), ENT_QUOTES, 'UTF-8') ?></dt>
                        <dd class="mt-2 text-base font-semibold text-slate-900"><?= htmlspecialchars((string) ($today['expected_work_label'] ?? $t('common.not_available')), ENT_QUOTES, 'UTF-8') ?></dd>
                    </div>
                    <?php if (!empty($today['break_label'])): ?>
                        <div class="rounded-xl border border-sky-100 bg-white/80 p-4">
                            <dt class="text-xs font-semibold uppercase tracking-wider text-slate-500"><?= htmlspecialchars($t('work_policy.break'), ENT_QUOTES, 'UTF-8') ?></dt>
                            <dd class="mt-2 text-base font-semibold text-slate-900"><?= htmlspecialchars((string) $today['break_label'], ENT_QUOTES, 'UTF-8') ?></dd>
                        </div>
                    <?php endif; ?>
                </dl>
            <?php endif; ?>
        </section>

        <section class="sapas-card overflow-hidden" aria-labelledby="weekly-schedule-title">
            <div class="border-b border-slate-200 px-5 py-4 sm:px-6">
                <h2 id="weekly-schedule-title" class="text-xl font-semibold text-slate-950"><?= htmlspecialchars($t('work_policy.weekly_schedule'), ENT_QUOTES, 'UTF-8') ?></h2>
                <p class="mt-1 text-sm leading-6 text-slate-600"><?= htmlspecialchars($t('work_policy.weekly_schedule_help'), ENT_QUOTES, 'UTF-8') ?></p>
            </div>
            <div class="divide-y divide-slate-200">
                <?php foreach ((array) ($policy['weekly_schedule'] ?? []) as $day): ?>
                    <div class="grid gap-3 px-5 py-4 sm:grid-cols-[9rem_minmax(0,1fr)_auto] sm:items-center sm:px-6">
                        <p class="font-semibold text-slate-900"><?= htmlspecialchars((string) ($day['weekday'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-slate-700"><?= htmlspecialchars((string) ($day['schedule_label'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
                            <?php if (!empty($day['break_label'])): ?>
                                <p class="mt-1 text-xs text-slate-500"><?= htmlspecialchars($t('work_policy.break'), ENT_QUOTES, 'UTF-8') ?>: <?= htmlspecialchars((string) $day['break_label'], ENT_QUOTES, 'UTF-8') ?></p>
                            <?php endif; ?>
                        </div>
                        <span class="<?= !empty($day['is_working_day']) ? 'sapas-badge sapas-badge-success' : 'sapas-badge sapas-badge-neutral' ?>"><?= htmlspecialchars((string) ($day['status_label'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <div class="grid gap-6 lg:grid-cols-2">
            <?php foreach ([
                ['title' => $t('work_policy.attendance_rules'), 'help' => $t('work_policy.attendance_rules_help'), 'rules' => $policy['attendance_rules'] ?? [], 'icon' => 'shield'],
                ['title' => $t('work_policy.overtime_rules'), 'help' => $t('work_policy.overtime_rules_help'), 'rules' => $policy['overtime_rules'] ?? [], 'icon' => 'timer'],
            ] as $section): ?>
                <section class="sapas-card p-5 sm:p-6">
                    <div class="sapas-card-heading">
                        <span class="sapas-card-icon is-brand"><?= $renderIcon((string) $section['icon'], 'sapas-icon') ?></span>
                        <div>
                            <h2 class="text-xl font-semibold text-slate-950"><?= htmlspecialchars((string) $section['title'], ENT_QUOTES, 'UTF-8') ?></h2>
                            <p class="mt-1 text-sm leading-6 text-slate-600"><?= htmlspecialchars((string) $section['help'], ENT_QUOTES, 'UTF-8') ?></p>
                        </div>
                    </div>
                    <dl class="mt-5 divide-y divide-slate-200 rounded-xl border border-slate-200 bg-slate-50/70 px-4">
                        <?php foreach ((array) $section['rules'] as $rule): ?>
                            <div class="flex flex-col gap-1 py-3 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
                                <dt class="text-sm text-slate-600"><?= htmlspecialchars((string) ($rule['label'] ?? ''), ENT_QUOTES, 'UTF-8') ?></dt>
                                <dd class="text-sm font-semibold text-slate-900"><?= htmlspecialchars((string) ($rule['value'] ?? ''), ENT_QUOTES, 'UTF-8') ?></dd>
                            </div>
                        <?php endforeach; ?>
                    </dl>
                </section>
            <?php endforeach; ?>
        </div>

        <p class="text-sm leading-6 text-slate-500"><?= htmlspecialchars($t('work_policy.read_only_note'), ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>
</div>
