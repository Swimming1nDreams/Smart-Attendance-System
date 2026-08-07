<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Application;
use App\Core\Request;
use App\Core\SessionManager;
use App\Models\CompanyProfile;
use App\Models\Department;
use App\Models\Employee;
use App\Models\WorkScheduleTemplate;
use App\Services\AttendanceOperationException;
use App\Services\OvertimeReviewService;
use App\Services\WorkScheduleAssignmentService;
use App\Services\WorkScheduleService;

final class WorkScheduleController extends MasterDataController
{
    public function __construct(
        private WorkScheduleTemplate $templates,
        private WorkScheduleService $service,
        private ?CompanyProfile $profiles,
        private ?WorkScheduleAssignmentService $assignments,
        private ?OvertimeReviewService $overtimeReview,
        private ?Department $departments,
        private ?Employee $employees,
        SessionManager $sessionManager,
        Application $app,
    ) {
        parent::__construct($sessionManager, $app);
    }

    public function index(Request $request): void
    {
        $this->ensureAdmin();

        $this->renderPolicyCenter($request);
    }

    public function create(Request $request): void
    {
        $this->ensureAdmin();

        $this->render('work_schedules/form', [
            'errors' => [],
            'schedule' => $this->service->defaultTemplateData(),
            'action' => '/admin/work-schedules',
            'title' => $this->t('work_schedules.create_title'),
        ]);
    }

    public function store(Request $request): void
    {
        $this->ensureAdmin();
        $this->validateCsrfToken($request);

        $result = $this->service->create($request->getBodyParams(), $this->sessionManager->getUserId(), $this->auditContext($request));
        if (!$result['ok']) {
            $this->render('work_schedules/form', [
                'errors' => $result['errors'],
                'schedule' => $result['data'],
                'action' => '/admin/work-schedules',
                'title' => $this->t('work_schedules.create_title'),
            ]);
            return;
        }

        $this->sessionManager->setFlash('success', $this->t('flash.work_schedule_created'));
        header('Location: /admin/work-schedules?section=weekly-schedule&policy_id=' . (int) $result['id']);
        exit;
    }

    public function edit(Request $request): void
    {
        $this->ensureAdmin();
        $id = (int) $request->query('id', 0);
        $schedule = $this->templates->findWithDays($id);

        if ($schedule === null) {
            $this->sessionManager->setFlash('error', $this->t('flash.work_schedule_not_found'));
            header('Location: /admin/work-schedules');
            exit;
        }

        $this->render('work_schedules/form', [
            'errors' => [],
            'schedule' => $this->service->prepareForForm($schedule),
            'action' => '/admin/work-schedules/update?id=' . $id,
            'title' => $this->t('work_schedules.edit_title'),
        ]);
    }

    public function update(Request $request): void
    {
        $this->ensureAdmin();
        $this->validateCsrfToken($request);

        $id = (int) $request->query('id', 0);
        $result = $this->service->update($id, $request->getBodyParams(), $this->sessionManager->getUserId(), $this->auditContext($request));
        if (!$result['ok']) {
            $schedule = $result['data'];
            $schedule['id'] = $id;
            $this->render('work_schedules/form', [
                'errors' => $result['errors'],
                'schedule' => $schedule,
                'action' => '/admin/work-schedules/update?id=' . $id,
                'title' => $this->t('work_schedules.edit_title'),
            ]);
            return;
        }

        $this->sessionManager->setFlash('success', $this->t('flash.work_schedule_updated'));
        header('Location: /admin/work-schedules?section=weekly-schedule&policy_id=' . $id);
        exit;
    }

    public function toggleStatus(Request $request): void
    {
        $this->ensureAdmin();
        $this->validateCsrfToken($request);

        $id = (int) ($request->getBodyParams()['id'] ?? 0);
        $active = (string) ($request->getBodyParams()['is_active'] ?? '0') === '1';
        $result = $this->service->toggleStatus($id, $active, $this->sessionManager->getUserId(), $this->auditContext($request));

        $this->sessionManager->setFlash($result['ok'] ? 'success' : 'error', $result['message']);
        header('Location: /admin/work-schedules?section=history');
        exit;
    }

    public function clonePolicy(Request $request): void
    {
        $this->ensureAdmin();
        $this->validateCsrfToken($request);

        $result = $this->service->cloneAsDraft($request->getBodyParams(), $this->sessionManager->getUserId(), $this->auditContext($request));
        if (!$result['ok']) {
            $this->renderPolicyCenter($request, ['clone' => $result['errors']], ['cloneInput' => $result['data'], 'section' => 'work-rules', 'createMode' => true]);
            return;
        }

        $this->sessionManager->setFlash('success', $this->t('flash.work_schedule_draft_created'));
        header('Location: /admin/work-schedules?section=work-rules&policy_id=' . (int) $result['id']);
        exit;
    }

    public function updateWeeklySchedule(Request $request): void
    {
        $this->ensureAdmin();
        $this->validateCsrfToken($request);

        $id = (int) $request->query('id', 0);
        $result = $this->service->updateWeeklySchedule($id, $request->getBodyParams(), $this->sessionManager->getUserId(), $this->auditContext($request));
        if (!$result['ok']) {
            $this->renderPolicyCenter($request, ['weekly' => $result['errors']], ['selectedPolicy' => array_replace($this->service->findWorkspacePolicy($id) ?? [], $result['data']), 'section' => 'weekly-schedule']);
            return;
        }

        $this->sessionManager->setFlash('success', $this->t('flash.work_schedule_weekly_updated'));
        header('Location: /admin/work-schedules?section=work-rules&policy_id=' . $id . '#weekly-schedule');
        exit;
    }

    public function updateAttendanceRules(Request $request): void
    {
        $this->ensureAdmin();
        $this->validateCsrfToken($request);

        $id = (int) $request->query('id', 0);
        $result = $this->service->updateAttendanceRules($id, $request->getBodyParams(), $this->sessionManager->getUserId(), $this->auditContext($request));
        if (!$result['ok']) {
            $this->renderPolicyCenter($request, ['attendance_rules' => $result['errors']], ['attendanceRules' => $result['data'], 'section' => 'attendance-rules']);
            return;
        }

        $this->sessionManager->setFlash('success', $this->t('flash.work_schedule_attendance_rules_updated'));
        header('Location: /admin/work-schedules?section=work-rules&policy_id=' . $id . '#attendance-rules');
        exit;
    }

    public function updateOvertimeRules(Request $request): void
    {
        $this->ensureAdmin();
        $this->validateCsrfToken($request);

        $id = (int) $request->query('id', 0);
        $result = $this->service->updateOvertimeRules($id, $request->getBodyParams(), $this->sessionManager->getUserId(), $this->auditContext($request));
        if (!$result['ok']) {
            $this->renderPolicyCenter($request, ['overtime_rules' => $result['errors']], ['overtimeRules' => $result['data'], 'section' => 'overtime-rules']);
            return;
        }

        $this->sessionManager->setFlash('success', $this->t('flash.work_schedule_overtime_rules_updated'));
        header('Location: /admin/work-schedules?section=work-rules&policy_id=' . $id . '#overtime-rules');
        exit;
    }

    public function scheduleVersion(Request $request): void
    {
        $this->ensureAdmin();
        $this->validateCsrfToken($request);
        $result = $this->service->scheduleVersion((int) $request->input('id', 0), (string) $request->input('effective_from', ''), $this->sessionManager->getUserId(), $this->auditContext($request));
        $this->sessionManager->setFlash($result['ok'] ? 'success' : 'error', $result['message']);
        header('Location: /admin/work-schedules?section=history');
        exit;
    }

    public function cancelSchedule(Request $request): void
    {
        $this->ensureAdmin();
        $this->validateCsrfToken($request);
        $result = $this->service->cancelSchedule((int) $request->input('id', 0), $this->sessionManager->getUserId(), $this->auditContext($request));
        $this->sessionManager->setFlash($result['ok'] ? 'success' : 'error', $result['message']);
        header('Location: /admin/work-schedules?section=history');
        exit;
    }

    public function archiveVersion(Request $request): void
    {
        $this->ensureAdmin();
        $this->validateCsrfToken($request);
        $result = $this->service->archiveVersion((int) $request->input('id', 0), $this->sessionManager->getUserId(), $this->auditContext($request));
        $this->sessionManager->setFlash($result['ok'] ? 'success' : 'error', $result['message']);
        header('Location: /admin/work-schedules?section=history');
        exit;
    }

    public function assignments(Request $request): void
    {
        $this->ensureAdmin();
        $service = $this->requireAssignments();
        $perPage = (int) $request->query('per_page', 20);
        $result = $service->list($request->getQueryParams(), max(1, (int) $request->query('page', 1)), $perPage);

        $this->render('work_schedules/assignments', [
            'result' => $result,
            'options' => $service->options(),
            'title' => $this->t('workforce_policy.assignments.title'),
        ]);
    }

    public function createAssignment(Request $request): void
    {
        $this->ensureAdmin();
        $service = $this->requireAssignments();

        $this->render('work_schedules/assignment_form', [
            'assignment' => $service->normalizeInput([]),
            'errors' => [],
            'options' => $service->options(),
            'action' => '/admin/work-schedules/assignments',
            'title' => $this->t('workforce_policy.assignments.create_title'),
        ]);
    }

    public function storeAssignment(Request $request): void
    {
        $this->ensureAdmin();
        $this->validateCsrfToken($request);
        $service = $this->requireAssignments();
        $result = $service->create($request->getBodyParams(), $this->sessionManager->getUserId(), $this->auditContext($request));
        if (!$result['ok']) {
            $this->render('work_schedules/assignment_form', [
                'assignment' => $result['data'],
                'errors' => $result['errors'],
                'options' => $service->options(),
                'action' => '/admin/work-schedules/assignments',
                'title' => $this->t('workforce_policy.assignments.create_title'),
            ]);
            return;
        }

        $this->sessionManager->setFlash('success', $this->t('flash.workforce_assignment_created'));
        header('Location: /admin/work-schedules/assignments');
        exit;
    }

    public function editAssignment(Request $request): void
    {
        $this->ensureAdmin();
        $service = $this->requireAssignments();
        $assignment = $service->find((int) $request->query('id', 0));
        if ($assignment === null) {
            $this->sessionManager->setFlash('error', $this->t('flash.workforce_assignment_not_found'));
            header('Location: /admin/work-schedules/assignments');
            exit;
        }

        $this->render('work_schedules/assignment_form', [
            'assignment' => $service->normalizeInput([], $assignment),
            'errors' => [],
            'options' => $service->options(),
            'action' => '/admin/work-schedules/assignments/update?id=' . (int) $assignment['id'],
            'title' => $this->t('workforce_policy.assignments.edit_title'),
        ]);
    }

    public function updateAssignment(Request $request): void
    {
        $this->ensureAdmin();
        $this->validateCsrfToken($request);
        $service = $this->requireAssignments();
        $id = (int) $request->query('id', 0);
        $result = $service->update($id, $request->getBodyParams(), $this->sessionManager->getUserId(), $this->auditContext($request));
        if (!$result['ok']) {
            $this->render('work_schedules/assignment_form', [
                'assignment' => $result['data'],
                'errors' => $result['errors'],
                'options' => $service->options(),
                'action' => '/admin/work-schedules/assignments/update?id=' . $id,
                'title' => $this->t('workforce_policy.assignments.edit_title'),
            ]);
            return;
        }

        $this->sessionManager->setFlash('success', $this->t('flash.workforce_assignment_updated'));
        header('Location: /admin/work-schedules/assignments');
        exit;
    }

    public function endAssignment(Request $request): void
    {
        $this->ensureAdmin();
        $this->validateCsrfToken($request);
        try {
            $this->requireAssignments()->end((int) $request->input('id', 0), (string) $request->input('effective_to', ''), $this->sessionManager->getUserId(), $this->auditContext($request));
            $this->sessionManager->setFlash('success', $this->t('flash.workforce_assignment_ended'));
        } catch (AttendanceOperationException) {
            $this->sessionManager->setFlash('error', $this->t('flash.workforce_assignment_failed'));
        }
        header('Location: /admin/work-schedules/assignments');
        exit;
    }

    public function removeEmployeeOverride(Request $request): void
    {
        $this->ensureAdmin();
        $this->validateCsrfToken($request);
        $removed = $this->requireAssignments()->removeEmployeeOverride((int) $request->input('employee_id', 0), $this->sessionManager->getUserId(), $this->auditContext($request));
        $this->sessionManager->setFlash($removed > 0 ? 'success' : 'error', $removed > 0 ? $this->t('flash.workforce_assignment_override_removed') : $this->t('flash.workforce_assignment_not_found'));
        header('Location: /admin/work-schedules/assignments');
        exit;
    }

    public function overtime(Request $request): void
    {
        $this->ensureAdmin();
        $service = $this->requireOvertimeReview();
        $result = $service->list($request->getQueryParams(), max(1, (int) $request->query('page', 1)), (int) $request->query('per_page', 20));
        $this->render('work_schedules/overtime_index', [
            'result' => $result,
            'options' => $service->options(),
            'title' => $this->t('workforce_policy.overtime.title'),
        ]);
    }

    public function showOvertime(Request $request): void
    {
        $this->ensureAdmin();
        $service = $this->requireOvertimeReview();
        $record = $service->find((int) $request->query('id', 0));
        if ($record === null) {
            $this->sessionManager->setFlash('error', $this->t('flash.overtime_not_found'));
            header('Location: /admin/overtime');
            exit;
        }
        $this->render('work_schedules/overtime_show', [
            'record' => $record,
            'decisions' => $service->decisions((int) $record['id']),
            'title' => $this->t('workforce_policy.overtime.details_title'),
        ]);
    }

    public function approveOvertime(Request $request): void
    {
        $this->decideOvertime($request, 'approve');
    }

    public function rejectOvertime(Request $request): void
    {
        $this->decideOvertime($request, 'reject');
    }

    public function adjustOvertime(Request $request): void
    {
        $this->decideOvertime($request, 'adjust');
    }

    public function resetOvertimePending(Request $request): void
    {
        $this->decideOvertime($request, 'reset');
    }

    private function decideOvertime(Request $request, string $action): void
    {
        $this->ensureAdmin();
        $this->validateCsrfToken($request);
        $service = $this->requireOvertimeReview();
        $id = (int) $request->input('id', 0);
        try {
            if ($action === 'approve') {
                $minutes = trim((string) $request->input('approved_minutes', '')) === '' ? null : (int) $request->input('approved_minutes', 0);
                $service->approve($id, $minutes, (string) $request->input('reason', ''), $this->sessionManager->getUserId(), $this->auditContext($request));
            } elseif ($action === 'reject') {
                $service->reject($id, (string) $request->input('reason', ''), $this->sessionManager->getUserId(), $this->auditContext($request));
            } elseif ($action === 'adjust') {
                $service->adjust($id, (int) $request->input('approved_minutes', 0), (string) $request->input('reason', ''), $this->sessionManager->getUserId(), $this->auditContext($request));
            } else {
                $service->resetPending($id, (string) $request->input('reason', ''), $this->sessionManager->getUserId(), $this->auditContext($request));
            }
            $this->sessionManager->setFlash('success', $this->t('flash.overtime_decision_saved'));
        } catch (AttendanceOperationException $exception) {
            $this->sessionManager->setFlash('error', $service->messageFor($exception->reason()));
        }
        header('Location: /admin/overtime/show?id=' . $id);
        exit;
    }

    private function requireAssignments(): WorkScheduleAssignmentService
    {
        if (!$this->assignments instanceof WorkScheduleAssignmentService) {
            throw new \RuntimeException('Workforce assignment service is unavailable.');
        }

        return $this->assignments;
    }

    private function requireOvertimeReview(): OvertimeReviewService
    {
        if (!$this->overtimeReview instanceof OvertimeReviewService) {
            throw new \RuntimeException('Overtime review service is unavailable.');
        }

        return $this->overtimeReview;
    }

    private function renderPolicyCenter(Request $request, array $errors = [], array $overrides = []): void
    {
        $section = (string) ($overrides['section'] ?? $request->query('section', 'overview'));
        $sectionAliases = [
            'versions' => 'history',
            'weekly-schedule' => 'work-rules',
            'attendance-rules' => 'work-rules',
            'overtime-rules' => 'work-rules',
        ];
        $section = $sectionAliases[$section] ?? $section;
        $allowed = ['overview', 'work-rules', 'assignments', 'overtime-review', 'history', 'organization'];
        if (!in_array($section, $allowed, true)) {
            $section = 'overview';
        }
        $schedules = $this->service->listWithSummaries();
        $policyId = (int) $request->query('policy_id', 0);
        $selectedPolicy = $overrides['selectedPolicy'] ?? $this->service->findWorkspacePolicy($policyId > 0 ? $policyId : null);
        $selectedPolicyId = (int) ($selectedPolicy['id'] ?? 0);
        $overtimeResult = $this->overtimeReview?->list(['approval_status' => 'pending'], 1, 1);
        $cloneInput = $overrides['cloneInput'] ?? [
            'source_mode' => (string) $request->query('source_mode', 'current'),
            'source_policy_id' => (int) $request->query('source_policy_id', 0),
        ];

        $this->render('work_schedules/index', array_merge($overrides, [
            'section' => $section,
            'schedules' => $schedules,
            'selectedPolicy' => $selectedPolicy,
            'attendanceRules' => $overrides['attendanceRules'] ?? ($selectedPolicyId > 0 ? $this->service->attendanceRules($selectedPolicyId) : []),
            'overtimeRules' => $overrides['overtimeRules'] ?? ($selectedPolicyId > 0 ? $this->service->overtimeRules($selectedPolicyId) : []),
            'profile' => $this->profiles?->findSingle(),
            'overview' => $this->service->overviewData($schedules, $this->profiles?->findSingle(), $overtimeResult),
            'assignments' => $this->assignments?->list($request->getQueryParams(), max(1, (int) $request->query('page', 1)), (int) $request->query('per_page', 20)),
            'overtime' => $this->overtimeReview?->list($request->getQueryParams(), 1, 20),
            'errors' => $errors,
            'cloneInput' => $cloneInput,
            'createMode' => (bool) ($overrides['createMode'] ?? ((string) $request->query('mode', '') === 'create')),
            'title' => $this->t('work_schedules.title'),
        ]));
    }
}
