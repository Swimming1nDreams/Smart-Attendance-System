<?php
$t = $t ?? static fn (string $key): string => $key;
$layout = 'layouts/app';
$title = $t('dashboard.employee.title');
$pageIcon = 'dashboard';

if (!isset($hasApprovedLeaveToday)) {
    $hasApprovedLeaveToday = false;
}
if (!isset($clockInBlockReason)) {
    $clockInBlockReason = null;
}

$resolvedTodayStatus = (string) ($todayStatus ?? 'present');
$specialDayClockInBlocked = in_array($clockInBlockReason, ['holiday_blocked', 'non_working_day_blocked'], true);

$employeeName = (string) ($name ?? $t('roles.employee'));
$departmentName = (string) ($employee['department_name'] ?? $t('dashboard.common.no_department'));
$positionName = (string) ($employee['position_name'] ?? '');
$pageDescription = $t('dashboard.employee.description_prefix') . ', ' . $employeeName . ' - ' . $departmentName;

if ($positionName !== '') {
    $pageDescription .= ' / ' . $positionName;
}

$statusLabel = static function (string $status) use ($t): string {
    return $t('statuses.attendance.' . $status);
};

$statusClass = static function (string $status): string {
    return match ($status) {
        'present' => 'sapas-badge sapas-badge-success',
        'late' => 'sapas-badge sapas-badge-warning',
        'absent' => 'sapas-badge sapas-badge-danger',
        'holiday' => 'sapas-badge sapas-badge-info',
        'weekend' => 'sapas-badge sapas-badge-neutral',
        default => 'sapas-badge sapas-badge-neutral',
    };
};

$renderIcon = static function (string $iconName, string $iconClass = 'sapas-icon'): void {
    require __DIR__ . '/../partials/icon.php';
};

$formatOvertimeMinutes = static function (mixed $minutes): string {
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
};

$summaryCards = [
    [
        'label' => $t('dashboard.employee.attendance_rate'),
        'value' => number_format((float) $attendancePercentage, 1) . '%',
        'color' => 'text-emerald-600',
        'icon' => 'analytics',
        'variant' => 'is-primary',
    ],
    [
        'label' => $t('dashboard.employee.present_days'),
        'value' => (int) $presentDays,
        'color' => 'text-sky-600',
        'icon' => 'check',
        'variant' => 'is-secondary',
    ],
    [
        'label' => $t('dashboard.employee.late_arrivals'),
        'value' => (int) $lateDays,
        'color' => 'text-amber-600',
        'icon' => 'timer',
        'variant' => 'is-attention',
    ],
    [
        'label' => $t('dashboard.employee.absent_days'),
        'value' => (int) $absentDays,
        'color' => 'text-rose-600',
        'icon' => 'warning',
        'variant' => 'is-attention',
    ],
    [
        'label' => $t('common.labels.overtime'),
        'value' => $formatOvertimeMinutes($totalOvertimeMinutes ?? 0),
        'color' => 'text-amber-600',
        'icon' => 'timer',
        'variant' => 'is-attention',
    ],
    [
        'label' => $t('dashboard.employee.total_hours'),
        'value' => number_format((float) $totalWorkingHours, 1),
        'color' => 'text-sky-600',
        'icon' => 'clock',
        'variant' => 'is-secondary',
    ],
];
?>
<div class="sapas-dashboard-stack">
    <section class="sapas-dashboard-hero sapas-dashboard-hero-personal">
        <div class="sapas-dashboard-hero-content">
            <div class="sapas-dashboard-personal-grid">
                <div class="space-y-5 lg:col-span-3">
                    <div>
                        <p class="sapas-dashboard-eyebrow"><?= htmlspecialchars($t('dashboard.common.today'), ENT_QUOTES, 'UTF-8') ?></p>
                        <p class="sapas-dashboard-date"><?= htmlspecialchars((string) $today, ENT_QUOTES, 'UTF-8') ?></p>
                        <p class="mt-3 text-sm leading-6 text-slate-600"><?= htmlspecialchars($t('dashboard.employee.status_description'), ENT_QUOTES, 'UTF-8') ?></p>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="sapas-dashboard-mini-card">
                            <div class="sapas-card-heading">
                                <span class="sapas-card-icon"><?= $renderIcon('leave', 'sapas-icon') ?></span>
                                <p class="sapas-dashboard-card-title"><?= htmlspecialchars($t('dashboard.employee.leave_context'), ENT_QUOTES, 'UTF-8') ?></p>
                            </div>
                            <p class="mt-2 text-sm leading-6 <?= $hasApprovedLeaveToday ? 'text-sky-700' : 'text-slate-600' ?>">
                                <?= htmlspecialchars($hasApprovedLeaveToday ? $t('dashboard.employee.leave_context_active') : $t('dashboard.employee.leave_context_clear'), ENT_QUOTES, 'UTF-8') ?>
                            </p>
                        </div>

                        <div class="sapas-dashboard-mini-card">
                            <div class="sapas-card-heading">
                                <span class="sapas-card-icon"><?= $renderIcon('analytics', 'sapas-icon') ?></span>
                                <p class="sapas-dashboard-card-title"><?= htmlspecialchars($t('dashboard.employee.month_summary'), ENT_QUOTES, 'UTF-8') ?></p>
                            </div>
                            <p class="mt-2 text-2xl font-semibold leading-tight text-emerald-600"><?= htmlspecialchars(number_format((float) $attendancePercentage, 1) . '%', ENT_QUOTES, 'UTF-8') ?></p>
                            <p class="mt-1 text-sm leading-6 text-slate-500"><?= htmlspecialchars($t('dashboard.employee.attendance_rate'), ENT_QUOTES, 'UTF-8') ?></p>
                        </div>
                    </div>
                </div>

                <div class="sapas-dashboard-status-panel lg:col-span-2">
                    <div class="sapas-card-heading">
                        <span class="sapas-card-icon is-brand"><?= $renderIcon('clock', 'sapas-icon') ?></span>
                        <p class="sapas-dashboard-eyebrow text-brand-700"><?= htmlspecialchars($t('dashboard.employee.status_title'), ENT_QUOTES, 'UTF-8') ?></p>
                    </div>

                    <?php if ($todayRecord === null): ?>
                        <p class="mt-3 text-xl font-semibold leading-8 text-slate-950">
                            <?= htmlspecialchars($hasApprovedLeaveToday ? $t('dashboard.employee.approved_leave_today') : $t('dashboard.employee.no_record_today'), ENT_QUOTES, 'UTF-8') ?>
                        </p>
                    <?php else: ?>
                        <?php $recordStatus = (string) ($todayRecord['attendance_display_status'] ?? $todayRecord['status'] ?? ''); ?>
                        <div class="sapas-dashboard-status-grid">
                            <div>
                                <p class="text-sm font-medium text-slate-600"><?= htmlspecialchars($t('common.labels.status'), ENT_QUOTES, 'UTF-8') ?></p>
                                <p class="mt-2"><span class="<?= $statusClass($recordStatus) ?>"><?= htmlspecialchars($statusLabel($recordStatus), ENT_QUOTES, 'UTF-8') ?></span></p>
                            </div>
                            <div>
                                <p class="text-sm font-medium text-slate-600"><?= htmlspecialchars($t('dashboard.common.working_hours'), ENT_QUOTES, 'UTF-8') ?></p>
                                <p class="mt-1 text-lg font-semibold text-slate-950"><?= htmlspecialchars((string) ($todayRecord['working_duration'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></p>
                            </div>
                            <div>
                                <p class="text-sm font-medium text-slate-600"><?= htmlspecialchars($t('common.labels.overtime'), ENT_QUOTES, 'UTF-8') ?></p>
                                <p class="mt-1 text-lg font-semibold text-slate-950"><?= htmlspecialchars((string) ($todayRecord['overtime_duration'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></p>
                            </div>
                            <div>
                                <p class="text-sm font-medium text-slate-600"><?= htmlspecialchars($t('dashboard.common.clock_in_time'), ENT_QUOTES, 'UTF-8') ?></p>
                                <p class="mt-1 text-lg font-semibold text-slate-950"><?= !empty($todayRecord['clock_in_time']) ? htmlspecialchars((string) $todayRecord['clock_in_time'], ENT_QUOTES, 'UTF-8') : '—' ?></p>
                            </div>
                            <div>
                                <p class="text-sm font-medium text-slate-600"><?= htmlspecialchars($t('dashboard.common.clock_out_time'), ENT_QUOTES, 'UTF-8') ?></p>
                                <p class="mt-1 text-lg font-semibold text-slate-950"><?= !empty($todayRecord['clock_out_time']) ? htmlspecialchars((string) $todayRecord['clock_out_time'], ENT_QUOTES, 'UTF-8') : '—' ?></p>
                            </div>
                        </div>

                        <?php if ((int) ($todayRecord['is_missing_check_out'] ?? 0) === 1 || $recordStatus === 'incomplete'): ?>
                            <div class="mt-4 sapas-dashboard-note is-warning">
                                <p class="text-sm leading-6 text-amber-800"><?= htmlspecialchars($t('attendance.missing_check_out'), ENT_QUOTES, 'UTF-8') ?></p>
                            </div>
                        <?php endif; ?>
                        <?php if ((int) ($todayRecord['late_minutes'] ?? 0) > 0): ?>
                            <div class="rounded-lg bg-amber-50 p-4 sapas-dashboard-card-hover mt-4 border border-amber-200">
                                <p class="text-sm leading-6 text-amber-800"><?= htmlspecialchars($t('dashboard.employee.late_by'), ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($todayRecord['late_duration'] ?? '0 min', ENT_QUOTES, 'UTF-8') ?></p>
                            </div>
                        <?php endif; ?>
                        <?php if ((int) ($todayRecord['overtime_minutes'] ?? 0) > 0): ?>
                            <div class="mt-4 sapas-dashboard-note is-info">
                                <p class="text-sm leading-6 text-sky-700"><?= htmlspecialchars($t('dashboard.employee.overtime_today'), ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($formatOvertimeMinutes($todayRecord['overtime_minutes'] ?? 0), ENT_QUOTES, 'UTF-8') ?></p>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if (!$hasApprovedLeaveToday && in_array($resolvedTodayStatus, ['holiday', 'weekend'], true) && !$specialDayClockInBlocked): ?>
                        <div class="mt-4 sapas-dashboard-note is-info">
                            <p class="text-sm leading-6 text-sky-700"><?= htmlspecialchars($t('dashboard.employee.special_day_work_notice'), ENT_QUOTES, 'UTF-8') ?></p>
                        </div>
                    <?php endif; ?>

                    <?php if ($specialDayClockInBlocked): ?>
                        <div class="mt-4 sapas-dashboard-note is-warning">
                            <p class="text-sm leading-6 text-amber-800"><?= htmlspecialchars($t($clockInBlockReason === 'holiday_blocked' ? 'dashboard.employee.clock_blocked_holiday' : 'dashboard.employee.clock_blocked_non_working_day'), ENT_QUOTES, 'UTF-8') ?></p>
                        </div>
                    <?php endif; ?>

                    <?php if ($hasApprovedLeaveToday): ?>
                        <div class="mt-4 sapas-dashboard-note is-info">
                            <p class="text-sm leading-6 text-sky-700"><?= htmlspecialchars($t('dashboard.employee.clock_blocked_leave'), ENT_QUOTES, 'UTF-8') ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>

    <section class="sapas-dashboard-primary-action sapas-dashboard-action-panel">
        <div class="sapas-dashboard-section-header">
            <div>
                <h2 class="sapas-dashboard-section-title"><?= htmlspecialchars($t('dashboard.employee.primary_action'), ENT_QUOTES, 'UTF-8') ?></h2>
                <p class="sapas-dashboard-section-copy"><?= htmlspecialchars($t('dashboard.employee.status_description'), ENT_QUOTES, 'UTF-8') ?></p>
            </div>
            <div class="sapas-dashboard-action-row">
                <?php if ($canClockIn): ?>
                    <form method="post" action="/attendance/clock-in">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token ?? '', ENT_QUOTES, 'UTF-8') ?>">
                        <button type="submit" class="sapas-btn sapas-btn-success">
                            <?= $renderIcon('check', 'sapas-action-icon') ?>
                            <?= htmlspecialchars($t('dashboard.common.clock_in'), ENT_QUOTES, 'UTF-8') ?>
                        </button>
                    </form>
                <?php endif; ?>
                <?php if ($canClockOut): ?>
                    <form method="post" action="/attendance/clock-out">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token ?? '', ENT_QUOTES, 'UTF-8') ?>">
                        <button type="submit" class="sapas-btn sapas-btn-danger">
                            <?= $renderIcon('log-out', 'sapas-action-icon') ?>
                            <?= htmlspecialchars($t('dashboard.common.clock_out'), ENT_QUOTES, 'UTF-8') ?>
                        </button>
                    </form>
                <?php endif; ?>
                <a href="/attendance" class="sapas-btn sapas-btn-secondary">
                    <?= $renderIcon('clock', 'sapas-action-icon') ?>
                    <?= htmlspecialchars($t('dashboard.employee.view_full_history'), ENT_QUOTES, 'UTF-8') ?>
                </a>
            </div>
        </div>

        <?php if (!$canClockIn && !$canClockOut): ?>
            <?php
            $noActionKey = match ($clockInBlockReason) {
                'approved_leave_conflict' => 'dashboard.employee.no_clock_actions_leave',
                'holiday_blocked' => 'dashboard.employee.no_clock_actions_holiday',
                'non_working_day_blocked' => 'dashboard.employee.no_clock_actions_non_working_day',
                'employee_profile_missing' => 'dashboard.employee.no_clock_actions_profile',
                default => 'attendance.no_actions_available',
            };
            ?>
            <p class="mt-4 text-sm leading-6 text-slate-500"><?= htmlspecialchars($t($noActionKey), ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
    </section>

    <section class="sapas-dashboard-section sapas-dashboard-section-summary">
        <div class="sapas-dashboard-section-header">
            <div>
                <h2 class="sapas-dashboard-section-title"><?= htmlspecialchars($t('dashboard.employee.month_summary'), ENT_QUOTES, 'UTF-8') ?></h2>
                <p class="sapas-dashboard-section-copy"><?= htmlspecialchars($t('dashboard.common.this_month'), ENT_QUOTES, 'UTF-8') ?></p>
            </div>
        </div>

        <div class="sapas-dashboard-kpi-grid sapas-dashboard-kpi-grid-personal mt-5">
            <?php foreach ($summaryCards as $card): ?>
                <div class="sapas-dashboard-kpi-card <?= htmlspecialchars((string) $card['variant'], ENT_QUOTES, 'UTF-8') ?>">
                    <div class="sapas-dashboard-kpi-heading">
                        <span class="sapas-kpi-icon"><?= $renderIcon((string) $card['icon'], 'sapas-icon') ?></span>
                        <p class="sapas-dashboard-kpi-label"><?= htmlspecialchars((string) $card['label'], ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                    <p class="sapas-dashboard-kpi-number <?= htmlspecialchars((string) $card['color'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) $card['value'], ENT_QUOTES, 'UTF-8') ?></p>
                    <p class="sapas-dashboard-kpi-note"><?= htmlspecialchars($t('dashboard.common.this_month'), ENT_QUOTES, 'UTF-8') ?></p>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="sapas-dashboard-section sapas-dashboard-section-activity">
        <div class="sapas-dashboard-section-header">
            <div>
                <h2 class="sapas-dashboard-section-title"><?= htmlspecialchars($t('dashboard.employee.recent_attendance'), ENT_QUOTES, 'UTF-8') ?></h2>
                <p class="sapas-dashboard-section-copy"><?= htmlspecialchars($t('dashboard.employee.recent_attendance_description'), ENT_QUOTES, 'UTF-8') ?></p>
            </div>
            <a href="/attendance" class="sapas-btn sapas-btn-primary">
                <?= $renderIcon('clock', 'sapas-action-icon') ?>
                <?= htmlspecialchars($t('dashboard.employee.view_full_history'), ENT_QUOTES, 'UTF-8') ?>
            </a>
        </div>

        <?php if (empty($recentRecords)): ?>
            <div class="sapas-dashboard-table-empty">
                <div class="sapas-empty-icon mx-auto"><?= $renderIcon('inbox', 'sapas-icon') ?></div>
                <p><?= htmlspecialchars($t('dashboard.employee.no_recent_attendance'), ENT_QUOTES, 'UTF-8') ?></p>
            </div>
        <?php else: ?>
            <div class="sapas-table-wrap mt-5">
                <table class="min-w-full divide-y divide-slate-200 sapas-data-table">
                    <thead>
                        <tr>
                            <th class="sapas-col-row-number px-4 py-3"><?= htmlspecialchars($t('common.labels.no'), ENT_QUOTES, 'UTF-8') ?></th>
                            <th class="px-4 py-3 text-left"><?= htmlspecialchars($t('common.labels.date'), ENT_QUOTES, 'UTF-8') ?></th>
                            <th class="px-4 py-3 text-left"><?= htmlspecialchars($t('common.labels.status'), ENT_QUOTES, 'UTF-8') ?></th>
                            <th class="px-4 py-3 text-left"><?= htmlspecialchars($t('dashboard.common.clock_in_time'), ENT_QUOTES, 'UTF-8') ?></th>
                            <th class="px-4 py-3 text-left"><?= htmlspecialchars($t('dashboard.common.clock_out_time'), ENT_QUOTES, 'UTF-8') ?></th>
                            <th class="px-4 py-3 text-left"><?= htmlspecialchars($t('dashboard.common.hours'), ENT_QUOTES, 'UTF-8') ?></th>
                            <th class="px-4 py-3 text-left"><?= htmlspecialchars($t('common.labels.overtime'), ENT_QUOTES, 'UTF-8') ?></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200">
                        <?php foreach ($recentRecords as $index => $record): ?>
                            <?php $recordStatus = (string) ($record['attendance_display_status'] ?? $record['status'] ?? ''); ?>
                            <tr>
                                <td class="px-4 py-4 text-sm text-slate-600"><span class="sapas-row-number"><?= (int) $index + 1 ?></span></td>
                                <td class="px-4 py-4 text-sm font-medium text-slate-950"><?= htmlspecialchars((string) $record['attendance_date'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="px-4 py-4 text-sm">
                                    <span class="<?= $statusClass($recordStatus) ?>">
                                        <?= htmlspecialchars($statusLabel($recordStatus), ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                </td>
                                <td class="px-4 py-4 text-sm text-slate-600"><?= !empty($record['clock_in_time']) ? htmlspecialchars((string) $record['clock_in_time'], ENT_QUOTES, 'UTF-8') : '—' ?></td>
                                <td class="px-4 py-4 text-sm text-slate-600"><?= !empty($record['clock_out_time']) ? htmlspecialchars((string) $record['clock_out_time'], ENT_QUOTES, 'UTF-8') : '—' ?></td>
                                <td class="px-4 py-4 text-sm text-slate-600"><?= htmlspecialchars((string) ($record['working_duration'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="px-4 py-4 text-sm text-slate-600"><?= htmlspecialchars((string) ($record['overtime_duration'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>
