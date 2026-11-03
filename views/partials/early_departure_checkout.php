<?php
$checkoutButtonClass = $checkoutButtonClass ?? 'sapas-btn sapas-btn-primary';
$checkoutButtonLabel = $checkoutButtonLabel ?? $t('dashboard.common.clock_out');
$checkoutButtonDisabled = $checkoutButtonDisabled ?? false;
$checkoutButtonIcon = $checkoutButtonIcon ?? false;
$earlyCheckoutPreview = is_array($earlyCheckoutPreview ?? null) ? $earlyCheckoutPreview : null;
$earlyMinutes = max(
    0,
    (int) ($earlyCheckoutPreview['early_departure_minutes'] ?? 0),
    (int) ($earlyCheckoutPreview['early_departure_candidate_minutes'] ?? 0),
);
$isEarlyCheckout = $earlyMinutes > 0 && (
    (int) ($earlyCheckoutPreview['is_early_departure'] ?? 0) === 1
    || (int) ($earlyCheckoutPreview['early_departure_provisional'] ?? 0) === 1
);
$reasonCodes = is_array($earlyDepartureReasonCodes ?? null) ? $earlyDepartureReasonCodes : [];
$earlyDepartureGraceMinutes = max(0, (int) ($earlyCheckoutPreview['early_departure_grace_minutes'] ?? 0));
$durationText = static function (int $minutes) use ($t): string {
    $hours = intdiv($minutes, 60);
    $remaining = $minutes % 60;
    if ($hours > 0 && $remaining > 0) {
        return strtr($t('common.duration_formats.hours_minutes'), ['{hours}' => (string) $hours, '{minutes}' => (string) $remaining]);
    }
    if ($hours > 0) {
        return strtr($t('common.duration_formats.hours'), ['{hours}' => (string) $hours]);
    }

    return strtr($t('common.duration_formats.minutes'), ['{minutes}' => (string) $minutes]);
};
$scheduledEndAt = trim((string) ($earlyCheckoutPreview['scheduled_end_at'] ?? ''));
$scheduledEndTimezoneName = trim((string) ($earlyCheckoutPreview['timezone'] ?? ''));
$scheduledEndTimezone = $scheduledEndTimezoneName !== '' && in_array($scheduledEndTimezoneName, DateTimeZone::listIdentifiers(), true)
    ? new DateTimeZone($scheduledEndTimezoneName)
    : new DateTimeZone(date_default_timezone_get());
$scheduledEnd = $scheduledEndAt !== '' ? new DateTimeImmutable($scheduledEndAt, $scheduledEndTimezone) : null;
$scheduledEndLabel = $scheduledEnd?->format('g:i A') ?? '—';
$scheduledEndEpochMs = ($scheduledEnd?->getTimestamp() ?? 0) * 1000;
?>
<form method="post" action="/attendance/clock-out" <?= $isEarlyCheckout ? 'data-early-checkout-form data-scheduled-end-epoch-ms="' . $scheduledEndEpochMs . '" data-early-departure-grace-minutes="' . $earlyDepartureGraceMinutes . '"' : '' ?>>
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token ?? '', ENT_QUOTES, 'UTF-8') ?>">
    <button type="submit" class="<?= htmlspecialchars($checkoutButtonClass, ENT_QUOTES, 'UTF-8') ?>" <?= $checkoutButtonDisabled ? 'disabled' : '' ?>>
        <?php if ($checkoutButtonIcon): ?>
            <?php $iconName = 'log-out'; $iconClass = 'sapas-action-icon'; require __DIR__ . '/icon.php'; unset($iconName, $iconClass); ?>
        <?php endif; ?>
        <?= htmlspecialchars($checkoutButtonLabel, ENT_QUOTES, 'UTF-8') ?>
    </button>

    <?php if ($isEarlyCheckout): ?>
        <div class="sapas-confirmation-modal" data-early-checkout-modal hidden aria-hidden="true">
            <div class="sapas-confirmation-backdrop" data-early-checkout-dismiss></div>
            <div class="sapas-confirmation-dialog" role="dialog" aria-modal="true" aria-labelledby="early-checkout-title" aria-describedby="early-checkout-message" tabindex="-1">
                <div class="sapas-confirmation-heading">
                    <span class="sapas-confirmation-icon"><?php $iconName = 'warning'; $iconClass = 'h-6 w-6'; require __DIR__ . '/icon.php'; unset($iconName, $iconClass); ?></span>
                    <div class="min-w-0">
                        <h2 id="early-checkout-title" class="text-xl font-semibold text-slate-950"><?= htmlspecialchars($t('attendance.early_checkout.title'), ENT_QUOTES, 'UTF-8') ?></h2>
                        <p id="early-checkout-message" class="mt-2 text-sm leading-6 text-slate-600" data-early-checkout-message><?= htmlspecialchars(strtr($t('attendance.early_checkout.message'), ['{time}' => $scheduledEndLabel, '{duration}' => $durationText($earlyMinutes)]), ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                </div>

                <div class="mt-5 space-y-4">
                    <div>
                        <label for="early-departure-reason" class="block text-sm font-medium text-slate-700"><?= htmlspecialchars($t('attendance.early_checkout.reason'), ENT_QUOTES, 'UTF-8') ?> <span class="text-rose-600" aria-hidden="true">*</span></label>
                        <select id="early-departure-reason" name="early_departure_reason" class="mt-2 sapas-form-control" data-early-reason>
                            <option value=""><?= htmlspecialchars($t('attendance.early_checkout.select_reason'), ENT_QUOTES, 'UTF-8') ?></option>
                            <?php foreach ($reasonCodes as $code): ?>
                                <option value="<?= htmlspecialchars((string) $code, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($t('attendance.early_departure_reasons.' . $code), ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="early-departure-note" class="block text-sm font-medium text-slate-700"><?= htmlspecialchars($t('attendance.early_checkout.optional_note'), ENT_QUOTES, 'UTF-8') ?></label>
                        <textarea id="early-departure-note" name="early_departure_note" rows="3" maxlength="500" class="mt-2 sapas-form-control" data-early-note></textarea>
                        <p class="mt-1 text-xs text-slate-500"><?= htmlspecialchars($t('attendance.early_checkout.note_help'), ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                </div>

                <div class="sapas-confirmation-actions">
                    <button type="button" class="sapas-btn sapas-btn-secondary" data-early-checkout-dismiss><?= htmlspecialchars($t('common.actions.cancel'), ENT_QUOTES, 'UTF-8') ?></button>
                    <button type="button" class="sapas-btn sapas-confirmation-submit" data-tone="warning" data-early-checkout-confirm><?= htmlspecialchars($t('attendance.early_checkout.confirm'), ENT_QUOTES, 'UTF-8') ?></button>
                </div>
            </div>
        </div>
    <?php endif; ?>
</form>

<?php if ($isEarlyCheckout): ?>
<script>
(() => {
    const form = document.querySelector('[data-early-checkout-form]');
    const modal = form?.querySelector('[data-early-checkout-modal]');
    if (!form || !modal) return;
    const dialog = modal.querySelector('.sapas-confirmation-dialog');
    const reason = modal.querySelector('[data-early-reason]');
    const note = modal.querySelector('[data-early-note]');
    const confirm = modal.querySelector('[data-early-checkout-confirm]');
    const dismiss = modal.querySelectorAll('[data-early-checkout-dismiss]');
    const trigger = form.querySelector('button[type="submit"]');
    const message = modal.querySelector('[data-early-checkout-message]');
    const scheduledEndEpochMs = Number(form.dataset.scheduledEndEpochMs);
    const graceMinutes = Math.max(0, Number(form.dataset.earlyDepartureGraceMinutes) || 0);
    const messageTemplate = <?= json_encode($t('attendance.early_checkout.message'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const scheduledEndLabel = <?= json_encode($scheduledEndLabel, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const durationFormats = {
        hoursMinutes: <?= json_encode($t('common.duration_formats.hours_minutes'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        hours: <?= json_encode($t('common.duration_formats.hours'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        minutes: <?= json_encode($t('common.duration_formats.minutes'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
    };

    const freshEarlyMinutes = () => {
        if (!Number.isFinite(scheduledEndEpochMs)) return 0;
        const minutes = Math.floor((scheduledEndEpochMs - Date.now()) / 60000);
        return minutes > graceMinutes ? minutes : 0;
    };
    const freshDurationText = (minutes) => {
        const hours = Math.floor(minutes / 60);
        const remaining = minutes % 60;
        if (hours > 0 && remaining > 0) {
            return durationFormats.hoursMinutes.replace('{hours}', String(hours)).replace('{minutes}', String(remaining));
        }
        if (hours > 0) return durationFormats.hours.replace('{hours}', String(hours));
        return durationFormats.minutes.replace('{minutes}', String(minutes));
    };
    const syncMessage = (minutes) => {
        if (!message) return;
        message.textContent = messageTemplate
            .replace('{time}', scheduledEndLabel)
            .replace('{duration}', freshDurationText(minutes));
    };

    const close = (restoreFocus = true, resetFields = true) => {
        modal.hidden = true;
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('sapas-modal-open');
        if (resetFields) {
            form.removeAttribute('data-confirmed');
            if (reason) {
                reason.required = false;
                reason.value = '';
            }
            if (note) {
                note.required = false;
                note.value = '';
            }
        }
        if (restoreFocus) trigger?.focus();
    };
    const open = (minutes) => {
        form.removeAttribute('data-confirmed');
        if (reason) reason.required = true;
        syncNoteRequirement();
        syncMessage(minutes);
        modal.hidden = false;
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('sapas-modal-open');
        reason?.focus();
    };
    const syncNoteRequirement = () => {
        if (note) note.required = reason?.value === 'other_authorized';
    };

    form.addEventListener('submit', (event) => {
        if (form.dataset.confirmed === 'true') return;
        const earlyMinutesNow = freshEarlyMinutes();
        if (earlyMinutesNow <= 0) return;
        event.preventDefault();
        open(earlyMinutesNow);
    });
    reason?.addEventListener('change', syncNoteRequirement);
    dismiss.forEach((button) => button.addEventListener('click', () => close()));
    confirm?.addEventListener('click', () => {
        if (form.dataset.confirmed === 'true') return;
        syncNoteRequirement();
        if (!reason?.reportValidity() || !note?.reportValidity()) return;
        form.dataset.confirmed = 'true';
        close(false, false);
        form.requestSubmit(trigger);
    });
    document.addEventListener('keydown', (event) => {
        if (modal.hidden) return;
        if (event.key === 'Escape') {
            event.preventDefault();
            close();
            return;
        }
        if (event.key !== 'Tab') return;
        const focusable = Array.from(dialog.querySelectorAll('button:not([disabled]), select:not([disabled]), textarea:not([disabled])'));
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault(); last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault(); first.focus();
        }
    });
})();
</script>
<?php endif; ?>
