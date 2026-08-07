<?php

declare(strict_types=1);

use App\Core\Localization;

test('localization defaults to English and falls back safely for missing keys', function (): void {
    reset_test_session();

    $localization = new Localization(project_path('lang'));
    Localization::setCurrent($localization);

    assert_same('en', $localization->locale());
    assert_same('Dashboard', $localization->translate('nav.dashboard'));
    assert_same('missing.translation.key', $localization->translate('missing.translation.key'));
});

test('query language switching supports English and Myanmar and persists in session', function (): void {
    reset_test_session();

    $localization = new Localization(project_path('lang'));
    $localization->handleQueryLanguage(['lang' => 'my']);

    assert_same('my', $localization->locale());
    assert_same('my', $_SESSION['locale'] ?? null);
    assert_same('my', $localization->htmlLang());

    $persistedLocalization = new Localization(project_path('lang'));
    assert_same('my', $persistedLocalization->locale(), 'Locale should be restored from session.');

    $persistedLocalization->handleQueryLanguage(['lang' => 'en']);
    assert_same('en', $persistedLocalization->locale());
    assert_same('en', $_SESSION['locale'] ?? null);
});

test('unsupported language values are ignored safely', function (): void {
    reset_test_session();

    $localization = new Localization(project_path('lang'));
    $localization->handleQueryLanguage(['lang' => 'fr']);

    assert_same('en', $localization->locale());
    assert_false(isset($_SESSION['locale']));
});

test('report duration unit abbreviations are localized', function (): void {
    reset_test_session();

    $english = new Localization(project_path('lang'));
    assert_same('h', $english->translate('common.units.hours_short'));
    assert_same('m', $english->translate('common.units.minutes_short'));

    $english->handleQueryLanguage(['lang' => 'my']);
    $myanmar = new Localization(project_path('lang'));
    assert_same(' နာရီ', $myanmar->translate('common.units.hours_short'));
    assert_same(' မိနစ်', $myanmar->translate('common.units.minutes_short'));
});

test('english and myanmar localization keys stay synchronized', function (): void {
    $flatten = static function (array $values, string $prefix = '') use (&$flatten): array {
        $keys = [];
        foreach ($values as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . (string) $key;
            if (is_array($value)) {
                $keys = array_merge($keys, $flatten($value, $path));
                continue;
            }
            $keys[] = $path;
        }
        sort($keys);
        return $keys;
    };

    $english = require project_path('lang/en.php');
    $myanmar = require project_path('lang/my.php');

    assert_same($flatten($english), $flatten($myanmar));
});

test('language switch URLs preserve the current internal path and query', function (): void {
    reset_test_session();
    $_SERVER['REQUEST_URI'] = '/admin/reports?status=pending&lang=en';

    $localization = new Localization(project_path('lang'));

    assert_same('/admin/reports?status=pending&lang=my', $localization->switchUrl('my'));
});
