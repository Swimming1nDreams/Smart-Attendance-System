<?php
$t = $t ?? static fn (string $key): string => $key;
$title = $title ?? '';
$action = $action ?? '';
$errors = $errors ?? [];
$department = $department ?? ['name' => '', 'description' => ''];

$isEdit = isset($department['id']) && (int) $department['id'] > 0;
$layout = 'layouts/app';
$pageTitle = $title !== '' ? $title : ($isEdit ? $t('masterdata.departments.edit') : $t('masterdata.departments.add'));
$pageDescription = $isEdit
    ? $t('masterdata.departments.edit_description')
    : $t('masterdata.departments.create_description');
$pageActions = '<a href="/departments" class="sapas-btn sapas-btn-secondary">' . htmlspecialchars($t('masterdata.departments.back'), ENT_QUOTES, 'UTF-8') . '</a>';
$submitLabel = $isEdit ? $t('employees_module.save_changes') : $t('masterdata.departments.create');
$title = $title !== '' ? $title : $pageTitle;
?>
<div class="sapas-masterdata-form-shell">
    <?php if (!empty($errors)): ?>
        <div class="sapas-alert-error">
            <p class="text-lg font-semibold text-rose-700"><?= htmlspecialchars($t('masterdata.departments.form_error_title'), ENT_QUOTES, 'UTF-8') ?></p>
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
            <h2 class="text-xl font-semibold text-slate-900"><?= htmlspecialchars($t('masterdata.departments.details'), ENT_QUOTES, 'UTF-8') ?></h2>
            <p class="mt-1 text-sm text-slate-500"><?= htmlspecialchars($t('masterdata.departments.details_help'), ENT_QUOTES, 'UTF-8') ?></p>
        </div>

        <div class="sapas-masterdata-form-body space-y-5">
            <div>
                <label for="name" class="block text-sm font-medium text-slate-700"><?= htmlspecialchars($t('masterdata.departments.name'), ENT_QUOTES, 'UTF-8') ?></label>
                <p class="mt-1 text-sm text-slate-500"><?= htmlspecialchars($t('masterdata.departments.name_help'), ENT_QUOTES, 'UTF-8') ?></p>
                <input
                    type="text"
                    id="name"
                    name="name"
                    value="<?= htmlspecialchars($department['name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                    required
                    class="mt-2 sapas-form-control"
                >
            </div>
            <div>
                <label for="description" class="block text-sm font-medium text-slate-700"><?= htmlspecialchars($t('masterdata.description'), ENT_QUOTES, 'UTF-8') ?></label>
                <p class="mt-1 text-sm text-slate-500"><?= htmlspecialchars($t('masterdata.departments.description_help'), ENT_QUOTES, 'UTF-8') ?></p>
                <textarea
                    id="description"
                    name="description"
                    rows="4"
                    class="mt-2 sapas-form-control"
                ><?= htmlspecialchars($department['description'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
            </div>
        </div>

        <div class="sapas-masterdata-form-footer">
            <p class="text-sm text-slate-500"><?= htmlspecialchars($t('masterdata.departments.review_help'), ENT_QUOTES, 'UTF-8') ?></p>
            <div class="sapas-masterdata-form-actions">
                <a href="/departments" class="sapas-btn sapas-btn-secondary"><?= htmlspecialchars($t('common.actions.cancel'), ENT_QUOTES, 'UTF-8') ?></a>
                <button type="submit" class="sapas-btn sapas-btn-primary"><?= htmlspecialchars($submitLabel, ENT_QUOTES, 'UTF-8') ?></button>
            </div>
        </div>
    </form>
</div>
