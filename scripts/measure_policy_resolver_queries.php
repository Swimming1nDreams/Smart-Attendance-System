<?php

declare(strict_types=1);

use App\Services\WorkScheduleResolverService;

require __DIR__ . '/../tests/bootstrap.php';
require __DIR__ . '/../tests/Support/QueryCountingPDO.php';
require __DIR__ . '/../tests/Support/TestDatabase.php';

$database = TestDatabase::connectWithQueryCounter('sapas_query_count_test');
$ids = $database->resetAndSeed();
$pdo = $database->pdo();

if (!$pdo instanceof QueryCountingPDO) {
    fwrite(STDERR, "Query-counting PDO is unavailable.\n");
    exit(1);
}

$departments = [$ids['department_id']];
for ($i = 2; $i <= 5; $i++) {
    $pdo->prepare('INSERT INTO departments (name, description) VALUES (:name, :description)')->execute([
        ':name' => 'Batch Department ' . $i,
        ':description' => 'Resolver batch fixture',
    ]);
    $departments[] = (int) $pdo->lastInsertId();
}

$employeeIds = [$ids['employee_id'], $ids['other_employee_id']];
for ($i = 3; $i <= 50; $i++) {
    $employeeIds[] = insert_query_count_employee(
        $pdo,
        'Batch Employee ' . $i,
        'batch.employee.' . $i . '@sapas.test',
        $departments[$i % count($departments)],
        $ids['position_id'],
        'BATCH-' . str_pad((string) $i, 4, '0', STR_PAD_LEFT),
    );
}

$departmentScheduleId = create_query_count_schedule($pdo, 'Batch Department Saturday Policy', [1, 2, 3, 4, 6]);
$employeeScheduleId = create_query_count_schedule($pdo, 'Batch Employee Sunday Policy', [7, 1, 2, 3, 4]);
$pdo->prepare(
    "INSERT INTO work_schedule_assignments (work_schedule_template_id, scope_type, scope_id, effective_from, is_active, created_by)
     VALUES (:schedule_id, 'department', :department_id, '2026-01-01', 1, :admin_id)"
)->execute([
    ':schedule_id' => $departmentScheduleId,
    ':department_id' => $departments[2],
    ':admin_id' => $ids['admin_id'],
]);
$pdo->prepare(
    "INSERT INTO work_schedule_assignments (work_schedule_template_id, scope_type, scope_id, effective_from, is_active, created_by)
     VALUES (:schedule_id, 'employee', :employee_id, '2026-01-01', 1, :admin_id)"
)->execute([
    ':schedule_id' => $employeeScheduleId,
    ':employee_id' => $ids['employee_id'],
    ':admin_id' => $ids['admin_id'],
]);
$pdo->exec("INSERT INTO holidays (holiday_name, holiday_date) VALUES ('Batch Holiday', '2026-07-06')");

$requests = [];
for ($day = new DateTimeImmutable('2026-07-01'), $end = new DateTimeImmutable('2026-07-10'); $day <= $end; $day = $day->modify('+1 day')) {
    foreach ($employeeIds as $employeeId) {
        $requests[] = ['employee_id' => $employeeId, 'business_date' => $day->format('Y-m-d')];
    }
}

$resolver = new WorkScheduleResolverService($pdo);
$pdo->resetQueryCount();
foreach ($requests as $request) {
    $resolver->resolve($request['employee_id'], $request['business_date']);
}
$legacyCount = $pdo->queryCount();

$pdo->resetQueryCount();
$batch = $resolver->resolveMany($requests);
$batchCount = $pdo->queryCount();

if (count($batch) !== count($requests) || $legacyCount <= 1000 || $batchCount > 12 || $batchCount * 10 >= $legacyCount) {
    fwrite(STDERR, "Resolver query-count assertion failed.\n");
    fwrite(STDERR, sprintf("500 requests\nLegacy path: %d queries\nBatch path: %d queries\n", $legacyCount, $batchCount));
    exit(1);
}

printf("500 requests\nLegacy path: %d queries\nBatch path: %d queries\n", $legacyCount, $batchCount);

function create_query_count_schedule(PDO $pdo, string $name, array $workingDays): int
{
    $statement = $pdo->prepare(
        "INSERT INTO work_schedule_templates (name, description, timezone, effective_from, is_active, lifecycle_status)
         VALUES (:name, 'Query-count diagnostic schedule', NULL, '2026-01-01', 1, 'active')"
    );
    $statement->execute([':name' => $name]);
    $scheduleId = (int) $pdo->lastInsertId();

    $insertDay = $pdo->prepare(
        'INSERT INTO work_schedule_days (work_schedule_template_id, day_of_week, is_working_day, start_time, end_time, is_overnight, break_minutes, grace_minutes, expected_minutes)
         VALUES (:schedule_id, :day_of_week, :is_working_day, :start_time, :end_time, 0, 0, :grace_minutes, :expected_minutes)'
    );
    for ($day = 1; $day <= 7; $day++) {
        $isWorking = in_array($day, $workingDays, true);
        $insertDay->execute([
            ':schedule_id' => $scheduleId,
            ':day_of_week' => $day,
            ':is_working_day' => $isWorking ? 1 : 0,
            ':start_time' => $isWorking ? '09:00:00' : null,
            ':end_time' => $isWorking ? '17:00:00' : null,
            ':grace_minutes' => $isWorking ? 30 : 0,
            ':expected_minutes' => $isWorking ? 480 : 0,
        ]);
    }

    $pdo->prepare('INSERT INTO attendance_policy_settings (work_schedule_template_id) VALUES (:schedule_id)')->execute([':schedule_id' => $scheduleId]);
    $pdo->prepare('INSERT INTO overtime_policy_settings (work_schedule_template_id) VALUES (:schedule_id)')->execute([':schedule_id' => $scheduleId]);

    return $scheduleId;
}

function insert_query_count_employee(PDO $pdo, string $name, string $email, int $departmentId, int $positionId, string $staffCode): int
{
    $statement = $pdo->prepare(
        "INSERT INTO users (name, email, password, role, department_id, position_id, status, staff_code, email_verified_at)
         VALUES (:name, :email, :password, 'employee', :department_id, :position_id, 'active', :staff_code, NOW())"
    );
    $statement->execute([
        ':name' => $name,
        ':email' => $email,
        ':password' => password_hash('Password123!', PASSWORD_DEFAULT),
        ':department_id' => $departmentId,
        ':position_id' => $positionId,
        ':staff_code' => $staffCode,
    ]);

    return (int) $pdo->lastInsertId();
}
