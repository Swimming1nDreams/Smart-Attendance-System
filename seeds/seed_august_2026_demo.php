<?php

declare(strict_types=1);

use App\Services\AttendanceCalculationService;
use App\Services\AttendanceOperationException;
use App\Services\AttendanceOperationService;
use App\Services\AttendancePolicyService;
use App\Services\WorkScheduleResolverService;

if ((string) getenv('SAPAS_ALLOW_DEMO_SEED') !== '1') {
    fwrite(STDERR, "August 2026 demo seed blocked. Set SAPAS_ALLOW_DEMO_SEED=1 to run this dev-only script.\n");
    exit(1);
}

require_once dirname(__DIR__) . '/vendor/autoload.php';

final class August2026DemoSeeder
{
    private const REMARK_PREFIX = 'August 2026 demo seed';

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

        $seedDomain = '@' . (getenv('SAPAS_DEMO_EMAIL_DOMAIN') ?: 'silveroakhr.com');
        $records = self::records($seedDomain);
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

        $summary = $connection->prepare(
            "SELECT COUNT(*) AS record_count,
                    COALESCE(SUM(calculated_overtime_minutes), 0) AS overtime_minutes,
                    SUM(CASE WHEN attendance_state = 'incomplete' THEN 1 ELSE 0 END) AS incomplete_count
             FROM attendance_records
             WHERE attendance_date BETWEEN '2026-08-01' AND '2026-08-04'
               AND remarks LIKE :remark_prefix"
        );
        $summary->execute([':remark_prefix' => self::REMARK_PREFIX . '%']);
        $result = $summary->fetch() ?: [];

        printf("August 2026 demo seed complete for database `%s`.\n", $databaseName);
        printf("Created: %d | Updated: %d | Protected/skipped: %d\n", $created, $updated, $skipped);
        printf(
            "Seeded records: %d | Calculated overtime: %d minutes | Incomplete: %d\n",
            (int) ($result['record_count'] ?? 0),
            (int) ($result['overtime_minutes'] ?? 0),
            (int) ($result['incomplete_count'] ?? 0),
        );
        fwrite(STDOUT, "Note: 2026-08-04 is intentionally included as demonstration data.\n");

        if ($errors !== []) {
            fwrite(STDERR, "Some August demo records could not be seeded:\n- " . implode("\n- ", $errors) . "\n");
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
            "SELECT id FROM users WHERE email = :email AND role = 'employee' AND status = 'active' LIMIT 1"
        );
        $statement->execute([':email' => $email]);
        $id = $statement->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /** @return array<string, mixed>|null */
    private static function findAttendance(PDO $connection, int $userId, string $date): ?array
    {
        $statement = $connection->prepare(
            'SELECT * FROM attendance_records WHERE user_id = :user_id AND attendance_date = :attendance_date LIMIT 1'
        );
        $statement->execute([':user_id' => $userId, ':attendance_date' => $date]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @return list<array{email:string,date:string,clock_in:?string,clock_out:?string,status:string,note:string}> */
    private static function records(string $seedDomain): array
    {
        $email = static fn (string $local): string => $local . $seedDomain;

        return [
            // Saturday, 1 August: limited permitted operations/support coverage.
            ['email' => $email('thiri.aung'), 'date' => '2026-08-01', 'clock_in' => '10:00:00', 'clock_out' => '14:00:00', 'status' => 'present', 'note' => 'Saturday operations coverage'],
            ['email' => $email('nay.chi.win'), 'date' => '2026-08-01', 'clock_in' => '11:00:00', 'clock_out' => '15:30:00', 'status' => 'present', 'note' => 'Saturday customer-support coverage'],

            // Sunday, 2 August: one emergency support shift.
            ['email' => $email('pyae.sone.aung'), 'date' => '2026-08-02', 'clock_in' => '09:30:00', 'clock_out' => '13:00:00', 'status' => 'present', 'note' => 'Sunday emergency support coverage'],

            // Monday, 3 August: mixed realistic states.
            ['email' => $email('thiri.aung'), 'date' => '2026-08-03', 'clock_in' => '08:55:00', 'clock_out' => '17:10:00', 'status' => 'present', 'note' => 'On-time shift'],
            ['email' => $email('ei.mon.kyaw'), 'date' => '2026-08-03', 'clock_in' => '09:00:00', 'clock_out' => '17:00:00', 'status' => 'present', 'note' => 'Standard shift'],
            ['email' => $email('htet.naing'), 'date' => '2026-08-03', 'clock_in' => '09:42:00', 'clock_out' => '17:00:00', 'status' => 'present', 'note' => 'Late arrival'],
            ['email' => $email('su.myat.noe'), 'date' => '2026-08-03', 'clock_in' => '09:00:00', 'clock_out' => '16:00:00', 'status' => 'present', 'note' => 'Early departure'],
            ['email' => $email('nay.chi.win'), 'date' => '2026-08-03', 'clock_in' => '09:10:00', 'clock_out' => null, 'status' => 'present', 'note' => 'Missing Check Out'],
            ['email' => $email('pyae.sone.aung'), 'date' => '2026-08-03', 'clock_in' => null, 'clock_out' => null, 'status' => 'absent', 'note' => 'Absent workday'],
            ['email' => $email('ko.ko.lwin'), 'date' => '2026-08-03', 'clock_in' => '08:30:00', 'clock_out' => '18:00:00', 'status' => 'present', 'note' => 'Extended QA coverage'],
            ['email' => $email('may.thu.hlaing'), 'date' => '2026-08-03', 'clock_in' => '09:05:00', 'clock_out' => '17:30:00', 'status' => 'present', 'note' => 'Project follow-up'],

            // Tuesday, 4 August: explicit future/demo records for UI review.
            ['email' => $email('thiri.aung'), 'date' => '2026-08-04', 'clock_in' => '09:00:00', 'clock_out' => '17:00:00', 'status' => 'present', 'note' => 'Demonstration standard shift'],
            ['email' => $email('ei.mon.kyaw'), 'date' => '2026-08-04', 'clock_in' => '08:50:00', 'clock_out' => '17:15:00', 'status' => 'present', 'note' => 'Demonstration early start'],
            ['email' => $email('htet.naing'), 'date' => '2026-08-04', 'clock_in' => '09:35:00', 'clock_out' => '17:00:00', 'status' => 'present', 'note' => 'Demonstration late arrival'],
            ['email' => $email('su.myat.noe'), 'date' => '2026-08-04', 'clock_in' => '09:00:00', 'clock_out' => '16:30:00', 'status' => 'present', 'note' => 'Demonstration early departure'],
            ['email' => $email('nay.chi.win'), 'date' => '2026-08-04', 'clock_in' => null, 'clock_out' => null, 'status' => 'absent', 'note' => 'Demonstration absence'],
            ['email' => $email('pyae.sone.aung'), 'date' => '2026-08-04', 'clock_in' => '09:00:00', 'clock_out' => '17:00:00', 'status' => 'present', 'note' => 'Demonstration standard shift'],
            ['email' => $email('ko.ko.lwin'), 'date' => '2026-08-04', 'clock_in' => '09:00:00', 'clock_out' => '18:00:00', 'status' => 'present', 'note' => 'Demonstration overtime shift'],
            ['email' => $email('may.thu.hlaing'), 'date' => '2026-08-04', 'clock_in' => '09:15:00', 'clock_out' => null, 'status' => 'present', 'note' => 'Demonstration Missing Check Out'],
        ];
    }
}

August2026DemoSeeder::run(isset($pdo) && $pdo instanceof PDO ? $pdo : null);
