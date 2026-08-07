<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Application;
use App\Core\Request;
use App\Core\SessionManager;
use App\Models\Department;
use App\Services\AnalyticsService;
use App\Services\ReportFilterService;

final class AnalyticsController extends MasterDataController
{
    public function __construct(
        private AnalyticsService $analytics,
        private ReportFilterService $filters,
        private Department $departments,
        SessionManager $sessionManager,
        Application $app,
    ) {
        parent::__construct($sessionManager, $app);
    }

    public function index(Request $request): void
    {
        $this->ensureAdmin();
        $filters = $this->filters->analytics($request->getQueryParams());
        $result = $this->analytics->generate($filters);

        $this->render('analytics/index', [
            'title' => $this->t('analytics_module.title'),
            'month' => $filters['month'],
            'departments' => $this->departments->findAll(''),
            'filterEmployees' => $result['filter_employees'],
            'selectedDepartmentId' => $filters['department_id'] ?? null,
            'selectedEmployeeId' => $filters['employee_id'] ?? null,
            'selectedEmployeeName' => $result['selected_employee_name'],
            'firstDayOfMonth' => $result['first_day'],
            'lastDayOfMonth' => $result['last_day'],
            'employeeAnalytics' => $result['employee_analytics'],
            'summary' => $result['summary'],
            'errors' => $filters['errors'],
        ]);
    }
}
