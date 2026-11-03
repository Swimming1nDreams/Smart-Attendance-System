<?php

declare(strict_types=1);

test('release-facing branding uses the current OAAS identity', function (): void {
    $releaseFacingFiles = [
        'AGENTS.md.txt',
        'composer.json',
        'package.json',
        'lang/en.php',
        'config/mail.php',
        'config/mail.local.example.php',
        'docs/COMPANY_SCHEDULE_ATTENDANCE_POLICY_SPEC.md',
        'scripts/cleanup_phase2c_final_demo.php',
        'scripts/diagnose_attendance_consistency.php',
        'scripts/rehearse_attendance_session_backfill.php',
        'scripts/seed_phase2b_leave_attachment_demo.php',
    ];
    $retiredVisibleTerms = [
        'OAPAS',
        'SAPAS',
        'Smart Attendance',
        'Office Attendance And Performance Analysis System',
        'Office Attendance and Performance Analysis System',
        'Office Attendance and Performance Analytics System',
    ];

    foreach ($releaseFacingFiles as $path) {
        $source = (string) file_get_contents(project_path($path));
        foreach ($retiredVisibleTerms as $term) {
            assert_not_contains($term, $source, $path . ' contains retired visible branding: ' . $term);
        }
    }

    assert_contains('OAAS — Office Attendance and Analytic System', (string) file_get_contents(project_path('AGENTS.md.txt')));
    assert_contains('Office Attendance and Analytic System', (string) file_get_contents(project_path('lang/en.php')));
    assert_contains('no-reply@oaas.local', (string) file_get_contents(project_path('config/mail.php')));
    assert_contains('"name": "oaas-ui"', (string) file_get_contents(project_path('package.json')));
    assert_contains('"name": "oaas/oaas"', (string) file_get_contents(project_path('composer.json')));

    $browserChecklist = (string) file_get_contents(project_path('tests/Browser/seeded_flows.md'));
    assert_contains('# OAAS Seeded Browser Flow Checklist', $browserChecklist);
    assert_not_contains('OAPAS', $browserChecklist);
    assert_contains('SAPAS_BROWSER_DB_NAME', $browserChecklist, 'The browser checklist must document the legacy environment fallback.');
});

test('OAAS tooling variables preserve explicit SAPAS compatibility fallbacks', function (): void {
    foreach ([
        'src/Services/DemoAccountRefreshService.php',
        'src/Services/ReportPdfExportService.php',
        'seeds/seed_demo_data.php',
        'seeds/seed_2026_holidays.php',
        'seeds/seed_august_2026_demo.php',
        'tests/browser_seed.php',
        'tests/Support/TestDatabase.php',
    ] as $path) {
        $source = (string) file_get_contents(project_path($path));
        assert_contains('OAAS_', $source, $path . ' must support an OAAS-prefixed setting.');
        assert_contains('SAPAS_', $source, $path . ' must retain its legacy SAPAS fallback.');
    }
});
