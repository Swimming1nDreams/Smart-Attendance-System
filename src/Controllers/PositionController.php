<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Application;
use App\Core\Request;
use App\Models\Position;
use App\Services\AuditLogService;

class PositionController extends MasterDataController
{
    private Position $positionModel;

    public function __construct(Position $positionModel, \App\Core\SessionManager $sessionManager, Application $app, private ?AuditLogService $auditLog = null)
    {
        parent::__construct($sessionManager, $app);
        $this->positionModel = $positionModel;
    }

    public function index(Request $request): void
    {
        $this->ensureAdmin();

        $search = trim((string) $request->query('search', ''));
        $positions = $this->positionModel->findAll($search);

        $this->render('masterdata/positions/index', [
            'positions' => $positions,
            'search' => $search,
            'title' => $this->t('masterdata.positions.title'),
        ]);
    }

    public function create(Request $request): void
    {
        $this->ensureAdmin();

        $this->render('masterdata/positions/form', [
            'errors' => [],
            'position' => ['name' => ''],
            'action' => '/positions/create',
            'method' => 'POST',
            'title' => $this->t('masterdata.positions.add'),
        ]);
    }

    public function store(Request $request): void
    {
        $this->ensureAdmin();
        $this->validateCsrfToken($request);

        $name = trim((string) ($request->getBodyParams()['name'] ?? ''));
        $errors = $this->validate($name);

        if (empty($errors) && $this->positionModel->existsByName($name)) {
            $errors[] = $this->t('validation.position_name_exists');
        }

        if (!empty($errors)) {
            $this->render('masterdata/positions/form', [
                'errors' => $errors,
                'position' => ['name' => $name],
                'action' => '/positions/create',
                'method' => 'POST',
                'title' => $this->t('masterdata.positions.add'),
            ]);
            return;
        }

        $this->positionModel->create($name);
        $this->auditLog?->record($this->sessionManager->getUserId(), 'position.created', 'position', null, 'Position created', null,
            ['name' => $name], $this->auditContext($request), false);
        $this->sessionManager->setFlash('success', $this->t('flash.position_created'));
        header('Location: /positions');
        exit;
    }

    public function edit(Request $request): void
    {
        $this->ensureAdmin();

        $id = (int) $request->query('id', 0);
        $position = $this->positionModel->findById($id);

        if ($position === null) {
            $this->sessionManager->setFlash('error', $this->t('flash.position_not_found'));
            header('Location: /positions');
            exit;
        }

        $this->render('masterdata/positions/form', [
            'errors' => [],
            'position' => $position,
            'action' => '/positions/edit?id=' . $id,
            'method' => 'POST',
            'title' => $this->t('masterdata.positions.edit'),
        ]);
    }

    public function update(Request $request): void
    {
        $this->ensureAdmin();
        $this->validateCsrfToken($request);

        $id = (int) $request->query('id', 0);
        $position = $this->positionModel->findById($id);

        if ($position === null) {
            $this->sessionManager->setFlash('error', $this->t('flash.position_not_found'));
            header('Location: /positions');
            exit;
        }

        $name = trim((string) ($request->getBodyParams()['name'] ?? ''));
        $errors = $this->validate($name);

        if (empty($errors) && $this->positionModel->existsByName($name, $id)) {
            $errors[] = $this->t('validation.position_name_exists');
        }

        if (!empty($errors)) {
            $this->render('masterdata/positions/form', [
                'errors' => $errors,
                'position' => ['id' => $id, 'name' => $name],
                'action' => '/positions/edit?id=' . $id,
                'method' => 'POST',
                'title' => $this->t('masterdata.positions.edit'),
            ]);
            return;
        }

        $this->positionModel->update($id, $name);
        $this->auditLog?->record($this->sessionManager->getUserId(), 'position.updated', 'position', $id, 'Position updated', $position,
            ['name' => $name], $this->auditContext($request), false);
        $this->sessionManager->setFlash('success', $this->t('flash.position_updated'));
        header('Location: /positions');
        exit;
    }

    public function delete(Request $request): void
    {
        $this->ensureAdmin();
        $this->validateCsrfToken($request);

        $id = (int) $request->query('id', 0);
        $position = $this->positionModel->findById($id);

        if ($position === null) {
            $this->sessionManager->setFlash('error', $this->t('flash.position_not_found'));
            header('Location: /positions');
            exit;
        }

        if ($this->positionModel->countAssignedUsers($id) > 0) {
            $this->sessionManager->setFlash('error', $this->t('flash.position_delete_assigned'));
            header('Location: /positions');
            exit;
        }

        $this->positionModel->delete($id);
        $this->auditLog?->record($this->sessionManager->getUserId(), 'position.deleted', 'position', $id, 'Position removed', $position, null,
            $this->auditContext($request), false);
        $this->sessionManager->setFlash('success', $this->t('flash.position_deleted'));
        header('Location: /positions');
        exit;
    }

    private function validate(string $name): array
    {
        $errors = [];

        if ($name === '') {
            $errors[] = $this->t('validation.position_name_required');
        }

        if (mb_strlen($name) > 255) {
            $errors[] = $this->t('validation.position_name_too_long');
        }

        return $errors;
    }
}
