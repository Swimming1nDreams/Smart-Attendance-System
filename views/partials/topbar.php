<?php
$t = $t ?? static fn (string $key): string => $key;
$locale = $locale ?? 'en';
$languageOptions = $languageOptions ?? ['en' => 'English', 'my' => 'Myanmar'];
$languageSwitchUrl = $languageSwitchUrl ?? static fn (string $locale): string => '?lang=' . rawurlencode($locale);
$userName = $userName ?? $t('common.user');
$userRole = $userRole ?? 'employee';
$roleLabel = $t('roles.' . $userRole);
$dashboardHref = match ($userRole) {
    'admin' => '/admin/dashboard',
    'department_head' => '/department-head/dashboard',
    default => '/employee/dashboard',
};
$unreadNotificationCount = (int) ($unreadNotificationCount ?? 0);
$notificationButtonLabel = $t('notifications.topbar_label');
if ($unreadNotificationCount > 0) {
    $notificationButtonLabel .= ', ' . $unreadNotificationCount . ' ' . $t('notifications.unread_count');
}
$topbarTitle = (string) ($pageTitle ?? $title ?? $t('app.name'));
?>
<div class="sapas-topbar">
    <div class="sapas-topbar-inner">
        <div class="sapas-topbar-left">
            <button
                type="button"
                class="sapas-mobile-menu-button"
                data-sidebar-open
                aria-controls="app-sidebar"
                aria-expanded="false"
                aria-label="<?= htmlspecialchars($t('layout.open_menu'), ENT_QUOTES, 'UTF-8') ?>"
            >
                <?php
                $iconName = 'menu';
                $iconClass = 'sapas-icon';
                require __DIR__ . '/icon.php';
                unset($iconName, $iconClass);
                ?>
            </button>
            <div class="sapas-topbar-mobile-brand">
                <?php
                $brandVariant = 'compact';
                $brandHref = $dashboardHref;
                $brandShowText = true;
                $brandShowSubtitle = false;
                $brandWordmark = $t('app.acronym');
                require __DIR__ . '/brand.php';
                unset($brandVariant, $brandHref, $brandShowText, $brandShowSubtitle, $brandWordmark);
                ?>
            </div>
            <div class="sapas-topbar-context">
                <p class="sapas-topbar-kicker"><?= htmlspecialchars($t('layout.workspace'), ENT_QUOTES, 'UTF-8') ?></p>
                <p class="sapas-topbar-name"><?= htmlspecialchars($topbarTitle, ENT_QUOTES, 'UTF-8') ?></p>
            </div>
        </div>
        <div class="sapas-topbar-actions">
            <form method="post" action="/notifications/read-all">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token ?? '', ENT_QUOTES, 'UTF-8') ?>">
                <button
                    type="submit"
                    class="sapas-topbar-control sapas-topbar-icon-control"
                    aria-label="<?= htmlspecialchars($notificationButtonLabel, ENT_QUOTES, 'UTF-8') ?>"
                    title="<?= htmlspecialchars($notificationButtonLabel, ENT_QUOTES, 'UTF-8') ?>"
                >
                    <?php
                    $iconName = 'bell';
                    $iconClass = 'sapas-action-icon';
                    require __DIR__ . '/icon.php';
                    unset($iconName, $iconClass);
                    ?>
                    <?php if ($unreadNotificationCount > 0): ?>
                        <span class="sapas-notification-count" aria-hidden="true"><?= (int) $unreadNotificationCount ?></span>
                    <?php endif; ?>
                </button>
            </form>
            <?php require __DIR__ . '/language_switcher.php'; ?>
            <a
                href="/profile"
                class="sapas-topbar-user sapas-topbar-profile-link"
                aria-label="<?= htmlspecialchars($t('nav.profile'), ENT_QUOTES, 'UTF-8') ?>"
                title="<?= htmlspecialchars($t('nav.profile') . ' (' . $roleLabel . ')', ENT_QUOTES, 'UTF-8') ?>"
            >
                <?php
                $avatarName = (string) $userName;
                $avatarPhotoUrl = (string) ($topbarAvatarUrl ?? '');
                $avatarClass = 'sapas-topbar-user-avatar';
                $avatarInitialsClass = 'text-xs text-white';
                $avatarAlt = sprintf($t('profile.avatar_alt'), $userName);
                $avatarFallbackLabel = sprintf($t('profile.avatar_fallback'), $userName);
                require __DIR__ . '/avatar.php';
                unset($avatarName, $avatarPhotoUrl, $avatarClass, $avatarInitialsClass, $avatarAlt, $avatarFallbackLabel);
                ?>
                <span class="min-w-0">
                    <strong><?= htmlspecialchars($userName, ENT_QUOTES, 'UTF-8') ?></strong>
                    <small><?= htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8') ?></small>
                </span>
            </a>
        </div>
    </div>
</div>
