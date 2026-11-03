<?php

declare(strict_types=1);

use App\Models\Attendance;
use App\Models\LeaveRequest;

test('database integration: absence management isolates absent records correctly', function (): void {
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $attendance = new Attendance($database->pdo());

    assert_true($attendance->create($ids['employee_id'], '2026-08-01', '09:00:00', '17:00:00', 8.0, 'present', 0, null));
    assert_true($attendance->create($ids['employee_id'], '2026-08-02', null, null, 8.0, 'absent', 0, 'Sick leave pending'));
    assert_true($attendance->create($ids['other_employee_id'], '2026-08-01', null, null, 8.0, 'absent', 0, 'No show'));

    // Test default behaviour (includes all)
    $allCount = $attendance->countAll(null, '', '2026-08-01', '2026-08-02');
    assert_same(3, $allCount);
    
    // Test attendance view exclusion (excludes 'absent')
    $attendanceOnlyCount = $attendance->countAll(null, '', '2026-08-01', '2026-08-02', null, 'absent');
    assert_same(1, $attendanceOnlyCount);
    
    $attendanceOnlyRecords = $attendance->findAll(null, '', '2026-08-01', '2026-08-02', 1, 20, null, 'absent');
    assert_same(1, count($attendanceOnlyRecords));
    assert_same('present', $attendanceOnlyRecords[0]['status']);

    // Test absence view inclusion (includes ONLY 'absent')
    $absenceOnlyCount = $attendance->countAll(null, '', '2026-08-01', '2026-08-02', null, null, 'absent');
    assert_same(2, $absenceOnlyCount);

    $absenceOnlyRecords = $attendance->findAll(null, '', '2026-08-01', '2026-08-02', 1, 20, null, null, 'absent');
    assert_same(2, count($absenceOnlyRecords));
    assert_same('absent', $absenceOnlyRecords[0]['status']);
    assert_same('absent', $absenceOnlyRecords[1]['status']);
});
