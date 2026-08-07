<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Localization;
use App\Models\Notification;
use PDO;
use Throwable;

final class OvertimeReviewService
{
    public function __construct(
        private PDO $connection,
        private ?AuditLogService $auditLog = null,
        private ?Notification $notifications = null,
    ) {
    }

    /** @return array{records: list<array<string, mixed>>, total: int, page: int, per_page: int, filters: array<string, mixed>} */
    public function list(array $input, int $page = 1, int $perPage = 20): array
    {
        $filters = $this->normalizeFilters($input);
        $page = max(1, $page);
        $perPage = in_array($perPage, [20, 50, 100], true) ? $perPage : 20;
        $where = ['ar.calculated_overtime_minutes IS NOT NULL', 'ar.calculated_overtime_minutes > 0'];
        $params = [];
        $this->applyFilters($filters, $where, $params);
        $whereSql = ' WHERE ' . implode(' AND ', $where);

        $count = $this->count($whereSql, $params);
        $page = $this->clampPage($page, $perPage, $count);
        $sql = $this->selectSql() . $whereSql . '
                ORDER BY ar.attendance_date DESC, ar.id DESC
                LIMIT :limit OFFSET :offset';
        $statement = $this->connection->prepare($sql);
        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $statement->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $statement->execute();

        return [
            'records' => $statement->fetchAll(),
            'total' => $count,
            'page' => $page,
            'per_page' => $perPage,
            'filters' => $filters,
        ];
    }

    public function find(int $attendanceId): ?array
    {
        $statement = $this->connection->prepare($this->selectSql() . ' WHERE ar.id = :id LIMIT 1');
        $statement->execute([':id' => $attendanceId]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    public function approve(int $attendanceId, ?int $approvedMinutes, ?string $reason, ?int $actorUserId, array $auditContext = []): void
    {
        $this->decide($attendanceId, 'approved', $approvedMinutes, $reason, $actorUserId, $auditContext);
    }

    public function reject(int $attendanceId, string $reason, ?int $actorUserId, array $auditContext = []): void
    {
        if (trim($reason) === '') {
            throw new AttendanceOperationException('reason_required');
        }
        $this->decide($attendanceId, 'rejected', 0, $reason, $actorUserId, $auditContext);
    }

    public function adjust(int $attendanceId, int $approvedMinutes, string $reason, ?int $actorUserId, array $auditContext = []): void
    {
        if (trim($reason) === '') {
            throw new AttendanceOperationException('reason_required');
        }
        $this->decide($attendanceId, 'adjusted', $approvedMinutes, $reason, $actorUserId, $auditContext);
    }

    public function resetPending(int $attendanceId, string $reason, ?int $actorUserId, array $auditContext = []): void
    {
        if (trim($reason) === '') {
            throw new AttendanceOperationException('reason_required');
        }
        $this->decide($attendanceId, 'reset_to_pending', 0, $reason, $actorUserId, $auditContext);
    }

    public function notifyPendingIfNeeded(int $attendanceId): void
    {
        if ($this->notifications === null) {
            return;
        }
        $record = $this->find($attendanceId);
        if ($record === null || (string) ($record['overtime_approval_status'] ?? '') !== 'pending' || (int) ($record['calculated_overtime_minutes'] ?? 0) <= 0) {
            return;
        }
        $link = '/admin/overtime/show?id=' . $attendanceId;
        $admins = $this->connection->query("SELECT id FROM users WHERE role = 'admin' AND status = 'active'")->fetchAll();
        foreach ($admins as $admin) {
            $adminId = (int) $admin['id'];
            if ($this->notificationExists($adminId, 'overtime_pending', $link)) {
                continue;
            }
            $this->notifications->create(
                $adminId,
                'overtime_pending',
                'notifications.events.overtime_pending.title',
                'notifications.events.overtime_pending.message',
                $link,
                (int) $record['user_id'],
            );
        }
    }

    /** @return list<array<string, mixed>> */
    public function decisions(int $attendanceId): array
    {
        $statement = $this->connection->prepare(
            'SELECT od.*, u.name AS actor_name
             FROM overtime_decisions od
             LEFT JOIN users u ON u.id = od.decided_by
             WHERE od.attendance_id = :attendance_id
             ORDER BY od.decided_at DESC, od.id DESC'
        );
        $statement->execute([':attendance_id' => $attendanceId]);

        return $statement->fetchAll();
    }

    /** @return array<string, list<array<string, mixed>>> */
    public function options(): array
    {
        return [
            'policies' => $this->connection->query('SELECT id, name FROM work_schedule_templates ORDER BY name ASC')->fetchAll(),
            'departments' => $this->connection->query('SELECT id, name FROM departments ORDER BY name ASC')->fetchAll(),
            'employees' => $this->connection->query("SELECT id, name, email, staff_code FROM users WHERE role = 'employee' ORDER BY name ASC")->fetchAll(),
        ];
    }

    private function decide(int $attendanceId, string $decision, ?int $approvedMinutes, ?string $reason, ?int $actorUserId, array $auditContext): void
    {
        $this->connection->beginTransaction();
        try {
            $record = $this->lockAttendance($attendanceId);
            if ($record === null || (int) ($record['calculated_overtime_minutes'] ?? 0) <= 0) {
                throw new AttendanceOperationException('overtime_not_found');
            }
            $policy = $this->policyFor((int) ($record['resolved_policy_version_id'] ?? 0));
            $calculated = (int) ($record['calculated_overtime_minutes'] ?? 0);
            $previousApproved = (int) ($record['approved_overtime_minutes'] ?? 0);
            $currentStatus = (string) ($record['overtime_approval_status'] ?? '');
            $approvalRequired = (int) ($policy['approval_required'] ?? 0) === 1;
            $allowAdjustment = (int) ($policy['allow_admin_adjustment'] ?? 1) === 1;
            $allowAboveCalculated = (int) ($policy['allow_approval_above_calculated'] ?? 0) === 1;

            if (in_array($decision, ['approved', 'rejected'], true) && $currentStatus !== 'pending') {
                throw new AttendanceOperationException('stale_decision');
            }
            if ($decision === 'adjusted' && !in_array($currentStatus, ['pending', 'approved'], true)) {
                throw new AttendanceOperationException('stale_decision');
            }
            if ($decision === 'reset_to_pending' && $currentStatus === 'pending') {
                throw new AttendanceOperationException('stale_decision');
            }
            if ($decision === 'reset_to_pending' && !$approvalRequired) {
                throw new AttendanceOperationException('approval_not_required');
            }
            if ($decision === 'adjusted' && !$allowAdjustment) {
                throw new AttendanceOperationException('adjustment_not_allowed');
            }

            $targetStatus = match ($decision) {
                'rejected' => 'rejected',
                'reset_to_pending' => 'pending',
                default => 'approved',
            };
            $targetApproved = $decision === 'approved' && $approvedMinutes === null ? $calculated : max(0, (int) $approvedMinutes);
            if (in_array($decision, ['rejected', 'reset_to_pending'], true)) {
                $targetApproved = 0;
            }

            $manualReasonRequired = trim((string) $reason) === ''
                && (($decision !== 'approved') || $targetApproved !== $calculated || $targetApproved > $calculated);
            if ($manualReasonRequired) {
                throw new AttendanceOperationException('reason_required');
            }
            if ($targetApproved > $calculated && !$allowAboveCalculated) {
                throw new AttendanceOperationException('approval_above_calculated');
            }

            $now = date('Y-m-d H:i:s');
            $this->connection->prepare(
                'INSERT INTO overtime_decisions
                 (attendance_id, decision, calculated_minutes, previous_approved_minutes, approved_minutes, reason, decided_by, decided_at)
                 VALUES (:attendance_id, :decision, :calculated, :previous_approved, :approved, :reason, :decided_by, :decided_at)'
            )->execute([
                ':attendance_id' => $attendanceId,
                ':decision' => $decision,
                ':calculated' => $calculated,
                ':previous_approved' => $previousApproved,
                ':approved' => $targetApproved,
                ':reason' => $this->nullable(mb_substr(trim((string) $reason), 0, 1000)),
                ':decided_by' => $actorUserId,
                ':decided_at' => $now,
            ]);

            $this->connection->prepare(
                'UPDATE attendance_records
                 SET approved_overtime_minutes = :approved_minutes,
                     overtime_approval_status = :approval_status,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id'
            )->execute([
                ':approved_minutes' => $targetApproved,
                ':approval_status' => $targetStatus,
                ':id' => $attendanceId,
            ]);

            $newValues = ['approved_overtime_minutes' => $targetApproved, 'overtime_approval_status' => $targetStatus, 'decision' => $decision, 'reason' => $reason];
            $this->auditLog?->record($actorUserId, 'overtime.' . $decision, 'attendance_record', $attendanceId, 'Overtime decision recorded', [
                'approved_overtime_minutes' => $previousApproved,
                'overtime_approval_status' => $record['overtime_approval_status'] ?? null,
            ], $newValues, $auditContext);
            $this->connection->commit();

            $this->notifyEmployeeDecision($record, $decision);
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $exception;
        }
    }

    private function notifyEmployeeDecision(array $record, string $decision): void
    {
        if ($this->notifications === null) {
            return;
        }
        $event = match ($decision) {
            'approved' => 'overtime_approved',
            'rejected' => 'overtime_rejected',
            'adjusted' => 'overtime_adjusted',
            default => 'overtime_reset_pending',
        };
        $this->notifications->create(
            (int) $record['user_id'],
            $event,
            'notifications.events.' . $event . '.title',
            'notifications.events.' . $event . '.message',
            '/attendance',
            null,
        );
    }

    private function lockAttendance(int $attendanceId): ?array
    {
        $statement = $this->connection->prepare('SELECT * FROM attendance_records WHERE id = :id LIMIT 1 FOR UPDATE');
        $statement->execute([':id' => $attendanceId]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    private function policyFor(int $policyId): array
    {
        if ($policyId <= 0) {
            return ['approval_required' => 0, 'allow_admin_adjustment' => 1, 'allow_approval_above_calculated' => 0];
        }
        $statement = $this->connection->prepare('SELECT * FROM overtime_policy_settings WHERE work_schedule_template_id = :id LIMIT 1');
        $statement->execute([':id' => $policyId]);
        $row = $statement->fetch();

        return $row === false ? ['approval_required' => 0, 'allow_admin_adjustment' => 1, 'allow_approval_above_calculated' => 0] : $row;
    }

    private function selectSql(): string
    {
        return 'SELECT ar.*, u.name AS employee_name, u.email AS employee_email, u.staff_code,
                       d.name AS department_name, wst.name AS policy_name, wsa.scope_type AS assignment_scope
                FROM attendance_records ar
                JOIN users u ON u.id = ar.user_id
                LEFT JOIN departments d ON d.id = u.department_id
                LEFT JOIN work_schedule_templates wst ON wst.id = ar.resolved_policy_version_id
                LEFT JOIN work_schedule_assignments wsa ON wsa.id = ar.resolved_assignment_id';
    }

    private function applyFilters(array $filters, array &$where, array &$params): void
    {
        if ($filters['from'] !== '') {
            $where[] = 'ar.attendance_date >= :from_date';
            $params[':from_date'] = $filters['from'];
        }
        if ($filters['to'] !== '') {
            $where[] = 'ar.attendance_date <= :to_date';
            $params[':to_date'] = $filters['to'];
        }
        if ($filters['employee_id'] !== null) {
            $where[] = 'ar.user_id = :employee_id';
            $params[':employee_id'] = $filters['employee_id'];
        }
        if ($filters['department_id'] !== null) {
            $where[] = 'u.department_id = :department_id';
            $params[':department_id'] = $filters['department_id'];
        }
        if ($filters['day_type'] !== '') {
            $where[] = 'ar.day_type = :day_type';
            $params[':day_type'] = $filters['day_type'];
        }
        if ($filters['approval_status'] !== '') {
            $where[] = 'ar.overtime_approval_status = :approval_status';
            $params[':approval_status'] = $filters['approval_status'];
        }
        if ($filters['policy_id'] !== null) {
            $where[] = 'ar.resolved_policy_version_id = :policy_id';
            $params[':policy_id'] = $filters['policy_id'];
        }
    }

    private function normalizeFilters(array $input): array
    {
        $from = $this->scalarString($input['from'] ?? '');
        $to = $this->scalarString($input['to'] ?? '');
        $dayType = $this->scalarString($input['day_type'] ?? '');
        $approvalStatus = $this->scalarString($input['approval_status'] ?? '');

        return [
            'from' => $this->validDate($from) ? $from : '',
            'to' => $this->validDate($to) ? $to : '',
            'employee_id' => $this->optionalInt($input['employee_id'] ?? null),
            'department_id' => $this->optionalInt($input['department_id'] ?? null),
            'policy_id' => $this->optionalInt($input['policy_id'] ?? null),
            'day_type' => in_array($dayType, ['working_day', 'non_working_day', 'holiday'], true) ? $dayType : '',
            'approval_status' => in_array($approvalStatus, ['not_required', 'pending', 'approved', 'rejected'], true) ? $approvalStatus : '',
        ];
    }

    private function count(string $whereSql, array $params): int
    {
        $statement = $this->connection->prepare('SELECT COUNT(*) FROM attendance_records ar JOIN users u ON u.id = ar.user_id LEFT JOIN departments d ON d.id = u.department_id ' . $whereSql);
        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $statement->execute();

        return (int) $statement->fetchColumn();
    }

    private function notificationExists(int $userId, string $type, string $link): bool
    {
        $statement = $this->connection->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = :user_id AND type = :type AND link_url = :link');
        $statement->execute([':user_id' => $userId, ':type' => $type, ':link' => $link]);

        return (int) $statement->fetchColumn() > 0;
    }

    private function optionalInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_scalar($value)) {
            return null;
        }
        $parsed = filter_var($value, FILTER_VALIDATE_INT);

        return $parsed === false ? null : (int) $parsed;
    }

    private function scalarString(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function clampPage(int $page, int $perPage, int $total): int
    {
        $lastPage = max(1, (int) ceil(max(0, $total) / max(1, $perPage)));

        return min(max(1, $page), $lastPage);
    }

    private function validDate(string $value): bool
    {
        if ($value === '') {
            return false;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }

    private function nullable(string $value): ?string
    {
        return $value === '' ? null : $value;
    }

    public function messageFor(string $reason): string
    {
        return Localization::current()->translate('workforce_policy.overtime.validation.' . $reason);
    }
}
