<?php

declare(strict_types=1);

use App\Models\LeaveRequest;

test('database integration: admin leave search works with native prepared statements and leave-date filters', function (): void {
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $leaveRequests = new LeaveRequest($database->pdo());

    $leaveId = $leaveRequests->create($ids['other_employee_id'], 'medical', '2026-07-24', '2026-07-24', 1, 'Scheduled appointment', 'approved');
    assert_true($leaveId > 0);

    $nameMatches = $leaveRequests->findAll(null, 'approved', null, 'Other Employee', '2026-07-01', '2026-07-31');
    assert_same(1, count($nameMatches));
    assert_same($leaveId, (int) $nameMatches[0]['id']);
    assert_same(1, $leaveRequests->countAll(null, 'approved', null, 'Other Employee', '2026-07-01', '2026-07-31'));

    $emailMatches = $leaveRequests->findAll(null, 'approved', 'medical', 'other.integration', '2026-07-24', '2026-07-24');
    assert_same(1, count($emailMatches));
    assert_same($leaveId, (int) $emailMatches[0]['id']);
    assert_same(1, $leaveRequests->countAll(null, 'approved', 'medical', 'other.integration', '2026-07-24', '2026-07-24'));

    assert_same([], $leaveRequests->findAll(null, 'approved', null, 'Other Employee', '2026-07-01', '2026-07-13'));
    assert_same(0, $leaveRequests->countAll(null, 'approved', null, 'Other Employee', '2026-07-01', '2026-07-13'));
});

test('database integration: employee leave listings and cancellation remain owner-scoped', function (): void {
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $leaveRequests = new LeaveRequest($database->pdo());

    $ownPendingId = $leaveRequests->create($ids['employee_id'], 'casual', '2026-09-01', '2026-09-01', 1, 'Own pending', 'pending');
    $otherPendingId = $leaveRequests->create($ids['other_employee_id'], 'casual', '2026-09-02', '2026-09-02', 1, 'Other pending', 'pending');
    $ownApprovedId = $leaveRequests->create($ids['employee_id'], 'medical', '2026-09-03', '2026-09-03', 1, 'Own approved', 'approved');

    $employeeRequests = $leaveRequests->findRequestsForEmployee($ids['employee_id']);
    $requestIds = array_map(static fn (array $request): int => (int) $request['id'], $employeeRequests);

    assert_true(in_array($ownPendingId, $requestIds, true));
    assert_true(in_array($ownApprovedId, $requestIds, true));
    assert_false(in_array($otherPendingId, $requestIds, true));
    assert_false($leaveRequests->cancelPendingRequestForEmployee($otherPendingId, $ids['employee_id'], '2026-08-01 10:00:00', 'leave.cancel_reasons.employee'));
    assert_false($leaveRequests->cancelPendingRequestForEmployee($ownApprovedId, $ids['employee_id'], '2026-08-01 10:00:00', 'leave.cancel_reasons.employee'));
    assert_true($leaveRequests->cancelPendingRequestForEmployee($ownPendingId, $ids['employee_id'], '2026-08-01 10:00:00', 'leave.cancel_reasons.employee'));

    $cancelled = $leaveRequests->findById($ownPendingId);
    assert_not_null($cancelled);
    assert_same('cancelled', $cancelled['status']);
});

test('database integration: admin leave decisions enforce V1 status transitions', function (): void {
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $leaveRequests = new LeaveRequest($database->pdo());

    $approveId = $leaveRequests->create($ids['employee_id'], 'casual', '2026-10-01', '2026-10-01', 1, null, 'pending');
    $rejectId = $leaveRequests->create($ids['employee_id'], 'medical', '2026-10-03', '2026-10-03', 1, null, 'pending');
    $cancelId = $leaveRequests->create($ids['employee_id'], 'unpaid', '2026-10-05', '2026-10-05', 1, null, 'approved');
    $rejectedId = $leaveRequests->create($ids['employee_id'], 'other', '2026-10-07', '2026-10-07', 1, null, 'rejected');

    assert_true($leaveRequests->approvePendingRequest($approveId, $ids['admin_id'], '2026-08-01 11:00:00'));
    assert_false($leaveRequests->approvePendingRequest($approveId, $ids['admin_id'], '2026-08-01 11:01:00'));
    assert_true($leaveRequests->rejectPendingRequest($rejectId, $ids['admin_id'], '2026-08-01 11:02:00', 'Not available'));
    assert_true($leaveRequests->cancelPendingOrApprovedRequestByAdmin($cancelId, $ids['admin_id'], '2026-08-01 11:03:00', 'leave.cancel_reasons.admin'));
    assert_false($leaveRequests->cancelPendingOrApprovedRequestByAdmin($rejectedId, $ids['admin_id'], '2026-08-01 11:04:00', 'leave.cancel_reasons.admin'));

    assert_same('approved', $leaveRequests->findById($approveId)['status']);
    assert_same('rejected', $leaveRequests->findById($rejectId)['status']);
    assert_same('cancelled', $leaveRequests->findById($cancelId)['status']);
    assert_same('rejected', $leaveRequests->findById($rejectedId)['status']);
});
