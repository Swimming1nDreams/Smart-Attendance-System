<?php
$t = $t ?? static fn (string $key): string => $key;
$notifications = $notifications ?? [];
$unreadNotificationCount = (int) ($unreadNotificationCount ?? 0);
$totalNotificationCount = (int) ($total ?? count($notifications));
$page = max(1, (int) ($page ?? 1));
$perPage = (int) ($perPage ?? 20);
$filters = $filters ?? ['category' => '', 'priority' => '', 'status' => ''];
$paginationPath = '/notifications';
$paginationQuery = array_filter([
    'category' => $filters['category'] ?? '',
    'priority' => $filters['priority'] ?? '',
    'status' => $filters['status'] ?? '',
]);
$paginationQuery['per_page'] = (string) $perPage;
$paginationPageSizeOptions = [20, 50, 100];

$layout = 'layouts/app';
$pageTitle = $pageTitle ?? $t('notifications.title');
$pageDescription = $pageDescription ?? $t('notifications.description');
$pageActions = $unreadNotificationCount > 0
    ? '<form method="post" action="/notifications/read-all"><input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrf_token ?? '', ENT_QUOTES, 'UTF-8') . '"><button type="submit" class="sapas-btn sapas-btn-secondary">' . htmlspecialchars($t('notifications.mark_all_read'), ENT_QUOTES, 'UTF-8') . '</button></form>'
    : '';

function notificationText(string $key, array $context = []): string
{
    $message = \App\Core\Localization::current()->translate($key);
    foreach ($context as $name => $value) {
        if (!is_scalar($value)) {
            continue;
        }
        $message = strtr($message, [':' . $name => (string) $value, '{' . $name . '}' => (string) $value]);
    }
    return $message;
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
$categories = ['action', 'policy', 'attendance', 'absence', 'leave', 'overtime', 'security', 'system'];
$priorities = ['urgent', 'high', 'normal', 'low'];
?>

<section class="sapas-filter-panel mb-5">
    <form method="get" action="/notifications" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div>
            <label for="notification-status" class="block text-sm font-medium text-slate-700"><?= htmlspecialchars($t('notifications.filters.status'), ENT_QUOTES, 'UTF-8') ?></label>
            <select id="notification-status" name="status" class="mt-2 sapas-form-control">
                <option value=""><?= htmlspecialchars($t('notifications.filters.all'), ENT_QUOTES, 'UTF-8') ?></option>
                <?php foreach (['unread', 'read', 'archived'] as $status): ?><option value="<?= $status ?>" <?= ($filters['status'] ?? '') === $status ? 'selected' : '' ?>><?= htmlspecialchars($t('notifications.status.' . $status), ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="notification-category" class="block text-sm font-medium text-slate-700"><?= htmlspecialchars($t('notifications.filters.category'), ENT_QUOTES, 'UTF-8') ?></label>
            <select id="notification-category" name="category" class="mt-2 sapas-form-control">
                <option value=""><?= htmlspecialchars($t('notifications.filters.all'), ENT_QUOTES, 'UTF-8') ?></option>
                <?php foreach ($categories as $category): ?><option value="<?= $category ?>" <?= ($filters['category'] ?? '') === $category ? 'selected' : '' ?>><?= htmlspecialchars($t('notifications.categories.' . $category), ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="notification-priority" class="block text-sm font-medium text-slate-700"><?= htmlspecialchars($t('notifications.filters.priority'), ENT_QUOTES, 'UTF-8') ?></label>
            <select id="notification-priority" name="priority" class="mt-2 sapas-form-control">
                <option value=""><?= htmlspecialchars($t('notifications.filters.all'), ENT_QUOTES, 'UTF-8') ?></option>
                <?php foreach ($priorities as $priority): ?><option value="<?= $priority ?>" <?= ($filters['priority'] ?? '') === $priority ? 'selected' : '' ?>><?= htmlspecialchars($t('notifications.priorities.' . $priority), ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="flex items-end gap-2">
            <button class="sapas-btn sapas-btn-primary" type="submit"><?= htmlspecialchars($t('common.actions.apply_filters'), ENT_QUOTES, 'UTF-8') ?></button>
            <a class="sapas-btn sapas-btn-secondary" href="/notifications"><?= htmlspecialchars($t('common.actions.reset'), ENT_QUOTES, 'UTF-8') ?></a>
        </div>
        <input type="hidden" name="per_page" value="<?= $perPage ?>">
    </form>
</section>

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
                $dateTime = $formatNotificationDateTime($notification['available_at'] ?? ($notification['created_at'] ?? null));
                $context = json_decode((string) ($notification['context_json'] ?? ''), true);
                $context = is_array($context) ? $context : [];
                $titleText = notificationText((string) ($notification['title_key'] ?? ''), $context);
                $messageText = notificationText((string) ($notification['message_key'] ?? ''), $context);
                $category = (string) ($notification['category'] ?? 'system');
                $priority = (string) ($notification['priority'] ?? 'normal');
                ?>
                <article class="sapas-notification-row <?= $isUnread ? 'is-unread' : '' ?>" data-notification-category="<?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8') ?>" data-notification-priority="<?= htmlspecialchars($priority, ENT_QUOTES, 'UTF-8') ?>">
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
                                <div class="mt-2 flex flex-wrap gap-2">
                                    <span class="sapas-chip sapas-chip-info"><?= htmlspecialchars($t('notifications.categories.' . $category), ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php if ($priority !== 'normal'): ?><span class="sapas-chip <?= $priority === 'urgent' ? 'sapas-chip-danger' : 'sapas-chip-warning' ?>"><?= htmlspecialchars($t('notifications.priorities.' . $priority), ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
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
                                <?php else: ?>
                                    <form method="post" action="/notifications/unread">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="id" value="<?= (int) ($notification['id'] ?? 0) ?>">
                                        <button type="submit" class="sapas-btn sapas-btn-secondary"><?= htmlspecialchars($t('notifications.mark_unread'), ENT_QUOTES, 'UTF-8') ?></button>
                                    </form>
                                <?php endif; ?>
                                <?php if (($filters['status'] ?? '') !== 'archived'): ?>
                                    <form method="post" action="/notifications/archive">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="id" value="<?= (int) ($notification['id'] ?? 0) ?>">
                                        <button type="submit" class="sapas-btn sapas-btn-secondary"><?= htmlspecialchars($t('notifications.archive'), ENT_QUOTES, 'UTF-8') ?></button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
        <?php require __DIR__ . '/../partials/pagination.php'; ?>
    </section>
<?php endif; ?>
