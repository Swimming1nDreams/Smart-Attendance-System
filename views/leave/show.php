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
$pageDescription = $t('leave_module.details_description');
$pageActions = '<a href="/leave" class="sapas-btn sapas-btn-secondary">' . htmlspecialchars($t('leave_module.back_to_leave'), ENT_QUOTES, 'UTF-8') . '</a>';

function leaveDetailStatusLabel(string $status): string
{
    return \App\Core\Localization::current()->translate('statuses.leave.' . $status);
}

function leaveDetailStatusBadgeClass(string $status): string
{
    return match ($status) {
        'pending' => 'sapas-badge bg-amber-50 text-amber-700',
        'approved' => 'sapas-badge bg-emerald-50 text-emerald-700',
        'rejected' => 'sapas-badge bg-rose-50 text-rose-700',
        'cancelled' => 'sapas-badge bg-slate-100 text-slate-700',
        default => 'sapas-badge bg-slate-100 text-slate-700',
    };
}

function leaveDetailTypeLabel(string $leaveType): string
{
    return \App\Core\Localization::current()->translate('leave.types.' . $leaveType);
}

function leaveDetailCancelReasonLabel(string $cancelReason): string
{
    $localization = \App\Core\Localization::current();

    return match ($cancelReason) {
        'leave.cancel_reasons.admin', 'Cancelled by admin' => $localization->translate('leave.cancel_reasons.admin'),
        'leave.cancel_reasons.employee', 'Cancelled by employee' => $localization->translate('leave.cancel_reasons.employee'),
        default => $cancelReason,
    };
}

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
$canCancel = $status === 'pending';
$hasReviewContext = $reviewedAt !== '' || $reviewedBy !== '' || $reviewNote !== '';
$hasCancellationContext = $cancelledAt !== '' || $cancelledBy !== '' || $cancelReason !== '';
?>
<div class="grid gap-6 xl:grid-cols-3">
    <div class="sapas-workspace-card xl:col-span-2">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h2 class="text-xl font-semibold"><?= htmlspecialchars($t('leave_module.details_heading'), ENT_QUOTES, 'UTF-8') ?></h2>
                <p class="mt-1 text-sm text-slate-500"><?= htmlspecialchars($t('common.labels.request_number'), ENT_QUOTES, 'UTF-8') ?><?= (int) ($leaveRequest['id'] ?? 0) ?></p>
            </div>
            <span class="<?= leaveDetailStatusBadgeClass($status) ?>"><?= htmlspecialchars(leaveDetailStatusLabel($status), ENT_QUOTES, 'UTF-8') ?></span>
        </div>

        <div class="mt-6 sapas-operation-detail-grid">
            <div class="sapas-operation-detail">
                <p class="text-sm font-medium text-slate-500"><?= htmlspecialchars($t('common.labels.type'), ENT_QUOTES, 'UTF-8') ?></p>
                <p class="mt-2 text-lg font-semibold text-slate-900"><?= htmlspecialchars(leaveDetailTypeLabel($leaveType), ENT_QUOTES, 'UTF-8') ?></p>
            </div>
            <div class="sapas-operation-detail">
                <p class="text-sm font-medium text-slate-500"><?= htmlspecialchars($t('common.labels.duration'), ENT_QUOTES, 'UTF-8') ?></p>
                <p class="mt-2 text-lg font-semibold text-slate-900"><?= $durationDays ?> <?= htmlspecialchars($t($durationDays === 1 ? 'common.units.working_day' : 'common.units.working_days'), ENT_QUOTES, 'UTF-8') ?></p>
            </div>
            <div class="sapas-operation-detail">
                <p class="text-sm font-medium text-slate-500"><?= htmlspecialchars($t('common.labels.start_date'), ENT_QUOTES, 'UTF-8') ?></p>
                <p class="mt-2 text-lg font-semibold text-slate-900"><?= htmlspecialchars($startDate, ENT_QUOTES, 'UTF-8') ?></p>
            </div>
            <div class="sapas-operation-detail">
                <p class="text-sm font-medium text-slate-500"><?= htmlspecialchars($t('common.labels.end_date'), ENT_QUOTES, 'UTF-8') ?></p>
                <p class="mt-2 text-lg font-semibold text-slate-900"><?= htmlspecialchars($endDate, ENT_QUOTES, 'UTF-8') ?></p>
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

        <?php if ($canCancel): ?>
            <form method="post" action="/leave/cancel" class="mt-6" onsubmit="return confirm('<?= htmlspecialchars($t('leave_module.confirm_cancel_pending'), ENT_QUOTES, 'UTF-8') ?>');">
                <input type="hidden" name="id" value="<?= (int) ($leaveRequest['id'] ?? 0) ?>">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($csrf_token ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                <button type="submit" class="sapas-btn border border-rose-200 bg-rose-50 text-rose-700 hover:bg-rose-100"><?= htmlspecialchars($t('leave_module.cancel_pending'), ENT_QUOTES, 'UTF-8') ?></button>
            </form>
        <?php endif; ?>
    </div>

    <div class="space-y-6">
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
                            <p class="mt-2 text-sm text-slate-700"><?= htmlspecialchars(leaveDetailCancelReasonLabel($cancelReason), ENT_QUOTES, 'UTF-8') ?></p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <?php if ($updatedAt !== '' && $updatedAt !== $createdAt): ?>
                    <div>
                        <p class="text-sm font-medium text-slate-500"><?= htmlspecialchars($t('leave_module.last_updated'), ENT_QUOTES, 'UTF-8') ?></p>
                        <p class="mt-1 text-sm text-slate-700"><?= htmlspecialchars($updatedAt, ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
