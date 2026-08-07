<?php

declare(strict_types=1);

use App\Services\DateValidationService;

test('strict dates accept normal and leap-day calendar values', function (): void {
    $validator = new DateValidationService();

    assert_true($validator->isValidDate('2026-02-28'));
    assert_true($validator->isValidDate('2024-02-29'));
    assert_true($validator->isValidMonth('2026-07'));
});

test('strict dates reject normalization invalid months and non-padded values', function (): void {
    $validator = new DateValidationService();

    foreach (['2026-02-29', '2026-02-30', '2026-13-01', '2026-00-10', '22-07-2026', '2026/07/22', '2026-2-5'] as $invalid) {
        assert_false($validator->isValidDate($invalid), 'Expected invalid date: ' . $invalid);
    }
    assert_false($validator->isValidMonth('2026-13'));
    assert_false($validator->isValidMonth('2026-7'));
});

test('strict optional dates and ranges preserve supported empty behavior', function (): void {
    $validator = new DateValidationService();

    assert_null($validator->normalizeOptionalDate(''));
    assert_same('2026-07-22', $validator->normalizeOptionalDate('2026-07-22'));
    assert_null($validator->normalizeOptionalDate('2026-02-30'));
    assert_true($validator->isValidRange(null, '2026-07-22'));
    assert_true($validator->isValidRange('2026-07-01', '2026-07-22'));
    assert_false($validator->isValidRange('2026-07-23', '2026-07-22'));
});

test('time normalization accepts supported HTML and database formats only', function (): void {
    $validator = new DateValidationService();

    assert_same('09:05:00', $validator->normalizeTime('09:05'));
    assert_same('09:05:30', $validator->normalizeTime('09:05:30'));
    assert_null($validator->normalizeTime('9:5'));
    assert_null($validator->normalizeTime('25:00'));
});
