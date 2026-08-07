<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Application;
use App\Core\Request;
use App\Models\Department;
use App\Services\AuditLogService;

class DepartmentController extends MasterDataController
{
    private Department $departmentModel;

    public function __construct(
        Department $departmentModel,
        \App\Core\SessionManager $sessionManager,
        Application $app,
        private ?AuditLogService $auditLog = null,
    ) {
        parent::__construct($sessionManager, $app);
        $this->departmentModel = $departmentModel;
    }


    public function index(Request $request): void
    {
        $this->ensureAdmin();

        $search = trim((string) $request->query('search', ''));

        $departments = $this->departmentModel->findAll($search);

        $this->render('masterdata/departments/index', [
            'departments' => $departments,
            'search' => $search,
            'title' => $this->t('masterdata.departments.title'),
        ]);
    }


    public function create(Request $request): void
    {
        $this->ensureAdmin();

        $this->render('masterdata/departments/form', [
            'errors' => [],
            'department' => [
                'name' => '',
                'description' => '',
            ],
            'action' => '/departments/create',
            'method' => 'POST',
            'title' => $this->t('masterdata.departments.add'),
        ]);
    }


    public function store(Request $request): void
    {
        $this->ensureAdmin();
        $this->validateCsrfToken($request);

        $body = $request->getBodyParams();

        $name = trim((string) ($body['name'] ?? ''));
        $description = trim((string) ($body['description'] ?? ''));

        $errors = $this->validate($name, $description);


        if (empty($errors) && $this->departmentModel->existsByName($name)) {
            $errors[] = $this->t('validation.department_name_exists');
        }


        if (!empty($errors)) {
            $this->render('masterdata/departments/form', [
                'errors' => $errors,
                'department' => [
                    'name' => $name,
                    'description' => $description,
                ],
                'action' => '/departments/create',
                'method' => 'POST',
                'title' => $this->t('masterdata.departments.add'),
            ]);

            return;
        }


        $this->departmentModel->create(
            $name,
            $description
        );
        $this->auditLog?->record($this->sessionManager->getUserId(), 'department.created', 'department', null, 'Department created', null,
            ['name' => $name, 'description' => $description], $this->auditContext($request), false);


        $this->sessionManager->setFlash(
            'success',
            $this->t('flash.department_created')
        );


        header('Location: /departments');
        exit;
    }


    public function edit(Request $request): void
    {
        $this->ensureAdmin();

        $id = (int) $request->query('id', 0);


        $department = $this->departmentModel->findById($id);


        if ($department === null) {

            $this->sessionManager->setFlash(
                'error',
                $this->t('flash.department_not_found')
            );

            header('Location: /departments');
            exit;
        }


        $this->render('masterdata/departments/form', [
            'errors' => [],
            'department' => $department,
            'action' => '/departments/edit?id=' . $id,
            'method' => 'POST',
            'title' => $this->t('masterdata.departments.edit'),
        ]);
    }


    public function update(Request $request): void
    {
        $this->ensureAdmin();
        $this->validateCsrfToken($request);

        $body = $request->getBodyParams();

        $id = (int) $request->query('id', 0);


        $oldDepartment = $this->departmentModel->findById($id);

        if ($oldDepartment === null) {

            $this->sessionManager->setFlash(
                'error',
                $this->t('flash.department_not_found')
            );

            header('Location: /departments');
            exit;
        }


        $name = trim((string) ($body['name'] ?? ''));
        $description = trim((string) ($body['description'] ?? ''));


        $errors = $this->validate(
            $name,
            $description
        );


        if (
            empty($errors)
            && $this->departmentModel->existsByName($name, $id)
        ) {
            $errors[] = $this->t('validation.department_name_exists');
        }


        if (!empty($errors)) {

            $this->render('masterdata/departments/form', [
                'errors' => $errors,
                'department' => [
                    'id' => $id,
                    'name' => $name,
                    'description' => $description,
                ],
                'action' => '/departments/edit?id=' . $id,
                'method' => 'POST',
                'title' => $this->t('masterdata.departments.edit'),
            ]);

            return;
        }

        $this->departmentModel->update(
            $id,
            $name,
            $description
        );
        $this->auditLog?->record($this->sessionManager->getUserId(), 'department.updated', 'department', $id, 'Department updated', $oldDepartment,
            ['name' => $name, 'description' => $description], $this->auditContext($request), false);


        $this->sessionManager->setFlash(
            'success',
            $this->t('flash.department_updated')
        );


        header('Location: /departments');
        exit;
    }


    public function delete(Request $request): void
    {
        $this->ensureAdmin();
        $this->validateCsrfToken($request);

        $id = (int) $request->query('id', 0);


        $department = $this->departmentModel->findById($id);


        if ($department === null) {

            $this->sessionManager->setFlash(
                'error',
                $this->t('flash.department_not_found')
            );

            header('Location: /departments');
            exit;
        }


        if ($this->departmentModel->countAssignedUsers($id) > 0) {

            $this->sessionManager->setFlash(
                'error',
                $this->t('flash.department_delete_assigned')
            );

            header('Location: /departments');
            exit;
        }


        $this->departmentModel->delete($id);
        $this->auditLog?->record($this->sessionManager->getUserId(), 'department.deleted', 'department', $id, 'Department removed', $department, null,
            $this->auditContext($request), false);


        $this->sessionManager->setFlash(
            'success',
            $this->t('flash.department_deleted')
        );


        header('Location: /departments');
        exit;
    }


    private function validate(
        string $name,
        string $description
    ): array {

        $errors = [];


        if ($name === '') {
            $errors[] = $this->t('validation.department_name_required');
        }


        if (mb_strlen($name) > 255) {
            $errors[] =
                $this->t('validation.department_name_too_long');
        }


        if (mb_strlen($description) > 1000) {
            $errors[] =
                $this->t('validation.description_too_long');
        }


        return $errors;
    }
}
