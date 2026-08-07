<?php
$t = $t ?? static fn (string $key): string => $key;
$title = $title ?? '';
$action = $action ?? '';
$errors = $errors ?? [];
$holiday = $holiday ?? ['holiday_name' => '', 'holiday_date' => '', 'description' => ''];

$isEdit = isset($holiday['id']) && (int) $holiday['id'] > 0;
$layout = 'layouts/app';
$pageTitle = $title !== '' ? $title : ($isEdit ? $t('masterdata.holidays.edit') : $t('masterdata.holidays.add'));
$pageDescription = $isEdit
    ? $t('masterdata.holidays.edit_description')
    : $t('masterdata.holidays.create_description');
$pageActions = '<a href="/holidays" class="sapas-btn sapas-btn-secondary">' . htmlspecialchars($t('masterdata.holidays.back'), ENT_QUOTES, 'UTF-8') . '</a>';
$submitLabel = $isEdit ? $t('employees_module.save_changes') : $t('masterdata.holidays.create');
$title = $title !== '' ? $title : $pageTitle;
?>
<div class="sapas-masterdata-form-shell">
    <?php if (!empty($errors)): ?>
        <div class="sapas-alert-error">
            <p class="text-lg font-semibold text-rose-700"><?= htmlspecialchars($t('masterdata.holidays.form_error_title'), ENT_QUOTES, 'UTF-8') ?></p>
            <p class="mt-1 text-sm text-rose-700"><?= htmlspecialchars($t('masterdata.form_attention'), ENT_QUOTES, 'UTF-8') ?></p>
            <ul class="mt-4 list-disc list-inside text-sm text-rose-700">
                <?php foreach ($errors as $error): ?>
                    <li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="post" action="<?= htmlspecialchars($action, ENT_QUOTES, 'UTF-8') ?>" class="sapas-masterdata-form-card">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token ?? '', ENT_QUOTES, 'UTF-8') ?>">

        <div class="sapas-masterdata-form-header">
            <h2 class="text-xl font-semibold text-slate-900"><?= htmlspecialchars($t('masterdata.holidays.details'), ENT_QUOTES, 'UTF-8') ?></h2>
            <p class="mt-1 text-sm text-slate-500"><?= htmlspecialchars($t('masterdata.holidays.details_help'), ENT_QUOTES, 'UTF-8') ?></p>
        </div>

        <div class="sapas-masterdata-form-body space-y-5">
            <div class="grid gap-5 md:grid-cols-[minmax(0,1fr)_15rem]">
                <div>
                    <label for="holiday_name" class="block text-sm font-medium text-slate-700"><?= htmlspecialchars($t('masterdata.holidays.name'), ENT_QUOTES, 'UTF-8') ?></label>
                    <p class="mt-1 text-sm text-slate-500"><?= htmlspecialchars($t('masterdata.holidays.name_help'), ENT_QUOTES, 'UTF-8') ?></p>
                    <input
                        type="text"
                        id="holiday_name"
                        name="holiday_name"
                        value="<?= htmlspecialchars($holiday['holiday_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                        required
                        class="mt-2 sapas-form-control"
                    >
                </div>
                <div>
                    <label for="holiday_date" class="block text-sm font-medium text-slate-700"><?= htmlspecialchars($t('masterdata.holidays.date'), ENT_QUOTES, 'UTF-8') ?></label>
                    <p class="mt-1 text-sm text-slate-500"><?= htmlspecialchars($t('masterdata.holidays.date_help'), ENT_QUOTES, 'UTF-8') ?></p>
                    <input
                        type="date"
                        id="holiday_date"
                        name="holiday_date"
                        value="<?= htmlspecialchars($holiday['holiday_date'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                        required
                        class="mt-2 sapas-form-control"
                    >
                </div>
            </div>
            <div>
                <label for="description" class="block text-sm font-medium text-slate-700"><?= htmlspecialchars($t('masterdata.description'), ENT_QUOTES, 'UTF-8') ?></label>
                <p class="mt-1 text-sm text-slate-500"><?= htmlspecialchars($t('masterdata.holidays.description_help'), ENT_QUOTES, 'UTF-8') ?></p>
                <textarea
                    id="description"
                    name="description"
                    rows="4"
                    class="mt-2 sapas-form-control"
                ><?= htmlspecialchars($holiday['description'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
            </div>
        </div>

        <div class="sapas-masterdata-form-footer">
            <p class="text-sm text-slate-500"><?= htmlspecialchars($t('masterdata.holidays.review_help'), ENT_QUOTES, 'UTF-8') ?></p>
            <div class="sapas-masterdata-form-actions">
                <a href="/holidays" class="sapas-btn sapas-btn-secondary"><?= htmlspecialchars($t('common.actions.cancel'), ENT_QUOTES, 'UTF-8') ?></a>
                <button type="submit" class="sapas-btn sapas-btn-primary"><?= htmlspecialchars($submitLabel, ENT_QUOTES, 'UTF-8') ?></button>
            </div>
        </div>
    </form>
</div>
