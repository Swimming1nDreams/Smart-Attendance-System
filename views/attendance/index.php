<?php
$t = $t ?? static fn (string $key): string => $key;
if (!isset($title)) {
    $title = $t('attendance.title');
}
if (!isset($isTeamViewer)) {
    $isTeamViewer = false;
}
if (!isset($canManageAttendance)) {
    $canManageAttendance = false;
}
$departmentScopeLocked = isset($departmentScopeLocked) ? (bool) $departmentScopeLocked : false;
if (!isset($records)) {
    $records = [];
}
if (!isset($search)) {
    $search = '';
}
if (!isset($selectedEmployeeId)) {
    $selectedEmployeeId = null;
}
if (!isset($fromDate)) {
    $fromDate = '';
}
if (!isset($toDate)) {
    $toDate = '';
}
if (!isset($page)) {
    $page = 1;
}
if (!isset($perPage)) {
    $perPage = 20;
}
if (!isset($total)) {
    $total = 0;
}
if (!isset($todayRecord)) {
    $todayRecord = null;
}
if (!isset($todayStatus)) {
    $todayStatus = 'present';
}
if (!isset($showClockActions)) {
    $showClockActions = false;
}
if (!isset($canClockIn)) {
    $canClockIn = false;
}
if (!isset($canClockOut)) {
    $canClockOut = false;
}
if (!isset($hasApprovedLeaveToday)) {
    $hasApprovedLeaveToday = false;
}
if (!isset($clockInBlockReason)) {
    $clockInBlockReason = null;
}
if (!isset($isHolidayOrWeekend)) {
    $isHolidayOrWeekend = false;
}
if (!isset($today)) {
    $today = date('Y-m-d');
}
$earlyCheckoutPreview ??= null;
$earlyDepartureReasonCodes ??= [];

function attendanceDateValue(string $date, string $englishFormat = 'M j, Y'): string
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if ($parsed === false) {
        return $date;
    }

    $localization = \App\Core\Localization::current();
    if ($localization->locale() !== 'my') {
        return $parsed->format($englishFormat);
    }

    $month = $localization->translate('analytics.months.' . $parsed->format('m'));
    if ($month === 'analytics.months.' . $parsed->format('m')) {
        return $parsed->format('Y-m-d');
    }

    return ltrim($parsed->format('d'), '0') . ' ' . $month . ' ' . $parsed->format('Y');
}

function attendanceWeekdayValue(string $date): string
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if ($parsed === false) {
        return '';
    }

    $localization = \App\Core\Localization::current();
    if ($localization->locale() !== 'my') {
        return $parsed->format('l');
    }

    $key = 'work_schedules.weekdays.' . $parsed->format('N');
    $weekday = $localization->translate($key);

    return $weekday === $key ? $parsed->format('Y-m-d') : $weekday;
}

$layout = 'layouts/app';
$pageDescription = $isTeamViewer
    ? $t('attendance.admin_description')
    : $t('attendance.employee_description');
$pageActions = $canManageAttendance
    ? '<a href="/attendance/create" class="sapas-btn sapas-btn-primary">' . htmlspecialchars($t('attendance.add_attendance'), ENT_QUOTES, 'UTF-8') . '</a>'
    : (!$isTeamViewer ? '<a href="/employee/dashboard" class="sapas-btn sapas-btn-secondary">' . htmlspecialchars($t('attendance.back_to_dashboard'), ENT_QUOTES, 'UTF-8') . '</a>' : '');

$pages = max(1, (int) ceil($total / $perPage));
$visibleCount = count($records);
$departmentId = isset($departmentId) && is_int($departmentId) && $departmentId > 0 ? $departmentId : null;
$departmentNames = [];
if (isset($departments) && is_array($departments)) {
    foreach ($departments as $department) {
        $departmentNames[(int) $department['id']] = (string) $department['name'];
    }
}
$employeeNames = [];
if (isset($employees) && is_array($employees)) {
    foreach ($employees as $employee) {
        $employeeNames[(int) $employee['id']] = (string) $employee['name'];
    }
}

$hasFilters = $search !== '' || $fromDate !== '' || $toDate !== '' || $selectedEmployeeId !== null || $departmentId !== null;
$rowNumberStart = ((max(1, (int) $page) - 1) * max(1, (int) $perPage)) + 1;

$activeFilters = [];
if ($departmentId !== null) {
    $activeFilters[] = $t('common.labels.department') . ': ' . ($departmentNames[$departmentId] ?? ('ID #' . $departmentId));
}
if ($selectedEmployeeId !== null) {
    $activeFilters[] = $t('common.labels.employee') . ': ' . ($employeeNames[$selectedEmployeeId] ?? ('ID #' . $selectedEmployeeId));
} elseif ($search !== '') {
    $activeFilters[] = $t('common.labels.employee') . ': ' . $search;
}
if ($fromDate !== '') {
    $activeFilters[] = $t('common.labels.date_from') . ': ' . attendanceDateValue($fromDate);
}
if ($toDate !== '') {
    $activeFilters[] = $t('common.labels.date_to') . ': ' . attendanceDateValue($toDate);
}

$paginationQuery = [];
if ($isTeamViewer && $departmentId !== null) {
    $paginationQuery['department_id'] = (string) $departmentId;
}
if ($isTeamViewer && $selectedEmployeeId !== null) {
    $paginationQuery['employee_id'] = (string) $selectedEmployeeId;
}
if ($search !== '') {
    $paginationQuery['search'] = $search;
}
if ($fromDate !== '') {
    $paginationQuery['from'] = $fromDate;
}
if ($toDate !== '') {
    $paginationQuery['to'] = $toDate;
}
$paginationQuery['per_page'] = (string) $perPage;
$paginationPageSizeOptions = [20, 50, 100];
$specialDayClockInBlocked = in_array($clockInBlockReason, ['holiday_blocked', 'non_working_day_blocked'], true);

function attendanceTemplate(string $key, array $values): string
{
    $template = \App\Core\Localization::current()->translate($key);
    $replacements = [];

    foreach ($values as $name => $value) {
        $replacements[':' . (string) $name] = (string) $value;
    }

    return strtr($template, $replacements);
}

function formatStatusLabel(string $status): string
{
    return \App\Core\Localization::current()->translate('statuses.attendance.' . $status);
}

function attendanceStatusClasses(string $status): string
{
    return match ($status) {
        'present' => 'sapas-badge sapas-badge-success',
        'late' => 'sapas-badge sapas-badge-warning',
        'absent' => 'sapas-badge sapas-badge-danger',
        'holiday' => 'sapas-badge sapas-badge-info',
        'weekend' => 'sapas-badge sapas-badge-neutral',
        'out_of_schedule' => 'sapas-badge sapas-badge-info',
        default => 'sapas-badge sapas-badge-neutral',
    };
}

function formatWorkingHoursValue(mixed $hours): string
{
    if ($hours === null || $hours === '') {
        return '—';
    }

    return number_format((float) $hours, 2);
}

function formatWorkingDurationValue(?array $record): string
{
    if ($record === null) {
        return '—';
    }

    return (string) ($record['working_duration'] ?? '—');
}

function formatOvertimeMinutesValue(mixed $minutes): string
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

function attendanceEarlyReasonLabel(mixed $reason, callable $t): string
{
    $reason = trim((string) $reason);
    return $reason === '' ? '—' : $t('attendance.early_departure_reasons.' . $reason);
}

function attendanceScheduleTime(mixed $value): string
{
    $value = trim((string) $value);
    return $value === '' ? '—' : (new DateTimeImmutable($value))->format('H:i');
}

function previewRemarks(?string $remarks, int $limit = 48): string
{
    if ($remarks === null) {
        return '—';
    }

    $trimmed = trim($remarks);
    if ($trimmed === '') {
        return '—';
    }

    return mb_strlen($trimmed, 'UTF-8') > $limit
        ? mb_substr($trimmed, 0, $limit - 1, 'UTF-8') . '…'
        : $trimmed;
}
?>
<?php if (!empty($errors)): ?>
    <div class="mb-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
        <?php foreach ($errors as $error): ?>
            <p><?= htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php if (!$isTeamViewer && $showClockActions): ?>
    <div class="grid gap-4 md:grid-cols-2">
        <div class="sapas-workspace-card">
            <div class="flex items-center justify-between gap-4">
                <div>
                    <p class="text-sm font-medium text-slate-500"><?= htmlspecialchars($t('dashboard.common.today'), ENT_QUOTES, 'UTF-8') ?></p>
                    <p class="mt-2 text-xl font-semibold text-slate-900"><?= htmlspecialchars(attendanceDateValue((string) $today, 'F j, Y'), ENT_QUOTES, 'UTF-8') ?></p>
                    <?php if ($todayRecord !== null): ?>
                        <?php $todayDisplayStatus = (string) ($todayRecord['attendance_display_status'] ?? $todayRecord['status'] ?? $todayStatus); ?>
                        <div class="mt-2">
                            <span class="<?= attendanceStatusClasses($todayDisplayStatus) ?>"><?= htmlspecialchars(formatStatusLabel($todayDisplayStatus), ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                    <?php endif; ?>
                </div>
                <?php if ($todayRecord !== null && $todayRecord['clock_in_time'] !== null): ?>
                    <div class="sapas-chip sapas-chip-info"><?= htmlspecialchars($t('attendance.clocked_in'), ENT_QUOTES, 'UTF-8') ?></div>
                <?php else: ?>
                    <div class="sapas-badge bg-amber-50 text-amber-700"><?= htmlspecialchars($t('attendance.waiting'), ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
            </div>

            <div class="mt-4 sapas-operation-detail-grid">
                <div>
                    <p class="text-sm font-medium text-slate-500"><?= htmlspecialchars($t('dashboard.common.clock_in_time'), ENT_QUOTES, 'UTF-8') ?></p>
                    <p class="mt-1 text-lg font-semibold text-slate-900"><?= htmlspecialchars($todayRecord['clock_in_time'] ?? '—', ENT_QUOTES, 'UTF-8') ?></p>
                </div>
                <div>
                    <p class="text-sm font-medium text-slate-500"><?= htmlspecialchars($t('dashboard.common.clock_out_time'), ENT_QUOTES, 'UTF-8') ?></p>
                    <p class="mt-1 text-lg font-semibold text-slate-900"><?= htmlspecialchars($todayRecord['clock_out_time'] ?? '—', ENT_QUOTES, 'UTF-8') ?></p>
                </div>
                <div>
                    <p class="text-sm font-medium text-slate-500"><?= htmlspecialchars($t('dashboard.common.working_hours'), ENT_QUOTES, 'UTF-8') ?></p>
                    <p class="mt-1 text-lg font-semibold text-slate-900"><?= htmlspecialchars(formatWorkingDurationValue($todayRecord), ENT_QUOTES, 'UTF-8') ?></p>
                </div>
                <div>
                    <p class="text-sm font-medium text-slate-500"><?= htmlspecialchars($t('attendance.late_minutes'), ENT_QUOTES, 'UTF-8') ?></p>
                    <p class="mt-1 text-lg font-semibold text-slate-900"><?= htmlspecialchars($todayRecord['late_duration'] ?? '0 min', ENT_QUOTES, 'UTF-8') ?></p>
                </div>
                <div>
                    <?php $todayOvertimePending = (string) ($todayRecord['overtime_approval_status'] ?? '') === 'pending'; ?>
                    <p class="text-sm font-medium text-slate-500"><?= htmlspecialchars($t($todayOvertimePending ? 'attendance.overtime_candidate_pending' : 'attendance.approved_overtime'), ENT_QUOTES, 'UTF-8') ?></p>
                    <p class="mt-1 text-lg font-semibold text-slate-900"><?= htmlspecialchars((string) ($todayOvertimePending ? ($todayRecord['calculated_overtime_duration'] ?? '—') : ($todayRecord['approved_overtime_duration'] ?? $todayRecord['overtime_duration'] ?? '—')), ENT_QUOTES, 'UTF-8') ?></p>
                </div>
                <?php if (!empty($todayRecord['has_actual_work_session']) && (int) ($todayRecord['out_of_schedule_work_minutes'] ?? 0) > 0): ?>
                    <div>
                        <p class="text-sm font-medium text-slate-500"><?= htmlspecialchars($t('attendance.out_of_schedule_work'), ENT_QUOTES, 'UTF-8') ?></p>
                        <p class="mt-1 text-lg font-semibold text-slate-900"><?= htmlspecialchars((string) ($todayRecord['out_of_schedule_work_duration'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></p>
                        <?php if (($todayRecord['scheduled_attendance_status'] ?? '') === 'pending'): ?><p class="mt-1 text-xs text-slate-600"><?= htmlspecialchars($t('attendance.scheduled_shift_pending'), ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
                    </div>
                <?php endif; ?>
                <?php if ((int) ($todayRecord['is_early_departure'] ?? 0) === 1): ?>
                    <div>
                        <p class="text-sm font-medium text-slate-500"><?= htmlspecialchars($t('attendance.early_departure'), ENT_QUOTES, 'UTF-8') ?></p>
                        <p class="mt-1 text-lg font-semibold text-amber-800"><?= htmlspecialchars((string) ($todayRecord['early_departure_duration'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></p>
                        <p class="mt-1 text-xs text-slate-600"><?= htmlspecialchars(attendanceEarlyReasonLabel($todayRecord['early_departure_reason'] ?? null, $t), ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($isHolidayOrWeekend && !$specialDayClockInBlocked): ?>
                <div class="mt-4 sapas-alert-warning">
                    <?= htmlspecialchars($t('attendance.special_day_notice'), ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>
            <?php if ($specialDayClockInBlocked): ?>
                <div class="mt-4 sapas-alert-warning">
                    <?= htmlspecialchars($t($clockInBlockReason === 'holiday_blocked' ? 'attendance.clock_blocked_holiday' : 'attendance.clock_blocked_non_working_day'), ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>
            <?php if ($hasApprovedLeaveToday): ?>
                <div class="mt-4 sapas-alert-info">
                    <?= htmlspecialchars($t('attendance.approved_leave_notice'), ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="sapas-workspace-card">
            <div class="flex items-center justify-between gap-4">
                <div>
                    <p class="text-sm font-medium text-slate-500"><?= htmlspecialchars($t('attendance.today_actions'), ENT_QUOTES, 'UTF-8') ?></p>
                    <p class="mt-1 text-sm text-slate-600"><?= htmlspecialchars($t('attendance.actions_auto_update'), ENT_QUOTES, 'UTF-8') ?></p>
                </div>
            </div>
            <div class="mt-4 flex flex-col gap-3">
                <form method="post" action="/attendance/clock-in">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token ?? '', ENT_QUOTES, 'UTF-8') ?>">
                    <button type="submit" class="sapas-btn sapas-btn-success w-full disabled:cursor-not-allowed disabled:opacity-50" <?= $canClockIn ? '' : 'disabled' ?>><?= htmlspecialchars($t('dashboard.common.clock_in'), ENT_QUOTES, 'UTF-8') ?></button>
                </form>
                <?php
                $checkoutButtonClass = 'sapas-btn sapas-btn-primary w-full disabled:cursor-not-allowed disabled:opacity-50';
                $checkoutButtonLabel = $t('dashboard.common.clock_out');
                $checkoutButtonDisabled = !$canClockOut;
                $checkoutButtonIcon = false;
                require __DIR__ . '/../partials/early_departure_checkout.php';
                unset($checkoutButtonClass, $checkoutButtonLabel, $checkoutButtonDisabled, $checkoutButtonIcon);
                ?>
                <?php if (!$canClockIn && !$canClockOut): ?>
                    <?php
                    $noActionKey = match ($clockInBlockReason) {
                        'approved_leave_conflict' => 'attendance.no_clock_actions_leave',
                        'holiday_blocked' => 'attendance.no_clock_actions_holiday',
                        'non_working_day_blocked' => 'attendance.no_clock_actions_non_working_day',
                        'employee_profile_missing' => 'attendance.no_clock_actions_profile',
                        default => 'attendance.no_actions_available',
                    };
                    ?>
                    <p class="text-sm text-slate-500"><?= htmlspecialchars($t($noActionKey), ENT_QUOTES, 'UTF-8') ?></p>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="sapas-report-table-panel">
    <div class="sapas-report-table-header">
        <div class="sapas-filter-header flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
            <div>
                <h2 class="text-xl font-semibold"><?= htmlspecialchars($isTeamViewer ? $t('attendance.admin_filters') : $t('attendance.employee_filters'), ENT_QUOTES, 'UTF-8') ?></h2>
                <p class="mt-1 text-sm text-slate-500"><?= htmlspecialchars($isTeamViewer ? $t('attendance.admin_filters_help') : $t('attendance.employee_filters_help'), ENT_QUOTES, 'UTF-8') ?></p>
            </div>
            <a href="/attendance" class="sapas-btn sapas-btn-secondary sapas-filter-reset"><?= htmlspecialchars($t('common.actions.reset'), ENT_QUOTES, 'UTF-8') ?></a>
        </div>

        <form method="get" action="/attendance" class="sapas-report-filter-grid sapas-polish-filter-grid is-four">
            <input type="hidden" name="per_page" value="<?= $perPage ?>">
            <?php if ($isTeamViewer): ?>
                <div>
                    <label for="department_id" class="block text-sm font-medium text-slate-700"><?= htmlspecialchars($t('common.labels.department'), ENT_QUOTES, 'UTF-8') ?></label>
                    <?php if ($departmentScopeLocked): ?>
                        <input type="hidden" name="department_id" value="<?= (int) ($departmentId ?? 0) ?>">
                        <select class="mt-2 sapas-form-control" disabled aria-disabled="true">
                            <?php foreach ($departments as $department): ?>
                                <option selected><?= htmlspecialchars((string) $department['name'], ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <select id="department_id" name="department_id" class="mt-2 sapas-form-control" onchange="if (this.form.elements['employee_id']) { this.form.elements['employee_id'].value = ''; } this.form.submit()">
                            <option value=""><?= htmlspecialchars($t('reports_module.all_departments'), ENT_QUOTES, 'UTF-8') ?></option>
                            <?php foreach ($departments as $department): ?>
                                <option value="<?= (int) $department['id'] ?>" <?= $departmentId === (int) $department['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars((string) $department['name'], ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                </div>
                <div>
                    <label for="employee_id" class="block text-sm font-medium text-slate-700"><?= htmlspecialchars($t('common.labels.employee'), ENT_QUOTES, 'UTF-8') ?></label>
                    <select id="employee_id" name="employee_id" class="mt-2 sapas-form-control">
                        <option value=""><?= htmlspecialchars($t('reports_module.all_employees'), ENT_QUOTES, 'UTF-8') ?></option>
                        <?php if (isset($employees) && is_array($employees)): ?>
                            <?php foreach ($employees as $employee): ?>
                                <option value="<?= (int) $employee['id'] ?>" <?= $selectedEmployeeId === (int) $employee['id'] ? 'selected' : '' ?>>
                                    <?php
                                    $employeeName = trim((string) ($employee['name'] ?? ''));
                                    $staffCode = trim((string) ($employee['staff_code'] ?? ''));
                                    $employeeIdentity = $staffCode !== '' ? $employeeName . ' (' . $staffCode . ')' : $employeeName;
                                    $employeeEmail = trim((string) ($employee['email'] ?? ''));
                                    ?>
                                    <?= htmlspecialchars($employeeEmail !== '' ? $employeeIdentity . ' — ' . $employeeEmail : $employeeIdentity, ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>
            <?php endif; ?>
            <div>
                <label class="block text-sm font-medium text-slate-700"><?= htmlspecialchars($t('common.labels.date_from'), ENT_QUOTES, 'UTF-8') ?></label>
                <input type="date" name="from" value="<?= htmlspecialchars($fromDate, ENT_QUOTES, 'UTF-8') ?>" class="mt-2 sapas-form-control">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700"><?= htmlspecialchars($t('common.labels.date_to'), ENT_QUOTES, 'UTF-8') ?></label>
                <input type="date" name="to" value="<?= htmlspecialchars($toDate, ENT_QUOTES, 'UTF-8') ?>" class="mt-2 sapas-form-control">
            </div>
            <div class="sapas-filter-actions flex items-end gap-2">
                <button type="submit" class="sapas-btn sapas-btn-primary w-full sm:w-auto"><?= htmlspecialchars($t('common.actions.apply_filters'), ENT_QUOTES, 'UTF-8') ?></button>
            </div>
        </form>

        <?php if ($hasFilters): ?>
            <div class="sapas-report-active-filters">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                    <div>
                        <p class="text-sm font-medium text-slate-500"><?= htmlspecialchars($t('common.labels.active_filters'), ENT_QUOTES, 'UTF-8') ?></p>
                        <div class="mt-2 flex flex-wrap gap-2">
                            <?php foreach ($activeFilters as $filter): ?>
                                <span class="sapas-chip sapas-chip-info"><?= htmlspecialchars($filter, ENT_QUOTES, 'UTF-8') ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <p class="text-sm text-slate-600"><?= htmlspecialchars(attendanceTemplate($total === 1 ? 'attendance.matching_records_summary' : 'attendance.matching_records_summary_plural', ['count' => (string) $total]), ENT_QUOTES, 'UTF-8') ?></p>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <?php if (empty($records)): ?>
        <div class="sapas-report-empty sapas-polish-empty-state">
            <p class="sapas-report-empty-title"><?= htmlspecialchars($t('attendance.empty_title'), ENT_QUOTES, 'UTF-8') ?></p>
            <p class="sapas-report-empty-copy"><?= htmlspecialchars($isTeamViewer ? $t('attendance.empty_admin_help') : $t('attendance.empty_employee_help'), ENT_QUOTES, 'UTF-8') ?></p>
        </div>
    <?php else: ?>
        <div class="sapas-report-table-header sapas-data-table-summary">
            <div class="sapas-report-meta-grid sapas-data-table-summary-grid">
                <div>
                    <p class="text-sm font-medium text-slate-500"><?= htmlspecialchars($t('reports.page'), ENT_QUOTES, 'UTF-8') ?></p>
                    <p class="mt-1 text-lg font-semibold text-slate-900"><?= htmlspecialchars(attendanceTemplate($visibleCount === 1 ? 'attendance.records_on_page_summary' : 'attendance.records_on_page_summary_plural', ['count' => (string) $visibleCount]), ENT_QUOTES, 'UTF-8') ?></p>
                </div>
                <div>
                    <p class="text-sm font-medium text-slate-500"><?= htmlspecialchars($t('reports.total_matches'), ENT_QUOTES, 'UTF-8') ?></p>
                    <p class="mt-1 text-lg font-semibold text-slate-900"><?= htmlspecialchars(attendanceTemplate($total === 1 ? 'attendance.total_records_summary' : 'attendance.total_records_summary_plural', ['count' => (string) $total]), ENT_QUOTES, 'UTF-8') ?></p>
                </div>
            </div>
        </div>

        <div class="overflow-x-auto sapas-data-table-scroll sapas-polish-table-scroll">
            <table class="min-w-full divide-y divide-slate-200 sapas-data-table sapas-attendance-table">
                <colgroup>
                    <col class="sapas-col-row-number">
                    <col class="sapas-col-date">
                    <?php if ($isTeamViewer): ?>
                        <col class="sapas-col-employee">
                    <?php endif; ?>
                    <col class="sapas-col-time sapas-col-attendance-time">
                    <col class="sapas-col-time sapas-col-attendance-time">
                    <col class="sapas-col-status sapas-col-attendance-status">
                    <col class="sapas-col-number sapas-col-attendance-late">
                    <col class="sapas-col-duration sapas-col-attendance-work-duration">
                    <col class="sapas-col-duration">
                    <col class="sapas-col-remarks">
                    <?php if ($canManageAttendance): ?>
                        <col class="sapas-col-action">
                    <?php endif; ?>
                </colgroup>
                <thead class="bg-slate-50">
                    <tr>
                        <th class="whitespace-nowrap px-3 py-2 text-left text-sm font-medium text-slate-700"><?= htmlspecialchars($t('common.labels.no'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th class="whitespace-nowrap px-3 py-2 text-left text-sm font-medium text-slate-700"><?= htmlspecialchars($t('common.labels.date'), ENT_QUOTES, 'UTF-8') ?></th>
                        <?php if ($isTeamViewer): ?>
                            <th class="whitespace-nowrap px-3 py-2 text-left text-sm font-medium text-slate-700"><?= htmlspecialchars($t('common.labels.employee'), ENT_QUOTES, 'UTF-8') ?></th>
                        <?php endif; ?>
                        <th class="whitespace-nowrap px-3 py-2 text-left text-sm font-medium text-slate-700"><?= htmlspecialchars($t('dashboard.common.clock_in_time'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th class="whitespace-nowrap px-3 py-2 text-left text-sm font-medium text-slate-700"><?= htmlspecialchars($t('dashboard.common.clock_out_time'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th class="whitespace-nowrap px-3 py-2 text-left text-sm font-medium text-slate-700"><?= htmlspecialchars($t('common.labels.status'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th class="whitespace-nowrap px-3 py-2 text-left text-sm font-medium text-slate-700"><?= htmlspecialchars($t('dashboard.common.late_minutes'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th class="whitespace-nowrap px-3 py-2 text-left text-sm font-medium text-slate-700"><?= htmlspecialchars($t('dashboard.common.working_hours'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th class="whitespace-nowrap px-3 py-2 text-left text-sm font-medium text-slate-700"><?= htmlspecialchars($t('common.labels.overtime'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th class="whitespace-nowrap px-3 py-2 text-left text-sm font-medium text-slate-700"><?= htmlspecialchars($t('attendance.remarks'), ENT_QUOTES, 'UTF-8') ?></th>
                        <?php if ($canManageAttendance): ?>
                            <th class="whitespace-nowrap px-3 py-2 text-left text-sm font-medium text-slate-700"><?= htmlspecialchars($t('common.labels.actions'), ENT_QUOTES, 'UTF-8') ?></th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200">
                    <?php foreach ($records as $index => $record): ?>
                        <tr>
                            <td class="px-3 py-2.5 text-sm text-slate-800 whitespace-nowrap"><span class="sapas-row-number"><?= $rowNumberStart + (int) $index ?></span></td>
                            <td class="px-3 py-2.5 text-sm text-slate-800 whitespace-nowrap">
                                <div class="sapas-date-cell leading-tight">
                                    <p class="font-medium text-slate-900"><?= htmlspecialchars(attendanceDateValue((string) $record['attendance_date']), ENT_QUOTES, 'UTF-8') ?></p>
                                    <p class="text-xs text-slate-500"><?= htmlspecialchars(attendanceWeekdayValue((string) $record['attendance_date']), ENT_QUOTES, 'UTF-8') ?></p>
                                </div>
                            </td>
                            <?php if ($isTeamViewer): ?>
                                <?php
                                $employeeName = trim((string) ($record['employee_name'] ?? ''));
                                $staffCode = trim((string) ($record['staff_code'] ?? ''));
                                $employeeIdentity = $staffCode !== '' ? $employeeName . ' (' . $staffCode . ')' : $employeeName;
                                ?>
                                <td class="px-3 py-2.5 text-sm text-slate-800">
                                    <div class="sapas-person-cell leading-tight">
                                        <div class="sapas-person-cell-head">
                                            <p class="sapas-person-name font-medium text-slate-900" title="<?= htmlspecialchars($employeeIdentity, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($employeeIdentity, ENT_QUOTES, 'UTF-8') ?></p>
                                        </div>
                                        <p class="sapas-person-email" title="<?= htmlspecialchars($record['employee_email'] ?? '', ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($record['employee_email'] ?? '', ENT_QUOTES, 'UTF-8') ?></p>
                                    </div>
                                </td>
                            <?php endif; ?>
                            <td class="px-3 py-2.5 text-sm text-slate-600 whitespace-nowrap sapas-table-value-nowrap"><?= htmlspecialchars($record['clock_in_time'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="px-3 py-2.5 text-sm text-slate-600 whitespace-nowrap sapas-table-value-nowrap"><?= htmlspecialchars($record['clock_out_time'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="px-3 py-2.5 text-sm sapas-attendance-status-cell">
                                <?php $displayStatus = (string) ($record['attendance_display_status'] ?? $record['status']); ?>
                                <div class="inline-flex items-center">
                                    <span class="<?= attendanceStatusClasses($displayStatus) ?>">
                                        <?= htmlspecialchars(formatStatusLabel($displayStatus), ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                </div>
                                <?php if ($isTeamViewer && !empty($record['scheduled_start_at']) && !empty($record['scheduled_end_at'])): ?>
                                    <div class="mt-1 text-xs text-slate-500 whitespace-nowrap">
                                        <?= htmlspecialchars(attendanceScheduleTime($record['scheduled_start_at']) . '–' . attendanceScheduleTime($record['scheduled_end_at']), ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars((string) ($record['scheduled_overlap_duration'] ?? '—'), ENT_QUOTES, 'UTF-8') ?>)
                                    </div>
                                <?php endif; ?>
                                <?php if ((int) ($record['out_of_schedule_work_minutes'] ?? 0) > 0): ?>
                                    <div class="mt-0.5 text-xs font-medium text-sky-700 whitespace-nowrap"><?= htmlspecialchars($t('attendance.out_of_schedule_work'), ENT_QUOTES, 'UTF-8') ?>: <?= htmlspecialchars((string) ($record['out_of_schedule_work_duration'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></div>
                                <?php endif; ?>
                                <?php if ((int) ($record['is_early_departure'] ?? 0) === 1): ?>
                                    <div class="mt-0.5 text-xs font-semibold text-amber-700 whitespace-nowrap"><?= htmlspecialchars($t('attendance.early_departure'), ENT_QUOTES, 'UTF-8') ?>: <?= htmlspecialchars((string) ($record['early_departure_duration'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></div>
                                    <div class="text-xs text-slate-500 truncate max-w-40" title="<?= htmlspecialchars((string) ($record['early_departure_note'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(attendanceEarlyReasonLabel($record['early_departure_reason'] ?? null, $t), ENT_QUOTES, 'UTF-8') ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="px-3 py-2.5 text-sm text-slate-600 whitespace-nowrap sapas-table-number"><?= htmlspecialchars($record['late_duration'] ?? '0 min', ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="px-3 py-2.5 text-sm text-slate-600 whitespace-nowrap sapas-table-number"><?= htmlspecialchars(formatWorkingDurationValue($record), ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="px-3 py-2.5 text-sm text-slate-600 whitespace-nowrap sapas-table-number">
                                <?php
                                $ovStatus = (string) ($record['overtime_approval_status'] ?? '');
                                $approvedDur = (string) ($record['approved_overtime_duration'] ?? '—');
                                $calcDur = (string) ($record['calculated_overtime_duration'] ?? '—');
                                $legacyDur = (string) ($record['overtime_duration'] ?? '—');
                                $ovDur = $ovStatus === 'pending' ? ($calcDur !== '—' ? $calcDur : $legacyDur) : ($approvedDur !== '—' ? $approvedDur : $legacyDur);
                                ?>
                                <?php if ($ovStatus === 'pending'): ?>
                                    <span class="block font-medium text-amber-700"><?= htmlspecialchars($ovDur, ENT_QUOTES, 'UTF-8') ?></span>
                                    <span class="block text-xs"><?= htmlspecialchars($t('attendance.overtime_candidate_pending'), ENT_QUOTES, 'UTF-8') ?></span>
                                <?php else: ?>
                                    <?= htmlspecialchars($ovDur, ENT_QUOTES, 'UTF-8') ?>
                                <?php endif; ?>
                            </td>
                            <?php $rem = trim((string) ($record['remarks'] ?? '')); ?>
                            <td class="px-3 py-2.5 text-sm text-slate-600 sapas-table-remarks max-w-[150px] truncate" title="<?= htmlspecialchars($rem, ENT_QUOTES, 'UTF-8') ?>">
                                <?= $rem !== '' ? htmlspecialchars($rem, ENT_QUOTES, 'UTF-8') : '—' ?>
                            </td>
                            <?php if ($canManageAttendance): ?>
                                <td class="px-3 py-2.5 text-sm font-medium sapas-table-action">
                                    <a href="/attendance/edit?id=<?= (int) $record['id'] ?>" class="sapas-btn sapas-btn-secondary sapas-btn-spotlight whitespace-nowrap"><?= htmlspecialchars($t('attendance.edit_record'), ENT_QUOTES, 'UTF-8') ?></a>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php $paginationPath = '/attendance'; ?>
        <?php require __DIR__ . '/../partials/pagination.php'; ?>
    <?php endif; ?>
</div>
