<?php
$t = $t ?? static fn (string $key): string => $key;
if (!isset($title)) {
    $title = $t('attendance.record_title');
}
if (!isset($action)) {
    $action = '';
}
if (!isset($method)) {
    $method = 'POST';
}
if (!isset($errors)) {
    $errors = [];
}
if (!isset($attendance)) {
    $attendance = ['employee_id' => '', 'attendance_date' => '', 'clock_in_time' => '', 'clock_out_time' => '', 'status' => 'present', 'remarks' => ''];
}
if (!isset($employees)) {
    $employees = [];
}
if (!isset($isEdit)) {
    $isEdit = false;
}

$layout = 'layouts/app';
$pageTitle = $title;
$pageDescription = $t('attendance.select_employee_help');
$pageActions = '<a href="/attendance" class="sapas-btn sapas-btn-secondary">' . htmlspecialchars($t('attendance.back_to_attendance'), ENT_QUOTES, 'UTF-8') . '</a>';
$requiredIndicator = '<span class="sapas-required-indicator">' . htmlspecialchars($t('common.required'), ENT_QUOTES, 'UTF-8') . '</span>';

if (!function_exists('attendanceFormSummaryValue')) {
    function attendanceFormSummaryValue(mixed $value): string
    {
        return $value === null || $value === '' ? '—' : (string) $value;
    }
}

if (!function_exists('attendanceFormMinutesValue')) {
    function attendanceFormMinutesValue(mixed $minutes): string
    {
        if ($minutes === null || $minutes === '') {
            return '—';
        }

        $minutes = max(0, (int) $minutes);
        $hours = intdiv($minutes, 60);
        $remainingMinutes = $minutes % 60;

        if ($hours === 0) {
            return $minutes . 'm';
        }

        return $remainingMinutes === 0
            ? $hours . 'h'
            : $hours . 'h ' . $remainingMinutes . 'm';
    }
}

if (!function_exists('attendanceFormDayTypeLabel')) {
    function attendanceFormDayTypeLabel(?string $dayType, callable $t): string
    {
        if ($dayType === null || $dayType === '') {
            return '—';
        }

        return $t('workforce_policy.day_types.' . $dayType);
    }
}

if (!function_exists('attendanceFormStateLabel')) {
    function attendanceFormStateLabel(?string $state, callable $t): string
    {
        if ($state === null || $state === '') {
            return '—';
        }

        return $t('attendance.states.' . $state);
    }
}
?>
<div class="max-w-4xl">
    <?php if (!empty($errors)): ?>
        <div class="mb-4 sapas-alert-error">
            <p class="font-semibold"><?= htmlspecialchars($t('common.form_review'), ENT_QUOTES, 'UTF-8') ?></p>
            <ul class="mt-3 list-disc list-inside">
                <?php foreach ($errors as $error): ?>
                    <li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

        <form method="post" action="<?= htmlspecialchars($action, ENT_QUOTES, 'UTF-8') ?>" class="sapas-form-section space-y-4">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token ?? '', ENT_QUOTES, 'UTF-8') ?>">
            <?php if ($isEdit): ?>
                <input type="hidden" name="attendance_freshness" value="<?= htmlspecialchars($attendance['freshness_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            <?php endif; ?>

            <div class="sapas-form-section-header">
                <h2><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></h2>
                <p><?= htmlspecialchars($t('attendance.form_policy_help'), ENT_QUOTES, 'UTF-8') ?></p>
            </div>

            <div class="sapas-form-grid">
                <?php if ($isEdit): ?>
                    <?php
                    $summaryValue = static fn (mixed $value): string => $value === null || $value === '' ? '—' : (string) $value;
                    $yesNo = static fn (mixed $value): string => (int) $value === 1 ? $t('common.yes') : $t('common.no');
                    ?>
                    <div class="md:col-span-2 rounded-md border border-slate-200 bg-slate-50 p-3">
                        <h3 class="text-sm font-semibold text-slate-900"><?= htmlspecialchars($t('attendance.details'), ENT_QUOTES, 'UTF-8') ?></h3>
                        <dl class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            <div>
                                <dt class="text-xs font-medium text-slate-500"><?= htmlspecialchars($t('workforce_policy.day_type'), ENT_QUOTES, 'UTF-8') ?></dt>
                                <dd class="mt-1 text-sm font-semibold text-slate-900"><?= htmlspecialchars(attendanceFormDayTypeLabel($attendance['day_type'] ?? null, $t), ENT_QUOTES, 'UTF-8') ?></dd>
                            </div>
                            <div>
                                <dt class="text-xs font-medium text-slate-500"><?= htmlspecialchars($t('workforce_policy.attendance_state'), ENT_QUOTES, 'UTF-8') ?></dt>
                                <dd class="mt-1 text-sm font-semibold text-slate-900"><?= htmlspecialchars(attendanceFormStateLabel($attendance['attendance_state'] ?? null, $t), ENT_QUOTES, 'UTF-8') ?></dd>
                            </div>
                            <div>
                                <dt class="text-xs font-medium text-slate-500"><?= htmlspecialchars($t('dashboard.common.working_hours'), ENT_QUOTES, 'UTF-8') ?></dt>
                                <dd class="mt-1 text-sm font-semibold text-slate-900"><?= htmlspecialchars(attendanceFormSummaryValue($attendance['working_hours'] ?? null), ENT_QUOTES, 'UTF-8') ?></dd>
                            </div>
                            <div>
                                <dt class="text-xs font-medium text-slate-500"><?= htmlspecialchars($t('attendance.late_minutes'), ENT_QUOTES, 'UTF-8') ?></dt>
                                <dd class="mt-1 text-sm font-semibold text-slate-900"><?= htmlspecialchars($attendance['late_duration'] ?? attendanceFormSummaryValue($attendance['late_minutes'] ?? null), ENT_QUOTES, 'UTF-8') ?></dd>
                            </div>
                            <div>
                                <dt class="text-xs font-medium text-slate-500"><?= htmlspecialchars($t('attendance.early_departure'), ENT_QUOTES, 'UTF-8') ?></dt>
                                <dd class="mt-1 text-sm font-semibold text-slate-900"><?= htmlspecialchars(attendanceFormSummaryValue($attendance['early_departure_minutes'] ?? null), ENT_QUOTES, 'UTF-8') ?></dd>
                            </div>
                            <div>
                                <dt class="text-xs font-medium text-slate-500"><?= htmlspecialchars($t('attendance.missing_check_out'), ENT_QUOTES, 'UTF-8') ?></dt>
                                <dd class="mt-1 text-sm font-semibold text-slate-900"><?= htmlspecialchars($yesNo($attendance['is_missing_check_out'] ?? 0), ENT_QUOTES, 'UTF-8') ?></dd>
                            </div>
                            <div>
                                <dt class="text-xs font-medium text-slate-500"><?= htmlspecialchars($t('attendance.calculated_overtime'), ENT_QUOTES, 'UTF-8') ?></dt>
                                <dd class="mt-1 text-sm font-semibold text-slate-900"><?= htmlspecialchars(attendanceFormMinutesValue($attendance['calculated_overtime_minutes'] ?? null), ENT_QUOTES, 'UTF-8') ?></dd>
                            </div>
                        </dl>
                    </div>
                <?php endif; ?>

                <div class="md:col-span-2">
                <label for="employee_id" class="sapas-form-label"><?= htmlspecialchars($t('common.labels.employee'), ENT_QUOTES, 'UTF-8') ?><?= $requiredIndicator ?></label>
                <select id="employee_id" name="employee_id" required class="mt-2 sapas-form-control">
                    <option value=""><?= htmlspecialchars($t('common.select_employee'), ENT_QUOTES, 'UTF-8') ?></option>
                    <?php foreach ($employees as $employee): ?>
                        <?php
                        $employeeName = trim((string) ($employee['name'] ?? ''));
                        $staffCode = trim((string) ($employee['staff_code'] ?? ''));
                        $employeeIdentity = $staffCode !== '' ? $employeeName . ' (' . $staffCode . ')' : $employeeName;
                        $employeeEmail = trim((string) ($employee['email'] ?? ''));
                        ?>
                        <option value="<?= (int) $employee['id'] ?>" <?= ((int) ($attendance['employee_id'] ?? 0) === (int) $employee['id']) ? 'selected' : '' ?>><?= htmlspecialchars($employeeEmail !== '' ? $employeeIdentity . ' — ' . $employeeEmail : $employeeIdentity, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

                <div>
                    <label for="attendance_date" class="sapas-form-label"><?= htmlspecialchars($t('attendance.attendance_date'), ENT_QUOTES, 'UTF-8') ?><?= $requiredIndicator ?></label>
                    <input type="date" id="attendance_date" name="attendance_date" value="<?= htmlspecialchars($attendance['attendance_date'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required class="mt-2 sapas-form-control">
                </div>
                <div>
                    <label for="status" class="sapas-form-label"><?= htmlspecialchars($t('common.labels.status'), ENT_QUOTES, 'UTF-8') ?></label>
                    <select id="status" name="status" class="mt-2 sapas-form-control">
                        <option value="present" <?= ($attendance['status'] ?? '') === 'present' ? 'selected' : '' ?>><?= htmlspecialchars($t('statuses.attendance.present'), ENT_QUOTES, 'UTF-8') ?></option>
                        <option value="absent" <?= ($attendance['status'] ?? '') === 'absent' ? 'selected' : '' ?>><?= htmlspecialchars($t('statuses.attendance.absent'), ENT_QUOTES, 'UTF-8') ?></option>
                        <option value="holiday" <?= ($attendance['status'] ?? '') === 'holiday' ? 'selected' : '' ?>><?= htmlspecialchars($t('statuses.attendance.holiday'), ENT_QUOTES, 'UTF-8') ?></option>
                        <option value="weekend" <?= ($attendance['status'] ?? '') === 'weekend' ? 'selected' : '' ?>><?= htmlspecialchars($t('statuses.attendance.weekend'), ENT_QUOTES, 'UTF-8') ?></option>
                    </select>
                    <p class="sapas-field-help"><?= htmlspecialchars($t('attendance.status_recalculated_help'), ENT_QUOTES, 'UTF-8') ?></p>
                </div>

                <div>
                    <label for="clock_in_time" class="sapas-form-label"><?= htmlspecialchars($t('dashboard.common.clock_in_time'), ENT_QUOTES, 'UTF-8') ?></label>
                    <input type="time" id="clock_in_time" name="clock_in_time" value="<?= htmlspecialchars($attendance['clock_in_time'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="mt-2 sapas-form-control">
                </div>
                <div>
                    <label for="clock_out_time" class="sapas-form-label"><?= htmlspecialchars($t('dashboard.common.clock_out_time'), ENT_QUOTES, 'UTF-8') ?></label>
                    <input type="time" id="clock_out_time" name="clock_out_time" value="<?= htmlspecialchars($attendance['clock_out_time'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="mt-2 sapas-form-control">
                    <p class="sapas-field-help"><?= htmlspecialchars($t('attendance.overnight_time_help'), ENT_QUOTES, 'UTF-8') ?></p>
                </div>

                <div class="md:col-span-2">
                    <label for="remarks" class="sapas-form-label"><?= htmlspecialchars($t('attendance.remarks'), ENT_QUOTES, 'UTF-8') ?></label>
                    <textarea id="remarks" name="remarks" rows="3" class="mt-2 sapas-form-control"><?= htmlspecialchars($attendance['remarks'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                </div>
            </div>

            <div class="sapas-form-action-row">
                <a href="/attendance" class="sapas-btn sapas-btn-secondary"><?= htmlspecialchars($t('common.actions.cancel'), ENT_QUOTES, 'UTF-8') ?></a>
                <button type="submit" class="sapas-btn sapas-btn-primary"><?= htmlspecialchars($isEdit ? $t('common.actions.save_changes') : $t('common.actions.save'), ENT_QUOTES, 'UTF-8') ?></button>
            </div>
        </form>
</div>
