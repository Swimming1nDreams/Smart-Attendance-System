<?php

declare(strict_types=1);

use App\Models\EmployeeProfile;

test('employee self profile contact and photo updates preserve protected account fields and other employees', function (): void {
    $database = TestDatabase::connect();
    $ids = $database->resetAndSeed();
    $connection = $database->pdo();
    $profileModel = new EmployeeProfile($connection);

    $accountStatement = $connection->prepare('SELECT name, email, role, status, department_id, position_id, staff_code, employment_type, join_date, expected_daily_work_hours, overtime_eligible FROM users WHERE id = :id');
    $accountStatement->execute([':id' => $ids['employee_id']]);
    $accountBefore = $accountStatement->fetch();
    assert_true(is_array($accountBefore));

    assert_true($profileModel->save($ids['employee_id'], '09700999001', 'Yangon'));
    assert_true($profileModel->updatePhotoFilename($ids['employee_id'], str_repeat('a', 32) . '.png'));
    assert_true($profileModel->save($ids['employee_id'], '094591020', 'Mandalay'));

    $ownProfile = $profileModel->findByUserId($ids['employee_id']);
    assert_not_null($ownProfile);
    assert_same('094591020', $ownProfile['phone']);
    assert_same('Mandalay', $ownProfile['address']);
    assert_same(str_repeat('a', 32) . '.png', $ownProfile['profile_photo_filename']);
    assert_null($profileModel->findByUserId($ids['other_employee_id']));

    $accountStatement->execute([':id' => $ids['employee_id']]);
    assert_same($accountBefore, $accountStatement->fetch());
});
