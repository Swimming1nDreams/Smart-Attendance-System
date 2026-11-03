<?php

declare(strict_types=1);

use App\Models\Notification;

test('database integration: notification read state is user-scoped and idempotent', function (): void {
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $notifications = new Notification($database->pdo());

    $notificationId = $notifications->create(
        $ids['employee_id'],
        'leave_approved',
        'notifications.leave_approved_title',
        'notifications.leave_approved_message',
        '/leave/show?id=1'
    );

    assert_same(1, $notifications->countUnreadForUser($ids['employee_id']));
    assert_false($notifications->markAsRead($notificationId, $ids['other_employee_id']));
    assert_true($notifications->markAsRead($notificationId, $ids['employee_id']));
    assert_true($notifications->markAsRead($notificationId, $ids['employee_id']));
    assert_same(0, $notifications->countUnreadForUser($ids['employee_id']));
});

test('database integration: opening notification details can clear all unread items for only that user', function (): void {
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $notifications = new Notification($database->pdo());

    foreach (['leave_approved', 'leave_rejected'] as $event) {
        $notifications->create(
            $ids['employee_id'],
            $event,
            'notifications.' . $event . '_title',
            'notifications.' . $event . '_message',
            '/leave'
        );
    }

    $notifications->create(
        $ids['other_employee_id'],
        'leave_approved',
        'notifications.leave_approved_title',
        'notifications.leave_approved_message',
        '/leave'
    );

    assert_same(2, $notifications->countUnreadForUser($ids['employee_id']));
    assert_same(1, $notifications->countUnreadForUser($ids['other_employee_id']));
    assert_true($notifications->markAllAsReadForUser($ids['employee_id']));
    assert_same(0, $notifications->countUnreadForUser($ids['employee_id']));
    assert_same(1, $notifications->countUnreadForUser($ids['other_employee_id']));
});

test('database integration: scheduled notifications stay hidden until available', function (): void {
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $notifications = new Notification($database->pdo());

    $futureId = $notifications->create(
        $ids['employee_id'],
        'work_policy_assignment_upcoming',
        'notifications.events.work_policy_assignment_upcoming.title',
        'notifications.events.work_policy_assignment_upcoming.message',
        '/my-work-policy',
        null,
        null,
        null,
        '2099-01-01 00:00:00',
    );

    assert_true($futureId > 0);
    assert_same(0, $notifications->countUnreadForUser($ids['employee_id']));
    assert_same([], $notifications->findForUser($ids['employee_id']));
    assert_false($notifications->markAsRead($futureId, $ids['employee_id']));
});

test('database integration: notification center filters priorities and archives within recipient ownership', function (): void {
    $database = integration_database();
    $ids = $database->resetAndSeed();
    $notifications = new Notification($database->pdo());

    $policyId = $notifications->create(
        $ids['employee_id'],
        'work_policy_changed',
        'notifications.events.work_policy_assignment_upcoming.title',
        'notifications.events.work_policy_assignment_upcoming.message',
        '/my-work-policy',
        null,
        null,
        null,
        null,
        'policy',
        'high',
        ['effective_date' => '2026-09-01'],
        'policy-test-employee',
    );
    $notifications->create(
        $ids['employee_id'],
        'leave_approved',
        'notifications.events.leave_approved.title',
        'notifications.events.leave_approved.message',
        '/leave',
        null,
        null,
        null,
        null,
        'leave',
    );

    $policy = $notifications->paginateForUser($ids['employee_id'], ['category' => 'policy', 'priority' => 'high']);
    assert_same(1, $policy['total']);
    assert_same($policyId, (int) $policy['records'][0]['id']);
    assert_false($notifications->archiveForUser($policyId, $ids['other_employee_id']));
    assert_true($notifications->archiveForUser($policyId, $ids['employee_id']));
    assert_same(1, $notifications->countUnreadForUser($ids['employee_id']));
    assert_same(1, $notifications->paginateForUser($ids['employee_id'], ['status' => 'archived'])['total']);
});
