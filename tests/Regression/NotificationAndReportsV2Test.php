<?php

declare(strict_types=1);

test('notification center v2 is filterable archivable and policy reminders are reconcilable', function (): void {
    $model = file_get_contents(project_path('src/Models/Notification.php'));
    $controller = file_get_contents(project_path('src/Controllers/NotificationController.php'));
    $view = file_get_contents(project_path('views/notifications/index.php'));
    $routes = file_get_contents(project_path('public/index.php'));
    $migration = file_get_contents(project_path('migrations/2026_08_21_000000_upgrade_notification_center_v2.sql'));
    $reconciler = file_get_contents(project_path('scripts/reconcile_policy_notifications.php'));

    foreach ([$model, $controller, $view, $routes, $migration, $reconciler] as $source) {
        assert_true(is_string($source));
    }

    assert_contains('paginateForUser', $model);
    assert_contains('archiveForUser', $model);
    assert_contains('cancelled_at IS NULL', $model);
    assert_contains("addRoute('POST', '/notifications/unread'", $routes);
    assert_contains("addRoute('POST', '/notifications/archive'", $routes);
    assert_contains('name="category"', $view);
    assert_contains('name="priority"', $view);
    assert_contains('name="status"', $view);
    assert_contains('category VARCHAR(32)', $migration);
    assert_contains('priority ENUM', $migration);
    assert_contains('deduplication_key', $migration);
    assert_contains('reconcileUpcomingNotifications', $reconciler);
});

test('reports v2 keeps absent records in the dedicated absence workspace', function (): void {
    $attendance = file_get_contents(project_path('src/Models/Attendance.php'));
    $employeeReport = file_get_contents(project_path('src/Services/EmployeeAttendanceReportService.php'));
    $absenceReport = file_get_contents(project_path('src/Services/AbsenceReportService.php'));
    $reportIndex = file_get_contents(project_path('views/reports/index.php'));
    $lateReport = file_get_contents(project_path('views/reports/late_absent_exceptions.php'));

    foreach ([$attendance, $employeeReport, $absenceReport, $reportIndex, $lateReport] as $source) {
        assert_true(is_string($source));
    }

    assert_true(substr_count($attendance, 'ar.status <> :excluded_absent_status') >= 3);
    assert_contains("\$departmentId, 'absent'", $employeeReport);
    assert_contains("null,\n            'absent'", $absenceReport);
    assert_contains("'absence' => '/admin/reports/absence'", $reportIndex);
    assert_not_contains('name="exception_type"', $lateReport);
    assert_contains("ar.late_minutes > 0 AND ar.status <> :exception_absent_status", $attendance);
});
