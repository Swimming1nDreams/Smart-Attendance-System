<?php

declare(strict_types=1);

use App\Core\Localization;
use App\Services\AttendancePolicyService;
use App\Services\AttendanceSummaryReportService;
use App\Services\ReportPdfExportService;

test('attendance summary aggregates completed work in integer minutes and counts late exactly once', function (): void {
    $service = attendance_summary_test_service('en');
    $totals = $service->totals([
        ['status' => 'present', 'late_minutes' => 0, 'actual_work_minutes' => 480, 'working_hours' => 8.0, 'overtime_minutes' => 0],
        ['status' => 'present', 'late_minutes' => 38, 'actual_work_minutes' => 498, 'working_hours' => 8.3, 'overtime_minutes' => 18],
        ['status' => 'absent', 'late_minutes' => 0, 'actual_work_minutes' => null, 'working_hours' => 0.0, 'overtime_minutes' => 0],
    ]);

    assert_same(978, $totals['total_work_minutes']);
    assert_same('16h 18m', $totals['total_work_duration']);
    assert_not_contains('16.30', $totals['total_work_duration']);
    assert_same(2, $totals['present_count']);
    assert_same(1, $totals['late_count']);
    assert_same($totals['present_count'], ($totals['present_count'] - $totals['late_count']) + $totals['late_count']);
    assert_same(18, $totals['total_overtime_minutes']);

    $myanmarTotals = attendance_summary_test_service('my')->totals([
        ['status' => 'present', 'late_minutes' => 0, 'actual_work_minutes' => 480, 'overtime_minutes' => 0],
        ['status' => 'present', 'late_minutes' => 38, 'actual_work_minutes' => 498, 'overtime_minutes' => 18],
    ]);
    assert_same(978, $myanmarTotals['total_work_minutes']);
    assert_contains(Localization::current()->translate('common.units.hours'), $myanmarTotals['total_work_duration']);
    assert_contains(Localization::current()->translate('common.units.minutes'), $myanmarTotals['total_work_duration']);
});

test('all supported attendance statuses are translated in English and Myanmar presentation', function (): void {
    $statuses = ['present', 'late', 'absent', 'weekend', 'holiday', 'approved_leave', 'incomplete'];
    attendance_summary_set_locale('en');
    $englishLabels = [];
    foreach ($statuses as $status) {
        $label = Localization::current()->translate('statuses.attendance.' . $status);
        assert_not_contains('statuses.attendance.', $label);
        $englishLabels[$status] = $label;
    }
    assert_same('Late', $englishLabels['late']);

    attendance_summary_set_locale('my');
    foreach ($statuses as $status) {
        $label = Localization::current()->translate('statuses.attendance.' . $status);
        assert_not_contains('statuses.attendance.', $label);
        assert_false($label === $englishLabels[$status], sprintf('Myanmar %s status must not fall back to English.', $status));
    }
    assert_not_contains('Late', Localization::current()->translate('statuses.attendance.late'));
});

test('attendance summary screen and PDF consume the same formatted duration while preserving filters and PDF layout', function (): void {
    $controllerSource = file_get_contents(project_path('src/Controllers/ReportsController.php'));
    $serviceSource = file_get_contents(project_path('src/Services/AttendanceSummaryReportService.php'));
    $viewSource = file_get_contents(project_path('views/reports/attendance_summary.php'));
    assert_true(is_string($controllerSource));
    assert_true(is_string($serviceSource));
    assert_true(is_string($viewSource));

    assert_contains("\$totals['total_work_duration']", $controllerSource);
    assert_contains("'total_work_duration' => \$this->policy->formatDuration", $serviceSource);
    assert_contains("\$totals['total_work_duration']", $viewSource);
    assert_not_contains('total_working_hours', $viewSource);
    assert_contains('$paginationQuery', $viewSource);
    assert_contains("require __DIR__ . '/../partials/pagination.php'", $viewSource);

    attendance_summary_set_locale('en');
    $pdfHtml = call_private(new ReportPdfExportService(), 'buildHtml', [
        'Attendance Summary',
        ['Employee: Example'],
        [['title' => 'Records', 'headers' => ['Status'], 'rows' => [['Late']]]],
    ]);
    assert_contains('@page{size:A4 landscape', $pdfHtml);
    assert_contains('thead{display:table-header-group;}', $pdfHtml);
    assert_contains('<thead><tr>', $pdfHtml);
    assert_contains('Employee: Example', $pdfHtml);
    $projectName = 'Smart Attendance and Performance Analytics System (SAPAS)';
    assert_contains('<header class="report-header"><h1>' . $projectName . '</h1><h2>Attendance Summary</h2>', $pdfHtml);
    assert_not_contains('<h1>SAPAS</h1>', $pdfHtml);
    assert_true(strpos($pdfHtml, $projectName) < strpos($pdfHtml, 'Attendance Summary'));

    attendance_summary_set_locale('my');
    $myanmarPdfHtml = call_private(new ReportPdfExportService(), 'buildHtml', [
        'တက်ရောက်မှု အနှစ်ချုပ်',
        ['ဝန်ထမ်း: စမ်းသပ်'],
        [['title' => 'မှတ်တမ်းများ', 'headers' => ['အခြေအနေ'], 'rows' => [['နောက်ကျ']]]],
    ]);
    assert_contains('<h1>' . $projectName . '</h1>', $myanmarPdfHtml);
    assert_contains('တက်ရောက်မှု အနှစ်ချုပ်', $myanmarPdfHtml);
});

function attendance_summary_test_service(string $locale): AttendanceSummaryReportService
{
    attendance_summary_set_locale($locale);
    $reflection = new ReflectionClass(AttendanceSummaryReportService::class);
    /** @var AttendanceSummaryReportService $service */
    $service = $reflection->newInstanceWithoutConstructor();
    $policy = new ReflectionProperty(AttendanceSummaryReportService::class, 'policy');
    $policy->setAccessible(true);
    $policy->setValue($service, new AttendancePolicyService());

    return $service;
}

function attendance_summary_set_locale(string $locale): void
{
    reset_test_session();
    $_SESSION['locale'] = $locale;
    Localization::setCurrent(new Localization(project_path('lang')));
}
