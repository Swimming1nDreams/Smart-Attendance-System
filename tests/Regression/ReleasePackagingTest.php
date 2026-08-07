<?php

declare(strict_types=1);

require_once project_path('scripts/package-release.php');

test('release package excludes the local environment file', function (): void {
    $entries = sapas_release_test_entries();
    assert_false(in_array('.env', $entries, true));
    assert_false(in_array('.env.local', $entries, true));
});

test('release package excludes the local mail configuration', function (): void {
    $entries = sapas_release_test_entries();
    assert_false(in_array('config/mail.local.php', $entries, true));
});

test('release package excludes Git metadata and operating-system artifacts', function (): void {
    $entries = sapas_release_test_entries();

    foreach ($entries as $entry) {
        assert_false(str_starts_with($entry, '.git/'));
        assert_false(str_ends_with($entry, '/.DS_Store') || $entry === '.DS_Store');
        assert_false(str_ends_with(strtolower($entry), '.zip'));
    }
});

test('release package preserves safe configuration examples', function (): void {
    $entries = sapas_release_test_entries();
    assert_true(in_array('.env.example', $entries, true));
    assert_true(in_array('config/mail.local.example.php', $entries, true));
});

test('release package includes required source migrations assets and documentation', function (): void {
    $entries = sapas_release_test_entries();
    assert_true(in_array('src/Core/Application.php', $entries, true));
    assert_true(in_array('migrations/2026_07_22_000000_add_session_version_to_users.sql', $entries, true));
    assert_true(in_array('public/assets/css/tailwind.css', $entries, true));
    assert_true(in_array('README.md', $entries, true));
    assert_true(in_array('tests/run.php', $entries, true));
});

/** @return list<string> */
function sapas_release_test_entries(): array
{
    static $entries = null;

    if (is_array($entries)) {
        return $entries;
    }

    if (!class_exists(ZipArchive::class)) {
        skip_test('PHP ZIP support is unavailable, so release archive contents could not be inspected.');
    }

    $outputDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sapas-release-test-' . bin2hex(random_bytes(8));
    $archivePath = buildSapasReleasePackage(
        project_path(),
        $outputDirectory,
        new DateTimeImmutable('2026-07-22 12:00:00'),
    );
    $zip = new ZipArchive();

    if ($zip->open($archivePath) !== true) {
        throw new RuntimeException('The generated release ZIP could not be opened for inspection.');
    }

    $entries = [];

    for ($index = 0; $index < $zip->numFiles; $index++) {
        $name = $zip->getNameIndex($index);

        if (is_string($name)) {
            $entries[] = $name;
        }
    }

    $zip->close();

    register_shutdown_function(static function () use ($archivePath, $outputDirectory): void {
        if (is_file($archivePath)) {
            unlink($archivePath);
        }

        if (is_dir($outputDirectory)) {
            rmdir($outputDirectory);
        }
    });

    return $entries;
}
