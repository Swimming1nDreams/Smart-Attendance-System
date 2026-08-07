<?php

declare(strict_types=1);

use App\Core\Localization;

test('employee attendance page renders safely before today has an attendance record', function (): void {
    $localization = new Localization(project_path('lang'));
    Localization::setCurrent($localization);

    $t = static fn (string $key): string => $localization->translate($key);
    $isAdmin = false;
    $records = [];
    $todayRecord = null;
    $todayStatus = 'present';
    $showClockActions = true;
    $canClockIn = true;
    $canClockOut = false;
    $hasApprovedLeaveToday = false;
    $isHolidayOrWeekend = false;
    $today = '2026-07-22';
    $csrf_token = 'test-token';

    ob_start();
    try {
        require project_path('views/attendance/index.php');
        $html = (string) ob_get_clean();
    } catch (Throwable $exception) {
        ob_end_clean();
        throw $exception;
    }

    assert_contains('Working Hours', $html);
    assert_contains('—', $html);
    assert_contains('action="/attendance/clock-in"', $html);
});
