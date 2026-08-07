<?php

declare(strict_types=1);

use App\Models\Attendance;
use App\Models\LeaveRequest;

test('database integration: attendance summary and exception report filters use seeded records', function (): void {
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $attendance = new Attendance($database->pdo());

    $attendance->create($ids['employee_id'], '2026-11-02', '09:05:00', '17:00:00', 7.92, 'present', 0, null);
    $attendance->create($ids['employee_id'], '2026-11-03', '10:00:00', '17:00:00', 7.00, 'present', 60, null);
    $attendance->create($ids['other_employee_id'], '2026-11-04', null, null, 0.00, 'absent', 0, null);

    $totals = $attendance->getAttendanceSummaryReportTotals('2026-11-01', '2026-11-30', $ids['department_id'], null);
    assert_same(3, $totals['total_records']);
    assert_same(2, $totals['present_count']);
    assert_same(1, $totals['late_count']);
    assert_same(1, $totals['absent_count']);
    assert_same(895, $totals['total_work_minutes']);

    assert_same(2, $attendance->countLateAbsentExceptionReportRecords('2026-11-01', '2026-11-30', null, null, 'all'));
    assert_same(1, $attendance->countLateAbsentExceptionReportRecords('2026-11-01', '2026-11-30', null, null, 'late'));
    assert_same(1, $attendance->countLateAbsentExceptionReportRecords('2026-11-01', '2026-11-30', null, null, 'absent'));
});

test('database integration: leave request report filters respect status type and date overlap', function (): void {
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $leaveRequests = new LeaveRequest($database->pdo());

    $approvedId = $leaveRequests->create($ids['employee_id'], 'medical', '2026-12-05', '2026-12-07', 3, 'Medical', 'approved');
    $leaveRequests->create($ids['employee_id'], 'casual', '2026-12-10', '2026-12-10', 1, 'Casual', 'pending');
    $leaveRequests->create($ids['other_employee_id'], 'medical', '2027-01-01', '2027-01-01', 1, 'Other range', 'approved');

    $matches = $leaveRequests->findAllRequestsForAdminListing(null, 'approved', 'medical', '', '2026-12-06', '2026-12-06', 1, 20);

    assert_same(1, count($matches));
    assert_same($approvedId, (int) $matches[0]['id']);
    assert_same(1, $leaveRequests->countAllRequestsForAdminListing(null, 'approved', 'medical', '', '2026-12-06', '2026-12-06'));
});
