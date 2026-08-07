<?php

declare(strict_types=1);

require_once project_path('scripts/package-release.php');

test('final release rules exclude runtime data uploads and local secrets', function (): void {
    foreach ([
        ['.env', false],
        ['config/mail.local.php', false],
        ['.uploads/temporary-document.pdf', false],
        ['storage/backups/database.sql', false],
        ['storage/generated-reports/report.pdf', false],
        ['storage/private/demo-credentials.txt', false],
        ['storage/sessions/sess_example', false],
        ['storage/dompdf-fonts/generated-font-cache.ttf', false],
        ['releases/old-release.zip', false],
    ] as [$path, $isDirectory]) {
        assert_true(
            shouldExcludeSapasReleasePath($path, $isDirectory),
            sprintf('Release packaging should exclude %s.', $path),
        );
    }

    foreach ([
        ['.env.example', false],
        ['config/mail.local.example.php', false],
        ['public/assets/css/tailwind.css', false],
        ['public/assets/js/main.js', false],
        ['README.md', false],
    ] as [$path, $isDirectory]) {
        assert_false(
            shouldExcludeSapasReleasePath($path, $isDirectory),
            sprintf('Release packaging should preserve %s.', $path),
        );
    }
});

test('editable and served JavaScript remain synchronized', function (): void {
    $source = file_get_contents(project_path('assets/js/main.js'));
    $runtime = file_get_contents(project_path('public/assets/js/main.js'));

    assert_true(is_string($source) && $source !== '');
    assert_same($source, $runtime, 'The runtime JavaScript must match the editable source.');
});
