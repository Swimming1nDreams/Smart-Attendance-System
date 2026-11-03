<?php
$t = $t ?? static fn (string $key): string => $key;
$layout = 'layouts/guest';
$status = $status ?? 'pending';
$expiryMinutes = (int) ($expiryMinutes ?? 15);
$title = $t('auth.email_verification_title') . ' - ' . $t('app.official_name');
$guestTitle = $t('auth.email_verification_title');
$guestBrandShowSubtitle = true;
$guestBrandWordmark = $t('app.acronym');
$guestBrandSubtitle = $t('app.official_name');
$showGuestVisual = false;
$guestFrameVariant = 'verification';

$isSuccess = $status === 'verified';
$isError = in_array($status, ['invalid', 'expired', 'attempts_exceeded', 'delivery_failed'], true);
?>
<div class="text-center">
    <div class="<?= $isSuccess ? 'bg-emerald-50 text-emerald-700' : ($isError ? 'bg-rose-50 text-rose-700' : 'bg-sky-50 text-sky-700') ?> mx-auto flex h-12 w-12 items-center justify-center rounded-full text-xl font-semibold">
        <?= $isSuccess ? '&#10003;' : '6' ?>
    </div>
    <h1 class="mt-4 text-2xl font-semibold text-slate-900"><?= htmlspecialchars($t($isSuccess ? 'auth.email_verification_success_title' : 'auth.email_verification_code_title'), ENT_QUOTES, 'UTF-8') ?></h1>

    <?php if ($isSuccess): ?>
        <p class="mt-3 text-sm leading-6 text-slate-600"><?= htmlspecialchars($t('auth.email_verification_success_message'), ENT_QUOTES, 'UTF-8') ?></p>
    <?php else: ?>
        <p class="mt-3 text-sm leading-6 text-slate-600"><?= htmlspecialchars(str_replace(':minutes', (string) $expiryMinutes, $t('auth.email_verification_code_help')), ENT_QUOTES, 'UTF-8') ?></p>

        <?php if ($status === 'resent'): ?>
            <div class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-left text-sm text-emerald-800"><?= htmlspecialchars($t('auth.email_verification_resent_message'), ENT_QUOTES, 'UTF-8') ?></div>
        <?php elseif ($status === 'delivery_failed'): ?>
            <div class="mt-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-left text-sm text-rose-800"><?= htmlspecialchars($t('auth.email_verification_delivery_failed_message'), ENT_QUOTES, 'UTF-8') ?></div>
        <?php elseif ($status === 'expired'): ?>
            <div class="mt-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-left text-sm text-amber-800"><?= htmlspecialchars($t('auth.email_verification_expired_message'), ENT_QUOTES, 'UTF-8') ?></div>
        <?php elseif ($status === 'attempts_exceeded'): ?>
            <div class="mt-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-left text-sm text-rose-800"><?= htmlspecialchars($t('auth.email_verification_attempts_message'), ENT_QUOTES, 'UTF-8') ?></div>
        <?php elseif ($status === 'invalid'): ?>
            <div class="mt-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-left text-sm text-rose-800"><?= htmlspecialchars($t('auth.email_verification_invalid_message'), ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <form method="post" action="/email-verification/verify" class="mt-6 text-left">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token ?? '', ENT_QUOTES, 'UTF-8') ?>">
            <label for="code" class="block text-sm font-medium text-slate-700"><?= htmlspecialchars($t('auth.verification_code_label'), ENT_QUOTES, 'UTF-8') ?></label>
            <input type="text" id="code" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required autofocus class="mt-2 sapas-form-control text-center text-2xl font-semibold tracking-[0.35em]" aria-describedby="verification-code-help">
            <p id="verification-code-help" class="mt-2 text-xs leading-5 text-slate-500"><?= htmlspecialchars($t('auth.verification_code_input_help'), ENT_QUOTES, 'UTF-8') ?></p>
            <button type="submit" class="mt-5 sapas-btn sapas-btn-primary w-full justify-center"><?= htmlspecialchars($t('auth.verify_code_button'), ENT_QUOTES, 'UTF-8') ?></button>
        </form>

        <form method="post" action="/email-verification/resend" class="mt-3">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token ?? '', ENT_QUOTES, 'UTF-8') ?>">
            <button type="submit" class="sapas-btn sapas-btn-secondary w-full justify-center"><?= htmlspecialchars($t('auth.resend_verification_code'), ENT_QUOTES, 'UTF-8') ?></button>
        </form>
        <p class="mt-3 text-xs leading-5 text-slate-500"><?= htmlspecialchars($t('auth.verification_resend_help'), ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>

    <a href="/login" class="mt-5 inline-flex text-sm font-medium text-sky-700 hover:text-sky-800"><?= htmlspecialchars($t('auth.back_to_login'), ENT_QUOTES, 'UTF-8') ?></a>
</div>
