<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Application;
use App\Core\Request;
use App\Core\SessionManager;
use App\Models\Department;
use App\Models\Employee;
use App\Services\AttendanceExceptionReportService;
use App\Services\AttendanceSummaryReportService;
use App\Services\AuditLogService;
use App\Services\EmployeeAttendanceReportService;
use App\Services\LeaveReportService;
use App\Services\ReportExcelExportService;
use App\Services\ReportFilterService;
use App\Services\ReportPdfExportService;

final class ReportsController extends MasterDataController
{
    private const REPORT_TYPES = [
        'attendance-summary' => ['title_key' => 'reports_module.attendance_summary', 'description_key' => 'reports_module.attendance_summary_card_description'],
        'employee-attendance-detail' => ['title_key' => 'reports_module.employee_attendance_detail', 'description_key' => 'reports_module.employee_attendance_detail_card_description'],
        'leave-request' => ['title_key' => 'reports_module.leave_request', 'description_key' => 'reports_module.leave_request_card_description'],
        'late-absent-exception' => ['title_key' => 'reports_module.late_absent_exception', 'description_key' => 'reports_module.late_absent_exception_card_description'],
    ];

    public function __construct(
        private ReportFilterService $filters,
        private AttendanceSummaryReportService $attendanceSummaryReports,
        private EmployeeAttendanceReportService $employeeAttendanceReports,
        private LeaveReportService $leaveReports,
        private AttendanceExceptionReportService $exceptionReports,
        private Employee $employees,
        private Department $departments,
        private ReportPdfExportService $pdf,
        private ReportExcelExportService $excel,
        SessionManager $sessionManager,
        Application $app,
        private ?AuditLogService $audit = null,
    ) {
        parent::__construct($sessionManager, $app);
    }

    public function index(Request $request): void
    {
        $this->ensureAdmin();
        $selected = trim((string) $request->query('type', ''));
        if (!isset(self::REPORT_TYPES[$selected])) {
            $selected = '';
        }
        $types = [];
        foreach (self::REPORT_TYPES as $key => $definition) {
            $types[$key] = ['title' => $this->t($definition['title_key']), 'description' => $this->t($definition['description_key'])];
        }
        $this->render('reports/index', [
            'title' => $this->t('reports_module.title'), 'pageTitle' => $this->t('reports_module.title'),
            'pageDescription' => $this->t('reports_module.description'), 'reportTypes' => $types, 'selectedType' => $selected,
        ]);
    }

    public function attendanceSummary(Request $request): void
    {
        $this->ensureAdmin();
        $filters = $this->filters->attendanceSummary($request->getQueryParams());
        $result = $this->attendanceSummaryReports->generate($filters, (int) ($filters['per_page'] ?? 20));
        $this->render('reports/attendance_summary', [
            'title' => $this->t('reports_module.attendance_summary'), 'pageTitle' => $this->t('reports_module.attendance_summary'),
            'pageDescription' => $this->t('reports_module.attendance_summary_description'), 'errors' => $filters['errors'],
            'records' => $result['records'], 'totals' => $result['totals'], 'totalRecords' => $result['total_records'],
            'page' => $result['page'], 'perPage' => $result['per_page'], 'departments' => $this->departments->findAll(''),
            'employees' => $this->employees->findReportEmployees(), 'filters' => $this->viewFilters($filters),
        ]);
    }

    public function employeeAttendanceDetail(Request $request): void
    {
        $this->ensureAdmin();
        $filters = $this->filters->employeeAttendance($request->getQueryParams());
        $result = $this->employeeAttendanceReports->generate($filters, (int) ($filters['per_page'] ?? 20));
        $this->render('reports/employee_attendance_detail', [
            'title' => $this->t('reports_module.employee_attendance_detail'), 'pageTitle' => $this->t('reports_module.employee_attendance_detail'),
            'pageDescription' => $this->t('reports_module.employee_attendance_detail_description'), 'errors' => $filters['errors'],
            'records' => $result['records'], 'approvedLeaveRequests' => $result['approved_leave_requests'],
            'approvedLeaveByDate' => $result['approved_leave_by_date'], 'selectedEmployee' => $filters['employee'] ?? null,
            'employees' => $this->employees->findReportEmployees(), 'totalRecords' => $result['total_records'],
            'page' => $result['page'], 'perPage' => $result['per_page'], 'filters' => $this->viewFilters($filters),
        ]);
    }

    public function leaveRequests(Request $request): void
    {
        $this->ensureAdmin();
        $filters = $this->filters->leaveRequests($request->getQueryParams());
        $result = $this->leaveReports->generate($filters, (int) ($filters['per_page'] ?? 20));
        $this->render('reports/leave_requests', [
            'title' => $this->t('reports_module.leave_request'), 'pageTitle' => $this->t('reports_module.leave_request'),
            'pageDescription' => $this->t('reports_module.leave_request_description'), 'errors' => $filters['errors'],
            'leaveRequests' => $result['records'], 'totalRequests' => $result['total_records'], 'page' => $result['page'],
            'perPage' => $result['per_page'], 'employees' => $this->employees->findReportEmployees(), 'filters' => $this->viewFilters($filters),
        ]);
    }

    public function lateAbsentExceptions(Request $request): void
    {
        $this->ensureAdmin();
        $filters = $this->filters->attendanceExceptions($request->getQueryParams());
        $result = $this->exceptionReports->generate($filters, (int) ($filters['per_page'] ?? 20));
        $this->render('reports/late_absent_exceptions', [
            'title' => $this->t('reports_module.late_absent_exception'), 'pageTitle' => $this->t('reports_module.late_absent_exception'),
            'pageDescription' => $this->t('reports_module.late_absent_exception_description'), 'errors' => $filters['errors'],
            'records' => $result['records'], 'totalRecords' => $result['total_records'], 'page' => $result['page'],
            'perPage' => $result['per_page'], 'departments' => $this->departments->findAll(''),
            'employees' => $this->employees->findReportEmployees(), 'filters' => $this->viewFilters($filters),
        ]);
    }

    public function attendanceSummaryPdf(Request $request): void
    {
        $this->ensureAdmin();
        $filters = $this->filters->attendanceSummary($request->getQueryParams());
        $this->ensurePdfFilters($filters, '/admin/reports/attendance-summary', $request);
        $result = $this->attendanceSummaryReports->generate($filters, 20, true);
        if ($result['total_records'] === 0) {
            $this->redirectBack('/admin/reports/attendance-summary', $request, $this->t('reports.pdf_empty_attendance'));
        }
        $totals = $result['totals'];
        $sections = [
            ['title' => $this->t('reports.totals'), 'headers' => [$this->t('reports.metric'), $this->t('reports.value')], 'rows' => [
                [$this->t('reports.total_records'), (string) $totals['total_records']],
                [$this->t('reports.total_attended_including_late'), (string) $totals['present_count']],
                [$this->t('statuses.attendance.late'), (string) $totals['late_count']],
                [$this->t('statuses.attendance.absent'), (string) $totals['absent_count']],
                [$this->t('reports.total_work_duration'), (string) $totals['total_work_duration']],
                [$this->t('common.labels.overtime'), $this->formatMinutes((int) $totals['total_overtime_minutes'])],
            ]],
            ['title' => $this->t('reports.attendance_records'),
             'headers' => [$this->t('employees_module.staff_code'), $this->t('common.labels.date'), $this->t('common.labels.employee'), $this->t('common.labels.email'), $this->t('common.labels.department'), $this->t('common.labels.status'), $this->t('common.labels.clock_in'), $this->t('common.labels.clock_out'), $this->t('attendance.late_minutes'), $this->t('common.labels.hours'), $this->t('common.labels.overtime')],
             'rows' => array_map(fn (array $row): array => [
                 (string) ($row['staff_code'] ?? ''), (string) ($row['attendance_date'] ?? ''), (string) ($row['employee_name'] ?? ''),
                 (string) ($row['employee_email'] ?? ''), (string) ($row['department_name'] ?? $this->t('reports_module.no_department')),
                 $this->attendanceStatus((string) ($row['attendance_display_status'] ?? $row['status'] ?? '')),
                 (string) ($row['clock_in_time'] ?? '—'), (string) ($row['clock_out_time'] ?? '—'), (string) ((int) ($row['late_minutes'] ?? 0)),
                 (string) ($row['working_duration'] ?? '—'), (string) ($row['overtime_duration'] ?? '—'),
             ], $result['records'])],
        ];
        $this->streamAndAudit($request, 'attendance_summary', $filters, $this->t('reports_module.attendance_summary'),
            'sapas_attendance_summary_' . $this->filters->dateRangeFilename($filters) . '_' . date('Ymd_His') . '.pdf', $sections);
    }

    public function employeeAttendanceDetailPdf(Request $request): void
    {
        $this->ensureAdmin();
        $filters = $this->filters->employeeAttendance($request->getQueryParams());
        $this->ensurePdfFilters($filters, '/admin/reports/employee-attendance-detail', $request);
        $result = $this->employeeAttendanceReports->generate($filters, 20, true);
        if ($result['records'] === [] && $result['approved_leave_requests'] === []) {
            $this->redirectBack('/admin/reports/employee-attendance-detail', $request, $this->t('reports.pdf_empty_employee_detail'));
        }
        $sections = [];
        if ($result['records'] !== []) {
            $sections[] = ['title' => $this->t('reports.attendance_records'),
                'headers' => [$this->t('employees_module.staff_code'), $this->t('common.labels.date'), $this->t('common.labels.status'), $this->t('common.labels.clock_in'), $this->t('common.labels.clock_out'), $this->t('attendance.late_minutes'), $this->t('common.labels.hours'), $this->t('common.labels.overtime'), $this->t('common.labels.approved_leave')],
                'rows' => array_map(function (array $row) use ($result, $filters): array {
                    $date = (string) ($row['attendance_date'] ?? '');
                    $leaves = $result['approved_leave_by_date'][$date] ?? [];
                    return [(string) ($filters['employee']['staff_code'] ?? $row['staff_code'] ?? ''), $date,
                        $this->attendanceStatus((string) ($row['attendance_display_status'] ?? $row['status'] ?? '')),
                        (string) ($row['clock_in_time'] ?? '—'), (string) ($row['clock_out_time'] ?? '—'),
                        (string) ((int) ($row['late_minutes'] ?? 0)), (string) ($row['working_duration'] ?? '—'),
                        (string) ($row['overtime_duration'] ?? '—'), $leaves === [] ? $this->t('common.none') : implode(', ', array_map(fn (array $leave): string => $this->leaveType((string) $leave['leave_type']), $leaves))];
                }, $result['records'])];
        }
        if ($result['approved_leave_requests'] !== []) {
            $sections[] = ['title' => $this->t('reports.approved_leave_context'),
                'headers' => [$this->t('common.labels.type'), $this->t('common.labels.start_date'), $this->t('common.labels.end_date'), $this->t('common.labels.duration')],
                'rows' => array_map(fn (array $leave): array => [$this->leaveType((string) $leave['leave_type']), (string) $leave['start_date'], (string) $leave['end_date'], (int) $leave['duration_days'] . ' ' . $this->t('common.units.days')], $result['approved_leave_requests'])];
        }
        $name = (string) ($filters['employee']['name'] ?? 'employee');
        $this->streamAndAudit($request, 'employee_attendance_detail', $filters, $this->t('reports_module.employee_attendance_detail'),
            'sapas_employee_attendance_detail_' . $this->filters->filenamePart($name) . '_' . $this->filters->dateRangeFilename($filters) . '_' . date('Ymd_His') . '.pdf', $sections);
    }

    public function leaveRequestsPdf(Request $request): void
    {
        $this->ensureAdmin();
        $filters = $this->filters->leaveRequests($request->getQueryParams());
        $this->ensurePdfFilters($filters, '/admin/reports/leave-requests', $request);
        $result = $this->leaveReports->generate($filters, 20, true);
        if ($result['total_records'] === 0) {
            $this->redirectBack('/admin/reports/leave-requests', $request, $this->t('reports.pdf_empty_leave'));
        }
        $sections = [['title' => $this->t('reports.leave_requests'),
            'headers' => [$this->t('employees_module.staff_code'), $this->t('common.labels.employee'), $this->t('common.labels.email'), $this->t('common.labels.type'), $this->t('common.labels.start_date'), $this->t('common.labels.end_date'), $this->t('leave_module.days'), $this->t('common.labels.status'), $this->t('common.labels.submitted'), $this->t('common.labels.decision_cancel_note')],
            'rows' => array_map(fn (array $row): array => [(string) ($row['staff_code'] ?? ''), (string) ($row['employee_name'] ?? ''), (string) ($row['employee_email'] ?? ''),
                $this->leaveType((string) ($row['leave_type'] ?? '')), (string) ($row['start_date'] ?? ''), (string) ($row['end_date'] ?? ''),
                (string) ((int) ($row['duration_days'] ?? 0)), $this->leaveStatus((string) ($row['status'] ?? '')), (string) ($row['created_at'] ?? ''), $this->notePreview($row)], $result['records'])]];
        $this->streamAndAudit($request, 'leave_requests', $filters, $this->t('reports_module.leave_request'),
            'sapas_leave_requests_' . $this->filters->dateRangeFilename($filters) . '_' . date('Ymd_His') . '.pdf', $sections);
    }

    public function lateAbsentExceptionsPdf(Request $request): void
    {
        $this->ensureAdmin();
        $filters = $this->filters->attendanceExceptions($request->getQueryParams());
        $this->ensurePdfFilters($filters, '/admin/reports/late-absent-exceptions', $request);
        $result = $this->exceptionReports->generate($filters, 20, true);
        if ($result['total_records'] === 0) {
            $this->redirectBack('/admin/reports/late-absent-exceptions', $request, $this->t('reports.pdf_empty_exceptions'));
        }
        $sections = [['title' => $this->t('reports.late_absent_exceptions'),
            'headers' => [$this->t('employees_module.staff_code'), $this->t('common.labels.employee'), $this->t('common.labels.email'), $this->t('common.labels.department'), $this->t('common.labels.date'), $this->t('common.labels.exception'), $this->t('common.labels.status'), $this->t('common.labels.clock_in'), $this->t('common.labels.clock_out'), $this->t('attendance.late_minutes'), $this->t('common.labels.approved_leave')],
            'rows' => array_map(fn (array $row): array => [(string) ($row['staff_code'] ?? ''), (string) ($row['employee_name'] ?? ''), (string) ($row['employee_email'] ?? ''),
                (string) ($row['department_name'] ?? $this->t('reports_module.no_department')), (string) ($row['attendance_date'] ?? ''),
                ($row['status'] ?? '') === 'absent' ? $this->t('statuses.exception.absent') : $this->t('statuses.exception.late'),
                $this->attendanceStatus((string) ($row['attendance_display_status'] ?? $row['status'] ?? '')),
                (string) ($row['clock_in_time'] ?? '—'), (string) ($row['clock_out_time'] ?? '—'), (string) ((int) ($row['late_minutes'] ?? 0)),
                !empty($row['has_approved_leave']) ? $this->t('reports_module.approved_leave_on_date') : $this->t('common.none')], $result['records'])]];
        $this->streamAndAudit($request, 'attendance_exceptions', $filters, $this->t('reports_module.late_absent_exception'),
            'sapas_late_absent_exceptions_' . $this->filters->dateRangeFilename($filters) . '_' . date('Ymd_His') . '.pdf', $sections);
    }

    public function attendanceSummaryExcel(Request $request): void
    {
        $this->ensureAdmin();
        $filters = $this->filters->attendanceSummary($request->getQueryParams());
        $this->ensureExcelFilters($filters, '/admin/reports/attendance-summary', $request);
        $result = $this->attendanceSummaryReports->generate($filters, 20, true);
        if ($result['total_records'] === 0) {
            $this->redirectBack('/admin/reports/attendance-summary', $request, $this->t('reports.excel_empty_attendance'));
        }

        $totals = $result['totals'];
        $summaryRows = [
            [$this->t('reports.total_records'), $this->excelCell((int) $totals['total_records'], 'integer')],
            [$this->t('reports.total_attended_including_late'), $this->excelCell((int) $totals['present_count'], 'integer')],
            [$this->t('statuses.attendance.late'), $this->excelCell((int) $totals['late_count'], 'integer')],
            [$this->t('statuses.attendance.absent'), $this->excelCell((int) $totals['absent_count'], 'integer')],
            [$this->t('reports.total_work_duration'), $this->excelCell((int) $totals['total_work_minutes'], 'duration')],
            [$this->t('common.labels.overtime'), $this->excelCell((int) $totals['total_overtime_minutes'], 'duration')],
        ];
        $recordRows = array_map(fn (array $row): array => [
            (string) ($row['staff_code'] ?? ''),
            $this->excelCell((string) ($row['attendance_date'] ?? ''), 'date'),
            (string) ($row['employee_name'] ?? ''),
            (string) ($row['employee_email'] ?? ''),
            (string) ($row['department_name'] ?? $this->t('reports_module.no_department')),
            $this->excelAttendanceStatusCell((string) ($row['attendance_display_status'] ?? $row['status'] ?? '')),
            $this->excelCell((string) ($row['clock_in_time'] ?? '—'), 'time'),
            $this->excelCell((string) ($row['clock_out_time'] ?? '—'), 'time'),
            $this->excelCell((int) ($row['late_minutes'] ?? 0), 'integer'),
            $this->excelCell((int) ($row['actual_work_minutes'] ?? 0), 'duration'),
            $this->excelCell((int) ($row['overtime_minutes'] ?? 0), 'duration'),
        ], $result['records']);

        $sheets = [
            $this->excelSheet(
                $this->t('reports.sheet_summary'),
                $this->t('reports_module.attendance_summary'),
                [
                    $this->excelColumn($this->t('reports.metric'), 34),
                    $this->excelColumn($this->t('reports.value'), 38),
                ],
                $summaryRows,
                'summary',
            ),
            $this->excelSheet(
                $this->t('reports.sheet_attendance_records'),
                $this->t('reports.attendance_records'),
                [
                    $this->excelColumn($this->t('employees_module.staff_code'), 14),
                    $this->excelColumn($this->t('common.labels.date'), 13),
                    $this->excelColumn($this->t('common.labels.employee'), 24),
                    $this->excelColumn($this->t('common.labels.email'), 32),
                    $this->excelColumn($this->t('common.labels.department'), 22),
                    $this->excelColumn($this->t('common.labels.status'), 16),
                    $this->excelColumn($this->t('common.labels.clock_in'), 12),
                    $this->excelColumn($this->t('common.labels.clock_out'), 12),
                    $this->excelColumn($this->t('attendance.late_minutes'), 12),
                    $this->excelColumn($this->t('common.labels.hours'), 16),
                    $this->excelColumn($this->t('common.labels.overtime'), 16),
                ],
                $recordRows,
            ),
        ];

        $this->streamExcelAndAudit(
            $request,
            'attendance_summary',
            $filters,
            'sapas_attendance_summary_' . $this->filters->dateRangeFilename($filters) . '_' . date('Ymd_His') . '.xlsx',
            $sheets,
        );
    }

    public function employeeAttendanceDetailExcel(Request $request): void
    {
        $this->ensureAdmin();
        $filters = $this->filters->employeeAttendance($request->getQueryParams());
        $this->ensureExcelFilters($filters, '/admin/reports/employee-attendance-detail', $request);
        $result = $this->employeeAttendanceReports->generate($filters, 20, true);
        if ($result['records'] === [] && $result['approved_leave_requests'] === []) {
            $this->redirectBack('/admin/reports/employee-attendance-detail', $request, $this->t('reports.excel_empty_employee_detail'));
        }

        $employee = $filters['employee'] ?? [];
        $summaryRows = [
            [$this->t('common.labels.employee'), (string) ($employee['name'] ?? '')],
            [$this->t('employees_module.staff_code'), (string) ($employee['staff_code'] ?? '')],
            [$this->t('common.labels.email'), (string) ($employee['email'] ?? '')],
            [$this->t('common.labels.department'), (string) ($employee['department_name'] ?? $this->t('reports_module.no_department'))],
            [$this->t('reports_module.date_range'), $this->dateRangeText($filters)],
            [$this->t('reports.attendance_records'), $this->excelCell((int) $result['total_records'], 'integer')],
            [$this->t('reports.approved_leave_context'), $this->excelCell(count($result['approved_leave_requests']), 'integer')],
        ];
        $attendanceRows = array_map(function (array $row) use ($result, $employee): array {
            $date = (string) ($row['attendance_date'] ?? '');
            $leaves = $result['approved_leave_by_date'][$date] ?? [];
            $leaveText = $leaves === []
                ? $this->t('common.none')
                : implode(', ', array_map(fn (array $leave): string => $this->leaveType((string) ($leave['leave_type'] ?? '')), $leaves));

            return [
                (string) ($employee['staff_code'] ?? $row['staff_code'] ?? ''),
                $this->excelCell($date, 'date'),
                $this->excelAttendanceStatusCell((string) ($row['attendance_display_status'] ?? $row['status'] ?? '')),
                $this->excelCell((string) ($row['clock_in_time'] ?? '—'), 'time'),
                $this->excelCell((string) ($row['clock_out_time'] ?? '—'), 'time'),
                $this->excelCell((int) ($row['late_minutes'] ?? 0), 'integer'),
                $this->excelCell((int) ($row['actual_work_minutes'] ?? 0), 'duration'),
                $this->excelCell((int) ($row['overtime_minutes'] ?? 0), 'duration'),
                $this->excelCell($leaveText, 'wrap'),
            ];
        }, $result['records']);
        $leaveRows = array_map(fn (array $leave): array => [
            $this->leaveType((string) ($leave['leave_type'] ?? '')),
            $this->excelCell((string) ($leave['start_date'] ?? ''), 'date'),
            $this->excelCell((string) ($leave['end_date'] ?? ''), 'date'),
            $this->excelCell((int) ($leave['duration_days'] ?? 0), 'integer'),
        ], $result['approved_leave_requests']);

        $sheets = [
            $this->excelSheet(
                $this->t('reports.sheet_summary'),
                $this->t('reports_module.employee_attendance_detail'),
                [
                    $this->excelColumn($this->t('reports.metric'), 34),
                    $this->excelColumn($this->t('reports.value'), 34),
                ],
                $summaryRows,
                'summary',
            ),
            $this->excelSheet(
                $this->t('reports.sheet_attendance_records'),
                $this->t('reports.attendance_records'),
                [
                    $this->excelColumn($this->t('employees_module.staff_code'), 14),
                    $this->excelColumn($this->t('common.labels.date'), 13),
                    $this->excelColumn($this->t('common.labels.status'), 16),
                    $this->excelColumn($this->t('common.labels.clock_in'), 12),
                    $this->excelColumn($this->t('common.labels.clock_out'), 12),
                    $this->excelColumn($this->t('attendance.late_minutes'), 12),
                    $this->excelColumn($this->t('common.labels.hours'), 16),
                    $this->excelColumn($this->t('common.labels.overtime'), 16),
                    $this->excelColumn($this->t('common.labels.approved_leave'), 28),
                ],
                $attendanceRows,
            ),
            $this->excelSheet(
                $this->t('reports.sheet_approved_leave'),
                $this->t('reports.approved_leave_context'),
                [
                    $this->excelColumn($this->t('common.labels.type'), 20),
                    $this->excelColumn($this->t('common.labels.start_date'), 14),
                    $this->excelColumn($this->t('common.labels.end_date'), 14),
                    $this->excelColumn($this->t('common.labels.duration'), 14),
                ],
                $leaveRows,
            ),
        ];

        $name = (string) ($employee['name'] ?? 'employee');
        $this->streamExcelAndAudit(
            $request,
            'employee_attendance_detail',
            $filters,
            'sapas_employee_attendance_detail_' . $this->filters->filenamePart($name) . '_' . $this->filters->dateRangeFilename($filters) . '_' . date('Ymd_His') . '.xlsx',
            $sheets,
        );
    }

    public function leaveRequestsExcel(Request $request): void
    {
        $this->ensureAdmin();
        $filters = $this->filters->leaveRequests($request->getQueryParams());
        $this->ensureExcelFilters($filters, '/admin/reports/leave-requests', $request);
        $result = $this->leaveReports->generate($filters, 20, true);
        if ($result['total_records'] === 0) {
            $this->redirectBack('/admin/reports/leave-requests', $request, $this->t('reports.excel_empty_leave'));
        }

        $summaryRows = [
            [$this->t('reports.total_records'), $this->excelCell((int) $result['total_records'], 'integer')],
        ];
        $requestRows = array_map(fn (array $row): array => [
            (string) ($row['staff_code'] ?? ''),
            (string) ($row['employee_name'] ?? ''),
            (string) ($row['employee_email'] ?? ''),
            $this->leaveType((string) ($row['leave_type'] ?? '')),
            $this->excelCell((string) ($row['start_date'] ?? ''), 'date'),
            $this->excelCell((string) ($row['end_date'] ?? ''), 'date'),
            $this->excelCell((int) ($row['duration_days'] ?? 0), 'integer'),
            $this->excelLeaveStatusCell((string) ($row['status'] ?? '')),
            $this->excelCell((string) ($row['created_at'] ?? ''), 'datetime'),
            $this->excelCell($this->notePreview($row), 'wrap'),
        ], $result['records']);

        $sheets = [
            $this->excelSheet(
                $this->t('reports.sheet_summary'),
                $this->t('reports_module.leave_request'),
                [
                    $this->excelColumn($this->t('reports.metric'), 34),
                    $this->excelColumn($this->t('reports.value'), 38),
                ],
                $summaryRows,
                'summary',
            ),
            $this->excelSheet(
                $this->t('reports.sheet_leave_requests'),
                $this->t('reports.leave_requests'),
                [
                    $this->excelColumn($this->t('employees_module.staff_code'), 14),
                    $this->excelColumn($this->t('common.labels.employee'), 24),
                    $this->excelColumn($this->t('common.labels.email'), 32),
                    $this->excelColumn($this->t('common.labels.type'), 20),
                    $this->excelColumn($this->t('common.labels.start_date'), 14),
                    $this->excelColumn($this->t('common.labels.end_date'), 14),
                    $this->excelColumn($this->t('leave_module.days'), 10),
                    $this->excelColumn($this->t('common.labels.status'), 15),
                    $this->excelColumn($this->t('common.labels.submitted'), 20),
                    $this->excelColumn($this->t('common.labels.decision_cancel_note'), 36),
                ],
                $requestRows,
            ),
        ];

        $this->streamExcelAndAudit(
            $request,
            'leave_requests',
            $filters,
            'sapas_leave_requests_' . $this->filters->dateRangeFilename($filters) . '_' . date('Ymd_His') . '.xlsx',
            $sheets,
        );
    }

    public function lateAbsentExceptionsExcel(Request $request): void
    {
        $this->ensureAdmin();
        $filters = $this->filters->attendanceExceptions($request->getQueryParams());
        $this->ensureExcelFilters($filters, '/admin/reports/late-absent-exceptions', $request);
        $result = $this->exceptionReports->generate($filters, 20, true);
        if ($result['total_records'] === 0) {
            $this->redirectBack('/admin/reports/late-absent-exceptions', $request, $this->t('reports.excel_empty_exceptions'));
        }

        $summaryRows = [
            [$this->t('reports.total_records'), $this->excelCell((int) $result['total_records'], 'integer')],
        ];
        $exceptionRows = array_map(fn (array $row): array => [
            (string) ($row['staff_code'] ?? ''),
            (string) ($row['employee_name'] ?? ''),
            (string) ($row['employee_email'] ?? ''),
            (string) ($row['department_name'] ?? $this->t('reports_module.no_department')),
            $this->excelCell((string) ($row['attendance_date'] ?? ''), 'date'),
            $this->excelExceptionCell((string) ($row['status'] ?? '')),
            $this->excelAttendanceStatusCell((string) ($row['attendance_display_status'] ?? $row['status'] ?? '')),
            $this->excelCell((string) ($row['clock_in_time'] ?? '—'), 'time'),
            $this->excelCell((string) ($row['clock_out_time'] ?? '—'), 'time'),
            $this->excelCell((int) ($row['late_minutes'] ?? 0), 'integer'),
            $this->excelCell(
                !empty($row['has_approved_leave']) ? $this->t('reports_module.approved_leave_on_date') : $this->t('common.none'),
                !empty($row['has_approved_leave']) ? 'status_success' : 'status_neutral',
            ),
        ], $result['records']);

        $sheets = [
            $this->excelSheet(
                $this->t('reports.sheet_summary'),
                $this->t('reports_module.late_absent_exception'),
                [
                    $this->excelColumn($this->t('reports.metric'), 34),
                    $this->excelColumn($this->t('reports.value'), 38),
                ],
                $summaryRows,
                'summary',
            ),
            $this->excelSheet(
                $this->t('reports.sheet_exceptions'),
                $this->t('reports.late_absent_exceptions'),
                [
                    $this->excelColumn($this->t('employees_module.staff_code'), 14),
                    $this->excelColumn($this->t('common.labels.employee'), 24),
                    $this->excelColumn($this->t('common.labels.email'), 32),
                    $this->excelColumn($this->t('common.labels.department'), 22),
                    $this->excelColumn($this->t('common.labels.date'), 14),
                    $this->excelColumn($this->t('common.labels.exception'), 16),
                    $this->excelColumn($this->t('common.labels.status'), 16),
                    $this->excelColumn($this->t('common.labels.clock_in'), 12),
                    $this->excelColumn($this->t('common.labels.clock_out'), 12),
                    $this->excelColumn($this->t('attendance.late_minutes'), 12),
                    $this->excelColumn($this->t('common.labels.approved_leave'), 26),
                ],
                $exceptionRows,
            ),
        ];

        $this->streamExcelAndAudit(
            $request,
            'attendance_exceptions',
            $filters,
            'sapas_late_absent_exceptions_' . $this->filters->dateRangeFilename($filters) . '_' . date('Ymd_His') . '.xlsx',
            $sheets,
        );
    }

    private function ensurePdfFilters(array $filters, string $path, Request $request): void
    {
        if (($filters['errors'] ?? []) !== []) {
            $this->redirectBack($path, $request, $this->t('reports.pdf_fix_filters'));
        }
        if (!$this->pdf->isAvailable()) {
            $this->redirectBack($path, $request, $this->t('reports.pdf_dependency_missing'));
        }
    }

    private function ensureExcelFilters(array $filters, string $path, Request $request): void
    {
        if (($filters['errors'] ?? []) !== []) {
            $this->redirectBack($path, $request, $this->t('reports.excel_fix_filters'));
        }
    }

    private function streamAndAudit(Request $request, string $type, array $filters, string $title, string $filename, array $sections): never
    {
        $this->pdf->stream($title, $filename, $this->filterSummary($filters), $sections);
        $this->audit?->record($this->sessionManager->getUserId(), 'report.pdf_exported', 'report', $type,
            'PDF report generated', null, ['report_type' => $type, 'filters' => $this->safeFilterValues($filters)],
            $this->audit->requestContext($request->getServerParams()), false);
        exit;
    }

    /** @param list<array<string,mixed>> $sheets */
    private function streamExcelAndAudit(Request $request, string $type, array $filters, string $filename, array $sheets): never
    {
        $this->excel->stream($filename, [
            'project_name' => $this->t('app.export_name'),
            'generated_at_label' => $this->t('reports.generated_at'),
            'active_filters_label' => $this->t('reports.active_filters'),
            'filter_summary' => $this->filterSummary($filters),
            'sheets' => $sheets,
        ]);
        $this->audit?->record($this->sessionManager->getUserId(), 'report.excel_exported', 'report', $type,
            'Excel report generated', null, ['report_type' => $type, 'filters' => $this->safeFilterValues($filters)],
            $this->audit->requestContext($request->getServerParams()), false);
        exit;
    }

    private function redirectBack(string $path, Request $request, string $message): never
    {
        $this->sessionManager->setFlash('error', $message);
        $query = $request->getQueryParams();
        unset($query['page']);
        header('Location: ' . $path . ($query === [] ? '' : '?' . http_build_query($query)));
        exit;
    }

    private function viewFilters(array $filters): array
    {
        return [
            'date_from' => $filters['date_from_input'] ?? '', 'date_to' => $filters['date_to_input'] ?? '',
            'department_id' => $filters['department_id'] ?? null, 'employee_id' => $filters['employee_id'] ?? null,
            'status' => $filters['status'] ?? null, 'leave_type' => $filters['leave_type'] ?? null,
            'exception_type' => $filters['exception_type'] ?? 'all', 'per_page' => $filters['per_page'] ?? 20,
        ];
    }

    private function filterSummary(array $filters): array
    {
        $summary = [];
        if (($filters['date_from'] ?? null) !== null || ($filters['date_to'] ?? null) !== null) {
            $summary[] = $this->t('common.labels.date') . ': ' . ($filters['date_from'] ?? '—') . ' – ' . ($filters['date_to'] ?? '—');
        }
        if (isset($filters['employee'])) {
            $summary[] = $this->t('common.labels.employee') . ': ' . ($filters['employee']['staff_code'] ?? '') . ' ' . ($filters['employee']['name'] ?? '');
        }
        if (isset($filters['department'])) {
            $summary[] = $this->t('common.labels.department') . ': ' . ($filters['department']['name'] ?? '');
        }
        if (($filters['status'] ?? null) !== null) {
            $summary[] = $this->t('common.labels.status') . ': ' . $this->leaveStatus((string) $filters['status']);
        }
        if (($filters['leave_type'] ?? null) !== null) {
            $summary[] = $this->t('common.labels.type') . ': ' . $this->leaveType((string) $filters['leave_type']);
        }
        if (($filters['exception_type'] ?? 'all') !== 'all') {
            $exceptionType = (string) $filters['exception_type'];
            $summary[] = $this->t('reports_module.exception_type') . ': '
                . ($exceptionType === 'absent' ? $this->t('statuses.exception.absent') : $this->t('statuses.exception.late'));
        }
        return $summary === [] ? [$this->t('reports.filter_all_records')] : $summary;
    }

    private function safeFilterValues(array $filters): array
    {
        return array_filter([
            'date_from' => $filters['date_from'] ?? null, 'date_to' => $filters['date_to'] ?? null,
            'department_id' => $filters['department_id'] ?? null, 'employee_id' => $filters['employee_id'] ?? null,
            'status' => $filters['status'] ?? null, 'leave_type' => $filters['leave_type'] ?? null,
            'exception_type' => $filters['exception_type'] ?? null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    private function attendanceStatus(string $status): string { return $this->t('statuses.attendance.' . $status); }
    private function leaveStatus(string $status): string { return $this->t('statuses.leave.' . $status); }
    private function leaveType(string $type): string { return $this->t('leave.types.' . $type); }

    private function notePreview(array $row): string
    {
        $note = trim((string) ($row['review_note'] ?? ''));
        if ($note !== '') {
            return mb_substr($note, 0, 120);
        }
        $reason = trim((string) ($row['cancel_reason'] ?? ''));
        if ($reason === '') {
            return $this->t('common.none');
        }
        return match ($reason) {
            'leave.cancel_reasons.admin', 'Cancelled by admin' => $this->t('leave.cancel_reasons.admin'),
            'leave.cancel_reasons.employee', 'Cancelled by employee' => $this->t('leave.cancel_reasons.employee'),
            default => mb_substr($reason, 0, 120),
        };
    }

    private function formatMinutes(int $minutes): string
    {
        $minutes = max(0, $minutes);
        $hours = intdiv($minutes, 60);
        $remaining = $minutes % 60;
        if ($hours > 0 && $remaining > 0) {
            return $hours . $this->t('common.units.hours_short') . ' ' . $remaining . $this->t('common.units.minutes_short');
        }
        return $hours > 0 ? $hours . $this->t('common.units.hours_short') : $remaining . $this->t('common.units.minutes_short');
    }

    private function dateRangeText(array $filters): string
    {
        $from = (string) ($filters['date_from'] ?? '');
        $to = (string) ($filters['date_to'] ?? '');

        return ($from !== '' ? $from : $this->t('reports_module.any_start'))
            . ' – '
            . ($to !== '' ? $to : $this->t('reports_module.any_end'));
    }

    /** @return array{value:mixed,type:string} */
    private function excelCell(mixed $value, string $type): array
    {
        return ['value' => $value, 'type' => $type];
    }

    /** @return array{header:string,width:float} */
    private function excelColumn(string $header, float $width): array
    {
        return ['header' => $header, 'width' => $width];
    }

    /** @return array{value:mixed,type:string} */
    private function excelAttendanceStatusCell(string $status): array
    {
        $type = match ($status) {
            'present', 'holiday', 'weekend', 'approved_leave' => 'status_success',
            'late', 'incomplete' => 'status_warning',
            'absent' => 'status_danger',
            default => 'status_neutral',
        };

        return $this->excelCell($this->attendanceStatus($status), $type);
    }

    /** @return array{value:mixed,type:string} */
    private function excelLeaveStatusCell(string $status): array
    {
        $type = match ($status) {
            'approved' => 'status_success',
            'pending' => 'status_warning',
            'rejected' => 'status_danger',
            'cancelled' => 'status_neutral',
            default => 'status_info',
        };

        return $this->excelCell($this->leaveStatus($status), $type);
    }

    /** @return array{value:mixed,type:string} */
    private function excelExceptionCell(string $status): array
    {
        $label = $status === 'absent'
            ? $this->t('statuses.exception.absent')
            : $this->t('statuses.exception.late');

        return $this->excelCell($label, $status === 'absent' ? 'status_danger' : 'status_warning');
    }

    /**
     * @param list<array{header:string,width:float}> $columns
     * @param list<array<int,mixed>> $rows
     * @return array{name:string,title:string,kind:string,columns:list<array{header:string,width:float}>,rows:list<array<int,mixed>>}
     */
    private function excelSheet(string $name, string $title, array $columns, array $rows, string $kind = 'data'): array
    {
        return [
            'name' => $name,
            'title' => $title,
            'kind' => $kind === 'summary' ? 'summary' : 'data',
            'columns' => $columns,
            'rows' => $rows,
        ];
    }
}
