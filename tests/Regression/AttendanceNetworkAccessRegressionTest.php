<?php

declare(strict_types=1);

test('attendance network configuration is disabled by default and explicitly lists local addresses', function (): void {
    $config = file_get_contents(project_path('config/config.php'));
    $environment = file_get_contents(project_path('.env.example'));
    $wiring = file_get_contents(project_path('public/index.php'));

    assert_true(is_string($config));
    assert_true(is_string($environment));
    assert_true(is_string($wiring));
    assert_contains("getenv('ATTENDANCE_OFFICE_NETWORK_ENABLED')", $config);
    assert_contains("getenv('ATTENDANCE_OFFICE_ALLOWED_IPS')", $config);
    assert_contains('$officeNetworkEnabled === false ? \'0\'', $config);
    assert_contains('$officeAllowedIps === false ? \'127.0.0.1,::1\'', $config);
    assert_contains('ATTENDANCE_OFFICE_NETWORK_ENABLED=0', $environment);
    assert_contains('ATTENDANCE_OFFICE_ALLOWED_IPS=127.0.0.1,::1', $environment);
    assert_contains('new AttendanceNetworkAccessService($config[\'attendance_network\'] ?? [], $auditLogService)', $wiring);
});

test('employee check-in and check-out enforce office network before attendance writes', function (): void {
    $controller = project_path('src/Controllers/AttendanceController.php');

    foreach (['clockIn' => 'clock_in', 'clockOut' => 'clock_out'] as $method => $operation) {
        assert_method_order($controller, $method, [
            '$this->sessionManager->requireAnyRole([\'employee\', \'department_head\'])',
            '$this->validateCsrfToken($request)',
            '$userId = $this->sessionManager->getUserId()',
            '$this->ensureActiveEmployee($userId)',
            '$this->selfAttendanceNetworkAllowed($request, $userId, \'' . $operation . '\')',
            $method === 'clockIn' ? '->clockIn($userId' : 'requireAttendanceOperations()->clockOut(',
        ]);
        assert_method_contains($controller, $method, "flash.attendance_office_network_required");
    }

    assert_method_contains($controller, 'selfAttendanceNetworkAllowed', '$request->getServerParam(\'REMOTE_ADDR\', \'\')');
    assert_method_contains($controller, 'selfAttendanceNetworkAllowed', '->authorizeSelfAttendance(');
});

test('office network guard ignores forwarded headers and does not affect authorized manager corrections', function (): void {
    $controller = project_path('src/Controllers/AttendanceController.php');
    $networkService = file_get_contents(project_path('src/Services/AttendanceNetworkAccessService.php'));
    $selfGuard = method_source($controller, 'selfAttendanceNetworkAllowed');

    assert_true(is_string($networkService));
    foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_FORWARDED', 'HTTP_X_REAL_IP', 'X-Forwarded-For', 'X-Real-IP'] as $header) {
        assert_not_contains($header, $selfGuard);
        assert_not_contains($header, $networkService);
    }

    foreach (['store', 'update'] as $method) {
        assert_not_contains('selfAttendanceNetworkAllowed', method_source($controller, $method));
        assert_method_order($controller, $method, [
            '$this->ensureAdminOrDepartmentHead();',
            '$this->validateCsrfToken($request);',
            $method === 'store' ? '->createRecord(' : '->updateRecord(',
        ]);
    }
});

test('office network failure message and deployment documentation are bilingual and scoped', function (): void {
    $english = require project_path('lang/en.php');
    $myanmar = require project_path('lang/my.php');
    $readme = file_get_contents(project_path('README.md'));
    $developmentMap = file_get_contents(project_path('DEVELOPMENT_MAP.md'));
    $expectedEnglish = 'Check In and Check Out are available only on the approved office network. Connect to the office network and try again, or contact an administrator.';
    $expectedMyanmar = 'အလုပ်ဝင်ချိန်နှင့် အလုပ်ဆင်းချိန်ကို သတ်မှတ်ထားသော ရုံးကွန်ရက်တွင်သာ မှတ်တမ်းတင်နိုင်ပါသည်။ ရုံးကွန်ရက်သို့ ချိတ်ဆက်ပြီး ထပ်မံကြိုးစားပါ သို့မဟုတ် စီမံခန့်ခွဲသူထံ ဆက်သွယ်ပါ။';

    assert_same($expectedEnglish, $english['flash']['attendance_office_network_required'] ?? null);
    assert_same($expectedMyanmar, $myanmar['flash']['attendance_office_network_required'] ?? null);
    assert_same('Office Network Access Denied', $english['audit_logs']['actions']['attendance']['network_access_denied'] ?? null);
    assert_same('ရုံးကွန်ရက်ပြင်ပမှ အသုံးပြုမှု ပိတ်ပင်ထားသည်', $myanmar['audit_logs']['actions']['attendance']['network_access_denied'] ?? null);
    assert_same(
        'သတ်မှတ်ထားသော ရုံးကွန်ရက်ပြင်ပမှ ဝန်ထမ်းတက်ရောက်မှုကို ပိတ်ပင်ခဲ့သည်',
        $myanmar['audit_logs']['descriptions']['attendance']['network_access_denied'] ?? null,
    );
    assert_true(is_string($readme));
    assert_true(is_string($developmentMap));
    assert_contains('ATTENDANCE_OFFICE_NETWORK_ENABLED=0', $readme);
    assert_contains('uses only `REMOTE_ADDR`', $readme);
    assert_contains('AttendanceNetworkAccessService', $developmentMap);
    assert_contains('authorized manager attendance corrections remain unrestricted', $developmentMap);
});
