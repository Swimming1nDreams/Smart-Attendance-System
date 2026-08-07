<?php

declare(strict_types=1);

use App\Core\Localization;
use App\Services\AuditLogPresenter;

function localization_hotfix_set_locale(string $locale): Localization
{
    reset_test_session();
    $_SESSION['locale'] = $locale;
    $localization = new Localization(project_path('lang'));
    Localization::setCurrent($localization);

    return $localization;
}

function localization_hotfix_t(): callable
{
    return static fn (string $key): string => Localization::current()->translate($key);
}

function localization_hotfix_flatten_keys(array $values, string $prefix = ''): array
{
    $keys = [];
    foreach ($values as $key => $value) {
        $path = $prefix === '' ? (string) $key : $prefix . '.' . (string) $key;
        if (is_array($value)) {
            $keys = array_merge($keys, localization_hotfix_flatten_keys($value, $path));
            continue;
        }

        $keys[] = $path;
    }

    sort($keys);

    return $keys;
}

function localization_hotfix_value(array $values, string $path): string
{
    $current = $values;
    foreach (explode('.', $path) as $segment) {
        if (!is_array($current) || !array_key_exists($segment, $current)) {
            return '';
        }

        $current = $current[$segment];
    }

    return is_string($current) ? $current : '';
}

function localization_hotfix_placeholders(string $value): array
{
    preg_match_all('/:([A-Za-z_][A-Za-z0-9_]*)/', $value, $matches);
    $placeholders = $matches[1] ?? [];
    sort($placeholders);

    return array_values(array_unique($placeholders));
}

test('myanmar login and shared navigation strings do not fall back to confirmed english copy', function (): void {
    $english = require project_path('lang/en.php');
    $myanmar = require project_path('lang/my.php');

    foreach ([
        'auth.welcome_back' => 'Welcome back',
        'auth.secure_sign_in' => 'Secure workforce access',
        'auth.login_intro' => 'Use your work account to continue to attendance, leave, analytics, and reports.',
        'auth.security_reassurance' => 'Secure access for authorized workforce accounts only.',
        'app.tagline' => 'Performance & Workforce Analytics',
        'nav_groups.overview' => 'Overview',
        'nav_groups.workforce' => 'Workforce',
        'nav_groups.insights' => 'Insights',
        'nav_groups.administration' => 'Administration',
        'nav.workforce_policy_center' => 'Workforce Policy Center',
    ] as $key => $englishText) {
        assert_same($englishText, localization_hotfix_value($english, $key), 'English baseline should remain unchanged for ' . $key . '.');
        assert_not_contains($englishText, localization_hotfix_value($myanmar, $key), 'Myanmar should not fall back to English for ' . $key . '.');
    }

    assert_contains('ပြန်လည်ကြိုဆိုပါတယ်', $myanmar['auth']['welcome_back']);
    assert_contains('အလုပ်မူဝါဒ', $myanmar['nav']['workforce_policy_center']);
});

test('admin leave summary renders localized myanmar sentence templates', function (): void {
    localization_hotfix_set_locale('my');
    $t = localization_hotfix_t();
    $title = $t('leave_module.admin_title');
    $errors = [];
    $filters = ['status' => '', 'leave_type' => '', 'search' => '', 'from' => '', 'to' => ''];
    $page = 1;
    $perPage = 20;
    $totalRequests = 6;
    $leaveRequests = [];

    for ($i = 1; $i <= 6; $i++) {
        $leaveRequests[] = [
            'id' => $i,
            'employee_name' => 'May Thu Hlaing',
            'employee_email' => 'may.thu.hlaing@example.test',
            'staff_code' => 'SAP-0018',
            'status' => 'pending',
            'leave_type' => 'casual',
            'start_date' => '2026-08-0' . min($i, 9),
            'end_date' => '2026-08-0' . min($i, 9),
            'duration_days' => 1,
            'created_at' => '2026-07-31 09:00:00',
        ];
    }

    ob_start();
    require project_path('views/leave/admin_index.php');
    $html = (string) ob_get_clean();

    assert_contains('ဤစာမျက်နှာတွင် တောင်းဆိုချက် 6 ခု', $html);
    assert_contains('စုစုပေါင်း တောင်းဆိုချက် 6 ခု', $html);
    assert_contains('စာမျက်နှာ 1 / 1', $html);
    assert_not_contains('requests on this page', $html);
    assert_not_contains(' matching request', $html);
    assert_not_contains(' of ', $html);
});

test('audit logs render localized standard labels and preserve unknown audit fallback', function (): void {
    localization_hotfix_set_locale('my');
    $t = localization_hotfix_t();
    $errors = [];
    $records = [
        [
            'id' => 1,
            'created_at' => '2026-07-31 09:00:00',
            'actor_name' => 'Admin User',
            'actor_staff_code' => 'SAP-0001',
            'action' => 'auth.admin_login_succeeded',
            'entity_type' => 'user',
            'entity_id' => 1,
            'description' => 'Administrator signed in successfully',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Mozilla/5.0 Chrome/126.0 Windows',
        ],
        [
            'id' => 2,
            'created_at' => '2026-07-31 09:05:00',
            'actor_name' => 'Admin User',
            'actor_staff_code' => 'SAP-0001',
            'action' => 'report.excel_exported',
            'entity_type' => 'report',
            'entity_id' => null,
            'description' => 'Excel report generated',
            'ip_address' => '127.0.0.1',
            'user_agent' => '',
        ],
        [
            'id' => 3,
            'created_at' => '2026-07-31 09:10:00',
            'actor_name' => 'Admin User',
            'actor_staff_code' => 'SAP-0001',
            'action' => 'work_schedule.weekly_schedule_updated',
            'entity_type' => 'work_schedule_template',
            'entity_id' => 1,
            'description' => 'Weekly schedule updated',
            'ip_address' => '127.0.0.1',
            'user_agent' => '',
        ],
        [
            'id' => 4,
            'created_at' => '2026-07-31 09:15:00',
            'actor_name' => 'Admin User',
            'actor_staff_code' => 'SAP-0001',
            'action' => 'work_schedule.lifecycle_scheduled',
            'entity_type' => 'work_schedule_template',
            'entity_id' => 1,
            'description' => 'Work schedule lifecycle changed',
            'ip_address' => '127.0.0.1',
            'user_agent' => '',
        ],
    ];
    $actions = array_column($records, 'action');
    $entityTypes = ['user', 'report', 'work_schedule_template'];
    $actors = [];
    $filters = [
        'date_from_input' => '',
        'date_to_input' => '',
        'actor_user_id' => '',
        'action' => '',
        'entity_type' => '',
        'search' => '',
    ];
    $total = count($records);
    $page = 1;
    $perPage = 20;

    ob_start();
    require project_path('views/audit_logs/index.php');
    $html = (string) ob_get_clean();

    assert_contains('စတင်ရက်', $html);
    assert_contains('ပြီးဆုံးရက်', $html);
    assert_contains('စီမံခန့်ခွဲသူ ဝင်ရောက်မှု အောင်မြင်သည်', $html);
    assert_contains('စီမံခန့်ခွဲသူ အောင်မြင်စွာ ဝင်ရောက်ခဲ့သည်', $html);
    assert_contains('အလုပ်စည်းမျဉ်း အချိန်သတ်မှတ်ထားသည်', $html);
    assert_contains('အလုပ်ချိန်ဇယား ပုံစံ', $html);
    assert_not_contains('From date', $html);
    assert_not_contains('To date', $html);
    assert_not_contains('Search actor, description, or target', $html);
    assert_not_contains('Administrator Login Succeeded', $html);
    assert_not_contains('Administrator signed in successfully', $html);

    assert_same('Custom Event Triggered', AuditLogPresenter::actionLabel('custom.event_triggered', $t));
    assert_same('Free-form admin note', AuditLogPresenter::description('Free-form admin note', $t));

    localization_hotfix_set_locale('en');
    $englishT = localization_hotfix_t();
    assert_same('Employee Checked In', AuditLogPresenter::actionLabel('attendance.checked_in', $englishT));
    assert_same('Employee Checked Out', AuditLogPresenter::actionLabel('attendance.checked_out', $englishT));
    assert_same('Employee checked in', AuditLogPresenter::description('Employee checked in', $englishT));
    assert_same('Employee checked out', AuditLogPresenter::description('Employee checked out', $englishT));

    localization_hotfix_set_locale('my');
    $myanmarT = localization_hotfix_t();
    assert_not_contains('Employee Checked In', AuditLogPresenter::actionLabel('attendance.checked_in', $myanmarT));
    assert_not_contains('Employee Checked Out', AuditLogPresenter::actionLabel('attendance.checked_out', $myanmarT));
    assert_not_contains('Employee checked in', AuditLogPresenter::description('Employee checked in', $myanmarT));
    assert_not_contains('Employee checked out', AuditLogPresenter::description('Employee checked out', $myanmarT));
});

test('english and myanmar translation keys and placeholders remain in parity', function (): void {
    $english = require project_path('lang/en.php');
    $myanmar = require project_path('lang/my.php');
    $englishKeys = localization_hotfix_flatten_keys($english);
    $myanmarKeys = localization_hotfix_flatten_keys($myanmar);

    assert_same($englishKeys, $myanmarKeys, 'English and Myanmar translation key sets should match.');

    foreach ($englishKeys as $key) {
        assert_same(
            localization_hotfix_placeholders(localization_hotfix_value($english, $key)),
            localization_hotfix_placeholders(localization_hotfix_value($myanmar, $key)),
            'Placeholder variables should match for ' . $key . '.'
        );
    }
});

test('logout redirects back to login with the selected locale', function (): void {
    $controller = file_get_contents(project_path('src/Controllers/AuthController.php'));

    assert_true(is_string($controller), 'AuthController should be readable.');
    assert_contains('\\App\\Core\\Localization::current()->locale()', $controller);
    assert_contains("header('Location: /login?lang=' . rawurlencode(\$logoutLocale));", $controller);
});
