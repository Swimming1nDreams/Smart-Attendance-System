<?php

declare(strict_types=1);

use App\Models\AttendanceSession;
use App\Services\AttendanceCalculationService;
use App\Services\AttendanceIntervalAggregationService;
use App\Services\AttendanceSessionReadService;

/** @return array<string, mixed> */
function dual_read_resolution(): array
{
    return [
        'day_type' => 'working_day',
        'scheduled_start_at' => '2026-08-12 09:00:00',
        'scheduled_end_at' => '2026-08-12 17:00:00',
        'timezone' => 'Asia/Yangon',
        'grace_minutes' => 30,
        'break_minutes' => 0,
        'expected_regular_minutes' => 480,
        'attendance_policy' => [
            'maximum_attendance_minutes' => 960,
            'early_departure_enabled' => 1,
            'early_departure_grace_minutes' => 10,
        ],
        'overtime_policy' => [
            'early_work_eligible' => 1,
            'after_hours_work_eligible' => 1,
            'non_working_day_work_eligible' => 1,
            'holiday_work_eligible' => 1,
            'minimum_overtime_minutes' => 0,
            'rounding_mode' => 'exact',
            'approval_required' => 1,
        ],
    ];
}

test('dual-read prefers child sessions and preserves daily parent compatibility fields', function (): void {
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $pdo = $database->pdo();

    $insert = $pdo->prepare(
        "INSERT INTO attendance_records
         (user_id, attendance_date, clock_in_time, clock_out_time, check_in_at, check_out_at, status, attendance_state, overtime_approval_status, approved_overtime_minutes)
         VALUES (?, '2026-08-12', '04:37:00', '17:00:00', '2026-08-12 04:37:00', '2026-08-12 17:00:00', 'present', 'present', 'approved', 60)"
    );
    $insert->execute([$ids['employee_id']]);
    $attendanceId = (int) $pdo->lastInsertId();

    $model = new AttendanceSession($pdo);
    $first = $model->create($attendanceId, '2026-08-12 04:37:00');
    assert_true($model->close($first, '2026-08-12 06:47:00'));
    $second = $model->create($attendanceId, '2026-08-12 09:00:00');
    assert_true($model->close($second, '2026-08-12 17:00:00'));

    $reader = new AttendanceSessionReadService(
        $model,
        new AttendanceCalculationService(new AttendanceIntervalAggregationService()),
    );
    $record = $pdo->query('SELECT ar.*, u.overtime_eligible FROM attendance_records ar JOIN users u ON u.id = ar.user_id WHERE ar.id = ' . $attendanceId)->fetch();
    $prefetched = $reader->prefetch([$record]);
    $calculation = $reader->calculateIfSessions($record, dual_read_resolution(), new DateTimeImmutable('2026-08-12 18:00:00'), $prefetched);

    assert_not_null($calculation);
    assert_same(610, $calculation['actual_work_minutes']);
    assert_same(480, $calculation['scheduled_overlap_minutes']);
    assert_same(130, $calculation['out_of_schedule_work_minutes']);
    assert_same(2, $calculation['session_count']);

    $enriched = $reader->applyToRecord($record, $calculation);
    assert_same('attendance_sessions', $enriched['read_model_source']);
    assert_same('04:37:00', $enriched['clock_in_time']);
    assert_same('17:00:00', $enriched['clock_out_time']);
    assert_same(610, $enriched['actual_work_minutes']);
    assert_same('present', $enriched['attendance_state']);
    assert_same('approved', $enriched['overtime_approval_status']);
    assert_same(60, (int) $enriched['approved_overtime_minutes']);
    assert_same(130, (int) $enriched['calculated_overtime_minutes']);
});

test('dual-read returns null when a parent has no child sessions so legacy path remains authoritative', function (): void {
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $pdo = $database->pdo();

    $insert = $pdo->prepare(
        "INSERT INTO attendance_records
         (user_id, attendance_date, clock_in_time, clock_out_time, check_in_at, check_out_at, status, attendance_state)
         VALUES (?, '2026-08-12', '09:00:00', '17:00:00', '2026-08-12 09:00:00', '2026-08-12 17:00:00', 'present', 'present')"
    );
    $insert->execute([$ids['employee_id']]);
    $attendanceId = (int) $pdo->lastInsertId();

    $model = new AttendanceSession($pdo);
    $reader = new AttendanceSessionReadService(
        $model,
        new AttendanceCalculationService(new AttendanceIntervalAggregationService()),
    );
    $record = $pdo->query('SELECT ar.*, u.overtime_eligible FROM attendance_records ar JOIN users u ON u.id = ar.user_id WHERE ar.id = ' . $attendanceId)->fetch();

    assert_null($reader->calculateIfSessions($record, dual_read_resolution(), new DateTimeImmutable('2026-08-12 18:00:00')));
});
