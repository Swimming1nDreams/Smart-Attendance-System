<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Application;
use App\Core\Request;
use App\Core\SessionManager;
use App\Models\Holiday;
use App\Services\DateValidationService;
use App\Services\AuditLogService;

class HolidayController extends MasterDataController
{
    private Holiday $holidayModel;
    private DateValidationService $dateValidator;

    public function __construct(Holiday $holidayModel, SessionManager $sessionManager, Application $app, ?DateValidationService $dateValidator = null, private ?AuditLogService $auditLog = null)
    {
        parent::__construct($sessionManager, $app);
        $this->holidayModel = $holidayModel;
        $this->dateValidator = $dateValidator ?? new DateValidationService();
    }

    public function index(Request $request): void
    {
        $this->sessionManager->requireAuthentication();

        $isAdmin = $this->sessionManager->getUserRole() === 'admin';
        $search = $isAdmin ? trim((string) $request->query('search', '')) : '';
        $holidays = $this->holidayModel->findAll($search);

        $this->render('masterdata/holidays/index', [
            'holidays' => $holidays,
            'search' => $search,
            'title' => $this->t('masterdata.holidays.title'),
        ]);
    }

    public function create(Request $request): void
    {
        $this->ensureAdmin();

        $this->render('masterdata/holidays/form', [
            'errors' => [],
            'holiday' => ['holiday_name' => '', 'holiday_date' => '', 'description' => ''],
            'action' => '/holidays/create',
            'method' => 'POST',
            'title' => $this->t('masterdata.holidays.add'),
        ]);
    }

    public function store(Request $request): void
    {
        $this->ensureAdmin();
        $this->validateCsrfToken($request);

        $name = trim((string) ($request->getBodyParams()['holiday_name'] ?? ''));
        $date = trim((string) ($request->getBodyParams()['holiday_date'] ?? ''));
        $description = trim((string) ($request->getBodyParams()['description'] ?? ''));
        $errors = $this->validate($name, $date, $description);

        if (empty($errors) && $this->holidayModel->existsByDate($date)) {
            $errors[] = $this->t('validation.holiday_date_exists');
        }

        if (!empty($errors)) {
            $this->render('masterdata/holidays/form', [
                'errors' => $errors,
                'holiday' => ['holiday_name' => $name, 'holiday_date' => $date, 'description' => $description],
                'action' => '/holidays/create',
                'method' => 'POST',
                'title' => $this->t('masterdata.holidays.add'),
            ]);
            return;
        }

        $this->holidayModel->create($name, $date, $description);
        $this->auditLog?->record($this->sessionManager->getUserId(), 'holiday.created', 'holiday', null, 'Holiday created', null,
            ['holiday_name' => $name, 'holiday_date' => $date, 'description' => $description], $this->auditContext($request), false);
        $this->sessionManager->setFlash('success', $this->t('flash.holiday_created'));
        header('Location: /holidays');
        exit;
    }

    public function edit(Request $request): void
    {
        $this->ensureAdmin();

        $id = (int) $request->query('id', 0);
        $holiday = $this->holidayModel->findById($id);

        if ($holiday === null) {
            $this->sessionManager->setFlash('error', $this->t('flash.holiday_not_found'));
            header('Location: /holidays');
            exit;
        }

        $this->render('masterdata/holidays/form', [
            'errors' => [],
            'holiday' => $holiday,
            'action' => '/holidays/edit?id=' . $id,
            'method' => 'POST',
            'title' => $this->t('masterdata.holidays.edit'),
        ]);
    }

    public function update(Request $request): void
    {
        $this->ensureAdmin();
        $this->validateCsrfToken($request);

        $id = (int) $request->query('id', 0);
        $holiday = $this->holidayModel->findById($id);

        if ($holiday === null) {
            $this->sessionManager->setFlash('error', $this->t('flash.holiday_not_found'));
            header('Location: /holidays');
            exit;
        }

        $name = trim((string) ($request->getBodyParams()['holiday_name'] ?? ''));
        $date = trim((string) ($request->getBodyParams()['holiday_date'] ?? ''));
        $description = trim((string) ($request->getBodyParams()['description'] ?? ''));
        $errors = $this->validate($name, $date, $description);

        if (empty($errors) && $this->holidayModel->existsByDate($date, $id)) {
            $errors[] = $this->t('validation.holiday_date_exists');
        }

        if (!empty($errors)) {
            $this->render('masterdata/holidays/form', [
                'errors' => $errors,
                'holiday' => ['id' => $id, 'holiday_name' => $name, 'holiday_date' => $date, 'description' => $description],
                'action' => '/holidays/edit?id=' . $id,
                'method' => 'POST',
                'title' => $this->t('masterdata.holidays.edit'),
            ]);
            return;
        }

        $this->holidayModel->update($id, $name, $date, $description);
        $this->auditLog?->record($this->sessionManager->getUserId(), 'holiday.updated', 'holiday', $id, 'Holiday updated', $holiday,
            ['holiday_name' => $name, 'holiday_date' => $date, 'description' => $description], $this->auditContext($request), false);
        $this->sessionManager->setFlash('success', $this->t('flash.holiday_updated'));
        header('Location: /holidays');
        exit;
    }

    public function delete(Request $request): void
    {
        $this->ensureAdmin();
        $this->validateCsrfToken($request);

        $id = (int) $request->query('id', 0);
        $holiday = $this->holidayModel->findById($id);

        if ($holiday === null) {
            $this->sessionManager->setFlash('error', $this->t('flash.holiday_not_found'));
            header('Location: /holidays');
            exit;
        }

        $this->holidayModel->delete($id);
        $this->auditLog?->record($this->sessionManager->getUserId(), 'holiday.deleted', 'holiday', $id, 'Holiday removed', $holiday, null,
            $this->auditContext($request), false);
        $this->sessionManager->setFlash('success', $this->t('flash.holiday_deleted'));
        header('Location: /holidays');
        exit;
    }

    private function validate(string $name, string $date, string $description): array
    {
        $errors = [];

        if ($name === '') {
            $errors[] = $this->t('validation.holiday_name_required');
        }

        if ($date === '') {
            $errors[] = $this->t('validation.holiday_date_required');
        }

        if ($date !== '' && !$this->dateValidator->isValidDate($date)) {
            $errors[] = $this->t('validation.holiday_date_invalid_format');
        }

        if (mb_strlen($name) > 255) {
            $errors[] = $this->t('validation.holiday_name_too_long');
        }

        if (mb_strlen($description) > 1000) {
            $errors[] = $this->t('validation.description_too_long');
        }

        return $errors;
    }
}
