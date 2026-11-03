<?php
$t = $t ?? static fn (string $key): string => $key;
if (!isset($title)) {
    $title = $t('leave_module.request_title');
}
if (!isset($errors)) {
    $errors = [];
}
if (!isset($fieldErrors)) {
    $fieldErrors = [];
}
if (!isset($leaveRequest)) {
    $leaveRequest = ['leave_type' => '', 'reason_category' => '', 'start_date' => '', 'end_date' => '', 'reason' => ''];
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
$reasonCategoriesByLeaveType = \App\Services\LeaveReasonCatalog::localizedMapping($t);
$selectedLeaveType = (string) ($leaveRequest['leave_type'] ?? '');
$selectedReasonCategory = (string) ($leaveRequest['reason_category'] ?? '');
$reasonCategories = $reasonCategoriesByLeaveType[$selectedLeaveType] ?? [];
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

<form method="post" action="/leave/create" enctype="multipart/form-data" class="space-y-6">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token ?? '', ENT_QUOTES, 'UTF-8') ?>">

    <div class="sapas-workspace-card">
        <div class="grid gap-6 md:grid-cols-2">
            <div>
                <label for="leave_type" class="block text-sm font-medium text-slate-700"><?= htmlspecialchars($t('common.labels.type'), ENT_QUOTES, 'UTF-8') ?></label>
                <select id="leave_type" name="leave_type" required class="mt-2 sapas-form-control" <?= !empty($fieldErrors['leave_type']) ? 'aria-invalid="true" aria-describedby="leave_type_error"' : '' ?>>
                    <option value=""><?= htmlspecialchars($t('common.select_leave_type'), ENT_QUOTES, 'UTF-8') ?></option>
                    <?php foreach ($leaveTypes as $leaveType): ?>
                        <option value="<?= htmlspecialchars($leaveType['value'], ENT_QUOTES, 'UTF-8') ?>" <?= ($leaveRequest['leave_type'] ?? '') === $leaveType['value'] ? 'selected' : '' ?>><?= htmlspecialchars($leaveType['label'], ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if (!empty($fieldErrors['leave_type'])): ?>
                    <p id="leave_type_error" class="mt-1 text-sm text-rose-700"><?= htmlspecialchars(implode(' ', $fieldErrors['leave_type']), ENT_QUOTES, 'UTF-8') ?></p>
                <?php endif; ?>
            </div>
            <div>
                <label for="reason_category" class="block text-sm font-medium text-slate-700"><?= htmlspecialchars($t('leave_module.reason_category'), ENT_QUOTES, 'UTF-8') ?></label>
                <select id="reason_category" name="reason_category" required class="mt-2 sapas-form-control" <?= $selectedLeaveType === '' ? 'disabled' : '' ?> <?= !empty($fieldErrors['reason_category']) ? 'aria-invalid="true" aria-describedby="reason_category_error"' : '' ?>>
                    <option value=""><?= htmlspecialchars($t($selectedLeaveType === '' ? 'leave_module.select_leave_type_first' : 'leave_module.select_reason_category'), ENT_QUOTES, 'UTF-8') ?></option>
                    <?php foreach ($reasonCategories as $reasonCategory): ?>
                        <option value="<?= htmlspecialchars($reasonCategory['value'], ENT_QUOTES, 'UTF-8') ?>" <?= $selectedReasonCategory === $reasonCategory['value'] ? 'selected' : '' ?>><?= htmlspecialchars($reasonCategory['label'], ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if (!empty($fieldErrors['reason_category'])): ?>
                    <p id="reason_category_error" class="mt-1 text-sm text-rose-700"><?= htmlspecialchars(implode(' ', $fieldErrors['reason_category']), ENT_QUOTES, 'UTF-8') ?></p>
                <?php endif; ?>
            </div>
            <div>
                <label for="start_date" class="block text-sm font-medium text-slate-700"><?= htmlspecialchars($t('common.labels.start_date'), ENT_QUOTES, 'UTF-8') ?></label>
                <input type="date" id="start_date" name="start_date" value="<?= htmlspecialchars((string) ($leaveRequest['start_date'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required class="mt-2 sapas-form-control" <?= !empty($fieldErrors['start_date']) ? 'aria-invalid="true" aria-describedby="start_date_error"' : '' ?>>
                <?php if (!empty($fieldErrors['start_date'])): ?>
                    <p id="start_date_error" class="mt-1 text-sm text-rose-700"><?= htmlspecialchars(implode(' ', $fieldErrors['start_date']), ENT_QUOTES, 'UTF-8') ?></p>
                <?php endif; ?>
            </div>
            <div>
                <label for="end_date" class="block text-sm font-medium text-slate-700"><?= htmlspecialchars($t('common.labels.end_date'), ENT_QUOTES, 'UTF-8') ?></label>
                <input type="date" id="end_date" name="end_date" value="<?= htmlspecialchars((string) ($leaveRequest['end_date'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required class="mt-2 sapas-form-control" <?= !empty($fieldErrors['end_date']) ? 'aria-invalid="true" aria-describedby="end_date_error"' : '' ?>>
                <?php if (!empty($fieldErrors['end_date'])): ?>
                    <p id="end_date_error" class="mt-1 text-sm text-rose-700"><?= htmlspecialchars(implode(' ', $fieldErrors['end_date']), ENT_QUOTES, 'UTF-8') ?></p>
                <?php endif; ?>
            </div>
            <div class="md:col-span-2">
                <label for="reason" class="block text-sm font-medium text-slate-700">
                    <?= htmlspecialchars($t('leave_module.reason_details'), ENT_QUOTES, 'UTF-8') ?>
                    <span class="text-rose-600" aria-hidden="true">*</span>
                </label>
                <textarea
                    id="reason"
                    name="reason"
                    rows="5"
                    maxlength="1000"
                    required
                    aria-describedby="reason_help<?= !empty($fieldErrors['reason']) ? ' reason_error' : '' ?>"
                    <?= !empty($fieldErrors['reason']) ? 'aria-invalid="true"' : '' ?>
                    class="mt-2 sapas-form-control"
                ><?= htmlspecialchars((string) ($leaveRequest['reason'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                <p id="reason_help" class="mt-2 text-xs text-slate-500"><?= htmlspecialchars($t('leave_module.reason_details_help'), ENT_QUOTES, 'UTF-8') ?></p>
                <?php if (!empty($fieldErrors['reason'])): ?>
                    <p id="reason_error" class="mt-1 text-sm text-rose-700"><?= htmlspecialchars(implode(' ', $fieldErrors['reason']), ENT_QUOTES, 'UTF-8') ?></p>
                <?php endif; ?>
            </div>
            <div class="md:col-span-2">
                <label for="attachment" id="attachment_label" class="block text-sm font-medium text-slate-700"><?= htmlspecialchars($t('leave_module.supporting_document_optional'), ENT_QUOTES, 'UTF-8') ?></label>
                <input type="file" id="attachment" name="attachment" accept=".jpg,.jpeg,.png,.pdf" class="mt-2 sapas-form-control p-1" aria-describedby="attachment_help<?= !empty($fieldErrors['attachment']) ? ' attachment_error' : '' ?>" <?= !empty($fieldErrors['attachment']) ? 'aria-invalid="true"' : '' ?>>
                <p id="attachment_help" class="mt-2 text-xs text-slate-500"><?= htmlspecialchars($t('leave_module.attachment_help'), ENT_QUOTES, 'UTF-8') ?></p>
                <?php if (!empty($fieldErrors['attachment'])): ?>
                    <p id="attachment_error" class="mt-1 text-sm text-rose-700"><?= htmlspecialchars(implode(' ', $fieldErrors['attachment']), ENT_QUOTES, 'UTF-8') ?></p>
                <?php endif; ?>
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

<script>
document.addEventListener('DOMContentLoaded', function () {
    const leaveTypeSelect = document.getElementById('leave_type');
    const reasonCategorySelect = document.getElementById('reason_category');
    const attachmentLabel = document.getElementById('attachment_label');
    const attachmentHelp = document.getElementById('attachment_help');
    const supportingDocumentLabel = <?= json_encode($t('leave_module.supporting_document_optional'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const supportingMedicalDocumentLabel = <?= json_encode($t('leave_module.supporting_medical_document'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const attachmentHelpText = <?= json_encode($t('leave_module.attachment_help'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const medicalAttachmentHelpText = <?= json_encode($t('leave_module.medical_attachment_help'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const reasonCategoriesByLeaveType = <?= json_encode($reasonCategoriesByLeaveType, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const selectLeaveTypeFirstText = <?= json_encode($t('leave_module.select_leave_type_first'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const selectReasonText = <?= json_encode($t('leave_module.select_reason_category'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const initiallySelectedReason = <?= json_encode($selectedReasonCategory, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    function updateReasonCategories(preserveSelection) {
        const leaveType = leaveTypeSelect.value;
        const categories = reasonCategoriesByLeaveType[leaveType] || [];
        const selectedValue = preserveSelection ? initiallySelectedReason : '';
        const placeholder = document.createElement('option');

        reasonCategorySelect.replaceChildren();
        placeholder.value = '';
        placeholder.textContent = leaveType === '' ? selectLeaveTypeFirstText : selectReasonText;
        reasonCategorySelect.appendChild(placeholder);
        reasonCategorySelect.disabled = leaveType === '';

        categories.forEach(function (category) {
            const option = document.createElement('option');
            option.value = category.value;
            option.textContent = category.label;
            option.selected = category.value === selectedValue;
            reasonCategorySelect.appendChild(option);
        });
    }

    function updateAttachmentUI() {
        if (leaveTypeSelect.value === 'medical') {
            attachmentLabel.textContent = supportingMedicalDocumentLabel;
            attachmentHelp.textContent = medicalAttachmentHelpText;
        } else {
            attachmentLabel.textContent = supportingDocumentLabel;
            attachmentHelp.textContent = attachmentHelpText;
        }
    }
    
    if (leaveTypeSelect && reasonCategorySelect && attachmentLabel && attachmentHelp) {
        leaveTypeSelect.addEventListener('change', function () {
            updateReasonCategories(false);
            updateAttachmentUI();
        });
        updateReasonCategories(true);
        updateAttachmentUI();
    }
});
</script>
