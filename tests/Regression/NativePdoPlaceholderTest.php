<?php

declare(strict_types=1);

test('model SQL string literals do not reuse named placeholders under native PDO prepares', function (): void {
    $modelFiles = glob(project_path('src/Models/*.php')) ?: [];
    $violations = [];

    foreach ($modelFiles as $modelFile) {
        foreach (token_get_all((string) file_get_contents($modelFile)) as $token) {
            if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            $literal = stripcslashes(substr($token[1], 1, -1));
            preg_match_all('/:[A-Za-z_][A-Za-z0-9_]*/', $literal, $matches);

            foreach (array_count_values($matches[0]) as $placeholder => $count) {
                if ($count > 1) {
                    $violations[] = basename($modelFile) . ':' . $token[2] . ' reuses ' . $placeholder;
                }
            }
        }
    }

    assert_same([], $violations, 'Native PDO placeholders must be unique per SQL statement: ' . implode(', ', $violations));
});
