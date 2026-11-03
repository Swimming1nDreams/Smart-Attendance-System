<?php

declare(strict_types=1);

use App\Services\AttendanceCalculationService;
use App\Services\AttendanceOperationException;
use App\Services\AttendanceOperationService;
use App\Services\AttendancePolicyService;
use App\Services\LeaveOperationService;
use App\Services\LeaveReasonCatalog;
use App\Services\WorkScheduleResolverService;
use App\Services\DateValidationService;
use App\Services\AuditLogService;

if ((string) (getenv('OAAS_ALLOW_DEMO_SEED') ?: getenv('SAPAS_ALLOW_DEMO_SEED')) !== '1') {
    fwrite(STDERR, "September 2026 demo seed blocked. Set OAAS_ALLOW_DEMO_SEED=1 to run this dev-only script.\n");
    exit(1);
}

require_once dirname(__DIR__) . '/vendor/autoload.php';

final class September2026DemoSeeder
{
    private const REMARK_PREFIX = 'September 2026 demo seed';

    public static function run(?PDO $existingConnection = null): void
    {
        $connection = $existingConnection ?? self::connect();
        $connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $connection->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $databaseName = (string) $connection->query('SELECT DATABASE()')->fetchColumn();
        if ($databaseName === '') {
            throw new RuntimeException('No database is selected.');
        }

        $policy = new AttendancePolicyService();
        $resolver = new WorkScheduleResolverService($connection);
        $calculator = new AttendanceCalculationService();
        $operations = new AttendanceOperationService($connection, $policy, null, $resolver, $calculator, null);

        $dateValidator = new DateValidationService();
        $auditLogService = new AuditLogService($connection);
        $leaveOperations = new LeaveOperationService($connection, $dateValidator, $auditLogService);

        $seedDomain = '@' . (getenv('OAAS_DEMO_EMAIL_DOMAIN') ?: getenv('SAPAS_DEMO_EMAIL_DOMAIN') ?: 'silveroakhr.com');
        $records = self::attendanceRecords($seedDomain);
        
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];

        foreach ($records as $record) {
            $userId = self::findEmployeeId($connection, $record['email']);
            if ($userId === null) {
                $errors[] = $record['date'] . ' ' . $record['email'] . ': employee not found; run seeds/seed_demo_data.php first.';
                continue;
            }

            $existing = self::findAttendance($connection, $userId, $record['date']);
            $remarks = self::REMARK_PREFIX . ' — ' . $record['note'];

            try {
                if ($existing === null) {
                    $operations->createRecord(
                        $userId,
                        $record['date'],
                        $record['clock_in'],
                        $record['clock_out'],
                        $record['status'],
                        $remarks,
                    );
                    $created++;
                    continue;
                }

                if (!str_starts_with((string) ($existing['remarks'] ?? ''), self::REMARK_PREFIX)) {
                    $skipped++;
                    fwrite(STDOUT, sprintf(
                        "Skipped protected existing attendance: user=%d date=%s id=%d\n",
                        $userId,
                        $record['date'],
                        (int) $existing['id'],
                    ));
                    continue;
                }

                $operations->updateRecord(
                    (int) $existing['id'],
                    $userId,
                    $record['date'],
                    $record['clock_in'],
                    $record['clock_out'],
                    $record['status'],
                    $remarks,
                    null,
                    [],
                    AttendanceOperationService::freshnessToken($existing),
                );
                $updated++;
            } catch (AttendanceOperationException $exception) {
                $errors[] = sprintf(
                    '%s %s: %s',
                    $record['date'],
                    $record['email'],
                    $exception->reason(),
                );
            }
        }

        // Seed Leaves
        $adminId = self::findAdminUserId($connection);
        if ($adminId !== null) {
            $leavesSeeded = self::seedLeaves($connection, $leaveOperations, $seedDomain, $adminId);
            printf("Leave Requests Seeded/Ensured: %d\n", $leavesSeeded);
        } else {
            $errors[] = 'Admin user not found. Leaves were not seeded.';
        }

        $summary = $connection->prepare(
            "SELECT COUNT(*) AS record_count,
                    COALESCE(SUM(calculated_overtime_minutes), 0) AS overtime_minutes,
                    SUM(CASE WHEN attendance_state = 'incomplete' THEN 1 ELSE 0 END) AS incomplete_count
             FROM attendance_records
             WHERE attendance_date BETWEEN '2026-09-01' AND '2026-09-08'
               AND remarks LIKE :remark_prefix"
        );
        $summary->execute([':remark_prefix' => self::REMARK_PREFIX . '%']);
        $result = $summary->fetch() ?: [];

        printf("September 2026 demo seed complete for database `%s`.\n", $databaseName);
        printf("Created: %d | Updated: %d | Protected/skipped: %d\n", $created, $updated, $skipped);
        printf(
            "Seeded records: %d | Calculated overtime: %d minutes | Incomplete: %d\n",
            (int) ($result['record_count'] ?? 0),
            (int) ($result['overtime_minutes'] ?? 0),
            (int) ($result['incomplete_count'] ?? 0),
        );

        if ($errors !== []) {
            fwrite(STDERR, "Some September demo records could not be seeded:\n- " . implode("\n- ", $errors) . "\n");
            exit(1);
        }
    }

    private static function connect(): PDO
    {
        $config = require dirname(__DIR__) . '/config/database.php';
        $connection = new PDO(
            sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['host'], $config['name'], $config['charset']),
            $config['user'],
            $config['pass'],
            $config['options'],
        );
        $connection->exec("SET time_zone = " . $connection->quote((string) ($config['timezone'] ?? '+06:30')));

        return $connection;
    }

    private static function findEmployeeId(PDO $connection, string $email): ?int
    {
        $statement = $connection->prepare(
            "SELECT id FROM users WHERE email = :email AND status = 'active' LIMIT 1"
        );
        $statement->execute([':email' => $email]);
        $id = $statement->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    private static function findAdminUserId(PDO $connection): ?int
    {
        $statement = $connection->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id ASC LIMIT 1");
        $id = $statement->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    private static function findAttendance(PDO $connection, int $userId, string $date): ?array
    {
        $statement = $connection->prepare(
            'SELECT * FROM attendance_records WHERE user_id = :user_id AND attendance_date = :attendance_date LIMIT 1'
        );
        $statement->execute([':user_id' => $userId, ':attendance_date' => $date]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    private static function findLeaveByDates(PDO $connection, int $userId, string $startDate, string $endDate): ?int
    {
        $statement = $connection->prepare(
            'SELECT id FROM leave_requests WHERE user_id = :user_id AND start_date = :start_date AND end_date = :end_date LIMIT 1'
        );
        $statement->execute([':user_id' => $userId, ':start_date' => $startDate, ':end_date' => $endDate]);
        $id = $statement->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    private static function seedLeaves(PDO $connection, LeaveOperationService $leaveOperations, string $seedDomain, int $adminId): int
    {
        $email = static fn (string $local): string => $local . $seedDomain;
        $leaveScenarios = [
            [
                'email' => $email('htet.naing'),
                'type' => 'casual',
                'category' => 'personal_appointment',
                'start' => '2026-09-04',
                'end' => '2026-09-04',
                'days' => 1,
                'reason' => 'Scheduled personal appointment',
                'status' => 'approved',
                'created_at' => '2026-08-25 09:15:00',
                'reviewed_at' => '2026-08-26 10:00:00'
            ],
            [
                'email' => $email('su.myat.noe'),
                'type' => 'medical',
                'category' => 'medical_treatment',
                'start' => '2026-09-09',
                'end' => '2026-09-10',
                'days' => 2,
                'reason' => 'Upcoming medical treatment',
                'status' => 'pending',
                'created_at' => '2026-09-02 11:30:00',
                'reviewed_at' => null
            ],
            [
                'email' => $email('may.thu.hlaing'),
                'type' => 'unpaid',
                'category' => 'travel',
                'start' => '2026-09-01',
                'end' => '2026-09-01',
                'days' => 1,
                'reason' => 'Out of town travel',
                'status' => 'rejected',
                'created_at' => '2026-08-28 14:00:00',
                'reviewed_at' => '2026-08-29 09:30:00'
            ],
        ];

        $seededCount = 0;

        foreach ($leaveScenarios as $scenario) {
            $userId = self::findEmployeeId($connection, $scenario['email']);
            if (!$userId) continue;

            $existingLeaveId = self::findLeaveByDates($connection, $userId, $scenario['start'], $scenario['end']);
            
            if ($existingLeaveId === null) {
                // Manually insert to have precise control over created_at to avoid issues with history
                $stmt = $connection->prepare(
                    'INSERT INTO leave_requests (user_id, leave_type, reason_category, start_date, end_date, duration_days, reason, status, reviewed_by, reviewed_at, created_at) 
                     VALUES (:user_id, :leave_type, :reason_category, :start_date, :end_date, :duration_days, :reason, :status, :reviewed_by, :reviewed_at, :created_at)'
                );
                $isReviewed = in_array($scenario['status'], ['approved', 'rejected'], true);
                
                $stmt->execute([
                    ':user_id' => $userId,
                    ':leave_type' => $scenario['type'],
                    ':reason_category' => $scenario['category'],
                    ':start_date' => $scenario['start'],
                    ':end_date' => $scenario['end'],
                    ':duration_days' => $scenario['days'],
                    ':reason' => $scenario['reason'],
                    ':status' => $scenario['status'],
                    ':reviewed_by' => $isReviewed ? $adminId : null,
                    ':reviewed_at' => $isReviewed ? $scenario['reviewed_at'] : null,
                    ':created_at' => $scenario['created_at']
                ]);
                
                $leaveId = (int) $connection->lastInsertId();
                self::seedLeaveNotification($connection, $leaveId, $userId, $adminId, $scenario['status'], $scenario['created_at'], $scenario['reviewed_at']);
                $seededCount++;
            }
        }

        return $seededCount;
    }

    private static function seedLeaveNotification(PDO $pdo, int $leaveId, int $employeeId, ?int $adminId, string $status, string $createdAt, ?string $reviewedAt): void
    {
        $statement = $pdo->prepare(
            'INSERT INTO notifications (user_id, type, title_key, message_key, link_url, related_user_id, related_leave_request_id, read_at, created_at)
             VALUES (:user_id, :type, :title_key, :message_key, :link_url, :related_user_id, :related_leave_request_id, :read_at, :created_at)'
        );

        if ($status === 'pending' && $adminId !== null) {
            $statement->execute([
                ':user_id' => $adminId,
                ':type' => 'leave_request_submitted',
                ':title_key' => 'notifications.events.leave_request_submitted.title',
                ':message_key' => 'notifications.events.leave_request_submitted.message',
                ':link_url' => '/admin/leave/show?id=' . $leaveId,
                ':related_user_id' => $employeeId,
                ':related_leave_request_id' => $leaveId,
                ':read_at' => null,
                ':created_at' => $createdAt,
            ]);
            return;
        }

        if (!in_array($status, ['approved', 'rejected'], true)) {
            return;
        }

        $event = $status === 'approved' ? 'leave_approved' : 'leave_rejected';
        $statement->execute([
            ':user_id' => $employeeId,
            ':type' => $event,
            ':title_key' => 'notifications.events.' . $event . '.title',
            ':message_key' => 'notifications.events.' . $event . '.message',
            ':link_url' => '/leave/show?id=' . $leaveId,
            ':related_user_id' => null,
            ':related_leave_request_id' => $leaveId,
            ':read_at' => null,
            ':created_at' => $reviewedAt ?? $createdAt,
        ]);
    }

    private static function attendanceRecords(string $seedDomain): array
    {
        $email = static fn (string $local): string => $local . $seedDomain;

        return [
            // Tuesday, 1 September
            ['email' => $email('thiri.aung'), 'date' => '2026-09-01', 'clock_in' => '09:00:00', 'clock_out' => '17:00:00', 'status' => 'present', 'note' => 'On-time shift'],
            ['email' => $email('ei.mon.kyaw'), 'date' => '2026-09-01', 'clock_in' => '08:50:00', 'clock_out' => '17:15:00', 'status' => 'present', 'note' => 'Standard shift'],
            ['email' => $email('htet.naing'), 'date' => '2026-09-01', 'clock_in' => '08:48:00', 'clock_out' => '17:12:00', 'status' => 'present', 'note' => 'Standard shift'],
            ['email' => $email('su.myat.noe'), 'date' => '2026-09-01', 'clock_in' => '08:55:00', 'clock_out' => '17:05:00', 'status' => 'present', 'note' => 'Standard shift'],
            ['email' => $email('nay.chi.win'), 'date' => '2026-09-01', 'clock_in' => '09:02:00', 'clock_out' => '17:30:00', 'status' => 'present', 'note' => 'Standard shift'],
            ['email' => $email('pyae.sone.aung'), 'date' => '2026-09-01', 'clock_in' => '09:00:00', 'clock_out' => '19:00:00', 'status' => 'present', 'note' => 'Overtime shift'],
            ['email' => $email('ko.ko.lwin'), 'date' => '2026-09-01', 'clock_in' => '08:56:00', 'clock_out' => '17:01:00', 'status' => 'present', 'note' => 'Standard shift'],
            ['email' => $email('may.thu.hlaing'), 'date' => '2026-09-01', 'clock_in' => '09:05:00', 'clock_out' => '17:00:00', 'status' => 'present', 'note' => 'Standard shift'],

            // Wednesday, 2 September
            ['email' => $email('thiri.aung'), 'date' => '2026-09-02', 'clock_in' => '08:50:00', 'clock_out' => '18:30:00', 'status' => 'present', 'note' => 'Overtime shift'],
            ['email' => $email('ei.mon.kyaw'), 'date' => '2026-09-02', 'clock_in' => '08:55:00', 'clock_out' => '17:05:00', 'status' => 'present', 'note' => 'Standard shift'],
            ['email' => $email('htet.naing'), 'date' => '2026-09-02', 'clock_in' => '08:58:00', 'clock_out' => '17:00:00', 'status' => 'present', 'note' => 'Standard shift'],
            ['email' => $email('su.myat.noe'), 'date' => '2026-09-02', 'clock_in' => '09:05:00', 'clock_out' => '17:00:00', 'status' => 'present', 'note' => 'Standard shift'],
            ['email' => $email('nay.chi.win'), 'date' => '2026-09-02', 'clock_in' => '08:55:00', 'clock_out' => '17:12:00', 'status' => 'present', 'note' => 'Standard shift'],
            ['email' => $email('pyae.sone.aung'), 'date' => '2026-09-02', 'clock_in' => '08:50:00', 'clock_out' => '17:05:00', 'status' => 'present', 'note' => 'Standard shift'],
            ['email' => $email('ko.ko.lwin'), 'date' => '2026-09-02', 'clock_in' => '08:55:00', 'clock_out' => '17:00:00', 'status' => 'present', 'note' => 'Standard shift'],
            ['email' => $email('may.thu.hlaing'), 'date' => '2026-09-02', 'clock_in' => '09:41:00', 'clock_out' => '17:30:00', 'status' => 'present', 'note' => 'Late arrival'],

            // Thursday, 3 September
            ['email' => $email('thiri.aung'), 'date' => '2026-09-03', 'clock_in' => '08:55:00', 'clock_out' => '17:00:00', 'status' => 'present', 'note' => 'Standard shift'],
            ['email' => $email('ei.mon.kyaw'), 'date' => '2026-09-03', 'clock_in' => '09:35:00', 'clock_out' => '17:00:00', 'status' => 'present', 'note' => 'Late arrival'],
            ['email' => $email('htet.naing'), 'date' => '2026-09-03', 'clock_in' => '08:50:00', 'clock_out' => '17:15:00', 'status' => 'present', 'note' => 'Standard shift'],
            ['email' => $email('su.myat.noe'), 'date' => '2026-09-03', 'clock_in' => '08:55:00', 'clock_out' => '17:05:00', 'status' => 'present', 'note' => 'Standard shift'],
            ['email' => $email('nay.chi.win'), 'date' => '2026-09-03', 'clock_in' => '08:48:00', 'clock_out' => '17:00:00', 'status' => 'present', 'note' => 'Standard shift'],
            ['email' => $email('pyae.sone.aung'), 'date' => '2026-09-03', 'clock_in' => '09:00:00', 'clock_out' => '18:05:00', 'status' => 'present', 'note' => 'Overtime shift'],
            ['email' => $email('ko.ko.lwin'), 'date' => '2026-09-03', 'clock_in' => '08:50:00', 'clock_out' => '17:00:00', 'status' => 'present', 'note' => 'Standard shift'],
            ['email' => $email('may.thu.hlaing'), 'date' => '2026-09-03', 'clock_in' => '08:56:00', 'clock_out' => '17:00:00', 'status' => 'present', 'note' => 'Standard shift'],

            // Friday, 4 September
            ['email' => $email('thiri.aung'), 'date' => '2026-09-04', 'clock_in' => '09:00:00', 'clock_out' => '17:00:00', 'status' => 'present', 'note' => 'Standard shift'],
            ['email' => $email('ei.mon.kyaw'), 'date' => '2026-09-04', 'clock_in' => '08:50:00', 'clock_out' => '17:05:00', 'status' => 'present', 'note' => 'Standard shift'],
            ['email' => $email('su.myat.noe'), 'date' => '2026-09-04', 'clock_in' => '08:55:00', 'clock_out' => '17:00:00', 'status' => 'present', 'note' => 'Standard shift'],
            ['email' => $email('nay.chi.win'), 'date' => '2026-09-04', 'clock_in' => '09:00:00', 'clock_out' => '17:00:00', 'status' => 'present', 'note' => 'Standard shift'],
            ['email' => $email('pyae.sone.aung'), 'date' => '2026-09-04', 'clock_in' => '08:55:00', 'clock_out' => '17:00:00', 'status' => 'present', 'note' => 'Standard shift'],
            ['email' => $email('ko.ko.lwin'), 'date' => '2026-09-04', 'clock_in' => '08:55:00', 'clock_out' => '17:00:00', 'status' => 'present', 'note' => 'Standard shift'],
            ['email' => $email('may.thu.hlaing'), 'date' => '2026-09-04', 'clock_in' => '09:05:00', 'clock_out' => '17:00:00', 'status' => 'present', 'note' => 'Standard shift'],

            // Saturday, 5 September
            ['email' => $email('ko.ko.lwin'), 'date' => '2026-09-05', 'clock_in' => '10:00:00', 'clock_out' => '15:00:00', 'status' => 'present', 'note' => 'Weekend support coverage'],

            // Monday, 7 September
            ['email' => $email('thiri.aung'), 'date' => '2026-09-07', 'clock_in' => '08:50:00', 'clock_out' => '17:10:00', 'status' => 'present', 'note' => 'Standard shift'],
            ['email' => $email('ei.mon.kyaw'), 'date' => '2026-09-07', 'clock_in' => '09:00:00', 'clock_out' => '17:00:00', 'status' => 'present', 'note' => 'Standard shift'],
            ['email' => $email('htet.naing'), 'date' => '2026-09-07', 'clock_in' => '08:55:00', 'clock_out' => '17:00:00', 'status' => 'present', 'note' => 'Standard shift'],
            ['email' => $email('su.myat.noe'), 'date' => '2026-09-07', 'clock_in' => '08:50:00', 'clock_out' => '15:30:00', 'status' => 'present', 'note' => 'Early departure'],
            ['email' => $email('nay.chi.win'), 'date' => '2026-09-07', 'clock_in' => '08:55:00', 'clock_out' => '17:12:00', 'status' => 'present', 'note' => 'Standard shift'],
            ['email' => $email('pyae.sone.aung'), 'date' => '2026-09-07', 'clock_in' => '09:00:00', 'clock_out' => '17:45:00', 'status' => 'present', 'note' => 'Overtime shift'],
            ['email' => $email('ko.ko.lwin'), 'date' => '2026-09-07', 'clock_in' => '09:05:00', 'clock_out' => '17:00:00', 'status' => 'present', 'note' => 'Standard shift'],
            ['email' => $email('may.thu.hlaing'), 'date' => '2026-09-07', 'clock_in' => '09:00:00', 'clock_out' => '17:00:00', 'status' => 'present', 'note' => 'Standard shift'],

            // Tuesday, 8 September
            ['email' => $email('thiri.aung'), 'date' => '2026-09-08', 'clock_in' => '08:55:00', 'clock_out' => '17:00:00', 'status' => 'present', 'note' => 'Standard shift'],
            ['email' => $email('ei.mon.kyaw'), 'date' => '2026-09-08', 'clock_in' => '08:50:00', 'clock_out' => '17:00:00', 'status' => 'present', 'note' => 'Standard shift'],
            ['email' => $email('htet.naing'), 'date' => '2026-09-08', 'clock_in' => '09:00:00', 'clock_out' => '17:00:00', 'status' => 'present', 'note' => 'Standard shift'],
            ['email' => $email('su.myat.noe'), 'date' => '2026-09-08', 'clock_in' => '08:55:00', 'clock_out' => '17:00:00', 'status' => 'present', 'note' => 'Standard shift'],
            ['email' => $email('nay.chi.win'), 'date' => '2026-09-08', 'clock_in' => '09:00:00', 'clock_out' => null, 'status' => 'present', 'note' => 'Missing Checkout'],
            ['email' => $email('pyae.sone.aung'), 'date' => '2026-09-08', 'clock_in' => '08:55:00', 'clock_out' => '17:05:00', 'status' => 'present', 'note' => 'Standard shift'],
            ['email' => $email('ko.ko.lwin'), 'date' => '2026-09-08', 'clock_in' => '08:50:00', 'clock_out' => '17:00:00', 'status' => 'present', 'note' => 'Standard shift'],
            ['email' => $email('may.thu.hlaing'), 'date' => '2026-09-08', 'clock_in' => '09:27:00', 'clock_out' => '17:00:00', 'status' => 'present', 'note' => 'Late arrival'],
        ];
    }
}

September2026DemoSeeder::run(isset($pdo) && $pdo instanceof PDO ? $pdo : null);
