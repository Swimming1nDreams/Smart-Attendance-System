<?php

declare(strict_types=1);

use App\Models\Attendance;
use App\Models\LeaveRequest;
use App\Services\AttendanceOperationService;
use App\Services\AttendancePolicyService;

test('database integration: attendance employee search works with native prepared statements and date filters', function (): void {
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $attendance = new Attendance($database->pdo());

    assert_true($attendance->create($ids['employee_id'], '2026-08-03', '09:05:00', '17:30:00', 8.42, 'present', 0, null));
    assert_true($attendance->create($ids['other_employee_id'], '2026-08-03', '08:55:00', '17:15:00', 8.33, 'present', 0, null));

    $nameMatches = $attendance->findAll(null, 'Integration Employee', '2026-08-01', '2026-08-31');
    assert_same(1, count($nameMatches));
    assert_same($ids['employee_id'], (int) $nameMatches[0]['user_id']);
    assert_same(1, $attendance->countAll(null, 'Integration Employee', '2026-08-01', '2026-08-31'));

    $emailMatches = $attendance->findAll(null, 'other.integration', '2026-08-01', '2026-08-31');
    assert_same(1, count($emailMatches));
    assert_same($ids['other_employee_id'], (int) $emailMatches[0]['user_id']);
    assert_same(1, $attendance->countAll(null, 'other.integration', '2026-08-01', '2026-08-31'));
});

test('database integration: attendance list ordering is deterministic for equal dates and names', function (): void {
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $pdo = $database->pdo();
    $attendance = new Attendance($pdo);

    $rename = $pdo->prepare('UPDATE users SET name = :name WHERE id IN (:employee_id, :other_employee_id)');
    $rename->bindValue(':name', 'Same Attendance Reader', PDO::PARAM_STR);
    $rename->bindValue(':employee_id', $ids['employee_id'], PDO::PARAM_INT);
    $rename->bindValue(':other_employee_id', $ids['other_employee_id'], PDO::PARAM_INT);
    $rename->execute();

    assert_true($attendance->create($ids['employee_id'], '2026-08-03', '09:00:00', '17:00:00', 8.00, 'present', 0, 'first same date'));
    assert_true($attendance->create($ids['other_employee_id'], '2026-08-03', '09:00:00', '17:00:00', 8.00, 'present', 0, 'second same date'));

    $records = $attendance->findAll(null, '', '2026-08-03', '2026-08-03');

    assert_same(2, count($records));
    assert_true((int) $records[0]['id'] > (int) $records[1]['id']);
    assert_same($ids['other_employee_id'], (int) $records[0]['user_id']);
    assert_same($ids['employee_id'], (int) $records[1]['user_id']);
});

test('database integration: attendance duplicate date guard is enforced by model and unique index', function (): void {
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $attendance = new Attendance($database->pdo());

    assert_true($attendance->create($ids['employee_id'], '2026-08-03', '09:05:00', '17:30:00', 8.42, 'present', 0, null));
    assert_true($attendance->existsByUserAndDate($ids['employee_id'], '2026-08-03'));

    $record = $attendance->findByUserIdAndDate($ids['employee_id'], '2026-08-03');
    assert_not_null($record);
    assert_false($attendance->existsByUserAndDate($ids['employee_id'], '2026-08-03', (int) $record['id']));
    assert_false($attendance->create($ids['employee_id'], '2026-08-03', '09:10:00', null, 0.0, 'present', 0, 'duplicate'));
});

test('database integration: approved leave helper blocks only approved leave dates', function (): void {
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $leaveRequests = new LeaveRequest($database->pdo());

    $approvedLeaveId = $leaveRequests->create($ids['employee_id'], 'medical', '2026-08-10', '2026-08-12', 3, 'Approved leave', 'approved');
    $leaveRequests->create($ids['employee_id'], 'casual', '2026-08-15', '2026-08-15', 1, 'Pending leave', 'pending');

    assert_true($leaveRequests->existsApprovedLeaveForDate($ids['employee_id'], '2026-08-11'));
    assert_false($leaveRequests->existsApprovedLeaveForDate($ids['employee_id'], '2026-08-15'));
    assert_false($leaveRequests->existsApprovedLeaveForDate($ids['employee_id'], '2026-08-20'));
});

test('database integration: overtime minutes require exact clock times and include special-day work', function (): void {
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $attendance = new Attendance($database->pdo());
    $leaveRequests = new LeaveRequest($database->pdo());

    assert_true($attendance->create($ids['employee_id'], '2026-08-04', '09:00:00', '18:15:00', 9.25, 'present', 0, null));
    $overEight = $attendance->findByUserIdAndDate($ids['employee_id'], '2026-08-04');
    assert_not_null($overEight);
    assert_same(75, $attendance->calculateOvertimeMinutes((string) $overEight['status'], $overEight['working_hours'], (string) $overEight['attendance_date'], false, false, $overEight['expected_daily_work_hours'], (bool) $overEight['overtime_eligible'], (string) $overEight['clock_in_time'], (string) $overEight['clock_out_time']));

    assert_true($attendance->create($ids['employee_id'], '2026-08-05', '09:00:00', '17:00:00', 8.00, 'present', 0, null));
    $exactEight = $attendance->findByUserIdAndDate($ids['employee_id'], '2026-08-05');
    assert_not_null($exactEight);
    assert_same(0, $attendance->calculateOvertimeMinutes((string) $exactEight['status'], $exactEight['working_hours'], (string) $exactEight['attendance_date'], false, false, $exactEight['expected_daily_work_hours'], (bool) $exactEight['overtime_eligible'], (string) $exactEight['clock_in_time'], (string) $exactEight['clock_out_time']));

    assert_true($attendance->create($ids['employee_id'], '2026-08-06', null, null, 10.00, 'absent', 0, null));
    $absent = $attendance->findByUserIdAndDate($ids['employee_id'], '2026-08-06');
    assert_not_null($absent);
    assert_null($attendance->calculateOvertimeMinutes((string) $absent['status'], $absent['working_hours'], (string) $absent['attendance_date']));

    assert_true($attendance->create($ids['employee_id'], '2026-08-08', '09:00:00', '19:00:00', 10.00, 'present', 0, null));
    $weekend = $attendance->findByUserIdAndDate($ids['employee_id'], '2026-08-08');
    assert_not_null($weekend);
    assert_same(600, $attendance->calculateOvertimeMinutes((string) $weekend['status'], $weekend['working_hours'], (string) $weekend['attendance_date'], false, false, $weekend['expected_daily_work_hours'], (bool) $weekend['overtime_eligible'], (string) $weekend['clock_in_time'], (string) $weekend['clock_out_time']));

    $database->pdo()->exec("INSERT INTO holidays (holiday_name, holiday_date) VALUES ('Integration Holiday', '2026-08-07')");
    assert_true($attendance->create($ids['employee_id'], '2026-08-07', '09:00:00', '19:00:00', 10.00, 'present', 0, null));
    $holiday = $attendance->findByUserIdAndDate($ids['employee_id'], '2026-08-07');
    assert_not_null($holiday);
    assert_same(600, $attendance->calculateOvertimeMinutes((string) $holiday['status'], $holiday['working_hours'], (string) $holiday['attendance_date'], true, false, $holiday['expected_daily_work_hours'], (bool) $holiday['overtime_eligible'], (string) $holiday['clock_in_time'], (string) $holiday['clock_out_time']));

    $leaveRequests->create($ids['other_employee_id'], 'casual', '2026-08-10', '2026-08-10', 1, 'Approved leave', 'approved');
    assert_true($attendance->create($ids['other_employee_id'], '2026-08-10', '09:00:00', '19:00:00', 10.00, 'present', 0, null));
    $leaveConflict = $attendance->findByUserIdAndDate($ids['other_employee_id'], '2026-08-10');
    assert_not_null($leaveConflict);
    assert_same(0, $attendance->calculateOvertimeMinutes(
        (string) $leaveConflict['status'],
        $leaveConflict['working_hours'],
        (string) $leaveConflict['attendance_date'],
        false,
        $leaveRequests->existsApprovedLeaveForDate($ids['other_employee_id'], '2026-08-10'),
        $leaveConflict['expected_daily_work_hours'],
        (bool) $leaveConflict['overtime_eligible'],
        (string) $leaveConflict['clock_in_time'],
        (string) $leaveConflict['clock_out_time']
    ));
});

test('database integration: admin attendance writes use centralized status and duration policy', function (): void {
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $attendance = new Attendance($database->pdo());
    $operations = new AttendanceOperationService($database->pdo(), new AttendancePolicyService());

    $operations->createRecord($ids['employee_id'], '2026-08-09', '10:15:00', '10:15:22', 'present', 'Sunday work');
    $weekend = $attendance->findByUserIdAndDate($ids['employee_id'], '2026-08-09');
    assert_not_null($weekend);
    assert_same('weekend', (string) $weekend['status']);
    assert_same(0, (int) $weekend['late_minutes']);
    assert_same('0 min', (new AttendancePolicyService())->enrichRecord($weekend)['working_duration']);

    $database->pdo()->exec("INSERT INTO holidays (holiday_name, holiday_date) VALUES ('Policy Holiday', '2026-08-10')");
    $operations->createRecord($ids['other_employee_id'], '2026-08-10', '10:00:00', '12:00:00', 'present', 'Holiday work');
    $holiday = $attendance->findByUserIdAndDate($ids['other_employee_id'], '2026-08-10');
    assert_not_null($holiday);
    assert_same('holiday', (string) $holiday['status']);
    assert_same(0, (int) $holiday['late_minutes']);

    $operations->createRecord($ids['employee_id'], '2026-08-11', null, null, 'present', 'No times');
    $noTimes = $attendance->findByUserIdAndDate($ids['employee_id'], '2026-08-11');
    assert_not_null($noTimes);
    assert_same('absent', (string) $noTimes['status']);

    $operations->updateRecord((int) $noTimes['id'], $ids['employee_id'], '2026-08-11', '09:00:00', '17:00:00', 'absent', 'Completed work');
    $completed = $attendance->findByUserIdAndDate($ids['employee_id'], '2026-08-11');
    assert_not_null($completed);
    assert_same('present', (string) $completed['status']);
    assert_same(0, (int) $completed['late_minutes']);
    assert_same(8.0, (float) $completed['working_hours']);
});
