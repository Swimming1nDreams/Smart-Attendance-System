<?php

declare(strict_types=1);

test('workforce policy specification documents authoritative foundation and remaining review work', function (): void {
    $spec = file_get_contents(project_path('docs/COMPANY_SCHEDULE_ATTENDANCE_POLICY_SPEC.md'));

    assert_true(is_string($spec), 'Company schedule policy spec should be readable.');
    assert_contains('Status: Workforce Policy Center foundation implemented', $spec);
    assert_contains('Foundation Implemented', $spec);
    assert_contains('New employee/admin attendance writes resolve the employee policy by business date', $spec);
    assert_contains('OAAS remains a single-company system.', $spec);
    assert_contains('WorkScheduleResolverService', $spec);
    assert_contains('Employee assignment', $spec);
    assert_contains('Department assignment', $spec);
    assert_contains('Company assignment', $spec);
    assert_contains('Controllers and views must not decide whether Saturday or Sunday is a weekend.', $spec);
    assert_contains('Historical attendance is diagnosed but not bulk rewritten.', $spec);
    assert_contains('Do not trust `X-Forwarded-For`, `Forwarded`, or `X-Real-IP` by default.', $spec);
});
