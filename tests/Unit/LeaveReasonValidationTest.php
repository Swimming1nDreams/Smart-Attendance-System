<?php

declare(strict_types=1);

use App\Controllers\LeaveController;
use App\Core\Localization;
use App\Core\SessionManager;
use App\Services\LeaveReasonCatalog;
use Tests\Support\FakeApplication;
use Tests\Support\FakeEmployee;
use Tests\Support\FakeHoliday;
use Tests\Support\FakeLeaveRequest;

function make_validation_leave_controller(): LeaveController
{
    reset_test_session();
    Localization::setCurrent(new Localization(project_path('lang'), 'en'));

    return new LeaveController(
        new FakeLeaveRequest([]),
        new FakeHoliday(),
        new FakeEmployee([]),
        new SessionManager(),
        new FakeApplication()
    );
}

/** @return list<string> */
function validate_leave_reason(string $reason): array
{
    return call_private(make_validation_leave_controller(), 'validateLeaveRequest', [
        1,
        'casual',
        'personal_matter',
        '2026-10-12',
        '2026-10-12',
        $reason,
    ]);
}

test('leave reason accepts normal employee free text instead of a predefined option', function (): void {
    $errors = validate_leave_reason('I need to accompany a family member to an appointment.');

    assert_same([], $errors);
});

test('leave reason rejects blank and whitespace-only input', function (): void {
    assert_contains('Reason is required.', implode(' ', validate_leave_reason('')));
    assert_contains('Reason is required.', implode(' ', validate_leave_reason("  \t\n  ")));
});

test('leave reason enforces the 1000-character limit', function (): void {
    assert_same([], validate_leave_reason(str_repeat('a', 1000)));
    assert_contains('Reason must be 1000 characters or fewer.', implode(' ', validate_leave_reason(str_repeat('a', 1001))));
});

test('leave reason category is validated against the selected actual leave type', function (): void {
    $controller = make_validation_leave_controller();
    $medical = call_private($controller, 'validateLeaveRequest', [1, 'medical', 'medical_appointment', '2026-10-12', '2026-10-12', 'Medical appointment details']);
    $crossType = call_private($controller, 'validateLeaveRequest', [1, 'medical', 'travel', '2026-10-12', '2026-10-12', 'Invalid cross-type details']);
    $unpaid = call_private($controller, 'validateLeaveRequest', [1, 'unpaid', 'study_education', '2026-10-12', '2026-10-12', 'Study details']);
    $missing = call_private($controller, 'validateLeaveRequest', [1, 'casual', '', '2026-10-12', '2026-10-12', 'Details']);
    $unknown = call_private($controller, 'validateLeaveRequest', [1, 'casual', 'hacked_value', '2026-10-12', '2026-10-12', 'Details']);
    $unsupportedLeaveType = call_private($controller, 'validateLeaveRequest', [1, 'family', 'family_emergency', '2026-10-12', '2026-10-12', 'Details']);

    assert_same([], $medical);
    assert_same([], $unpaid);
    assert_contains('Select a valid reason for the selected leave type.', implode(' ', $crossType));
    assert_contains('Reason category is required.', implode(' ', $missing));
    assert_contains('Select a valid reason for the selected leave type.', implode(' ', $unknown));
    assert_contains('Please choose a valid leave type.', implode(' ', $unsupportedLeaveType));
    assert_same(['casual', 'medical', 'unpaid', 'other'], LeaveReasonCatalog::leaveTypes());
    assert_true(max(array_map('strlen', array_merge(...array_map([LeaveReasonCatalog::class, 'categoriesForLeaveType'], LeaveReasonCatalog::leaveTypes())))) <= 32);
});

test('leave submission trims and stores reason under the authenticated session employee', function (): void {
    $controller = project_path('src/Controllers/LeaveController.php');

    assert_method_order($controller, 'store', [
        "requireAnyRole(['employee', 'department_head'])",
        'validateCsrfToken($request)',
        '$userId = $this->sessionManager->getUserId()',
        '$reasonCategory = trim(',
        '$reason = trim(',
        "['reason']",
        'validateLeaveRequestFields($userId',
        'createRequest($userId',
    ]);
    assert_not_contains("['user_id']", method_source($controller, 'store'));
});

test('leave reason field is a required escaped textarea and survives validation rendering', function (): void {
    $form = (string) file_get_contents(project_path('views/leave/form.php'));

    assert_contains('<textarea', $form);
    assert_contains('name="reason"', $form);
    assert_contains('maxlength="1000"', $form);
    assert_contains('required', $form);
    assert_contains("leave_module.reason_details_help", $form);
    assert_contains('name="reason_category"', $form);
    assert_contains('LeaveReasonCatalog::localizedMapping', $form);
    assert_contains('reasonCategoriesByLeaveType', $form);
    assert_contains('updateReasonCategories', $form);
    assert_contains('replaceChildren', $form);
    assert_contains("\$leaveRequest['reason_category']", $form);
    assert_contains("\$fieldErrors['reason_category']", $form);
    assert_contains("htmlspecialchars((string) (\$leaveRequest['reason'] ?? '')", $form);
    assert_not_contains('<select id="reason"', $form);

    $t = static fn (string $key): string => $key;
    $title = 'Request Leave';
    $errors = ['Another field is invalid.'];
    $fieldErrors = ['reason_category' => ['Choose a category.']];
    $leaveRequest = [
        'leave_type' => 'medical',
        'reason_category' => 'medical_appointment',
        'start_date' => '',
        'end_date' => '',
        'reason' => '<script>alert("reason")</script>',
    ];
    $csrf_token = 'test-token';
    ob_start();
    require project_path('views/leave/form.php');
    $output = (string) ob_get_clean();

    assert_contains('&lt;script&gt;alert(&quot;reason&quot;)&lt;/script&gt;', $output);
    assert_not_contains('<script>alert("reason")</script>', $output);
    assert_contains('value="medical_appointment" selected', $output);
    assert_contains('leave_module.reason_categories.medical_appointment', $output);
    assert_contains('Choose a category.', $output);
});

test('leave form keeps the requested two-column row order and full-width detail fields', function (): void {
    $form = (string) file_get_contents(project_path('views/leave/form.php'));

    assert_true(strpos($form, 'id="leave_type"') < strpos($form, 'id="reason_category"'));
    assert_true(strpos($form, 'id="reason_category"') < strpos($form, 'id="start_date"'));
    assert_true(strpos($form, 'id="start_date"') < strpos($form, 'id="end_date"'));
    assert_true(substr_count($form, 'class="md:col-span-2"') >= 2);
});

test('leave reason categories and supporting-document copy are localized in English and Myanmar', function (): void {
    $english = require project_path('lang/en.php');
    $myanmar = require project_path('lang/my.php');

    assert_same('Medical Appointment', $english['leave_module']['reason_categories']['medical_appointment']);
    assert_same('Study / Education', $english['leave_module']['reason_categories']['study_education']);
    assert_same('ဆေးဘက်ဆိုင်ရာ ချိန်းဆိုမှု', $myanmar['leave_module']['reason_categories']['medical_appointment']);
    assert_same('ပညာသင်ကြားရေး', $myanmar['leave_module']['reason_categories']['study_education']);
    foreach (LeaveReasonCatalog::leaveTypes() as $leaveType) {
        foreach (LeaveReasonCatalog::categoriesForLeaveType($leaveType) as $category) {
            assert_true(trim((string) ($english['leave_module']['reason_categories'][$category] ?? '')) !== '');
            assert_true(trim((string) ($myanmar['leave_module']['reason_categories'][$category] ?? '')) !== '');
        }
    }
    assert_true($myanmar['leave_module']['supporting_document_optional'] !== 'Supporting Document (Optional)');
});

test('dependent reason categories reuse the existing column and do not repurpose admin review note', function (): void {
    $service = method_source(project_path('src/Services/LeaveOperationService.php'), 'createRequest');
    $categoryMigrations = glob(project_path('migrations/*reason_category*.sql')) ?: [];

    assert_same(1, count($categoryMigrations));
    $migration = file_get_contents($categoryMigrations[0]);
    assert_true(is_string($migration));
    assert_contains('INFORMATION_SCHEMA.COLUMNS', $migration);
    assert_contains("COLUMN_NAME = 'reason_category'", $migration);
    assert_contains("'DO 1'", $migration);
    assert_contains('reason_category', $service);
    assert_contains(':reason', $service);
    assert_not_contains('review_note', $service);
});

test('reason category presentation localizes current and legacy values with a safe fallback', function (): void {
    $english = require project_path('lang/en.php');
    $translate = static function (string $key) use ($english): string {
        $segments = explode('.', $key);
        $value = $english;
        foreach ($segments as $segment) {
            $value = is_array($value) && array_key_exists($segment, $value) ? $value[$segment] : $key;
        }
        return is_string($value) ? $value : $key;
    };

    assert_same('Medical Appointment', LeaveReasonCatalog::label('medical_appointment', $translate));
    assert_same('Medical / Sick', LeaveReasonCatalog::label('medical_sick', $translate));
    assert_same('Legacy Custom Value', LeaveReasonCatalog::label('legacy_custom_value', $translate));
});

test('employee and admin leave details escape reason output and handle historical null values', function (): void {
    $employeeDetail = (string) file_get_contents(project_path('views/leave/show.php'));
    $adminDetail = (string) file_get_contents(project_path('views/leave/admin_show.php'));
    $employeeList = (string) file_get_contents(project_path('views/leave/index.php'));

    foreach ([$employeeDetail, $adminDetail, $employeeList] as $view) {
        assert_contains("leave_module.no_reason_provided", $view);
        assert_contains('htmlspecialchars', $view);
    }
    assert_contains("\$leaveRequest['reason']", $employeeDetail);
    assert_contains("\$leaveRequest['reason']", $adminDetail);
    assert_contains("\$leaveRequest['review_note']", $adminDetail);
});

test('leave report and exports keep employee reason separate from decision notes', function (): void {
    $reportView = (string) file_get_contents(project_path('views/reports/leave_requests.php'));
    $reportController = (string) file_get_contents(project_path('src/Controllers/ReportsController.php'));

    assert_contains("\$leaveRequest['reason']", $reportView);
    assert_contains("common.labels.reason", $reportView);
    assert_contains('requestReason($row)', $reportController);
    assert_contains('reasonCategory($row)', $reportController);
    assert_contains('notePreview($row)', $reportController);
});
