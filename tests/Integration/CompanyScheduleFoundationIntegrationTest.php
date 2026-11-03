<?php

declare(strict_types=1);

use App\Core\Localization;
use App\Models\CompanyProfile;
use App\Models\WorkScheduleDay;
use App\Models\WorkScheduleTemplate;
use App\Services\AuditLogService;
use App\Services\CompanyProfileService;
use App\Services\DateValidationService;
use App\Services\WorkScheduleService;

test('company schedule foundation seed matches current attendance behavior', function (): void {
    $database = TestDatabase::connect();
    $database->resetAndSeed();
    $pdo = $database->pdo();

    assert_same(1, (int) $pdo->query('SELECT COUNT(*) FROM company_profiles')->fetchColumn());
    assert_same(1, (int) $pdo->query("SELECT COUNT(*) FROM work_schedule_templates WHERE name = 'SAPAS Standard Office' AND is_active = 1")->fetchColumn());
    assert_same(7, (int) $pdo->query('SELECT COUNT(*) FROM work_schedule_days')->fetchColumn());

    $profile = $pdo->query('SELECT company_name, short_name, timezone, date_format, time_format, default_work_schedule_id FROM company_profiles LIMIT 1')->fetch();
    assert_same('Smart Attendance And Performance Analytic System', $profile['company_name']);
    assert_same('Smart Attendance', $profile['short_name']);
    assert_same('Asia/Yangon', $profile['timezone']);
    assert_same('Y-m-d', $profile['date_format']);
    assert_same('H:i', $profile['time_format']);

    $working = $pdo->query('SELECT day_of_week, start_time, end_time, break_minutes, grace_minutes, expected_minutes FROM work_schedule_days WHERE is_working_day = 1 ORDER BY day_of_week')->fetchAll();
    assert_same([1, 2, 3, 4, 5], array_map(static fn (array $day): int => (int) $day['day_of_week'], $working));
    foreach ($working as $day) {
        assert_same('09:00:00', $day['start_time']);
        assert_same('17:00:00', $day['end_time']);
        assert_same(0, (int) $day['break_minutes']);
        assert_same(30, (int) $day['grace_minutes']);
        assert_same(480, (int) $day['expected_minutes']);
    }
    assert_same(2, (int) $pdo->query('SELECT COUNT(*) FROM work_schedule_days WHERE is_working_day = 0 AND start_time IS NULL AND end_time IS NULL')->fetchColumn());
});

test('company profile validates inputs and records audit entries', function (): void {
    $database = TestDatabase::connect();
    $ids = $database->resetAndSeed();
    Localization::setCurrent(new Localization(project_path('lang')));
    $pdo = $database->pdo();
    $service = company_schedule_profile_service($pdo);

    $invalid = $service->update([
        'company_name' => '',
        'email' => 'bad-email',
        'timezone' => 'Not/AZone',
        'date_format' => 'raw',
        'time_format' => 'raw',
        'default_work_schedule_id' => 999999,
    ], $ids['admin_id']);
    assert_false($invalid['ok']);
    foreach (['company_name', 'email', 'timezone', 'date_format', 'time_format', 'default_work_schedule_id'] as $field) {
        assert_true(isset($invalid['errors'][$field]), $field . ' should have a field-level error.');
    }

    $scheduleId = (int) $pdo->query('SELECT id FROM work_schedule_templates LIMIT 1')->fetchColumn();
    $valid = $service->update([
        'company_name' => 'Silver Oak Office Attendance',
        'short_name' => 'Silver Oak',
        'email' => 'admin@example.test',
        'phone' => '09 111 222 333',
        'address' => 'Yangon',
        'timezone' => 'Asia/Yangon',
        'date_format' => 'Y-m-d',
        'time_format' => 'H:i',
        'default_work_schedule_id' => $scheduleId,
    ], $ids['admin_id'], ['ip_address' => '127.0.0.1', 'user_agent' => 'Unit']);
    assert_true($valid['ok']);
    assert_same('Silver Oak Office Attendance', $pdo->query('SELECT company_name FROM company_profiles LIMIT 1')->fetchColumn());
    assert_same(1, (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'company_profile.updated'")->fetchColumn());
});

test('work schedule service creates valid variants and rejects unsafe configurations', function (): void {
    $database = TestDatabase::connect();
    $ids = $database->resetAndSeed();
    Localization::setCurrent(new Localization(project_path('lang')));
    $pdo = $database->pdo();
    $service = company_schedule_work_service($pdo);

    $mondaySaturday = company_schedule_payload('Customer Support', [1, 2, 3, 4, 5, 6], '08:00', '16:00', false, 45, 15, 435);
    assert_true($service->create($mondaySaturday, $ids['admin_id'])['ok']);

    $sundayThursday = company_schedule_payload('Sunday Operations', [7, 1, 2, 3, 4], '09:00', '17:00', false, 0, 30, 480);
    assert_true($service->create($sundayThursday, $ids['admin_id'])['ok']);

    $overnight = company_schedule_payload('Night Operations', [7, 1, 2, 3, 4], '21:00', '05:00', true, 60, 10, 420);
    assert_true($service->create($overnight, $ids['admin_id'])['ok']);

    $missing = $mondaySaturday;
    unset($missing['days'][3]);
    assert_false($service->create($missing, $ids['admin_id'])['ok'], 'Missing weekday rows must be rejected.');

    $noWorking = company_schedule_payload('No Work', [], '09:00', '17:00', false, 0, 0, 0);
    assert_false($service->create($noWorking, $ids['admin_id'])['ok'], 'Schedules with no working day must be rejected.');

    $badBreak = company_schedule_payload('Bad Break', [1], '09:00', '10:00', false, 90, 0, 60);
    assert_false($service->create($badBreak, $ids['admin_id'])['ok'], 'Break minutes greater than scheduled duration must be rejected.');

    $badTime = company_schedule_payload('Bad Time', [1], '17:00', '09:00', false, 0, 0, 480);
    assert_false($service->create($badTime, $ids['admin_id'])['ok'], 'Same-day end time before start time must require explicit overnight selection.');

    $badRange = company_schedule_payload('Bad Range', [1], '09:00', '17:00', false, 0, 0, 480);
    $badRange['effective_from'] = '2026-08-10';
    $badRange['effective_to'] = '2026-08-01';
    assert_false($service->create($badRange, $ids['admin_id'])['ok'], 'Effective end before start must be rejected.');

    assert_true((int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'work_schedule.created'")->fetchColumn() >= 3);
});

test('workforce policy center clones versions and edits only unlocked policy settings', function (): void {
    $database = TestDatabase::connect();
    $ids = $database->resetAndSeed();
    Localization::setCurrent(new Localization(project_path('lang')));
    $pdo = $database->pdo();
    $service = company_schedule_work_service($pdo);
    $sourceId = (int) $pdo->query('SELECT default_work_schedule_id FROM company_profiles LIMIT 1')->fetchColumn();
    $futureEffectiveDate = (new DateTimeImmutable('today'))->modify('+30 days')->format('Y-m-d');
    $futureScheduleDate = (new DateTimeImmutable('today'))->modify('+35 days')->format('Y-m-d');

    $lockedWeekly = $service->updateWeeklySchedule($sourceId, company_schedule_payload('Ignored', [1, 2, 3, 4, 5], '08:00', '16:00', false, 0, 10, 480), $ids['admin_id']);
    assert_false($lockedWeekly['ok'], 'Active policies must not be directly edited.');

    $clone = $service->cloneAsDraft([
        'source_policy_id' => $sourceId,
        'name' => 'August Office Policy',
        'effective_from' => $futureEffectiveDate,
        'change_reason' => 'Operational tab regression',
    ], $ids['admin_id']);
    assert_true($clone['ok'], 'Draft clone should succeed: ' . json_encode($clone['errors'] ?? [], JSON_UNESCAPED_UNICODE));
    $draftId = (int) $clone['id'];
    assert_same(7, (int) $pdo->query('SELECT COUNT(*) FROM work_schedule_days WHERE work_schedule_template_id = ' . $draftId)->fetchColumn());
    assert_same(1, (int) $pdo->query('SELECT COUNT(*) FROM attendance_policy_settings WHERE work_schedule_template_id = ' . $draftId)->fetchColumn());
    assert_same(1, (int) $pdo->query('SELECT COUNT(*) FROM overtime_policy_settings WHERE work_schedule_template_id = ' . $draftId)->fetchColumn());

    $duplicate = $service->cloneAsDraft([
        'source_policy_id' => $sourceId,
        'name' => 'August Office Policy',
        'effective_from' => $futureEffectiveDate,
        'change_reason' => 'Duplicate submission',
    ], $ids['admin_id']);
    assert_false($duplicate['ok'], 'Duplicate policy creation must return validation errors instead of a database exception.');

    $blank = $service->cloneAsDraft([
        'source_mode' => 'blank',
        'name' => 'Blank Future Policy',
        'effective_from' => '2026-09-01',
        'change_reason' => 'Blank policy setup',
    ], $ids['admin_id']);
    assert_true($blank['ok'], 'Blank policy creation should succeed: ' . json_encode($blank['errors'] ?? [], JSON_UNESCAPED_UNICODE));
    assert_same(7, (int) $pdo->query('SELECT COUNT(*) FROM work_schedule_days WHERE work_schedule_template_id = ' . (int) $blank['id'])->fetchColumn());

    $weekly = $service->updateWeeklySchedule($draftId, company_schedule_payload('Ignored', [1, 2, 3, 4, 5, 6], '08:00', '16:30', false, 30, 20, 480), $ids['admin_id']);
    assert_true($weekly['ok'], 'Draft weekly schedule update should succeed: ' . json_encode($weekly['errors'] ?? [], JSON_UNESCAPED_UNICODE));
    assert_same(1, (int) $pdo->query('SELECT is_working_day FROM work_schedule_days WHERE work_schedule_template_id = ' . $draftId . ' AND day_of_week = 6')->fetchColumn());

    $attendance = $service->updateAttendanceRules($draftId, [
        'allow_non_working_day_check_in' => '1',
        'allow_holiday_check_in' => '1',
        'allow_overnight_attendance' => '1',
        'maximum_attendance_minutes' => '720',
        'missing_check_out_mode' => 'admin_review_required',
        'early_departure_enabled' => '1',
        'manual_override_allowed' => '1',
        'override_reason_required' => '1',
    ], $ids['admin_id']);
    assert_true($attendance['ok'], 'Draft attendance rules update should succeed: ' . json_encode($attendance['errors'] ?? [], JSON_UNESCAPED_UNICODE));
    assert_same('admin_review_required', $pdo->query('SELECT missing_check_out_mode FROM attendance_policy_settings WHERE work_schedule_template_id = ' . $draftId)->fetchColumn());

    $overtime = $service->updateOvertimeRules($draftId, [
        'early_work_eligible' => '1',
        'after_hours_work_eligible' => '1',
        'non_working_day_work_eligible' => '1',
        'holiday_work_eligible' => '1',
        'approval_required' => '1',
        'minimum_overtime_minutes' => '30',
        'rounding_mode' => 'nearest',
        'rounding_interval_minutes' => '15',
        'special_day_break_threshold_minutes' => '360',
        'special_day_break_minutes' => '30',
        'allow_admin_adjustment' => '1',
        'allow_approval_above_calculated' => '1',
        'reason_required' => '1',
    ], $ids['admin_id']);
    assert_true($overtime['ok'], 'Draft overtime rules update should succeed: ' . json_encode($overtime['errors'] ?? [], JSON_UNESCAPED_UNICODE));
    assert_same('nearest', $pdo->query('SELECT rounding_mode FROM overtime_policy_settings WHERE work_schedule_template_id = ' . $draftId)->fetchColumn());

    $scheduled = $service->scheduleVersion($draftId, $futureScheduleDate, $ids['admin_id']);
    assert_true($scheduled['ok'], 'Draft scheduling should succeed: ' . ($scheduled['message'] ?? ''));
    assert_same('scheduled', $pdo->query('SELECT lifecycle_status FROM work_schedule_templates WHERE id = ' . $draftId)->fetchColumn());

    assert_true((int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action IN ('work_schedule.cloned', 'work_schedule.weekly_schedule_updated', 'work_schedule.attendance_rules_updated', 'work_schedule.overtime_rules_updated', 'work_schedule.lifecycle_scheduled')")->fetchColumn() >= 5);
});

test('default work schedule cannot be deactivated', function (): void {
    $database = TestDatabase::connect();
    $ids = $database->resetAndSeed();
    Localization::setCurrent(new Localization(project_path('lang')));
    $pdo = $database->pdo();
    $service = company_schedule_work_service($pdo);
    $defaultId = (int) $pdo->query('SELECT default_work_schedule_id FROM company_profiles LIMIT 1')->fetchColumn();

    $result = $service->toggleStatus($defaultId, false, $ids['admin_id']);
    assert_false($result['ok']);
    assert_same(1, (int) $pdo->query('SELECT is_active FROM work_schedule_templates WHERE id = ' . $defaultId)->fetchColumn());
    assert_same(0, (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'work_schedule.deactivated'")->fetchColumn());
});

function company_schedule_profile_service(PDO $pdo): CompanyProfileService
{
    $days = new WorkScheduleDay($pdo);
    $templates = new WorkScheduleTemplate($pdo, $days);

    return new CompanyProfileService($pdo, new CompanyProfile($pdo), $templates, new AuditLogService($pdo));
}

function company_schedule_work_service(PDO $pdo): WorkScheduleService
{
    $days = new WorkScheduleDay($pdo);
    $templates = new WorkScheduleTemplate($pdo, $days);

    return new WorkScheduleService($pdo, $templates, new DateValidationService(), new AuditLogService($pdo));
}

function company_schedule_payload(string $name, array $workingDays, string $start, string $end, bool $overnight, int $break, int $grace, int $expected): array
{
    $days = [];
    for ($day = 1; $day <= 7; $day++) {
        $isWorking = in_array($day, $workingDays, true);
        $days[$day] = [
            'day_present' => '1',
            'is_working_day' => $isWorking ? '1' : '0',
            'start_time' => $isWorking ? $start : '',
            'end_time' => $isWorking ? $end : '',
            'is_overnight' => $isWorking && $overnight ? '1' : '0',
            'break_minutes' => (string) ($isWorking ? $break : 0),
            'grace_minutes' => (string) ($isWorking ? $grace : 0),
            'expected_minutes' => (string) ($isWorking ? $expected : 0),
        ];
    }

    return [
        'name' => $name,
        'description' => 'Test schedule',
        'timezone' => '',
        'effective_from' => '2026-01-01',
        'effective_to' => '',
        'is_active' => '1',
        'days' => $days,
    ];
}
