<?php
use App\Services\AuditLogPresenter;
use App\Services\AuditSourcePresenter;

$t = $t ?? static fn (string $key): string => $key;
$layout = 'layouts/app';
$pageTitle = $pageTitle ?? $t('audit_logs.title');
$pageDescription = $pageDescription ?? $t('audit_logs.description');
$records = $records ?? [];
$filters = $filters ?? [];
$errors = $errors ?? [];
$actions = $actions ?? [];
$entityTypes = $entityTypes ?? [];
$actors = $actors ?? [];
$total = (int) ($total ?? 0);
$page = (int) ($page ?? 1);
$perPage = (int) ($perPage ?? 20);
$pages = max(1, (int) ceil($total / $perPage));
$rowNumberStart = ((max(1, (int) $page) - 1) * max(1, (int) $perPage)) + 1;
$paginationPath = '/admin/audit-logs';
$paginationQuery = array_filter([
    'date_from' => (string) ($filters['date_from_input'] ?? ''),
    'date_to' => (string) ($filters['date_to_input'] ?? ''),
    'actor_user_id' => (string) ($filters['actor_user_id'] ?? ''),
    'action' => (string) ($filters['action'] ?? ''),
    'entity_type' => (string) ($filters['entity_type'] ?? ''),
    'entity_id' => (string) ($filters['entity_id'] ?? ''),
    'search' => (string) ($filters['search'] ?? ''),
], static fn (string $value): bool => $value !== '');
$paginationQuery['per_page'] = (string) $perPage;
$paginationPageSizeOptions = [20, 50, 100];

$splitAuditDateTime = static function (mixed $value): array {
    $raw = trim((string) $value);
    if ($raw === '') {
        return ['date' => '—', 'time' => ''];
    }

    try {
        $dateTime = new DateTimeImmutable($raw);
        return ['date' => $dateTime->format('Y-m-d'), 'time' => $dateTime->format('H:i:s')];
    } catch (Throwable) {
        $parts = preg_split('/\s+/', $raw, 2) ?: [];
        return ['date' => (string) ($parts[0] ?? $raw), 'time' => (string) ($parts[1] ?? '')];
    }
};

$formatAuditSource = static function (mixed $ipAddress, mixed $userAgent) use ($t): array {
    $source = AuditSourcePresenter::describe($ipAddress, $userAgent);
    $labelKey = 'audit_logs.source.' . $source['network'];

    return [
        'label' => $t($labelKey),
        'ip_address' => $source['ip_address'] ?? '—',
        'user_agent_summary' => $source['user_agent_summary'],
    ];
};

$translateAuditAction = static fn (string $action): string => AuditLogPresenter::actionLabel($action, $t);
$translateEntityType = static fn (string $entityType): string => AuditLogPresenter::entityTypeLabel($entityType, $t);
$translateAuditDescription = static fn (string $description): string => AuditLogPresenter::description($description, $t);
$previewAuditText = static function (string $value, int $limit): string {
    $value = trim($value);

    return mb_strlen($value, 'UTF-8') > $limit
        ? mb_substr($value, 0, $limit - 1, 'UTF-8') . '…'
        : $value;
};
?>
<?php if ($errors !== []): ?>
    <div class="sapas-alert-error">
        <ul class="list-disc pl-5">
            <?php foreach ($errors as $error): ?>
                <li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="sapas-report-filter-panel">
    <form method="get" action="/admin/audit-logs" class="sapas-report-filter-grid is-five sapas-audit-filter-grid">
        <input type="hidden" name="per_page" value="<?= $perPage ?>">
        <div>
            <label class="block text-sm font-medium text-slate-700" for="audit-date-from"><?= htmlspecialchars($t('audit_logs.from_date'), ENT_QUOTES, 'UTF-8') ?></label>
            <input id="audit-date-from" class="mt-2 sapas-form-control" type="date" name="date_from" value="<?= htmlspecialchars((string) ($filters['date_from_input'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div>
            <label class="block text-sm font-medium text-slate-700" for="audit-date-to"><?= htmlspecialchars($t('audit_logs.to_date'), ENT_QUOTES, 'UTF-8') ?></label>
            <input id="audit-date-to" class="mt-2 sapas-form-control" type="date" name="date_to" value="<?= htmlspecialchars((string) ($filters['date_to_input'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div>
            <label class="block text-sm font-medium text-slate-700" for="audit-actor"><?= htmlspecialchars($t('audit_logs.actor'), ENT_QUOTES, 'UTF-8') ?></label>
            <select id="audit-actor" class="mt-2 sapas-form-control" name="actor_user_id">
                <option value=""><?= htmlspecialchars($t('common.all'), ENT_QUOTES, 'UTF-8') ?></option>
                <?php foreach ($actors as $actor): ?>
                    <option value="<?= (int) $actor['id'] ?>" <?= (int) ($filters['actor_user_id'] ?? 0) === (int) $actor['id'] ? 'selected' : '' ?>><?= htmlspecialchars(trim((string) ($actor['staff_code'] ?? '') . ' ' . (string) $actor['name']), ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="block text-sm font-medium text-slate-700" for="audit-action"><?= htmlspecialchars($t('audit_logs.action'), ENT_QUOTES, 'UTF-8') ?></label>
            <select id="audit-action" class="mt-2 sapas-form-control" name="action">
                <option value=""><?= htmlspecialchars($t('common.all'), ENT_QUOTES, 'UTF-8') ?></option>
                <?php foreach ($actions as $action): ?>
                    <option value="<?= htmlspecialchars($action, ENT_QUOTES, 'UTF-8') ?>" <?= ($filters['action'] ?? '') === $action ? 'selected' : '' ?>><?= htmlspecialchars($translateAuditAction((string) $action), ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="block text-sm font-medium text-slate-700" for="audit-entity-type"><?= htmlspecialchars($t('audit_logs.entity_type'), ENT_QUOTES, 'UTF-8') ?></label>
            <select id="audit-entity-type" class="mt-2 sapas-form-control" name="entity_type">
                <option value=""><?= htmlspecialchars($t('common.all'), ENT_QUOTES, 'UTF-8') ?></option>
                <?php foreach ($entityTypes as $type): ?>
                    <option value="<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>" <?= ($filters['entity_type'] ?? '') === $type ? 'selected' : '' ?>><?= htmlspecialchars($translateEntityType((string) $type), ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="sapas-audit-search-field xl:col-span-2">
            <label class="block text-sm font-medium text-slate-700" for="audit-search"><?= htmlspecialchars($t('common.actions.search'), ENT_QUOTES, 'UTF-8') ?></label>
            <input id="audit-search" class="mt-2 sapas-form-control" type="search" name="search" placeholder="<?= htmlspecialchars($t('audit_logs.search_placeholder'), ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars((string) ($filters['search'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div class="sapas-audit-filter-actions xl:col-span-3">
            <button class="sapas-btn sapas-btn-primary" type="submit"><?= htmlspecialchars($t('common.actions.apply_filters'), ENT_QUOTES, 'UTF-8') ?></button>
            <a class="sapas-btn sapas-btn-secondary" href="/admin/audit-logs"><?= htmlspecialchars($t('common.actions.reset'), ENT_QUOTES, 'UTF-8') ?></a>
        </div>
    </form>
</div>

<div class="sapas-report-table-panel mt-6">
    <?php if ($records === []): ?>
        <div class="p-6 text-center text-slate-500"><?= htmlspecialchars($t('audit_logs.empty'), ENT_QUOTES, 'UTF-8') ?></div>
    <?php else: ?>
        <div class="sapas-data-table-scroll overflow-x-auto">
            <table class="sapas-data-table sapas-audit-table">
                <thead>
                    <tr>
                        <th class="sapas-col-row-number"><?= htmlspecialchars($t('common.labels.no'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th class="sapas-col-audit-date"><?= htmlspecialchars($t('audit_logs.date_time'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th class="sapas-col-audit-actor"><?= htmlspecialchars($t('audit_logs.actor'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th class="sapas-col-audit-action"><?= htmlspecialchars($t('audit_logs.action'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th class="sapas-col-audit-target"><?= htmlspecialchars($t('audit_logs.target'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th class="sapas-col-audit-description"><?= htmlspecialchars($t('audit_logs.description_label'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th class="sapas-col-audit-ip"><?= htmlspecialchars($t('audit_logs.ip_address'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th class="sapas-col-audit-view"><?= htmlspecialchars($t('common.labels.actions'), ENT_QUOTES, 'UTF-8') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($records as $index => $record): ?>
                        <?php
                        $dateTime = $splitAuditDateTime($record['created_at'] ?? null);
                        $actorName = trim((string) ($record['actor_name'] ?? ''));
                        $actorCode = trim((string) ($record['actor_staff_code'] ?? ''));
                        $actorIdentity = $actorName !== '' && $actorCode !== '' ? $actorName . ' (' . $actorCode . ')' : ($actorName !== '' ? $actorName : $t('audit_logs.system_actor'));
                        $action = (string) ($record['action'] ?? '');
                        $entityType = (string) ($record['entity_type'] ?? '');
                        $entityId = $record['entity_id'] ?? null;
                        $actionLabel = $translateAuditAction($action);
                        $entityTypeLabel = $translateEntityType($entityType);
                        $targetLabel = $entityId !== null && (string) $entityId !== ''
                            ? $entityTypeLabel . ' #' . (string) $entityId
                            : $entityTypeLabel;
                        $description = $translateAuditDescription((string) ($record['description'] ?? ''));
                        $source = $formatAuditSource($record['ip_address'] ?? null, $record['user_agent'] ?? null);
                        ?>
                        <tr>
                            <td><span class="sapas-row-number"><?= $rowNumberStart + (int) $index ?></span></td>
                            <td class="sapas-col-audit-date">
                                <div class="sapas-audit-date-cell">
                                    <span class="sapas-audit-date-value"><?= htmlspecialchars($dateTime['date'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php if ($dateTime['time'] !== ''): ?><span class="sapas-audit-time-value"><?= htmlspecialchars($dateTime['time'], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                                </div>
                            </td>
                            <td class="sapas-col-audit-actor">
                                <div class="sapas-person-cell">
                                    <div class="sapas-person-cell-head">
                                        <span class="sapas-person-name font-semibold text-slate-900" title="<?= htmlspecialchars($actorIdentity, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($actorIdentity, ENT_QUOTES, 'UTF-8') ?></span>
                                    </div>
                                </div>
                            </td>
                            <td class="sapas-col-audit-action">
                                <span class="sapas-audit-action-badge" title="<?= htmlspecialchars($actionLabel, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($previewAuditText($actionLabel, 32), ENT_QUOTES, 'UTF-8') ?></span>
                            </td>
                            <td class="sapas-col-audit-target">
                                <div class="sapas-audit-target-cell" title="<?= htmlspecialchars($targetLabel, ENT_QUOTES, 'UTF-8') ?>">
                                    <span class="sapas-audit-target-type"><?= htmlspecialchars($previewAuditText($entityTypeLabel, 24), ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php if ($entityId !== null && (string) $entityId !== ''): ?><span class="sapas-audit-target-id">#<?= htmlspecialchars((string) $entityId, ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                                </div>
                            </td>
                            <td class="sapas-col-audit-description">
                                <p class="sapas-audit-description" title="<?= htmlspecialchars($description, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($description, ENT_QUOTES, 'UTF-8') ?></p>
                            </td>
                            <td class="sapas-col-audit-ip">
                                <div class="sapas-audit-source-cell" title="<?= htmlspecialchars($source['label'] . "\n" . (string) $source['ip_address'], ENT_QUOTES, 'UTF-8') ?>">
                                    <span class="sapas-audit-source-label"><?= htmlspecialchars($source['label'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php if ($source['user_agent_summary'] !== null): ?><span class="sapas-audit-source-agent"><?= htmlspecialchars($source['user_agent_summary'], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                                    <span class="sapas-audit-ip"><?= htmlspecialchars((string) $source['ip_address'], ENT_QUOTES, 'UTF-8') ?></span>
                                </div>
                            </td>
                            <td class="sapas-col-audit-view sapas-table-action">
                                <a class="sapas-btn sapas-btn-secondary" href="/admin/audit-logs/show?id=<?= (int) $record['id'] ?>"><?= htmlspecialchars($t('common.actions.view_details'), ENT_QUOTES, 'UTF-8') ?></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <?php require __DIR__ . '/../partials/pagination.php'; ?>
</div>
