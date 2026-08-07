<?php

declare(strict_types=1);

use App\Core\Localization;

test('overtime review empty state hides zero-result pagination chrome', function (): void {
    overtime_review_hotfix_set_locale('en');
    $html = overtime_review_hotfix_render_index([
        'records' => [],
        'total' => 0,
        'page' => 1,
        'per_page' => 20,
        'filters' => ['from' => '', 'to' => '', 'approval_status' => ''],
    ]);
    $visibleText = overtime_review_hotfix_visible_text($html);

    assert_contains('Filters', $visibleText);
    assert_contains('No overtime records match these filters.', $visibleText);
    assert_contains('Completed attendance with calculated overtime will appear here.', $visibleText);
    assert_not_contains('0–0', $visibleText);
    assert_not_contains('Page 1', $visibleText);
    assert_not_contains('Rows per page', $visibleText);
    assert_not_contains('pagination-per-page', $html);
});

test('overtime review keeps reset action only when filters are active', function (): void {
    overtime_review_hotfix_set_locale('en');

    $withoutFilters = overtime_review_hotfix_render_index([
        'records' => [],
        'total' => 0,
        'page' => 1,
        'per_page' => 20,
        'filters' => ['from' => '', 'to' => '', 'approval_status' => ''],
    ]);
    assert_not_contains('Reset', overtime_review_hotfix_visible_text($withoutFilters));

    $withFilters = overtime_review_hotfix_render_index([
        'records' => [],
        'total' => 0,
        'page' => 1,
        'per_page' => 20,
        'filters' => ['from' => '2026-07-01', 'to' => '2026-07-31', 'approval_status' => 'pending'],
    ]);
    assert_contains('Reset', overtime_review_hotfix_visible_text($withFilters));
});

test('overtime review myanmar visible text does not leak confirmed english labels', function (): void {
    overtime_review_hotfix_set_locale('my');
    $html = overtime_review_hotfix_render_index([
        'records' => [],
        'total' => 0,
        'page' => 1,
        'per_page' => 20,
        'filters' => ['from' => '2026-07-01', 'to' => '2026-07-31', 'approval_status' => ''],
    ]);
    $visibleText = overtime_review_hotfix_visible_text($html);

    assert_contains('စစ်ထုတ်မှုများ', $visibleText);
    assert_contains('အလုပ်မူဝါဒစင်တာသို့ ပြန်ရန်', $visibleText);
    assert_contains('ကိုက်ညီသော အချိန်ပိုမှတ်တမ်း မရှိပါ။', $visibleText);
    assert_not_contains('Back to Workforce Policy Center', $visibleText);
    assert_not_contains('Workforce Policy Center', $visibleText);
    assert_not_contains('filter', $visibleText);
});

test('overtime review service keeps attendance as the authoritative overtime source', function (): void {
    $serviceSource = file_get_contents(project_path('src/Services/OvertimeReviewService.php'));
    assert_true(is_string($serviceSource));

    assert_contains('FROM attendance_records ar', $serviceSource);
    assert_contains('ar.attendance_date >= :from_date', $serviceSource);
    assert_contains('ar.attendance_date <= :to_date', $serviceSource);
    assert_contains('ar.calculated_overtime_minutes > 0', $serviceSource);
    assert_contains('$page = $this->clampPage($page, $perPage, $count);', $serviceSource);
    assert_contains('private function scalarString', $serviceSource);
});

function overtime_review_hotfix_set_locale(string $locale): void
{
    reset_test_session();
    $_SESSION['locale'] = $locale;
    $localization = new Localization(project_path('lang'));
    Localization::setCurrent($localization);
}

/** @param array<string, mixed> $result */
function overtime_review_hotfix_render_index(array $result): string
{
    $t = static fn (string $key): string => Localization::current()->translate($key);
    $options = ['policies' => [], 'departments' => [], 'employees' => []];

    ob_start();
    require project_path('views/work_schedules/overtime_index.php');
    $content = (string) ob_get_clean();

    return (string) ($pageActions ?? '') . $content;
}

function overtime_review_hotfix_visible_text(string $html): string
{
    return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8')));
}
