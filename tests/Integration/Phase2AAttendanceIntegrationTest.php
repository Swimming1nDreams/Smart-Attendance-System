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

    $month = '2026-08';
    $today = '2026-08-03';
    $asOf = new DateTimeImmutable($today . ' 18:00:00', new DateTimeZone('Asia/Yangon'));

    // Initial state: the resolved threshold has passed without attendance.
    $result = $analyticsService->generate(['month' => $month, 'employee_id' => $ids['employee_id']], $asOf);
    assert_false($result['employee_analytics'][0]['has_100_percent_attendance']);
    assert_same(1, $result['employee_analytics'][0]['absent_days']);
    
    // An open attendance record remains ineligible for recognition.
    $operations->clockIn($ids['employee_id'], $today, '09:00:00');
    $result = $analyticsService->generate(['month' => $month, 'employee_id' => $ids['employee_id']], $asOf);
    assert_false($result['employee_analytics'][0]['has_100_percent_attendance']);
    assert_same(0, $result['employee_analytics'][0]['absent_days']);
    
    // Clock out to complete it
    $operations->clockOut($ids['employee_id'], $today, '17:00:00');
    
    $result = $analyticsService->generate(['month' => $month, 'employee_id' => $ids['employee_id']], $asOf);
    assert_true($result['employee_analytics'][0]['has_100_percent_attendance']);
});
