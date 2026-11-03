<?php

declare(strict_types=1);

use App\Controllers\LeaveController;
use App\Core\SessionManager;
use Tests\Support\FakeApplication;
use Tests\Support\FakeEmployee;
use Tests\Support\FakeHoliday;
use Tests\Support\FakeLeaveRequest;

function make_leave_controller(): LeaveController
{
    reset_test_session();

    return new LeaveController(
        new FakeLeaveRequest([
            100 => ['id' => 100, 'user_id' => 21, 'status' => 'pending'],
            101 => ['id' => 101, 'user_id' => 22, 'status' => 'approved'],
        ]),
        new FakeHoliday(),
        new FakeEmployee([
            21 => ['id' => 21, 'role' => 'employee', 'status' => 'active', 'name' => 'Employee A'],
            22 => ['id' => 22, 'role' => 'employee', 'status' => 'active', 'name' => 'Employee B'],
        ]),
        new SessionManager(),
        new FakeApplication()
    );
}

test('employees can resolve only their own leave request details', function (): void {
    $controller = make_leave_controller();

    $owned = call_private($controller, 'fetchOwnedLeaveRequest', [100, 21]);
    assert_same(100, (int) $owned['id']);

    assert_null(call_private($controller, 'fetchOwnedLeaveRequest', [101, 21]), 'Changing the ID must not expose another employee leave request.');
    assert_null(call_private($controller, 'fetchOwnedLeaveRequest', [999, 21]));
});

test('employee leave detail and hidden-id cancellation verify ownership before exposing or changing a request', function (): void {
    $controller = project_path('src/Controllers/LeaveController.php');

    assert_method_order($controller, 'show', [
        '$this->sessionManager->requireAnyRole([\'employee\', \'department_head\'])',
        '$request->query(\'id\', 0)',
        '$userId = $this->sessionManager->getUserId()',
        '$this->fetchOwnedLeaveRequest($leaveId, $userId)',
        '$this->renderView(\'leave/show\'',
    ]);
    assert_method_order($controller, 'cancel', [
        '$this->sessionManager->requireAnyRole([\'employee\', \'department_head\'])',
        '$this->validateCsrfToken($request)',
        '$userId = $this->sessionManager->getUserId()',
        '$request->getBodyParams()[\'id\']',
        '$this->fetchOwnedLeaveRequest($leaveId, $userId)',
        '->cancelByEmployee($leaveId, $userId',
    ]);
});

test('leave cancellation service rechecks ownership and scopes the update to the session user', function (): void {
    $service = project_path('src/Services/LeaveOperationService.php');
    $source = method_source($service, 'cancelByEmployee');

    assert_method_order($service, 'cancelByEmployee', [
        '$this->findLeave($leaveId, false)',
        '(int) $snapshot[\'user_id\'] !== $userId',
        '$this->lockLeaveRequester($userId, true)',
        '$this->findLeave($leaveId, true)',
        'WHERE id = :id AND user_id = :user_id AND status = :current_status',
        '\':user_id\' => $userId',
        '\'leave.cancelled_by_employee\'',
    ]);
    assert_contains("throw new LeaveOperationException('not_found')", $source);
});

test('admin action lookup helper rejects invalid leave IDs before transitions are considered', function (): void {
    $controller = make_leave_controller();

    assert_null(call_private($controller, 'findAdminActionLeaveRequest', [0]));
    assert_same(101, (int) call_private($controller, 'findAdminActionLeaveRequest', [101])['id']);
});

test('leave filters accept only V1 statuses and leave types', function (): void {
    $controller = make_leave_controller();

    assert_same('pending', call_private($controller, 'normalizeAllowedFilter', ['pending', ['pending', 'approved', 'rejected', 'cancelled']]));
    assert_null(call_private($controller, 'normalizeAllowedFilter', ['archived', ['pending', 'approved', 'rejected', 'cancelled']]));
    assert_same('other', call_private($controller, 'normalizeAllowedFilter', ['other', ['casual', 'medical', 'unpaid', 'other']]));
    assert_null(call_private($controller, 'normalizeAllowedFilter', ['half-day', ['casual', 'medical', 'unpaid', 'other']]));
});

test('structural guard: leave cancellation reasons are stored as localization keys, not baked-in English text', function (): void {
    $source = file_get_contents(project_path('src/Controllers/LeaveController.php'));
    assert_true(is_string($source), 'LeaveController.php should be readable.');

    assert_contains("'leave.cancel_reasons.admin'", $source);
    assert_contains("'leave.cancel_reasons.department_head'", $source);
    assert_contains("'leave.cancel_reasons.employee'", $source);
    assert_not_contains("'Cancelled by admin'", $source);
    assert_not_contains("'Cancelled by employee'", $source);
});

test('structural guard: admin leave actions use the in-app confirmation modal', function (): void {
    $source = file_get_contents(project_path('views/leave/admin_show.php'));
    assert_true(is_string($source), 'Admin leave detail view should be readable.');

    assert_contains('data-leave-confirmation-modal', $source);
    assert_contains('data-leave-confirm-form', $source);
    assert_contains('form.requestSubmit(submitter)', $source);
    assert_not_contains('confirm(', $source);
});
