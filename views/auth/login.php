<?php
$t = $t ?? static fn (string $key): string => $key;
$layout = 'layouts/guest';
$title = $t('app.official_name');
$guestTitle = $t('app.official_name');
$guestDescription = $t('app.official_name');
$guestBrandShowSubtitle = false;
$guestBrandWordmark = $t('app.acronym');
$guestVisualTitle = $t('auth.login_visual_title');
$loadingText = $t('auth.logging_in');
$guestOverviewIntro = '';
$guestOverviewFeatures = [
    [
        'title' => $t('auth.overview_attendance_title'),
        'copy' => '',
        'tone' => 'emerald',
        'icon' => 'clock',
    ],
    [
        'title' => $t('auth.overview_leave_title'),
        'copy' => '',
        'tone' => 'amber',
        'icon' => 'leave',
    ],
    [
        'title' => $t('auth.overview_analytics_title'),
        'copy' => '',
        'tone' => 'indigo',
        'icon' => 'analytics',
    ],
];
$guestCompactVisual = true;
$showGuestVisual = true;
$guestFrameVariant = 'focused-login';

$renderIcon = static function (string $iconName, string $iconClass = 'sapas-icon'): void {
    require __DIR__ . '/../partials/icon.php';
};
?>
<?php if (!empty($errors)): ?>
    <div id="login-errors" class="sapas-alert-error mb-4" role="alert" aria-live="assertive" aria-atomic="true">
        <?php foreach ($errors as $error): ?>
            <p><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="sapas-login-card-header">
    <h1><?= htmlspecialchars($t('auth.welcome_back'), ENT_QUOTES, 'UTF-8') ?></h1>
    <p><?= htmlspecialchars($t('auth.login_form_intro'), ENT_QUOTES, 'UTF-8') ?></p>
</div>

<form method="post" action="/login" id="loginForm" class="space-y-4"<?= !empty($errors) ? ' aria-describedby="login-errors"' : '' ?>>
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token ?? '', ENT_QUOTES, 'UTF-8') ?>">

    <div>
        <label for="email" class="block text-sm font-medium text-slate-700"><?= htmlspecialchars($t('auth.email_label'), ENT_QUOTES, 'UTF-8') ?></label>
        <div class="sapas-input-shell mt-2">
            <span class="sapas-input-icon"><?= $renderIcon('mail', 'sapas-icon') ?></span>
            <input
                type="email"
                id="email"
                name="email"
                value="<?= htmlspecialchars($old['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                placeholder="<?= htmlspecialchars($t('auth.email_placeholder'), ENT_QUOTES, 'UTF-8') ?>"
                autocomplete="username"
                required
                class="sapas-form-control"
            >
        </div>
    </div>

    <div>
        <label for="password" class="block text-sm font-medium text-slate-700"><?= htmlspecialchars($t('auth.password_label'), ENT_QUOTES, 'UTF-8') ?></label>
        <div class="sapas-input-shell mt-2">
            <span class="sapas-input-icon"><?= $renderIcon('lock', 'sapas-icon') ?></span>
            <input
                type="password"
                id="password"
                name="password"
                placeholder="<?= htmlspecialchars($t('auth.password_placeholder'), ENT_QUOTES, 'UTF-8') ?>"
                autocomplete="current-password"
                required
                class="sapas-form-control"
            >
            <button
                type="button"
                class="sapas-password-toggle"
                data-password-toggle
                data-show-label="<?= htmlspecialchars($t('auth.show_password'), ENT_QUOTES, 'UTF-8') ?>"
                data-hide-label="<?= htmlspecialchars($t('auth.hide_password'), ENT_QUOTES, 'UTF-8') ?>"
                aria-controls="password"
                aria-label="<?= htmlspecialchars($t('auth.show_password'), ENT_QUOTES, 'UTF-8') ?>"
                aria-pressed="false"
            >
                <span data-password-show-icon><?= $renderIcon('eye', 'sapas-icon') ?></span>
                <span data-password-hide-icon hidden><?= $renderIcon('eye-off', 'sapas-icon') ?></span>
            </button>
        </div>
    </div>


    <button
        type="submit"
        id="loginButton"
        data-loading-text="<?= htmlspecialchars($loadingText, ENT_QUOTES, 'UTF-8') ?>"
        class="sapas-btn sapas-btn-primary sapas-login-submit w-full justify-center py-3 text-base"
    >
        <?= $renderIcon('arrow-right', 'sapas-action-icon') ?>
        <?= htmlspecialchars($t('auth.login_button'), ENT_QUOTES, 'UTF-8') ?>
    </button>

    <p class="sapas-login-help"><?= htmlspecialchars($t('auth.help_text'), ENT_QUOTES, 'UTF-8') ?></p>
</form>
