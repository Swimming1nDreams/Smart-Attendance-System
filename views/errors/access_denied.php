<?php
$t = $t ?? static fn (string $key): string => $key;
$userRole = $userRole ?? 'employee';
$dashboardUrl = $userRole === 'admin' ? '/admin/dashboard' : '/employee/dashboard';

$layout = 'layouts/app';
$title = $t('errors.access_denied.title');
$pageTitle = $t('errors.access_denied.title');
$pageDescription = $t('errors.access_denied.description');
$pageIcon = 'shield';
?>
<section class="sapas-access-denied-panel" role="alert" aria-labelledby="access-denied-message">
    <span class="sapas-access-denied-icon" aria-hidden="true">
        <?php
        $iconName = 'shield';
        $iconClass = 'sapas-icon';
        require __DIR__ . '/../partials/icon.php';
        unset($iconName, $iconClass);
        ?>
    </span>

    <div class="min-w-0">
        <h2 id="access-denied-message" class="sapas-access-denied-title">
            <?= htmlspecialchars($t('errors.access_denied.title'), ENT_QUOTES, 'UTF-8') ?>
        </h2>
        <p class="sapas-access-denied-copy">
            <?= htmlspecialchars($t('errors.access_denied.message'), ENT_QUOTES, 'UTF-8') ?>
        </p>

        <div class="sapas-access-denied-actions">
            <a href="<?= htmlspecialchars($dashboardUrl, ENT_QUOTES, 'UTF-8') ?>" class="sapas-btn sapas-btn-secondary">
                <?= htmlspecialchars($t('errors.access_denied.back_to_dashboard'), ENT_QUOTES, 'UTF-8') ?>
            </a>
            <form method="post" action="/logout">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token ?? '', ENT_QUOTES, 'UTF-8') ?>">
                <button type="submit" class="sapas-btn sapas-btn-primary">
                    <?= htmlspecialchars($t('layout.logout'), ENT_QUOTES, 'UTF-8') ?>
                </button>
            </form>
        </div>
    </div>
</section>
