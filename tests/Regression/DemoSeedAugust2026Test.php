<?php

declare(strict_types=1);

test('demo seeds include official-style 2026 holidays and policy-calculated August attendance', function (): void {
    $mainSeed = file_get_contents(project_path('seeds/seed_demo_data.php'));
    $augustSeed = file_get_contents(project_path('seeds/seed_august_2026_demo.php'));
    $holidaySeed = file_get_contents(project_path('seeds/data/holidays_2026.php'));

    assert_true(is_string($mainSeed));
    assert_true(is_string($augustSeed));
    assert_true(is_string($holidaySeed));

    foreach (['2026-07-19', '2026-07-29', '2026-10-25', '2026-10-26', '2026-10-27', '2026-11-23', '2026-11-24', '2026-12-04', '2026-12-25'] as $date) {
        assert_contains($date, $holidaySeed);
    }
    assert_contains("Company holiday:", $holidaySeed);
    assert_contains("require __DIR__ . '/data/holidays_2026.php'", $mainSeed);
    assert_contains('array_fill_keys(array_column($holidays', $mainSeed);
    assert_contains('if (!empty($holidayDates[$date]))', $mainSeed);
    assert_contains("require __DIR__ . '/seed_august_2026_demo.php';", $mainSeed);

    assert_contains('new AttendanceOperationService', $augustSeed);
    assert_contains('AttendanceOperationService::freshnessToken', $augustSeed);
    assert_contains("'2026-08-01'", $augustSeed);
    assert_contains("'2026-08-02'", $augustSeed);
    assert_contains("'2026-08-03'", $augustSeed);
    assert_contains("'2026-08-04'", $augustSeed);
    assert_contains('Protected/skipped', $augustSeed);
    assert_not_contains('INSERT INTO attendance_records', $augustSeed);
    assert_not_contains('calculated_overtime_minutes =', $augustSeed);
});
