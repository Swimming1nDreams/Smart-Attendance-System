<?php

declare(strict_types=1);

use App\Models\Attendance;
use App\Models\AttendanceSession;
use App\Services\AttendanceCalculationService;
use App\Services\AttendanceOperationException;
use App\Services\AttendanceOperationService;
use App\Services\AttendancePolicyService;
use App\Services\AttendanceSessionReadService;
use App\Services\OvertimeReviewService;
use App\Services\WorkScheduleResolverService;

test('phase 5 multi-session write cutover allows completed same-day sessions and recalculates the daily parent', function (): void {
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $pdo = $database->pdo();
    phase5_seed_employee_profile($pdo, $ids['employee_id']);
    $operations = phase5_attendance_operations($pdo);
    $attendance = new Attendance($pdo, new AttendancePolicyService());
    $sessions = new AttendanceSession($pdo);

    $operations->clockIn($ids['employee_id'], '2026-08-03', '04:37:00', $ids['employee_id']);
    assert_same(true, $operations->hasOpenSessionForEmployee($ids['employee_id'], new DateTimeImmutable('2026-08-03 04:37:00')));
    assert_same('already_clocked_in', phase5_attendance_reason(static fn () => $operations->clockIn(
        $ids['employee_id'], '2026-08-03', '04:38:00', $ids['employee_id']
    )));
    $firstResult = $operations->clockOut($ids['employee_id'], '2026-08-03', '06:47:00', $ids['employee_id']);
    assert_same('out_of_schedule', $firstResult['attendance_state']);
    assert_same(false, $operations->hasOpenSessionForEmployee($ids['employee_id'], new DateTimeImmutable('2026-08-03 06:47:00')));

    $record = $attendance->findByUserIdAndDate($ids['employee_id'], '2026-08-03');
    assert_not_null($record);
    assert_same(1, $sessions->countByAttendanceId((int) $record['id']));
    assert_null($operations->selfClockInBlockReason($ids['employee_id'], '2026-08-03', $record, false));

    $operations->clockIn($ids['employee_id'], '2026-08-03', '09:00:00', $ids['employee_id']);
    assert_same(true, $operations->hasOpenSessionForEmployee($ids['employee_id'], new DateTimeImmutable('2026-08-03 09:00:00')));
    $open = $attendance->findByUserIdAndDate($ids['employee_id'], '2026-08-03');
    assert_same('04:37:00', $open['clock_in_time']);
    assert_null($open['clock_out_time']);
    assert_same(1, (int) $open['is_missing_check_out']);
    assert_same(2, $sessions->countByAttendanceId((int) $open['id']));

    $preview = $operations->previewClockOut($ids['employee_id'], '2026-08-03', '17:00:00');
    assert_not_null($preview);
    assert_same(2, (int) $preview['session_count']);
    assert_same(610, (int) $preview['actual_work_minutes']);
    assert_same(480, (int) $preview['scheduled_overlap_minutes']);
    assert_same(130, (int) $preview['early_overtime_minutes']);
    assert_same(0, (int) $preview['early_departure_minutes']);
    assert_same('present', $preview['attendance_state']);

    $final = $operations->clockOut($ids['employee_id'], '2026-08-03', '17:00:00', $ids['employee_id']);
    assert_same('present', $final['attendance_state']);
    assert_same(610, (int) $final['actual_work_minutes']);

    $record = $attendance->findByUserIdAndDate($ids['employee_id'], '2026-08-03');
    assert_same('04:37:00', $record['clock_in_time']);
    assert_same('17:00:00', $record['clock_out_time']);
    assert_same(10.17, (float) $record['working_hours']);
    assert_same(480, (int) $record['regular_minutes']);
    assert_same(130, (int) $record['early_overtime_minutes']);
    assert_same(130, (int) $record['calculated_overtime_minutes']);
    assert_same(130, (int) $record['approved_overtime_minutes']);
    assert_same('not_required', $record['overtime_approval_status']);
    assert_same(2, $sessions->countByAttendanceId((int) $record['id']));
    assert_null($operations->selfClockInBlockReason($ids['employee_id'], '2026-08-03', $record, false));
});

test('phase 5 later session clears an earlier daily early-departure result when the employee returns', function (): void {
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $pdo = $database->pdo();
    phase5_seed_employee_profile($pdo, $ids['employee_id']);
    $operations = phase5_attendance_operations($pdo);
    $attendance = new Attendance($pdo, new AttendancePolicyService());

    $operations->clockIn($ids['employee_id'], '2026-08-03', '09:00:00', $ids['employee_id']);
    $provisional = $operations->clockOut(
        $ids['employee_id'],
        '2026-08-03',
        '12:00:00',
        $ids['employee_id'],
        [],
        'medical',
        'Morning appointment.',
    );
    assert_same(1, (int) $provisional['early_departure_provisional']);
    assert_same(300, (int) $provisional['early_departure_candidate_minutes']);
    assert_same(0, (int) $provisional['is_early_departure']);
    $first = $attendance->findByUserIdAndDate($ids['employee_id'], '2026-08-03');
    assert_same(0, (int) $first['is_early_departure']);
    assert_same(0, (int) $first['early_departure_minutes']);
    assert_same('medical', $first['early_departure_reason']);

    $operations->clockIn($ids['employee_id'], '2026-08-03', '13:00:00', $ids['employee_id']);
    $returned = $attendance->findByUserIdAndDate($ids['employee_id'], '2026-08-03');
    assert_same(0, (int) $returned['is_early_departure']);
    assert_same(0, (int) $returned['early_departure_minutes']);
    assert_same(null, $returned['early_departure_reason']);
    assert_same(null, $returned['early_departure_note']);

    $operations->clockOut($ids['employee_id'], '2026-08-03', '17:00:00', $ids['employee_id']);
    $final = $attendance->findByUserIdAndDate($ids['employee_id'], '2026-08-03');
    assert_same(0, (int) $final['is_early_departure']);
    assert_same(0, (int) $final['early_departure_minutes']);
    assert_same(null, $final['early_departure_reason']);
    assert_same(7.0, (float) $final['working_hours']);
});

test('phase 5 stale early-checkout preview cannot make an after-schedule checkout early', function (): void {
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $pdo = $database->pdo();
    phase5_seed_employee_profile($pdo, $ids['employee_id']);
    $operations = phase5_attendance_operations($pdo);
    $attendance = new Attendance($pdo, new AttendancePolicyService());

    $operations->clockIn($ids['employee_id'], '2026-08-03', '11:37:44', $ids['employee_id']);

    $stalePagePreview = $operations->previewClockOut($ids['employee_id'], '2026-08-03', '15:46:00');
    assert_not_null($stalePagePreview);
    assert_same(1, (int) $stalePagePreview['early_departure_provisional']);
    assert_same(74, (int) $stalePagePreview['early_departure_candidate_minutes']);

    $actualCheckoutPreview = $operations->previewClockOut($ids['employee_id'], '2026-08-03', '17:18:00');
    assert_not_null($actualCheckoutPreview);
    assert_same(0, (int) $actualCheckoutPreview['is_early_departure']);
    assert_same(0, (int) $actualCheckoutPreview['early_departure_candidate_minutes']);

    $result = $operations->clockOut($ids['employee_id'], '2026-08-03', '17:18:00', $ids['employee_id']);
    assert_same(0, (int) $result['is_early_departure']);
    assert_same(0, (int) $result['early_departure_minutes']);
    assert_same(null, $result['early_departure_reason']);
    assert_same(340, (int) $result['actual_work_minutes']);

    $record = $attendance->findByUserIdAndDate($ids['employee_id'], '2026-08-03');
    assert_not_null($record);
    assert_same('17:18:00', $record['clock_out_time']);
    assert_same(0, (int) $record['is_early_departure']);
    assert_same(null, $record['early_departure_reason']);
});

test('phase 5 fresh 15:46 checkout still requires and records an early-departure reason', function (): void {
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $pdo = $database->pdo();
    phase5_seed_employee_profile($pdo, $ids['employee_id']);
    $operations = phase5_attendance_operations($pdo);

    $operations->clockIn($ids['employee_id'], '2026-08-03', '11:37:44', $ids['employee_id']);
    assert_same('early_departure_reason_required', phase5_attendance_reason(static fn () => $operations->clockOut(
        $ids['employee_id'], '2026-08-03', '15:46:00', $ids['employee_id']
    )));

    $result = $operations->clockOut(
        $ids['employee_id'],
        '2026-08-03',
        '15:46:00',
        $ids['employee_id'],
        [],
        'medical',
    );
    assert_same(1, (int) $result['early_departure_provisional']);
    assert_same(74, (int) $result['early_departure_candidate_minutes']);
    assert_same('medical', $result['early_departure_reason']);
});

test('phase 5 a provisional early departure becomes final after scheduled end when the employee does not return', function (): void {
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $pdo = $database->pdo();
    phase5_seed_employee_profile($pdo, $ids['employee_id']);
    $operations = phase5_attendance_operations($pdo);
    $attendance = new Attendance($pdo, new AttendancePolicyService());
    $sessions = new AttendanceSession($pdo);
    $resolver = new WorkScheduleResolverService($pdo);
    $reader = new AttendanceSessionReadService($sessions, new AttendanceCalculationService());

    $operations->clockIn($ids['employee_id'], '2026-08-03', '09:00:00', $ids['employee_id']);
    $operations->clockOut(
        $ids['employee_id'],
        '2026-08-03',
        '12:00:00',
        $ids['employee_id'],
        [],
        'medical',
        'Morning appointment.',
    );

    $record = $attendance->findByUserIdAndDate($ids['employee_id'], '2026-08-03');
    assert_same(0, (int) $record['is_early_departure']);
    assert_same('medical', $record['early_departure_reason']);
    $resolution = $resolver->resolve($ids['employee_id'], '2026-08-03');
    $calculation = $reader->calculateIfSessions(
        $record,
        $resolution,
        new DateTimeImmutable('2026-08-03 18:00:00', new DateTimeZone('Asia/Yangon')),
    );
    assert_not_null($calculation);
    assert_same(0, (int) $calculation['early_departure_provisional']);
    assert_same(1, (int) $calculation['is_early_departure']);
    assert_same(300, (int) $calculation['early_departure_minutes']);
    $finalRead = $reader->applyToRecord($record, $calculation);
    assert_same('medical', $finalRead['early_departure_reason']);
});

test('phase 5 opening a later session resets an already reviewed overtime candidate to pending', function (): void {
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $pdo = $database->pdo();
    phase5_seed_employee_profile($pdo, $ids['employee_id']);
    $policyId = (int) $pdo->query('SELECT default_work_schedule_id FROM company_profiles ORDER BY id ASC LIMIT 1')->fetchColumn();
    $pdo->prepare('UPDATE overtime_policy_settings SET approval_required = 1 WHERE work_schedule_template_id = :id')
        ->execute([':id' => $policyId]);

    $operations = phase5_attendance_operations($pdo);
    $attendance = new Attendance($pdo, new AttendancePolicyService());
    $operations->clockIn($ids['employee_id'], '2026-08-03', '08:00:00', $ids['employee_id']);
    $operations->clockOut($ids['employee_id'], '2026-08-03', '17:00:00', $ids['employee_id']);

    $record = $attendance->findByUserIdAndDate($ids['employee_id'], '2026-08-03');
    assert_same(60, (int) $record['calculated_overtime_minutes']);
    assert_same('pending', $record['overtime_approval_status']);
    (new OvertimeReviewService($pdo))->approve((int) $record['id'], null, null, $ids['admin_id']);

    $approved = $attendance->findById((int) $record['id']);
    assert_same('approved', $approved['overtime_approval_status']);
    assert_same(60, (int) $approved['approved_overtime_minutes']);

    $operations->clockIn($ids['employee_id'], '2026-08-03', '18:00:00', $ids['employee_id']);
    $reset = $attendance->findById((int) $record['id']);
    assert_same('pending', $reset['overtime_approval_status']);
    assert_same(0, (int) $reset['approved_overtime_minutes']);
    assert_same(60, (int) $reset['calculated_overtime_minutes']);

    $decision = $pdo->query(
        'SELECT decision, calculated_minutes, previous_approved_minutes, approved_minutes, reason
         FROM overtime_decisions ORDER BY id DESC LIMIT 1'
    )->fetch();
    assert_same('reset_to_pending', $decision['decision']);
    assert_same(60, (int) $decision['calculated_minutes']);
    assert_same(60, (int) $decision['previous_approved_minutes']);
    assert_same(0, (int) $decision['approved_minutes']);
    assert_contains('attendance session change', (string) $decision['reason']);
});

function phase5_attendance_operations(PDO $pdo): AttendanceOperationService
{
    return new AttendanceOperationService(
        $pdo,
        new AttendancePolicyService(),
        null,
        new WorkScheduleResolverService($pdo),
        new AttendanceCalculationService(),
        null,
        new AttendanceSession($pdo),
    );
}

function phase5_seed_employee_profile(PDO $pdo, int $userId): void
{
    $pdo->prepare('INSERT INTO employee_profiles (user_id) VALUES (:user_id)')->execute([':user_id' => $userId]);
}

function phase5_attendance_reason(callable $callback): ?string
{
    try {
        $callback();
    } catch (AttendanceOperationException $exception) {
        return $exception->reason();
    }

    return null;
}
