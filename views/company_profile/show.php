<?php
$t = $t ?? static fn (string $key): string => $key;
$profile = $profile ?? null;
$layout = 'layouts/app';
$pageTitle = $t('company_profile.title');
$pageDescription = $t('company_profile.description');
$pageActions = '<a href="/admin/company-profile/edit" class="sapas-btn sapas-btn-primary">' . htmlspecialchars($t('company_profile.edit_profile'), ENT_QUOTES, 'UTF-8') . '</a> '
    . '<a href="/admin/work-schedules" class="sapas-btn sapas-btn-secondary">' . htmlspecialchars($t('work_schedules.title'), ENT_QUOTES, 'UTF-8') . '</a>';
$title = $title ?? $pageTitle;
$value = static fn (mixed $item): string => trim((string) $item) !== '' ? (string) $item : '—';
$identityValue = static fn (string $key, mixed $item): string => \App\Services\BrandingPresenter::companyValue(
    $key,
    $item,
    $t('app.official_name'),
    $t('app.acronym'),
);
?>
<div class="sapas-workspace-stack">
    <?php if ($profile === null): ?>
        <div class="sapas-report-empty">
            <p class="sapas-report-empty-title"><?= htmlspecialchars($t('company_profile.empty_title'), ENT_QUOTES, 'UTF-8') ?></p>
            <p class="sapas-report-empty-copy"><?= htmlspecialchars($t('company_profile.empty_copy'), ENT_QUOTES, 'UTF-8') ?></p>
        </div>
    <?php else: ?>
        <section class="sapas-workspace-card">
            <div class="sapas-workspace-card-header">
                <div>
                    <p class="sapas-workspace-eyebrow"><?= htmlspecialchars($t('company_profile.identity'), ENT_QUOTES, 'UTF-8') ?></p>
                    <h2 class="sapas-workspace-card-title"><?= htmlspecialchars($identityValue('company_name', $profile['company_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></h2>
                    <p class="sapas-workspace-card-copy"><?= htmlspecialchars($t('company_profile.logo_deferred'), ENT_QUOTES, 'UTF-8') ?></p>
                </div>
                <span class="sapas-chip sapas-chip-info"><?= htmlspecialchars($t('company_profile.single_company'), ENT_QUOTES, 'UTF-8') ?></span>
            </div>

            <dl class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <div>
                    <dt class="text-xs font-semibold text-slate-500"><?= htmlspecialchars($t('company_profile.fields.short_name'), ENT_QUOTES, 'UTF-8') ?></dt>
                    <dd class="mt-1 font-medium text-slate-900"><?= htmlspecialchars($identityValue('short_name', $profile['short_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold text-slate-500"><?= htmlspecialchars($t('company_profile.fields.email'), ENT_QUOTES, 'UTF-8') ?></dt>
                    <dd class="mt-1 font-medium text-slate-900"><?= htmlspecialchars($value($profile['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?></dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold text-slate-500"><?= htmlspecialchars($t('company_profile.fields.phone'), ENT_QUOTES, 'UTF-8') ?></dt>
                    <dd class="mt-1 font-medium text-slate-900"><?= htmlspecialchars($value($profile['phone'] ?? ''), ENT_QUOTES, 'UTF-8') ?></dd>
                </div>
                <div class="md:col-span-2 xl:col-span-3">
                    <dt class="text-xs font-semibold text-slate-500"><?= htmlspecialchars($t('company_profile.fields.address'), ENT_QUOTES, 'UTF-8') ?></dt>
                    <dd class="mt-1 text-sm leading-6 text-slate-700"><?= nl2br(htmlspecialchars($value($profile['address'] ?? ''), ENT_QUOTES, 'UTF-8')) ?></dd>
                </div>
            </dl>
        </section>

        <section class="sapas-workspace-card">
            <div class="sapas-workspace-card-header">
                <div>
                    <h2 class="sapas-workspace-card-title"><?= htmlspecialchars($t('company_profile.regional_settings'), ENT_QUOTES, 'UTF-8') ?></h2>
                    <p class="sapas-workspace-card-copy"><?= htmlspecialchars($t('company_profile.regional_help'), ENT_QUOTES, 'UTF-8') ?></p>
                </div>
            </div>
            <dl class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <div>
                    <dt class="text-xs font-semibold text-slate-500"><?= htmlspecialchars($t('company_profile.fields.timezone'), ENT_QUOTES, 'UTF-8') ?></dt>
                    <dd class="mt-1 font-medium text-slate-900"><?= htmlspecialchars($value($profile['timezone'] ?? ''), ENT_QUOTES, 'UTF-8') ?></dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold text-slate-500"><?= htmlspecialchars($t('company_profile.fields.date_format'), ENT_QUOTES, 'UTF-8') ?></dt>
                    <dd class="mt-1 font-medium text-slate-900"><?= htmlspecialchars($value($profile['date_format'] ?? ''), ENT_QUOTES, 'UTF-8') ?></dd>
                    <p class="mt-1 text-xs text-slate-500"><?= htmlspecialchars($t('company_profile.format_help'), ENT_QUOTES, 'UTF-8') ?></p>
                </div>
                <div>
                    <dt class="text-xs font-semibold text-slate-500"><?= htmlspecialchars($t('company_profile.fields.time_format'), ENT_QUOTES, 'UTF-8') ?></dt>
                    <dd class="mt-1 font-medium text-slate-900"><?= htmlspecialchars($value($profile['time_format'] ?? ''), ENT_QUOTES, 'UTF-8') ?></dd>
                    <p class="mt-1 text-xs text-slate-500"><?= htmlspecialchars($t('company_profile.format_help'), ENT_QUOTES, 'UTF-8') ?></p>
                </div>
                <div>
                    <dt class="text-xs font-semibold text-slate-500"><?= htmlspecialchars($t('company_profile.fields.default_schedule'), ENT_QUOTES, 'UTF-8') ?></dt>
                    <dd class="mt-1 font-medium text-slate-900"><?= htmlspecialchars($value(\App\Services\BrandingPresenter::policyName($profile['default_schedule_name'] ?? '')), ENT_QUOTES, 'UTF-8') ?></dd>
                    <p class="mt-1 text-xs text-slate-500"><?= htmlspecialchars($t('company_profile.default_schedule_help'), ENT_QUOTES, 'UTF-8') ?></p>
                </div>
            </dl>
            <p class="mt-5 text-xs text-slate-500">
                <?= htmlspecialchars($t('company_profile.last_updated'), ENT_QUOTES, 'UTF-8') ?>:
                <?= htmlspecialchars($value($profile['updated_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
            </p>
        </section>
    <?php endif; ?>
</div>
