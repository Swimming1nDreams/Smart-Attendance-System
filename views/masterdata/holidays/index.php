<?php
$t = $t ?? static fn (string $key): string => $key;
if (!isset($search)) {
    $search = '';
}
if (!isset($holidays)) {
    $holidays = [];
}
$isAdmin = ($userRole ?? 'employee') === 'admin';

$layout = 'layouts/app';
$pageTitle = $t('masterdata.holidays.title');
$pageDescription = $isAdmin
    ? $t('masterdata.holidays_description')
    : $t('masterdata.holidays_employee_description');
$dashboardHref = $isAdmin ? '/admin/dashboard' : '/employee/dashboard';
$pageActions = $isAdmin
    ? '<a href="/holidays/create" class="sapas-btn sapas-btn-primary">' . htmlspecialchars($t('masterdata.holidays.add'), ENT_QUOTES, 'UTF-8') . '</a> '
        . '<a href="' . $dashboardHref . '" class="sapas-btn sapas-btn-secondary">' . htmlspecialchars($t('masterdata.back_to_dashboard'), ENT_QUOTES, 'UTF-8') . '</a>'
    : '<a href="' . $dashboardHref . '" class="sapas-btn sapas-btn-secondary">' . htmlspecialchars($t('masterdata.back_to_dashboard'), ENT_QUOTES, 'UTF-8') . '</a>';

$hasFilters = $search !== '';
$visibleCount = count($holidays);
$title = $title ?? $pageTitle;

$today = new DateTimeImmutable('today');
$upcomingHolidays = [];

foreach ($holidays as $holiday) {
    $holidayDate = DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($holiday['holiday_date'] ?? ''));

    if ($holidayDate instanceof DateTimeImmutable && $holidayDate >= $today) {
        $upcomingHolidays[] = $holiday;
    }
}

$upcomingPreview = array_slice($upcomingHolidays, 0, 3);
?>

<?php if ($isAdmin): ?>
    <div class="sapas-report-table-panel">
        <div class="sapas-report-table-header sapas-masterdata-filter-panel">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h2 class="text-xl font-semibold text-slate-900"><?= htmlspecialchars($t('masterdata.holidays.filters'), ENT_QUOTES, 'UTF-8') ?></h2>
                    <p class="mt-1 text-sm text-slate-500"><?= htmlspecialchars($t('masterdata.holidays.filters_help'), ENT_QUOTES, 'UTF-8') ?></p>
                </div>
                <?php if ($hasFilters): ?>
                    <a href="/holidays" class="sapas-btn sapas-btn-secondary"><?= htmlspecialchars($t('common.actions.reset'), ENT_QUOTES, 'UTF-8') ?></a>
                <?php endif; ?>
            </div>

            <form method="get" action="/holidays" class="sapas-masterdata-search-form">
                <div class="min-w-0 flex-1">
                    <label for="search" class="block text-sm font-medium text-slate-700"><?= htmlspecialchars($t('common.actions.search'), ENT_QUOTES, 'UTF-8') ?></label>
                    <input
                        type="search"
                        id="search"
                        name="search"
                        value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>"
                        placeholder="<?= htmlspecialchars($t('masterdata.holidays.placeholder'), ENT_QUOTES, 'UTF-8') ?>"
                        class="mt-2 sapas-form-control"
                    >
                </div>
                <button type="submit" class="sapas-btn sapas-btn-primary w-full sm:w-auto"><?= htmlspecialchars($t('common.actions.apply_filters'), ENT_QUOTES, 'UTF-8') ?></button>
            </form>

            <?php if ($hasFilters): ?>
                <div class="sapas-report-active-filters">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <p class="text-sm font-medium text-slate-500"><?= htmlspecialchars($t('common.labels.active_filters'), ENT_QUOTES, 'UTF-8') ?></p>
                            <div class="mt-2 flex flex-wrap gap-2">
                                <span class="sapas-chip sapas-chip-info"><?= htmlspecialchars($t('common.actions.search'), ENT_QUOTES, 'UTF-8') ?>: <?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                        </div>
                        <p class="text-sm text-slate-600"><?= $visibleCount ?> <?= htmlspecialchars($t('masterdata.matching_holidays'), ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <?php if (empty($holidays)): ?>
            <div class="sapas-report-empty">
                <p class="sapas-report-empty-title"><?= htmlspecialchars($t('masterdata.holidays.empty'), ENT_QUOTES, 'UTF-8') ?></p>
                <p class="sapas-report-empty-copy"><?= htmlspecialchars($hasFilters
                    ? $t('common.empty.try_broader_search')
                    : $t('masterdata.holidays.empty_create'), ENT_QUOTES, 'UTF-8') ?></p>
            </div>
        <?php else: ?>
            <div class="sapas-report-table-header sapas-data-table-summary">
                <div class="sapas-report-meta-grid sapas-data-table-summary-grid">
                    <div>
                        <p class="sapas-report-meta-label"><?= htmlspecialchars($t('common.showing'), ENT_QUOTES, 'UTF-8') ?></p>
                        <p class="sapas-report-meta-value"><?= $visibleCount ?> <?= htmlspecialchars($t('masterdata.matching_holidays'), ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                    <div>
                        <p class="sapas-report-meta-label"><?= htmlspecialchars($t('common.scope'), ENT_QUOTES, 'UTF-8') ?></p>
                        <p class="sapas-report-meta-value"><?= htmlspecialchars($hasFilters ? $t('common.filtered_results') : $t('masterdata.all_holidays'), ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                </div>
            </div>

            <div class="sapas-data-table-scroll overflow-x-auto">
                <table class="sapas-data-table sapas-masterdata-table sapas-holiday-admin-table divide-y divide-slate-200">
                    <thead>
                        <tr>
                            <th class="sapas-col-row-number"><?= htmlspecialchars($t('common.labels.no'), ENT_QUOTES, 'UTF-8') ?></th>
                            <th class="sapas-col-holiday-name"><?= htmlspecialchars($t('masterdata.holidays.title'), ENT_QUOTES, 'UTF-8') ?></th>
                            <th class="sapas-col-holiday-date"><?= htmlspecialchars($t('common.labels.date'), ENT_QUOTES, 'UTF-8') ?></th>
                            <th class="sapas-col-holiday-status"><?= htmlspecialchars($t('common.labels.status'), ENT_QUOTES, 'UTF-8') ?></th>
                            <th class="sapas-col-holiday-description"><?= htmlspecialchars($t('masterdata.description'), ENT_QUOTES, 'UTF-8') ?></th>
                            <th class="sapas-col-holiday-actions"><?= htmlspecialchars($t('common.labels.actions'), ENT_QUOTES, 'UTF-8') ?></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 bg-white">
                        <?php foreach ($holidays as $index => $holiday): ?>
                            <?php
                            $holidayName = trim((string) ($holiday['holiday_name'] ?? ''));
                            $holidayDate = DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($holiday['holiday_date'] ?? ''));
                            $description = trim((string) ($holiday['description'] ?? ''));
                            $isToday = $holidayDate instanceof DateTimeImmutable && $holidayDate == $today;
                            $isUpcoming = $holidayDate instanceof DateTimeImmutable && $holidayDate > $today;
                            $statusKey = $isToday
                                ? 'masterdata.holidays.status_today'
                                : ($isUpcoming ? 'masterdata.holidays.status_upcoming' : 'masterdata.holidays.status_past');
                            $statusClass = $isToday
                                ? 'sapas-badge sapas-badge-success'
                                : ($isUpcoming ? 'sapas-badge sapas-badge-info' : 'sapas-badge sapas-badge-neutral');
                            ?>
                            <tr>
                                <td><span class="sapas-row-number"><?= (int) $index + 1 ?></span></td>
                                <td>
                                    <p class="sapas-masterdata-name" title="<?= htmlspecialchars($holidayName, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($holidayName, ENT_QUOTES, 'UTF-8') ?></p>
                                </td>
                                <td>
                                    <div class="sapas-masterdata-date-cell">
                                        <p class="font-semibold text-slate-900"><?= htmlspecialchars($holidayDate?->format('M j, Y') ?? (string) ($holiday['holiday_date'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
                                        <p class="text-xs text-slate-500"><?= htmlspecialchars($holidayDate?->format('l') ?? '', ENT_QUOTES, 'UTF-8') ?></p>
                                    </div>
                                </td>
                                <td><span class="<?= $statusClass ?>"><?= htmlspecialchars($t($statusKey), ENT_QUOTES, 'UTF-8') ?></span></td>
                                <td>
                                    <p class="sapas-masterdata-description" title="<?= htmlspecialchars($description !== '' ? $description : '—', ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($description !== '' ? $description : '—', ENT_QUOTES, 'UTF-8') ?></p>
                                </td>
                                <td class="sapas-table-action">
                                    <div class="sapas-masterdata-actions">
                                        <a href="/holidays/edit?id=<?= (int) $holiday['id'] ?>" class="sapas-btn sapas-btn-secondary sapas-btn-spotlight"><?= htmlspecialchars($t('common.actions.edit'), ENT_QUOTES, 'UTF-8') ?></a>
                                        <form method="post" action="/holidays/delete?id=<?= (int) $holiday['id'] ?>" onsubmit="return confirm('<?= htmlspecialchars($t('common.confirm.delete_holiday'), ENT_QUOTES, 'UTF-8') ?>');">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                            <button type="submit" class="sapas-btn sapas-btn-danger-quiet"><?= htmlspecialchars($t('common.actions.delete'), ENT_QUOTES, 'UTF-8') ?></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="space-y-6">
        <section aria-labelledby="upcoming-holidays-title">
            <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <h2 id="upcoming-holidays-title" class="text-xl font-semibold text-slate-900"><?= htmlspecialchars($t('masterdata.holidays.upcoming_title'), ENT_QUOTES, 'UTF-8') ?></h2>
                    <p class="mt-1 text-sm text-slate-600"><?= htmlspecialchars($t('masterdata.holidays.upcoming_help'), ENT_QUOTES, 'UTF-8') ?></p>
                </div>
                <span class="inline-flex w-fit items-center rounded-full border border-brand-200 bg-brand-50 px-3 py-1 text-sm font-semibold text-brand-700">
                    <?= count($upcomingHolidays) ?> <?= htmlspecialchars($t('masterdata.holidays.scheduled'), ENT_QUOTES, 'UTF-8') ?>
                </span>
            </div>

            <?php if (empty($upcomingPreview)): ?>
                <div class="rounded-2xl border border-dashed border-slate-300 bg-white px-6 py-10 text-center shadow-sm">
                    <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-slate-100 text-slate-500" aria-hidden="true">
                        <svg viewBox="0 0 24 24" class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.8">
                            <path d="M7 3v3M17 3v3M4 9h16M5 5h14a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1Z" />
                        </svg>
                    </div>
                    <h3 class="mt-4 text-base font-semibold text-slate-900"><?= htmlspecialchars($t('masterdata.holidays.no_upcoming'), ENT_QUOTES, 'UTF-8') ?></h3>
                    <p class="mt-1 text-sm text-slate-600"><?= htmlspecialchars($t('masterdata.holidays.no_upcoming_help'), ENT_QUOTES, 'UTF-8') ?></p>
                </div>
            <?php else: ?>
                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    <?php foreach ($upcomingPreview as $index => $holiday): ?>
                        <?php
                        $holidayDate = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $holiday['holiday_date']);
                        $description = trim((string) ($holiday['description'] ?? ''));
                        $isToday = $holidayDate instanceof DateTimeImmutable && $holidayDate == $today;
                        ?>
                        <article class="relative flex min-h-52 flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                            <div class="absolute inset-x-0 top-0 h-1 <?= $index === 0 ? 'bg-brand-600' : 'bg-slate-200' ?>" aria-hidden="true"></div>
                            <div class="flex items-start gap-4">
                                <div class="flex w-16 shrink-0 flex-col items-center overflow-hidden rounded-xl border border-brand-200 bg-brand-50 text-center">
                                    <span class="w-full bg-brand-600 px-2 py-1 text-xs font-bold uppercase tracking-wide text-white"><?= htmlspecialchars($holidayDate?->format('M') ?? '', ENT_QUOTES, 'UTF-8') ?></span>
                                    <span class="px-2 py-2 text-2xl font-bold leading-none text-brand-800"><?= htmlspecialchars($holidayDate?->format('d') ?? '', ENT_QUOTES, 'UTF-8') ?></span>
                                </div>
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-start justify-between gap-2">
                                        <p class="text-sm font-medium text-slate-500"><?= htmlspecialchars($holidayDate?->format('l') ?? '', ENT_QUOTES, 'UTF-8') ?></p>
                                        <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold <?= $isToday ? 'bg-emerald-100 text-emerald-700' : 'bg-sky-100 text-sky-700' ?>">
                                            <?= htmlspecialchars($t($isToday ? 'masterdata.holidays.status_today' : 'masterdata.holidays.status_upcoming'), ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    </div>
                                    <h3 class="mt-2 text-lg font-semibold text-slate-900"><?= htmlspecialchars((string) $holiday['holiday_name'], ENT_QUOTES, 'UTF-8') ?></h3>
                                    <p class="mt-1 text-sm font-medium text-brand-700"><?= htmlspecialchars($holidayDate?->format('F j, Y') ?? (string) $holiday['holiday_date'], ENT_QUOTES, 'UTF-8') ?></p>
                                </div>
                            </div>
                            <p class="mt-4 line-clamp-3 text-sm leading-6 text-slate-600"><?= htmlspecialchars($description !== '' ? $description : $t('masterdata.holidays.company_holiday_default'), ENT_QUOTES, 'UTF-8') ?></p>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm" aria-labelledby="all-company-holidays-title">
            <div class="border-b border-slate-200 px-5 py-5 sm:px-6">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <h2 id="all-company-holidays-title" class="text-xl font-semibold text-slate-900"><?= htmlspecialchars($t('masterdata.holidays.all_company_title'), ENT_QUOTES, 'UTF-8') ?></h2>
                        <p class="mt-1 text-sm text-slate-600"><?= htmlspecialchars($t('masterdata.holidays.all_company_help'), ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                    <p class="text-sm font-semibold text-slate-600"><?= $visibleCount ?> <?= htmlspecialchars($t('masterdata.holidays.total'), ENT_QUOTES, 'UTF-8') ?></p>
                </div>
            </div>

            <?php if (empty($holidays)): ?>
                <div class="px-6 py-12 text-center">
                    <p class="text-base font-semibold text-slate-900"><?= htmlspecialchars($t('masterdata.holidays.empty'), ENT_QUOTES, 'UTF-8') ?></p>
                    <p class="mt-1 text-sm text-slate-600"><?= htmlspecialchars($t('masterdata.holidays.empty_employee'), ENT_QUOTES, 'UTF-8') ?></p>
                </div>
            <?php else: ?>
                <div class="divide-y divide-slate-200">
                    <?php foreach ($holidays as $holiday): ?>
                        <?php
                        $holidayDate = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $holiday['holiday_date']);
                        $description = trim((string) ($holiday['description'] ?? ''));
                        $isToday = $holidayDate instanceof DateTimeImmutable && $holidayDate == $today;
                        $isUpcoming = $holidayDate instanceof DateTimeImmutable && $holidayDate > $today;
                        $statusKey = $isToday
                            ? 'masterdata.holidays.status_today'
                            : ($isUpcoming ? 'masterdata.holidays.status_upcoming' : 'masterdata.holidays.status_past');
                        $statusClass = $isToday
                            ? 'bg-emerald-100 text-emerald-700'
                            : ($isUpcoming ? 'bg-sky-100 text-sky-700' : 'bg-slate-100 text-slate-600');
                        ?>
                        <article class="grid gap-4 px-5 py-4 sm:grid-cols-[4.5rem_minmax(0,1fr)_auto] sm:items-center sm:px-6">
                            <div class="flex w-16 flex-col items-center overflow-hidden rounded-xl border border-slate-200 bg-slate-50 text-center">
                                <span class="w-full bg-slate-800 px-2 py-1 text-xs font-bold uppercase tracking-wide text-white"><?= htmlspecialchars($holidayDate?->format('M') ?? '', ENT_QUOTES, 'UTF-8') ?></span>
                                <span class="px-2 py-2 text-xl font-bold leading-none text-slate-800"><?= htmlspecialchars($holidayDate?->format('d') ?? '', ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                            <div class="min-w-0">
                                <h3 class="font-semibold text-slate-900"><?= htmlspecialchars((string) $holiday['holiday_name'], ENT_QUOTES, 'UTF-8') ?></h3>
                                <p class="mt-1 text-sm text-slate-600"><?= htmlspecialchars($description !== '' ? $description : $t('masterdata.holidays.company_holiday_default'), ENT_QUOTES, 'UTF-8') ?></p>
                                <p class="mt-2 text-xs font-medium text-slate-500"><?= htmlspecialchars($holidayDate?->format('l, F j, Y') ?? (string) $holiday['holiday_date'], ENT_QUOTES, 'UTF-8') ?></p>
                            </div>
                            <span class="inline-flex w-fit rounded-full px-2.5 py-1 text-xs font-semibold <?= $statusClass ?>"><?= htmlspecialchars($t($statusKey), ENT_QUOTES, 'UTF-8') ?></span>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>
<?php endif; ?>
