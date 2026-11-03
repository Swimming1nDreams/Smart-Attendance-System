<?php

declare(strict_types=1);

use App\Services\AttendanceOperationException;
use App\Services\AttendanceOperationService;
use App\Services\AttendancePolicyService;
use App\Services\LeaveOperationException;
use App\Services\LeaveOperationService;

test('database integration: department-head attendance corrections are limited to employees in the assigned department', function (): void {
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $pdo = $database->pdo();

    [$departmentHeadId, $outsideEmployeeId] = delegated_authority_fixture($pdo, $ids);
    $operations = new AttendanceOperationService($pdo, new AttendancePolicyService());

    $operations->createRecord($ids['employee_id'], '2026-10-05', '09:00:00', '17:00:00', 'present', 'same department', $departmentHeadId);

    assert_same('authorization_denied', delegated_attendance_reason(static function () use ($operations, $outsideEmployeeId, $departmentHeadId): void {
        $operations->createRecord($outsideEmployeeId, '2026-10-05', '09:00:00', '17:00:00', 'present', 'outside department', $departmentHeadId);
    }));
});

test('database integration: department-head leave decisions are scoped and their own leave remains admin-reviewed', function (): void {
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $pdo = $database->pdo();

    [$departmentHeadId, $outsideEmployeeId] = delegated_authority_fixture($pdo, $ids);
    $leaveOperations = new LeaveOperationService($pdo);

    $sameDepartmentLeave = delegated_pending_leave($pdo, $ids['employee_id'], '2026-10-12');
    $outsideDepartmentLeave = delegated_pending_leave($pdo, $outsideEmployeeId, '2026-10-13');
    $departmentHeadLeave = delegated_pending_leave($pdo, $departmentHeadId, '2026-10-14');

    $leaveOperations->approve($sameDepartmentLeave, $departmentHeadId, '2026-10-01 09:00:00');

    assert_same('authorization_denied', delegated_leave_reason(static function () use ($leaveOperations, $outsideDepartmentLeave, $departmentHeadId): void {
        $leaveOperations->reject($outsideDepartmentLeave, $departmentHeadId, '2026-10-01 09:05:00', 'Out of scope');
    }));
    assert_same('authorization_denied', delegated_leave_reason(static function () use ($leaveOperations, $departmentHeadLeave, $departmentHeadId): void {
        $leaveOperations->approve($departmentHeadLeave, $departmentHeadId, '2026-10-01 09:10:00');
    }));

    $leaveOperations->approve($departmentHeadLeave, $ids['admin_id'], '2026-10-01 09:15:00');
});

/** @return array{int, int} */
function delegated_authority_fixture(PDO $pdo, array $ids): array
{
    $pdo->exec("INSERT INTO departments (name, description) VALUES ('Operations', 'Outside delegated scope')");
    $outsideDepartmentId = (int) $pdo->lastInsertId();
    $insert = $pdo->prepare(
        'INSERT INTO users (name, email, password, role, department_id, position_id, status, staff_code, employment_type, join_date)
         VALUES (:name, :email, :password, :role, :department_id, :position_id, :status, :staff_code, :employment_type, :join_date)'
    );

    $insert->execute([
        ':name' => 'Engineering Department Head',
        ':email' => 'head.integration@sapas.test',
        ':password' => password_hash('test-password', PASSWORD_DEFAULT),
        ':role' => 'department_head',
        ':department_id' => $ids['department_id'],
        ':position_id' => $ids['position_id'],
        ':status' => 'active',
        ':staff_code' => 'HD-0001',
        ':employment_type' => 'full-time',
        ':join_date' => '2026-01-01',
    ]);
    $departmentHeadId = (int) $pdo->lastInsertId();

    $insert->execute([
        ':name' => 'Operations Employee',
        ':email' => 'outside.integration@sapas.test',
        ':password' => password_hash('test-password', PASSWORD_DEFAULT),
        ':role' => 'employee',
        ':department_id' => $outsideDepartmentId,
        ':position_id' => $ids['position_id'],
        ':status' => 'active',
        ':staff_code' => 'OP-0001',
        ':employment_type' => 'full-time',
        ':join_date' => '2026-01-01',
    ]);

    return [$departmentHeadId, (int) $pdo->lastInsertId()];
}

function delegated_pending_leave(PDO $pdo, int $userId, string $date): int
{
    $statement = $pdo->prepare(
        "INSERT INTO leave_requests (user_id, leave_type, reason_category, start_date, end_date, duration_days, reason, status)
         VALUES (:user_id, 'casual', 'personal_matter', :start_date, :end_date, 1, 'Integration leave', 'pending')"
    );
    $statement->execute([':user_id' => $userId, ':start_date' => $date, ':end_date' => $date]);

    return (int) $pdo->lastInsertId();
}

function delegated_attendance_reason(callable $callback): string
{
    try {
        $callback();
    } catch (AttendanceOperationException $exception) {
        return $exception->reason();
    }

    return '';
}

function delegated_leave_reason(callable $callback): string
{
    try {
        $callback();
    } catch (LeaveOperationException $exception) {
        return $exception->reason();
    }

    return '';
}
