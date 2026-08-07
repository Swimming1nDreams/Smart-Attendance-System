<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Application;
use App\Core\Request;
use App\Core\SessionManager;
use App\Models\CompanyProfile;
use App\Models\WorkScheduleTemplate;
use App\Services\CompanyProfileService;

final class CompanyProfileController extends MasterDataController
{
    public function __construct(
        private CompanyProfile $profiles,
        private WorkScheduleTemplate $schedules,
        private CompanyProfileService $service,
        SessionManager $sessionManager,
        Application $app,
    ) {
        parent::__construct($sessionManager, $app);
    }

    public function show(Request $request): void
    {
        $this->ensureAdmin();

        header('Location: /admin/work-schedules#organization-information');
        exit;
    }

    public function edit(Request $request): void
    {
        $this->ensureAdmin();
        $profile = $this->profiles->findSingle();

        if ($profile === null) {
            $this->sessionManager->setFlash('error', $this->t('company_profile.validation.missing_profile'));
            header('Location: /admin/company-profile');
            exit;
        }

        $this->render('company_profile/form', [
            'errors' => [],
            'profile' => $profile,
            'scheduleOptions' => $this->schedules->findActiveOptions(),
            'dateFormats' => CompanyProfileService::DATE_FORMATS,
            'timeFormats' => CompanyProfileService::TIME_FORMATS,
            'action' => '/admin/company-profile/update',
            'title' => $this->t('company_profile.edit_title'),
        ]);
    }

    public function update(Request $request): void
    {
        $this->ensureAdmin();
        $this->validateCsrfToken($request);

        $result = $this->service->update($request->getBodyParams(), $this->sessionManager->getUserId(), $this->auditContext($request));
        if (!$result['ok']) {
            $this->render('company_profile/form', [
                'errors' => $result['errors'],
                'profile' => $result['data'],
                'scheduleOptions' => $this->schedules->findActiveOptions(),
                'dateFormats' => CompanyProfileService::DATE_FORMATS,
                'timeFormats' => CompanyProfileService::TIME_FORMATS,
                'action' => '/admin/company-profile/update',
                'title' => $this->t('company_profile.edit_title'),
            ]);
            return;
        }

        $this->sessionManager->setFlash('success', $this->t('flash.company_profile_updated'));
        header('Location: /admin/company-profile');
        exit;
    }
}
