<?php
$t = $t ?? static fn (string $key): string => $key;
if (!isset($title)) {
    $title = $t('leave_module.details_title');
}
if (!isset($leaveRequest)) {
    $leaveRequest = [];
}

$layout = 'layouts/app';
$pageTitle = $title;
$pageDescription = $t('leave_module.admin_details_description');
$pageActions = '<a href="/admin/leave" class="sapas-btn sapas-btn-secondary">' . htmlspecialchars($t('leave_module.back_to_requests'), ENT_QUOTES, 'UTF-8') . '</a>';

function adminLeaveDetailStatusLabel(string $status): string
{
    return \App\Core\Localization::current()->translate('statuses.leave.' . $status);
}

function adminLeaveDetailStatusClasses(string $status): string
{
    return match ($status) {
        'pending' => 'sapas-badge bg-amber-50 text-amber-700',
        'approved' => 'sapas-badge bg-emerald-50 text-emerald-700',
        'rejected' => 'sapas-badge bg-rose-50 text-rose-700',
        'cancelled' => 'sapas-badge bg-slate-100 text-slate-700',
        default => 'sapas-badge bg-slate-100 text-slate-700',
    };
}

function adminLeaveDetailTypeLabel(string $leaveType): string
{
    return \App\Core\Localization::current()->translate('leave.types.' . $leaveType);
}

function adminLeaveDetailCancelReasonLabel(string $cancelReason): string
{
    $localization = \App\Core\Localization::current();

    return match ($cancelReason) {
        'leave.cancel_reasons.admin', 'Cancelled by admin' => $localization->translate('leave.cancel_reasons.admin'),
        'leave.cancel_reasons.employee', 'Cancelled by employee' => $localization->translate('leave.cancel_reasons.employee'),
        default => $cancelReason,
    };
}

$employeeName = (string) ($leaveRequest['employee_name'] ?? '');
$employeeEmail = (string) ($leaveRequest['employee_email'] ?? '');
$leaveType = (string) ($leaveRequest['leave_type'] ?? '');
$startDate = (string) ($leaveRequest['start_date'] ?? '');
$endDate = (string) ($leaveRequest['end_date'] ?? '');
$durationDays = (int) ($leaveRequest['duration_days'] ?? 0);
$status = (string) ($leaveRequest['status'] ?? '');
$reason = trim((string) ($leaveRequest['reason'] ?? ''));
$reviewNote = trim((string) ($leaveRequest['review_note'] ?? ''));
$createdAt = (string) ($leaveRequest['created_at'] ?? '');
$updatedAt = (string) ($leaveRequest['updated_at'] ?? '');
$reviewedAt = (string) ($leaveRequest['reviewed_at'] ?? '');
$reviewedBy = (string) ($leaveRequest['reviewed_by_name'] ?? ($leaveRequest['reviewed_by'] ?? ''));
$cancelledAt = (string) ($leaveRequest['cancelled_at'] ?? '');
$cancelledBy = (string) ($leaveRequest['cancelled_by_name'] ?? ($leaveRequest['cancelled_by'] ?? ''));
$cancelReason = trim((string) ($leaveRequest['cancel_reason'] ?? ''));
$canApprove = $status === 'pending';
$canReject = $status === 'pending';
$canCancel = in_array($status, ['pending', 'approved'], true);
$hasReviewContext = $reviewedAt !== '' || $reviewedBy !== '' || $reviewNote !== '';
$hasCancellationContext = $cancelledAt !== '' || $cancelledBy !== '' || $cancelReason !== '';
?>
<div class="grid gap-6 xl:grid-cols-3">
    <div class="sapas-workspace-card xl:col-span-2">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h2 class="text-xl font-semibold"><?= htmlspecialchars($t('common.labels.request_number'), ENT_QUOTES, 'UTF-8') ?><?= (int) ($leaveRequest['id'] ?? 0) ?></h2>
                <p class="mt-1 text-sm text-slate-500"><?= htmlspecialchars($t('leave_module.submitted'), ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($createdAt, ENT_QUOTES, 'UTF-8') ?></p>
            </div>
            <span class="<?= adminLeaveDetailStatusClasses($status) ?>"><?= htmlspecialchars(adminLeaveDetailStatusLabel($status), ENT_QUOTES, 'UTF-8') ?></span>
        </div>

        <div class="mt-6 sapas-operation-detail-grid">
            <div class="sapas-operation-detail">
                <p class="text-sm font-medium text-slate-500"><?= htmlspecialchars($t('common.labels.employee'), ENT_QUOTES, 'UTF-8') ?></p>
                <p class="mt-2 text-lg font-semibold text-slate-900"><?= htmlspecialchars($employeeName, ENT_QUOTES, 'UTF-8') ?></p>
                <p class="mt-1 text-sm text-slate-600"><?= htmlspecialchars($employeeEmail, ENT_QUOTES, 'UTF-8') ?></p>
            </div>
            <div class="sapas-operation-detail">
                <p class="text-sm font-medium text-slate-500"><?= htmlspecialchars($t('common.labels.type'), ENT_QUOTES, 'UTF-8') ?></p>
                <p class="mt-2 text-lg font-semibold text-slate-900"><?= htmlspecialchars(adminLeaveDetailTypeLabel($leaveType), ENT_QUOTES, 'UTF-8') ?></p>
            </div>
            <div class="sapas-operation-detail">
                <p class="text-sm font-medium text-slate-500"><?= htmlspecialchars($t('common.labels.start_date'), ENT_QUOTES, 'UTF-8') ?></p>
                <p class="mt-2 text-lg font-semibold text-slate-900"><?= htmlspecialchars($startDate, ENT_QUOTES, 'UTF-8') ?></p>
            </div>
            <div class="sapas-operation-detail">
                <p class="text-sm font-medium text-slate-500"><?= htmlspecialchars($t('common.labels.end_date'), ENT_QUOTES, 'UTF-8') ?></p>
                <p class="mt-2 text-lg font-semibold text-slate-900"><?= htmlspecialchars($endDate, ENT_QUOTES, 'UTF-8') ?></p>
            </div>
            <div class="sapas-operation-detail">
                <p class="text-sm font-medium text-slate-500"><?= htmlspecialchars($t('common.labels.duration'), ENT_QUOTES, 'UTF-8') ?></p>
                <p class="mt-2 text-lg font-semibold text-slate-900"><?= $durationDays ?> <?= htmlspecialchars($t($durationDays === 1 ? 'common.units.working_day' : 'common.units.working_days'), ENT_QUOTES, 'UTF-8') ?></p>
            </div>
            <div class="sapas-operation-detail">
                <p class="text-sm font-medium text-slate-500"><?= htmlspecialchars($t('leave_module.last_updated'), ENT_QUOTES, 'UTF-8') ?></p>
                <p class="mt-2 text-lg font-semibold text-slate-900"><?= htmlspecialchars($updatedAt !== '' ? $updatedAt : '-', ENT_QUOTES, 'UTF-8') ?></p>
            </div>
        </div>

        <div class="mt-6 sapas-operation-detail bg-white">
            <p class="text-sm font-medium text-slate-500"><?= htmlspecialchars($t('common.labels.reason'), ENT_QUOTES, 'UTF-8') ?></p>
            <p class="mt-2 text-slate-700"><?= htmlspecialchars($reason !== '' ? $reason : '-', ENT_QUOTES, 'UTF-8') ?></p>
        </div>

        <?php if (!empty($attachment)): ?>
        <div class="mt-6 sapas-operation-detail bg-white">
            <p class="text-sm font-medium text-slate-500">Supporting Document</p>
            <p class="mt-2 text-slate-700">
                <a href="/leave/attachment/download?id=<?= (int)$attachment['id'] ?>" class="text-sky-600 hover:underline" target="_blank" rel="noopener noreferrer">
                    <?= htmlspecialchars($attachment['original_name'], ENT_QUOTES, 'UTF-8') ?>
                </a>
                <span class="text-xs text-slate-400 ml-2">(<?= round($attachment['file_size'] / 1024, 1) ?> KB)</span>
            </p>
        </div>
        <?php endif; ?>
    </div>

    <div class="space-y-6">
        <?php if ($canApprove || $canReject || $canCancel): ?>
            <div class="sapas-workspace-card">
                <h2 class="text-xl font-semibold"><?= htmlspecialchars($t('leave_module.admin_actions'), ENT_QUOTES, 'UTF-8') ?></h2>
                <div class="mt-4 space-y-4">
                    <?php if ($canApprove): ?>
                        <form method="post" action="/admin/leave/approve" data-leave-confirm-form data-confirm-message="<?= htmlspecialchars($t('leave_module.confirm_approve'), ENT_QUOTES, 'UTF-8') ?>" data-confirm-tone="success">
                            <input type="hidden" name="id" value="<?= (int) ($leaveRequest['id'] ?? 0) ?>">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($csrf_token ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                            <button type="submit" class="sapas-btn sapas-btn-success w-full"><?= htmlspecialchars($t('leave_module.approve_leave'), ENT_QUOTES, 'UTF-8') ?></button>
                        </form>
                    <?php endif; ?>

                    <?php if ($canReject): ?>
                        <form method="post" action="/admin/leave/reject" class="space-y-3" data-leave-confirm-form data-confirm-message="<?= htmlspecialchars($t('leave_module.confirm_reject'), ENT_QUOTES, 'UTF-8') ?>" data-confirm-tone="danger">
                            <input type="hidden" name="id" value="<?= (int) ($leaveRequest['id'] ?? 0) ?>">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($csrf_token ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                            <div>
                                <label class="block text-sm font-medium text-slate-700"><?= htmlspecialchars($t('leave_module.rejection_note'), ENT_QUOTES, 'UTF-8') ?></label>
                                <textarea name="review_note" maxlength="1000" required rows="4" class="mt-2 sapas-form-control"></textarea>
                            </div>
                            <button type="submit" class="sapas-btn sapas-btn-danger w-full"><?= htmlspecialchars($t('leave_module.reject_leave'), ENT_QUOTES, 'UTF-8') ?></button>
                        </form>
                    <?php endif; ?>

                    <?php if ($canCancel): ?>
                        <form method="post" action="/admin/leave/cancel" data-leave-confirm-form data-confirm-message="<?= htmlspecialchars($t('leave_module.confirm_cancel'), ENT_QUOTES, 'UTF-8') ?>" data-confirm-tone="warning">
                            <input type="hidden" name="id" value="<?= (int) ($leaveRequest['id'] ?? 0) ?>">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($csrf_token ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                            <button type="submit" class="sapas-btn sapas-btn-danger-quiet w-full"><?= htmlspecialchars($t('leave_module.cancel_leave'), ENT_QUOTES, 'UTF-8') ?></button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="sapas-workspace-card">
            <h2 class="text-xl font-semibold"><?= htmlspecialchars($t('leave_module.timeline'), ENT_QUOTES, 'UTF-8') ?></h2>
            <div class="mt-4 space-y-4">
                <div>
                    <p class="text-sm font-medium text-slate-500"><?= htmlspecialchars($t('leave_module.submitted'), ENT_QUOTES, 'UTF-8') ?></p>
                    <p class="mt-1 text-sm text-slate-700"><?= htmlspecialchars($createdAt !== '' ? $createdAt : '-', ENT_QUOTES, 'UTF-8') ?></p>
                </div>
                <?php if ($hasReviewContext): ?>
                    <div>
                        <p class="text-sm font-medium text-slate-500"><?= htmlspecialchars($status === 'rejected' ? $t('statuses.leave.rejected') : $t('leave_module.review_note'), ENT_QUOTES, 'UTF-8') ?></p>
                        <?php if ($reviewedAt !== ''): ?>
                            <p class="mt-1 text-sm text-slate-700"><?= htmlspecialchars($reviewedAt, ENT_QUOTES, 'UTF-8') ?></p>
                        <?php endif; ?>
                        <?php if ($reviewedBy !== ''): ?>
                            <p class="mt-1 text-sm text-slate-600"><?= htmlspecialchars($t('common.by'), ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($reviewedBy, ENT_QUOTES, 'UTF-8') ?></p>
                        <?php endif; ?>
                        <?php if ($reviewNote !== ''): ?>
                            <p class="mt-2 text-sm text-slate-700"><?= htmlspecialchars($reviewNote, ENT_QUOTES, 'UTF-8') ?></p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <?php if ($hasCancellationContext): ?>
                    <div>
                        <p class="text-sm font-medium text-slate-500"><?= htmlspecialchars($t('leave_module.cancelled'), ENT_QUOTES, 'UTF-8') ?></p>
                        <?php if ($cancelledAt !== ''): ?>
                            <p class="mt-1 text-sm text-slate-700"><?= htmlspecialchars($cancelledAt, ENT_QUOTES, 'UTF-8') ?></p>
                        <?php endif; ?>
                        <?php if ($cancelledBy !== ''): ?>
                            <p class="mt-1 text-sm text-slate-600"><?= htmlspecialchars($t('common.by'), ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($cancelledBy, ENT_QUOTES, 'UTF-8') ?></p>
                        <?php endif; ?>
                        <?php if ($cancelReason !== ''): ?>
                            <p class="mt-2 text-sm text-slate-700"><?= htmlspecialchars(adminLeaveDetailCancelReasonLabel($cancelReason), ENT_QUOTES, 'UTF-8') ?></p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="sapas-confirmation-modal" data-leave-confirmation-modal hidden aria-hidden="true">
    <div class="sapas-confirmation-backdrop" data-confirm-dismiss></div>
    <div class="sapas-confirmation-dialog" role="dialog" aria-modal="true" aria-labelledby="leave-confirmation-title" aria-describedby="leave-confirmation-message" tabindex="-1">
        <div class="sapas-confirmation-heading">
            <span class="sapas-confirmation-icon">
                <?php
                $iconName = 'warning';
                $iconClass = 'h-6 w-6';
                require __DIR__ . '/../partials/icon.php';
                unset($iconName, $iconClass);
                ?>
            </span>
            <div class="min-w-0">
                <h2 id="leave-confirmation-title" class="text-xl font-semibold text-slate-950"><?= htmlspecialchars($t('leave_module.confirm_action_title'), ENT_QUOTES, 'UTF-8') ?></h2>
                <p id="leave-confirmation-message" class="mt-2 text-sm leading-6 text-slate-600" data-confirm-message></p>
            </div>
        </div>

        <div class="sapas-confirmation-actions">
            <button type="button" class="sapas-btn sapas-btn-secondary" data-confirm-dismiss><?= htmlspecialchars($t('common.actions.cancel'), ENT_QUOTES, 'UTF-8') ?></button>
            <button type="button" class="sapas-btn sapas-confirmation-submit" data-confirm-submit></button>
        </div>
    </div>
</div>

<script>
(() => {
    const modal = document.querySelector('[data-leave-confirmation-modal]');
    if (!modal) {
        return;
    }

    const forms = document.querySelectorAll('[data-leave-confirm-form]');
    const dialog = modal.querySelector('.sapas-confirmation-dialog');
    const message = modal.querySelector('[data-confirm-message]');
    const confirmButton = modal.querySelector('[data-confirm-submit]');
    const dismissButtons = modal.querySelectorAll('[data-confirm-dismiss]');
    let pendingForm = null;
    let pendingSubmitter = null;
    let returnFocus = null;

    const closeModal = (restoreFocus = true) => {
        modal.hidden = true;
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('sapas-modal-open');
        pendingForm = null;
        pendingSubmitter = null;

        if (restoreFocus && returnFocus instanceof HTMLElement) {
            returnFocus.focus();
        }
    };

    const openModal = (form, submitter) => {
        pendingForm = form;
        pendingSubmitter = submitter;
        returnFocus = submitter;
        message.textContent = form.dataset.confirmMessage || '';
        confirmButton.textContent = submitter?.textContent?.trim() || '';
        confirmButton.dataset.tone = form.dataset.confirmTone || 'primary';
        modal.hidden = false;
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('sapas-modal-open');
        confirmButton.focus();
    };

    forms.forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (form.dataset.confirmed === 'true') {
                return;
            }

            event.preventDefault();
            const submitter = event.submitter || form.querySelector('[type="submit"]');
            openModal(form, submitter);
        });
    });

    dismissButtons.forEach((button) => {
        button.addEventListener('click', () => closeModal());
    });

    confirmButton.addEventListener('click', () => {
        if (!pendingForm) {
            return;
        }

        const form = pendingForm;
        const submitter = pendingSubmitter;
        closeModal(false);
        form.dataset.confirmed = 'true';

        if (typeof form.requestSubmit === 'function') {
            form.requestSubmit(submitter);
            return;
        }

        form.submit();
    });

    document.addEventListener('keydown', (event) => {
        if (modal.hidden) {
            return;
        }

        if (event.key === 'Escape') {
            event.preventDefault();
            closeModal();
            return;
        }

        if (event.key !== 'Tab') {
            return;
        }

        const focusable = Array.from(dialog.querySelectorAll('button:not([disabled])'));
        const first = focusable[0];
        const last = focusable[focusable.length - 1];

        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    });
})();
</script>
