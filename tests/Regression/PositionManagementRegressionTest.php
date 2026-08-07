<?php

declare(strict_types=1);

test('position update checks the target record before reporting success', function (): void {
    $controller = file_get_contents(project_path('src/Controllers/PositionController.php'));
    assert_true(is_string($controller), 'Position controller should be readable.');

    $updatePosition = strpos($controller, 'public function update');
    assert_true($updatePosition !== false, 'Position update action should exist.');

    $method = substr($controller, $updatePosition, 2200);
    assert_contains('$position = $this->positionModel->findById($id);', $method);
    assert_contains('$this->t(\'flash.position_not_found\')', $method);
    assert_contains("header('Location: /positions');", $method);

    $findPosition = strpos($method, '$position = $this->positionModel->findById($id);');
    $validatePosition = strpos($method, '$errors = $this->validate($name);');
    $savePosition = strpos($method, '$this->positionModel->update(');

    assert_true($findPosition !== false && $validatePosition !== false && $savePosition !== false);
    assert_true($findPosition < $validatePosition, 'Missing positions should be rejected before validation redisplays an edit form.');
    assert_true($findPosition < $savePosition, 'Missing positions must not be reported as successfully updated.');
});

test('position mutations remain admin and csrf guarded', function (): void {
    $controller = file_get_contents(project_path('src/Controllers/PositionController.php'));
    assert_true(is_string($controller), 'Position controller should be readable.');

    foreach (['store', 'update', 'delete'] as $action) {
        $position = strpos($controller, 'public function ' . $action);
        assert_true($position !== false, 'Position ' . $action . ' action should exist.');

        $method = substr($controller, $position, 1400);
        assert_contains('$this->ensureAdmin();', $method);
        assert_contains('$this->validateCsrfToken($request);', $method);

        $adminPosition = strpos($method, '$this->ensureAdmin();');
        $csrfPosition = strpos($method, '$this->validateCsrfToken($request);');
        assert_true($adminPosition !== false && $csrfPosition !== false);
        assert_true($adminPosition < $csrfPosition, 'Position ' . $action . ' should authorize before CSRF handling.');
    }
});

test('position deletion records an audit entry after reference protection', function (): void {
    $controller = file_get_contents(project_path('src/Controllers/PositionController.php'));
    assert_true(is_string($controller), 'Position controller should be readable.');

    $deletePosition = strpos($controller, 'public function delete');
    assert_true($deletePosition !== false, 'Position delete action should exist.');

    $method = substr($controller, $deletePosition, 1800);
    assert_contains('countAssignedUsers($id) > 0', $method);
    assert_contains('$this->positionModel->delete($id);', $method);
    assert_contains("'position.deleted'", $method);

    $referenceCheckPosition = strpos($method, 'countAssignedUsers($id) > 0');
    $deleteCallPosition = strpos($method, '$this->positionModel->delete($id);');
    $auditPosition = strpos($method, "'position.deleted'");

    assert_true($referenceCheckPosition !== false && $deleteCallPosition !== false && $auditPosition !== false);
    assert_true($referenceCheckPosition < $deleteCallPosition, 'Referenced positions should be protected before deletion.');
    assert_true($deleteCallPosition < $auditPosition, 'Successful position deletion should be audited after the delete call.');
});

test('position deleted audit action has localized labels', function (): void {
    $english = file_get_contents(project_path('lang/en.php'));
    $myanmar = file_get_contents(project_path('lang/my.php'));

    assert_true(is_string($english) && is_string($myanmar));
    assert_contains("'deleted' => 'Position Deleted'", $english);
    assert_contains("'deleted' => 'ရာထူး ဖျက်ခြင်း'", $myanmar);
});
