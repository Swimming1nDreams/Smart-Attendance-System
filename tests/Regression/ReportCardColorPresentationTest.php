<?php

declare(strict_types=1);

test('reports landing cards have distinct restrained color treatments', function (): void {
    $view = file_get_contents(project_path('views/reports/index.php'));
    $css = file_get_contents(project_path('assets/css/tailwind.css'));

    assert_true(is_string($view), 'Reports index should be readable.');
    assert_true(is_string($css), 'Tailwind source should be readable.');

    foreach (['is-attendance', 'is-employee', 'is-leave', 'is-exception'] as $tone) {
        assert_contains($tone, $view);
        assert_contains('.sapas-report-card.' . $tone, $css);
    }

    assert_contains('border-teal-200/80', $css);
    assert_contains('border-plum-200/80', $css);
    assert_contains('border-indigo-200/80', $css);
    assert_contains('border-amber-100/90', $css);
    assert_contains('focus-visible:ring-2', $css);
});
