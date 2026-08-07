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

        $this->renderView('notifications/index', [
            'notifications' => $this->notificationModel->findForUser($userId, 50),
            'unreadNotificationCount' => $this->notificationModel->countUnreadForUser($userId),
            'title' => $this->t('notifications.title'),
            'pageTitle' => $this->t('notifications.title'),
            'pageDescription' => $this->t('notifications.description'),
        ]);
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
