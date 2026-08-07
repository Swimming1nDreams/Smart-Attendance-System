<?php
$t = $t ?? static fn (string $key): string => $key;
$errors = $errors ?? [];
$schedule = $schedule ?? [];
$action = $action ?? '/admin/work-schedules';
$days = $schedule['days'] ?? [];
$layout = 'layouts/app';
$isEdit = isset($schedule['id']) && (int) $schedule['id'] > 0;
$pageTitle = $title ?? ($isEdit ? $t('work_schedules.edit_title') : $t('work_schedules.create_title'));
$pageDescription = $isEdit ? $t('work_schedules.edit_description') : $t('work_schedules.create_description');
$pageActions = '<a href="/admin/work-schedules" class="sapas-btn sapas-btn-secondary">' . htmlspecialchars($t('work_schedules.back'), ENT_QUOTES, 'UTF-8') . '</a>';
$flattenErrors = static function (array $errors): array {
    $messages = [];
    foreach ($errors as $fieldErrors) {
        foreach ((array) $fieldErrors as $message) {
            $messages[] = (string) $message;
        }
    }
    return $messages;
};
$fieldError = static function (array $errors, string $field): string {
    return isset($errors[$field]) ? implode(' ', array_map('strval', (array) $errors[$field])) : '';
};
$required = '<span class="text-rose-600" aria-hidden="true">*</span><span class="sr-only">' . htmlspecialchars($t('common.required'), ENT_QUOTES, 'UTF-8') . '</span>';
?>
<div class="sapas-masterdata-form-shell">
    <?php $allErrors = $flattenErrors($errors); ?>
    <?php if ($allErrors !== []): ?>
        <div class="sapas-alert-error">
            <p class="text-lg font-semibold text-rose-700"><?= htmlspecialchars($t('work_schedules.form_error_title'), ENT_QUOTES, 'UTF-8') ?></p>
            <ul class="mt-3 list-disc list-inside text-sm text-rose-700">
                <?php foreach ($allErrors as $error): ?><li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li><?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="post" action="<?= htmlspecialchars($action, ENT_QUOTES, 'UTF-8') ?>" class="sapas-masterdata-form-card" data-work-schedule-form>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token ?? '', ENT_QUOTES, 'UTF-8') ?>">

        <section class="sapas-masterdata-form-body space-y-6">
            <div>
                <h2 class="text-lg font-semibold text-slate-900"><?= htmlspecialchars($t('work_schedules.template_details'), ENT_QUOTES, 'UTF-8') ?></h2>
                <div class="mt-4 grid gap-4 md:grid-cols-2">
                    <div>
                        <label for="schedule-name" class="block text-sm font-medium text-slate-700"><?= htmlspecialchars($t('work_schedules.fields.name'), ENT_QUOTES, 'UTF-8') ?> <?= $required ?></label>
                        <input id="schedule-name" class="mt-2 sapas-form-control" name="name" value="<?= htmlspecialchars((string) ($schedule['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required>
                        <?php if ($fieldError($errors, 'name') !== ''): ?><p class="mt-1 text-sm text-rose-700"><?= htmlspecialchars($fieldError($errors, 'name'), ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
                    </div>
                    <div>
                        <label for="schedule-timezone" class="block text-sm font-medium text-slate-700"><?= htmlspecialchars($t('work_schedules.fields.timezone'), ENT_QUOTES, 'UTF-8') ?></label>
                        <input id="schedule-timezone" class="mt-2 sapas-form-control" name="timezone" value="<?= htmlspecialchars((string) ($schedule['timezone'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="Asia/Yangon">
                        <p class="mt-1 text-sm text-slate-500"><?= htmlspecialchars($t('work_schedules.timezone_help'), ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                    <div>
                        <label for="effective-from" class="block text-sm font-medium text-slate-700"><?= htmlspecialchars($t('work_schedules.fields.effective_from'), ENT_QUOTES, 'UTF-8') ?> <?= $required ?></label>
                        <input id="effective-from" class="mt-2 sapas-form-control" type="date" name="effective_from" value="<?= htmlspecialchars((string) ($schedule['effective_from'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required>
                    </div>
                    <div>
                        <label for="effective-to" class="block text-sm font-medium text-slate-700"><?= htmlspecialchars($t('work_schedules.fields.effective_to'), ENT_QUOTES, 'UTF-8') ?></label>
                        <input id="effective-to" class="mt-2 sapas-form-control" type="date" name="effective_to" value="<?= htmlspecialchars((string) ($schedule['effective_to'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div class="md:col-span-2">
                        <label for="schedule-description" class="block text-sm font-medium text-slate-700"><?= htmlspecialchars($t('work_schedules.fields.description'), ENT_QUOTES, 'UTF-8') ?></label>
                        <textarea id="schedule-description" class="mt-2 sapas-form-control" name="description" rows="3"><?= htmlspecialchars((string) ($schedule['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                    </div>
                    <label class="inline-flex items-center gap-2 text-sm font-medium text-slate-700">
                        <input type="checkbox" name="is_active" value="1" class="h-4 w-4 rounded border-slate-300 text-brand-600" <?= (int) ($schedule['is_active'] ?? 1) === 1 ? 'checked' : '' ?>>
                        <?= htmlspecialchars($t('work_schedules.fields.active'), ENT_QUOTES, 'UTF-8') ?>
                    </label>
                </div>
            </div>

            <div>
                <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <h2 class="text-lg font-semibold text-slate-900"><?= htmlspecialchars($t('work_schedules.weekly_editor'), ENT_QUOTES, 'UTF-8') ?></h2>
                        <p class="mt-1 text-sm text-slate-500"><?= htmlspecialchars($t('work_schedules.weekly_editor_help'), ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                </div>

                <div class="mt-4 space-y-3">
                    <?php for ($day = 1; $day <= 7; $day++): ?>
                        <?php
                        $dayData = $days[$day] ?? ['is_working_day' => 0, 'start_time' => '', 'end_time' => '', 'is_overnight' => 0, 'break_start_time' => '', 'break_end_time' => '', 'break_minutes' => 0, 'grace_minutes' => 0, 'expected_minutes' => 0];
                        $isWorking = (int) ($dayData['is_working_day'] ?? 0) === 1;
                        $disabled = $isWorking ? '' : 'disabled';
                        ?>
                        <div class="sapas-schedule-day-row" data-work-schedule-row data-on-label="<?= htmlspecialchars($t('workforce_policy.ux.working'), ENT_QUOTES, 'UTF-8') ?>" data-off-label="<?= htmlspecialchars($t('workforce_policy.ux.non_working'), ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="days[<?= $day ?>][day_present]" value="1">
                            <div class="sapas-schedule-day-name">
                                <label class="inline-flex items-center gap-2 text-sm font-semibold text-slate-800">
                                    <input type="checkbox" name="days[<?= $day ?>][is_working_day]" value="1" class="h-4 w-4 rounded border-slate-300 text-brand-600" data-working-day-toggle <?= $isWorking ? 'checked' : '' ?>>
                                    <span><?= htmlspecialchars($t('work_schedules.weekdays.' . $day), ENT_QUOTES, 'UTF-8') ?></span>
                                    <span class="font-medium text-slate-500" data-working-toggle-label><?= htmlspecialchars($isWorking ? $t('workforce_policy.ux.working') : $t('workforce_policy.ux.non_working'), ENT_QUOTES, 'UTF-8') ?></span>
                                </label>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-slate-500" for="day-<?= $day ?>-start"><?= htmlspecialchars($t('work_schedules.fields.start_time'), ENT_QUOTES, 'UTF-8') ?></label>
                                <input id="day-<?= $day ?>-start" class="mt-1 sapas-form-control" type="time" name="days[<?= $day ?>][start_time]" value="<?= htmlspecialchars((string) ($dayData['start_time'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" data-working-day-field <?= $disabled ?>>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-slate-500" for="day-<?= $day ?>-end"><?= htmlspecialchars($t('work_schedules.fields.end_time'), ENT_QUOTES, 'UTF-8') ?></label>
                                <input id="day-<?= $day ?>-end" class="mt-1 sapas-form-control" type="time" name="days[<?= $day ?>][end_time]" value="<?= htmlspecialchars((string) ($dayData['end_time'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" data-working-day-field <?= $disabled ?>>
                            </div>
                            <label class="mt-6 inline-flex items-center gap-2 text-sm text-slate-700">
                                <input type="checkbox" name="days[<?= $day ?>][is_overnight]" value="1" class="h-4 w-4 rounded border-slate-300 text-brand-600" data-working-day-field <?= (int) ($dayData['is_overnight'] ?? 0) === 1 ? 'checked' : '' ?> <?= $disabled ?>>
                                <?= htmlspecialchars($t('work_schedules.fields.overnight'), ENT_QUOTES, 'UTF-8') ?>
                            </label>
                            <div>
                                <label class="block text-xs font-semibold text-slate-500" for="day-<?= $day ?>-break-start"><?= htmlspecialchars($t('work_schedules.fields.break_start_time'), ENT_QUOTES, 'UTF-8') ?></label>
                                <input id="day-<?= $day ?>-break-start" class="mt-1 sapas-form-control" type="time" name="days[<?= $day ?>][break_start_time]" value="<?= htmlspecialchars((string) ($dayData['break_start_time'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" data-working-day-field <?= $disabled ?>>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-slate-500" for="day-<?= $day ?>-break-end"><?= htmlspecialchars($t('work_schedules.fields.break_end_time'), ENT_QUOTES, 'UTF-8') ?></label>
                                <input id="day-<?= $day ?>-break-end" class="mt-1 sapas-form-control" type="time" name="days[<?= $day ?>][break_end_time]" value="<?= htmlspecialchars((string) ($dayData['break_end_time'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" data-working-day-field <?= $disabled ?>>
                            </div>
                            <?php foreach (['break_minutes', 'grace_minutes', 'expected_minutes'] as $minuteField): ?>
                                <div>
                                    <label class="block text-xs font-semibold text-slate-500" for="day-<?= $day ?>-<?= htmlspecialchars($minuteField, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($t('work_schedules.fields.' . $minuteField), ENT_QUOTES, 'UTF-8') ?></label>
                                    <input id="day-<?= $day ?>-<?= htmlspecialchars($minuteField, ENT_QUOTES, 'UTF-8') ?>" class="mt-1 sapas-form-control" type="number" min="0" name="days[<?= $day ?>][<?= htmlspecialchars($minuteField, ENT_QUOTES, 'UTF-8') ?>]" value="<?= htmlspecialchars((string) ($dayData[$minuteField] ?? 0), ENT_QUOTES, 'UTF-8') ?>" data-working-day-field <?= $disabled ?>>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endfor; ?>
                </div>
            </div>
        </section>

        <div class="sapas-masterdata-form-footer">
            <p class="text-sm text-slate-500"><?= htmlspecialchars($t('work_schedules.integration_notice'), ENT_QUOTES, 'UTF-8') ?></p>
            <div class="sapas-masterdata-form-actions">
                <a href="/admin/work-schedules" class="sapas-btn sapas-btn-secondary"><?= htmlspecialchars($t('common.actions.cancel'), ENT_QUOTES, 'UTF-8') ?></a>
                <button type="submit" class="sapas-btn sapas-btn-primary"><?= htmlspecialchars($t('work_schedules.save'), ENT_QUOTES, 'UTF-8') ?></button>
            </div>
        </div>
    </form>
</div>
