<?php

declare(strict_types=1);

use App\Services\ReportPdfExportService;
use App\Core\Localization;

test('PDF browser discovery gives explicit configuration priority', function (): void {
    $existing = ['C:\\Configured\\browser.exe', 'C:\\Environment\\chrome.exe'];
    $service = new ReportPdfExportService(
        $existing[0],
        ['SAPAS_CHROME_PATH' => $existing[1], 'PATH' => ''],
        'Windows',
        static fn (string $path): bool => in_array($path, $existing, true)
    );

    assert_same($existing[0], call_private($service, 'browserExecutablePath'));
});

test('PDF browser discovery prefers OAAS environment configuration and keeps the SAPAS fallback', function (): void {
    $oaasBrowser = 'C:\\Environment\\oaas-chrome.exe';
    $legacyBrowser = 'C:\\Environment\\legacy-chrome.exe';
    $pathExists = static fn (string $path): bool => in_array($path, [$oaasBrowser, $legacyBrowser], true);

    $preferred = new ReportPdfExportService(
        null,
        ['OAAS_CHROME_PATH' => $oaasBrowser, 'SAPAS_CHROME_PATH' => $legacyBrowser, 'PATH' => ''],
        'Windows',
        $pathExists
    );
    $fallback = new ReportPdfExportService(
        null,
        ['SAPAS_CHROME_PATH' => $legacyBrowser, 'PATH' => ''],
        'Windows',
        $pathExists
    );

    assert_same($oaasBrowser, call_private($preferred, 'browserExecutablePath'));
    assert_same($legacyBrowser, call_private($fallback, 'browserExecutablePath'));
});

test('PDF browser discovery supports standard macOS Chrome and Edge paths', function (): void {
    $chrome = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
    $edge = '/Applications/Microsoft Edge.app/Contents/MacOS/Microsoft Edge';

    $chromeService = new ReportPdfExportService(null, ['PATH' => ''], 'Darwin', static fn (string $path): bool => $path === $chrome);
    $edgeService = new ReportPdfExportService(null, ['PATH' => ''], 'Darwin', static fn (string $path): bool => $path === $edge);

    assert_same($chrome, call_private($chromeService, 'browserExecutablePath'));
    assert_same($edge, call_private($edgeService, 'browserExecutablePath'));
});

test('PDF export prefers an available browser for English and Myanmar output', function (): void {
    reset_test_session();
    Localization::setCurrent(new Localization(project_path('lang')));
    $browser = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
    $service = new ReportPdfExportService($browser, ['PATH' => ''], 'Windows', static fn (string $path): bool => $path === $browser);

    $_SESSION['locale'] = 'en';
    assert_true(call_private($service, 'shouldUseBrowserRenderer'));
    $_SESSION['locale'] = 'my';
    assert_true(call_private($service, 'shouldUseBrowserRenderer'));
});

test('PDF browser discovery preserves Windows paths and safe missing fallback', function (): void {
    $windowsChrome = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
    $windowsService = new ReportPdfExportService(null, ['PATH' => ''], 'Windows', static fn (string $path): bool => $path === $windowsChrome);
    $missingService = new ReportPdfExportService(null, ['PATH' => ''], 'Darwin', static fn (string $path): bool => false);

    assert_same($windowsChrome, call_private($windowsService, 'browserExecutablePath'));
    assert_null(call_private($missingService, 'browserExecutablePath'));
});
