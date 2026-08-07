<?php

declare(strict_types=1);

use App\Core\Localization;

test('attendance list count summaries use localized templates instead of english fragments', function (): void {
    $view = file_get_contents(project_path('views/attendance/index.php'));
    assert_true(is_string($view));

    assert_contains("attendance.matching_records_summary", $view);
    assert_contains("attendance.records_on_page_summary", $view);
    assert_contains("attendance.total_records_summary", $view);
    assert_not_contains("matching record<?= \$total", $view);
    assert_not_contains("records on this page", $view);
    assert_not_contains("record<?= \$visibleCount", $view);

    foreach (['en', 'my'] as $locale) {
        reset_test_session();
        $_SESSION['locale'] = $locale;
        $localization = new Localization(project_path('lang'));
        Localization::setCurrent($localization);

        foreach ([0, 1, 6] as $count) {
            $matchingKey = $count === 1 ? 'attendance.matching_records_summary' : 'attendance.matching_records_summary_plural';
            $pageKey = $count === 1 ? 'attendance.records_on_page_summary' : 'attendance.records_on_page_summary_plural';
            $totalKey = $count === 1 ? 'attendance.total_records_summary' : 'attendance.total_records_summary_plural';

            $matching = attendance_summary_localization_template($matchingKey, ['count' => (string) $count]);
            $onPage = attendance_summary_localization_template($pageKey, ['count' => (string) $count]);
            $total = attendance_summary_localization_template($totalKey, ['count' => (string) $count]);

            assert_contains((string) $count, $matching);
            assert_contains((string) $count, $onPage);
            assert_contains((string) $count, $total);

            if ($locale === 'my') {
                assert_not_contains('matching records', $matching);
                assert_not_contains('matching record', $matching);
                assert_not_contains('records on this page', $onPage);
                assert_not_contains('record on this page', $onPage);
                assert_not_contains(' records', $total);
                assert_not_contains(' of ', $matching . $onPage . $total);
            }
        }
    }

    reset_test_session();
    Localization::setCurrent(new Localization(project_path('lang')));
});

test('attendance list dates and weekdays avoid english-only formatting in myanmar mode', function (): void {
    $view = file_get_contents(project_path('views/attendance/index.php'));
    assert_true(is_string($view));

    assert_contains('function attendanceDateValue', $view);
    assert_contains('function attendanceWeekdayValue', $view);
    assert_contains("attendanceDateValue(\$fromDate)", $view);
    assert_contains("attendanceDateValue(\$toDate)", $view);
    assert_contains("attendanceWeekdayValue((string) \$record['attendance_date'])", $view);
    assert_not_contains("date('M j, Y', strtotime(\$fromDate))", $view);
    assert_not_contains("date('M j, Y', strtotime(\$toDate))", $view);
    assert_not_contains("date('M j, Y', strtotime(\$record['attendance_date']))", $view);
    assert_not_contains("date('l', strtotime(\$record['attendance_date']))", $view);

    $myanmar = require project_path('lang/my.php');
    foreach (['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'] as $englishDay) {
        assert_false(in_array($englishDay, $myanmar['work_schedules']['weekdays'], true));
        assert_false(in_array(substr($englishDay, 0, 3), $myanmar['work_schedules']['weekdays_short'], true));
    }
});

if (!function_exists('attendance_summary_localization_template')) {
    function attendance_summary_localization_template(string $key, array $values): string
    {
        $template = Localization::current()->translate($key);
        $replacements = [];

        foreach ($values as $name => $value) {
            $replacements[':' . (string) $name] = (string) $value;
        }

        return strtr($template, $replacements);
    }
}
