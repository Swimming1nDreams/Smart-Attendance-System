<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Application;
use App\Core\Controller;
use App\Core\SessionManager;
use App\Core\Request;

abstract class MasterDataController extends Controller
{
    public function __construct(SessionManager $sessionManager, Application $app)
    {
        parent::__construct($app, $sessionManager);
    }

    protected function render(string $view, array $data = []): void
    {
        $data['flash'] = $this->sessionManager->consumeFlash();
        $data['userName'] = $this->sessionManager->getUserName();
        parent::renderView($view, $data);
    }

    protected function ensureAdmin(): void
    {
        $this->sessionManager->requireRole('admin');
    }

    protected function parseOptionalInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_INT);
        return $parsed === false ? null : $parsed;
    }

    protected function auditContext(Request $request): array
    {
        return [
            'ip_address' => (string) $request->getServerParam('REMOTE_ADDR', ''),
            'user_agent' => (string) $request->getServerParam('HTTP_USER_AGENT', ''),
        ];
    }
}
