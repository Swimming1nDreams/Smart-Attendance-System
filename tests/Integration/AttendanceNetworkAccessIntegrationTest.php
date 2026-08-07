<?php

declare(strict_types=1);

use App\Models\Attendance;
use App\Services\AttendanceCalculationService;
use App\Services\AttendanceNetworkAccessService;
use App\Services\AttendanceOperationService;
use App\Services\AttendancePolicyService;
use App\Services\AuditLogService;
use App\Services\WorkScheduleResolverService;

test('database integration: denied office network access is audited without writing attendance', function (): void {
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $pdo = $database->pdo();
    $attendance = new Attendance($pdo, new AttendancePolicyService());
    $networkAccess = new AttendanceNetworkAccessService([
        'enabled' => true,
        'allowed_ips' => ['203.0.113.10'],
    ], new AuditLogService($pdo));

    assert_false($networkAccess->authorizeSelfAttendance(
        '198.51.100.25',
        $ids['employee_id'],
        'clock_in',
        ['user_agent' => 'SAPAS Network Test'],
    ));
    assert_null($attendance->findByUserIdAndDate($ids['employee_id'], '2026-07-22'));

    $audit = $pdo->query("SELECT action, entity_id, new_values, ip_address FROM audit_logs WHERE action = 'attendance.network_access_denied' LIMIT 1")->fetch();
    assert_true(is_array($audit));
    assert_same((string) $ids['employee_id'], (string) $audit['entity_id']);
    assert_same('198.51.100.25', $audit['ip_address']);
    assert_contains('clock_in', (string) $audit['new_values']);
    assert_not_contains('203.0.113.10', (string) $audit['new_values']);
});

test('database integration: allowed self attendance preserves success audits and denied checkout leaves record open', function (): void {
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $pdo = $database->pdo();
    attendance_network_seed_employee_profile($pdo, $ids['employee_id']);

    $policy = new AttendancePolicyService();
    $auditLog = new AuditLogService($pdo);
    $operations = new AttendanceOperationService($pdo, $policy, $auditLog, new WorkScheduleResolverService($pdo), new AttendanceCalculationService());
    $attendance = new Attendance($pdo, $policy);
    $networkAccess = new AttendanceNetworkAccessService([
        'enabled' => true,
        'allowed_ips' => ['127.0.0.1'],
    ], $auditLog);

    assert_true($networkAccess->authorizeSelfAttendance('127.0.0.1', $ids['employee_id'], 'clock_in'));
    $operations->clockIn($ids['employee_id'], '2026-07-22', '09:00:00', $ids['employee_id'], ['ip_address' => '127.0.0.1']);
    assert_same(1, attendance_network_audit_count($pdo, 'attendance.checked_in'));

    assert_false($networkAccess->authorizeSelfAttendance('198.51.100.25', $ids['employee_id'], 'clock_out'));
    $open = $attendance->findByUserIdAndDate($ids['employee_id'], '2026-07-22');
    assert_not_null($open);
    assert_null($open['clock_out_time']);
    assert_same(1, attendance_network_audit_count($pdo, 'attendance.network_access_denied'));

    assert_true($networkAccess->authorizeSelfAttendance('127.0.0.1', $ids['employee_id'], 'clock_out'));
    $operations->clockOut($ids['employee_id'], '2026-07-22', '17:00:00', $ids['employee_id'], ['ip_address' => '127.0.0.1']);
    assert_same(1, attendance_network_audit_count($pdo, 'attendance.checked_out'));
});

test('database integration: denied network audit failure remains non-critical', function (): void {
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $pdo = $database->pdo();
    $networkAccess = new AttendanceNetworkAccessService([
        'enabled' => true,
        'allowed_ips' => ['127.0.0.1'],
    ], new AuditLogService($pdo));

    $pdo->exec('DROP TABLE audit_logs');

    assert_false($networkAccess->authorizeSelfAttendance('198.51.100.25', $ids['employee_id'], 'clock_in'));
    assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM attendance_records')->fetchColumn());
});

function attendance_network_audit_count(\PDO $pdo, string $action): int
{
    $statement = $pdo->prepare('SELECT COUNT(*) FROM audit_logs WHERE action = :action');
    $statement->execute([':action' => $action]);

    return (int) $statement->fetchColumn();
}

function attendance_network_seed_employee_profile(\PDO $pdo, int $userId): void
{
    $statement = $pdo->prepare('INSERT INTO employee_profiles (user_id, phone, address) VALUES (:user_id, NULL, NULL)');
    $statement->execute([':user_id' => $userId]);
}
