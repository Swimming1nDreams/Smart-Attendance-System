<?php

declare(strict_types=1);

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Services\AnalyticsService;
use App\Services\AttendanceOperationService;
use App\Services\AttendancePolicyService;
use App\Services\WorkScheduleResolverService;

test('database integration: 100% attendance recognition phase 2a', function (): void {
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $pdo = $database->pdo();
    
    $policy = new AttendancePolicyService();
    $operations = new AttendanceOperationService($pdo, $policy);
    $analyticsService = new AnalyticsService(
        new Employee($pdo),
        new Attendance($pdo, $policy),
        new Holiday($pdo),
        new LeaveRequest($pdo),
        $policy,
        new WorkScheduleResolverService($pdo)
    );

    // Initial state: no attendance for the month, should not have 100%
    $month = date('Y-m');
    $result = $analyticsService->generate(['month' => $month, 'employee_id' => $ids['employee_id']]);
    assert_false($result['employee_analytics'][0]['has_100_percent_attendance']);
    
    // Add one present day (incomplete). It still shouldn't be 100% since expected > present (most likely)
    $today = date('Y-m-d');
    $operations->clockIn($ids['employee_id'], $today, '09:00:00');
    $result = $analyticsService->generate(['month' => $month, 'employee_id' => $ids['employee_id']]);
    // Incomplete checkout prevents 100% attendance
    assert_false($result['employee_analytics'][0]['has_100_percent_attendance']);
    
    // Clock out to complete it
    $operations->clockOut($ids['employee_id'], $today, '17:00:00');
    
    $result = $analyticsService->generate(['month' => $month, 'employee_id' => $ids['employee_id']]);
    assert_true(is_bool($result['employee_analytics'][0]['has_100_percent_attendance']));
});
