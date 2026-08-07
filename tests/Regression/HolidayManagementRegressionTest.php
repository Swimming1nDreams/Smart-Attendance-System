<?php

declare(strict_types=1);

test('holiday mutations remain admin and csrf guarded', function (): void {
    $controller = file_get_contents(project_path('src/Controllers/HolidayController.php'));
    assert_true(is_string($controller), 'Holiday controller should be readable.');

    foreach (['store', 'update', 'delete'] as $action) {
        $position = strpos($controller, 'public function ' . $action);
        assert_true($position !== false, 'Holiday ' . $action . ' action should exist.');

        $method = substr($controller, $position, 1600);
        assert_contains('$this->ensureAdmin();', $method);
        assert_contains('$this->validateCsrfToken($request);', $method);

        $adminPosition = strpos($method, '$this->ensureAdmin();');
        $csrfPosition = strpos($method, '$this->validateCsrfToken($request);');
        assert_true($adminPosition !== false && $csrfPosition !== false);
        assert_true($adminPosition < $csrfPosition, 'Holiday ' . $action . ' should authorize before CSRF handling.');
    }
});

test('holiday update checks the target record before reporting success', function (): void {
    $controller = file_get_contents(project_path('src/Controllers/HolidayController.php'));
    assert_true(is_string($controller), 'Holiday controller should be readable.');

    $updatePosition = strpos($controller, 'public function update');
    assert_true($updatePosition !== false, 'Holiday update action should exist.');

    $method = substr($controller, $updatePosition, 2400);
    assert_contains('$holiday = $this->holidayModel->findById($id);', $method);
    assert_contains('$this->t(\'flash.holiday_not_found\')', $method);
    assert_contains("header('Location: /holidays');", $method);

    $findPosition = strpos($method, '$holiday = $this->holidayModel->findById($id);');
    $validatePosition = strpos($method, '$errors = $this->validate($name, $date, $description);');
    $savePosition = strpos($method, '$this->holidayModel->update(');

    assert_true($findPosition !== false && $validatePosition !== false && $savePosition !== false);
    assert_true($findPosition < $validatePosition, 'Missing holidays should be rejected before validation redisplays an edit form.');
    assert_true($findPosition < $savePosition, 'Missing holidays must not be reported as successfully updated.');
});

test('holiday deletion records an audit entry after not found protection', function (): void {
    $controller = file_get_contents(project_path('src/Controllers/HolidayController.php'));
    assert_true(is_string($controller), 'Holiday controller should be readable.');

    $deletePosition = strpos($controller, 'public function delete');
    assert_true($deletePosition !== false, 'Holiday delete action should exist.');

    $method = substr($controller, $deletePosition, 1700);
    assert_contains('$holiday = $this->holidayModel->findById($id);', $method);
    assert_contains('$this->holidayModel->delete($id);', $method);
    assert_contains("'holiday.deleted'", $method);

    $findPosition = strpos($method, '$holiday = $this->holidayModel->findById($id);');
    $deleteCallPosition = strpos($method, '$this->holidayModel->delete($id);');
    $auditPosition = strpos($method, "'holiday.deleted'");

    assert_true($findPosition !== false && $deleteCallPosition !== false && $auditPosition !== false);
    assert_true($findPosition < $deleteCallPosition, 'Missing holidays should be protected before deletion.');
    assert_true($deleteCallPosition < $auditPosition, 'Successful holiday deletion should be audited after the delete call.');
});

test('myanmar holiday management labels avoid visible english fallbacks', function (): void {
    $myanmar = file_get_contents(project_path('lang/my.php'));
    assert_true(is_string($myanmar), 'Myanmar language file should be readable.');

    foreach ([
        "'created' => 'အားလပ်ရက် ထည့်ခြင်း'",
        "'deleted' => 'အားလပ်ရက် ဖျက်ခြင်း'",
        "'updated' => 'အားလပ်ရက် ပြင်ဆင်ခြင်း'",
        "'upcoming_title' => 'လာမည့် အားလပ်ရက်များ'",
        "'no_upcoming' => 'လာမည့် အားလပ်ရက် မရှိပါ'",
        "'status_upcoming' => 'လာမည့်'",
    ] as $expected) {
        assert_contains($expected, $myanmar);
    }
});
