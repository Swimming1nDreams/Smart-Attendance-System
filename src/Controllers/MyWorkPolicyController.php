<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Application;
use App\Core\Controller;
use App\Core\SessionManager;
use App\Models\Employee;
use App\Models\WorkScheduleTemplate;
use App\Services\EmployeeWorkPolicyPresenter;
use App\Services\WorkScheduleResolverService;

final class MyWorkPolicyController extends Controller
{
    public function __construct(
        private Employee $employeeModel,
        private WorkScheduleTemplate $workScheduleTemplateModel,
        private WorkScheduleResolverService $workScheduleResolver,
        private EmployeeWorkPolicyPresenter $presenter,
        SessionManager $sessionManager,
        Application $app,
    ) {
        parent::__construct($app, $sessionManager);
    }

    public function index(): void
    {
        $this->sessionManager->requireAnyRole(['employee', 'department_head']);

        $employeeId = $this->sessionManager->getUserId();
        if ($employeeId === null) {
            header('Location: /login');
            exit;
        }

        $employee = $this->employeeModel->findById($employeeId);
        if (
            $employee === null
            || !in_array(($employee['role'] ?? ''), ['employee', 'department_head'], true)
            || ($employee['status'] ?? '') !== 'active'
        ) {
            $this->redirectInactiveAuthenticatedUser();
        }

        $policy = $this->presenter->unavailable();
        $businessDate = date('Y-m-d');

        try {
            $resolution = $this->workScheduleResolver->resolve($employeeId, $businessDate);
            $template = $this->workScheduleTemplateModel->findWithDays((int) ($resolution['policy_version_id'] ?? 0));

            if ($template !== null) {
                $policy = $this->presenter->present(
                    $resolution,
                    $template,
                    $employee,
                    fn (string $key): string => $this->t($key),
                );
            }
        } catch (\Throwable) {
            // Render the localized employee-safe empty state without exposing resolver or database details.
        }

        $this->renderView('work_policy/my_work_policy', [
            'title' => $this->t('work_policy.title'),
            'policy' => $policy,
        ]);
    }
}
