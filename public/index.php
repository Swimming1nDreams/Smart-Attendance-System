<?php

declare(strict_types=1);

use App\Autoloader;
use App\Core\Application;
use App\Core\Localization;
use App\Core\Request;
use App\Core\Router;
use App\Core\SessionManager;
use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\DepartmentController;
use App\Controllers\PositionController;
use App\Controllers\HolidayController;
use App\Controllers\EmployeeController;
use App\Controllers\AttendanceController;
use App\Controllers\AnalyticsController;
use App\Controllers\LeaveController;
use App\Controllers\NotificationController;
use App\Controllers\ReportsController;
use App\Controllers\AuditLogController;
use App\Controllers\CompanyProfileController;
use App\Controllers\WorkScheduleController;
use App\Models\User;
use App\Models\Department;
use App\Models\Position;
use App\Models\Holiday;
use App\Models\Employee;
use App\Models\EmployeeProfile;
use App\Models\Attendance;
use App\Models\Notification;
use App\Models\AuditLog;
use App\Models\CompanyProfile;
use App\Models\WorkScheduleDay;
use App\Models\WorkScheduleTemplate;
use App\Services\AuthService;
use App\Services\AccountManagementService;
use App\Services\AttendanceNetworkAccessService;
use App\Services\AttendanceOperationService;
use App\Services\AttendanceCalculationService;
use App\Services\AttendancePolicyService;
use App\Services\CompanyProfileService;
use App\Services\DatabaseOperationException;
use App\Services\DateValidationService;
use App\Services\EmailVerificationMailer;
use App\Services\EmailVerificationService;
use App\Services\PasswordPolicyService;
use App\Services\ReportExcelExportService;
use App\Services\ReportPdfExportService;
use App\Services\SessionSecurityService;
use App\Services\LeaveOperationService;
use App\Services\AnalyticsService;
use App\Services\AttendanceExceptionReportService;
use App\Services\AttendanceReportContextService;
use App\Services\AttendanceSummaryReportService;
use App\Services\AuditLogService;
use App\Services\EmployeeAttendanceReportService;
use App\Services\EmployeeValidationService;
use App\Services\LeaveReportService;
use App\Services\OvertimeReviewService;
use App\Services\ReportFilterService;
use App\Services\WorkScheduleAssignmentService;
use App\Services\WorkScheduleService;
use App\Services\WorkScheduleResolverService;

if (PHP_SAPI === 'cli-server') {
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $publicRoot = realpath(__DIR__);
    $requestedFile = is_string($requestPath) ? realpath(__DIR__ . rawurldecode($requestPath)) : false;

    if (
        $requestPath !== '/'
        && $publicRoot !== false
        && $requestedFile !== false
        && str_starts_with($requestedFile, $publicRoot . DIRECTORY_SEPARATOR)
        && is_file($requestedFile)
    ) {
        return false;
    }
}

$composerAutoload = __DIR__ . '/../vendor/autoload.php';

if (is_file($composerAutoload)) {
    require $composerAutoload;
}

require __DIR__ . '/../src/Autoloader.php';

Autoloader::register();

set_exception_handler(static function (\Throwable $exception): void {
    if ($exception instanceof DatabaseOperationException) {
        $previous = $exception->getPrevious();
        error_log(sprintf(
            'Database operation failure: component=%s operation=%s exception=%s message=%s',
            $exception->component(),
            $exception->operation(),
            $previous !== null ? $previous::class : $exception::class,
            $previous !== null ? $previous->getMessage() : $exception->getMessage()
        ));
    } else {
        error_log(sprintf('Unhandled application exception: type=%s message=%s', $exception::class, $exception->getMessage()));
    }

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=UTF-8');
    }

    $message = $exception instanceof DatabaseOperationException && class_exists(Localization::class)
        ? Localization::current()->translate('validation.database_operation_failed')
        : 'SAPAS could not start safely. Please try again later or contact the administrator.';
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Service temporarily unavailable</title></head><body><h1>Service temporarily unavailable</h1><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p></body></html>';
});

$config = require __DIR__ . '/../config/config.php';

$appTimezone = (string) ($config['app']['timezone'] ?? 'Asia/Yangon');

if (!in_array($appTimezone, timezone_identifiers_list(), true)) {
    throw new InvalidArgumentException('Invalid application timezone configuration.');
}

if (!date_default_timezone_set($appTimezone)) {
    throw new RuntimeException('Unable to configure the application timezone.');
}

$app = new Application($config);
$sessionManager = new SessionManager();
$localization = new Localization(__DIR__ . '/../lang');
$localization->handleQueryLanguage($_GET);
Localization::setCurrent($localization);

$userModel = new User($app->getDatabase()->getConnection());
$sessionSecurityService = new SessionSecurityService($userModel);
$sessionManager->setAuthenticatedSessionValidator([$sessionSecurityService, 'validate']);
$authService = new AuthService($userModel, $sessionManager);
$mailConfig = $config['mail'] ?? [];
$emailVerificationMailer = new EmailVerificationMailer($mailConfig);
$emailVerificationService = new EmailVerificationService(
    $userModel,
    $emailVerificationMailer,
);

$departmentModel = new Department($app->getDatabase()->getConnection());
$positionModel = new Position($app->getDatabase()->getConnection());
$holidayModel = new Holiday($app->getDatabase()->getConnection());
$employeeModel = new Employee($app->getDatabase()->getConnection());
$employeeProfileModel = new EmployeeProfile($app->getDatabase()->getConnection());
$passwordPolicyService = new PasswordPolicyService();
$auditLogService = new AuditLogService($app->getDatabase()->getConnection());
$accountManagementService = new AccountManagementService(
    $app->getDatabase()->getConnection(),
    $employeeModel,
    $employeeProfileModel,
    $passwordPolicyService,
    $auditLogService,
);
$dateValidationService = new DateValidationService();
$employeeValidationService = new EmployeeValidationService(
    $employeeModel,
    $departmentModel,
    $positionModel,
    $passwordPolicyService,
    $dateValidationService,
);
$attendancePolicyService = new AttendancePolicyService($dateValidationService);
$attendanceModel = new Attendance($app->getDatabase()->getConnection(), $attendancePolicyService);
$leaveRequestModel = new App\Models\LeaveRequest($app->getDatabase()->getConnection());
$notificationModel = new Notification($app->getDatabase()->getConnection());
$auditLogModel = new AuditLog($app->getDatabase()->getConnection());
$workScheduleDayModel = new WorkScheduleDay($app->getDatabase()->getConnection());
$workScheduleTemplateModel = new WorkScheduleTemplate($app->getDatabase()->getConnection(), $workScheduleDayModel);
$companyProfileModel = new CompanyProfile($app->getDatabase()->getConnection());
$workScheduleService = new WorkScheduleService($app->getDatabase()->getConnection(), $workScheduleTemplateModel, $dateValidationService, $auditLogService);
$companyProfileService = new CompanyProfileService($app->getDatabase()->getConnection(), $companyProfileModel, $workScheduleTemplateModel, $auditLogService);
$workScheduleResolverService = new WorkScheduleResolverService($app->getDatabase()->getConnection());
$workScheduleAssignmentService = new WorkScheduleAssignmentService($app->getDatabase()->getConnection(), $auditLogService);
$overtimeReviewService = new OvertimeReviewService($app->getDatabase()->getConnection(), $auditLogService, $notificationModel);
$attendanceCalculationService = new AttendanceCalculationService();
$authController = new AuthController($authService, $emailVerificationService, $app, $sessionManager, $auditLogService);
$attendanceNetworkAccessService = new AttendanceNetworkAccessService($config['attendance_network'] ?? [], $auditLogService);
$attendanceOperationService = new AttendanceOperationService($app->getDatabase()->getConnection(), $attendancePolicyService, $auditLogService, $workScheduleResolverService, $attendanceCalculationService, $notificationModel);
$leaveOperationService = new LeaveOperationService($app->getDatabase()->getConnection(), $dateValidationService, $auditLogService);
$reportPdfExportService = new ReportPdfExportService($config['pdf_browser_path'] ?? null);
$reportExcelExportService = new ReportExcelExportService();
$reportFilterService = new ReportFilterService($dateValidationService, $employeeModel, $departmentModel);
$attendanceReportContextService = new AttendanceReportContextService(
    $holidayModel,
    $leaveRequestModel,
    $attendancePolicyService,
    $workScheduleResolverService,
    $attendanceCalculationService,
);
$attendanceSummaryReportService = new AttendanceSummaryReportService($attendanceModel, $attendanceReportContextService, $attendancePolicyService);
$employeeAttendanceReportService = new EmployeeAttendanceReportService($attendanceModel, $leaveRequestModel, $attendanceReportContextService);
$leaveReportService = new LeaveReportService($leaveRequestModel);
$attendanceExceptionReportService = new AttendanceExceptionReportService($attendanceModel, $attendanceReportContextService);
$analyticsService = new AnalyticsService($employeeModel, $attendanceModel, $holidayModel, $leaveRequestModel, $attendancePolicyService, $workScheduleResolverService);

$dashboardController = new DashboardController($sessionManager, $attendanceModel, $employeeModel, $holidayModel, $leaveRequestModel, $app, $attendancePolicyService, $attendanceReportContextService, $workScheduleResolverService, $attendanceOperationService);
$departmentController = new DepartmentController($departmentModel, $sessionManager, $app, $auditLogService);
$positionController = new PositionController($positionModel, $sessionManager, $app, $auditLogService);
$holidayController = new HolidayController($holidayModel, $sessionManager, $app, $dateValidationService, $auditLogService);
$employeeController = new EmployeeController($employeeModel, $employeeProfileModel, $departmentModel, $positionModel, $emailVerificationService, $accountManagementService, $employeeValidationService, $passwordPolicyService, $sessionManager, $app, $workScheduleResolverService, $auditLogService);
$attendanceController = new AttendanceController($attendanceModel, $holidayModel, $employeeModel, $leaveRequestModel, $sessionManager, $app, $dateValidationService, $attendancePolicyService, $attendanceOperationService, $attendanceReportContextService, $attendanceNetworkAccessService);
$analyticsController = new AnalyticsController($analyticsService, $reportFilterService, $departmentModel, $sessionManager, $app);
$leaveController = new LeaveController($leaveRequestModel, $holidayModel, $employeeModel, $sessionManager, $app, $notificationModel, $dateValidationService, $leaveOperationService, $workScheduleResolverService);
$notificationController = new NotificationController($notificationModel, $employeeModel, $sessionManager, $app);
$reportsController = new ReportsController($reportFilterService, $attendanceSummaryReportService, $employeeAttendanceReportService, $leaveReportService, $attendanceExceptionReportService, $employeeModel, $departmentModel, $reportPdfExportService, $reportExcelExportService, $sessionManager, $app, $auditLogService);
$auditLogController = new AuditLogController($auditLogModel, $employeeModel, $reportFilterService, $sessionManager, $app);
$companyProfileController = new CompanyProfileController($companyProfileModel, $workScheduleTemplateModel, $companyProfileService, $sessionManager, $app);
$workScheduleController = new WorkScheduleController($workScheduleTemplateModel, $workScheduleService, $companyProfileModel, $workScheduleAssignmentService, $overtimeReviewService, $departmentModel, $employeeModel, $sessionManager, $app);

$router = new Router();
$request = new Request($_SERVER, $_GET, $_POST);

$router->addRoute('GET', '/', static function (Request $request) use ($authController): void {
    header('Location: /login');
    exit;
});

$router->addRoute('GET', '/login', static function (Request $request) use ($authController): void {
    $authController->showLogin($request);
});

$router->addRoute('POST', '/login', static function (Request $request) use ($authController): void {
    $authController->login($request);
});

$router->addRoute('POST', '/logout', static function (Request $request) use ($authController): void {
    $authController->logout($request);
});

$router->addRoute('POST', '/email-verification/verify', static function (Request $request) use ($authController): void {
    $authController->verifyEmail($request);
});

$router->addRoute('POST', '/email-verification/resend', static function (Request $request) use ($authController): void {
    $authController->resendVerification($request);
});

$router->addRoute('GET', '/admin/dashboard', static function (Request $request) use ($dashboardController): void {
    $dashboardController->adminDashboard();
});

$router->addRoute('GET', '/employee/dashboard', static function (Request $request) use ($dashboardController): void {
    $dashboardController->employeeDashboard();
});

$router->addRoute('GET', '/access-denied', static function (Request $request) use ($dashboardController): void {
    $dashboardController->accessDenied();
});

$router->addRoute('GET', '/notifications', static function (Request $request) use ($notificationController): void {
    $notificationController->index($request);
});

$router->addRoute('POST', '/notifications/read', static function (Request $request) use ($notificationController): void {
    $notificationController->markRead($request);
});

$router->addRoute('POST', '/notifications/read-all', static function (Request $request) use ($notificationController): void {
    $notificationController->markAllRead($request);
});

$router->addRoute('GET', '/admin/analytics', static function (Request $request) use ($analyticsController): void {
    $analyticsController->index($request);
});

$router->addRoute('GET', '/admin/reports', static function (Request $request) use ($reportsController): void {
    $reportsController->index($request);
});

$router->addRoute('GET', '/admin/reports/attendance-summary', static function (Request $request) use ($reportsController): void {
    $reportsController->attendanceSummary($request);
});

$router->addRoute('GET', '/admin/reports/attendance-summary/pdf', static function (Request $request) use ($reportsController): void {
    $reportsController->attendanceSummaryPdf($request);
});

$router->addRoute('GET', '/admin/reports/attendance-summary/excel', static function (Request $request) use ($reportsController): void {
    $reportsController->attendanceSummaryExcel($request);
});

$router->addRoute('GET', '/admin/reports/employee-attendance-detail', static function (Request $request) use ($reportsController): void {
    $reportsController->employeeAttendanceDetail($request);
});

$router->addRoute('GET', '/admin/reports/employee-attendance-detail/pdf', static function (Request $request) use ($reportsController): void {
    $reportsController->employeeAttendanceDetailPdf($request);
});

$router->addRoute('GET', '/admin/reports/employee-attendance-detail/excel', static function (Request $request) use ($reportsController): void {
    $reportsController->employeeAttendanceDetailExcel($request);
});

$router->addRoute('GET', '/admin/reports/leave-requests', static function (Request $request) use ($reportsController): void {
    $reportsController->leaveRequests($request);
});

$router->addRoute('GET', '/admin/reports/leave-requests/pdf', static function (Request $request) use ($reportsController): void {
    $reportsController->leaveRequestsPdf($request);
});

$router->addRoute('GET', '/admin/reports/leave-requests/excel', static function (Request $request) use ($reportsController): void {
    $reportsController->leaveRequestsExcel($request);
});

$router->addRoute('GET', '/admin/reports/late-absent-exceptions', static function (Request $request) use ($reportsController): void {
    $reportsController->lateAbsentExceptions($request);
});

$router->addRoute('GET', '/admin/reports/late-absent-exceptions/pdf', static function (Request $request) use ($reportsController): void {
    $reportsController->lateAbsentExceptionsPdf($request);
});

$router->addRoute('GET', '/admin/reports/late-absent-exceptions/excel', static function (Request $request) use ($reportsController): void {
    $reportsController->lateAbsentExceptionsExcel($request);
});

$router->addRoute('GET', '/admin/audit-logs', static function (Request $request) use ($auditLogController): void {
    $auditLogController->index($request);
});

$router->addRoute('GET', '/admin/audit-logs/show', static function (Request $request) use ($auditLogController): void {
    $auditLogController->show($request);
});

$router->addRoute('GET', '/admin/company-profile', static function (Request $request) use ($companyProfileController): void {
    $companyProfileController->show($request);
});

$router->addRoute('GET', '/admin/company-profile/edit', static function (Request $request) use ($companyProfileController): void {
    $companyProfileController->edit($request);
});

$router->addRoute('POST', '/admin/company-profile/update', static function (Request $request) use ($companyProfileController): void {
    $companyProfileController->update($request);
});

$router->addRoute('GET', '/admin/work-schedules', static function (Request $request) use ($workScheduleController): void {
    $workScheduleController->index($request);
});

$router->addRoute('GET', '/admin/work-schedules/create', static function (Request $request) use ($workScheduleController): void {
    $workScheduleController->create($request);
});

$router->addRoute('POST', '/admin/work-schedules', static function (Request $request) use ($workScheduleController): void {
    $workScheduleController->store($request);
});

$router->addRoute('GET', '/admin/work-schedules/edit', static function (Request $request) use ($workScheduleController): void {
    $workScheduleController->edit($request);
});

$router->addRoute('POST', '/admin/work-schedules/update', static function (Request $request) use ($workScheduleController): void {
    $workScheduleController->update($request);
});

$router->addRoute('POST', '/admin/work-schedules/toggle-status', static function (Request $request) use ($workScheduleController): void {
    $workScheduleController->toggleStatus($request);
});

$router->addRoute('POST', '/admin/work-schedules/clone', static function (Request $request) use ($workScheduleController): void {
    $workScheduleController->clonePolicy($request);
});

$router->addRoute('POST', '/admin/work-schedules/update-weekly-schedule', static function (Request $request) use ($workScheduleController): void {
    $workScheduleController->updateWeeklySchedule($request);
});

$router->addRoute('POST', '/admin/work-schedules/update-attendance-rules', static function (Request $request) use ($workScheduleController): void {
    $workScheduleController->updateAttendanceRules($request);
});

$router->addRoute('POST', '/admin/work-schedules/update-overtime-rules', static function (Request $request) use ($workScheduleController): void {
    $workScheduleController->updateOvertimeRules($request);
});

$router->addRoute('POST', '/admin/work-schedules/schedule-version', static function (Request $request) use ($workScheduleController): void {
    $workScheduleController->scheduleVersion($request);
});

$router->addRoute('POST', '/admin/work-schedules/cancel-schedule', static function (Request $request) use ($workScheduleController): void {
    $workScheduleController->cancelSchedule($request);
});

$router->addRoute('POST', '/admin/work-schedules/archive-version', static function (Request $request) use ($workScheduleController): void {
    $workScheduleController->archiveVersion($request);
});

$router->addRoute('GET', '/admin/work-schedules/assignments', static function (Request $request) use ($workScheduleController): void {
    $workScheduleController->assignments($request);
});

$router->addRoute('GET', '/admin/work-schedules/assignments/create', static function (Request $request) use ($workScheduleController): void {
    $workScheduleController->createAssignment($request);
});

$router->addRoute('POST', '/admin/work-schedules/assignments', static function (Request $request) use ($workScheduleController): void {
    $workScheduleController->storeAssignment($request);
});

$router->addRoute('GET', '/admin/work-schedules/assignments/edit', static function (Request $request) use ($workScheduleController): void {
    $workScheduleController->editAssignment($request);
});

$router->addRoute('POST', '/admin/work-schedules/assignments/update', static function (Request $request) use ($workScheduleController): void {
    $workScheduleController->updateAssignment($request);
});

$router->addRoute('POST', '/admin/work-schedules/assignments/end', static function (Request $request) use ($workScheduleController): void {
    $workScheduleController->endAssignment($request);
});

$router->addRoute('POST', '/admin/work-schedules/assignments/remove-employee-override', static function (Request $request) use ($workScheduleController): void {
    $workScheduleController->removeEmployeeOverride($request);
});

$router->addRoute('GET', '/admin/overtime', static function (Request $request) use ($workScheduleController): void {
    $workScheduleController->overtime($request);
});

$router->addRoute('GET', '/admin/overtime/show', static function (Request $request) use ($workScheduleController): void {
    $workScheduleController->showOvertime($request);
});

$router->addRoute('POST', '/admin/overtime/approve', static function (Request $request) use ($workScheduleController): void {
    $workScheduleController->approveOvertime($request);
});

$router->addRoute('POST', '/admin/overtime/reject', static function (Request $request) use ($workScheduleController): void {
    $workScheduleController->rejectOvertime($request);
});

$router->addRoute('POST', '/admin/overtime/adjust', static function (Request $request) use ($workScheduleController): void {
    $workScheduleController->adjustOvertime($request);
});

$router->addRoute('POST', '/admin/overtime/reset-pending', static function (Request $request) use ($workScheduleController): void {
    $workScheduleController->resetOvertimePending($request);
});

$router->addRoute('GET', '/departments', static function (Request $request) use ($departmentController): void {
    $departmentController->index($request);
});

$router->addRoute('GET', '/departments/create', static function (Request $request) use ($departmentController): void {
    $departmentController->create($request);
});

$router->addRoute('POST', '/departments/create', static function (Request $request) use ($departmentController): void {
    $departmentController->store($request);
});

$router->addRoute('GET', '/departments/edit', static function (Request $request) use ($departmentController): void {
    $departmentController->edit($request);
});

$router->addRoute('POST', '/departments/edit', static function (Request $request) use ($departmentController): void {
    $departmentController->update($request);
});

$router->addRoute('POST', '/departments/delete', static function (Request $request) use ($departmentController): void {
    $departmentController->delete($request);
});

$router->addRoute('GET', '/positions', static function (Request $request) use ($positionController): void {
    $positionController->index($request);
});

$router->addRoute('GET', '/positions/create', static function (Request $request) use ($positionController): void {
    $positionController->create($request);
});

$router->addRoute('POST', '/positions/create', static function (Request $request) use ($positionController): void {
    $positionController->store($request);
});

$router->addRoute('GET', '/positions/edit', static function (Request $request) use ($positionController): void {
    $positionController->edit($request);
});

$router->addRoute('POST', '/positions/edit', static function (Request $request) use ($positionController): void {
    $positionController->update($request);
});

$router->addRoute('POST', '/positions/delete', static function (Request $request) use ($positionController): void {
    $positionController->delete($request);
});

$router->addRoute('GET', '/holidays', static function (Request $request) use ($holidayController): void {
    $holidayController->index($request);
});

$router->addRoute('GET', '/holidays/create', static function (Request $request) use ($holidayController): void {
    $holidayController->create($request);
});

$router->addRoute('POST', '/holidays/create', static function (Request $request) use ($holidayController): void {
    $holidayController->store($request);
});

$router->addRoute('GET', '/holidays/edit', static function (Request $request) use ($holidayController): void {
    $holidayController->edit($request);
});

$router->addRoute('POST', '/holidays/edit', static function (Request $request) use ($holidayController): void {
    $holidayController->update($request);
});

$router->addRoute('POST', '/holidays/delete', static function (Request $request) use ($holidayController): void {
    $holidayController->delete($request);
});

$router->addRoute('GET', '/employees', static function (Request $request) use ($employeeController): void {
    $employeeController->index($request);
});

$router->addRoute('GET', '/employees/create', static function (Request $request) use ($employeeController): void {
    $employeeController->create($request);
});

$router->addRoute('POST', '/employees/create', static function (Request $request) use ($employeeController): void {
    $employeeController->store($request);
});

$router->addRoute('GET', '/employees/show', static function (Request $request) use ($employeeController): void {
    $employeeController->show($request);
});

$router->addRoute('GET', '/employees/edit', static function (Request $request) use ($employeeController): void {
    $employeeController->edit($request);
});

$router->addRoute('POST', '/employees/edit', static function (Request $request) use ($employeeController): void {
    $employeeController->update($request);
});

$router->addRoute('POST', '/employees/deactivate', static function (Request $request) use ($employeeController): void {
    $employeeController->deactivate($request);
});

$router->addRoute('POST', '/employees/reset-password', static function (Request $request) use ($employeeController): void {
    $employeeController->resetPassword($request);
});

$router->addRoute('POST', '/employees/resend-verification', static function (Request $request) use ($employeeController): void {
    $employeeController->resendVerification($request);
});

$router->addRoute('GET', '/attendance', static function (Request $request) use ($attendanceController): void {
    $attendanceController->index($request);
});

$router->addRoute('POST', '/attendance/clock-in', static function (Request $request) use ($attendanceController): void {
    $attendanceController->clockIn($request);
});

$router->addRoute('POST', '/attendance/clock-out', static function (Request $request) use ($attendanceController): void {
    $attendanceController->clockOut($request);
});

$router->addRoute('GET', '/attendance/create', static function (Request $request) use ($attendanceController): void {
    $attendanceController->create($request);
});

$router->addRoute('POST', '/attendance/create', static function (Request $request) use ($attendanceController): void {
    $attendanceController->store($request);
});

$router->addRoute('GET', '/admin/leave', static function (Request $request) use ($leaveController): void {
    $leaveController->adminIndex($request);
});

$router->addRoute('GET', '/admin/leave/show', static function (Request $request) use ($leaveController): void {
    $leaveController->adminShow($request);
});

$router->addRoute('POST', '/admin/leave/approve', static function (Request $request) use ($leaveController): void {
    $leaveController->adminApprove($request);
});

$router->addRoute('POST', '/admin/leave/reject', static function (Request $request) use ($leaveController): void {
    $leaveController->adminReject($request);
});

$router->addRoute('POST', '/admin/leave/cancel', static function (Request $request) use ($leaveController): void {
    $leaveController->adminCancel($request);
});

$router->addRoute('GET', '/leave', static function (Request $request) use ($leaveController): void {
    $leaveController->index($request);
});

$router->addRoute('GET', '/leave/create', static function (Request $request) use ($leaveController): void {
    $leaveController->create($request);
});

$router->addRoute('GET', '/leave/show', static function (Request $request) use ($leaveController): void {
    $leaveController->show($request);
});

$router->addRoute('POST', '/leave/cancel', static function (Request $request) use ($leaveController): void {
    $leaveController->cancel($request);
});

$router->addRoute('POST', '/leave/create', static function (Request $request) use ($leaveController): void {
    $leaveController->store($request);
});

$router->addRoute('GET', '/leave/attachment/download', static function (Request $request) use ($leaveController): void {
    $leaveController->downloadAttachment($request);
});

$router->addRoute('GET', '/attendance/edit', static function (Request $request) use ($attendanceController): void {
    $attendanceController->edit($request);
});

$router->addRoute('POST', '/attendance/edit', static function (Request $request) use ($attendanceController): void {
    $attendanceController->update($request);
});

$router->addRoute('POST', '/attendance/delete', static function (Request $request) use ($attendanceController): void {
    $attendanceController->delete($request);
});

$router->dispatch($request);
