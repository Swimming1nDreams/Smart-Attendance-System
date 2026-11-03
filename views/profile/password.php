<?php
$t = $t ?? static fn (string $key): string => $key;
$employee = is_array($employee ?? null) ? $employee : [];
$errors = is_array($errors ?? null) ? $errors : [];

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<div class="space-y-6">
    <div class="sapas-workspace-card max-w-2xl mx-auto">
        <h2 class="text-xl font-semibold text-slate-950"><?= $escape($t('profile.change_password')) ?></h2>
        <p class="mt-1 text-sm text-slate-500"><?= $escape($t('profile.change_password_desc')) ?></p>

        <div class="mt-5 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900" role="note">
            <?= $escape($t('profile.password_signout_warning')) ?>
        </div>

        <form action="/profile/password/update" method="POST" class="mt-6 space-y-6" novalidate>
            <input type="hidden" name="csrf_token" value="<?= $escape($csrf_token ?? '') ?>">

            <div class="sapas-field">
                <label for="current_password" class="sapas-label"><?= $escape($t('profile.current_password')) ?> <span class="text-rose-500" aria-hidden="true">*</span></label>
                <div class="sapas-input-group mt-1">
                    <span class="sapas-input-icon">
                        <?php
                        $iconName = 'lock';
                        $iconClass = 'sapas-icon text-slate-400';
                        require __DIR__ . '/../partials/icon.php';
                        unset($iconName, $iconClass);
                        ?>
                    </span>
                    <input
                        type="password"
                        id="current_password"
                        name="current_password"
                        class="sapas-input sapas-input-with-icon <?= isset($errors['current_password']) ? 'sapas-input-error' : '' ?>"
                        autocomplete="current-password"
                        required
                        autofocus
                    >
                </div>
                <?php if (isset($errors['current_password'])): ?>
                    <p class="sapas-error-message mt-2" role="alert"><?= $escape($errors['current_password']) ?></p>
                <?php endif; ?>
            </div>

            <hr class="border-slate-200">

            <div class="sapas-field">
                <label for="new_password" class="sapas-label"><?= $escape($t('profile.new_password')) ?> <span class="text-rose-500" aria-hidden="true">*</span></label>
                <div class="sapas-input-group mt-1">
                    <span class="sapas-input-icon">
                        <?php
                        $iconName = 'key';
                        $iconClass = 'sapas-icon text-slate-400';
                        require __DIR__ . '/../partials/icon.php';
                        unset($iconName, $iconClass);
                        ?>
                    </span>
                    <input
                        type="password"
                        id="new_password"
                        name="new_password"
                        class="sapas-input sapas-input-with-icon <?= isset($errors['new_password']) ? 'sapas-input-error' : '' ?>"
                        autocomplete="new-password"
                        aria-describedby="new-password-requirements"
                        required
                    >
                </div>
                <?php if (isset($errors['new_password'])): ?>
                    <p class="sapas-error-message mt-2" role="alert"><?= $escape($errors['new_password']) ?></p>
                <?php else: ?>
                    <p id="new-password-requirements" class="mt-2 text-sm text-slate-500"><?= $escape($t('profile.password_requirements')) ?></p>
                <?php endif; ?>
            </div>

            <div class="sapas-field">
                <label for="confirm_password" class="sapas-label"><?= $escape($t('profile.confirm_password')) ?> <span class="text-rose-500" aria-hidden="true">*</span></label>
                <div class="sapas-input-group mt-1">
                    <span class="sapas-input-icon">
                        <?php
                        $iconName = 'key';
                        $iconClass = 'sapas-icon text-slate-400';
                        require __DIR__ . '/../partials/icon.php';
                        unset($iconName, $iconClass);
                        ?>
                    </span>
                    <input
                        type="password"
                        id="confirm_password"
                        name="confirm_password"
                        class="sapas-input sapas-input-with-icon <?= isset($errors['confirm_password']) ? 'sapas-input-error' : '' ?>"
                        autocomplete="new-password"
                        required
                    >
                </div>
                <?php if (isset($errors['confirm_password'])): ?>
                    <p class="sapas-error-message mt-2" role="alert"><?= $escape($errors['confirm_password']) ?></p>
                <?php endif; ?>
            </div>

            <div class="flex items-center justify-end gap-3 pt-2">
                <a href="/profile" class="sapas-btn sapas-btn-neutral">
                    <?= $escape($t('common.actions.cancel')) ?>
                </a>
                <button type="submit" class="sapas-btn sapas-btn-primary">
                    <?= $escape($t('profile.save_password')) ?>
                </button>
            </div>
        </form>
    </div>
</div>
