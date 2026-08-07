<?php
$t = $t ?? static fn (string $key): string => $key;
$errors = $errors ?? [];
$profile = $profile ?? [];
$scheduleOptions = $scheduleOptions ?? [];
$dateFormats = $dateFormats ?? ['Y-m-d'];
$timeFormats = $timeFormats ?? ['H:i'];
$action = $action ?? '/admin/company-profile/update';
$layout = 'layouts/app';
$pageTitle = $title ?? $t('company_profile.edit_title');
$pageDescription = $t('company_profile.edit_description');
$pageActions = '<a href="/admin/company-profile" class="sapas-btn sapas-btn-secondary">' . htmlspecialchars($t('company_profile.back'), ENT_QUOTES, 'UTF-8') . '</a>';
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
            <p class="text-lg font-semibold text-rose-700"><?= htmlspecialchars($t('company_profile.form_error_title'), ENT_QUOTES, 'UTF-8') ?></p>
            <ul class="mt-3 list-disc list-inside text-sm text-rose-700">
                <?php foreach ($allErrors as $error): ?><li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li><?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="post" action="<?= htmlspecialchars($action, ENT_QUOTES, 'UTF-8') ?>" class="sapas-masterdata-form-card">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token ?? '', ENT_QUOTES, 'UTF-8') ?>">

        <section class="sapas-masterdata-form-body space-y-5">
            <div>
                <h2 class="text-lg font-semibold text-slate-900"><?= htmlspecialchars($t('company_profile.identity'), ENT_QUOTES, 'UTF-8') ?></h2>
                <div class="mt-4 grid gap-4 md:grid-cols-2">
                    <div>
                        <label for="company-name" class="block text-sm font-medium text-slate-700"><?= htmlspecialchars($t('company_profile.fields.company_name'), ENT_QUOTES, 'UTF-8') ?> <?= $required ?></label>
                        <input id="company-name" class="mt-2 sapas-form-control" name="company_name" value="<?= htmlspecialchars((string) ($profile['company_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required>
                        <?php if ($fieldError($errors, 'company_name') !== ''): ?><p class="mt-1 text-sm text-rose-700"><?= htmlspecialchars($fieldError($errors, 'company_name'), ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
                    </div>
                    <div>
                        <label for="short-name" class="block text-sm font-medium text-slate-700"><?= htmlspecialchars($t('company_profile.fields.short_name'), ENT_QUOTES, 'UTF-8') ?></label>
                        <input id="short-name" class="mt-2 sapas-form-control" name="short_name" value="<?= htmlspecialchars((string) ($profile['short_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                </div>
            </div>

            <div>
                <h2 class="text-lg font-semibold text-slate-900"><?= htmlspecialchars($t('company_profile.contact'), ENT_QUOTES, 'UTF-8') ?></h2>
                <div class="mt-4 grid gap-4 md:grid-cols-2">
                    <div>
                        <label for="company-email" class="block text-sm font-medium text-slate-700"><?= htmlspecialchars($t('company_profile.fields.email'), ENT_QUOTES, 'UTF-8') ?></label>
                        <input id="company-email" class="mt-2 sapas-form-control" type="email" name="email" value="<?= htmlspecialchars((string) ($profile['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                        <?php if ($fieldError($errors, 'email') !== ''): ?><p class="mt-1 text-sm text-rose-700"><?= htmlspecialchars($fieldError($errors, 'email'), ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
                    </div>
                    <div>
                        <label for="company-phone" class="block text-sm font-medium text-slate-700"><?= htmlspecialchars($t('company_profile.fields.phone'), ENT_QUOTES, 'UTF-8') ?></label>
                        <input id="company-phone" class="mt-2 sapas-form-control" name="phone" value="<?= htmlspecialchars((string) ($profile['phone'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div class="md:col-span-2">
                        <label for="company-address" class="block text-sm font-medium text-slate-700"><?= htmlspecialchars($t('company_profile.fields.address'), ENT_QUOTES, 'UTF-8') ?></label>
                        <textarea id="company-address" class="mt-2 sapas-form-control" name="address" rows="3"><?= htmlspecialchars((string) ($profile['address'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                    </div>
                </div>
            </div>

            <div>
                <h2 class="text-lg font-semibold text-slate-900"><?= htmlspecialchars($t('company_profile.regional_settings'), ENT_QUOTES, 'UTF-8') ?></h2>
                <div class="mt-4 grid gap-4 md:grid-cols-3">
                    <div>
                        <label for="timezone" class="block text-sm font-medium text-slate-700"><?= htmlspecialchars($t('company_profile.fields.timezone'), ENT_QUOTES, 'UTF-8') ?> <?= $required ?></label>
                        <input id="timezone" class="mt-2 sapas-form-control" name="timezone" value="<?= htmlspecialchars((string) ($profile['timezone'] ?? 'Asia/Yangon'), ENT_QUOTES, 'UTF-8') ?>" required>
                        <p class="mt-1 text-sm text-slate-500"><?= htmlspecialchars($t('company_profile.timezone_help'), ENT_QUOTES, 'UTF-8') ?></p>
                        <?php if ($fieldError($errors, 'timezone') !== ''): ?><p class="mt-1 text-sm text-rose-700"><?= htmlspecialchars($fieldError($errors, 'timezone'), ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
                    </div>
                    <div>
                        <label for="date-format" class="block text-sm font-medium text-slate-700"><?= htmlspecialchars($t('company_profile.fields.date_format'), ENT_QUOTES, 'UTF-8') ?> <?= $required ?></label>
                        <select id="date-format" class="mt-2 sapas-form-control" name="date_format">
                            <?php foreach ($dateFormats as $format): ?><option value="<?= htmlspecialchars($format, ENT_QUOTES, 'UTF-8') ?>" <?= (string) ($profile['date_format'] ?? '') === $format ? 'selected' : '' ?>><?= htmlspecialchars($format, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="time-format" class="block text-sm font-medium text-slate-700"><?= htmlspecialchars($t('company_profile.fields.time_format'), ENT_QUOTES, 'UTF-8') ?> <?= $required ?></label>
                        <select id="time-format" class="mt-2 sapas-form-control" name="time_format">
                            <?php foreach ($timeFormats as $format): ?><option value="<?= htmlspecialchars($format, ENT_QUOTES, 'UTF-8') ?>" <?= (string) ($profile['time_format'] ?? '') === $format ? 'selected' : '' ?>><?= htmlspecialchars($format, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <div>
                <h2 class="text-lg font-semibold text-slate-900"><?= htmlspecialchars($t('company_profile.attendance_default'), ENT_QUOTES, 'UTF-8') ?></h2>
                <div class="mt-4 max-w-xl">
                    <label for="default-work-schedule" class="block text-sm font-medium text-slate-700"><?= htmlspecialchars($t('company_profile.fields.default_schedule'), ENT_QUOTES, 'UTF-8') ?> <?= $required ?></label>
                    <select id="default-work-schedule" class="mt-2 sapas-form-control" name="default_work_schedule_id" required>
                        <?php foreach ($scheduleOptions as $schedule): ?><option value="<?= (int) $schedule['id'] ?>" <?= (int) ($profile['default_work_schedule_id'] ?? 0) === (int) $schedule['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $schedule['name'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?>
                    </select>
                    <?php if ($fieldError($errors, 'default_work_schedule_id') !== ''): ?><p class="mt-1 text-sm text-rose-700"><?= htmlspecialchars($fieldError($errors, 'default_work_schedule_id'), ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
                </div>
            </div>
        </section>

        <div class="sapas-masterdata-form-footer">
            <p class="text-sm text-slate-500"><?= htmlspecialchars($t('company_profile.integration_notice'), ENT_QUOTES, 'UTF-8') ?></p>
            <div class="sapas-masterdata-form-actions">
                <a href="/admin/company-profile" class="sapas-btn sapas-btn-secondary"><?= htmlspecialchars($t('common.actions.cancel'), ENT_QUOTES, 'UTF-8') ?></a>
                <button type="submit" class="sapas-btn sapas-btn-primary"><?= htmlspecialchars($t('common.actions.save'), ENT_QUOTES, 'UTF-8') ?></button>
            </div>
        </div>
    </form>
</div>
