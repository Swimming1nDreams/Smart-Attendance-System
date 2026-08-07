<?php

declare(strict_types=1);

test('department update checks the target record before reporting success', function (): void {
    $controller = file_get_contents(project_path('src/Controllers/DepartmentController.php'));
    assert_true(is_string($controller), 'Department controller should be readable.');

    $updatePosition = strpos($controller, 'public function update');
    assert_true($updatePosition !== false, 'Department update action should exist.');

    $method = substr($controller, $updatePosition, 2600);
    assert_contains('$oldDepartment = $this->departmentModel->findById($id);', $method);
    assert_contains('$this->t(\'flash.department_not_found\')', $method);
    assert_contains("header('Location: /departments');", $method);

    $findPosition = strpos($method, '$oldDepartment = $this->departmentModel->findById($id);');
    $validatePosition = strpos($method, '$errors = $this->validate(');
    $savePosition = strpos($method, '$this->departmentModel->update(');

    assert_true($findPosition !== false && $validatePosition !== false && $savePosition !== false);
    assert_true($findPosition < $validatePosition, 'Missing departments should be rejected before validation redisplays an edit form.');
    assert_true($findPosition < $savePosition, 'Missing departments must not be reported as successfully updated.');
});

test('department mutations remain admin and csrf guarded', function (): void {
    $controller = file_get_contents(project_path('src/Controllers/DepartmentController.php'));
    assert_true(is_string($controller), 'Department controller should be readable.');

    foreach (['store', 'update', 'delete'] as $action) {
        $position = strpos($controller, 'public function ' . $action);
        assert_true($position !== false, 'Department ' . $action . ' action should exist.');

        $method = substr($controller, $position, 1400);
        assert_contains('$this->ensureAdmin();', $method);
        assert_contains('$this->validateCsrfToken($request);', $method);

        $adminPosition = strpos($method, '$this->ensureAdmin();');
        $csrfPosition = strpos($method, '$this->validateCsrfToken($request);');
        assert_true($adminPosition !== false && $csrfPosition !== false);
        assert_true($adminPosition < $csrfPosition, 'Department ' . $action . ' should authorize before CSRF handling.');
    }
});

test('department deletion records an audit entry after reference protection', function (): void {
    $controller = file_get_contents(project_path('src/Controllers/DepartmentController.php'));
    assert_true(is_string($controller), 'Department controller should be readable.');

    $deletePosition = strpos($controller, 'public function delete');
    assert_true($deletePosition !== false, 'Department delete action should exist.');

    $method = substr($controller, $deletePosition, 1800);
    assert_contains('countAssignedUsers($id) > 0', $method);
    assert_contains('$this->departmentModel->delete($id);', $method);
    assert_contains("'department.deleted'", $method);

    $referenceCheckPosition = strpos($method, 'countAssignedUsers($id) > 0');
    $deleteCallPosition = strpos($method, '$this->departmentModel->delete($id);');
    $auditPosition = strpos($method, "'department.deleted'");

    assert_true($referenceCheckPosition !== false && $deleteCallPosition !== false && $auditPosition !== false);
    assert_true($referenceCheckPosition < $deleteCallPosition, 'Referenced departments should be protected before deletion.');
    assert_true($deleteCallPosition < $auditPosition, 'Successful department deletion should be audited after the delete call.');
});
