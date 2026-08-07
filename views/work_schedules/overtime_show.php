<?php
$t = $t ?? static fn (string $key): string => $key;
$record = $record ?? [];
$decisions = $decisions ?? [];
$layout = 'layouts/app';
$pageTitle = $t('workforce_policy.overtime.details_title');
$pageDescription = $t('workforce_policy.overtime.details_description');
$pageActions = '<a href="/admin/overtime" class="sapas-btn sapas-btn-secondary">' . htmlspecialchars($t('workforce_policy.overtime.back'), ENT_QUOTES, 'UTF-8') . '</a>';
$minutes = static fn (mixed $value): string => $value === null || $value === '' ? '—' : ((int) $value . 'm');
$identity = trim((string) ($record['employee_name'] ?? '')) . (trim((string) ($record['staff_code'] ?? '')) !== '' ? ' (' . trim((string) $record['staff_code']) . ')' : '');
$item = static function (string $label, string $value): string {
    return '<div><dt class="text-xs font-semibold uppercase text-slate-500">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</dt><dd class="mt-1 text-sm text-slate-900">' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</dd></div>';
};
?>

<section class="sapas-report-table-panel">
    <dl class="grid gap-4 md:grid-cols-4">
        <?= $item($t('common.labels.employee'), $identity) ?>
        <?= $item($t('common.labels.date'), (string) ($record['attendance_date'] ?? '—')) ?>
        <?= $item($t('workforce_policy.policy_version'), (string) ($record['policy_name'] ?? '—')) ?>
        <?= $item($t('workforce_policy.assignment_source'), (string) ($record['assignment_scope'] ?? 'compatibility')) ?>
        <?= $item($t('workforce_policy.day_type'), $t('workforce_policy.day_types.' . (string) ($record['day_type'] ?? 'working_day'))) ?>
        <?= $item($t('workforce_policy.attendance_state'), (string) ($record['attendance_state'] ?? '—')) ?>
        <?= $item($t('common.labels.clock_in'), (string) ($record['check_in_at'] ?? $record['clock_in_time'] ?? '—')) ?>
        <?= $item($t('common.labels.clock_out'), (string) ($record['check_out_at'] ?? $record['clock_out_time'] ?? '—')) ?>
        <?= $item($t('workforce_policy.overtime.regular'), $minutes($record['regular_minutes'] ?? null)) ?>
        <?= $item($t('workforce_policy.overtime.early'), $minutes($record['early_overtime_minutes'] ?? null)) ?>
        <?= $item($t('workforce_policy.overtime.after_hours'), $minutes($record['after_hours_overtime_minutes'] ?? null)) ?>
        <?= $item($t('workforce_policy.overtime.non_working'), $minutes($record['non_working_day_overtime_minutes'] ?? null)) ?>
        <?= $item($t('workforce_policy.overtime.holiday'), $minutes($record['holiday_overtime_minutes'] ?? null)) ?>
        <?= $item($t('workforce_policy.overtime.raw'), $minutes($record['raw_calculated_overtime_minutes'] ?? null)) ?>
        <?= $item($t('workforce_policy.overtime.calculated'), $minutes($record['calculated_overtime_minutes'] ?? null)) ?>
        <?= $item($t('workforce_policy.overtime.approved'), $minutes($record['approved_overtime_minutes'] ?? null)) ?>
        <?= $item($t('workforce_policy.overtime.approval_status'), $t('workforce_policy.overtime.status_' . (string) ($record['overtime_approval_status'] ?? 'not_required'))) ?>
        <?= $item($t('workforce_policy.calculation_version'), (string) ($record['calculation_version'] ?? '—')) ?>
        <?= $item($t('attendance.remarks'), (string) ($record['remarks'] ?? '—')) ?>
    </dl>
</section>

<section class="sapas-report-table-panel mt-5">
    <h2 class="text-base font-semibold text-slate-900"><?= htmlspecialchars($t('workforce_policy.overtime.actions_title'), ENT_QUOTES, 'UTF-8') ?></h2>
    <div class="mt-4 grid gap-4 md:grid-cols-2">
        <form method="post" action="/admin/overtime/approve" class="space-y-3">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token ?? '', ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="id" value="<?= (int) ($record['id'] ?? 0) ?>">
            <label class="block text-sm font-medium text-slate-700"><?= htmlspecialchars($t('workforce_policy.overtime.approved_minutes'), ENT_QUOTES, 'UTF-8') ?></label>
            <input type="number" min="0" name="approved_minutes" class="sapas-form-control" value="<?= (int) ($record['calculated_overtime_minutes'] ?? 0) ?>">
            <textarea name="reason" rows="2" class="sapas-form-control" placeholder="<?= htmlspecialchars($t('workforce_policy.overtime.reason_placeholder'), ENT_QUOTES, 'UTF-8') ?>"></textarea>
            <button class="sapas-btn sapas-btn-success" type="submit"><?= htmlspecialchars($t('workforce_policy.overtime.approve'), ENT_QUOTES, 'UTF-8') ?></button>
        </form>
        <form method="post" action="/admin/overtime/adjust" class="space-y-3">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token ?? '', ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="id" value="<?= (int) ($record['id'] ?? 0) ?>">
            <label class="block text-sm font-medium text-slate-700"><?= htmlspecialchars($t('workforce_policy.overtime.adjust_minutes'), ENT_QUOTES, 'UTF-8') ?></label>
            <input type="number" min="0" name="approved_minutes" class="sapas-form-control" value="<?= (int) ($record['approved_overtime_minutes'] ?? 0) ?>">
            <textarea name="reason" rows="2" class="sapas-form-control" required placeholder="<?= htmlspecialchars($t('workforce_policy.overtime.reason_placeholder'), ENT_QUOTES, 'UTF-8') ?>"></textarea>
            <button class="sapas-btn sapas-btn-secondary" type="submit"><?= htmlspecialchars($t('workforce_policy.overtime.adjust'), ENT_QUOTES, 'UTF-8') ?></button>
        </form>
        <form method="post" action="/admin/overtime/reject" class="space-y-3">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token ?? '', ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="id" value="<?= (int) ($record['id'] ?? 0) ?>">
            <textarea name="reason" rows="2" class="sapas-form-control" required placeholder="<?= htmlspecialchars($t('workforce_policy.overtime.reason_placeholder'), ENT_QUOTES, 'UTF-8') ?>"></textarea>
            <button class="sapas-btn sapas-btn-danger-quiet" type="submit"><?= htmlspecialchars($t('workforce_policy.overtime.reject'), ENT_QUOTES, 'UTF-8') ?></button>
        </form>
        <form method="post" action="/admin/overtime/reset-pending" class="space-y-3">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token ?? '', ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="id" value="<?= (int) ($record['id'] ?? 0) ?>">
            <textarea name="reason" rows="2" class="sapas-form-control" required placeholder="<?= htmlspecialchars($t('workforce_policy.overtime.reason_placeholder'), ENT_QUOTES, 'UTF-8') ?>"></textarea>
            <button class="sapas-btn sapas-btn-secondary" type="submit"><?= htmlspecialchars($t('workforce_policy.overtime.reset_pending'), ENT_QUOTES, 'UTF-8') ?></button>
        </form>
    </div>
</section>

<section class="sapas-report-table-panel mt-5">
    <h2 class="text-base font-semibold text-slate-900"><?= htmlspecialchars($t('workforce_policy.overtime.decision_history'), ENT_QUOTES, 'UTF-8') ?></h2>
    <?php if ($decisions === []): ?>
        <p class="mt-3 text-sm text-slate-500"><?= htmlspecialchars($t('workforce_policy.overtime.no_decisions'), ENT_QUOTES, 'UTF-8') ?></p>
    <?php else: ?>
        <div class="mt-4 sapas-data-table-scroll overflow-x-auto">
            <table class="sapas-data-table divide-y divide-slate-200">
                <thead>
                    <tr>
                        <th><?= htmlspecialchars($t('audit_logs.date_time'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th><?= htmlspecialchars($t('audit_logs.action'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th><?= htmlspecialchars($t('workforce_policy.overtime.calculated'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th><?= htmlspecialchars($t('workforce_policy.overtime.approved'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th><?= htmlspecialchars($t('workforce_policy.overtime.reason'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th><?= htmlspecialchars($t('audit_logs.actor'), ENT_QUOTES, 'UTF-8') ?></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 bg-white">
                    <?php foreach ($decisions as $decision): ?>
                        <tr>
                            <td><?= htmlspecialchars((string) ($decision['decided_at'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($t('workforce_policy.overtime.decision_' . (string) ($decision['decision'] ?? 'approved')), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($minutes($decision['calculated_minutes'] ?? null), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($minutes($decision['approved_minutes'] ?? null), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><p class="sapas-table-text-cell"><?= htmlspecialchars((string) ($decision['reason'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></p></td>
                            <td><?= htmlspecialchars((string) ($decision['actor_name'] ?? $t('audit_logs.system_actor')), ENT_QUOTES, 'UTF-8') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
