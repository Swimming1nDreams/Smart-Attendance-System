<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Application;
use App\Core\Request;
use App\Core\SessionManager;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Services\ReportFilterService;

final class AuditLogController extends MasterDataController
{
    public function __construct(
        private AuditLog $auditLogs,
        private Employee $employees,
        private ReportFilterService $filters,
        SessionManager $sessionManager,
        Application $app,
    ) {
        parent::__construct($sessionManager, $app);
    }

    public function index(Request $request): void
    {
        $this->ensureAdmin();
        $actions = $this->auditLogs->distinctActions();
        $entityTypes = $this->auditLogs->distinctEntityTypes();
        $filters = $this->filters->auditLogs($request->getQueryParams(), $actions, $entityTypes);
        $perPage = (int) ($filters['per_page'] ?? 20);
        $total = $filters['errors'] === [] ? $this->auditLogs->countAll($filters) : 0;
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min((int) $filters['page'], $pages);
        $records = $filters['errors'] === [] ? $this->auditLogs->findAll($filters, $page, $perPage) : [];

        $this->render('audit_logs/index', [
            'title' => $this->t('audit_logs.title'), 'pageTitle' => $this->t('audit_logs.title'),
            'pageDescription' => $this->t('audit_logs.description'), 'errors' => $filters['errors'],
            'records' => $records, 'filters' => $filters, 'actions' => $actions, 'entityTypes' => $entityTypes,
            'actors' => $this->employees->findAll('', null, null, null, 1, 500),
            'total' => $total, 'page' => $page, 'perPage' => $perPage,
        ]);
    }

    public function show(Request $request): void
    {
        $this->ensureAdmin();
        $id = filter_var($request->query('id'), FILTER_VALIDATE_INT);
        $record = $id !== false && $id > 0 ? $this->auditLogs->findById((int) $id) : null;
        if ($record === null) {
            $this->sessionManager->setFlash('error', $this->t('audit_logs.not_found'));
            header('Location: /admin/audit-logs');
            exit;
        }
        $this->render('audit_logs/show', [
            'title' => $this->t('audit_logs.details_title'), 'pageTitle' => $this->t('audit_logs.details_title'),
            'pageDescription' => $this->t('audit_logs.details_description'), 'record' => $record,
            'oldValues' => $this->decodeValues($record['old_values'] ?? null),
            'newValues' => $this->decodeValues($record['new_values'] ?? null),
        ]);
    }

    private function decodeValues(mixed $value): array
    {
        if (!is_string($value) || $value === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
