<?php

declare(strict_types=1);

test('PHP built-in server passes existing public files through instead of routing them as pages', function (): void {
    $bootstrap = file_get_contents(project_path('public/index.php'));

    assert_true($bootstrap !== false);
    assert_contains("PHP_SAPI === 'cli-server'", $bootstrap);
    assert_contains('is_file($requestedFile)', $bootstrap);
    assert_contains('return false;', $bootstrap);
});
