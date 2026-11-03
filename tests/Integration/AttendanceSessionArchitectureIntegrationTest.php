<?php

declare(strict_types=1);

use App\Models\AttendanceSession;

/** @return int */
function session_architecture_parent(PDO $pdo, int $userId, string $date = '2026-08-12'): int
{
    $statement = $pdo->prepare(
        'INSERT INTO attendance_records (user_id, attendance_date, status, attendance_state)
         VALUES (:user_id, :attendance_date, "present", "incomplete")'
    );
    $statement->execute([':user_id' => $userId, ':attendance_date' => $date]);
    return (int) $pdo->lastInsertId();
}

test('attendance parent remains unique while one parent supports multiple completed sessions', function (): void {
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $pdo = $database->pdo();
    $attendanceId = session_architecture_parent($pdo, $ids['employee_id']);
    $sessions = new AttendanceSession($pdo);

    $firstId = $sessions->create($attendanceId, '2026-08-12 04:37:00');
    assert_true($sessions->close($firstId, '2026-08-12 06:47:00'));
    $secondId = $sessions->create($attendanceId, '2026-08-12 09:00:00');
    assert_true($sessions->close($secondId, '2026-08-12 17:00:00'));

    assert_same(2, $sessions->countByAttendanceId($attendanceId));
    $rows = $sessions->findByAttendanceId($attendanceId);
    assert_same('2026-08-12 04:37:00', $rows[0]['check_in_at']);
    assert_same('2026-08-12 06:47:00', $rows[0]['check_out_at']);
    assert_same('2026-08-12 09:00:00', $rows[1]['check_in_at']);
    assert_same('2026-08-12 17:00:00', $rows[1]['check_out_at']);
    $batch = $sessions->findByAttendanceIds([$attendanceId]);
    assert_same(2, count($batch[$attendanceId] ?? []));

    $duplicate = $pdo->prepare(
        'INSERT INTO attendance_records (user_id, attendance_date, status) VALUES (:user_id, :attendance_date, "present")'
    );
    $duplicateRejected = false;
    try {
        $duplicate->execute([':user_id' => $ids['employee_id'], ':attendance_date' => '2026-08-12']);
    } catch (PDOException) {
        $duplicateRejected = true;
    }
    assert_true($duplicateRejected, 'Daily attendance parent uniqueness must remain enforced.');
});

test('open-session lookup works through the daily parent and completed session permits another session', function (): void {
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $pdo = $database->pdo();
    $attendanceId = session_architecture_parent($pdo, $ids['employee_id']);
    $sessions = new AttendanceSession($pdo);

    $firstId = $sessions->create($attendanceId, '2026-08-12 08:00:00');
    assert_true($sessions->hasOpenForEmployee($ids['employee_id']));
    $open = $sessions->findOpenForEmployee($ids['employee_id']);
    assert_same($firstId, (int) $open['id']);
    assert_same($attendanceId, (int) $open['attendance_id']);

    assert_true($sessions->close($firstId, '2026-08-12 11:00:00'));
    assert_false($sessions->hasOpenForEmployee($ids['employee_id']));

    $secondId = $sessions->create($attendanceId, '2026-08-12 13:00:00');
    assert_true($secondId > $firstId);
    assert_same(2, $sessions->countByAttendanceId($attendanceId));
    assert_same($secondId, (int) $sessions->findOpenByAttendanceId($attendanceId)['id']);
});

test('session database constraint rejects equal and earlier checkout timestamps', function (): void {
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $pdo = $database->pdo();
    $attendanceId = session_architecture_parent($pdo, $ids['employee_id']);

    foreach (['2026-08-12 09:00:00', '2026-08-12 08:59:59'] as $checkOutAt) {
        $statement = $pdo->prepare(
            'INSERT INTO attendance_sessions (attendance_id, check_in_at, check_out_at)
             VALUES (:attendance_id, :check_in_at, :check_out_at)'
        );
        $rejected = false;
        try {
            $statement->execute([
                ':attendance_id' => $attendanceId,
                ':check_in_at' => '2026-08-12 09:00:00',
                ':check_out_at' => $checkOutAt,
            ]);
        } catch (PDOException) {
            $rejected = true;
        }
        assert_true($rejected, 'Invalid attendance session interval should be rejected by the database.');
    }
});

test('deleting the daily attendance parent cascades its physical sessions', function (): void {
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $pdo = $database->pdo();
    $attendanceId = session_architecture_parent($pdo, $ids['employee_id']);
    $sessions = new AttendanceSession($pdo);

    $sessionId = $sessions->create($attendanceId, '2026-08-12 09:00:00');
    assert_true($sessionId > 0);
    $pdo->prepare('DELETE FROM attendance_records WHERE id = :id')->execute([':id' => $attendanceId]);
    assert_same(0, $sessions->countByAttendanceId($attendanceId));
});
