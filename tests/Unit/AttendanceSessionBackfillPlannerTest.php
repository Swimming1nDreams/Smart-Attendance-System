<?php

declare(strict_types=1);

use App\Services\AttendanceSessionBackfillPlanner;

function backfill_planner_record(array $overrides = []): array
{
    return array_replace([
        'id' => 101,
        'user_id' => 7,
        'attendance_date' => '2026-08-12',
        'business_date' => '2026-08-12',
        'clock_in_time' => '09:00:00',
        'clock_out_time' => '17:00:00',
        'check_in_at' => '2026-08-12 09:00:00',
        'check_out_at' => '2026-08-12 17:00:00',
        'is_overnight_attendance' => 0,
    ], $overrides);
}

test('backfill planner prefers full datetimes for a completed legacy interval', function (): void {
    $plan = (new AttendanceSessionBackfillPlanner())->plan(backfill_planner_record());

    assert_same('candidate', $plan['classification']);
    assert_same('full_datetime', $plan['source']);
    assert_same('2026-08-12 09:00:00', $plan['check_in_at']);
    assert_same('2026-08-12 17:00:00', $plan['check_out_at']);
    assert_same(480, $plan['duration_minutes']);
});

test('backfill planner falls back to legacy clock times without inventing a second parent', function (): void {
    $plan = (new AttendanceSessionBackfillPlanner())->plan(backfill_planner_record([
        'check_in_at' => null,
        'check_out_at' => null,
        'clock_in_time' => '08:15:00',
        'clock_out_time' => '16:45:00',
    ]));

    assert_same('candidate', $plan['classification']);
    assert_same('legacy_clock_time', $plan['source']);
    assert_same('2026-08-12 08:15:00', $plan['check_in_at']);
    assert_same('2026-08-12 16:45:00', $plan['check_out_at']);
    assert_same(510, $plan['duration_minutes']);
});

test('backfill planner reconstructs a confirmed overnight legacy clock interval', function (): void {
    $plan = (new AttendanceSessionBackfillPlanner())->plan(backfill_planner_record([
        'check_in_at' => null,
        'check_out_at' => null,
        'clock_in_time' => '22:00:00',
        'clock_out_time' => '06:00:00',
        'is_overnight_attendance' => 1,
    ]));

    assert_same('candidate', $plan['classification']);
    assert_same('2026-08-12 22:00:00', $plan['check_in_at']);
    assert_same('2026-08-13 06:00:00', $plan['check_out_at']);
    assert_same(480, $plan['duration_minutes']);
});

test('backfill planner skips historical missing-checkout rows instead of creating open sessions', function (): void {
    $plan = (new AttendanceSessionBackfillPlanner())->plan(backfill_planner_record([
        'clock_out_time' => null,
        'check_out_at' => null,
    ]));

    assert_same('skipped_incomplete', $plan['classification']);
    assert_same(null, $plan['check_out_at']);
    assert_true(in_array('missing_checkout', $plan['issues'], true));
});

test('backfill planner flags ambiguous reverse clock times when overnight was not confirmed', function (): void {
    $plan = (new AttendanceSessionBackfillPlanner())->plan(backfill_planner_record([
        'check_in_at' => null,
        'check_out_at' => null,
        'clock_in_time' => '22:00:00',
        'clock_out_time' => '06:00:00',
        'is_overnight_attendance' => 0,
    ]));

    assert_same('manual_review', $plan['classification']);
    assert_true(in_array('ambiguous_or_invalid_interval', $plan['issues'], true));
});

test('backfill planner is idempotent for an exact existing child session and rejects conflicts', function (): void {
    $planner = new AttendanceSessionBackfillPlanner();
    $record = backfill_planner_record();
    $exact = $planner->plan($record, [[
        'id' => 1,
        'attendance_id' => 101,
        'check_in_at' => '2026-08-12 09:00:00',
        'check_out_at' => '2026-08-12 17:00:00',
    ]]);
    assert_same('already_backfilled', $exact['classification']);

    $conflict = $planner->plan($record, [[
        'id' => 2,
        'attendance_id' => 101,
        'check_in_at' => '2026-08-12 09:05:00',
        'check_out_at' => '2026-08-12 17:00:00',
    ]]);
    assert_same('existing_session_conflict', $conflict['classification']);
    assert_true(in_array('existing_session_differs_from_legacy_interval', $conflict['issues'], true));
});

test('Phase 4 database guards distinguish rehearsal names from the exact live database', function (): void {
    assert_true(App\Services\AttendanceSessionBackfillService::isSafeRehearsalDatabaseName('smart_attendance_backfill_test'));
    assert_true(App\Services\AttendanceSessionBackfillService::isSafeRehearsalDatabaseName('sapas_rehearsal'));
    assert_false(App\Services\AttendanceSessionBackfillService::isSafeRehearsalDatabaseName('smart_attendance'));
    assert_false(App\Services\AttendanceSessionBackfillService::isSafeRehearsalDatabaseName('production'));

    assert_true(App\Services\AttendanceSessionBackfillService::isLiveDatabaseName('smart_attendance'));
    assert_true(App\Services\AttendanceSessionBackfillService::isLiveDatabaseName(' SMART_ATTENDANCE '));
    assert_false(App\Services\AttendanceSessionBackfillService::isLiveDatabaseName('smart_attendance_backfill_test'));
    assert_false(App\Services\AttendanceSessionBackfillService::isLiveDatabaseName('production'));
    assert_same('BACKFILL_SMART_ATTENDANCE', App\Services\AttendanceSessionBackfillService::LIVE_CONFIRMATION);
});
