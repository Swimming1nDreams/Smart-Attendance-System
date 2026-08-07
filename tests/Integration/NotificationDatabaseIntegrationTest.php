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
