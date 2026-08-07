<?php

declare(strict_types=1);

use App\Models\Attendance;
use App\Models\LeaveRequest;
use App\Services\AttendanceCalculationService;
use App\Services\AttendanceOperationException;
use App\Services\AttendanceOperationService;
use App\Services\AttendancePolicyService;
use App\Services\LeaveOperationException;
use App\Services\LeaveOperationService;
use App\Services\WorkScheduleResolverService;

test('database integration: admin attendance add blocks approved leave but allows non-approved leave statuses', function (): void {
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $pdo = $database->pdo();
    $leaves = new LeaveRequest($pdo);
    $attendance = new Attendance($pdo, new AttendancePolicyService());
    $operations = new AttendanceOperationService(
        $pdo,
        new AttendancePolicyService(),
        null,
        new WorkScheduleResolverService($pdo),
        new AttendanceCalculationService()
    );

    $leaves->create($ids['employee_id'], 'casual', '2026-09-01', '2026-09-01', 1, 'approved leave', 'approved');
    $leaves->create($ids['employee_id'], 'medical', '2026-09-02', '2026-09-02', 1, 'pending leave', 'pending');
    $leaves->create($ids['employee_id'], 'casual', '2026-09-03', '2026-09-03', 1, 'rejected leave', 'rejected');
    $leaves->create($ids['employee_id'], 'unpaid', '2026-09-04', '2026-09-04', 1, 'cancelled leave', 'cancelled');
    $leaves->create($ids['employee_id'], 'unpaid', '2026-09-08', '2026-09-10', 3, 'approved multi-day leave', 'approved');

    assert_same('approved_leave_conflict', attendance_leave_interaction_reason(static function () use ($operations, $ids): void {
        $operations->createRecord($ids['employee_id'], '2026-09-01', '09:00:00', '17:00:00', 'present', 'approved single day');
    }));
    foreach (['2026-09-08', '2026-09-09', '2026-09-10'] as $date) {
        assert_true($leaves->existsApprovedLeaveForDate($ids['employee_id'], $date));
        assert_same('approved_leave_conflict', attendance_leave_interaction_reason(static function () use ($operations, $ids, $date): void {
            $operations->createRecord($ids['employee_id'], $date, '09:00:00', '17:00:00', 'present', 'approved range');
        }));
    }
    assert_false($leaves->existsApprovedLeaveForDate($ids['employee_id'], '2026-09-07'));
    assert_false($leaves->existsApprovedLeaveForDate($ids['employee_id'], '2026-09-11'));

    foreach (['2026-09-02', '2026-09-03', '2026-09-04'] as $date) {
        $operations->createRecord($ids['employee_id'], $date, '09:00:00', '17:00:00', 'present', 'non-approved leave status');
        $record = $attendance->findByUserIdAndDate($ids['employee_id'], $date);
        assert_not_null($record);
        assert_same('present', (string) $record['attendance_state']);
        assert_same(480, (int) $record['regular_minutes']);
        assert_same(0, (int) $record['calculated_overtime_minutes']);
    }
});

function attendance_leave_interaction_reason(callable $callback): ?string
{
    try {
        $callback();
    } catch (AttendanceOperationException $exception) {
        return $exception->reason();
    }

    return null;
}

test('database integration: leave approval blocks existing present and absent attendance without side effects', function (): void {
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $pdo = $database->pdo();
    $attendance = new Attendance($pdo, new AttendancePolicyService());
    $attendanceOperations = phase4d2_attendance_operations($pdo);
    $leaveOperations = new LeaveOperationService($pdo);
    $leaveModel = new LeaveRequest($pdo);

    $attendanceOperations->createRecord($ids['employee_id'], '2026-09-14', '09:00:00', '17:00:00', 'present', 'present conflict');
    $attendanceOperations->createRecord($ids['employee_id'], '2026-09-15', null, null, 'absent', 'absent conflict');
    $presentLeaveId = $leaveModel->create($ids['employee_id'], 'casual', '2026-09-14', '2026-09-14', 1, 'present overlap', 'pending');
    $absentLeaveId = $leaveModel->create($ids['employee_id'], 'medical', '2026-09-15', '2026-09-15', 1, 'absent overlap', 'pending');

    assert_same('attendance_conflict', phase4d2_leave_reason(static function () use ($leaveOperations, $presentLeaveId, $ids): void {
        $leaveOperations->approve($presentLeaveId, $ids['admin_id'], '2026-09-01 10:00:00');
    }));
    assert_same('attendance_conflict', phase4d2_leave_reason(static function () use ($leaveOperations, $absentLeaveId, $ids): void {
        $leaveOperations->approve($absentLeaveId, $ids['admin_id'], '2026-09-01 10:01:00');
    }));

    assert_same('pending', $leaveModel->findById($presentLeaveId)['status']);
    assert_same('pending', $leaveModel->findById($absentLeaveId)['status']);
    assert_same('present', $attendance->findByUserIdAndDate($ids['employee_id'], '2026-09-14')['attendance_state']);
    assert_same('absent', $attendance->findByUserIdAndDate($ids['employee_id'], '2026-09-15')['attendance_state']);
    assert_same(0, (int) $pdo->query("SELECT COUNT(*) FROM notifications")->fetchColumn());
    assert_same(0, (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'leave.approved'")->fetchColumn());
});

test('database integration: rejecting pending leave and cancelling approved leave preserve attendance rules', function (): void {
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $pdo = $database->pdo();
    $attendance = new Attendance($pdo, new AttendancePolicyService());
    $attendanceOperations = phase4d2_attendance_operations($pdo);
    $leaveOperations = new LeaveOperationService($pdo);
    $leaveModel = new LeaveRequest($pdo);

    $attendanceOperations->createRecord($ids['employee_id'], '2026-09-16', '09:00:00', '17:00:00', 'present', 'reject remains');
    $rejectLeaveId = $leaveModel->create($ids['employee_id'], 'casual', '2026-09-16', '2026-09-16', 1, 'reject overlap', 'pending');
    $leaveOperations->reject($rejectLeaveId, $ids['admin_id'], '2026-09-01 10:00:00', 'UAT attendance and leave <conflict> & resolution review 4D2');

    $record = $attendance->findByUserIdAndDate($ids['employee_id'], '2026-09-16');
    assert_same('rejected', $leaveModel->findById($rejectLeaveId)['status']);
    assert_same('09:00:00', $record['clock_in_time']);
    assert_same('17:00:00', $record['clock_out_time']);

    $attendanceOperations->createRecord($ids['employee_id'], '2026-09-17', '09:00:00', '17:00:00', 'present', 'editable source');
    $editable = $attendance->findByUserIdAndDate($ids['employee_id'], '2026-09-17');
    $approvedLeaveId = $leaveModel->create($ids['employee_id'], 'unpaid', '2026-09-18', '2026-09-18', 1, 'approved destination', 'approved');

    assert_same('approved_leave_conflict', attendance_leave_interaction_reason(static function () use ($attendanceOperations, $editable, $ids): void {
        $attendanceOperations->updateRecord((int) $editable['id'], $ids['employee_id'], '2026-09-18', '09:00:00', '17:00:00', 'present', 'blocked');
    }));
    assert_same('2026-09-17', $attendance->findById((int) $editable['id'])['attendance_date']);

    $leaveOperations->cancelByAdmin($approvedLeaveId, $ids['admin_id'], '2026-09-01 11:00:00', 'leave.cancel_reasons.admin');
    $attendanceOperations->updateRecord((int) $editable['id'], $ids['employee_id'], '2026-09-18', '09:00:00', '17:00:00', 'present', 'allowed after cancellation');
    $moved = $attendance->findById((int) $editable['id']);
    assert_same('cancelled', $leaveModel->findById($approvedLeaveId)['status']);
    assert_same('2026-09-18', $moved['attendance_date']);
    assert_same('present', $moved['attendance_state']);
});

test('database integration: attendance edit uses final employee and date against approved leave ranges', function (): void {
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $pdo = $database->pdo();
    $attendance = new Attendance($pdo, new AttendancePolicyService());
    $operations = phase4d2_attendance_operations($pdo);
    $leaveModel = new LeaveRequest($pdo);

    $operations->createRecord($ids['employee_id'], '2026-09-21', '09:00:00', '17:00:00', 'present', 'editable');
    $editable = $attendance->findByUserIdAndDate($ids['employee_id'], '2026-09-21');
    $leaveModel->create($ids['employee_id'], 'casual', '2026-09-23', '2026-09-25', 3, 'range', 'approved');
    $leaveModel->create($ids['other_employee_id'], 'casual', '2026-09-22', '2026-09-22', 1, 'other employee same date', 'approved');

    foreach (['2026-09-23', '2026-09-24', '2026-09-25'] as $date) {
        assert_same('approved_leave_conflict', attendance_leave_interaction_reason(static function () use ($operations, $editable, $ids, $date): void {
            $operations->updateRecord((int) $editable['id'], $ids['employee_id'], $date, '09:00:00', '17:00:00', 'present', 'blocked range');
        }));
        assert_same('2026-09-21', $attendance->findById((int) $editable['id'])['attendance_date']);
    }

    $operations->updateRecord((int) $editable['id'], $ids['employee_id'], '2026-09-22', '09:00:00', '17:00:00', 'present', 'adjacent before');
    $editable = $attendance->findById((int) $editable['id']);
    assert_same('2026-09-22', $editable['attendance_date']);
    $operations->updateRecord((int) $editable['id'], $ids['employee_id'], '2026-09-28', '09:00:00', '17:00:00', 'present', 'adjacent after');
    $editable = $attendance->findById((int) $editable['id']);
    assert_same('2026-09-28', $editable['attendance_date']);

    assert_same('approved_leave_conflict', attendance_leave_interaction_reason(static function () use ($operations, $editable, $ids): void {
        $operations->updateRecord((int) $editable['id'], $ids['other_employee_id'], '2026-09-22', '09:00:00', '17:00:00', 'present', 'employee conflict');
    }));
    assert_same($ids['employee_id'], (int) $attendance->findById((int) $editable['id'])['user_id']);
});

test('database integration: stale attendance edit rechecks leave state created after form load', function (): void {
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $pdo = $database->pdo();
    $attendance = new Attendance($pdo, new AttendancePolicyService());
    $operations = phase4d2_attendance_operations($pdo);
    $leaveModel = new LeaveRequest($pdo);

    $operations->createRecord($ids['employee_id'], '2026-09-29', '09:00:00', '17:00:00', 'present', 'opened form');
    $editable = $attendance->findByUserIdAndDate($ids['employee_id'], '2026-09-29');
    $staleToken = AttendanceOperationService::freshnessToken($editable);

    $leaveId = $leaveModel->create($ids['employee_id'], 'casual', '2026-09-30', '2026-09-30', 1, 'approved after form opened', 'pending');
    (new LeaveOperationService($pdo))->approve($leaveId, $ids['admin_id'], '2026-09-01 12:00:00');

    assert_same('approved_leave_conflict', attendance_leave_interaction_reason(static function () use ($operations, $editable, $ids, $staleToken): void {
        $operations->updateRecord((int) $editable['id'], $ids['employee_id'], '2026-09-30', '09:00:00', '17:00:00', 'present', 'stale bypass attempt', null, [], $staleToken);
    }));
    $current = $attendance->findById((int) $editable['id']);
    assert_same('2026-09-29', $current['attendance_date']);
    assert_same('approved', $leaveModel->findById($leaveId)['status']);
});

function phase4d2_attendance_operations(PDO $pdo): AttendanceOperationService
{
    return new AttendanceOperationService(
        $pdo,
        new AttendancePolicyService(),
        null,
        new WorkScheduleResolverService($pdo),
        new AttendanceCalculationService()
    );
}

function phase4d2_leave_reason(callable $callback): ?string
{
    try {
        $callback();
    } catch (LeaveOperationException $exception) {
        return $exception->reason();
    }

    return null;
}
