<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Application;
use App\Core\Controller;
use App\Core\SessionManager;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\Notification;
use App\Services\AttendancePolicyService;
use App\Services\AttendanceOperationService;
use App\Services\AttendanceReportContextService;
use App\Services\WorkScheduleResolverService;

class DashboardController extends Controller
{
    private Attendance $attendanceModel;
    private Employee $employeeModel;
    private Holiday $holidayModel;
    private LeaveRequest $leaveRequestModel;
    private AttendancePolicyService $attendancePolicy;

    public function __construct(
        SessionManager $sessionManager,
        Attendance $attendanceModel,
        Employee $employeeModel,
        Holiday $holidayModel,
        LeaveRequest $leaveRequestModel,
        Application $app,
        ?AttendancePolicyService $attendancePolicy = null,
        private ?AttendanceReportContextService $attendanceContext = null,
        private ?WorkScheduleResolverService $scheduleResolver = null,
        private ?AttendanceOperationService $attendanceOperations = null,
    ) {
        parent::__construct($app, $sessionManager);

        $this->attendanceModel = $attendanceModel;
        $this->employeeModel = $employeeModel;
        $this->holidayModel = $holidayModel;
        $this->leaveRequestModel = $leaveRequestModel;
        $this->attendancePolicy = $attendancePolicy ?? new AttendancePolicyService();
    }

    public function adminDashboard(): void
    {
        $this->sessionManager->requireRole('admin');

        $today = date('Y-m-d');
        $monthStart = date('Y-m-01');
        $monthEnd = date('Y-m-t');

        $activeEmployees = $this->employeeModel->countByRoleAndStatus('employee', 'active');
        $inactiveEmployees = $this->employeeModel->countByRoleAndStatus('employee', 'inactive');
        $todaySummary = $this->attendanceModel->getDailySummary($today);
        $lateArrivals = $this->attendanceModel->findLateArrivalsByDate($today, 5);
        $missingClockOuts = $this->attendanceModel->findMissingClockOutsByDate($today, 5);
        $inactiveAccounts = $this->employeeModel->findByRoleAndStatus('employee', 'inactive', 5);
        $recentAttendance = $this->attendanceModel->findRecentActivity(8);
        $holidaysThisMonth = $this->holidayModel->countInRange($monthStart, $monthEnd);
        $upcomingHolidays = $this->holidayModel->findUpcoming($today, 3);
        $attendanceTrend = $this->attendanceModel->getRecentDailyTrend($today, 7);
        $departmentAttendance = $this->attendanceModel->getDepartmentDailySummary($today, 6);
        $recentLeaveRequests = $this->leaveRequestModel->findRecentForAdminDashboard(5);
        $dashboardNotifications = $this->findDashboardNotifications(5);

        $this->renderView('dashboard/admin', [
            'name' => $this->sessionManager->getUserName(),
            'today' => $today,
            'activeEmployees' => $activeEmployees,
            'inactiveEmployees' => $inactiveEmployees,
            'todaySummary' => $todaySummary,
            'lateArrivals' => $lateArrivals,
            'missingClockOuts' => $missingClockOuts,
            'inactiveAccounts' => $inactiveAccounts,
            'recentAttendance' => $recentAttendance,
            'holidaysThisMonth' => $holidaysThisMonth,
            'upcomingHolidays' => $upcomingHolidays,
            'attendanceTrend' => $attendanceTrend,
            'departmentAttendance' => $departmentAttendance,
            'recentLeaveRequests' => $recentLeaveRequests,
            'dashboardNotifications' => $dashboardNotifications,
        ]);
    }

    public function employeeDashboard(): void
    {
        $this->sessionManager->requireRole('employee');

        $userId = $this->sessionManager->getUserId();

        if ($userId === null) {
            header('Location: /login');
            exit;
        }

        $employee = $this->employeeModel->findById($userId);

        $today = date('Y-m-d');
        $firstDayOfMonth = date('Y-m-01');

        $this->ensureActiveEmployee($userId);

        $todayStatus = $this->resolveAttendanceStatus($userId, $today);
        $hasApprovedLeaveToday = $this->leaveRequestModel->existsApprovedLeaveForDate($userId, $today);

        // Current month attendance
        $monthRecords = $this->attendanceModel->findAll(
            $userId,
            '',
            $firstDayOfMonth,
            $today,
            1,
            100
        );

        // Today's attendance
        $todayRecord = $this->attendanceModel->findByUserIdAndDate(
            $userId,
            $today
        );

        // Recent attendance history
        $recentRecords = $this->attendanceModel->findAll(
            $userId,
            '',
            null,
            null,
            1,
            7
        );
        $recentRecords = $this->withOvertimeContext($recentRecords);

        if ($todayRecord !== null) {
            $todayRecord = $this->withOvertimeContext([$todayRecord])[0];
        }

        $presentDays = 0;
        $absentDays = 0;
        $lateDays = 0;
        $totalWorkingHours = 0.0;
        $totalOvertimeMinutes = 0;

        $monthRecords = $this->withOvertimeContext($monthRecords);
        foreach ($monthRecords as $record) {
            if ($record['actual_work_minutes'] !== null) {
                $totalWorkingHours += (float) $record['working_hours'];
            }
            $totalOvertimeMinutes += (int) ($record['overtime_minutes'] ?? 0);

            if ($record['status'] === 'present') {
                $presentDays++;

                if ((int) $record['late_minutes'] > 0) {
                    $lateDays++;
                }
            } elseif ($record['status'] === 'absent') {
                $absentDays++;
            }
        }

        $totalWorkingDays = $presentDays + $absentDays;

        $attendancePercentage = $totalWorkingDays > 0
            ? round(($presentDays / $totalWorkingDays) * 100, 1)
            : 0.0;


        // Attendance action permissions

        $clockInBlockReason = $this->selfClockInBlockReason($userId, $today, $todayRecord, $hasApprovedLeaveToday);
        $canClockIn = $clockInBlockReason === null;


        $canClockOut =
            $todayRecord !== null &&
            $todayRecord['clock_in_time'] !== null &&
            $todayRecord['clock_out_time'] === null;


        $this->renderView('dashboard/employee', [

            'name' => $this->sessionManager->getUserName(),

            'employee' => $employee,

            'attendancePercentage' => $attendancePercentage,

            'presentDays' => $presentDays,

            'absentDays' => $absentDays,

            'lateDays' => $lateDays,

            'totalWorkingHours' => round($totalWorkingHours, 2),

            'totalOvertimeMinutes' => $totalOvertimeMinutes,

            'todayRecord' => $todayRecord,

            'today' => $today,

            'todayStatus' => $todayStatus,

            'canClockIn' => $canClockIn,

            'canClockOut' => $canClockOut,

            'hasApprovedLeaveToday' => $hasApprovedLeaveToday,
            'clockInBlockReason' => $clockInBlockReason,

            'recentRecords' => $recentRecords,

        ]);
    }

    private function ensureActiveEmployee(int $userId): void
    {
        $employee = $this->employeeModel->findById($userId);

        if ($employee === null || ($employee['status'] ?? '') !== 'active') {
            $this->redirectInactiveAuthenticatedUser();
        }
    }

    private function resolveAttendanceStatus(int $userId, string $attendanceDate): string
    {
        if ($this->scheduleResolver !== null) {
            try {
                $resolution = $this->scheduleResolver->resolve($userId, $attendanceDate);

                return (string) ($resolution['legacy_status'] ?? 'present');
            } catch (\Throwable) {
                // Fall back to the legacy calendar check so the dashboard still renders.
            }
        }

        if ($this->holidayModel->existsByDate($attendanceDate)) {
            return 'holiday';
        }

        return 'present';
    }

    /** @param array<string, mixed>|null $todayRecord */
    private function selfClockInBlockReason(int $userId, string $attendanceDate, ?array $todayRecord, bool $hasApprovedLeaveToday): ?string
    {
        if ($this->attendanceOperations !== null) {
            return $this->attendanceOperations->selfClockInBlockReason($userId, $attendanceDate, $todayRecord, $hasApprovedLeaveToday);
        }

        if ($todayRecord !== null && !empty($todayRecord['clock_in_time'])) {
            return 'already_clocked_in';
        }

        return $hasApprovedLeaveToday ? 'approved_leave_conflict' : null;
    }

    private function withOvertimeContext(array $records): array
    {
        if ($this->attendanceContext !== null) {
            return $this->attendanceContext->enrich($records);
        }
        foreach ($records as &$record) {
            $attendanceDate = (string) ($record['attendance_date'] ?? '');
            $userId = (int) ($record['user_id'] ?? 0);

            $record = $this->attendancePolicy->enrichRecord(
                $record,
                $attendanceDate !== '' && $this->holidayModel->existsByDate($attendanceDate),
                $userId > 0 && $attendanceDate !== '' && $this->leaveRequestModel->existsApprovedLeaveForDate($userId, $attendanceDate)
            );
        }

        unset($record);

        return $records;
    }

    private function findDashboardNotifications(int $limit): array
    {
        $userId = $this->sessionManager->getUserId();

        if ($userId === null) {
            return [];
        }

        try {
            $notificationModel = new Notification($this->app->getDatabase()->getConnection());
            return $notificationModel->findForUser($userId, $limit);
        } catch (\Throwable) {
            return [];
        }
    }


    public function accessDenied(): void
    {
        $this->sessionManager->requireAuthentication();
        $this->renderView('errors/access_denied');
    }
}
