<?php
$t = $t ?? static fn (string $key): string => $key;
$notifications = $notifications ?? [];
$unreadNotificationCount = (int) ($unreadNotificationCount ?? 0);
$totalNotificationCount = count($notifications);

$layout = 'layouts/app';
$pageTitle = $pageTitle ?? $t('notifications.title');
$pageDescription = $pageDescription ?? $t('notifications.description');
$pageActions = $unreadNotificationCount > 0
    ? '<form method="post" action="/notifications/read-all"><input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrf_token ?? '', ENT_QUOTES, 'UTF-8') . '"><button type="submit" class="sapas-btn sapas-btn-secondary">' . htmlspecialchars($t('notifications.mark_all_read'), ENT_QUOTES, 'UTF-8') . '</button></form>'
    : '';

function notificationText(string $key): string
{
    return \App\Core\Localization::current()->translate($key);
}

$formatNotificationDateTime = static function ($value): array {
    $raw = trim((string) $value);

    if ($raw === '') {
        return ['date' => '', 'time' => '', 'raw' => ''];
    }

    try {
        $dateTime = new DateTimeImmutable($raw);

        return [
            'date' => $dateTime->format('M j, Y'),
            'time' => $dateTime->format('g:i A'),
            'raw' => $raw,
        ];
    } catch (Throwable $exception) {
        return ['date' => $raw, 'time' => '', 'raw' => $raw];
    }
};

$renderIcon = static function (string $iconName, string $iconClass = 'sapas-icon'): void {
    require __DIR__ . '/../partials/icon.php';
};
?>

<?php if ($notifications === []): ?>
    <section class="sapas-empty-state">
        <span class="sapas-notifications-empty-icon"><?= $renderIcon('bell', 'sapas-icon') ?></span>
        <p class="sapas-report-empty-title"><?= htmlspecialchars($t('notifications.empty_title'), ENT_QUOTES, 'UTF-8') ?></p>
        <p class="sapas-report-empty-copy"><?= htmlspecialchars($t('notifications.empty_description'), ENT_QUOTES, 'UTF-8') ?></p>
    </section>
<?php else: ?>
    <section class="sapas-notifications-panel">
        <header class="sapas-notifications-summary">
            <div>
                <p class="sapas-notifications-summary-label"><?= htmlspecialchars($t('notifications.title'), ENT_QUOTES, 'UTF-8') ?></p>
                <p class="sapas-notifications-summary-value"><?= $totalNotificationCount ?> <?= htmlspecialchars($t('notifications.recent_count'), ENT_QUOTES, 'UTF-8') ?></p>
            </div>
            <div class="sapas-notifications-unread-summary" aria-label="<?= (int) $unreadNotificationCount ?> <?= htmlspecialchars($t('notifications.unread_count'), ENT_QUOTES, 'UTF-8') ?>">
                <span class="sapas-notifications-unread-dot" aria-hidden="true"></span>
                <span><?= (int) $unreadNotificationCount ?> <?= htmlspecialchars($t('notifications.unread_count'), ENT_QUOTES, 'UTF-8') ?></span>
            </div>
        </header>

        <div class="sapas-notification-list">
            <?php foreach ($notifications as $notification): ?>
                <?php
                $isUnread = empty($notification['read_at']);
                $linkUrl = trim((string) ($notification['link_url'] ?? ''));
                $dateTime = $formatNotificationDateTime($notification['created_at'] ?? null);
                $titleText = notificationText((string) ($notification['title_key'] ?? ''));
                $messageText = notificationText((string) ($notification['message_key'] ?? ''));
                ?>
                <article class="sapas-notification-row <?= $isUnread ? 'is-unread' : '' ?>">
                    <span class="<?= $isUnread ? 'sapas-notification-icon is-unread' : 'sapas-notification-icon' ?>">
                        <?= $renderIcon('bell', 'sapas-icon') ?>
                    </span>

                    <div class="sapas-notification-content">
                        <div class="sapas-notification-heading">
                            <div class="min-w-0">
                                <div class="flex min-w-0 items-center gap-2">
                                    <?php if ($isUnread): ?><span class="sapas-notification-unread-marker" aria-hidden="true"></span><?php endif; ?>
                                    <h2 class="sapas-notification-title"><?= htmlspecialchars($titleText, ENT_QUOTES, 'UTF-8') ?></h2>
                                </div>
                                <p class="sapas-notification-message"><?= htmlspecialchars($messageText, ENT_QUOTES, 'UTF-8') ?></p>
                            </div>

                            <time class="sapas-notification-time" datetime="<?= htmlspecialchars($dateTime['raw'], ENT_QUOTES, 'UTF-8') ?>" title="<?= htmlspecialchars($dateTime['raw'], ENT_QUOTES, 'UTF-8') ?>">
                                <span><?= htmlspecialchars($dateTime['date'], ENT_QUOTES, 'UTF-8') ?></span>
                                <?php if ($dateTime['time'] !== ''): ?><span><?= htmlspecialchars($dateTime['time'], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                            </time>
                        </div>

                        <div class="sapas-notification-footer">
                            <span class="<?= $isUnread ? 'sapas-notification-status is-unread' : 'sapas-notification-status' ?>">
                                <?= htmlspecialchars($isUnread ? $t('notifications.unread') : $t('notifications.read'), ENT_QUOTES, 'UTF-8') ?>
                            </span>

                            <div class="sapas-notification-actions">
                                <?php if ($linkUrl !== ''): ?>
                                    <a href="<?= htmlspecialchars($linkUrl, ENT_QUOTES, 'UTF-8') ?>" class="sapas-btn sapas-btn-secondary">
                                        <?= htmlspecialchars($t('notifications.open_related'), ENT_QUOTES, 'UTF-8') ?>
                                        <?= $renderIcon('arrow-right', 'sapas-action-icon') ?>
                                    </a>
                                <?php endif; ?>

                                <?php if ($isUnread): ?>
                                    <form method="post" action="/notifications/read">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="id" value="<?= (int) ($notification['id'] ?? 0) ?>">
                                        <button type="submit" class="sapas-btn sapas-btn-primary">
                                            <?= $renderIcon('check', 'sapas-action-icon') ?>
                                            <?= htmlspecialchars($t('notifications.mark_read'), ENT_QUOTES, 'UTF-8') ?>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>
