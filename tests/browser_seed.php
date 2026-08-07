<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/Support/TestDatabase.php';

$databaseName = getenv('SAPAS_BROWSER_DB_NAME') ?: 'sapas_browser_test';
$database = TestDatabase::connect($databaseName);
$ids = $database->resetAndSeed();
$pdo = $database->pdo();

$pdo->prepare(
    'INSERT INTO attendance_records (user_id, attendance_date, clock_in_time, clock_out_time, working_hours, status, late_minutes, remarks) VALUES (:user_id, :attendance_date, :clock_in_time, :clock_out_time, :working_hours, :status, :late_minutes, :remarks)'
)->execute([
    ':user_id' => $ids['employee_id'],
    ':attendance_date' => '2026-11-02',
    ':clock_in_time' => '09:05:00',
    ':clock_out_time' => '17:10:00',
    ':working_hours' => 8.08,
    ':status' => 'present',
    ':late_minutes' => 0,
    ':remarks' => 'Browser seed attendance',
]);

$pdo->prepare(
    'INSERT INTO leave_requests (user_id, leave_type, start_date, end_date, duration_days, reason, status, reviewed_by, reviewed_at, review_note) VALUES (:user_id, :leave_type, :start_date, :end_date, :duration_days, :reason, :status, :reviewed_by, :reviewed_at, :review_note)'
)->execute([
    ':user_id' => $ids['employee_id'],
    ':leave_type' => 'medical',
    ':start_date' => '2026-12-05',
    ':end_date' => '2026-12-06',
    ':duration_days' => 2,
    ':reason' => 'Browser seed approved leave',
    ':status' => 'approved',
    ':reviewed_by' => $ids['admin_id'],
    ':reviewed_at' => '2026-11-01 09:00:00',
    ':review_note' => 'Seeded approved leave',
]);

$pdo->prepare(
    'INSERT INTO leave_requests (user_id, leave_type, start_date, end_date, duration_days, reason, status) VALUES (:user_id, :leave_type, :start_date, :end_date, :duration_days, :reason, :status)'
)->execute([
    ':user_id' => $ids['employee_id'],
    ':leave_type' => 'casual',
    ':start_date' => '2026-12-10',
    ':end_date' => '2026-12-10',
    ':duration_days' => 1,
    ':reason' => 'Browser seed pending leave',
    ':status' => 'pending',
]);

echo "Seeded browser QA database: {$database->databaseName()}\n";
echo "Start the app with DB_NAME={$database->databaseName()} and browse to /login.\n";
echo "Admin: admin.integration@sapas.test / Password123!\n";
echo "Employee: employee.integration@sapas.test / Password123!\n";
echo "Inactive employee: inactive.integration@sapas.test / Password123!\n";
