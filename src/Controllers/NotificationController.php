<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Application;
use App\Core\Controller;
use App\Core\Request;
use App\Core\SessionManager;
use App\Models\Employee;
use App\Models\Notification;

class NotificationController extends Controller
{
    private Notification $notificationModel;
    private Employee $employeeModel;

    public function __construct(Notification $notificationModel, Employee $employeeModel, SessionManager $sessionManager, Application $app)
    {
        parent::__construct($app, $sessionManager);
        $this->notificationModel = $notificationModel;
        $this->employeeModel = $employeeModel;
    }

    public function index(Request $request): void
    {
        $this->sessionManager->requireAuthentication();
        $this->ensureActiveEmployee();

        $userId = $this->sessionManager->getUserId();

        if ($userId === null) {
            header('Location: /login');
            exit;
        }

        $page = max(1, (int) $request->query('page', 1));
        $perPage = (int) $request->query('per_page', 20);
        $result = $this->notificationModel->paginateForUser($userId, [
            'category' => (string) $request->query('category', ''),
            'priority' => (string) $request->query('priority', ''),
            'status' => (string) $request->query('status', ''),
        ], $page, $perPage);

        $this->renderView('notifications/index', [
            'notifications' => $result['records'],
            'total' => $result['total'],
            'page' => $result['page'],
            'perPage' => $result['per_page'],
            'filters' => $result['filters'],
            'unreadNotificationCount' => $this->notificationModel->countUnreadForUser($userId),
            'title' => $this->t('notifications.title'),
            'pageTitle' => $this->t('notifications.title'),
            'pageDescription' => $this->t('notifications.description'),
        ]);
    }

    public function markUnread(Request $request): void
    {
        $this->sessionManager->requireAuthentication();
        $this->ensureActiveEmployee();
        $this->validateCsrfToken($request);
        $userId = $this->sessionManager->getUserId();
        $notificationId = max(0, (int) $request->input('id', 0));

        if ($userId === null || $notificationId <= 0 || !$this->notificationModel->markAsUnread($notificationId, $userId)) {
            $this->sessionManager->setFlash('error', $this->t('flash.notification_not_found'));
        } else {
            $this->sessionManager->setFlash('success', $this->t('notifications.marked_unread'));
        }
        header('Location: /notifications');
        exit;
    }

    public function archive(Request $request): void
    {
        $this->sessionManager->requireAuthentication();
        $this->ensureActiveEmployee();
        $this->validateCsrfToken($request);
        $userId = $this->sessionManager->getUserId();
        $notificationId = max(0, (int) $request->input('id', 0));

        if ($userId === null || $notificationId <= 0 || !$this->notificationModel->archiveForUser($notificationId, $userId)) {
            $this->sessionManager->setFlash('error', $this->t('flash.notification_not_found'));
        } else {
            $this->sessionManager->setFlash('success', $this->t('notifications.archived_success'));
        }
        header('Location: /notifications');
        exit;
    }

    public function markRead(Request $request): void
    {
        $this->sessionManager->requireAuthentication();
        $this->ensureActiveEmployee();
        $this->validateCsrfToken($request);

        $userId = $this->sessionManager->getUserId();

        if ($userId === null) {
            header('Location: /login');
            exit;
        }

        $notificationId = max(0, (int) $request->input('id', 0));

        if ($notificationId <= 0 || !$this->notificationModel->markAsRead($notificationId, $userId)) {
            $this->sessionManager->setFlash('error', $this->t('flash.notification_not_found'));
            header('Location: /notifications');
            exit;
        }

        $this->sessionManager->setFlash('success', $this->t('flash.notification_marked_read'));
        header('Location: /notifications');
        exit;
    }

    public function markAllRead(Request $request): void
    {
        $this->sessionManager->requireAuthentication();
        $this->ensureActiveEmployee();
        $this->validateCsrfToken($request);

        $userId = $this->sessionManager->getUserId();

        if ($userId === null) {
            header('Location: /login');
            exit;
        }

        $this->notificationModel->markAllAsReadForUser($userId);
        $this->sessionManager->setFlash('success', $this->t('flash.notifications_marked_read'));
        header('Location: /notifications');
        exit;
    }

    private function ensureActiveEmployee(): void
    {
        if ($this->sessionManager->getUserRole() !== 'employee') {
            return;
        }

        $userId = $this->sessionManager->getUserId();

        if ($userId === null) {
            header('Location: /login');
            exit;
        }

        $employee = $this->employeeModel->findById($userId);

        if ($employee === null || ($employee['role'] ?? '') !== 'employee' || ($employee['status'] ?? '') !== 'active') {
            $this->redirectInactiveAuthenticatedUser();
        }
    }
}
