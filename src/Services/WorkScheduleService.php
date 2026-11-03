<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Localization;
use App\Models\WorkScheduleTemplate;
use DateTimeZone;
use PDO;
use Throwable;

final class WorkScheduleService
{
    public const WEEKDAYS = [1, 2, 3, 4, 5, 6, 7];
    private const MAX_MINUTES = 1440;
    private const MAX_BREAK_MINUTES = 720;
    private const MAX_GRACE_MINUTES = 240;
    private const MISSING_CHECK_OUT_MODES = ['mark_incomplete', 'admin_review_required'];
    private const ROUNDING_MODES = ['exact', 'down', 'up', 'nearest'];
    private const ROUNDING_INTERVALS = [5, 10, 15, 30];

    public function __construct(
        private PDO $connection,
        private WorkScheduleTemplate $templates,
        private DateValidationService $dateValidator,
        private ?AuditLogService $auditLog = null,
    ) {
    }

    public function defaultTemplateData(): array
    {
        return [
            'name' => '',
            'description' => '',
            'timezone' => '',
            'effective_from' => date('Y-m-d'),
            'effective_to' => '',
            'is_active' => 1,
            'lifecycle_status' => 'draft',
            'version_number' => 1,
            'change_reason' => '',
            'days' => $this->defaultDays(),
        ];
    }

    public function defaultDays(): array
    {
        $days = [];
        foreach (self::WEEKDAYS as $day) {
            $isWorking = $day >= 1 && $day <= 5;
            $days[$day] = [
                'day_of_week' => $day,
                'is_working_day' => $isWorking ? 1 : 0,
                'start_time' => $isWorking ? '09:00' : '',
                'end_time' => $isWorking ? '17:00' : '',
                'is_overnight' => 0,
                'break_start_time' => '',
                'break_end_time' => '',
                'break_minutes' => 0,
                'grace_minutes' => $isWorking ? 30 : 0,
                'expected_minutes' => $isWorking ? 480 : 0,
            ];
        }

        return $days;
    }

    public function prepareForForm(array $template): array
    {
        $days = $this->defaultDays();
        foreach (($template['days'] ?? []) as $day) {
            $dayOfWeek = (int) ($day['day_of_week'] ?? 0);
            if (!in_array($dayOfWeek, self::WEEKDAYS, true)) {
                continue;
            }
            $days[$dayOfWeek] = [
                'day_of_week' => $dayOfWeek,
                'is_working_day' => (int) ($day['is_working_day'] ?? 0),
                'start_time' => $this->shortTime($day['start_time'] ?? ''),
                'end_time' => $this->shortTime($day['end_time'] ?? ''),
                'is_overnight' => (int) ($day['is_overnight'] ?? 0),
                'break_start_time' => $this->shortTime($day['break_start_time'] ?? ''),
                'break_end_time' => $this->shortTime($day['break_end_time'] ?? ''),
                'break_minutes' => (int) ($day['break_minutes'] ?? 0),
                'grace_minutes' => (int) ($day['grace_minutes'] ?? 0),
                'expected_minutes' => (int) ($day['expected_minutes'] ?? 0),
            ];
        }
        $template['days'] = $days;
        $template['timezone'] = (string) ($template['timezone'] ?? '');
        $template['effective_to'] = (string) ($template['effective_to'] ?? '');
        $template['change_reason'] = (string) ($template['change_reason'] ?? '');

        return $template;
    }

    public function listWithSummaries(): array
    {
        return array_map(fn (array $template): array => $this->withOperationalContext($template), $this->templates->findAll());
    }

    /** @return array<string, mixed>|null */
    public function findWorkspacePolicy(?int $id): ?array
    {
        $template = $id !== null && $id > 0 ? $this->templates->findWithDays($id) : null;
        if ($template === null) {
            $templates = $this->listWithSummaries();
            $fallbackId = (int) ($templates[0]['id'] ?? 0);
            $template = $fallbackId > 0 ? $this->templates->findWithDays($fallbackId) : null;
            if ($template === null) {
                return null;
            }
        }

        return $this->withOperationalContext($this->prepareForForm($template));
    }

    /** @return array<string, mixed> */
    public function overviewData(array $schedules, ?array $profile, ?array $overtimeResult): array
    {
        $current = null;
        $upcoming = null;
        foreach ($schedules as $schedule) {
            if ($current === null && (string) ($schedule['lifecycle_status'] ?? '') === 'active') {
                $current = $schedule;
            }
            if ((string) ($schedule['lifecycle_status'] ?? '') === 'scheduled' && (string) ($schedule['effective_from'] ?? '') > date('Y-m-d')) {
                $upcoming = $schedule;
                break;
            }
        }
        $current ??= $schedules[0] ?? null;

        return [
            'current' => $current,
            'upcoming' => $upcoming,
            'profile' => $profile,
            'department_overrides' => $this->assignmentCount('department'),
            'employee_overrides' => $this->assignmentCount('employee'),
            'pending_overtime_count' => (int) ($overtimeResult['total_records'] ?? 0),
        ];
    }

    public function attendanceRules(int $templateId): array
    {
        $this->ensurePolicySettings($templateId);
        $statement = $this->connection->prepare('SELECT * FROM attendance_policy_settings WHERE work_schedule_template_id = :template_id LIMIT 1');
        $statement->execute([':template_id' => $templateId]);
        $row = $statement->fetch();

        return $row === false ? [] : $row;
    }

    public function overtimeRules(int $templateId): array
    {
        $this->ensurePolicySettings($templateId);
        $statement = $this->connection->prepare('SELECT * FROM overtime_policy_settings WHERE work_schedule_template_id = :template_id LIMIT 1');
        $statement->execute([':template_id' => $templateId]);
        $row = $statement->fetch();

        return $row === false ? [] : $row;
    }

    /** @param array<string, mixed> $input @return array{ok: bool, errors: array<string, list<string>>, data: array<string, mixed>, id?: int} */
    public function create(array $input, ?int $actorUserId, array $auditContext = []): array
    {
        $data = $this->normalizeInput($input);
        $errors = $this->validate($data);
        if ($errors === [] && (int) $data['is_active'] === 1 && $this->templates->existsActiveByName((string) $data['name'])) {
            $errors['name'][] = $this->t('work_schedules.validation.name_exists');
        }

        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors, 'data' => $data];
        }

        $this->connection->beginTransaction();
        try {
            $data['created_by'] = $actorUserId;
            $data['updated_by'] = $actorUserId;
            $id = $this->templates->create($data, array_values($data['days']));
            $this->ensurePolicySettings($id);
            $this->auditLog?->record($actorUserId, 'work_schedule.created', 'work_schedule_template', $id, 'Work schedule created',
                null, $this->auditTemplate($data), $auditContext);
            $this->connection->commit();
        } catch (Throwable $exception) {
            $this->connection->rollBack();
            throw $exception;
        }

        return ['ok' => true, 'errors' => [], 'data' => $data, 'id' => $id];
    }

    /** @param array<string, mixed> $input @return array{ok: bool, errors: array<string, list<string>>, data: array<string, mixed>} */
    public function update(int $id, array $input, ?int $actorUserId, array $auditContext = []): array
    {
        $current = $this->templates->findWithDays($id);
        if ($current === null) {
            return ['ok' => false, 'errors' => ['form' => [$this->t('work_schedules.validation.not_found')]], 'data' => $this->normalizeInput($input)];
        }
        if (!$this->isEditable($current)) {
            return ['ok' => false, 'errors' => ['form' => [$this->t('work_schedules.validation.locked_policy')]], 'data' => $this->prepareForForm($current)];
        }

        $data = $this->normalizeInput($input, $current);
        $errors = $this->validate($data);
        if ($errors === [] && (int) $data['is_active'] === 1 && $this->templates->existsActiveByName((string) $data['name'], $id)) {
            $errors['name'][] = $this->t('work_schedules.validation.name_exists');
        }
        if ($errors === [] && (int) ($current['is_company_default'] ?? 0) === 1 && (int) $data['is_active'] !== 1) {
            $errors['is_active'][] = $this->t('work_schedules.validation.default_cannot_be_inactive');
        }

        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors, 'data' => $data];
        }

        $this->connection->beginTransaction();
        try {
            $data['updated_by'] = $actorUserId;
            $this->templates->update($id, $data, array_values($data['days']));
            $this->ensurePolicySettings($id);
            $this->auditLog?->record($actorUserId, 'work_schedule.updated', 'work_schedule_template', $id, 'Work schedule updated',
                $this->auditTemplate($this->prepareForForm($current)), $this->auditTemplate($data), $auditContext);
            $this->connection->commit();
        } catch (Throwable $exception) {
            $this->connection->rollBack();
            throw $exception;
        }

        return ['ok' => true, 'errors' => [], 'data' => $data];
    }

    /** @return array{ok: bool, message: string} */
    public function toggleStatus(int $id, bool $active, ?int $actorUserId, array $auditContext = []): array
    {
        $current = $this->templates->findWithDays($id);
        if ($current === null) {
            return ['ok' => false, 'message' => $this->t('work_schedules.validation.not_found')];
        }

        if (!$active && $this->templates->isCompanyDefault($id)) {
            return ['ok' => false, 'message' => $this->t('work_schedules.validation.default_cannot_deactivate')];
        }

        $this->connection->beginTransaction();
        try {
            $this->templates->setActive($id, $active, $actorUserId);
            $this->auditLog?->record($actorUserId, $active ? 'work_schedule.activated' : 'work_schedule.deactivated',
                'work_schedule_template', $id, $active ? 'Work schedule activated' : 'Work schedule deactivated',
                ['is_active' => (int) ($current['is_active'] ?? 0)], ['is_active' => $active ? 1 : 0], $auditContext);
            $this->connection->commit();
        } catch (Throwable $exception) {
            $this->connection->rollBack();
            throw $exception;
        }

        return ['ok' => true, 'message' => $active ? $this->t('flash.work_schedule_activated') : $this->t('flash.work_schedule_deactivated')];
    }

    /** @param array<string, mixed> $input @param array<string, mixed>|null $fallback */
    public function normalizeInput(array $input, ?array $fallback = null): array
    {
        $data = [
            'name' => mb_substr(trim((string) ($input['name'] ?? ($fallback['name'] ?? ''))), 0, 120),
            'description' => $this->nullable(mb_substr(trim((string) ($input['description'] ?? ($fallback['description'] ?? ''))), 0, 1000)),
            'timezone' => $this->nullable(trim((string) ($input['timezone'] ?? ($fallback['timezone'] ?? '')))),
            'effective_from' => trim((string) ($input['effective_from'] ?? ($fallback['effective_from'] ?? date('Y-m-d')))),
            'effective_to' => $this->nullable(trim((string) ($input['effective_to'] ?? ($fallback['effective_to'] ?? '')))),
            'is_active' => isset($input['is_active']) ? 1 : 0,
            'lifecycle_status' => (string) ($fallback['lifecycle_status'] ?? (isset($input['lifecycle_status']) ? (string) $input['lifecycle_status'] : 'draft')),
            'version_number' => (int) ($fallback['version_number'] ?? 1),
            'change_reason' => $this->nullable(mb_substr(trim((string) ($input['change_reason'] ?? ($fallback['change_reason'] ?? ''))), 0, 1000)),
            'created_from_template_id' => $fallback['created_from_template_id'] ?? null,
            'days' => $this->normalizeDays(is_array($input['days'] ?? null) ? $input['days'] : ($fallback['days'] ?? [])),
        ];

        if ($fallback === null && $input === []) {
            $data['is_active'] = 1;
        }

        return $data;
    }

    /** @return array{ok: bool, errors: array<string, list<string>>, data: array<string, mixed>, id?: int} */
    public function cloneAsDraft(array $input, ?int $actorUserId, array $auditContext = []): array
    {
        $sourceMode = (string) ($input['source_mode'] ?? ((int) ($input['source_policy_id'] ?? 0) > 0 ? 'existing' : 'current'));
        $sourceId = (int) ($input['source_policy_id'] ?? 0);
        $source = null;
        if ($sourceMode === 'blank') {
            $data = $this->defaultTemplateData();
        } else {
            if ($sourceMode === 'current' && $sourceId <= 0) {
                $sourceId = (int) ($this->currentPolicy()['id'] ?? 0);
            }
            $source = $sourceId > 0 ? $this->templates->findWithDays($sourceId) : null;
            if ($source === null) {
                return ['ok' => false, 'errors' => ['source_policy_id' => [$this->t('work_schedules.validation.not_found')]], 'data' => $input];
            }
            $data = $this->prepareForForm($source);
        }

        $data['name'] = mb_substr(trim((string) ($input['name'] ?? '')), 0, 120);
        $data['timezone'] = $this->nullable((string) ($data['timezone'] ?? ''));
        $data['effective_from'] = trim((string) ($input['effective_from'] ?? date('Y-m-d')));
        $data['effective_to'] = null;
        $data['is_active'] = 0;
        $data['lifecycle_status'] = 'draft';
        $data['version_number'] = $this->nextVersionNumber();
        $data['created_from_template_id'] = $sourceId > 0 ? $sourceId : null;
        $data['change_reason'] = $this->nullable(mb_substr(trim((string) ($input['change_reason'] ?? '')), 0, 1000));
        $errors = $this->validate($data);
        if ($errors === [] && $this->templates->existsByName((string) $data['name'])) {
            $errors['name'][] = $this->t('work_schedules.validation.name_exists');
        }
        if ($errors === [] && (string) $data['effective_from'] <= date('Y-m-d')) {
            $errors['effective_from'][] = $this->t('work_schedules.validation.schedule_date_future');
        }
        if ($data['change_reason'] === null) {
            $errors['change_reason'][] = $this->t('work_schedules.validation.change_reason_required');
        }

        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors, 'data' => array_replace($input, $data)];
        }

        $this->connection->beginTransaction();
        try {
            $data['created_by'] = $actorUserId;
            $data['updated_by'] = $actorUserId;
            $id = $this->templates->create($data, array_values($data['days']));
            if ($sourceId > 0) {
                $this->copyPolicySettings($sourceId, $id);
            } else {
                $this->ensurePolicySettings($id);
            }
            $this->auditLog?->record($actorUserId, 'work_schedule.cloned', 'work_schedule_template', $id, 'Work schedule cloned as draft',
                ['source_policy_id' => $sourceId > 0 ? $sourceId : null, 'source_mode' => $sourceMode], $this->auditTemplate($data), $auditContext);
            $this->connection->commit();
        } catch (Throwable $exception) {
            $this->connection->rollBack();
            throw $exception;
        }

        return ['ok' => true, 'errors' => [], 'data' => $data, 'id' => $id];
    }

    /** @return array{ok: bool, errors: array<string, list<string>>, data: array<string, mixed>} */
    public function updateWeeklySchedule(int $id, array $input, ?int $actorUserId, array $auditContext = []): array
    {
        $current = $this->templates->findWithDays($id);
        if ($current === null) {
            return ['ok' => false, 'errors' => ['form' => [$this->t('work_schedules.validation.not_found')]], 'data' => $this->normalizeInput($input)];
        }
        if (!$this->isEditable($current)) {
            return ['ok' => false, 'errors' => ['form' => [$this->t('work_schedules.validation.locked_policy')]], 'data' => $this->prepareForForm($current)];
        }

        $data = $this->normalizeInput(array_replace($this->prepareForForm($current), ['days' => $input['days'] ?? []]), $current);
        $errors = $this->validate($data);
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors, 'data' => $data];
        }

        return $this->saveTemplateUpdate($id, $current, $data, $actorUserId, $auditContext, 'work_schedule.weekly_schedule_updated', 'Weekly schedule updated');
    }

    /** @return array{ok: bool, errors: array<string, list<string>>, data: array<string, mixed>} */
    public function updateAttendanceRules(int $id, array $input, ?int $actorUserId, array $auditContext = []): array
    {
        $current = $this->templates->findWithDays($id);
        $data = $this->normalizeAttendanceRules($input);
        if ($current === null) {
            return ['ok' => false, 'errors' => ['form' => [$this->t('work_schedules.validation.not_found')]], 'data' => $data];
        }
        if (!$this->isEditable($current)) {
            return ['ok' => false, 'errors' => ['form' => [$this->t('work_schedules.validation.locked_policy')]], 'data' => $this->attendanceRules($id)];
        }

        $errors = [];
        if (!in_array((string) $data['missing_check_out_mode'], self::MISSING_CHECK_OUT_MODES, true)) {
            $errors['missing_check_out_mode'][] = $this->t('work_schedules.validation.missing_mode_invalid');
        }
        if ((int) $data['maximum_attendance_minutes'] < 1 || (int) $data['maximum_attendance_minutes'] > self::MAX_MINUTES) {
            $errors['maximum_attendance_minutes'][] = $this->t('work_schedules.validation.maximum_attendance_invalid');
        }
        if ((int) $data['early_departure_grace_minutes'] < 0 || (int) $data['early_departure_grace_minutes'] > self::MAX_GRACE_MINUTES) {
            $errors['early_departure_grace_minutes'][] = $this->t('work_schedules.validation.early_departure_grace_invalid');
        }
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors, 'data' => $data];
        }

        $before = $this->attendanceRules($id);
        $this->connection->beginTransaction();
        try {
            $statement = $this->connection->prepare(
                'UPDATE attendance_policy_settings
                 SET allow_non_working_day_check_in = :allow_non_working_day_check_in,
                     allow_holiday_check_in = :allow_holiday_check_in,
                     allow_overnight_attendance = :allow_overnight_attendance,
                     maximum_attendance_minutes = :maximum_attendance_minutes,
                      missing_check_out_mode = :missing_check_out_mode,
                      early_departure_enabled = :early_departure_enabled,
                      early_departure_grace_minutes = :early_departure_grace_minutes,
                      manual_override_allowed = :manual_override_allowed,
                     override_reason_required = :override_reason_required,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE work_schedule_template_id = :template_id'
            );
            $statement->execute(array_merge($data, ['template_id' => $id]));
            $this->auditLog?->record($actorUserId, 'work_schedule.attendance_rules_updated', 'work_schedule_template', $id, 'Attendance rules updated',
                $before, $data, $auditContext);
            $this->connection->commit();
        } catch (Throwable $exception) {
            $this->connection->rollBack();
            throw $exception;
        }

        return ['ok' => true, 'errors' => [], 'data' => $data];
    }

    /** @return array{ok: bool, errors: array<string, list<string>>, data: array<string, mixed>} */
    public function updateOvertimeRules(int $id, array $input, ?int $actorUserId, array $auditContext = []): array
    {
        $current = $this->templates->findWithDays($id);
        $data = $this->normalizeOvertimeRules($input);
        if ($current === null) {
            return ['ok' => false, 'errors' => ['form' => [$this->t('work_schedules.validation.not_found')]], 'data' => $data];
        }
        if (!$this->isEditable($current)) {
            return ['ok' => false, 'errors' => ['form' => [$this->t('work_schedules.validation.locked_policy')]], 'data' => $this->overtimeRules($id)];
        }

        $errors = [];
        if (!in_array((string) $data['rounding_mode'], self::ROUNDING_MODES, true)) {
            $errors['rounding_mode'][] = $this->t('work_schedules.validation.rounding_mode_invalid');
        }
        if ($data['rounding_mode'] !== 'exact' && !in_array((int) $data['rounding_interval_minutes'], self::ROUNDING_INTERVALS, true)) {
            $errors['rounding_interval_minutes'][] = $this->t('work_schedules.validation.rounding_interval_invalid');
        }
        if ($data['rounding_mode'] === 'exact') {
            $data['rounding_interval_minutes'] = null;
        }
        foreach (['minimum_overtime_minutes', 'special_day_break_threshold_minutes', 'special_day_break_minutes'] as $field) {
            if ($data[$field] !== null && ((int) $data[$field] < 0 || (int) $data[$field] > self::MAX_MINUTES)) {
                $errors[$field][] = $this->t('work_schedules.validation.minutes_invalid');
            }
        }
        if ((int) $data['approval_required'] !== 1) {
            $data['allow_approval_above_calculated'] = 0;
        }
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors, 'data' => $data];
        }

        $before = $this->overtimeRules($id);
        $this->connection->beginTransaction();
        try {
            $statement = $this->connection->prepare(
                'UPDATE overtime_policy_settings
                 SET early_work_eligible = :early_work_eligible,
                     after_hours_work_eligible = :after_hours_work_eligible,
                     non_working_day_work_eligible = :non_working_day_work_eligible,
                     holiday_work_eligible = :holiday_work_eligible,
                     approval_required = :approval_required,
                     minimum_overtime_minutes = :minimum_overtime_minutes,
                     rounding_mode = :rounding_mode,
                     rounding_interval_minutes = :rounding_interval_minutes,
                     special_day_break_threshold_minutes = :special_day_break_threshold_minutes,
                     special_day_break_minutes = :special_day_break_minutes,
                     allow_admin_adjustment = :allow_admin_adjustment,
                     allow_approval_above_calculated = :allow_approval_above_calculated,
                     reason_required = :reason_required,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE work_schedule_template_id = :template_id'
            );
            $statement->execute(array_merge($data, ['template_id' => $id]));
            $this->auditLog?->record($actorUserId, 'work_schedule.overtime_rules_updated', 'work_schedule_template', $id, 'Overtime rules updated',
                $before, $data, $auditContext);
            $this->connection->commit();
        } catch (Throwable $exception) {
            $this->connection->rollBack();
            throw $exception;
        }

        return ['ok' => true, 'errors' => [], 'data' => $data];
    }

    public function scheduleVersion(int $id, string $effectiveFrom, ?int $actorUserId, array $auditContext = []): array
    {
        return $this->setLifecycle($id, 'scheduled', $effectiveFrom, null, $actorUserId, $auditContext);
    }

    public function archiveVersion(int $id, ?int $actorUserId, array $auditContext = []): array
    {
        return $this->setLifecycle($id, 'archived', null, null, $actorUserId, $auditContext);
    }

    public function cancelSchedule(int $id, ?int $actorUserId, array $auditContext = []): array
    {
        return $this->setLifecycle($id, 'draft', null, null, $actorUserId, $auditContext);
    }

    public function isEditable(array $template): bool
    {
        $state = (string) ($template['lifecycle_status'] ?? 'active');
        if ($state === 'draft') {
            return true;
        }

        return $state === 'scheduled' && (string) ($template['effective_from'] ?? '') > date('Y-m-d');
    }

    /** @param array<string|int, mixed> $inputDays @return array<int, array<string, mixed>> */
    private function normalizeDays(array $inputDays): array
    {
        $days = [];
        foreach (self::WEEKDAYS as $dayOfWeek) {
            $day = is_array($inputDays[$dayOfWeek] ?? null) ? $inputDays[$dayOfWeek] : [];
            $isMissing = !isset($day['day_present']) && $inputDays !== [];
            $isWorking = isset($day['is_working_day']) && (string) $day['is_working_day'] !== '0';
            $days[$dayOfWeek] = [
                'day_of_week' => $dayOfWeek,
                '_missing' => $isMissing,
                'is_working_day' => $isWorking ? 1 : 0,
                'start_time' => $isWorking ? trim((string) ($day['start_time'] ?? '')) : null,
                'end_time' => $isWorking ? trim((string) ($day['end_time'] ?? '')) : null,
                'is_overnight' => $isWorking && isset($day['is_overnight']) && (string) $day['is_overnight'] !== '0' ? 1 : 0,
                'break_start_time' => $isWorking ? $this->nullable(trim((string) ($day['break_start_time'] ?? ''))) : null,
                'break_end_time' => $isWorking ? $this->nullable(trim((string) ($day['break_end_time'] ?? ''))) : null,
                'break_minutes' => $this->intValue($day['break_minutes'] ?? 0),
                'grace_minutes' => $this->intValue($day['grace_minutes'] ?? 0),
                'expected_minutes' => $this->intValue($day['expected_minutes'] ?? 0),
            ];
        }

        return $days;
    }

    /** @param array<string, mixed> $data @return array<string, list<string>> */
    private function validate(array &$data): array
    {
        $errors = [];

        if ($data['name'] === '') {
            $errors['name'][] = $this->t('work_schedules.validation.name_required');
        }

        if ($data['effective_from'] === '' || !$this->dateValidator->isValidDate((string) $data['effective_from'])) {
            $errors['effective_from'][] = $this->t('work_schedules.validation.effective_from_invalid');
        }

        if ($data['effective_to'] !== null && !$this->dateValidator->isValidDate((string) $data['effective_to'])) {
            $errors['effective_to'][] = $this->t('work_schedules.validation.effective_to_invalid');
        }

        if ($data['effective_to'] !== null && $data['effective_from'] !== '' && $data['effective_to'] < $data['effective_from']) {
            $errors['effective_to'][] = $this->t('work_schedules.validation.effective_to_before_from');
        }

        if ($data['timezone'] !== null && !in_array($data['timezone'], DateTimeZone::listIdentifiers(), true)) {
            $errors['timezone'][] = $this->t('work_schedules.validation.timezone_invalid');
        }

        if (count($data['days']) !== 7 || array_keys($data['days']) !== self::WEEKDAYS) {
            $errors['days'][] = $this->t('work_schedules.validation.seven_days_required');
        }

        $workingDayCount = 0;
        foreach (self::WEEKDAYS as $dayOfWeek) {
            $day = $data['days'][$dayOfWeek];
            if (!empty($day['_missing'])) {
                $errors['days'][] = $this->t('work_schedules.validation.seven_days_required');
                continue;
            }
            if ((int) $day['is_working_day'] !== 1) {
                $data['days'][$dayOfWeek]['start_time'] = null;
                $data['days'][$dayOfWeek]['end_time'] = null;
                $data['days'][$dayOfWeek]['is_overnight'] = 0;
                $data['days'][$dayOfWeek]['break_start_time'] = null;
                $data['days'][$dayOfWeek]['break_end_time'] = null;
                $data['days'][$dayOfWeek]['break_minutes'] = 0;
                $data['days'][$dayOfWeek]['grace_minutes'] = 0;
                $data['days'][$dayOfWeek]['expected_minutes'] = 0;
                continue;
            }

            $workingDayCount++;
            $start = $this->dateValidator->normalizeTime((string) $day['start_time']);
            $end = $this->dateValidator->normalizeTime((string) $day['end_time']);
            if ($start === null) {
                $errors['days.' . $dayOfWeek . '.start_time'][] = $this->t('work_schedules.validation.start_time_required');
            }
            if ($end === null) {
                $errors['days.' . $dayOfWeek . '.end_time'][] = $this->t('work_schedules.validation.end_time_required');
            }
            if ($start !== null && $end !== null) {
                $duration = $this->durationMinutes($start, $end, (int) $day['is_overnight'] === 1);
                if ($duration <= 0) {
                    $errors['days.' . $dayOfWeek . '.end_time'][] = $this->t('work_schedules.validation.overnight_required');
                }
                $paidDuration = $duration - max(0, (int) $day['break_minutes']);
                if ((int) $day['expected_minutes'] > $paidDuration) {
                    $errors['days.' . $dayOfWeek . '.expected_minutes'][] = $this->t('work_schedules.validation.expected_exceeds_duration');
                }
                if ((int) $day['break_minutes'] >= $duration) {
                    $errors['days.' . $dayOfWeek . '.break_minutes'][] = $this->t('work_schedules.validation.break_exceeds_duration');
                }
                $data['days'][$dayOfWeek]['start_time'] = $start;
                $data['days'][$dayOfWeek]['end_time'] = $end;

                $breakStart = $day['break_start_time'] !== null ? $this->dateValidator->normalizeTime((string) $day['break_start_time']) : null;
                $breakEnd = $day['break_end_time'] !== null ? $this->dateValidator->normalizeTime((string) $day['break_end_time']) : null;
                if (($breakStart === null) !== ($breakEnd === null)) {
                    $errors['days.' . $dayOfWeek . '.break_start_time'][] = $this->t('work_schedules.validation.break_pair_required');
                }
                if ($breakStart !== null && $breakEnd !== null) {
                    $breakDuration = $this->durationMinutes($breakStart, $breakEnd, (int) $day['is_overnight'] === 1 && $breakEnd <= $breakStart);
                    if ($breakDuration <= 0 || $breakDuration > $duration) {
                        $errors['days.' . $dayOfWeek . '.break_minutes'][] = $this->t('work_schedules.validation.break_exceeds_duration');
                    } elseif ((int) $day['break_minutes'] !== $breakDuration) {
                        $errors['days.' . $dayOfWeek . '.break_minutes'][] = $this->t('work_schedules.validation.break_minutes_mismatch');
                    }
                    $data['days'][$dayOfWeek]['break_start_time'] = $breakStart;
                    $data['days'][$dayOfWeek]['break_end_time'] = $breakEnd;
                }
            }

            if ((int) $day['break_minutes'] < 0 || (int) $day['break_minutes'] > self::MAX_BREAK_MINUTES) {
                $errors['days.' . $dayOfWeek . '.break_minutes'][] = $this->t('work_schedules.validation.break_invalid');
            }
            if ((int) $day['grace_minutes'] < 0 || (int) $day['grace_minutes'] > self::MAX_GRACE_MINUTES) {
                $errors['days.' . $dayOfWeek . '.grace_minutes'][] = $this->t('work_schedules.validation.grace_invalid');
            }
            if ((int) $day['expected_minutes'] <= 0 || (int) $day['expected_minutes'] > self::MAX_MINUTES) {
                $errors['days.' . $dayOfWeek . '.expected_minutes'][] = $this->t('work_schedules.validation.expected_invalid');
            }
        }

        if ($workingDayCount === 0) {
            $errors['days'][] = $this->t('work_schedules.validation.working_day_required');
        }

        return $errors;
    }

    public function withSummary(array $template): array
    {
        $template['working_days_summary'] = $this->workingDaysSummary($template['days'] ?? []);
        $template['daily_time_summary'] = $this->dailyTimeSummary($template['days'] ?? []);
        $template['weekly_preview'] = $this->weeklyPreview($template['days'] ?? []);

        return $template;
    }

    public function withOperationalContext(array $template): array
    {
        $template = $this->withSummary($template);
        $template['is_editable_policy'] = $this->isEditable($template);
        $template['assignment_count'] = (int) ($template['assignment_count'] ?? 0);
        $template['lifecycle_status'] = (string) ($template['lifecycle_status'] ?? ((int) ($template['is_active'] ?? 0) === 1 ? 'active' : 'draft'));

        return $template;
    }

    private function workingDaysSummary(array $days): string
    {
        $working = array_values(array_filter($days, static fn (array $day): bool => (int) ($day['is_working_day'] ?? 0) === 1));
        if ($working === []) {
            return '—';
        }

        $numbers = array_map(static fn (array $day): int => (int) $day['day_of_week'], $working);
        if ($numbers === [1, 2, 3, 4, 5]) {
            return $this->t('work_schedules.preview.monday_friday');
        }
        if ($numbers === [1, 2, 3, 4, 5, 6]) {
            return $this->t('work_schedules.preview.monday_saturday');
        }
        if ($numbers === [1, 2, 3, 4, 7]) {
            return $this->t('work_schedules.preview.sunday_thursday');
        }

        return implode(', ', array_map(fn (int $day): string => $this->t('work_schedules.weekdays_short.' . $day), $numbers));
    }

    private function dailyTimeSummary(array $days): string
    {
        $working = array_values(array_filter($days, static fn (array $day): bool => (int) ($day['is_working_day'] ?? 0) === 1));
        if ($working === []) {
            return '—';
        }

        $first = $working[0];
        $start = $this->shortTime($first['start_time'] ?? '');
        $end = $this->shortTime($first['end_time'] ?? '');
        if ($start === '' || $end === '') {
            return '—';
        }

        $suffix = (int) ($first['is_overnight'] ?? 0) === 1 ? ' ' . $this->t('work_schedules.overnight_short') : '';

        return $start . '-' . $end . $suffix;
    }

    private function weeklyPreview(array $days): string
    {
        $working = array_values(array_filter($days, static fn (array $day): bool => (int) ($day['is_working_day'] ?? 0) === 1));
        if ($working === []) {
            return $this->t('work_schedules.preview.no_working_days');
        }

        $first = $working[0];

        return $this->workingDaysSummary($days) . "\n"
            . $this->dailyTimeSummary($days) . "\n"
            . (int) ($first['break_minutes'] ?? 0) . ' ' . $this->t('work_schedules.preview.break_minutes') . "\n"
            . (int) ($first['grace_minutes'] ?? 0) . ' ' . $this->t('work_schedules.preview.grace_minutes');
    }

    /** @return array{ok: bool, errors: array<string, list<string>>, data: array<string, mixed>} */
    private function saveTemplateUpdate(int $id, array $current, array $data, ?int $actorUserId, array $auditContext, string $action, string $description): array
    {
        $this->connection->beginTransaction();
        try {
            $data['updated_by'] = $actorUserId;
            $this->templates->update($id, $data, array_values($data['days']));
            $this->ensurePolicySettings($id);
            $this->auditLog?->record($actorUserId, $action, 'work_schedule_template', $id, $description,
                $this->auditTemplate($this->prepareForForm($current)), $this->auditTemplate($data), $auditContext);
            $this->connection->commit();
        } catch (Throwable $exception) {
            $this->connection->rollBack();
            throw $exception;
        }

        return ['ok' => true, 'errors' => [], 'data' => $data];
    }

    /** @return array<string, mixed> */
    private function normalizeAttendanceRules(array $input): array
    {
        return [
            'allow_non_working_day_check_in' => isset($input['allow_non_working_day_check_in']) ? 1 : 0,
            'allow_holiday_check_in' => isset($input['allow_holiday_check_in']) ? 1 : 0,
            'allow_overnight_attendance' => isset($input['allow_overnight_attendance']) ? 1 : 0,
            'maximum_attendance_minutes' => $this->intValue($input['maximum_attendance_minutes'] ?? 960),
            'missing_check_out_mode' => (string) ($input['missing_check_out_mode'] ?? 'mark_incomplete'),
            'early_departure_enabled' => isset($input['early_departure_enabled']) ? 1 : 0,
            'early_departure_grace_minutes' => $this->intValue($input['early_departure_grace_minutes'] ?? 0),
            'manual_override_allowed' => isset($input['manual_override_allowed']) ? 1 : 0,
            'override_reason_required' => isset($input['override_reason_required']) ? 1 : 0,
        ];
    }

    /** @return array<string, mixed> */
    private function normalizeOvertimeRules(array $input): array
    {
        $nullableInt = function (mixed $value): ?int {
            $value = trim((string) $value);
            return $value === '' ? null : $this->intValue($value);
        };

        return [
            'early_work_eligible' => isset($input['early_work_eligible']) ? 1 : 0,
            'after_hours_work_eligible' => isset($input['after_hours_work_eligible']) ? 1 : 0,
            'non_working_day_work_eligible' => isset($input['non_working_day_work_eligible']) ? 1 : 0,
            'holiday_work_eligible' => isset($input['holiday_work_eligible']) ? 1 : 0,
            'approval_required' => isset($input['approval_required']) ? 1 : 0,
            'minimum_overtime_minutes' => $this->intValue($input['minimum_overtime_minutes'] ?? 0),
            'rounding_mode' => (string) ($input['rounding_mode'] ?? 'exact'),
            'rounding_interval_minutes' => $nullableInt($input['rounding_interval_minutes'] ?? ''),
            'special_day_break_threshold_minutes' => $nullableInt($input['special_day_break_threshold_minutes'] ?? ''),
            'special_day_break_minutes' => $this->intValue($input['special_day_break_minutes'] ?? 0),
            'allow_admin_adjustment' => isset($input['allow_admin_adjustment']) ? 1 : 0,
            'allow_approval_above_calculated' => isset($input['allow_approval_above_calculated']) ? 1 : 0,
            'reason_required' => isset($input['reason_required']) ? 1 : 0,
        ];
    }

    /** @return array{ok: bool, message: string} */
    private function setLifecycle(int $id, string $state, ?string $effectiveFrom, ?string $effectiveTo, ?int $actorUserId, array $auditContext): array
    {
        $current = $this->templates->findWithDays($id);
        if ($current === null) {
            return ['ok' => false, 'message' => $this->t('work_schedules.validation.not_found')];
        }
        if ((string) ($current['lifecycle_status'] ?? '') === 'active' && $state !== 'archived') {
            return ['ok' => false, 'message' => $this->t('work_schedules.validation.locked_policy')];
        }
        if ($state === 'scheduled' && ($effectiveFrom === null || !$this->dateValidator->isValidDate($effectiveFrom) || $effectiveFrom <= date('Y-m-d'))) {
            return ['ok' => false, 'message' => $this->t('work_schedules.validation.schedule_date_future')];
        }

        $this->connection->beginTransaction();
        try {
            $params = [
                ':lifecycle_status' => $state,
                ':is_active' => $state === 'active' ? 1 : 0,
                ':locked_at' => in_array($state, ['active', 'archived'], true) ? date('Y-m-d H:i:s') : null,
                ':locked_by' => in_array($state, ['active', 'archived'], true) ? $actorUserId : null,
                ':updated_by' => $actorUserId,
                ':id' => $id,
            ];
            $sql = 'UPDATE work_schedule_templates
                    SET lifecycle_status = :lifecycle_status,
                        is_active = :is_active,
                        locked_at = :locked_at,
                        locked_by = :locked_by,
                        updated_by = :updated_by,
                        updated_at = CURRENT_TIMESTAMP';
            if ($effectiveFrom !== null) {
                $sql .= ', effective_from = :effective_from';
                $params[':effective_from'] = $effectiveFrom;
            }
            if ($effectiveTo !== null) {
                $sql .= ', effective_to = :effective_to';
                $params[':effective_to'] = $effectiveTo;
            }
            $sql .= ' WHERE id = :id';
            $this->connection->prepare($sql)->execute($params);
            $this->auditLog?->record($actorUserId, 'work_schedule.lifecycle_' . $state, 'work_schedule_template', $id, 'Work schedule lifecycle changed',
                ['lifecycle_status' => $current['lifecycle_status'] ?? null, 'effective_from' => $current['effective_from'] ?? null],
                ['lifecycle_status' => $state, 'effective_from' => $effectiveFrom ?? ($current['effective_from'] ?? null)], $auditContext);
            $this->connection->commit();
        } catch (Throwable $exception) {
            $this->connection->rollBack();
            throw $exception;
        }

        return ['ok' => true, 'message' => $this->t('flash.work_schedule_lifecycle_updated')];
    }

    private function copyPolicySettings(int $sourceId, int $targetId): void
    {
        $this->connection->prepare(
            'INSERT INTO attendance_policy_settings
             (work_schedule_template_id, allow_non_working_day_check_in, allow_holiday_check_in, allow_overnight_attendance, maximum_attendance_minutes, missing_check_out_mode, early_departure_enabled, early_departure_grace_minutes, manual_override_allowed, override_reason_required)
             SELECT :target_id, allow_non_working_day_check_in, allow_holiday_check_in, allow_overnight_attendance, maximum_attendance_minutes, missing_check_out_mode, early_departure_enabled, early_departure_grace_minutes, manual_override_allowed, override_reason_required
             FROM attendance_policy_settings WHERE work_schedule_template_id = :source_id'
        )->execute([':target_id' => $targetId, ':source_id' => $sourceId]);

        $this->connection->prepare(
            'INSERT INTO overtime_policy_settings
             (work_schedule_template_id, early_work_eligible, after_hours_work_eligible, non_working_day_work_eligible, holiday_work_eligible, approval_required, minimum_overtime_minutes, rounding_mode, rounding_interval_minutes, special_day_break_threshold_minutes, special_day_break_minutes, allow_admin_adjustment, allow_approval_above_calculated, reason_required)
             SELECT :target_id, early_work_eligible, after_hours_work_eligible, non_working_day_work_eligible, holiday_work_eligible, approval_required, minimum_overtime_minutes, rounding_mode, rounding_interval_minutes, special_day_break_threshold_minutes, special_day_break_minutes, allow_admin_adjustment, allow_approval_above_calculated, reason_required
             FROM overtime_policy_settings WHERE work_schedule_template_id = :source_id'
        )->execute([':target_id' => $targetId, ':source_id' => $sourceId]);

        $this->ensurePolicySettings($targetId);
    }

    private function nextVersionNumber(): int
    {
        return ((int) $this->connection->query('SELECT COALESCE(MAX(version_number), 0) + 1 FROM work_schedule_templates')->fetchColumn());
    }

    /** @return array<string, mixed>|null */
    private function currentPolicy(): ?array
    {
        foreach ($this->templates->findAll() as $template) {
            if ((string) ($template['lifecycle_status'] ?? '') === 'active' || (int) ($template['is_active'] ?? 0) === 1) {
                return $template;
            }
        }

        return null;
    }

    private function assignmentCount(string $scopeType): int
    {
        $statement = $this->connection->prepare('SELECT COUNT(*) FROM work_schedule_assignments WHERE scope_type = :scope_type AND is_active = 1');
        $statement->execute([':scope_type' => $scopeType]);

        return (int) $statement->fetchColumn();
    }

    /** @param array<string, mixed> $data */
    private function auditTemplate(array $data): array
    {
        $days = array_map(static function (array $day): array {
            unset($day['_missing']);
            return $day;
        }, $data['days'] ?? []);

        return [
            'name' => $data['name'] ?? null,
            'timezone' => $data['timezone'] ?? null,
            'effective_from' => $data['effective_from'] ?? null,
            'effective_to' => $data['effective_to'] ?? null,
            'is_active' => (int) ($data['is_active'] ?? 0),
            'days' => $days,
        ];
    }

    private function durationMinutes(string $start, string $end, bool $overnight): int
    {
        [$startHour, $startMinute] = array_map('intval', explode(':', $start));
        [$endHour, $endMinute] = array_map('intval', explode(':', $end));
        $startTotal = ($startHour * 60) + $startMinute;
        $endTotal = ($endHour * 60) + $endMinute;
        if ($overnight) {
            $endTotal += 1440;
        }

        return $endTotal - $startTotal;
    }

    private function shortTime(mixed $value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        $normalized = $this->dateValidator->normalizeTime($value);

        return $normalized === null ? $value : substr($normalized, 0, 5);
    }

    private function intValue(mixed $value): int
    {
        $parsed = filter_var($value, FILTER_VALIDATE_INT);

        return $parsed === false ? -1 : (int) $parsed;
    }

    private function nullable(string $value): ?string
    {
        return $value === '' ? null : $value;
    }

    private function t(string $key): string
    {
        return Localization::current()->translate($key);
    }

    private function ensurePolicySettings(int $templateId): void
    {
        $this->connection->prepare(
            'INSERT IGNORE INTO attendance_policy_settings (work_schedule_template_id) VALUES (:template_id)'
        )->execute([':template_id' => $templateId]);

        $this->connection->prepare(
            'INSERT IGNORE INTO overtime_policy_settings (work_schedule_template_id) VALUES (:template_id)'
        )->execute([':template_id' => $templateId]);
    }
}
