<?php

declare(strict_types=1);

test('audit log screens use translated compact presentation labels', function (): void {
    $indexView = file_get_contents(project_path('views/audit_logs/index.php'));
    $showView = file_get_contents(project_path('views/audit_logs/show.php'));
    $english = require project_path('lang/en.php');
    $myanmar = require project_path('lang/my.php');
    $css = file_get_contents(project_path('assets/css/tailwind.css'));

    assert_true(is_string($indexView), 'Audit log index should be readable.');
    assert_true(is_string($showView), 'Audit log detail view should be readable.');
    assert_true(is_string($css), 'Tailwind source should be readable.');

    assert_contains('sapas-audit-table', $indexView);
    assert_contains('sapas-data-table-scroll', $indexView);
    assert_contains('audit_logs.from_date', $indexView);
    assert_contains('audit_logs.to_date', $indexView);
    assert_not_contains('name="entity_id"', $indexView);
    assert_contains('xl:col-span-3', $indexView);
    assert_not_contains('common.labels.from_date', $indexView);
    assert_not_contains('common.labels.to_date', $indexView);
    assert_contains('translateAuditAction', $indexView);
    assert_contains('translateEntityType', $indexView);
    assert_contains('translateAuditAction', $showView);
    assert_contains('AuditSourcePresenter::describe', $indexView);
    assert_contains('AuditSourcePresenter::describe', $showView);
    assert_contains('sapas-audit-source-cell', $indexView);
    assert_contains('$previewAuditText', $indexView);
    assert_contains('title="<?= htmlspecialchars($actionLabel, ENT_QUOTES, \'UTF-8\') ?>"', $indexView);
    assert_contains('title="<?= htmlspecialchars($targetLabel, ENT_QUOTES, \'UTF-8\') ?>"', $indexView);
    assert_contains('sapas-audit-source-cell', $showView);
    assert_contains('.sapas-audit-action-badge', $css);
    assert_contains('.sapas-audit-description', $css);
    assert_contains('.sapas-audit-source-label', $css);
    assert_contains('.sapas-audit-source-agent', $css);

    assert_same('Administrator Login Succeeded', $english['audit_logs']['actions']['auth']['admin_login_succeeded'] ?? null);
    assert_same('Excel Report Exported', $english['audit_logs']['actions']['report']['excel_exported'] ?? null);
    assert_same('Localhost', $english['audit_logs']['source']['localhost'] ?? null);
    assert_same('Office/Remote Network', $english['audit_logs']['source']['private_network'] ?? null);
    assert_true(isset($myanmar['audit_logs']['actions']['auth']['admin_login_succeeded']), 'Myanmar audit action translation should exist.');
    assert_true(isset($myanmar['audit_logs']['actions']['report']['excel_exported']), 'Myanmar Excel audit action translation should exist.');
    assert_true(isset($myanmar['audit_logs']['source']['localhost']), 'Myanmar audit source translation should exist.');
    assert_true(isset($myanmar['audit_logs']['source']['private_network']), 'Myanmar private source translation should exist.');
    assert_same('User account', $english['audit_logs']['entity_types']['user_account'] ?? null);
});
