<?php
$t = $t ?? static fn (string $key): string => $key;
$schedules = $schedules ?? [];
$profile = $profile ?? null;
$overview = $overview ?? [];
$selectedPolicy = $selectedPolicy ?? ($schedules[0] ?? null);
$attendanceRules = $attendanceRules ?? [];
$overtimeRules = $overtimeRules ?? [];
$errors = $errors ?? [];
$cloneInput = $cloneInput ?? [];
$section = (string) ($section ?? 'overview');
$createMode = (bool) ($createMode ?? false);
$layout = 'layouts/app';
$pageTitle = $t('work_schedules.title');
$pageDescription = $t('workforce_policy.ux.short_explanation');
$pageActions = '<a href="/admin/work-schedules?section=work-rules&mode=create" class="sapas-btn sapas-btn-primary">' . htmlspecialchars($t('workforce_policy.ux.create_new_work_policy'), ENT_QUOTES, 'UTF-8') . '</a>';
$title = $title ?? $pageTitle;
$dash = '—';
$policyId = (int) ($selectedPolicy['id'] ?? 0);
$isEditable = !empty($selectedPolicy['is_editable_policy']);
$days = $selectedPolicy['days'] ?? [];
$current = $overview['current'] ?? null;
$upcoming = $overview['upcoming'] ?? null;
$organizationValue = static fn (string $key, mixed $value): string => \App\Services\BrandingPresenter::companyValue(
    $key,
    $value,
    $t('app.official_name'),
    $t('app.acronym'),
);
$policyName = static fn (mixed $value): string => \App\Services\BrandingPresenter::policyName($value);
$fieldError = static function (array $errors, string $group): array {
    $messages = [];
    foreach (($errors[$group] ?? []) as $fieldErrors) {
        foreach ((array) $fieldErrors as $message) {
            $messages[] = (string) $message;
        }
    }
    return $messages;
};
$checked = static fn (array $row, string $key): string => (int) ($row[$key] ?? 0) === 1 ? 'checked' : '';
$boolText = static fn (mixed $value): string => (int) $value === 1 ? $t('workforce_policy.ux.allowed') : $t('workforce_policy.ux.not_allowed');
$enabledText = static fn (mixed $value): string => (int) $value === 1 ? $t('workforce_policy.ux.enabled') : $t('workforce_policy.ux.disabled');
$minutesText = static function (mixed $minutes) use ($t): string {
    $minutes = max(0, (int) $minutes);
    if ($minutes >= 60 && $minutes % 60 === 0) {
        return (int) ($minutes / 60) . ' ' . $t('common.units.hours');
    }
    return $minutes . ' ' . $t('common.units.minutes');
};
$displayTime = static function (mixed $time): string {
    $time = trim((string) $time);
    return preg_match('/^\d{2}:\d{2}/', $time) === 1 ? substr($time, 0, 5) : $time;
};
$timeRange = static function (array $day) use ($t, $dash): string {
    if ((int) ($day['is_working_day'] ?? 0) !== 1) {
        return $dash;
    }
    $start = trim((string) ($day['start_time'] ?? ''));
    $end = trim((string) ($day['end_time'] ?? ''));
    if ($start === '' || $end === '') {
        return $dash;
    }
    $overnight = (int) ($day['is_overnight'] ?? 0) === 1 ? ' (' . $t('workforce_policy.ux.next_day') . ')' : '';
    $formatTime = static fn (string $time): string => preg_match('/^\d{2}:\d{2}/', $time) === 1 ? substr($time, 0, 5) : $time;
    return htmlspecialchars($formatTime($start) . '-' . $formatTime($end) . $overnight, ENT_QUOTES, 'UTF-8');
};
$breakSummary = static function (array $day) use ($t, $dash, $displayTime): string {
    if ((int) ($day['is_working_day'] ?? 0) !== 1) {
        return $dash;
    }
    $start = trim((string) ($day['break_start_time'] ?? ''));
    $end = trim((string) ($day['break_end_time'] ?? ''));
    if ($start === '' || $end === '' || (int) ($day['break_minutes'] ?? 0) <= 0) {
        return htmlspecialchars($t('workforce_policy.ux.no_unpaid_break'), ENT_QUOTES, 'UTF-8');
    }
    return htmlspecialchars($displayTime($start) . '-' . $displayTime($end), ENT_QUOTES, 'UTF-8');
};
$friendlyState = static function (array $schedule) use ($t): string {
    $state = (string) ($schedule['lifecycle_status'] ?? 'active');
    if ($state === 'active') {
        return $t('workforce_policy.ux.currently_active');
    }
    if ($state === 'archived') {
        return $t('workforce_policy.ux.previous_policy');
    }
    return $t('workforce_policy.lifecycle.' . $state);
};
$workingDayNames = [];
$dailyGroups = [];
for ($summaryDay = 1; $summaryDay <= 7; $summaryDay++) {
    $summaryDayData = $days[$summaryDay] ?? ['is_working_day' => 0];
    if ((int) ($summaryDayData['is_working_day'] ?? 0) !== 1) {
        continue;
    }
    $workingDayNames[] = $t('work_schedules.weekdays.' . $summaryDay);
    $dailyGroups[] = $t('work_schedules.weekdays.' . $summaryDay) . ': '
        . strip_tags($timeRange($summaryDayData))
        . ' | ' . strip_tags($breakSummary($summaryDayData))
        . ' | ' . $minutesText($summaryDayData['grace_minutes'] ?? 0);
}
$reviewAttendanceSummary = [
    $t('workforce_policy.attendance_rules.allow_non_working_day_check_in') . ': ' . $boolText($attendanceRules['allow_non_working_day_check_in'] ?? 0),
    $t('workforce_policy.attendance_rules.allow_holiday_check_in') . ': ' . $boolText($attendanceRules['allow_holiday_check_in'] ?? 0),
    $t('workforce_policy.missing_check_out_mode') . ': ' . $t('workforce_policy.missing_modes.' . (string) ($attendanceRules['missing_check_out_mode'] ?? 'mark_incomplete')),
];
$reviewOvertimeSummary = [
    $t('workforce_policy.overtime_rules.after_hours_work_eligible') . ': ' . $enabledText($overtimeRules['after_hours_work_eligible'] ?? 0),
    $t('workforce_policy.overtime_rules.holiday_work_eligible') . ': ' . $enabledText($overtimeRules['holiday_work_eligible'] ?? 0),
    $t('workforce_policy.overtime_rules.minimum_overtime_minutes') . ': ' . $minutesText($overtimeRules['minimum_overtime_minutes'] ?? 0),
    $t('workforce_policy.rounding_mode') . ': ' . $t('workforce_policy.rounding_modes.' . (string) ($overtimeRules['rounding_mode'] ?? 'exact')),
    $t('workforce_policy.overtime_rules.approval_required') . ': ' . $enabledText($overtimeRules['approval_required'] ?? 0),
];
$primaryNav = [
    'overview' => ['/admin/work-schedules?section=overview', 'overview'],
    'work-rules' => ['/admin/work-schedules?section=work-rules' . ($policyId > 0 ? '&policy_id=' . $policyId : ''), 'work_rules'],
    'assignments' => ['/admin/work-schedules/assignments', 'assignments'],
    'overtime-review' => ['/admin/overtime', 'overtime_review'],
];
$workspaceDescriptions = [
    'overview' => $t('workforce_policy.ux.workspace_overview_help'),
    'work-rules' => $t('workforce_policy.ux.workspace_work_rules_help'),
    'assignments' => $t('workforce_policy.ux.workspace_assignments_help'),
    'overtime-review' => $t('workforce_policy.ux.workspace_overtime_help'),
];
$stepCards = [
    ['href' => '#weekly-schedule', 'step' => $t('workforce_policy.ux.step_2'), 'title' => $t('workforce_policy.tabs.weekly_schedule'), 'copy' => $t('workforce_policy.ux.weekly_schedule_help')],
    ['href' => '#attendance-rules', 'step' => $t('workforce_policy.ux.step_3'), 'title' => $t('workforce_policy.tabs.attendance_rules'), 'copy' => $t('workforce_policy.ux.attendance_rules_help')],
    ['href' => '#overtime-rules', 'step' => $t('workforce_policy.ux.step_4'), 'title' => $t('workforce_policy.tabs.overtime_rules'), 'copy' => $t('workforce_policy.ux.overtime_rules_help')],
    ['href' => '#review-changes', 'step' => $t('workforce_policy.ux.step_5'), 'title' => $t('workforce_policy.ux.review_changes'), 'copy' => $t('workforce_policy.ux.schedule_help')],
];
?>

<div class="sapas-policy-center mx-auto max-w-7xl space-y-4">
    <div class="sapas-policy-nav-row flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 border-b border-slate-200 pb-4">
        <nav class="sapas-policy-workspace-nav flex flex-wrap gap-2 sm:gap-4" aria-label="<?= htmlspecialchars($t('workforce_policy.section_navigation'), ENT_QUOTES, 'UTF-8') ?>">
            <?php foreach ($primaryNav as $key => [$href, $label]): ?>
                <?php $active = $section === $key; ?>
                <a href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>" class="sapas-policy-workspace-link px-3 py-2 text-sm font-medium rounded-md transition-colors <?= $active ? 'bg-slate-100 text-slate-900 is-active' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900' ?>" title="<?= htmlspecialchars($workspaceDescriptions[$key] ?? '', ENT_QUOTES, 'UTF-8') ?>" <?= $active ? 'aria-current="page"' : '' ?>>
                    <span><?= htmlspecialchars($t('workforce_policy.tabs.' . $label), ENT_QUOTES, 'UTF-8') ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
        <details class="sapas-policy-advanced">
            <summary class="sapas-btn sapas-btn-secondary cursor-pointer"><?= htmlspecialchars($t('workforce_policy.ux.advanced'), ENT_QUOTES, 'UTF-8') ?></summary>
            <div class="mt-2 min-w-56 rounded-lg border border-slate-200 bg-white p-2 shadow-sm lg:absolute lg:right-0 lg:z-10">
                <p class="px-3 py-2 text-xs font-semibold text-slate-500"><?= htmlspecialchars($t('workforce_policy.ux.advanced_help'), ENT_QUOTES, 'UTF-8') ?></p>
                <a class="block rounded-md px-3 py-2 text-sm text-slate-700 hover:bg-slate-50 focus:bg-slate-50" href="/admin/work-schedules?section=history"><?= htmlspecialchars($t('workforce_policy.tabs.policy_history'), ENT_QUOTES, 'UTF-8') ?></a>
                <a class="block rounded-md px-3 py-2 text-sm text-slate-700 hover:bg-slate-50 focus:bg-slate-50" href="/admin/work-schedules?section=organization"><?= htmlspecialchars($t('workforce_policy.tabs.organization_information'), ENT_QUOTES, 'UTF-8') ?></a>
            </div>
        </details>
    </div>

    <?php if ($section === 'overview'): ?>
        <section class="sapas-report-table-panel sapas-policy-overview-card p-6">
            <div class="sapas-policy-overview-head flex flex-col lg:flex-row lg:items-start lg:justify-between gap-6">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wider text-slate-500"><?= htmlspecialchars($t('workforce_policy.ux.current_work_policy'), ENT_QUOTES, 'UTF-8') ?></p>
                    <h2 class="mt-1 text-2xl font-semibold text-slate-900"><?= htmlspecialchars($policyName($current['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?: $dash ?></h2>
                    <p class="mt-2 text-sm text-slate-600 max-w-2xl"><?= htmlspecialchars($t('workforce_policy.ux.short_explanation'), ENT_QUOTES, 'UTF-8') ?></p>
                </div>
                <div class="sapas-policy-overview-actions flex flex-wrap items-center gap-3 lg:justify-end">
                    <a href="/admin/work-schedules?section=work-rules&mode=create&source_mode=current<?= $current !== null ? '&source_policy_id=' . (int) ($current['id'] ?? 0) : '' ?>" class="sapas-btn sapas-btn-primary whitespace-nowrap"><?= htmlspecialchars($t('workforce_policy.ux.change_work_rules'), ENT_QUOTES, 'UTF-8') ?></a>
                    <a href="/admin/work-schedules/assignments" class="sapas-btn sapas-btn-secondary whitespace-nowrap"><?= htmlspecialchars($t('workforce_policy.actions.manage_assignments'), ENT_QUOTES, 'UTF-8') ?></a>
                    <a href="/admin/overtime" class="sapas-btn sapas-btn-secondary whitespace-nowrap"><?= htmlspecialchars($t('workforce_policy.actions.review_overtime'), ENT_QUOTES, 'UTF-8') ?></a>
                </div>
            </div>
            <dl class="sapas-policy-overview-grid mt-8 grid grid-cols-2 lg:grid-cols-6 gap-6 border-t border-slate-200 pt-6">
                <div class="sapas-policy-overview-stat"><dt class="text-xs font-semibold uppercase tracking-wider text-slate-500"><?= htmlspecialchars($t('work_schedules.fields.working_days'), ENT_QUOTES, 'UTF-8') ?></dt><dd class="mt-2 text-sm font-medium text-slate-900"><?= htmlspecialchars((string) ($current['working_days_summary'] ?? ''), ENT_QUOTES, 'UTF-8') ?: $dash ?></dd></div>
                <div class="sapas-policy-overview-stat"><dt class="text-xs font-semibold uppercase tracking-wider text-slate-500"><?= htmlspecialchars($t('workforce_policy.ux.working_time'), ENT_QUOTES, 'UTF-8') ?></dt><dd class="mt-2 text-sm font-medium text-slate-900"><?= htmlspecialchars((string) ($current['daily_time_summary'] ?? ''), ENT_QUOTES, 'UTF-8') ?: $dash ?></dd></div>
                <div class="sapas-policy-overview-stat"><dt class="text-xs font-semibold uppercase tracking-wider text-slate-500"><?= htmlspecialchars($t('workforce_policy.ux.break'), ENT_QUOTES, 'UTF-8') ?></dt><dd class="mt-2 text-sm font-medium text-slate-900"><?= $breakSummary($current['days'][0] ?? []) ?></dd></div>
                <div class="sapas-policy-overview-stat"><dt class="text-xs font-semibold uppercase tracking-wider text-slate-500"><?= htmlspecialchars($t('workforce_policy.ux.late_grace_period'), ENT_QUOTES, 'UTF-8') ?></dt><dd class="mt-2 text-sm font-medium text-slate-900"><?= $minutesText(($current['days'][0]['grace_minutes'] ?? 0)) ?></dd></div>
                <div class="sapas-policy-overview-stat"><dt class="text-xs font-semibold uppercase tracking-wider text-slate-500"><?= htmlspecialchars($t('workforce_policy.ux.overtime'), ENT_QUOTES, 'UTF-8') ?></dt><dd class="mt-2 text-sm font-medium text-slate-900"><?= htmlspecialchars($t('workforce_policy.ux.automatically_calculated'), ENT_QUOTES, 'UTF-8') ?></dd></div>
                <div class="sapas-policy-overview-stat"><dt class="text-xs font-semibold uppercase tracking-wider text-slate-500"><?= htmlspecialchars($t('workforce_policy.ux.effective_since'), ENT_QUOTES, 'UTF-8') ?></dt><dd class="mt-2 text-sm font-medium text-slate-900"><?= htmlspecialchars((string) ($current['effective_from'] ?? ''), ENT_QUOTES, 'UTF-8') ?: $dash ?></dd></div>
            </dl>
            <div class="sapas-policy-kpi-strip mt-6 grid grid-cols-1 md:grid-cols-3 gap-4 rounded-xl bg-slate-50 p-4 border border-slate-100">
                <div class="sapas-policy-kpi-item flex flex-col"><dt class="text-xs font-medium text-slate-500"><?= htmlspecialchars($t('workforce_policy.department_overrides'), ENT_QUOTES, 'UTF-8') ?></dt><dd class="mt-1 text-2xl font-bold text-slate-900"><?= (int) ($overview['department_overrides'] ?? 0) ?></dd></div>
                <div class="sapas-policy-kpi-item flex flex-col"><dt class="text-xs font-medium text-slate-500"><?= htmlspecialchars($t('workforce_policy.employee_overrides'), ENT_QUOTES, 'UTF-8') ?></dt><dd class="mt-1 text-2xl font-bold text-slate-900"><?= (int) ($overview['employee_overrides'] ?? 0) ?></dd></div>
                <div class="sapas-policy-kpi-item flex flex-col"><dt class="text-xs font-medium text-slate-500"><?= htmlspecialchars($t('workforce_policy.pending_overtime'), ENT_QUOTES, 'UTF-8') ?></dt><dd class="mt-1 text-2xl font-bold text-slate-900"><?= (int) ($overview['pending_overtime_count'] ?? 0) ?></dd></div>
            </div>
        </section>

        <?php if ($upcoming !== null): ?>
            <section class="sapas-report-table-panel p-6 mt-6">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wider text-slate-500"><?= htmlspecialchars($t('workforce_policy.upcoming_change'), ENT_QUOTES, 'UTF-8') ?></p>
                        <h2 class="mt-1 text-xl font-semibold text-slate-900"><?= htmlspecialchars($policyName($upcoming['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></h2>
                        <p class="mt-2 text-sm text-slate-600"><?= htmlspecialchars($t('workforce_policy.effective_period'), ENT_QUOTES, 'UTF-8') ?>: <span class="font-medium text-slate-900"><?= htmlspecialchars((string) ($upcoming['effective_from'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span></p>
                        <p class="mt-1 text-sm text-slate-600"><?= htmlspecialchars((string) ($upcoming['working_days_summary'] ?? ''), ENT_QUOTES, 'UTF-8') ?> &middot; <?= htmlspecialchars((string) ($upcoming['daily_time_summary'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                    <div class="flex flex-wrap items-center gap-3 lg:justify-end">
                        <a class="sapas-btn sapas-btn-secondary whitespace-nowrap" href="/admin/work-schedules?section=work-rules&policy_id=<?= (int) $upcoming['id'] ?>"><?= htmlspecialchars($t('workforce_policy.ux.review'), ENT_QUOTES, 'UTF-8') ?></a>
                        <?php if (!empty($upcoming['is_editable_policy'])): ?><a class="sapas-btn sapas-btn-secondary whitespace-nowrap" href="/admin/work-schedules?section=work-rules&policy_id=<?= (int) $upcoming['id'] ?>"><?= htmlspecialchars($t('common.actions.edit'), ENT_QUOTES, 'UTF-8') ?></a><?php endif; ?>
                        <form method="post" action="/admin/work-schedules/cancel-schedule" class="inline-block"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token ?? '', ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="id" value="<?= (int) $upcoming['id'] ?>"><button class="sapas-btn sapas-btn-secondary whitespace-nowrap" type="submit"><?= htmlspecialchars($t('workforce_policy.ux.cancel_scheduled_change'), ENT_QUOTES, 'UTF-8') ?></button></form>
                    </div>
                </div>
            </section>
        <?php endif; ?>

    <?php elseif ($section === 'work-rules'): ?>
        <section class="sapas-policy-flow-panel">
            <div>
                <p class="sapas-policy-eyebrow"><?= htmlspecialchars($t('workforce_policy.ux.work_rules_guide'), ENT_QUOTES, 'UTF-8') ?></p>
                <h2><?= htmlspecialchars($createMode ? $t('workforce_policy.ux.create_new_work_policy') : ($isEditable ? $t('workforce_policy.ux.editing_policy') : $t('workforce_policy.ux.current_rules')), ENT_QUOTES, 'UTF-8') ?></h2>
                <p><?= htmlspecialchars($isEditable ? $t('workforce_policy.ux.draft_flow_help') : $t('workforce_policy.ux.locked_flow_help'), ENT_QUOTES, 'UTF-8') ?></p>
            </div>
            <div class="sapas-policy-step-grid">
                <?php if ($createMode): ?>
                    <a href="#basic-setup" class="sapas-policy-step-card is-active">
                        <span><?= htmlspecialchars($t('workforce_policy.ux.step_1'), ENT_QUOTES, 'UTF-8') ?></span>
                        <strong><?= htmlspecialchars($t('workforce_policy.ux.basic_setup'), ENT_QUOTES, 'UTF-8') ?></strong>
                        <small><?= htmlspecialchars($t('workforce_policy.ux.basic_setup_help'), ENT_QUOTES, 'UTF-8') ?></small>
                    </a>
                <?php endif; ?>
                <?php foreach ($stepCards as $card): ?>
                    <a href="<?= htmlspecialchars($card['href'], ENT_QUOTES, 'UTF-8') ?>" class="sapas-policy-step-card <?= !$isEditable && $card['href'] === '#review-changes' ? 'is-muted' : '' ?>">
                        <span><?= htmlspecialchars($card['step'], ENT_QUOTES, 'UTF-8') ?></span>
                        <strong><?= htmlspecialchars($card['title'], ENT_QUOTES, 'UTF-8') ?></strong>
                        <small><?= htmlspecialchars($card['copy'], ENT_QUOTES, 'UTF-8') ?></small>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>

        <?php if ($createMode): ?>
            <section id="basic-setup" class="sapas-report-table-panel sapas-policy-section-panel">
                <div class="sapas-policy-section-heading">
                    <div>
                    <p class="text-xs font-semibold uppercase text-slate-500"><?= htmlspecialchars($t('workforce_policy.ux.step_1'), ENT_QUOTES, 'UTF-8') ?></p>
                    <h2 class="mt-1 text-lg font-semibold text-slate-900"><?= htmlspecialchars($t('workforce_policy.ux.basic_setup'), ENT_QUOTES, 'UTF-8') ?></h2>
                    <p class="mt-1 text-sm text-slate-500"><?= htmlspecialchars($t('workforce_policy.ux.basic_setup_help'), ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                </div>
                <?php foreach ($fieldError($errors, 'clone') as $message): ?><p class="mb-2 text-sm text-rose-700"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p><?php endforeach; ?>
                <form method="post" action="/admin/work-schedules/clone" class="grid gap-4 md:grid-cols-2">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token ?? '', ENT_QUOTES, 'UTF-8') ?>">
                    <div>
                        <label class="block text-sm font-medium text-slate-700" for="policy-name"><?= htmlspecialchars($t('workforce_policy.ux.policy_name'), ENT_QUOTES, 'UTF-8') ?></label>
                        <input id="policy-name" name="name" class="mt-2 sapas-form-control" value="<?= htmlspecialchars((string) ($cloneInput['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700" for="policy-effective-from"><?= htmlspecialchars($t('workforce_policy.ux.future_effective_date'), ENT_QUOTES, 'UTF-8') ?></label>
                        <input id="policy-effective-from" type="date" name="effective_from" class="mt-2 sapas-form-control" value="<?= htmlspecialchars((string) ($cloneInput['effective_from'] ?? date('Y-m-d', strtotime('+1 day'))), ENT_QUOTES, 'UTF-8') ?>" required>
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-slate-700" for="policy-reason"><?= htmlspecialchars($t('workforce_policy.ux.reason_for_change'), ENT_QUOTES, 'UTF-8') ?></label>
                        <textarea id="policy-reason" name="change_reason" rows="2" class="mt-2 sapas-form-control" required><?= htmlspecialchars((string) ($cloneInput['change_reason'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                    </div>
                    <fieldset class="md:col-span-2">
                        <legend class="text-sm font-medium text-slate-700"><?= htmlspecialchars($t('workforce_policy.ux.start_from'), ENT_QUOTES, 'UTF-8') ?></legend>
                        <div class="mt-2 grid gap-2 md:grid-cols-3">
                            <label class="rounded-lg border border-slate-200 p-3 text-sm"><input type="radio" name="source_mode" value="current" <?= ($cloneInput['source_mode'] ?? 'current') === 'current' ? 'checked' : '' ?>> <?= htmlspecialchars($t('workforce_policy.ux.current_active_policy'), ENT_QUOTES, 'UTF-8') ?></label>
                            <label class="rounded-lg border border-slate-200 p-3 text-sm"><input type="radio" name="source_mode" value="blank" <?= ($cloneInput['source_mode'] ?? '') === 'blank' ? 'checked' : '' ?>> <?= htmlspecialchars($t('workforce_policy.ux.blank_policy'), ENT_QUOTES, 'UTF-8') ?></label>
                            <label class="rounded-lg border border-slate-200 p-3 text-sm"><input type="radio" name="source_mode" value="existing" <?= ($cloneInput['source_mode'] ?? '') === 'existing' ? 'checked' : '' ?>> <?= htmlspecialchars($t('workforce_policy.ux.existing_policy'), ENT_QUOTES, 'UTF-8') ?></label>
                        </div>
                        <select name="source_policy_id" class="mt-3 sapas-form-control">
                            <option value=""><?= htmlspecialchars($t('workforce_policy.ux.choose_existing_policy'), ENT_QUOTES, 'UTF-8') ?></option>
                            <?php foreach ($schedules as $schedule): ?><option value="<?= (int) $schedule['id'] ?>" <?= (int) ($cloneInput['source_policy_id'] ?? 0) === (int) $schedule['id'] ? 'selected' : '' ?>><?= htmlspecialchars($policyName($schedule['name']), ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?>
                        </select>
                    </fieldset>
                    <div class="md:col-span-2 flex justify-end gap-2">
                        <a href="/admin/work-schedules?section=overview" class="sapas-btn sapas-btn-secondary"><?= htmlspecialchars($t('common.actions.cancel'), ENT_QUOTES, 'UTF-8') ?></a>
                        <button class="sapas-btn sapas-btn-primary" type="submit"><?= htmlspecialchars($t('workforce_policy.ux.create_new_work_policy'), ENT_QUOTES, 'UTF-8') ?></button>
                    </div>
                </form>
            </section>
        <?php endif; ?>

        <?php if ($selectedPolicy !== null): ?>
            <section class="sapas-policy-state-panel <?= $isEditable ? 'is-draft' : 'is-protected' ?>">
                <div class="sapas-policy-state-copy">
                    <div>
                        <p class="text-xs font-semibold uppercase text-slate-500"><?= htmlspecialchars($isEditable ? $t('workforce_policy.ux.editing_policy') : $t('workforce_policy.ux.current_rules'), ENT_QUOTES, 'UTF-8') ?></p>
                        <h2 class="mt-1 text-lg font-semibold text-slate-900"><?= htmlspecialchars($policyName($selectedPolicy['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></h2>
                        <p class="mt-1 text-sm text-slate-600"><?= htmlspecialchars($isEditable ? $t('workforce_policy.ux.changes_not_active') : $t('workforce_policy.ux.protected_help'), ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                    <dl>
                        <div><dt><?= htmlspecialchars($t('workforce_policy.ux.future_effective_date'), ENT_QUOTES, 'UTF-8') ?></dt><dd><?= htmlspecialchars((string) (($selectedPolicy['effective_from'] ?? '') ?: strip_tags($dash)), ENT_QUOTES, 'UTF-8') ?></dd></div>
                        <div><dt><?= htmlspecialchars($t('work_schedules.fields.working_days'), ENT_QUOTES, 'UTF-8') ?></dt><dd><?= htmlspecialchars(implode(', ', $workingDayNames) ?: $dash, ENT_QUOTES, 'UTF-8') ?></dd></div>
                    </dl>
                </div>
                <div class="sapas-policy-state-actions">
                    <?php if (!$isEditable): ?>
                        <a href="/admin/work-schedules?section=work-rules&mode=create&source_mode=current&source_policy_id=<?= $policyId ?>" class="sapas-btn sapas-btn-primary"><?= htmlspecialchars($t('workforce_policy.ux.change_these_rules'), ENT_QUOTES, 'UTF-8') ?></a>
                    <?php else: ?>
                        <a href="#review-changes" class="sapas-btn sapas-btn-secondary"><?= htmlspecialchars($t('workforce_policy.ux.review_changes'), ENT_QUOTES, 'UTF-8') ?></a>
                    <?php endif; ?>
                </div>
            </section>

            <section id="weekly-schedule" class="sapas-report-table-panel sapas-policy-section-panel">
                <div class="sapas-policy-section-heading">
                    <div><p class="text-xs font-semibold uppercase text-slate-500"><?= htmlspecialchars($t('workforce_policy.ux.step_2'), ENT_QUOTES, 'UTF-8') ?></p><h2 class="mt-1 text-lg font-semibold text-slate-900"><?= htmlspecialchars($t('workforce_policy.tabs.weekly_schedule'), ENT_QUOTES, 'UTF-8') ?></h2><p class="mt-1 text-sm text-slate-500"><?= htmlspecialchars($t('workforce_policy.ux.weekly_schedule_help'), ENT_QUOTES, 'UTF-8') ?></p></div>
                    <?php if ($isEditable): ?><div class="flex flex-wrap gap-2 text-sm"><button type="button" class="sapas-btn sapas-btn-secondary" data-schedule-preset="standard"><?= htmlspecialchars($t('workforce_policy.ux.standard_monday_friday'), ENT_QUOTES, 'UTF-8') ?></button><button type="button" class="sapas-btn sapas-btn-secondary" data-schedule-preset="monday-saturday"><?= htmlspecialchars($t('workforce_policy.ux.monday_saturday'), ENT_QUOTES, 'UTF-8') ?></button><button type="button" class="sapas-btn sapas-btn-secondary" data-schedule-preset="sunday-thursday"><?= htmlspecialchars($t('workforce_policy.ux.sunday_thursday'), ENT_QUOTES, 'UTF-8') ?></button><button type="button" class="sapas-btn sapas-btn-secondary" data-copy-monday-weekdays><?= htmlspecialchars($t('workforce_policy.ux.apply_monday_to_weekdays'), ENT_QUOTES, 'UTF-8') ?></button></div><?php endif; ?>
                </div>
                <?php foreach ($fieldError($errors, 'weekly') as $message): ?><p class="mb-2 text-sm text-rose-700"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p><?php endforeach; ?>
                <?php if (!$isEditable): ?>
                    <div class="sapas-data-table-scroll overflow-x-auto">
                        <table class="sapas-data-table divide-y divide-slate-200">
                            <thead><tr><th><?= htmlspecialchars($t('common.units.day'), ENT_QUOTES, 'UTF-8') ?></th><th><?= htmlspecialchars($t('workforce_policy.ux.working_day'), ENT_QUOTES, 'UTF-8') ?></th><th><?= htmlspecialchars($t('workforce_policy.ux.work_time'), ENT_QUOTES, 'UTF-8') ?></th><th><?= htmlspecialchars($t('workforce_policy.ux.break'), ENT_QUOTES, 'UTF-8') ?></th><th><?= htmlspecialchars($t('workforce_policy.ux.late_grace_period'), ENT_QUOTES, 'UTF-8') ?></th></tr></thead>
                            <tbody class="divide-y divide-slate-200 bg-white"><?php for ($day = 1; $day <= 7; $day++): ?><?php $dayData = $days[$day] ?? ['is_working_day' => 0]; ?><tr><td><?= htmlspecialchars($t('work_schedules.weekdays.' . $day), ENT_QUOTES, 'UTF-8') ?></td><td><?= (int) ($dayData['is_working_day'] ?? 0) === 1 ? htmlspecialchars($t('workforce_policy.ux.working_day'), ENT_QUOTES, 'UTF-8') : htmlspecialchars($t('workforce_policy.ux.non_working_day'), ENT_QUOTES, 'UTF-8') ?></td><td><?= $timeRange($dayData) ?></td><td><?= $breakSummary($dayData) ?></td><td><?= (int) ($dayData['is_working_day'] ?? 0) === 1 ? $minutesText($dayData['grace_minutes'] ?? 0) : $dash ?></td></tr><?php endfor; ?></tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <form method="post" action="/admin/work-schedules/update-weekly-schedule?id=<?= $policyId ?>" class="space-y-4">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token ?? '', ENT_QUOTES, 'UTF-8') ?>">
                        <div class="sapas-policy-day-grid">
                            <?php for ($day = 1; $day <= 7; $day++): ?>
                                <?php $dayData = $days[$day] ?? ['is_working_day' => 0]; $isWorking = (int) ($dayData['is_working_day'] ?? 0) === 1; $hasBreak = (int) ($dayData['break_minutes'] ?? 0) > 0 && trim((string) ($dayData['break_start_time'] ?? '')) !== '' && trim((string) ($dayData['break_end_time'] ?? '')) !== ''; ?>
                                <section class="sapas-policy-day-card <?= $isWorking ? '' : 'is-non-working' ?>" data-work-schedule-row data-dash-label="<?= htmlspecialchars($dash, ENT_QUOTES, 'UTF-8') ?>" data-no-break-label="<?= htmlspecialchars($t('workforce_policy.ux.no_unpaid_break'), ENT_QUOTES, 'UTF-8') ?>" data-minutes-label="<?= htmlspecialchars($t('common.units.minutes'), ENT_QUOTES, 'UTF-8') ?>" data-next-day-label="<?= htmlspecialchars($t('workforce_policy.ux.next_day'), ENT_QUOTES, 'UTF-8') ?>" data-on-label="<?= htmlspecialchars($t('workforce_policy.ux.working'), ENT_QUOTES, 'UTF-8') ?>" data-off-label="<?= htmlspecialchars($t('workforce_policy.ux.non_working'), ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="days[<?= $day ?>][day_present]" value="1">
                                    <div class="sapas-policy-day-header">
                                        <div>
                                            <h3><?= htmlspecialchars($t('work_schedules.weekdays.' . $day), ENT_QUOTES, 'UTF-8') ?></h3>
                                            <p data-working-state><?= htmlspecialchars($isWorking ? $t('workforce_policy.ux.working') : $t('workforce_policy.ux.non_working'), ENT_QUOTES, 'UTF-8') ?></p>
                                        </div>
                                        <label class="sapas-policy-switch-control">
                                            <input type="checkbox" name="days[<?= $day ?>][is_working_day]" value="1" data-working-day-toggle <?= $isWorking ? 'checked' : '' ?>>
                                            <span class="sapas-policy-switch" aria-hidden="true"><span></span></span>
                                            <span data-working-toggle-label><?= htmlspecialchars($isWorking ? $t('workforce_policy.ux.working') : $t('workforce_policy.ux.non_working'), ENT_QUOTES, 'UTF-8') ?></span>
                                        </label>
                                    </div>
                                    <dl class="sapas-policy-day-summary">
                                        <div><dt><?= htmlspecialchars($t('workforce_policy.ux.work_time'), ENT_QUOTES, 'UTF-8') ?></dt><dd data-day-summary="time"><?= $timeRange($dayData) ?></dd></div>
                                        <div><dt><?= htmlspecialchars($t('workforce_policy.ux.break'), ENT_QUOTES, 'UTF-8') ?></dt><dd data-day-summary="break"><?= $breakSummary($dayData) ?></dd></div>
                                        <div><dt><?= htmlspecialchars($t('workforce_policy.ux.late_grace_period'), ENT_QUOTES, 'UTF-8') ?></dt><dd data-day-summary="grace"><?= $isWorking ? $minutesText($dayData['grace_minutes'] ?? 0) : $dash ?></dd></div>
                                    </dl>
                                    <details class="sapas-policy-day-details">
                                        <summary><?= htmlspecialchars($t('workforce_policy.ux.edit_day_settings'), ENT_QUOTES, 'UTF-8') ?></summary>
                                        <div class="sapas-policy-day-editor">
                                            <div><label><?= htmlspecialchars($t('work_schedules.fields.start_time'), ENT_QUOTES, 'UTF-8') ?></label><input class="sapas-form-control" type="time" name="days[<?= $day ?>][start_time]" value="<?= htmlspecialchars((string) ($dayData['start_time'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" data-working-day-field></div>
                                            <div><label><?= htmlspecialchars($t('work_schedules.fields.end_time'), ENT_QUOTES, 'UTF-8') ?></label><input class="sapas-form-control" type="time" name="days[<?= $day ?>][end_time]" value="<?= htmlspecialchars((string) ($dayData['end_time'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" data-working-day-field></div>
                                            <label class="sapas-policy-toggle-card md:col-span-2"><input class="sapas-policy-switch-input" type="checkbox" name="days[<?= $day ?>][is_overnight]" value="1" data-working-day-field <?= (int) ($dayData['is_overnight'] ?? 0) === 1 ? 'checked' : '' ?>><span class="sapas-policy-switch" aria-hidden="true"><span></span></span><span class="sapas-policy-toggle-text"><strong><?= htmlspecialchars($t('work_schedules.fields.overnight'), ENT_QUOTES, 'UTF-8') ?></strong><small><span class="sapas-policy-toggle-on"><?= htmlspecialchars($t('workforce_policy.ux.enabled'), ENT_QUOTES, 'UTF-8') ?></span><span class="sapas-policy-toggle-off"><?= htmlspecialchars($t('workforce_policy.ux.disabled'), ENT_QUOTES, 'UTF-8') ?></span></small></span></label>
                                            <label class="sapas-policy-toggle-card md:col-span-2"><input class="sapas-policy-switch-input" type="checkbox" data-break-enabled <?= $hasBreak ? 'checked' : '' ?>><span class="sapas-policy-switch" aria-hidden="true"><span></span></span><span class="sapas-policy-toggle-text"><strong><?= htmlspecialchars($t('workforce_policy.ux.break_enabled'), ENT_QUOTES, 'UTF-8') ?></strong><small><span class="sapas-policy-toggle-on"><?= htmlspecialchars($t('workforce_policy.ux.enabled'), ENT_QUOTES, 'UTF-8') ?></span><span class="sapas-policy-toggle-off"><?= htmlspecialchars($t('workforce_policy.ux.no_unpaid_break'), ENT_QUOTES, 'UTF-8') ?></span></small></span></label>
                                            <div><label><?= htmlspecialchars($t('work_schedules.fields.break_start_time'), ENT_QUOTES, 'UTF-8') ?></label><input class="sapas-form-control" type="time" name="days[<?= $day ?>][break_start_time]" value="<?= htmlspecialchars((string) ($dayData['break_start_time'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" data-working-day-field data-break-field></div>
                                            <div><label><?= htmlspecialchars($t('work_schedules.fields.break_end_time'), ENT_QUOTES, 'UTF-8') ?></label><input class="sapas-form-control" type="time" name="days[<?= $day ?>][break_end_time]" value="<?= htmlspecialchars((string) ($dayData['break_end_time'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" data-working-day-field data-break-field></div>
                                            <div><label><?= htmlspecialchars($t('work_schedules.fields.grace_minutes'), ENT_QUOTES, 'UTF-8') ?></label><input class="sapas-form-control" type="number" min="0" name="days[<?= $day ?>][grace_minutes]" value="<?= htmlspecialchars((string) ($dayData['grace_minutes'] ?? 0), ENT_QUOTES, 'UTF-8') ?>" data-working-day-field></div>
                                            <input type="hidden" name="days[<?= $day ?>][break_minutes]" value="<?= htmlspecialchars((string) ($dayData['break_minutes'] ?? 0), ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="days[<?= $day ?>][expected_minutes]" value="<?= htmlspecialchars((string) ($dayData['expected_minutes'] ?? 0), ENT_QUOTES, 'UTF-8') ?>">
                                        </div>
                                    </details>
                                </section>
                            <?php endfor; ?>
                        </div>
                        <div class="mt-4 flex justify-end"><button type="submit" class="sapas-btn sapas-btn-primary"><?= htmlspecialchars($t('workforce_policy.ux.save_draft'), ENT_QUOTES, 'UTF-8') ?></button></div>
                    </form>
                <?php endif; ?>
            </section>

            <section id="attendance-rules" class="sapas-report-table-panel sapas-policy-section-panel">
                <div class="sapas-policy-section-heading"><div><p class="text-xs font-semibold uppercase text-slate-500"><?= htmlspecialchars($t('workforce_policy.ux.step_3'), ENT_QUOTES, 'UTF-8') ?></p><h2 class="mt-1 text-lg font-semibold text-slate-900"><?= htmlspecialchars($t('workforce_policy.tabs.attendance_rules'), ENT_QUOTES, 'UTF-8') ?></h2><p class="mt-1 text-sm text-slate-500"><?= htmlspecialchars($t('workforce_policy.ux.attendance_rules_help'), ENT_QUOTES, 'UTF-8') ?></p></div></div>
                <?php foreach ($fieldError($errors, 'attendance_rules') as $message): ?><p class="mb-2 text-sm text-rose-700"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p><?php endforeach; ?>
                <?php if (!$isEditable): ?>
                    <dl class="grid gap-3 md:grid-cols-2">
                        <div><dt class="text-xs font-semibold uppercase text-slate-500"><?= htmlspecialchars($t('workforce_policy.attendance_rules.allow_non_working_day_check_in'), ENT_QUOTES, 'UTF-8') ?></dt><dd class="mt-1 text-sm text-slate-900"><?= htmlspecialchars($boolText($attendanceRules['allow_non_working_day_check_in'] ?? 0), ENT_QUOTES, 'UTF-8') ?></dd></div>
                        <div><dt class="text-xs font-semibold uppercase text-slate-500"><?= htmlspecialchars($t('workforce_policy.attendance_rules.allow_holiday_check_in'), ENT_QUOTES, 'UTF-8') ?></dt><dd class="mt-1 text-sm text-slate-900"><?= htmlspecialchars($boolText($attendanceRules['allow_holiday_check_in'] ?? 0), ENT_QUOTES, 'UTF-8') ?></dd></div>
                        <div><dt class="text-xs font-semibold uppercase text-slate-500"><?= htmlspecialchars($t('workforce_policy.attendance_rules.allow_overnight_attendance'), ENT_QUOTES, 'UTF-8') ?></dt><dd class="mt-1 text-sm text-slate-900"><?= htmlspecialchars($boolText($attendanceRules['allow_overnight_attendance'] ?? 0), ENT_QUOTES, 'UTF-8') ?></dd></div>
                        <div><dt class="text-xs font-semibold uppercase text-slate-500"><?= htmlspecialchars($t('workforce_policy.attendance_rules.early_departure_enabled'), ENT_QUOTES, 'UTF-8') ?></dt><dd class="mt-1 text-sm text-slate-900"><?= htmlspecialchars($enabledText($attendanceRules['early_departure_enabled'] ?? 0), ENT_QUOTES, 'UTF-8') ?></dd></div>
                        <div><dt class="text-xs font-semibold uppercase text-slate-500"><?= htmlspecialchars($t('workforce_policy.early_departure_grace'), ENT_QUOTES, 'UTF-8') ?></dt><dd class="mt-1 text-sm text-slate-900"><?= $minutesText($attendanceRules['early_departure_grace_minutes'] ?? 0) ?></dd><p class="mt-1 text-sm leading-6 text-slate-500"><?= htmlspecialchars($t('workforce_policy.early_departure_grace_help'), ENT_QUOTES, 'UTF-8') ?></p></div>
                        <div><dt class="text-xs font-semibold uppercase text-slate-500"><?= htmlspecialchars($t('workforce_policy.attendance_rules.manual_override_allowed'), ENT_QUOTES, 'UTF-8') ?></dt><dd class="mt-1 text-sm text-slate-900"><?= htmlspecialchars($boolText($attendanceRules['manual_override_allowed'] ?? 0), ENT_QUOTES, 'UTF-8') ?></dd></div>
                        <div><dt class="text-xs font-semibold uppercase text-slate-500"><?= htmlspecialchars($t('workforce_policy.maximum_attendance_duration'), ENT_QUOTES, 'UTF-8') ?></dt><dd class="mt-1 text-sm text-slate-900"><?= $minutesText($attendanceRules['maximum_attendance_minutes'] ?? 0) ?></dd></div>
                    </dl>
                <?php else: ?>
                    <form method="post" action="/admin/work-schedules/update-attendance-rules?id=<?= $policyId ?>" class="space-y-5">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token ?? '', ENT_QUOTES, 'UTF-8') ?>">
                        <fieldset class="sapas-policy-setting-group"><legend><?= htmlspecialchars($t('workforce_policy.ux.check_in_permissions'), ENT_QUOTES, 'UTF-8') ?></legend><div class="sapas-policy-toggle-grid"><?php foreach (['allow_non_working_day_check_in', 'allow_holiday_check_in', 'allow_overnight_attendance'] as $key): ?><label class="sapas-policy-toggle-card"><input class="sapas-policy-switch-input" type="checkbox" name="<?= $key ?>" value="1" <?= $checked($attendanceRules, $key) ?>><span class="sapas-policy-switch" aria-hidden="true"><span></span></span><span class="sapas-policy-toggle-text"><strong><?= htmlspecialchars($t('workforce_policy.attendance_rules.' . $key), ENT_QUOTES, 'UTF-8') ?></strong><small><span class="sapas-policy-toggle-on"><?= htmlspecialchars($t('workforce_policy.ux.enabled'), ENT_QUOTES, 'UTF-8') ?></span><span class="sapas-policy-toggle-off"><?= htmlspecialchars($t('workforce_policy.ux.disabled'), ENT_QUOTES, 'UTF-8') ?></span></small></span></label><?php endforeach; ?></div></fieldset>
                        <fieldset class="sapas-policy-setting-group"><legend><?= htmlspecialchars($t('workforce_policy.ux.attendance_monitoring'), ENT_QUOTES, 'UTF-8') ?></legend><div class="sapas-policy-toggle-grid"><label class="sapas-policy-toggle-card"><input class="sapas-policy-switch-input" type="checkbox" name="early_departure_enabled" value="1" <?= $checked($attendanceRules, 'early_departure_enabled') ?>><span class="sapas-policy-switch" aria-hidden="true"><span></span></span><span class="sapas-policy-toggle-text"><strong><?= htmlspecialchars($t('workforce_policy.attendance_rules.early_departure_enabled'), ENT_QUOTES, 'UTF-8') ?></strong><small><span class="sapas-policy-toggle-on"><?= htmlspecialchars($t('workforce_policy.ux.enabled'), ENT_QUOTES, 'UTF-8') ?></span><span class="sapas-policy-toggle-off"><?= htmlspecialchars($t('workforce_policy.ux.disabled'), ENT_QUOTES, 'UTF-8') ?></span></small></span></label><div class="sapas-policy-setting-field"><label><?= htmlspecialchars($t('workforce_policy.missing_check_out_mode'), ENT_QUOTES, 'UTF-8') ?></label><select name="missing_check_out_mode" class="sapas-form-control"><?php foreach (['mark_incomplete', 'admin_review_required'] as $mode): ?><option value="<?= $mode ?>" <?= ($attendanceRules['missing_check_out_mode'] ?? '') === $mode ? 'selected' : '' ?>><?= htmlspecialchars($t('workforce_policy.missing_modes.' . $mode), ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div><div class="sapas-policy-setting-field"><label><?= htmlspecialchars($t('workforce_policy.maximum_attendance_duration'), ENT_QUOTES, 'UTF-8') ?></label><input type="number" min="1" max="1440" name="maximum_attendance_minutes" class="sapas-form-control" value="<?= (int) ($attendanceRules['maximum_attendance_minutes'] ?? 960) ?>"></div></div></fieldset>
                        <fieldset class="sapas-policy-setting-group"><legend><?= htmlspecialchars($t('workforce_policy.early_departure_grace'), ENT_QUOTES, 'UTF-8') ?></legend><div class="sapas-policy-setting-field"><label for="early-departure-grace-minutes"><?= htmlspecialchars($t('workforce_policy.early_departure_grace'), ENT_QUOTES, 'UTF-8') ?></label><input id="early-departure-grace-minutes" type="number" min="0" max="240" name="early_departure_grace_minutes" class="sapas-form-control" value="<?= (int) ($attendanceRules['early_departure_grace_minutes'] ?? 0) ?>"><p class="mt-2 text-sm leading-6 text-slate-500"><?= htmlspecialchars($t('workforce_policy.early_departure_grace_help'), ENT_QUOTES, 'UTF-8') ?></p></div></fieldset>
                        <fieldset class="sapas-policy-setting-group"><legend><?= htmlspecialchars($t('workforce_policy.ux.administrative_corrections'), ENT_QUOTES, 'UTF-8') ?></legend><div class="sapas-policy-toggle-grid"><?php foreach (['manual_override_allowed', 'override_reason_required'] as $key): ?><label class="sapas-policy-toggle-card"><input class="sapas-policy-switch-input" type="checkbox" name="<?= $key ?>" value="1" <?= $checked($attendanceRules, $key) ?>><span class="sapas-policy-switch" aria-hidden="true"><span></span></span><span class="sapas-policy-toggle-text"><strong><?= htmlspecialchars($t('workforce_policy.attendance_rules.' . $key), ENT_QUOTES, 'UTF-8') ?></strong><small><span class="sapas-policy-toggle-on"><?= htmlspecialchars($t('workforce_policy.ux.enabled'), ENT_QUOTES, 'UTF-8') ?></span><span class="sapas-policy-toggle-off"><?= htmlspecialchars($t('workforce_policy.ux.disabled'), ENT_QUOTES, 'UTF-8') ?></span></small></span></label><?php endforeach; ?></div></fieldset>
                        <div class="flex justify-end"><button type="submit" class="sapas-btn sapas-btn-primary"><?= htmlspecialchars($t('workforce_policy.save_attendance_rules'), ENT_QUOTES, 'UTF-8') ?></button></div>
                    </form>
                <?php endif; ?>
            </section>

            <section id="overtime-rules" class="sapas-report-table-panel sapas-policy-section-panel">
                <div class="sapas-policy-section-heading"><div><p class="text-xs font-semibold uppercase text-slate-500"><?= htmlspecialchars($t('workforce_policy.ux.step_4'), ENT_QUOTES, 'UTF-8') ?></p><h2 class="mt-1 text-lg font-semibold text-slate-900"><?= htmlspecialchars($t('workforce_policy.tabs.overtime_rules'), ENT_QUOTES, 'UTF-8') ?></h2><p class="mt-1 text-sm text-slate-500"><?= htmlspecialchars($t('workforce_policy.ux.overtime_rules_help'), ENT_QUOTES, 'UTF-8') ?></p></div></div>
                <?php foreach ($fieldError($errors, 'overtime_rules') as $message): ?><p class="mb-2 text-sm text-rose-700"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p><?php endforeach; ?>
                <?php if (!$isEditable): ?>
                    <dl class="grid gap-3 md:grid-cols-2">
                        <?php foreach (['early_work_eligible', 'after_hours_work_eligible', 'non_working_day_work_eligible', 'holiday_work_eligible', 'approval_required', 'allow_admin_adjustment', 'allow_approval_above_calculated', 'reason_required'] as $key): ?>
                            <div><dt class="text-xs font-semibold uppercase text-slate-500"><?= htmlspecialchars($t('workforce_policy.overtime_rules.' . $key), ENT_QUOTES, 'UTF-8') ?></dt><dd class="mt-1 text-sm text-slate-900"><?= htmlspecialchars($enabledText($overtimeRules[$key] ?? 0), ENT_QUOTES, 'UTF-8') ?></dd></div>
                        <?php endforeach; ?>
                        <div><dt class="text-xs font-semibold uppercase text-slate-500"><?= htmlspecialchars($t('workforce_policy.overtime_rules.minimum_overtime_minutes'), ENT_QUOTES, 'UTF-8') ?></dt><dd class="mt-1 text-sm text-slate-900"><?= $minutesText($overtimeRules['minimum_overtime_minutes'] ?? 0) ?></dd></div>
                    </dl>
                <?php else: ?>
                    <form method="post" action="/admin/work-schedules/update-overtime-rules?id=<?= $policyId ?>" class="space-y-5">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token ?? '', ENT_QUOTES, 'UTF-8') ?>">
                        <fieldset class="sapas-policy-setting-group"><legend><?= htmlspecialchars($t('workforce_policy.ux.eligible_work'), ENT_QUOTES, 'UTF-8') ?></legend><div class="sapas-policy-toggle-grid"><?php foreach (['early_work_eligible', 'after_hours_work_eligible', 'non_working_day_work_eligible', 'holiday_work_eligible'] as $key): ?><label class="sapas-policy-toggle-card"><input class="sapas-policy-switch-input" type="checkbox" name="<?= $key ?>" value="1" <?= $checked($overtimeRules, $key) ?>><span class="sapas-policy-switch" aria-hidden="true"><span></span></span><span class="sapas-policy-toggle-text"><strong><?= htmlspecialchars($t('workforce_policy.overtime_rules.' . $key), ENT_QUOTES, 'UTF-8') ?></strong><small><span class="sapas-policy-toggle-on"><?= htmlspecialchars($t('workforce_policy.ux.enabled'), ENT_QUOTES, 'UTF-8') ?></span><span class="sapas-policy-toggle-off"><?= htmlspecialchars($t('workforce_policy.ux.disabled'), ENT_QUOTES, 'UTF-8') ?></span></small></span></label><?php endforeach; ?></div></fieldset>
                        <fieldset class="sapas-policy-setting-group"><legend><?= htmlspecialchars($t('workforce_policy.ux.minimum_and_rounding'), ENT_QUOTES, 'UTF-8') ?></legend><div class="sapas-policy-setting-grid"><div class="sapas-policy-setting-field"><label><?= htmlspecialchars($t('workforce_policy.overtime_rules.minimum_overtime_minutes'), ENT_QUOTES, 'UTF-8') ?></label><input type="number" min="0" max="1440" name="minimum_overtime_minutes" class="sapas-form-control" value="<?= htmlspecialchars((string) ($overtimeRules['minimum_overtime_minutes'] ?? 0), ENT_QUOTES, 'UTF-8') ?>"></div><div class="sapas-policy-setting-field"><label><?= htmlspecialchars($t('workforce_policy.rounding_mode'), ENT_QUOTES, 'UTF-8') ?></label><select name="rounding_mode" class="sapas-form-control" data-rounding-mode><?php foreach (['exact', 'down', 'up', 'nearest'] as $mode): ?><option value="<?= $mode ?>" <?= ($overtimeRules['rounding_mode'] ?? 'exact') === $mode ? 'selected' : '' ?>><?= htmlspecialchars($t('workforce_policy.rounding_modes.' . $mode), ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div><div class="sapas-policy-setting-field" data-rounding-interval><label><?= htmlspecialchars($t('workforce_policy.rounding_interval'), ENT_QUOTES, 'UTF-8') ?></label><select name="rounding_interval_minutes" class="sapas-form-control"><option value=""><?= htmlspecialchars($t('common.none'), ENT_QUOTES, 'UTF-8') ?></option><?php foreach ([5, 10, 15, 30] as $interval): ?><option value="<?= $interval ?>" <?= (int) ($overtimeRules['rounding_interval_minutes'] ?? 0) === $interval ? 'selected' : '' ?>><?= $interval ?></option><?php endforeach; ?></select></div></div><p class="mt-3 text-sm text-slate-500"><?= htmlspecialchars($t('workforce_policy.ux.rounding_example'), ENT_QUOTES, 'UTF-8') ?></p></fieldset>
                        <fieldset class="sapas-policy-setting-group"><legend><?= htmlspecialchars($t('workforce_policy.ux.approval'), ENT_QUOTES, 'UTF-8') ?></legend><div class="sapas-policy-toggle-grid"><?php foreach (['approval_required', 'allow_admin_adjustment', 'allow_approval_above_calculated', 'reason_required'] as $key): ?><label class="sapas-policy-toggle-card"><input class="sapas-policy-switch-input" type="checkbox" name="<?= $key ?>" value="1" <?= $checked($overtimeRules, $key) ?> <?= $key === 'approval_required' ? 'data-approval-toggle' : 'data-approval-field' ?>><span class="sapas-policy-switch" aria-hidden="true"><span></span></span><span class="sapas-policy-toggle-text"><strong><?= htmlspecialchars($t('workforce_policy.overtime_rules.' . $key), ENT_QUOTES, 'UTF-8') ?></strong><small><span class="sapas-policy-toggle-on"><?= htmlspecialchars($t('workforce_policy.ux.enabled'), ENT_QUOTES, 'UTF-8') ?></span><span class="sapas-policy-toggle-off"><?= htmlspecialchars($t('workforce_policy.ux.disabled'), ENT_QUOTES, 'UTF-8') ?></span></small></span></label><?php endforeach; ?></div></fieldset>
                        <fieldset class="sapas-policy-setting-group"><legend><?= htmlspecialchars($t('workforce_policy.ux.special_day_breaks'), ENT_QUOTES, 'UTF-8') ?></legend><div class="sapas-policy-setting-grid"><div class="sapas-policy-setting-field"><label><?= htmlspecialchars($t('workforce_policy.overtime_rules.special_day_break_threshold_minutes'), ENT_QUOTES, 'UTF-8') ?></label><input type="number" min="0" max="1440" name="special_day_break_threshold_minutes" class="sapas-form-control" value="<?= htmlspecialchars((string) ($overtimeRules['special_day_break_threshold_minutes'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></div><div class="sapas-policy-setting-field"><label><?= htmlspecialchars($t('workforce_policy.overtime_rules.special_day_break_minutes'), ENT_QUOTES, 'UTF-8') ?></label><input type="number" min="0" max="1440" name="special_day_break_minutes" class="sapas-form-control" value="<?= htmlspecialchars((string) ($overtimeRules['special_day_break_minutes'] ?? 0), ENT_QUOTES, 'UTF-8') ?>"></div></div></fieldset>
                        <div class="flex justify-end"><button type="submit" class="sapas-btn sapas-btn-primary"><?= htmlspecialchars($t('workforce_policy.save_overtime_rules'), ENT_QUOTES, 'UTF-8') ?></button></div>
                    </form>
                <?php endif; ?>
            </section>

            <?php if ($isEditable): ?>
                <section id="review-changes" class="sapas-report-table-panel sapas-policy-section-panel sapas-policy-review-panel">
                    <div class="sapas-policy-review-heading">
                        <div>
                            <p class="sapas-policy-eyebrow"><?= htmlspecialchars($t('workforce_policy.ux.step_5'), ENT_QUOTES, 'UTF-8') ?></p>
                            <h2><?= htmlspecialchars($t('workforce_policy.ux.review_changes'), ENT_QUOTES, 'UTF-8') ?></h2>
                            <p><?= htmlspecialchars($t('workforce_policy.ux.review_summary_help'), ENT_QUOTES, 'UTF-8') ?></p>
                        </div>
                    </div>

                    <dl class="sapas-policy-review-meta">
                        <div><dt><?= htmlspecialchars($t('workforce_policy.ux.policy_name'), ENT_QUOTES, 'UTF-8') ?></dt><dd><?= htmlspecialchars($policyName($selectedPolicy['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></dd></div>
                        <div><dt><?= htmlspecialchars($t('workforce_policy.ux.future_effective_date'), ENT_QUOTES, 'UTF-8') ?></dt><dd><?= htmlspecialchars((string) ($selectedPolicy['effective_from'] ?? ''), ENT_QUOTES, 'UTF-8') ?></dd></div>
                        <div><dt><?= htmlspecialchars($t('work_schedules.fields.working_days'), ENT_QUOTES, 'UTF-8') ?></dt><dd><?= htmlspecialchars(implode(', ', $workingDayNames) ?: $dash, ENT_QUOTES, 'UTF-8') ?></dd></div>
                        <div><dt><?= htmlspecialchars($t('workforce_policy.change_reason'), ENT_QUOTES, 'UTF-8') ?></dt><dd><?= htmlspecialchars((string) (($selectedPolicy['change_reason'] ?? '') ?: strip_tags($dash)), ENT_QUOTES, 'UTF-8') ?></dd></div>
                    </dl>

                    <div class="sapas-data-table-scroll sapas-policy-review-schedule overflow-x-auto">
                        <table class="sapas-data-table">
                            <thead>
                                <tr>
                                    <th><?= htmlspecialchars($t('workforce_policy.ux.day'), ENT_QUOTES, 'UTF-8') ?></th>
                                    <th><?= htmlspecialchars($t('workforce_policy.ux.work_time'), ENT_QUOTES, 'UTF-8') ?></th>
                                    <th><?= htmlspecialchars($t('workforce_policy.ux.break'), ENT_QUOTES, 'UTF-8') ?></th>
                                    <th><?= htmlspecialchars($t('workforce_policy.ux.late_grace_period'), ENT_QUOTES, 'UTF-8') ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php for ($reviewDay = 1; $reviewDay <= 7; $reviewDay++): ?>
                                    <?php $reviewDayData = $days[$reviewDay] ?? ['is_working_day' => 0]; if ((int) ($reviewDayData['is_working_day'] ?? 0) !== 1) { continue; } ?>
                                    <tr>
                                        <td class="font-semibold text-slate-900"><?= htmlspecialchars($t('work_schedules.weekdays.' . $reviewDay), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= $timeRange($reviewDayData) ?></td>
                                        <td><?= $breakSummary($reviewDayData) ?></td>
                                        <td><?= htmlspecialchars($minutesText($reviewDayData['grace_minutes'] ?? 0), ENT_QUOTES, 'UTF-8') ?></td>
                                    </tr>
                                <?php endfor; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="sapas-policy-review-rules">
                        <article>
                            <h3><?= htmlspecialchars($t('workforce_policy.tabs.attendance_rules'), ENT_QUOTES, 'UTF-8') ?></h3>
                            <ul><?php foreach ($reviewAttendanceSummary as $summary): ?><li><?= htmlspecialchars($summary, ENT_QUOTES, 'UTF-8') ?></li><?php endforeach; ?></ul>
                        </article>
                        <article>
                            <h3><?= htmlspecialchars($t('workforce_policy.tabs.overtime_rules'), ENT_QUOTES, 'UTF-8') ?></h3>
                            <ul><?php foreach ($reviewOvertimeSummary as $summary): ?><li><?= htmlspecialchars($summary, ENT_QUOTES, 'UTF-8') ?></li><?php endforeach; ?></ul>
                        </article>
                    </div>

                    <div class="sapas-policy-assignment-note">
                        <strong><?= htmlspecialchars($t('workforce_policy.assignments.title'), ENT_QUOTES, 'UTF-8') ?></strong>
                        <span><?= htmlspecialchars($t('workforce_policy.ux.assignment_note'), ENT_QUOTES, 'UTF-8') ?></span>
                    </div>

                    <form method="post" action="/admin/work-schedules/schedule-version" class="sapas-policy-review-actions">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token ?? '', ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="id" value="<?= $policyId ?>">
                        <div class="sapas-policy-review-date"><label><?= htmlspecialchars($t('workforce_policy.ux.future_effective_date'), ENT_QUOTES, 'UTF-8') ?></label><input type="date" name="effective_from" class="sapas-form-control" value="<?= htmlspecialchars((string) ($selectedPolicy['effective_from'] ?? date('Y-m-d', strtotime('+1 day'))), ENT_QUOTES, 'UTF-8') ?>" required></div>
                        <div class="sapas-operation-actions">
                            <a href="#weekly-schedule" class="sapas-btn sapas-btn-secondary"><?= htmlspecialchars($t('workforce_policy.ux.review_changes'), ENT_QUOTES, 'UTF-8') ?></a>
                            <button class="sapas-btn sapas-btn-primary" type="submit"><?= htmlspecialchars($t('workforce_policy.ux.schedule_policy'), ENT_QUOTES, 'UTF-8') ?></button>
                        </div>
                    </form>
                </section>
            <?php endif; ?>
        <?php endif; ?>
    <?php elseif ($section === 'history'): ?>
        <section class="sapas-report-table-panel">
            <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div><h2 class="text-lg font-semibold text-slate-900"><?= htmlspecialchars($t('workforce_policy.tabs.policy_history'), ENT_QUOTES, 'UTF-8') ?></h2><p class="mt-1 text-sm text-slate-500"><?= htmlspecialchars($t('workforce_policy.ux.history_help'), ENT_QUOTES, 'UTF-8') ?></p></div>
                <a href="/admin/work-schedules?section=work-rules&mode=create" class="sapas-btn sapas-btn-primary"><?= htmlspecialchars($t('workforce_policy.ux.create_new_work_policy'), ENT_QUOTES, 'UTF-8') ?></a>
            </div>
            <div class="sapas-data-table-scroll overflow-x-auto">
                <table class="sapas-data-table divide-y divide-slate-200">
                    <thead><tr><th><?= htmlspecialchars($t('common.labels.no'), ENT_QUOTES, 'UTF-8') ?></th><th><?= htmlspecialchars($t('work_schedules.fields.name'), ENT_QUOTES, 'UTF-8') ?></th><th><?= htmlspecialchars($t('workforce_policy.effective_period'), ENT_QUOTES, 'UTF-8') ?></th><th><?= htmlspecialchars($t('common.labels.status'), ENT_QUOTES, 'UTF-8') ?></th><th><?= htmlspecialchars($t('workforce_policy.change_reason'), ENT_QUOTES, 'UTF-8') ?></th><th><?= htmlspecialchars($t('common.labels.actions'), ENT_QUOTES, 'UTF-8') ?></th></tr></thead>
                    <tbody class="divide-y divide-slate-200 bg-white">
                        <?php foreach ($schedules as $index => $schedule): ?>
                            <?php $scheduleState = (string) ($schedule['lifecycle_status'] ?? ''); $primaryActionLabel = $scheduleState === 'draft' ? $t('workforce_policy.ux.continue_editing') : ($scheduleState === 'scheduled' ? $t('common.actions.edit') : $t('common.actions.view')); $startingPointLabel = $scheduleState === 'active' ? $t('workforce_policy.ux.change_these_rules') : $t('workforce_policy.ux.use_as_starting_point'); ?>
                            <tr>
                                <td><?= $index + 1 ?></td>
                                <td><p class="font-semibold text-slate-900"><?= htmlspecialchars($policyName($schedule['name']), ENT_QUOTES, 'UTF-8') ?></p><p class="text-xs text-slate-500"><?= htmlspecialchars($policyName($schedule['created_from_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p></td>
                                <td><?= htmlspecialchars((string) ($schedule['effective_from'] ?? ''), ENT_QUOTES, 'UTF-8') ?> - <?= htmlspecialchars((string) (($schedule['effective_to'] ?? '') ?: $t('work_schedules.open_ended')), ENT_QUOTES, 'UTF-8') ?></td>
                                <td><span class="sapas-badge sapas-badge-info"><?= htmlspecialchars($friendlyState($schedule), ENT_QUOTES, 'UTF-8') ?></span></td>
                                <td><?= htmlspecialchars((string) ($schedule['change_reason'] ?? ''), ENT_QUOTES, 'UTF-8') ?: $dash ?></td>
                                <td class="sapas-table-action"><div class="flex flex-wrap gap-2"><a class="sapas-btn sapas-btn-secondary" href="/admin/work-schedules?section=work-rules&policy_id=<?= (int) $schedule['id'] ?>"><?= htmlspecialchars($primaryActionLabel, ENT_QUOTES, 'UTF-8') ?></a><?php if ($scheduleState === 'draft'): ?><form method="post" action="/admin/work-schedules/schedule-version" class="flex gap-1"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token ?? '', ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="id" value="<?= (int) $schedule['id'] ?>"><input type="date" name="effective_from" class="sapas-form-control min-w-36" required><button class="sapas-btn sapas-btn-secondary" type="submit"><?= htmlspecialchars($t('workforce_policy.ux.schedule_policy'), ENT_QUOTES, 'UTF-8') ?></button></form><?php elseif ($scheduleState === 'scheduled'): ?><form method="post" action="/admin/work-schedules/cancel-schedule"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token ?? '', ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="id" value="<?= (int) $schedule['id'] ?>"><button class="sapas-btn sapas-btn-secondary" type="submit"><?= htmlspecialchars($t('workforce_policy.ux.cancel_scheduled_change'), ENT_QUOTES, 'UTF-8') ?></button></form><?php else: ?><a class="sapas-btn sapas-btn-secondary" href="/admin/work-schedules?section=work-rules&mode=create&source_mode=existing&source_policy_id=<?= (int) $schedule['id'] ?>"><?= htmlspecialchars($startingPointLabel, ENT_QUOTES, 'UTF-8') ?></a><?php endif; ?></div></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php elseif ($section === 'organization'): ?>
        <section class="sapas-report-table-panel">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div><h2 class="text-lg font-semibold text-slate-900"><?= htmlspecialchars($t('workforce_policy.tabs.organization_information'), ENT_QUOTES, 'UTF-8') ?></h2><p class="mt-1 text-sm text-slate-500"><?= htmlspecialchars($t('workforce_policy.organization_secondary_help'), ENT_QUOTES, 'UTF-8') ?></p></div>
                <a href="/admin/company-profile/edit" class="sapas-btn sapas-btn-secondary"><?= htmlspecialchars($t('company_profile.edit_profile'), ENT_QUOTES, 'UTF-8') ?></a>
            </div>
            <dl class="mt-4 grid gap-4 md:grid-cols-3">
                <?php foreach (['company_name', 'short_name', 'email', 'phone', 'timezone', 'date_format', 'time_format', 'address'] as $key): ?>
                    <div><dt class="text-xs font-semibold uppercase text-slate-500"><?= htmlspecialchars($t('company_profile.fields.' . $key), ENT_QUOTES, 'UTF-8') ?></dt><dd class="mt-1 text-sm text-slate-700"><?= htmlspecialchars($organizationValue($key, $profile[$key] ?? null), ENT_QUOTES, 'UTF-8') ?: $dash ?></dd></div>
                <?php endforeach; ?>
            </dl>
        </section>
    <?php endif; ?>
</div>
