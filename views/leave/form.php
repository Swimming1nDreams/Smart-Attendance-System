<?php
$t = $t ?? static fn (string $key): string => $key;
if (!isset($title)) {
    $title = $t('leave_module.request_title');
}
if (!isset($errors)) {
    $errors = [];
}
if (!isset($leaveRequest)) {
    $leaveRequest = ['leave_type' => '', 'start_date' => '', 'end_date' => '', 'reason' => ''];
}

$layout = 'layouts/app';
$pageTitle = $title;
$pageDescription = $t('leave_module.request_description');
$pageActions = '<a href="/leave" class="sapas-btn sapas-btn-secondary">' . htmlspecialchars($t('leave_module.back_to_leave'), ENT_QUOTES, 'UTF-8') . '</a>';
$leaveTypes = [
    ['value' => 'casual', 'label' => $t('leave.types.casual')],
    ['value' => 'medical', 'label' => $t('leave.types.medical')],
    ['value' => 'unpaid', 'label' => $t('leave.types.unpaid')],
    ['value' => 'other', 'label' => $t('leave.types.other')],
];
?>
<?php if (!empty($errors)): ?>
    <div class="sapas-alert-error">
        <p class="text-lg font-semibold text-rose-700"><?= htmlspecialchars($t('common.form_review'), ENT_QUOTES, 'UTF-8') ?></p>
        <p class="mt-1 text-sm text-rose-700"><?= htmlspecialchars($t('leave_module.form_error_help'), ENT_QUOTES, 'UTF-8') ?></p>
        <ul class="mt-4 list-disc list-inside text-sm text-rose-700">
            <?php foreach ($errors as $error): ?>
                <li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="post" action="/leave/create" class="space-y-6">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token ?? '', ENT_QUOTES, 'UTF-8') ?>">

    <div class="sapas-workspace-card">
        <div class="grid gap-6 md:grid-cols-2">
            <div>
                <label for="leave_type" class="block text-sm font-medium text-slate-700"><?= htmlspecialchars($t('common.labels.type'), ENT_QUOTES, 'UTF-8') ?></label>
                <select id="leave_type" name="leave_type" required class="mt-2 sapas-form-control">
                    <option value=""><?= htmlspecialchars($t('common.select_leave_type'), ENT_QUOTES, 'UTF-8') ?></option>
                    <?php foreach ($leaveTypes as $leaveType): ?>
                        <option value="<?= htmlspecialchars($leaveType['value'], ENT_QUOTES, 'UTF-8') ?>" <?= ($leaveRequest['leave_type'] ?? '') === $leaveType['value'] ? 'selected' : '' ?>><?= htmlspecialchars($leaveType['label'], ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="reason" class="block text-sm font-medium text-slate-700"><?= htmlspecialchars($t('common.labels.reason'), ENT_QUOTES, 'UTF-8') ?></label>
                <textarea id="reason" name="reason" rows="4" maxlength="1000" required class="mt-2 sapas-form-control"><?= htmlspecialchars((string) ($leaveRequest['reason'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                <p class="mt-2 text-xs text-slate-500"><?= htmlspecialchars($t('leave_module.reason_help'), ENT_QUOTES, 'UTF-8') ?></p>
            </div>
            <div>
                <label for="start_date" class="block text-sm font-medium text-slate-700"><?= htmlspecialchars($t('common.labels.start_date'), ENT_QUOTES, 'UTF-8') ?></label>
                <input type="date" id="start_date" name="start_date" value="<?= htmlspecialchars((string) ($leaveRequest['start_date'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required class="mt-2 sapas-form-control">
            </div>
            <div>
                <label for="end_date" class="block text-sm font-medium text-slate-700"><?= htmlspecialchars($t('common.labels.end_date'), ENT_QUOTES, 'UTF-8') ?></label>
                <input type="date" id="end_date" name="end_date" value="<?= htmlspecialchars((string) ($leaveRequest['end_date'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required class="mt-2 sapas-form-control">
            </div>
        </div>
    </div>

    <div class="sapas-workspace-card">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h2 class="text-xl font-semibold"><?= htmlspecialchars($t('leave_module.submit_section_title'), ENT_QUOTES, 'UTF-8') ?></h2>
                <p class="mt-1 text-sm text-slate-500"><?= htmlspecialchars($t('leave_module.submit_section_help'), ENT_QUOTES, 'UTF-8') ?></p>
            </div>
            <div class="sapas-operation-actions">
                <button type="submit" class="sapas-btn sapas-btn-primary"><?= htmlspecialchars($t('leave_module.submit_request'), ENT_QUOTES, 'UTF-8') ?></button>
                <a href="/leave" class="sapas-btn sapas-btn-secondary"><?= htmlspecialchars($t('common.actions.cancel'), ENT_QUOTES, 'UTF-8') ?></a>
            </div>
        </div>
    </div>
</form>
