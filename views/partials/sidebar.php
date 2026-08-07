<?php
$t = $t ?? static fn (string $key): string => $key;
$userRole = $userRole ?? 'employee';
$currentPath = $currentPath ?? '/';

$navigationGroups = $userRole === 'admin'
    ? [
        [
            'label' => $t('nav_groups.overview'),
            'items' => [
                ['label' => $t('nav.dashboard'), 'href' => '/admin/dashboard', 'match' => '/admin/dashboard', 'icon' => 'dashboard'],
                ['label' => $t('notifications.title'), 'href' => '/notifications', 'match' => '/notifications', 'icon' => 'bell'],
            ],
        ],
        [
            'label' => $t('nav_groups.workforce'),
            'items' => [
                ['label' => $t('nav.employees'), 'href' => '/employees', 'match' => '/employees', 'icon' => 'users'],
                ['label' => $t('nav.attendance'), 'href' => '/attendance', 'match' => '/attendance', 'icon' => 'clock'],
                ['label' => $t('nav.leave_requests'), 'href' => '/admin/leave', 'match' => '/admin/leave', 'icon' => 'leave'],
            ],
        ],
        [
            'label' => $t('nav_groups.insights'),
            'items' => [
                ['label' => $t('nav.analytics'), 'href' => '/admin/analytics', 'match' => '/admin/analytics', 'icon' => 'analytics'],
                ['label' => $t('nav.reports'), 'href' => '/admin/reports', 'match' => '/admin/reports', 'icon' => 'file-text'],
            ],
        ],
        [
            'label' => $t('nav_groups.administration'),
            'items' => [
                ['label' => $t('nav.workforce_policy_center'), 'href' => '/admin/work-schedules', 'match' => '/admin/work-schedules', 'icon' => 'timer'],
                ['label' => $t('nav.departments'), 'href' => '/departments', 'match' => '/departments', 'icon' => 'building'],
                ['label' => $t('nav.positions'), 'href' => '/positions', 'match' => '/positions', 'icon' => 'briefcase'],
                ['label' => $t('nav.holidays'), 'href' => '/holidays', 'match' => '/holidays', 'icon' => 'calendar'],
                ['label' => $t('nav.audit_logs'), 'href' => '/admin/audit-logs', 'match' => '/admin/audit-logs', 'icon' => 'shield'],
            ],
        ],
    ]
    : [
        [
            'label' => $t('nav_groups.overview'),
            'items' => [
                ['label' => $t('nav.dashboard'), 'href' => '/employee/dashboard', 'match' => '/employee/dashboard', 'icon' => 'dashboard'],
                ['label' => $t('notifications.title'), 'href' => '/notifications', 'match' => '/notifications', 'icon' => 'bell'],
            ],
        ],
        [
            'label' => $t('nav_groups.workforce'),
            'items' => [
                ['label' => $t('nav.attendance'), 'href' => '/attendance', 'match' => '/attendance', 'icon' => 'clock'],
                ['label' => $t('nav.my_leave'), 'href' => '/leave', 'match' => '/leave', 'icon' => 'leave'],
                ['label' => $t('nav.holidays'), 'href' => '/holidays', 'match' => '/holidays', 'icon' => 'calendar'],
            ],
        ],
    ];
?>
<div class="sapas-sidebar-content">
    <div class="sapas-sidebar-heading">
        <div class="sapas-sidebar-brand">
            <?php
            $brandVariant = 'primary';
            $brandHref = $userRole === 'admin' ? '/admin/dashboard' : '/employee/dashboard';
            $brandShowSubtitle = true;
            $brandWordmark = $t('app.acronym');
            $brandSubtitle = $t('app.sidebar_subtitle');
            require __DIR__ . '/brand.php';
            unset($brandVariant, $brandHref, $brandShowSubtitle, $brandWordmark, $brandSubtitle);
            ?>
        </div>
        <button type="button" class="sapas-sidebar-close" data-sidebar-close aria-label="<?= htmlspecialchars($t('layout.close_menu'), ENT_QUOTES, 'UTF-8') ?>">
            <?php
            $iconName = 'x';
            $iconClass = 'sapas-icon';
            require __DIR__ . '/icon.php';
            unset($iconName, $iconClass);
            ?>
        </button>
    </div>

    <nav class="sapas-sidebar-nav" aria-label="<?= htmlspecialchars($t('layout.primary_navigation'), ENT_QUOTES, 'UTF-8') ?>">
        <?php foreach ($navigationGroups as $group): ?>
            <section class="sapas-nav-group">
                <h2 class="sapas-nav-group-label"><?= htmlspecialchars((string) $group['label'], ENT_QUOTES, 'UTF-8') ?></h2>
                <div class="sapas-nav-group-links">
                    <?php foreach ($group['items'] as $item): ?>
                        <?php $isActive = str_starts_with($currentPath, $item['match']); ?>
                        <a
                            href="<?= htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8') ?>"
                            class="sapas-nav-link <?= $isActive ? 'is-active' : '' ?>"
                            <?= $isActive ? 'aria-current="page"' : '' ?>
                        >
                            <?php
                            $iconName = (string) ($item['icon'] ?? 'circle');
                            $iconClass = 'sapas-nav-icon';
                            require __DIR__ . '/icon.php';
                            unset($iconName, $iconClass);
                            ?>
                            <span><?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endforeach; ?>
    </nav>

    <div class="sapas-sidebar-footer">
        <form method="post" action="/logout" class="sapas-sidebar-logout-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token ?? '', ENT_QUOTES, 'UTF-8') ?>">
            <button type="submit" class="sapas-nav-link sapas-sidebar-logout">
                <?php
                $iconName = 'log-out';
                $iconClass = 'sapas-nav-icon';
                require __DIR__ . '/icon.php';
                unset($iconName, $iconClass);
                ?>
                <span><?= htmlspecialchars($t('layout.logout'), ENT_QUOTES, 'UTF-8') ?></span>
            </button>
        </form>
    </div>
</div>
