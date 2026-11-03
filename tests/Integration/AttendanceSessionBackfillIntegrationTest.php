<?php

declare(strict_types=1);

use App\Models\AttendanceSession;
use App\Services\AttendanceCalculationService;
use App\Services\AttendanceIntervalAggregationService;
use App\Services\AttendanceSessionBackfillPlanner;
use App\Services\AttendanceSessionBackfillService;
use App\Services\WorkScheduleResolverService;

function backfill_integration_service(PDO $pdo): AttendanceSessionBackfillService
{
    return new AttendanceSessionBackfillService(
        $pdo,
        new AttendanceSession($pdo),
        new AttendanceSessionBackfillPlanner(),
        new WorkScheduleResolverService($pdo),
        new AttendanceCalculationService(new AttendanceIntervalAggregationService()),
    );
}

function insert_backfill_parent(PDO $pdo, int $userId, string $date = '2026-08-12', ?string $checkOutAt = '2026-08-12 17:00:00'): int
{
    $statement = $pdo->prepare(
        'INSERT INTO attendance_records
         (user_id, attendance_date, clock_in_time, clock_out_time, check_in_at, check_out_at, status, attendance_state, is_missing_check_out, working_hours)
         VALUES (:user_id, :attendance_date, :clock_in_time, :clock_out_time, :check_in_at, :check_out_at, "present", :attendance_state, :missing, :working_hours)'
    );
    $statement->execute([
        ':user_id' => $userId,
        ':attendance_date' => $date,
        ':clock_in_time' => '09:00:00',
        ':clock_out_time' => $checkOutAt === null ? null : '17:00:00',
        ':check_in_at' => $date . ' 09:00:00',
        ':check_out_at' => $checkOutAt,
        ':attendance_state' => $checkOutAt === null ? 'incomplete' : 'present',
        ':missing' => $checkOutAt === null ? 1 : 0,
        ':working_hours' => $checkOutAt === null ? 0 : 8,
    ]);

    return (int) $pdo->lastInsertId();
}

test('Phase 4 rehearsal backfills one completed session without changing the daily parent or overtime decisions', function (): void {
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $pdo = $database->pdo();
    $attendanceId = insert_backfill_parent($pdo, $ids['employee_id']);

    $decision = $pdo->prepare(
        "INSERT INTO overtime_decisions
         (attendance_id, decision, calculated_minutes, previous_approved_minutes, approved_minutes, reason, decided_by, decided_at)
         VALUES (:attendance_id, 'approved', 0, 0, 0, 'Backfill preservation test', :admin_id, '2026-08-12 18:00:00')"
    );
    $decision->execute([':attendance_id' => $attendanceId, ':admin_id' => $ids['admin_id']]);

    $parentBefore = $pdo->query('SELECT * FROM attendance_records WHERE id = ' . $attendanceId)->fetch();
    $decisionBefore = $pdo->query('SELECT * FROM overtime_decisions WHERE attendance_id = ' . $attendanceId)->fetch();
    $service = backfill_integration_service($pdo);

    $analysis = $service->analyze();
    assert_true($analysis['safe_to_rehearse']);
    assert_same(1, $analysis['counts']['candidate']);

    $result = $service->applyRehearsal();
    assert_same(1, $result['applied_count']);
    assert_same(1, $result['post_analysis']['counts']['already_backfilled']);
    assert_same(0, $result['post_analysis']['counts']['candidate']);

    $sessions = (new AttendanceSession($pdo))->findByAttendanceId($attendanceId);
    assert_same(1, count($sessions));
    assert_same('2026-08-12 09:00:00', $sessions[0]['check_in_at']);
    assert_same('2026-08-12 17:00:00', $sessions[0]['check_out_at']);

    $parentAfter = $pdo->query('SELECT * FROM attendance_records WHERE id = ' . $attendanceId)->fetch();
    $decisionAfter = $pdo->query('SELECT * FROM overtime_decisions WHERE attendance_id = ' . $attendanceId)->fetch();
    assert_same($parentBefore, $parentAfter, 'Backfill rehearsal must not mutate the daily parent.');
    assert_same($decisionBefore, $decisionAfter, 'Backfill rehearsal must preserve overtime decisions exactly.');

    $rerun = $service->applyRehearsal();
    assert_same(0, $rerun['applied_count']);
    assert_same(1, (new AttendanceSession($pdo))->countByAttendanceId($attendanceId));
});

test('Phase 4 rehearsal leaves historical missing-checkout rows on legacy fallback', function (): void {
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $pdo = $database->pdo();
    $attendanceId = insert_backfill_parent($pdo, $ids['employee_id'], '2026-08-12', null);
    $service = backfill_integration_service($pdo);

    $analysis = $service->analyze();
    assert_true($analysis['safe_to_rehearse']);
    assert_same(1, $analysis['counts']['skipped_incomplete']);
    assert_same(0, $analysis['counts']['candidate']);

    $result = $service->applyRehearsal();
    assert_same(0, $result['applied_count']);
    assert_same(0, (new AttendanceSession($pdo))->countByAttendanceId($attendanceId));
});

test('Phase 4 preflight blocks an existing child session that conflicts with the legacy interval', function (): void {
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $pdo = $database->pdo();
    $attendanceId = insert_backfill_parent($pdo, $ids['employee_id']);
    (new AttendanceSession($pdo))->createCompleted($attendanceId, '2026-08-12 09:30:00', '2026-08-12 17:00:00');
    $service = backfill_integration_service($pdo);

    $analysis = $service->analyze();
    assert_false($analysis['safe_to_rehearse']);
    assert_same(1, $analysis['counts']['existing_session_conflict']);

    $rejected = false;
    try {
        $service->applyRehearsal();
    } catch (RuntimeException) {
        $rejected = true;
    }
    assert_true($rejected, 'Conflicting existing sessions must block rehearsal apply.');
    assert_same(1, (new AttendanceSession($pdo))->countByAttendanceId($attendanceId));
});


test('guarded live backfill refuses a non-live database even with the exact confirmation phrase', function (): void {
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $pdo = $database->pdo();
    insert_backfill_parent($pdo, $ids['employee_id']);
    $service = backfill_integration_service($pdo);

    $rejected = false;
    try {
        $service->applyLive(AttendanceSessionBackfillService::LIVE_CONFIRMATION, 1);
    } catch (RuntimeException $exception) {
        $rejected = str_contains($exception->getMessage(), 'Expected exactly "smart_attendance"');
    }

    assert_true($rejected, 'Live backfill must reject every database except the exact canonical smart_attendance database.');
    assert_same(0, (new AttendanceSession($pdo))->countByAttendanceId(
        (int) $pdo->query('SELECT id FROM attendance_records ORDER BY id DESC LIMIT 1')->fetchColumn()
    ));
});
