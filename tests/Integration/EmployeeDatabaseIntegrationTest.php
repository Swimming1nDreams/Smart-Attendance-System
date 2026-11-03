<?php

declare(strict_types=1);

use App\Models\Employee;

test('database integration: employee search combines with department and position filters under native prepares', function (): void {
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $employees = new Employee($database->pdo());

    $nameMatches = $employees->findAll('Integration Employee', $ids['department_id'], $ids['position_id']);
    assert_same(1, count($nameMatches));
    assert_same($ids['employee_id'], (int) $nameMatches[0]['id']);
    assert_same(1, $employees->countAll('Integration Employee', $ids['department_id'], $ids['position_id']));

    $emailMatches = $employees->findAll('employee.integration@sapas.test', $ids['department_id'], $ids['position_id']);
    assert_same(1, count($emailMatches));
    assert_same($ids['employee_id'], (int) $emailMatches[0]['id']);
});

test('database integration: employee list search includes staff code and exact status filter', function (): void {
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $employees = new Employee($database->pdo());

    $employeeId = $employees->create(
        'Staff Code Search Employee',
        'staff-code-search.integration@sapas.test',
        'Password123!',
        'employee',
        $ids['department_id'],
        $ids['position_id'],
        'inactive',
        'SAP-3C-SEARCH',
        'full-time',
        '2026-07-13',
        8.0,
        true,
    );

    $staffCodeMatches = $employees->findAll('SAP-3C-SEARCH', null, null, null, 1, 20);
    assert_same(1, count($staffCodeMatches));
    assert_same($employeeId, (int) $staffCodeMatches[0]['id']);

    $inactiveMatches = $employees->findAll('Staff Code Search', null, null, 'inactive', 1, 20);
    assert_same(1, count($inactiveMatches));
    assert_same($employeeId, (int) $inactiveMatches[0]['id']);

    assert_same(0, $employees->countAll('Staff Code Search', null, null, 'active'));
});

test('database integration: employee list role filter is applied to rows and counts', function (): void {
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $employees = new Employee($database->pdo());

    $employeeRows = $employees->findAll('', null, null, null, 1, 100, 'employee');
    assert_true(count($employeeRows) >= 1);
    foreach ($employeeRows as $row) {
        assert_same('employee', $row['role']);
    }
    assert_same(count($employeeRows), $employees->countAll('', null, null, null, 'employee'));
});

test('database integration: employee employment and work-rule options are stored with practical defaults', function (): void {
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $employees = new Employee($database->pdo());

    $employeeId = $employees->create(
        'Options Employee',
        'options.integration@sapas.test',
        'Password123!',
        'employee',
        $ids['department_id'],
        $ids['position_id'],
        'active',
        'STAFF-100',
        'full-time',
        '2026-07-13',
        8.0,
        true,
    );

    assert_true($employeeId > 0);
    $employee = $employees->findById($employeeId);
    assert_true(is_array($employee));
    assert_same('STAFF-100', $employee['staff_code']);
    assert_same('full-time', $employee['employment_type']);
    assert_same('2026-07-13', $employee['join_date']);
    assert_same(8.0, (float) $employee['expected_daily_work_hours']);
    assert_same(1, (int) $employee['overtime_eligible']);
});
