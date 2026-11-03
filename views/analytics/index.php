<?php
$t = $t ?? static fn (string $key): string => $key;
if (!isset($title)) {
    $title = $t('analytics_module.title');
}
if (!isset($month)) {
    $month = date('Y-m');
}
if (!isset($departments)) {
    $departments = [];
}
if (!isset($filterEmployees)) {
    $filterEmployees = [];
}
if (!isset($selectedDepartmentId)) {
    $selectedDepartmentId = null;
}
if (!isset($selectedEmployeeId)) {
    $selectedEmployeeId = null;
}
if (!isset($selectedEmployeeName)) {
    $selectedEmployeeName = null;
}
if (!isset($employeeAnalytics)) {
    $employeeAnalytics = [];
}
if (!isset($firstDayOfMonth)) {
    $firstDayOfMonth = date('Y-m-01');
}
if (!isset($lastDayOfMonth)) {
    $lastDayOfMonth = date('Y-m-t');
}
if (!isset($summary)) {
    $summary = [
        'total_employees' => 0,
        'employees_with_attendance' => 0,
        'average_attendance' => 0.0,
        'total_present_days' => 0,
        'total_late_arrivals' => 0,
        'total_early_departures' => 0,
        'total_early_departure_minutes' => 0,
        'total_absent_days' => 0,
        'total_approved_leave_days' => 0,
        'total_working_hours' => 0.0,
        'total_overtime_minutes' => 0,
    ];
}

$layout = 'layouts/app';
$title = $t('analytics_module.title');
$pageTitle = $title;
$pageDescription = $t('analytics_module.description');
$pageIcon = 'analytics';
$pageActions = '<div class="sapas-workspace-actions"><a href="/attendance" class="sapas-btn sapas-btn-primary">' . htmlspecialchars($t('analytics_module.open_attendance'), ENT_QUOTES, 'UTF-8') . '</a><a href="/admin/dashboard" class="sapas-btn sapas-btn-secondary">' . htmlspecialchars($t('analytics_module.back_to_dashboard'), ENT_QUOTES, 'UTF-8') . '</a></div>';

$renderIcon = static function (string $iconName, string $iconClass = 'sapas-icon'): void {
    require __DIR__ . '/../partials/icon.php';
};

$monthLabel = analyticsMonthLabel($month);
$visibleCount = count($employeeAnalytics);
$rowNumberStart = 1;
$averageAttendance = (float) ($summary['average_attendance'] ?? 0);
$healthMessage = $averageAttendance >= 90
    ? $t('analytics_module.attendance_health_good')
    : ($averageAttendance >= 75 ? $t('analytics_module.attendance_health_watch') : $t('analytics_module.attendance_health_low'));

$departmentNames = [];
foreach ($departments as $department) {
    $departmentNames[(int) $department['id']] = (string) $department['name'];
}

$scopeFilters = [
    $t('analytics_module.period') . ': ' . $monthLabel,
];

if ($selectedDepartmentId !== null && isset($departmentNames[(int) $selectedDepartmentId])) {
    $scopeFilters[] = $t('common.labels.department') . ': ' . $departmentNames[(int) $selectedDepartmentId];
}

if ($selectedEmployeeId !== null) {
    $scopeFilters[] = $t('common.labels.employee') . ': ' . (($selectedEmployeeName !== null && $selectedEmployeeName !== '')
        ? $selectedEmployeeName
        : 'ID #' . $selectedEmployeeId);
}

function analyticsAttendanceClasses(float $attendancePercentage): string
{
    if ($attendancePercentage >= 90) {
        return 'sapas-badge sapas-badge-success';
    }

    if ($attendancePercentage >= 75) {
        return 'sapas-badge sapas-badge-warning';
    }

    return 'sapas-badge sapas-badge-danger';
}

function analyticsMonthLabel(string $month): string
{
    $date = DateTimeImmutable::createFromFormat('!Y-m', $month);

    if ($date === false || $date->format('Y-m') !== $month) {
        return $month;
    }

    $localization = \App\Core\Localization::current();
    $monthName = $localization->translate('analytics_module.months.' . $date->format('m'));

    return strtr($localization->translate('analytics_module.month_display_pattern'), [
        '{month}' => $monthName,
        '{year}' => $date->format('Y'),
    ]);
}

function analyticsDateLabel(string $dateValue): string
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $dateValue);

    if ($date === false || $date->format('Y-m-d') !== $dateValue) {
        return $dateValue;
    }

    $localization = \App\Core\Localization::current();
    $monthName = $localization->translate('analytics_module.months.' . $date->format('m'));

    return strtr($localization->translate('analytics_module.date_display_pattern'), [
        '{month}' => $monthName,
        '{day}' => (string) (int) $date->format('j'),
        '{year}' => $date->format('Y'),
    ]);
}

function analyticsFallbackLabel(?string $value, string $fallbackKey): string
{
    $label = trim((string) $value);

    if ($label === '' || $label === 'No Department' || $label === 'No Position') {
        return \App\Core\Localization::current()->translate($fallbackKey);
    }

    return $label;
}

function analyticsFormatMinutes(mixed $minutes): string
{
    $minutes = max(0, (int) $minutes);

    if ($minutes === 0) {
        return '0';
    }

    $hours = intdiv($minutes, 60);
    $remainingMinutes = $minutes % 60;

    if ($hours === 0) {
        return (string) $minutes;
    }

    return $remainingMinutes === 0
        ? $hours . 'h'
        : $hours . 'h ' . $remainingMinutes . 'm';
}

function analyticsChartWidth(float $value, float $max = 100.0): string
{
    if ($max <= 0 || $value <= 0) {
        return '0%';
    }

    return number_format(min(100, max(2, ($value / $max) * 100)), 2, '.', '') . '%';
}

$attentionRows = $employeeAnalytics;
usort($attentionRows, static function (array $a, array $b): int {
    $aRisk = ((int) ($a['absent_days'] ?? 0) * 4) + ((int) ($a['late_days'] ?? 0) * 2) + ((int) ($a['early_departure_count'] ?? 0) * 2) + ((int) ($a['late_minutes_total'] ?? 0) / 15) - ((float) ($a['attendance_percentage'] ?? 0) / 12);
    $bRisk = ((int) ($b['absent_days'] ?? 0) * 4) + ((int) ($b['late_days'] ?? 0) * 2) + ((int) ($b['early_departure_count'] ?? 0) * 2) + ((int) ($b['late_minutes_total'] ?? 0) / 15) - ((float) ($b['attendance_percentage'] ?? 0) / 12);

    return $bRisk <=> $aRisk;
});
$workingDayCount = (int) ($summary['working_day_count'] ?? 0);
$totalPresentDays = (int) ($summary['total_present_days'] ?? 0);
$totalAbsentDays = (int) ($summary['total_absent_days'] ?? 0);
$totalLeaveDays = (int) ($summary['total_approved_leave_days'] ?? 0);
$totalLateArrivals = (int) ($summary['total_late_arrivals'] ?? 0);
$onTimePresentDays = max(0, $totalPresentDays - $totalLateArrivals);
$totalRecordedDays = $totalPresentDays + $totalAbsentDays + $totalLeaveDays;
$averageHoursPerPresentDay = $totalPresentDays > 0
    ? (float) ($summary['total_working_hours'] ?? 0) / $totalPresentDays
    : 0.0;

$compositionRows = [
    ['label' => $t('analytics_module.on_time_days'), 'value' => $onTimePresentDays, 'class' => 'is-emerald'],
    ['label' => $t('analytics_module.late_present_days'), 'value' => $totalLateArrivals, 'class' => 'is-amber'],
    ['label' => $t('analytics_module.absent_days'), 'value' => $totalAbsentDays, 'class' => 'is-rose'],
    ['label' => $t('analytics_module.approved_leave_days'), 'value' => $totalLeaveDays, 'class' => 'is-sky'],
];
$onTimeStop = $totalRecordedDays > 0 ? ($onTimePresentDays / $totalRecordedDays) * 100 : 0.0;
$lateStop = $totalRecordedDays > 0 ? $onTimeStop + (($totalLateArrivals / $totalRecordedDays) * 100) : 0.0;
$absentStop = $totalRecordedDays > 0 ? $lateStop + (($totalAbsentDays / $totalRecordedDays) * 100) : 0.0;

$rankingRows = $employeeAnalytics;
usort($rankingRows, static function (array $a, array $b): int {
    $aHasRecords = (int) ($a['recorded_days'] ?? 0) > 0;
    $bHasRecords = (int) ($b['recorded_days'] ?? 0) > 0;

    if ($aHasRecords !== $bHasRecords) {
        return $bHasRecords <=> $aHasRecords;
    }

    $attendanceComparison = (float) ($b['attendance_percentage'] ?? 0) <=> (float) ($a['attendance_percentage'] ?? 0);

    return $attendanceComparison !== 0
        ? $attendanceComparison
        : strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
});
$attentionRows = array_values(array_filter($attentionRows, static fn (array $row): bool =>
    (int) ($row['recorded_days'] ?? 0) === 0
    || (float) ($row['attendance_percentage'] ?? 0) < 90
    || (int) ($row['absent_days'] ?? 0) > 0
    || (int) ($row['late_days'] ?? 0) > 0
    || (int) ($row['early_departure_count'] ?? 0) > 0
));
$attentionCount = count($attentionRows);
$perfectAttendanceEmployees = array_filter($employeeAnalytics, static fn (array $row): bool => $row['has_100_percent_attendance'] ?? false);
$perfectAttendanceCount = count($perfectAttendanceEmployees);
?>
<div class="sapas-workspace-stack sapas-analytics-workspace">
    <?php if (!empty($errors)): ?>
        <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            <?php foreach ($errors as $error): ?><p><?= htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8') ?></p><?php endforeach; ?>
        </div>
    <?php endif; ?>
    <section class="sapas-workspace-card sapas-analytics-command-center">
        <div class="sapas-workspace-card-header">
            <div>
                <p class="sapas-workspace-eyebrow"><?= htmlspecialchars($t('analytics_module.workforce_intelligence'), ENT_QUOTES, 'UTF-8') ?></p>
                <h2 class="sapas-workspace-card-title"><?= htmlspecialchars($t('analytics_module.charts_title'), ENT_QUOTES, 'UTF-8') ?></h2>
                <p class="sapas-workspace-card-copy"><?= htmlspecialchars($t('analytics_module.charts_help'), ENT_QUOTES, 'UTF-8') ?></p>
            </div>
            <span class="sapas-chip sapas-chip-info"><?= $visibleCount ?> <?= htmlspecialchars($t('analytics_module.employees_in_view'), ENT_QUOTES, 'UTF-8') ?></span>
        </div>

        <div class="sapas-analytics-office-grid">
            <article class="sapas-analytics-visual-card is-composition">
                <div class="sapas-chart-card-header">
                    <div>
                        <h3 class="sapas-chart-title"><?= htmlspecialchars($t('analytics_module.status_composition'), ENT_QUOTES, 'UTF-8') ?></h3>
                        <p class="sapas-chart-copy"><?= htmlspecialchars($t('analytics_module.status_composition_help'), ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                </div>
                <?php if ($totalRecordedDays <= 0): ?>
                    <p class="sapas-report-empty-copy text-left"><?= htmlspecialchars($t('analytics_module.no_chart_data'), ENT_QUOTES, 'UTF-8') ?></p>
                <?php else: ?>
                    <div class="sapas-analytics-composition-layout">
                        <div class="sapas-analytics-donut" style="--sapas-on-time: <?= number_format($onTimeStop, 2, '.', '') ?>%; --sapas-late: <?= number_format($lateStop, 2, '.', '') ?>%; --sapas-absent: <?= number_format($absentStop, 2, '.', '') ?>%;">
                            <div><strong><?= $totalRecordedDays ?></strong><span><?= htmlspecialchars($t('analytics_module.recorded_workdays'), ENT_QUOTES, 'UTF-8') ?></span></div>
                        </div>
                        <div class="sapas-analytics-composition-list">
                            <?php foreach ($compositionRows as $composition): ?>
                                <div>
                                    <span class="sapas-chart-dot <?= htmlspecialchars((string) $composition['class'], ENT_QUOTES, 'UTF-8') ?>"></span>
                                    <p><strong><?= (int) $composition['value'] ?></strong><span><?= htmlspecialchars((string) $composition['label'], ENT_QUOTES, 'UTF-8') ?></span></p>
                                    <em><?= number_format(((int) $composition['value'] / $totalRecordedDays) * 100, 1) ?>%</em>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </article>

            <article class="sapas-analytics-visual-card is-ranking">
                <div class="sapas-chart-card-header">
                    <div>
                        <h3 class="sapas-chart-title"><?= htmlspecialchars($t('analytics_module.attendance_by_employee'), ENT_QUOTES, 'UTF-8') ?></h3>
                        <p class="sapas-chart-copy"><?= htmlspecialchars($t('analytics_module.attendance_by_employee_help'), ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                    <span class="sapas-analytics-target-note"><?= htmlspecialchars($t('analytics_module.office_target'), ENT_QUOTES, 'UTF-8') ?> 90%</span>
                </div>
                <?php if (empty($rankingRows)): ?>
                    <p class="sapas-report-empty-copy text-left"><?= htmlspecialchars($t('analytics_module.no_chart_data'), ENT_QUOTES, 'UTF-8') ?></p>
                <?php else: ?>
                    <div class="sapas-analytics-ranking-list sapas-analytics-scroll-region" tabindex="0" role="region" aria-label="<?= htmlspecialchars($t('analytics_module.attendance_by_employee'), ENT_QUOTES, 'UTF-8') ?>">
                        <?php foreach ($rankingRows as $rank => $row): ?>
                            <?php
                            $attendanceValue = (float) ($row['attendance_percentage'] ?? 0);
                            $hasAttendanceRecords = (int) ($row['recorded_days'] ?? 0) > 0;
                            ?>
                            <div class="sapas-analytics-ranking-row">
                                <span class="sapas-analytics-rank"><?= $rank + 1 ?></span>
                                <div class="min-w-0">
                                    <div class="sapas-chart-row-meta">
                                        <span class="sapas-chart-row-label"><?= htmlspecialchars((string) ($row['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                                        <span class="sapas-chart-row-value <?= $hasAttendanceRecords ? '' : 'text-slate-500' ?>"><?= $hasAttendanceRecords ? number_format($attendanceValue, 1) . '%' : htmlspecialchars($t('analytics_module.no_records_badge'), ENT_QUOTES, 'UTF-8') ?></span>
                                    </div>
                                    <?php if ($hasAttendanceRecords): ?>
                                        <div class="sapas-analytics-ranking-track"><span class="<?= $attendanceValue >= 90 ? 'is-emerald' : ($attendanceValue >= 75 ? 'is-amber' : 'is-rose') ?>" style="width: <?= analyticsChartWidth($attendanceValue) ?>"></span></div>
                                    <?php else: ?>
                                        <div class="sapas-analytics-ranking-track is-empty" aria-hidden="true"></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </article>
        </div>

        <div class="sapas-analytics-feature-panels grid grid-cols-1 xl:grid-cols-2 gap-6 mt-6 items-start">
            <article class="sapas-analytics-attention-panel !mt-0">
                <div class="sapas-analytics-attention-header">
                    <div>
                        <p class="sapas-workspace-eyebrow"><?= htmlspecialchars($t('analytics_module.manager_action'), ENT_QUOTES, 'UTF-8') ?></p>
                        <h3 class="sapas-chart-title"><?= htmlspecialchars($t('analytics_module.attention_title'), ENT_QUOTES, 'UTF-8') ?></h3>
                        <p class="sapas-chart-copy"><?= htmlspecialchars($t('analytics_module.attention_help'), ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                    <span class="sapas-analytics-attention-count"><?= $attentionCount ?></span>
                </div>
                <?php if (empty($attentionRows)): ?>
                    <div class="sapas-analytics-all-clear flex-1"><span><?= $renderIcon('check', 'sapas-icon') ?></span><div><strong><?= htmlspecialchars($t('analytics_module.all_clear_title'), ENT_QUOTES, 'UTF-8') ?></strong><p><?= htmlspecialchars($t('analytics_module.all_clear_help'), ENT_QUOTES, 'UTF-8') ?></p></div></div>
                <?php else: ?>
                    <div class="sapas-analytics-review-list sapas-analytics-scroll-region" tabindex="0" role="region" aria-label="<?= htmlspecialchars($t('analytics_module.attention_title'), ENT_QUOTES, 'UTF-8') ?>">
                        <?php foreach ($attentionRows as $row): ?>
                            <?php
                            $detailsUrl = '/attendance?' . http_build_query([
                                'employee_id' => (int) $row['id'],
                                'from' => $firstDayOfMonth,
                                'to' => $lastDayOfMonth,
                            ]);
                            $reviewContext = array_values(array_unique(array_filter([
                                trim((string) ($row['department_name'] ?? '')),
                                trim((string) ($row['position_name'] ?? '')),
                            ], static fn (string $value): bool => $value !== '')));
                            $reviewContextLabel = $reviewContext !== []
                                ? implode(' / ', $reviewContext)
                                : analyticsFallbackLabel(null, 'analytics_module.no_department');
                            ?>
                            <a href="<?= htmlspecialchars($detailsUrl, ENT_QUOTES, 'UTF-8') ?>" class="sapas-analytics-attention-card">
                                <div class="sapas-analytics-attention-card-head">
                                    <span class="sapas-analytics-person-icon"><?= $renderIcon('user', 'sapas-icon') ?></span>
                                    <div class="sapas-analytics-review-identity"><strong><?= htmlspecialchars((string) ($row['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong><span><?= htmlspecialchars($reviewContextLabel, ENT_QUOTES, 'UTF-8') ?></span></div>
                                    <?php if ((int) ($row['recorded_days'] ?? 0) > 0): ?>
                                        <span class="<?= analyticsAttendanceClasses((float) ($row['attendance_percentage'] ?? 0)) ?>"><?= number_format((float) ($row['attendance_percentage'] ?? 0), 1) ?>%</span>
                                    <?php else: ?>
                                        <span class="sapas-badge sapas-badge-neutral"><?= htmlspecialchars($t('analytics_module.no_records_badge'), ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php endif; ?>
                                </div>
                                <?php if ((int) ($row['recorded_days'] ?? 0) > 0): ?>
                                    <div class="sapas-analytics-attention-signals">
                                        <span><strong><?= (int) ($row['absent_days'] ?? 0) ?></strong> <?= htmlspecialchars($t('analytics_module.absent'), ENT_QUOTES, 'UTF-8') ?></span>
                                        <span><strong><?= (int) ($row['late_days'] ?? 0) ?></strong> <?= htmlspecialchars($t('analytics_module.late'), ENT_QUOTES, 'UTF-8') ?></span>
                                        <span><strong><?= (int) ($row['late_minutes_total'] ?? 0) ?></strong> <?= htmlspecialchars($t('analytics_module.minutes_unit'), ENT_QUOTES, 'UTF-8') ?></span>
                                        <?php if ((int) ($row['early_departure_count'] ?? 0) > 0): ?><span><strong><?= (int) ($row['early_departure_count'] ?? 0) ?></strong> <?= htmlspecialchars($t('analytics_module.early_departures'), ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                                        <?php if ((int) ($row['early_departure_minutes'] ?? 0) > 0): ?><span><strong><?= (int) ($row['early_departure_minutes'] ?? 0) ?></strong> <?= htmlspecialchars($t('analytics_module.early_minutes'), ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <p class="sapas-analytics-data-gap"><?= htmlspecialchars($t('analytics_module.no_records_help'), ENT_QUOTES, 'UTF-8') ?></p>
                                <?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </article>

            <article class="sapas-workspace-card sapas-analytics-recognition-panel border border-amber-200 bg-amber-50/40">
                <div class="sapas-analytics-attention-header border-b border-amber-200 pb-4 mb-4">
                    <div>
                        <p class="sapas-workspace-eyebrow text-amber-700"><?= htmlspecialchars($t('analytics_module.top_performers'), ENT_QUOTES, 'UTF-8') ?></p>
                        <h3 class="sapas-chart-title text-amber-900"><?= htmlspecialchars($t('analytics_module.recognition_title'), ENT_QUOTES, 'UTF-8') ?></h3>
                        <p class="sapas-chart-copy text-amber-800"><?= htmlspecialchars($t('analytics_module.recognition_help'), ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                    <span class="sapas-analytics-recognition-count"><?= $perfectAttendanceCount ?></span>
                </div>
                <?php if (empty($perfectAttendanceEmployees)): ?>
                    <div class="flex-1 flex flex-col items-center justify-center p-8 text-center bg-white/50 rounded-xl border border-amber-100/50">
                        <span class="flex h-12 w-12 items-center justify-center rounded-full bg-amber-100/50 text-amber-400 mb-4"><?= $renderIcon('check', 'sapas-icon') ?></span>
                        <strong class="text-sm font-medium text-amber-900"><?= htmlspecialchars($t('analytics_module.no_recognition_title'), ENT_QUOTES, 'UTF-8') ?></strong>
                        <p class="mt-1 text-sm text-amber-700/80"><?= htmlspecialchars($t('analytics_module.no_recognition_help'), ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                <?php else: ?>
                    <div class="sapas-analytics-recognition-list sapas-analytics-scroll-region" tabindex="0" role="region" aria-label="<?= htmlspecialchars($t('analytics_module.recognition_title'), ENT_QUOTES, 'UTF-8') ?>">
                        <?php foreach ($perfectAttendanceEmployees as $row): ?>
                            <?php
                            $detailsUrl = '/attendance?' . http_build_query([
                                'employee_id' => (int) $row['id'],
                                'from' => $firstDayOfMonth,
                                'to' => $lastDayOfMonth,
                            ]);
                            $recognitionContext = array_values(array_unique(array_filter([
                                trim((string) ($row['department_name'] ?? '')),
                                trim((string) ($row['position_name'] ?? '')),
                            ], static fn (string $value): bool => $value !== '')));
                            $recognitionContextLabel = $recognitionContext !== []
                                ? implode(' / ', $recognitionContext)
                                : analyticsFallbackLabel(null, 'analytics_module.no_department');
                            ?>
                            <a href="<?= htmlspecialchars($detailsUrl, ENT_QUOTES, 'UTF-8') ?>" class="sapas-analytics-recognition-card">
                                <span class="sapas-analytics-recognition-star" aria-hidden="true">&#9733;</span>
                                <div class="sapas-analytics-recognition-identity">
                                    <p class="sapas-analytics-recognition-name"><?= htmlspecialchars((string) ($row['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
                                    <p class="sapas-analytics-recognition-context"><?= htmlspecialchars($recognitionContextLabel, ENT_QUOTES, 'UTF-8') ?></p>
                                </div>
                                <span class="sapas-analytics-recognition-score">100%</span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </article>
        </div>
    </section>

    <section class="sapas-analytics-summary" aria-labelledby="analytics-summary-title">
        <header class="sapas-analytics-summary-header">
            <div>
                <p class="sapas-workspace-eyebrow"><?= htmlspecialchars($t('analytics_module.period'), ENT_QUOTES, 'UTF-8') ?></p>
                <h2 id="analytics-summary-title" class="sapas-analytics-summary-title"><?= htmlspecialchars($monthLabel, ENT_QUOTES, 'UTF-8') ?></h2>
            </div>
        </header>
        <div class="sapas-analytics-summary-grid">
            <article class="sapas-analytics-summary-card is-primary">
                <p class="sapas-report-summary-label"><?= htmlspecialchars($t('analytics_module.average_attendance'), ENT_QUOTES, 'UTF-8') ?></p>
                <p class="sapas-analytics-summary-value text-emerald-600"><?= number_format($averageAttendance, 1) ?>%</p>
            </article>
            <article class="sapas-analytics-summary-card">
                <p class="sapas-report-meta-label"><?= htmlspecialchars($t('analytics_module.attendance_health'), ENT_QUOTES, 'UTF-8') ?></p>
                <p class="sapas-analytics-summary-message"><?= htmlspecialchars($healthMessage, ENT_QUOTES, 'UTF-8') ?></p>
            </article>
            <article class="sapas-analytics-summary-card">
                <p class="sapas-report-meta-label"><?= htmlspecialchars($t('common.showing'), ENT_QUOTES, 'UTF-8') ?></p>
                <p class="sapas-analytics-summary-message"><?= $visibleCount ?> <?= htmlspecialchars($t('analytics_module.employees_in_view'), ENT_QUOTES, 'UTF-8') ?></p>
            </article>
        </div>
    </section>

    <section class="sapas-report-table-panel sapas-analytics-panel sapas-analytics-filter-panel">
        <div class="sapas-report-table-header">
            <div class="sapas-filter-header flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div class="min-w-0">
                    <h2 class="sapas-workspace-card-title"><?= htmlspecialchars($t('analytics_module.filters_title'), ENT_QUOTES, 'UTF-8') ?></h2>
                    <p class="sapas-workspace-card-copy"><?= htmlspecialchars($t('analytics_module.filters_help'), ENT_QUOTES, 'UTF-8') ?></p>
                </div>
                <a href="/admin/analytics" class="sapas-btn sapas-btn-secondary sapas-filter-reset"><?= htmlspecialchars($t('common.actions.reset'), ENT_QUOTES, 'UTF-8') ?></a>
            </div>

            <form method="get" action="/admin/analytics" class="sapas-report-filter-grid sapas-polish-filter-grid is-four mt-6">
                <div>
                    <label for="month" class="block text-sm font-medium text-slate-700"><?= htmlspecialchars($t('analytics_module.month'), ENT_QUOTES, 'UTF-8') ?></label>
                    <input type="month" id="month" name="month" value="<?= htmlspecialchars($month, ENT_QUOTES, 'UTF-8') ?>" class="sapas-form-control mt-2">
                </div>
                <div>
                    <label for="department_id" class="block text-sm font-medium text-slate-700"><?= htmlspecialchars($t('common.labels.department'), ENT_QUOTES, 'UTF-8') ?></label>
                    <select id="department_id" name="department_id" class="sapas-form-control mt-2">
                        <option value=""><?= htmlspecialchars($t('reports_module.all_departments'), ENT_QUOTES, 'UTF-8') ?></option>
                        <?php foreach ($departments as $department): ?>
                            <option value="<?= (int) $department['id'] ?>" <?= ((int) $selectedDepartmentId === (int) $department['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($department['name'], ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="employee_id" class="block text-sm font-medium text-slate-700"><?= htmlspecialchars($t('common.labels.employee'), ENT_QUOTES, 'UTF-8') ?></label>
                    <select id="employee_id" name="employee_id" class="sapas-form-control mt-2">
                        <option value=""><?= htmlspecialchars($t('reports_module.all_employees'), ENT_QUOTES, 'UTF-8') ?></option>
                        <?php foreach ($filterEmployees as $employee): ?>
                            <option value="<?= (int) $employee['id'] ?>" <?= ((int) $selectedEmployeeId === (int) $employee['id']) ? 'selected' : '' ?>>
                                <?php
                                $employeeName = trim((string) ($employee['name'] ?? ''));
                                $staffCode = trim((string) ($employee['staff_code'] ?? ''));
                                $employeeIdentity = $staffCode !== '' ? $employeeName . ' (' . $staffCode . ')' : $employeeName;
                                $employeeEmail = trim((string) ($employee['email'] ?? ''));
                                ?>
                                <?= htmlspecialchars($employeeEmail !== '' ? $employeeIdentity . ' — ' . $employeeEmail : $employeeIdentity, ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="sapas-filter-actions flex items-end">
                    <button type="submit" class="sapas-btn sapas-btn-primary w-full"><?= htmlspecialchars($t('common.actions.apply_filters'), ENT_QUOTES, 'UTF-8') ?></button>
                </div>
            </form>

            <div class="sapas-report-active-filters">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p class="text-sm font-medium text-slate-500"><?= htmlspecialchars($t('analytics_module.current_scope'), ENT_QUOTES, 'UTF-8') ?></p>
                        <div class="mt-2 flex flex-wrap gap-2">
                            <?php foreach ($scopeFilters as $filter): ?>
                                <span class="sapas-chip sapas-chip-info"><?= htmlspecialchars($filter, ENT_QUOTES, 'UTF-8') ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <p class="text-sm text-slate-600"><?= $visibleCount ?> <?= htmlspecialchars($t('analytics_module.employees_in_view'), ENT_QUOTES, 'UTF-8') ?></p>
                </div>
            </div>
        </div>

        <div class="sapas-analytics-overview">
            <div class="sapas-analytics-section-heading">
                <div>
                    <p class="sapas-workspace-eyebrow"><?= htmlspecialchars($t('analytics_module.executive_summary'), ENT_QUOTES, 'UTF-8') ?></p>
                    <h2 class="sapas-workspace-card-title"><?= htmlspecialchars($t('analytics_module.overview_title'), ENT_QUOTES, 'UTF-8') ?></h2>
                    <p class="sapas-workspace-card-copy"><?= htmlspecialchars($t('analytics_module.overview_help'), ENT_QUOTES, 'UTF-8') ?></p>
                </div>
                <span class="sapas-chip sapas-chip-info"><?= htmlspecialchars($monthLabel, ENT_QUOTES, 'UTF-8') ?></span>
            </div>

            <div class="sapas-analytics-kpi-grid">
                <article class="sapas-analytics-kpi is-primary">
                    <span class="sapas-analytics-kpi-icon"><?= $renderIcon('analytics', 'sapas-icon') ?></span>
                    <div>
                        <p class="sapas-report-summary-label"><?= htmlspecialchars($t('analytics_module.average_attendance'), ENT_QUOTES, 'UTF-8') ?></p>
                        <p class="sapas-analytics-kpi-value"><?= number_format($averageAttendance, 1) ?>%</p>
                        <p class="sapas-analytics-kpi-note"><?= (int) ($summary['employees_with_attendance'] ?? 0) ?> <?= htmlspecialchars($t('analytics_module.measured_employees'), ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars($healthMessage, ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                </article>
                <article class="sapas-analytics-kpi is-neutral">
                    <span class="sapas-analytics-kpi-icon"><?= $renderIcon('users', 'sapas-icon') ?></span>
                    <div>
                        <p class="sapas-report-summary-label"><?= htmlspecialchars($t('analytics_module.active_employees'), ENT_QUOTES, 'UTF-8') ?></p>
                        <p class="sapas-analytics-kpi-value"><?= (int) $summary['total_employees'] ?></p>
                        <p class="sapas-analytics-kpi-note"><?= htmlspecialchars($t('analytics_module.active_employees_help'), ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                </article>
                <article class="sapas-analytics-kpi is-warning">
                    <span class="sapas-analytics-kpi-icon"><?= $renderIcon('clock', 'sapas-icon') ?></span>
                    <div>
                        <p class="sapas-report-summary-label"><?= htmlspecialchars($t('analytics_module.late_arrivals'), ENT_QUOTES, 'UTF-8') ?></p>
                        <p class="sapas-analytics-kpi-value"><?= $totalLateArrivals ?></p>
                        <p class="sapas-analytics-kpi-note"><?= (int) ($summary['total_late_minutes'] ?? 0) ?> <?= htmlspecialchars($t('analytics_module.minutes_lost'), ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                </article>
                <article class="sapas-analytics-kpi is-danger">
                    <span class="sapas-analytics-kpi-icon"><?= $renderIcon('warning', 'sapas-icon') ?></span>
                    <div>
                        <p class="sapas-report-summary-label"><?= htmlspecialchars($t('analytics_module.absent_days'), ENT_QUOTES, 'UTF-8') ?></p>
                        <p class="sapas-analytics-kpi-value"><?= $totalAbsentDays ?></p>
                        <p class="sapas-analytics-kpi-note"><?= htmlspecialchars($t('analytics_module.absent_days_help'), ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                </article>
            </div>

            <div class="sapas-analytics-metric-strip">
                <div><span class="sapas-analytics-metric-icon"><?= $renderIcon('calendar', 'sapas-icon') ?></span><div><p><?= htmlspecialchars($t('analytics_module.working_days_in_scope'), ENT_QUOTES, 'UTF-8') ?></p><strong><?= $workingDayCount ?></strong></div></div>
                <div><span class="sapas-analytics-metric-icon"><?= $renderIcon('briefcase', 'sapas-icon') ?></span><div><p><?= htmlspecialchars($t('common.labels.working_hours'), ENT_QUOTES, 'UTF-8') ?></p><strong><?= number_format((float) $summary['total_working_hours'], 1) ?></strong></div></div>
                <div><span class="sapas-analytics-metric-icon"><?= $renderIcon('timer', 'sapas-icon') ?></span><div><p><?= htmlspecialchars($t('analytics_module.average_hours_day'), ENT_QUOTES, 'UTF-8') ?></p><strong><?= number_format($averageHoursPerPresentDay, 1) ?></strong></div></div>
                <div><span class="sapas-analytics-metric-icon"><?= $renderIcon('leave', 'sapas-icon') ?></span><div><p><?= htmlspecialchars($t('analytics_module.approved_leave_days'), ENT_QUOTES, 'UTF-8') ?></p><strong><?= $totalLeaveDays ?></strong></div></div>
                <div><span class="sapas-analytics-metric-icon"><?= $renderIcon('clock', 'sapas-icon') ?></span><div><p><?= htmlspecialchars($t('attendance.approved_overtime'), ENT_QUOTES, 'UTF-8') ?></p><strong><?= htmlspecialchars(analyticsFormatMinutes($summary['total_overtime_minutes'] ?? 0), ENT_QUOTES, 'UTF-8') ?></strong></div></div>
            </div>
        </div>
    </section>

    <section class="sapas-report-table-panel sapas-analytics-panel">
        <div class="sapas-report-table-header">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div class="min-w-0">
                    <h2 class="sapas-workspace-card-title"><?= htmlspecialchars($t('analytics_module.breakdown_title'), ENT_QUOTES, 'UTF-8') ?></h2>
                    <p class="sapas-workspace-card-copy"><?= htmlspecialchars($t('analytics_module.breakdown_help'), ENT_QUOTES, 'UTF-8') ?></p>
                </div>
                <p class="text-sm text-slate-600"><?= htmlspecialchars(analyticsDateLabel($firstDayOfMonth), ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($t('analytics_module.date_range_separator'), ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars(analyticsDateLabel($lastDayOfMonth), ENT_QUOTES, 'UTF-8') ?></p>
            </div>
        </div>

        <?php if (empty($employeeAnalytics)): ?>
            <div class="sapas-report-empty sapas-polish-empty-state">
                <p class="sapas-report-empty-title"><?= htmlspecialchars($t('analytics_module.no_data_title'), ENT_QUOTES, 'UTF-8') ?></p>
                <p class="sapas-report-empty-copy">
                    <?php if ($selectedEmployeeId !== null): ?>
                        <?= htmlspecialchars($t('analytics_module.no_data_employee'), ENT_QUOTES, 'UTF-8') ?>
                    <?php elseif ($selectedDepartmentId !== null): ?>
                        <?= htmlspecialchars($t('analytics_module.no_data_department'), ENT_QUOTES, 'UTF-8') ?>
                    <?php else: ?>
                        <?= htmlspecialchars($t('analytics_module.no_data_default'), ENT_QUOTES, 'UTF-8') ?>
                    <?php endif; ?>
                </p>
            </div>
        <?php else: ?>
            <div class="sapas-report-table-header sapas-data-table-summary">
                <div class="grid gap-4 md:grid-cols-2 sapas-data-table-summary-grid">
                    <div>
                        <p class="sapas-report-meta-label"><?= htmlspecialchars($t('common.showing'), ENT_QUOTES, 'UTF-8') ?></p>
                        <p class="sapas-report-meta-value"><?= $visibleCount ?> <?= htmlspecialchars($t('analytics_module.employees_in_view'), ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                    <div>
                        <p class="sapas-report-meta-label"><?= htmlspecialchars($t('analytics_module.drilldown_range'), ENT_QUOTES, 'UTF-8') ?></p>
                        <p class="sapas-report-meta-value"><?= htmlspecialchars($monthLabel, ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                </div>
            </div>

            <div class="overflow-x-auto sapas-data-table-scroll">
                <table class="min-w-full divide-y divide-slate-200 sapas-data-table sapas-analytics-table">
                    <colgroup>
                        <col class="sapas-col-row-number">
                        <col class="sapas-col-employee">
                        <col class="sapas-col-department">
                        <col class="sapas-col-position">
                        <col class="sapas-col-percent">
                        <col class="sapas-col-number">
                        <col class="sapas-col-number">
                        <col class="sapas-col-approved-leave">
                        <col class="sapas-col-late">
                        <col class="sapas-col-hours">
                        <col class="sapas-col-overtime">
                        <col class="sapas-col-action">
                    </colgroup>
                    <thead>
                        <tr>
                            <th class="whitespace-nowrap px-4 py-3 text-left text-sm font-medium text-slate-700"><?= htmlspecialchars($t('common.labels.no'), ENT_QUOTES, 'UTF-8') ?></th>
                            <th class="whitespace-nowrap px-4 py-3 text-left text-sm font-medium text-slate-700"><?= htmlspecialchars($t('common.labels.employee'), ENT_QUOTES, 'UTF-8') ?></th>
                            <th class="whitespace-nowrap px-4 py-3 text-left text-sm font-medium text-slate-700"><?= htmlspecialchars($t('common.labels.department'), ENT_QUOTES, 'UTF-8') ?></th>
                            <th class="whitespace-nowrap px-4 py-3 text-left text-sm font-medium text-slate-700"><?= htmlspecialchars($t('common.labels.position'), ENT_QUOTES, 'UTF-8') ?></th>
                            <th class="whitespace-nowrap px-4 py-3 text-left text-sm font-medium text-slate-700"><?= htmlspecialchars($t('analytics_module.attendance_percent'), ENT_QUOTES, 'UTF-8') ?></th>
                            <th class="whitespace-nowrap px-4 py-3 text-left text-sm font-medium text-slate-700"><?= htmlspecialchars($t('analytics_module.present'), ENT_QUOTES, 'UTF-8') ?></th>
                            <th class="whitespace-nowrap px-4 py-3 text-left text-sm font-medium text-slate-700"><?= htmlspecialchars($t('analytics_module.absent'), ENT_QUOTES, 'UTF-8') ?></th>
                            <th class="whitespace-nowrap px-4 py-3 text-left text-sm font-medium text-slate-700"><?= htmlspecialchars($t('common.labels.approved_leave'), ENT_QUOTES, 'UTF-8') ?></th>
                            <th class="whitespace-nowrap px-4 py-3 text-left text-sm font-medium text-slate-700"><?= htmlspecialchars($t('analytics_module.late'), ENT_QUOTES, 'UTF-8') ?></th>
                            <th class="whitespace-nowrap px-4 py-3 text-left text-sm font-medium text-slate-700"><?= htmlspecialchars($t('analytics_module.hours'), ENT_QUOTES, 'UTF-8') ?></th>
                            <th class="whitespace-nowrap px-4 py-3 text-left text-sm font-medium text-slate-700"><?= htmlspecialchars($t('common.labels.overtime'), ENT_QUOTES, 'UTF-8') ?></th>
                            <th class="whitespace-nowrap px-4 py-3 text-left text-sm font-medium text-slate-700"><?= htmlspecialchars($t('common.actions.view'), ENT_QUOTES, 'UTF-8') ?></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200">
                        <?php foreach ($employeeAnalytics as $index => $analytics): ?>
                            <?php
                            $detailsUrl = '/attendance?' . http_build_query([
                                'employee_id' => (int) $analytics['id'],
                                'from' => $firstDayOfMonth,
                                'to' => $lastDayOfMonth,
                            ]);
                            ?>
                            <tr>
                                <td class="px-4 py-4 text-sm text-slate-800"><span class="sapas-row-number"><?= $rowNumberStart + (int) $index ?></span></td>
                                <?php
                                $employeeName = trim((string) ($analytics['name'] ?? ''));
                                $staffCode = trim((string) ($analytics['staff_code'] ?? ''));
                                $employeeIdentity = $staffCode !== '' ? $employeeName . ' (' . $staffCode . ')' : $employeeName;
                                ?>
                                <td class="px-4 py-4 text-sm text-slate-800">
                                    <div class="sapas-person-cell">
                                        <div class="sapas-person-cell-head flex items-center gap-2">
                                            <p class="sapas-person-name font-medium text-slate-900" title="<?= htmlspecialchars($employeeIdentity, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($employeeIdentity, ENT_QUOTES, 'UTF-8') ?></p>
                                        </div>
                                        <p class="sapas-person-email" title="<?= htmlspecialchars($analytics['email'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($analytics['email'], ENT_QUOTES, 'UTF-8') ?></p>
                                    </div>
                                </td>
                                <?php $departmentLabel = analyticsFallbackLabel($analytics['department_name'] ?? null, 'analytics_module.no_department'); ?>
                                <?php $positionLabel = analyticsFallbackLabel($analytics['position_name'] ?? null, 'analytics_module.no_position'); ?>
                                <td class="px-4 py-4 text-sm text-slate-600 whitespace-nowrap sapas-table-text-cell" title="<?= htmlspecialchars($departmentLabel, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($departmentLabel, ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="px-4 py-4 text-sm text-slate-600 whitespace-nowrap sapas-table-text-cell" title="<?= htmlspecialchars($positionLabel, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($positionLabel, ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="px-4 py-4 text-sm whitespace-nowrap">
                                    <span class="<?= analyticsAttendanceClasses((float) $analytics['attendance_percentage']) ?>">
                                        <?= number_format((float) $analytics['attendance_percentage'], 1) ?>%
                                    </span>
                                </td>
                                <td class="px-4 py-4 text-sm text-slate-600 sapas-table-number"><?= (int) $analytics['present_days'] ?></td>
                                <td class="px-4 py-4 text-sm text-slate-600 sapas-table-number"><?= (int) $analytics['absent_days'] ?></td>
                                <td class="px-4 py-4 text-sm text-slate-600 sapas-table-number"><?= (int) ($analytics['approved_leave_days'] ?? 0) ?></td>
                                <td class="px-4 py-4 text-sm text-slate-600 sapas-table-number"><?= (int) $analytics['late_days'] ?></td>
                                <td class="px-4 py-4 text-sm text-slate-600 sapas-table-number"><?= number_format((float) $analytics['working_hours'], 1) ?></td>
                                <td class="px-4 py-4 text-sm text-slate-600 sapas-table-number whitespace-nowrap"><?= htmlspecialchars(analyticsFormatMinutes($analytics['overtime_minutes'] ?? 0), ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="px-4 py-4 text-sm font-medium sapas-table-action">
                                    <a
                                        href="<?= htmlspecialchars($detailsUrl, ENT_QUOTES, 'UTF-8') ?>"
                                        class="sapas-btn sapas-btn-primary"
                                        title="<?= htmlspecialchars($t('analytics_module.open_attendance_for_period'), ENT_QUOTES, 'UTF-8') ?>"
                                        aria-label="<?= htmlspecialchars($t('analytics_module.view_details') . ': ' . $analytics['name'], ENT_QUOTES, 'UTF-8') ?>"
                                    ><?= htmlspecialchars($t('analytics_module.view_details'), ENT_QUOTES, 'UTF-8') ?></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>

<script>
(() => {
    const chartGrid = document.querySelector('.sapas-analytics-office-grid');
    if (!chartGrid || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        return;
    }

    chartGrid.classList.add('is-animation-ready');

    const revealCharts = () => {
        chartGrid.classList.add('is-visible');
    };

    if (!('IntersectionObserver' in window)) {
        window.requestAnimationFrame(revealCharts);
        return;
    }

    const observer = new IntersectionObserver((entries) => {
        if (!entries.some((entry) => entry.isIntersecting)) {
            return;
        }

        revealCharts();
        observer.disconnect();
    }, {
        threshold: 0.18,
        rootMargin: '0px 0px -8% 0px',
    });

    observer.observe(chartGrid);
})();
</script>
