<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\Notification;
use App\Models\EmployeeProfile;
use App\Services\ProfilePhotoStorageService;

abstract class Controller
{
    protected Application $app;
    protected SessionManager $sessionManager;

    public function __construct(Application $app, SessionManager $sessionManager)
    {
        $this->app = $app;
        $this->sessionManager = $sessionManager;
    }

    protected function renderView(string $view, array $data = []): void
    {
        $viewFile = __DIR__ . '/../../views/' . $view . '.php';

        if (!is_file($viewFile)) {
            throw new \RuntimeException(sprintf('View file not found: %s', $viewFile));
        }

        if (!array_key_exists('csrf_token', $data)) {
            $data['csrf_token'] = $this->sessionManager->generateCsrfToken();
        }

        if (!array_key_exists('flash', $data)) {
            $data['flash'] = $this->sessionManager->consumeFlash();
        }

        if (!array_key_exists('userName', $data)) {
            $data['userName'] = $this->sessionManager->getUserName();
        }

        if (!array_key_exists('userRole', $data)) {
            $data['userRole'] = $this->sessionManager->getUserRole();
        }

        if (!array_key_exists('userId', $data)) {
            $data['userId'] = $this->sessionManager->getUserId();
        }

        if (!array_key_exists('currentPath', $data)) {
            $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
            $data['currentPath'] = parse_url($requestUri, PHP_URL_PATH) ?: '/';
        }

        if (!array_key_exists('topbarAvatarUrl', $data)) {
            $data['topbarAvatarUrl'] = $this->resolveTopbarAvatarUrl(
                is_string($data['userRole'] ?? null) ? $data['userRole'] : null,
                is_int($data['userId'] ?? null) ? $data['userId'] : null,
            );
        }

        $localization = Localization::current();

        $data['locale'] ??= $localization->locale();
        $data['htmlLang'] ??= $localization->htmlLang();
        $data['languageOptions'] ??= $localization->languageOptions();
        $data['languageSwitchUrl'] ??= static fn (string $locale): string => $localization->switchUrl($locale);
        $data['t'] ??= static fn (string $key): string => $localization->translate($key);
        $data['unreadNotificationCount'] ??= $this->resolveUnreadNotificationCount();

        extract($data, EXTR_SKIP);
        $layout = $layout ?? null;

        ob_start();
        require $viewFile;
        $content = ob_get_clean();

        if ($layout === null || $layout === '') {
            echo $content;
            return;
        }

        $layoutFile = __DIR__ . '/../../views/' . trim($layout, '/') . '.php';

        if (!is_file($layoutFile)) {
            throw new \RuntimeException(sprintf('Layout file not found: %s', $layoutFile));
        }

        require $layoutFile;
    }

    protected function validateCsrfToken(Request $request): void
    {
        $token = $request->input('csrf_token');

        if (!$this->sessionManager->validateCsrfToken($token)) {
            $this->sessionManager->setFlash('error', $this->t('flash.csrf_invalid'));
            header('Location: ' . $this->getSafeCsrfFailureRedirect());
            exit;
        }
    }

    protected function redirectInactiveAuthenticatedUser(): void
    {
        $this->sessionManager->invalidateAuthenticatedSession();
        $this->sessionManager->setFlash('error', $this->t('flash.account_inactive'));
        header('Location: /login');
        exit;
    }

    protected function t(string $key): string
    {
        return Localization::current()->translate($key);
    }

    private function getSafeCsrfFailureRedirect(): string
    {
        if (!$this->sessionManager->isAuthenticated()) {
            return '/login';
        }

        return match ($this->sessionManager->getUserRole()) {
            'admin' => '/admin/dashboard',
            'department_head' => '/department-head/dashboard',
            default => '/employee/dashboard',
        };
    }

    private function resolveUnreadNotificationCount(): int
    {
        $userId = $this->sessionManager->getUserId();

        if ($userId === null) {
            return 0;
        }

        try {
            $notificationModel = new Notification($this->app->getDatabase()->getConnection());
            return $notificationModel->countUnreadForUser($userId);
        } catch (\Throwable) {
            return 0;
        }
    }

    protected function resolveTopbarAvatarUrl(?string $role, ?int $userId): ?string
    {
        if (!in_array($role, ['admin', 'department_head', 'employee'], true) || $userId === null || $userId <= 0) {
            return null;
        }

        try {
            $profileModel = new EmployeeProfile($this->app->getDatabase()->getConnection());
            $profile = $profileModel->findByUserId($userId);
            $filename = trim((string) ($profile['profile_photo_filename'] ?? ''));

            if ($filename === '' || (new ProfilePhotoStorageService())->resolve($filename) === null) {
                return null;
            }

            $version = rawurlencode((string) ($profile['updated_at'] ?? ''));
            return '/profile/photo' . ($version !== '' ? '?v=' . $version : '');
        } catch (\Throwable) {
            return null;
        }
    }
}
