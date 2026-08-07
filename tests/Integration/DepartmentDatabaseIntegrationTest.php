<?php

declare(strict_types=1);

use App\Models\Department;

test('database integration: department search matches names and descriptions with native prepares', function (): void {
    $database = integration_database();
    $database->resetAndSeed();
    $departments = new Department($database->pdo());

    $departments->create('Customer Support', 'Handles customer enquiries.');
    $departments->create('Finance', 'Owns customer billing and payroll.');
    $departments->create('IT', 'IT Department');
    $departments->create('Quality Assurance', 'Reviews service quality, process compliance, and continuous improvement.');

    $nameMatches = $departments->findAll('Support');
    assert_same(1, count($nameMatches));
    assert_same('Customer Support', $nameMatches[0]['name']);

    $descriptionMatches = $departments->findAll('billing');
    assert_same(1, count($descriptionMatches));
    assert_same('Finance', $descriptionMatches[0]['name']);

    $shortNameMatches = $departments->findAll('IT');
    assert_same(1, count($shortNameMatches), 'Short searches must not match text inside another word such as Quality.');
    assert_same('IT', $shortNameMatches[0]['name']);
});
