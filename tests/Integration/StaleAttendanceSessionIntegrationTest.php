<?php

declare(strict_types=1);

use App\Models\Attendance;
use App\Models\AttendanceSession;
use App\Services\AttendanceCalculationService;
use App\Services\AttendanceOperationException;
use App\Services\AttendanceOperationService;
use App\Services\AttendancePolicyException;
use App\Services\AttendancePolicyService;
use App\Services\WorkScheduleResolverService;

test('stale attendance session handles exactly threshold boundary correctly', function (): void {
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $pdo = $database->pdo();
    $operations = new AttendanceOperationService(
        $pdo,
        new AttendancePolicyService(),
        null,
        new WorkScheduleResolverService($pdo, new AttendancePolicyService()),
        new AttendanceCalculationService(),
        null,
        new AttendanceSession($pdo)
    );

    // Initial clock-in
    $operations->clockIn($ids['employee_id'], '2026-08-01', '09:00:00');
    $session = (new AttendanceSession($pdo))->findOpenForEmployee($ids['employee_id']);
    assert_not_null($session);

    // Make the session stale. The stale threshold is exactly 16 hours. We simulate that `now` is many days later.
    // But we need to ensure its check_in_at remains BEFORE the next clock in.
    $checkInTime = '2026-08-01 09:00:00';
    $pdo->exec("UPDATE attendance_sessions SET check_in_at = '{$checkInTime}' WHERE id = " . $session['id']);

    $hasOpen = $operations->hasOpenSessionForEmployee($ids['employee_id']);
    assert_false($hasOpen); // because it's stale! (16 hours from 2026-08-01 09:00:00 is definitely before today)

    // Next day check-in should work
    $operations->clockIn($ids['employee_id'], '2026-08-02', '09:00:00');
    $newSession = (new AttendanceSession($pdo))->findOpenForEmployee($ids['employee_id']);
    assert_not_null($newSession);
    assert_true($newSession['id'] !== $session['id']);
});

test('completed too-long session still throws attendance_duration_too_long', function (): void {
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $pdo = $database->pdo();
    $operations = new AttendanceOperationService(
        $pdo,
        new AttendancePolicyService(),
        null,
        new WorkScheduleResolverService($pdo, new AttendancePolicyService()),
        new AttendanceCalculationService(),
        null,
        new AttendanceSession($pdo)
    );

    $operations->clockIn($ids['employee_id'], '2026-08-01', '09:00:00');
    $session = (new AttendanceSession($pdo))->findOpenForEmployee($ids['employee_id']);
    assert_not_null($session);

    // Make it completed and 17 hours long
    $pdo->exec("UPDATE attendance_sessions SET check_out_at = '2026-08-02 02:00:00' WHERE id = " . $session['id']);

    $attendance = new Attendance($pdo, new AttendancePolicyService());
    $record = $attendance->findByUserIdAndDate($ids['employee_id'], '2026-08-01');

    $resolver = new WorkScheduleResolverService($pdo, new AttendancePolicyService());
    $sessions = (new AttendanceSession($pdo))->findByAttendanceId((int) $record['id']);
    $resolution = $resolver->resolve($ids['employee_id'], '2026-08-01', false);

    $exceptionThrown = false;
    try {
        (new AttendanceCalculationService())->calculateFromSessions(
            $resolution,
            ['overtime_eligible' => 1],
            $sessions,
            false,
            new DateTimeImmutable()
        );
    } catch (AttendancePolicyException $e) {
        if ($e->getMessage() === 'attendance_duration_too_long') {
            $exceptionThrown = true;
        }
    }
    assert_true($exceptionThrown);
});

test('stale session avoids calculating fake duration and overtime', function (): void {
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $pdo = $database->pdo();
    $operations = new AttendanceOperationService(
        $pdo,
        new AttendancePolicyService(),
        null,
        new WorkScheduleResolverService($pdo, new AttendancePolicyService()),
        new AttendanceCalculationService(),
        null,
        new AttendanceSession($pdo)
    );

    $operations->clockIn($ids['employee_id'], '2026-08-01', '09:00:00');
    $session = (new AttendanceSession($pdo))->findOpenForEmployee($ids['employee_id']);
    assert_not_null($session);

    // It is 24 hours later (so it's stale and unresolved)
    $checkInTime = (new DateTimeImmutable('now'))->modify('-24 hours')->format('Y-m-d H:i:s');
    $pdo->exec("UPDATE attendance_sessions SET check_in_at = '{$checkInTime}' WHERE id = " . $session['id']);

    $attendance = new Attendance($pdo, new AttendancePolicyService());
    $record = $attendance->findByUserIdAndDate($ids['employee_id'], '2026-08-01');

    $resolver = new WorkScheduleResolverService($pdo, new AttendancePolicyService());
    $sessions = (new AttendanceSession($pdo))->findByAttendanceId((int) $record['id']);
    $resolution = $resolver->resolve($ids['employee_id'], '2026-08-01', false);

    $calc = (new AttendanceCalculationService())->calculateFromSessions(
        $resolution,
        ['overtime_eligible' => 1],
        $sessions,
        false,
        new DateTimeImmutable()
    );

    // Because it's stale, it's open but shouldn't have accumulated 24 hours of work!
    assert_same('incomplete', $calc['attendance_state']);
    assert_same(1, $calc['is_missing_check_out']);
    assert_same(0.0, $calc['working_hours']);
    assert_same(0, (int) $calc['calculated_overtime_minutes']);
});
