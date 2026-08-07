<?php

declare(strict_types=1);

use App\Models\Holiday;

test('database integration: holiday search works across name date and description with native prepared statements', function (): void {
    $database = integration_database();
    $database->resetAndSeed();
    $holidays = new Holiday($database->pdo());

    assert_true($holidays->create('Company Wellness Day', '2026-07-02', 'Scheduled employee wellbeing observance'));

    $nameMatches = $holidays->findAll('Wellness');
    assert_same(1, count($nameMatches));
    assert_same('Company Wellness Day', $nameMatches[0]['holiday_name']);

    $descriptionMatches = $holidays->findAll('wellbeing');
    assert_same(1, count($descriptionMatches));
    assert_same('Company Wellness Day', $descriptionMatches[0]['holiday_name']);

    $dateMatches = $holidays->findAll('2026-07-02');
    assert_same(1, count($dateMatches));
    assert_same('Company Wellness Day', $dateMatches[0]['holiday_name']);
});
