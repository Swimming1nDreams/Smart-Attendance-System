<?php
$t = $t ?? static fn (string $key): string => $key;
$layout = 'layouts/app';
$title = $t('dashboard.admin.title');
$pageIcon = 'dashboard';

$today = $today ?? date('Y-m-d');
$pageDescription = $t('dashboard.admin.description') . ' - ' . $today;
$todaySummary = $todaySummary ?? [
    'clocked_in_count' => 0,
    'late_count' => 0,
    'absent_count' => 0,
    'missing_clock_out_count' => 0,
];
$attendanceTrend = $attendanceTrend ?? [];
$departmentAttendance = $departmentAttendance ?? [];
$departmentAttendancePreview = array_slice($departmentAttendance, 0, 3);
$recentLeaveRequests = $recentLeaveRequests ?? [];
$dashboardNotifications = $dashboardNotifications ?? [];

$statusLabel = static function (string $status) use ($t): string {
    return $t('statuses.attendance.' . $status);
};

$leaveStatusLabel = static function (string $status) use ($t): string {
    return $t('statuses.leave.' . $status);
};

$leaveTypeLabel = static function (string $leaveType) use ($t): string {
    return $t('leave.types.' . $leaveType);
};

$notificationText = static function (string $key): string {
    return \App\Core\Localization::current()->translate($key);
};

$renderIcon = static function (string $iconName, string $iconClass = 'sapas-icon'): void {
    require __DIR__ . '/../partials/icon.php';
};

$badgeClass = static function (string $badgeType, string $badgeStatus): string {
    ob_start();
    require __DIR__ . '/../partials/status_badge.php';
    return trim((string) ob_get_clean());
};

$chartMax = 1;
foreach ($attendanceTrend as $trendRow) {
    $chartMax = max(
        $chartMax,
        (int) ($trendRow['clocked_in_count'] ?? 0),
        (int) ($trendRow['late_count'] ?? 0),
        (int) ($trendRow['absent_count'] ?? 0)
    );
}

$chartWidth = 640;
$chartHeight = 250;
$chartPadding = 30;
$chartInnerWidth = $chartWidth - ($chartPadding * 2);
$chartInnerHeight = $chartHeight - ($chartPadding * 2);

$trendPoints = static function (array $rows, string $key) use ($chartMax, $chartPadding, $chartInnerWidth, $chartInnerHeight): string {
    $count = count($rows);

    if ($count === 0) {
        return '';
    }

    $points = [];
    foreach (array_values($rows) as $index => $row) {
        $x = $count === 1
            ? $chartPadding + ($chartInnerWidth / 2)
            : $chartPadding + (($chartInnerWidth / max(1, $count - 1)) * $index);
        $value = (int) ($row[$key] ?? 0);
        $y = $chartPadding + ($chartInnerHeight - (($value / max(1, $chartMax)) * $chartInnerHeight));
        $points[] = number_format($x, 2, '.', '') . ',' . number_format($y, 2, '.', '');
    }

    return implode(' ', $points);
};

$kpis = [
    [
        'label' => $t('dashboard.admin.kpi_active_employees'),
        'value' => (int) ($activeEmployees ?? 0),
        'note' => $t('dashboard.admin.kpi_active_employees_note'),
        'color' => 'text-sky-600',
        'icon' => 'users',
        'variant' => 'is-primary',
    ],
    [
        'label' => $t('dashboard.admin.kpi_clocked_in'),
        'value' => (int) ($todaySummary['clocked_in_count'] ?? 0),
        'note' => $t('dashboard.admin.kpi_clocked_in_note'),
        'color' => 'text-emerald-600',
        'icon' => 'check',
        'variant' => 'is-primary',
    ],
    [
        'label' => $t('dashboard.admin.kpi_late'),
        'value' => (int) ($todaySummary['late_count'] ?? 0),
        'note' => $t('dashboard.admin.kpi_late_note'),
        'color' => 'text-amber-600',
        'icon' => 'timer',
        'variant' => 'is-attention',
    ],
    [
        'label' => $t('dashboard.admin.kpi_absent'),
        'value' => (int) ($todaySummary['absent_count'] ?? 0),
        'note' => $t('dashboard.admin.kpi_absent_note'),
        'color' => 'text-rose-600',
        'icon' => 'warning',
        'variant' => 'is-attention',
    ],
    [
        'label' => $t('dashboard.admin.kpi_holidays'),
        'value' => (int) ($holidaysThisMonth ?? 0),
        'note' => $t('dashboard.admin.kpi_holidays_note'),
        'color' => 'text-sky-600',
        'icon' => 'calendar',
        'variant' => 'is-secondary',
    ],
];
?>
<div class="sapas-dashboard-stack">
    <section class="sapas-dashboard-hero sapas-dashboard-hero-command">
        <div class="sapas-dashboard-hero-content">
            <div class="sapas-dashboard-opening-grid">
                <div class="sapas-dashboard-opening-copy">
                    <p class="sapas-dashboard-eyebrow"><?= htmlspecialchars($t('dashboard.admin.command_center'), ENT_QUOTES, 'UTF-8') ?></p>
                    <h2 class="sapas-dashboard-date"><?= htmlspecialchars($t('dashboard.admin.today_operations'), ENT_QUOTES, 'UTF-8') ?></h2>
                    <p class="mt-3 text-sm leading-6 text-slate-600"><?= htmlspecialchars($t('dashboard.admin.command_center_help'), ENT_QUOTES, 'UTF-8') ?></p>
                </div>
                <div class="sapas-dashboard-command-panel">
                    <div class="sapas-dashboard-command-stat">
                        <p class="sapas-dashboard-command-label"><?= htmlspecialchars($t('dashboard.admin.date_label'), ENT_QUOTES, 'UTF-8') ?></p>
                        <p class="sapas-dashboard-command-value text-slate-950"><?= htmlspecialchars($today, ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                    <div class="sapas-dashboard-command-stat">
                        <p class="sapas-dashboard-command-label"><?= htmlspecialchars($t('dashboard.admin.open_records'), ENT_QUOTES, 'UTF-8') ?></p>
                        <p class="sapas-dashboard-command-value text-rose-600"><?= (int) ($todaySummary['missing_clock_out_count'] ?? 0) ?></p>
                    </div>
                    <div class="sapas-dashboard-command-stat">
                        <p class="sapas-dashboard-command-label"><?= htmlspecialchars($t('dashboard.admin.inactive_accounts'), ENT_QUOTES, 'UTF-8') ?></p>
                        <p class="sapas-dashboard-command-value text-slate-700"><?= (int) ($inactiveEmployees ?? 0) ?></p>
                    </div>
                </div>
                <div class="sapas-dashboard-action-row sapas-dashboard-opening-actions">
                    <a href="/attendance" class="sapas-btn sapas-btn-primary">
                        <?= $renderIcon('clock', 'sapas-action-icon') ?>
                        <?= htmlspecialchars($t('dashboard.admin.review_attendance'), ENT_QUOTES, 'UTF-8') ?>
                    </a>
                    <a href="/admin/analytics" class="sapas-btn sapas-btn-secondary">
                        <?= $renderIcon('analytics', 'sapas-action-icon') ?>
                        <?= htmlspecialchars($t('nav.analytics'), ENT_QUOTES, 'UTF-8') ?>
                    </a>
                    <a href="/admin/reports" class="sapas-btn sapas-btn-secondary">
                        <?= $renderIcon('file-text', 'sapas-action-icon') ?>
                        <?= htmlspecialchars($t('nav.reports'), ENT_QUOTES, 'UTF-8') ?>
                    </a>
                </div>
            </div>

            <div class="sapas-dashboard-kpi-grid sapas-dashboard-kpi-grid-admin">
                <?php foreach ($kpis as $kpi): ?>
                    <div class="sapas-dashboard-kpi-card <?= htmlspecialchars((string) $kpi['variant'], ENT_QUOTES, 'UTF-8') ?>">
                        <div class="sapas-dashboard-kpi-heading">
                            <span class="sapas-kpi-icon"><?= $renderIcon((string) $kpi['icon'], 'sapas-icon') ?></span>
                            <p class="sapas-dashboard-kpi-label"><?= htmlspecialchars($kpi['label'], ENT_QUOTES, 'UTF-8') ?></p>
                        </div>
                        <p class="sapas-dashboard-kpi-number <?= htmlspecialchars($kpi['color'], ENT_QUOTES, 'UTF-8') ?>"><?= (int) $kpi['value'] ?></p>
                        <p class="sapas-dashboard-kpi-note"><?= htmlspecialchars($kpi['note'], ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section class="sapas-dashboard-insight-grid">
        <div class="sapas-chart-card sapas-dashboard-chart-card">
            <div class="sapas-chart-card-header">
                <div>
                    <h2 class="sapas-chart-title"><?= htmlspecialchars($t('dashboard.admin.attendance_overview'), ENT_QUOTES, 'UTF-8') ?></h2>
                    <p class="sapas-chart-copy"><?= htmlspecialchars($t('dashboard.admin.attendance_overview_help'), ENT_QUOTES, 'UTF-8') ?></p>
                </div>
                <span class="sapas-chip sapas-chip-info"><?= htmlspecialchars($t('dashboard.admin.recent_days'), ENT_QUOTES, 'UTF-8') ?></span>
            </div>

            <?php if (empty($attendanceTrend)): ?>
                <div class="sapas-dashboard-table-empty">
                    <div class="sapas-empty-icon mx-auto"><?= $renderIcon('analytics', 'sapas-icon') ?></div>
                    <p><?= htmlspecialchars($t('dashboard.admin.no_recent_attendance'), ENT_QUOTES, 'UTF-8') ?></p>
                </div>
            <?php else: ?>
                <div class="sapas-line-chart" role="img" aria-label="<?= htmlspecialchars($t('dashboard.admin.attendance_overview'), ENT_QUOTES, 'UTF-8') ?>">
                    <svg viewBox="0 0 <?= $chartWidth ?> <?= $chartHeight ?>" preserveAspectRatio="none">
                        <line x1="<?= $chartPadding ?>" y1="<?= $chartPadding ?>" x2="<?= $chartPadding ?>" y2="<?= $chartHeight - $chartPadding ?>" class="sapas-chart-axis" />
                        <line x1="<?= $chartPadding ?>" y1="<?= $chartHeight - $chartPadding ?>" x2="<?= $chartWidth - $chartPadding ?>" y2="<?= $chartHeight - $chartPadding ?>" class="sapas-chart-axis" />
                        <?php for ($grid = 1; $grid <= 3; $grid++): ?>
                            <?php $gridY = $chartPadding + (($chartInnerHeight / 4) * $grid); ?>
                            <line x1="<?= $chartPadding ?>" y1="<?= number_format($gridY, 2, '.', '') ?>" x2="<?= $chartWidth - $chartPadding ?>" y2="<?= number_format($gridY, 2, '.', '') ?>" class="sapas-chart-gridline" />
                        <?php endfor; ?>
                        <polyline points="<?= htmlspecialchars($trendPoints($attendanceTrend, 'clocked_in_count'), ENT_QUOTES, 'UTF-8') ?>" class="sapas-line-series is-emerald" />
                        <polyline points="<?= htmlspecialchars($trendPoints($attendanceTrend, 'late_count'), ENT_QUOTES, 'UTF-8') ?>" class="sapas-line-series is-amber" />
                        <polyline points="<?= htmlspecialchars($trendPoints($attendanceTrend, 'absent_count'), ENT_QUOTES, 'UTF-8') ?>" class="sapas-line-series is-rose" />
                    </svg>
                    <div class="sapas-line-chart-labels">
                        <?php foreach ($attendanceTrend as $trendRow): ?>
                            <span><?= htmlspecialchars(substr((string) ($trendRow['attendance_date'] ?? ''), 5), ENT_QUOTES, 'UTF-8') ?></span>
                        <?php endforeach; ?>
                    </div>
                    <div class="sapas-chart-legend">
                        <span class="sapas-chart-legend-item"><span class="sapas-chart-dot bg-emerald-500"></span><?= htmlspecialchars($t('dashboard.admin.kpi_clocked_in'), ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="sapas-chart-legend-item"><span class="sapas-chart-dot bg-amber-500"></span><?= htmlspecialchars($t('dashboard.admin.kpi_late'), ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="sapas-chart-legend-item"><span class="sapas-chart-dot bg-rose-500"></span><?= htmlspecialchars($t('dashboard.admin.kpi_absent'), ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div class="sapas-dashboard-section sapas-dashboard-section-secondary sapas-dashboard-department-panel">
            <div class="sapas-dashboard-section-header">
                <div>
                    <h2 class="sapas-dashboard-section-title"><?= htmlspecialchars($t('dashboard.admin.department_attendance'), ENT_QUOTES, 'UTF-8') ?></h2>
                    <p class="sapas-dashboard-section-copy"><?= htmlspecialchars($t('dashboard.admin.department_attendance_help'), ENT_QUOTES, 'UTF-8') ?></p>
                </div>
            </div>

            <?php if (empty($departmentAttendance)): ?>
                <div class="sapas-dashboard-table-empty">
                    <div class="sapas-empty-icon mx-auto"><?= $renderIcon('building', 'sapas-icon') ?></div>
                    <p><?= htmlspecialchars($t('dashboard.admin.no_department_attendance'), ENT_QUOTES, 'UTF-8') ?></p>
                </div>
            <?php else: ?>
                <div class="sapas-dashboard-department-list">
                    <?php foreach ($departmentAttendancePreview as $department): ?>
                        <?php
                        $departmentActive = max(1, (int) ($department['active_count'] ?? 0));
                        $departmentProgress = min(100, ((int) ($department['clocked_in_count'] ?? 0) / $departmentActive) * 100);
                        ?>
                        <div class="sapas-dashboard-department-row">
                            <div class="flex min-w-0 items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="font-semibold leading-6 text-slate-950"><?= htmlspecialchars((string) ($department['department_name'] ?? $t('dashboard.common.no_department')), ENT_QUOTES, 'UTF-8') ?></p>
                                    <p class="mt-1 text-xs leading-5 text-slate-500"><?= (int) ($department['clocked_in_count'] ?? 0) ?> / <?= (int) ($department['active_count'] ?? 0) ?> <?= htmlspecialchars($t('dashboard.admin.clocked_in_ratio'), ENT_QUOTES, 'UTF-8') ?></p>
                                </div>
                                <div class="flex flex-none gap-2">
                                    <span class="sapas-badge sapas-badge-warning"><?= (int) ($department['late_count'] ?? 0) ?> <?= htmlspecialchars($t('dashboard.admin.short_late'), ENT_QUOTES, 'UTF-8') ?></span>
                                    <span class="sapas-badge sapas-badge-danger"><?= (int) ($department['absent_count'] ?? 0) ?> <?= htmlspecialchars($t('dashboard.admin.short_absent'), ENT_QUOTES, 'UTF-8') ?></span>
                                </div>
                            </div>
                            <div class="sapas-chart-track mt-3">
                                <div class="sapas-chart-bar is-emerald" style="width: <?= number_format($departmentProgress, 2, '.', '') ?>%"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="sapas-dashboard-department-footer">
                    <a href="/departments" class="sapas-btn sapas-btn-secondary"><?= htmlspecialchars($t('dashboard.admin.view_all_departments'), ENT_QUOTES, 'UTF-8') ?></a>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="sapas-dashboard-operational-grid">
        <div class="sapas-dashboard-section sapas-dashboard-section-activity">
            <div class="sapas-dashboard-section-header">
                <div>
                    <h2 class="sapas-dashboard-section-title"><?= htmlspecialchars($t('dashboard.admin.recent_leave_requests'), ENT_QUOTES, 'UTF-8') ?></h2>
                    <p class="sapas-dashboard-section-copy"><?= htmlspecialchars($t('dashboard.admin.recent_leave_requests_help'), ENT_QUOTES, 'UTF-8') ?></p>
                </div>
                <a href="/admin/leave" class="sapas-btn sapas-btn-secondary">
                    <?= $renderIcon('leave', 'sapas-action-icon') ?>
                    <?= htmlspecialchars($t('nav.leave_requests'), ENT_QUOTES, 'UTF-8') ?>
                </a>
            </div>

            <?php if (empty($recentLeaveRequests)): ?>
                <div class="sapas-dashboard-table-empty">
                    <div class="sapas-empty-icon mx-auto"><?= $renderIcon('leave', 'sapas-icon') ?></div>
                    <p><?= htmlspecialchars($t('dashboard.admin.no_recent_leave_requests'), ENT_QUOTES, 'UTF-8') ?></p>
                </div>
            <?php else: ?>
                <div class="sapas-dashboard-list">
                    <?php foreach ($recentLeaveRequests as $leaveRequest): ?>
                        <?php $leaveStatus = (string) ($leaveRequest['status'] ?? 'pending'); ?>
                        <a href="/admin/leave/show?id=<?= (int) ($leaveRequest['id'] ?? 0) ?>" class="sapas-dashboard-list-row">
                            <span class="sapas-card-icon"><?= $renderIcon('leave', 'sapas-icon') ?></span>
                            <span class="min-w-0 flex-1">
                                <span class="block font-semibold leading-6 text-slate-950"><?= htmlspecialchars((string) ($leaveRequest['employee_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                                <span class="mt-1 block text-sm leading-6 text-slate-600">
                                    <?= htmlspecialchars($leaveTypeLabel((string) ($leaveRequest['leave_type'] ?? '')), ENT_QUOTES, 'UTF-8') ?>
                                    / <?= htmlspecialchars((string) ($leaveRequest['start_date'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>
                                    <?= htmlspecialchars($t('analytics_module.date_range_separator'), ENT_QUOTES, 'UTF-8') ?>
                                    <?= htmlspecialchars((string) ($leaveRequest['end_date'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </span>
                            <span class="<?= htmlspecialchars($badgeClass('leave', $leaveStatus), ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars($leaveStatusLabel($leaveStatus), ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="sapas-dashboard-section sapas-dashboard-section-secondary">
            <div class="sapas-dashboard-section-header">
                <div>
                    <h2 class="sapas-dashboard-section-title"><?= htmlspecialchars($t('notifications.title'), ENT_QUOTES, 'UTF-8') ?></h2>
                    <p class="sapas-dashboard-section-copy"><?= htmlspecialchars($t('dashboard.admin.notifications_help'), ENT_QUOTES, 'UTF-8') ?></p>
                </div>
                <form method="post" action="/notifications/read-all">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token ?? '', ENT_QUOTES, 'UTF-8') ?>">
                    <button type="submit" class="sapas-btn sapas-btn-secondary">
                        <?= $renderIcon('bell', 'sapas-action-icon') ?>
                        <?= htmlspecialchars($t('notifications.open_related'), ENT_QUOTES, 'UTF-8') ?>
                    </button>
                </form>
            </div>

            <?php if (empty($dashboardNotifications)): ?>
                <div class="sapas-dashboard-table-empty">
                    <div class="sapas-empty-icon mx-auto"><?= $renderIcon('bell', 'sapas-icon') ?></div>
                    <p><?= htmlspecialchars($t('notifications.empty_title'), ENT_QUOTES, 'UTF-8') ?></p>
                </div>
            <?php else: ?>
                <div class="sapas-notification-list">
                    <?php foreach ($dashboardNotifications as $notification): ?>
                        <?php $isUnread = empty($notification['read_at']); ?>
                        <a href="<?= htmlspecialchars((string) (($notification['link_url'] ?? '') !== '' ? $notification['link_url'] : '/notifications'), ENT_QUOTES, 'UTF-8') ?>" class="sapas-notification-row <?= $isUnread ? 'is-unread' : '' ?>">
                            <span class="<?= $isUnread ? 'sapas-notification-icon is-unread' : 'sapas-notification-icon' ?>"><?= $renderIcon('bell', 'sapas-icon') ?></span>
                            <span class="min-w-0 flex-1">
                                <span class="block font-semibold leading-6 text-slate-950"><?= htmlspecialchars($notificationText((string) ($notification['title_key'] ?? '')), ENT_QUOTES, 'UTF-8') ?></span>
                                <span class="mt-1 block text-sm leading-6 text-slate-600"><?= htmlspecialchars($notificationText((string) ($notification['message_key'] ?? '')), ENT_QUOTES, 'UTF-8') ?></span>
                            </span>
                            <span class="sapas-meta-text"><?= htmlspecialchars((string) ($notification['available_at'] ?? ($notification['created_at'] ?? '')), ENT_QUOTES, 'UTF-8') ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="sapas-dashboard-section sapas-dashboard-section-priority">
        <div class="sapas-dashboard-section-header">
            <div>
                <h2 class="sapas-dashboard-section-title"><?= htmlspecialchars($t('dashboard.admin.attention_title'), ENT_QUOTES, 'UTF-8') ?></h2>
                <p class="sapas-dashboard-section-copy"><?= htmlspecialchars($t('dashboard.admin.attention_description'), ENT_QUOTES, 'UTF-8') ?></p>
            </div>
            <a href="/attendance" class="sapas-btn sapas-btn-secondary">
                <?= $renderIcon('clock', 'sapas-action-icon') ?>
                <?= htmlspecialchars($t('dashboard.admin.open_attendance'), ENT_QUOTES, 'UTF-8') ?>
            </a>
        </div>

        <div class="sapas-dashboard-attention-grid">
            <div class="sapas-dashboard-attention-card is-warning">
                <p class="sapas-dashboard-card-title text-amber-800"><?= htmlspecialchars($t('dashboard.admin.late_arrivals'), ENT_QUOTES, 'UTF-8') ?></p>
                <?php if (empty($lateArrivals)): ?>
                    <p class="mt-3 text-sm leading-6 text-slate-700"><?= htmlspecialchars($t('dashboard.admin.no_late_arrivals'), ENT_QUOTES, 'UTF-8') ?></p>
                <?php else: ?>
                    <div class="sapas-dashboard-list">
                        <?php foreach ($lateArrivals as $record): ?>
                            <div class="sapas-dashboard-list-item">
                                <p class="font-semibold leading-6 text-slate-950"><?= htmlspecialchars((string) $record['employee_name'], ENT_QUOTES, 'UTF-8') ?></p>
                                <p class="mt-1 text-sm text-slate-700"><?= htmlspecialchars($t('dashboard.admin.clocked_in_at'), ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars((string) ($record['clock_in_time'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></p>
                                <p class="mt-1 text-sm font-semibold text-amber-800"><?= htmlspecialchars($t('dashboard.admin.late_by'), ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($record['late_duration'] ?? '0 min', ENT_QUOTES, 'UTF-8') ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="sapas-dashboard-attention-card is-danger">
                <p class="sapas-dashboard-card-title text-rose-800"><?= htmlspecialchars($t('dashboard.admin.missing_clock_outs'), ENT_QUOTES, 'UTF-8') ?></p>
                <?php if (empty($missingClockOuts)): ?>
                    <p class="mt-3 text-sm leading-6 text-slate-700"><?= htmlspecialchars($t('dashboard.admin.no_missing_clock_outs'), ENT_QUOTES, 'UTF-8') ?></p>
                <?php else: ?>
                    <div class="sapas-dashboard-list">
                        <?php foreach ($missingClockOuts as $record): ?>
                            <div class="sapas-dashboard-list-item">
                                <p class="font-semibold leading-6 text-slate-950"><?= htmlspecialchars((string) $record['employee_name'], ENT_QUOTES, 'UTF-8') ?></p>
                                <p class="mt-1 text-sm text-slate-700"><?= htmlspecialchars($t('dashboard.admin.clocked_in_at'), ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars((string) ($record['clock_in_time'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></p>
                                <p class="mt-1 text-sm font-semibold text-rose-800"><?= htmlspecialchars($t('dashboard.admin.clock_out_pending'), ENT_QUOTES, 'UTF-8') ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </section>
</div>
