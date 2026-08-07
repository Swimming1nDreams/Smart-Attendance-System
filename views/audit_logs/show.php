<?php
use App\Services\AuditLogPresenter;
use App\Services\AuditSourcePresenter;

$t = $t ?? static fn (string $key): string => $key;
$layout = 'layouts/app';
$record = $record ?? [];
$oldValues = $oldValues ?? [];
$newValues = $newValues ?? [];
$pageActions = '<a href="/admin/audit-logs" class="sapas-btn sapas-btn-secondary">' . htmlspecialchars($t('audit_logs.back'), ENT_QUOTES, 'UTF-8') . '</a>';

$formatAuditDateTime = static function (mixed $value): string {
    $raw = trim((string) $value);
    if ($raw === '') {
        return '—';
    }
    try {
        return (new DateTimeImmutable($raw))->format('Y-m-d H:i:s');
    } catch (Throwable) {
        return $raw;
    }
};

$renderValues = static function (array $values) use ($t): string {
    if ($values === []) {
        return '<p class="text-sm text-slate-500">' . htmlspecialchars($t('common.none'), ENT_QUOTES, 'UTF-8') . '</p>';
    }

    $html = '<dl class="space-y-3">';
    foreach ($values as $key => $value) {
        $display = is_array($value)
            ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
            : (is_bool($value) ? ($value ? $t('common.yes') : $t('common.no')) : (string) $value);
        $html .= '<div class="rounded-lg bg-slate-50 p-4"><dt class="text-xs font-semibold text-slate-500">'
            . htmlspecialchars(AuditLogPresenter::humanizeKey((string) $key), ENT_QUOTES, 'UTF-8')
            . '</dt><dd class="mt-1 whitespace-pre-wrap break-words text-sm text-slate-900">'
            . htmlspecialchars((string) $display, ENT_QUOTES, 'UTF-8')
            . '</dd></div>';
    }

    return $html . '</dl>';
};

$actorName = trim((string) ($record['actor_name'] ?? ''));
$actorCode = trim((string) ($record['actor_staff_code'] ?? ''));
$actorIdentity = $actorName !== '' && $actorCode !== '' ? $actorName . ' (' . $actorCode . ')' : ($actorName !== '' ? $actorName : $t('audit_logs.system_actor'));
$entityType = (string) ($record['entity_type'] ?? '');
$entityId = $record['entity_id'] ?? null;
$source = AuditSourcePresenter::describe($record['ip_address'] ?? null, $record['user_agent'] ?? null);
$sourceLabel = $t('audit_logs.source.' . $source['network']);
$translateAuditAction = static fn (string $action): string => AuditLogPresenter::actionLabel($action, $t);
$translateEntityType = static fn (string $entityType): string => AuditLogPresenter::entityTypeLabel($entityType, $t);
$description = AuditLogPresenter::description((string) ($record['description'] ?? ''), $t);
?>
<div class="grid gap-6 lg:grid-cols-2">
    <section class="sapas-workspace-card lg:col-span-2">
        <dl class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
            <div>
                <dt class="text-xs font-semibold text-slate-500"><?= htmlspecialchars($t('audit_logs.date_time'), ENT_QUOTES, 'UTF-8') ?></dt>
                <dd class="mt-1 font-medium text-slate-900"><?= htmlspecialchars($formatAuditDateTime($record['created_at'] ?? null), ENT_QUOTES, 'UTF-8') ?></dd>
            </div>
            <div>
                <dt class="text-xs font-semibold text-slate-500"><?= htmlspecialchars($t('audit_logs.actor'), ENT_QUOTES, 'UTF-8') ?></dt>
                <dd class="mt-1 font-medium text-slate-900" title="<?= htmlspecialchars($actorIdentity, ENT_QUOTES, 'UTF-8') ?>">
                    <?= htmlspecialchars($actorIdentity, ENT_QUOTES, 'UTF-8') ?>
                </dd>
            </div>
            <div>
                <dt class="text-xs font-semibold text-slate-500"><?= htmlspecialchars($t('audit_logs.action'), ENT_QUOTES, 'UTF-8') ?></dt>
                <dd class="mt-1"><span class="sapas-audit-action-badge" title="<?= htmlspecialchars((string) ($record['action'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($translateAuditAction((string) ($record['action'] ?? '')), ENT_QUOTES, 'UTF-8') ?></span></dd>
            </div>
            <div>
                <dt class="text-xs font-semibold text-slate-500"><?= htmlspecialchars($t('audit_logs.target'), ENT_QUOTES, 'UTF-8') ?></dt>
                <dd class="mt-1 flex flex-wrap items-center gap-2 font-medium text-slate-900">
                    <span><?= htmlspecialchars($translateEntityType($entityType), ENT_QUOTES, 'UTF-8') ?></span>
                    <?php if ($entityId !== null && (string) $entityId !== ''): ?><span class="sapas-audit-target-id">#<?= htmlspecialchars((string) $entityId, ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                </dd>
            </div>
            <div>
                <dt class="text-xs font-semibold text-slate-500"><?= htmlspecialchars($t('audit_logs.ip_address'), ENT_QUOTES, 'UTF-8') ?></dt>
                <dd class="mt-1">
                    <div class="sapas-audit-source-cell" title="<?= htmlspecialchars($sourceLabel . "\n" . (string) ($source['ip_address'] ?? '—'), ENT_QUOTES, 'UTF-8') ?>">
                        <span class="sapas-audit-source-label"><?= htmlspecialchars($sourceLabel, ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="sapas-audit-ip"><?= htmlspecialchars((string) ($source['ip_address'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                </dd>
            </div>
            <div>
                <dt class="text-xs font-semibold text-slate-500"><?= htmlspecialchars($t('audit_logs.user_agent'), ENT_QUOTES, 'UTF-8') ?></dt>
                <dd class="mt-1 break-words text-sm text-slate-700">
                    <?= htmlspecialchars((string) ($source['user_agent_summary'] ?? ($record['user_agent'] ?? '—')), ENT_QUOTES, 'UTF-8') ?>
                </dd>
            </div>
        </dl>
        <?php if ($description !== '—'): ?>
            <p class="mt-5 rounded-xl bg-slate-50 p-4 text-sm leading-6 text-slate-700"><?= htmlspecialchars($description, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
    </section>
    <section class="sapas-workspace-card">
        <h2 class="text-xl font-semibold"><?= htmlspecialchars($t('audit_logs.old_values'), ENT_QUOTES, 'UTF-8') ?></h2>
        <div class="mt-4"><?= $renderValues($oldValues) ?></div>
    </section>
    <section class="sapas-workspace-card">
        <h2 class="text-xl font-semibold"><?= htmlspecialchars($t('audit_logs.new_values'), ENT_QUOTES, 'UTF-8') ?></h2>
        <div class="mt-4"><?= $renderValues($newValues) ?></div>
    </section>
</div>
