<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use PDOException;
use Throwable;

final class LeaveOperationService
{
    private DateValidationService $dateValidator;

    public function __construct(private PDO $connection, ?DateValidationService $dateValidator = null, private ?AuditLogService $auditLog = null)
    {
        $this->dateValidator = $dateValidator ?? new DateValidationService();
    }

    public function createRequest(int $userId, string $leaveType, string $startDate, string $endDate, int $durationDays, ?string $reason, string $reasonCategory, ?array $attachmentData = null): int
    {
        if (!LeaveReasonCatalog::supportsLeaveType($leaveType)) {
            throw new LeaveOperationException('leave_type_invalid');
        }
        if (!LeaveReasonCatalog::isValid($leaveType, $reasonCategory)) {
            throw new LeaveOperationException('reason_category_invalid');
        }
        $reason = trim((string) $reason);
        if ($reason === '') {
            throw new LeaveOperationException('reason_required');
        }
        if (mb_strlen($reason) > 1000) {
            throw new LeaveOperationException('reason_too_long');
        }
        if (
            !$this->dateValidator->isValidDate($startDate)
            || !$this->dateValidator->isValidDate($endDate)
            || !$this->dateValidator->isValidRange($startDate, $endDate)
        ) {
            throw new LeaveOperationException('invalid_date_range');
        }
        if ($durationDays <= 0) {
            throw new LeaveOperationException('invalid_duration');
        }

        return $this->transactional('create_leave_request', function () use ($userId, $leaveType, $startDate, $endDate, $durationDays, $reason, $reasonCategory, $attachmentData): int {
            $this->lockLeaveRequester($userId, true);
            if ($this->hasOverlappingRequest($userId, $startDate, $endDate, null)) {
                throw new LeaveOperationException('overlap');
            }

            $statement = $this->connection->prepare(
                'INSERT INTO leave_requests (user_id, leave_type, reason_category, start_date, end_date, duration_days, reason, status) VALUES (:user_id, :leave_type, :reason_category, :start_date, :end_date, :duration_days, :reason, :status)'
            );
            $statement->execute([
                ':user_id' => $userId,
                ':leave_type' => $leaveType,
                ':reason_category' => $reasonCategory,
                ':start_date' => $startDate,
                ':end_date' => $endDate,
                ':duration_days' => $durationDays,
                ':reason' => $reason,
                ':status' => 'pending',
            ]);

            $leaveId = (int) $this->connection->lastInsertId();

            if ($attachmentData !== null) {
                $attachStmt = $this->connection->prepare(
                    'INSERT INTO leave_request_attachments (leave_request_id, file_path, original_name, mime_type, file_size) VALUES (:leave_request_id, :file_path, :original_name, :mime_type, :file_size)'
                );
                $attachStmt->execute([
                    ':leave_request_id' => $leaveId,
                    ':file_path' => $attachmentData['file_path'],
                    ':original_name' => $attachmentData['original_name'],
                    ':mime_type' => $attachmentData['mime_type'],
                    ':file_size' => $attachmentData['file_size'],
                ]);
            }

            return $leaveId;
        });
    }

    public function approve(int $leaveId, int $reviewedBy, string $reviewedAt, array $auditContext = []): array
    {
        return $this->transactional('approve_leave_request', function () use ($leaveId, $reviewedBy, $reviewedAt, $auditContext): array {
            $snapshot = $this->findLeave($leaveId, false);
            if ($snapshot === null) {
                throw new LeaveOperationException('not_found');
            }

            $requester = $this->lockLeaveRequester((int) $snapshot['user_id'], true);
            $this->assertManagerCanManageRequester($reviewedBy, $requester);
            $leave = $this->findLeave($leaveId, true);
            if ($leave === null) {
                throw new LeaveOperationException('not_found');
            }
            if ($leave['status'] !== 'pending') {
                throw new LeaveOperationException('concurrent_update');
            }
            if ($this->hasOverlappingRequest((int) $leave['user_id'], (string) $leave['start_date'], (string) $leave['end_date'], $leaveId)) {
                throw new LeaveOperationException('overlap');
            }
            if ($this->hasAttendanceConflict((int) $leave['user_id'], (string) $leave['start_date'], (string) $leave['end_date'])) {
                throw new LeaveOperationException('attendance_conflict');
            }

            $statement = $this->connection->prepare(
                'UPDATE leave_requests SET status = :status, reviewed_by = :reviewed_by, reviewed_at = :reviewed_at, review_note = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND status = :current_status'
            );
            $statement->execute([
                ':status' => 'approved',
                ':reviewed_by' => $reviewedBy,
                ':reviewed_at' => $reviewedAt,
                ':id' => $leaveId,
                ':current_status' => 'pending',
            ]);
            if ($statement->rowCount() !== 1) {
                throw new LeaveOperationException('concurrent_update');
            }
            $this->auditLog?->record($reviewedBy, 'leave.approved', 'leave_request', $leaveId, 'Leave request approved',
                ['status' => $leave['status']], ['status' => 'approved', 'reviewed_by' => $reviewedBy, 'reviewed_at' => $reviewedAt], $auditContext);

            return $leave;
        });
    }

    public function reject(int $leaveId, int $reviewedBy, string $reviewedAt, string $reviewNote, array $auditContext = []): array
    {
        return $this->transactional('reject_leave_request', function () use ($leaveId, $reviewedBy, $reviewedAt, $reviewNote, $auditContext): array {
            $snapshot = $this->findLeave($leaveId, false);
            if ($snapshot === null) {
                throw new LeaveOperationException('not_found');
            }
            $requester = $this->lockLeaveRequester((int) $snapshot['user_id'], false);
            $this->assertManagerCanManageRequester($reviewedBy, $requester);
            $leave = $this->findLeave($leaveId, true);
            if ($leave === null) {
                throw new LeaveOperationException('not_found');
            }
            if ($leave['status'] !== 'pending') {
                throw new LeaveOperationException('concurrent_update');
            }
            $statement = $this->connection->prepare(
                'UPDATE leave_requests SET status = :status, reviewed_by = :reviewed_by, reviewed_at = :reviewed_at, review_note = :review_note, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND status = :current_status'
            );
            $statement->execute([
                ':status' => 'rejected',
                ':reviewed_by' => $reviewedBy,
                ':reviewed_at' => $reviewedAt,
                ':review_note' => $reviewNote,
                ':id' => (int) $leave['id'],
                ':current_status' => 'pending',
            ]);
            if ($statement->rowCount() !== 1) {
                throw new LeaveOperationException('concurrent_update');
            }
            $this->auditLog?->record($reviewedBy, 'leave.rejected', 'leave_request', (int) $leave['id'], 'Leave request rejected',
                ['status' => $leave['status']], ['status' => 'rejected', 'reviewed_by' => $reviewedBy, 'reviewed_at' => $reviewedAt, 'review_note' => $reviewNote], $auditContext);

            return $leave;
        });
    }

    public function cancelByAdmin(int $leaveId, int $cancelledBy, string $cancelledAt, string $cancelReason, array $auditContext = []): array
    {
        return $this->transactional('cancel_leave_request_by_admin', function () use ($leaveId, $cancelledBy, $cancelledAt, $cancelReason, $auditContext): array {
            $snapshot = $this->findLeave($leaveId, false);
            if ($snapshot === null) {
                throw new LeaveOperationException('not_found');
            }
            $requester = $this->lockLeaveRequester((int) $snapshot['user_id'], false);
            $this->assertManagerCanManageRequester($cancelledBy, $requester);
            $leave = $this->findLeave($leaveId, true);
            if ($leave === null) {
                throw new LeaveOperationException('not_found');
            }
            if (!in_array($leave['status'], ['pending', 'approved'], true)) {
                throw new LeaveOperationException('concurrent_update');
            }

            $statement = $this->connection->prepare(
                'UPDATE leave_requests SET status = :status, cancelled_by = :cancelled_by, cancelled_at = :cancelled_at, cancel_reason = :cancel_reason, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND status IN (\'pending\', \'approved\')'
            );
            $statement->execute([
                ':status' => 'cancelled',
                ':cancelled_by' => $cancelledBy,
                ':cancelled_at' => $cancelledAt,
                ':cancel_reason' => $cancelReason,
                ':id' => $leaveId,
            ]);
            if ($statement->rowCount() !== 1) {
                throw new LeaveOperationException('concurrent_update');
            }
            $this->auditLog?->record($cancelledBy, 'leave.cancelled_by_manager', 'leave_request', $leaveId, 'Authorized manager cancelled leave request',
                ['status' => $leave['status']], ['status' => 'cancelled', 'cancelled_by' => $cancelledBy, 'cancelled_at' => $cancelledAt, 'cancel_reason' => $cancelReason], $auditContext);

            return $leave;
        });
    }

    public function cancelByEmployee(int $leaveId, int $userId, string $cancelledAt, string $cancelReason, array $auditContext = []): array
    {
        return $this->transactional('cancel_leave_request_by_employee', function () use ($leaveId, $userId, $cancelledAt, $cancelReason, $auditContext): array {
            $snapshot = $this->findLeave($leaveId, false);
            if ($snapshot === null || (int) $snapshot['user_id'] !== $userId) {
                throw new LeaveOperationException('not_found');
            }
            $this->lockLeaveRequester($userId, true);
            $leave = $this->findLeave($leaveId, true);
            if ($leave === null || (int) $leave['user_id'] !== $userId) {
                throw new LeaveOperationException('not_found');
            }
            if ($leave['status'] !== 'pending') {
                throw new LeaveOperationException('concurrent_update');
            }

            $statement = $this->connection->prepare(
                'UPDATE leave_requests SET status = :status, cancelled_by = :cancelled_by, cancelled_at = :cancelled_at, cancel_reason = :cancel_reason, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND user_id = :user_id AND status = :current_status'
            );
            $statement->execute([
                ':status' => 'cancelled',
                ':cancelled_by' => $userId,
                ':cancelled_at' => $cancelledAt,
                ':cancel_reason' => $cancelReason,
                ':id' => $leaveId,
                ':user_id' => $userId,
                ':current_status' => 'pending',
            ]);
            if ($statement->rowCount() !== 1) {
                throw new LeaveOperationException('concurrent_update');
            }
            $this->auditLog?->record($userId, 'leave.cancelled_by_employee', 'leave_request', $leaveId, 'Employee cancelled leave request',
                ['status' => $leave['status']], ['status' => 'cancelled', 'cancelled_by' => $userId, 'cancelled_at' => $cancelledAt, 'cancel_reason' => $cancelReason], $auditContext);

            return $leave;
        });
    }

    /** @return array<string, mixed> */
    private function lockLeaveRequester(int $userId, bool $requireActive): array
    {
        $statement = $this->connection->prepare('SELECT id, role, status, department_id FROM users WHERE id = :id LIMIT 1 FOR UPDATE');
        $statement->execute([':id' => $userId]);
        $requester = $statement->fetch();
        if (
            $requester === false
            || !in_array((string) ($requester['role'] ?? ''), ['employee', 'department_head'], true)
            || ($requireActive && ($requester['status'] ?? '') !== 'active')
        ) {
            throw new LeaveOperationException('employee_invalid');
        }

        return $requester;
    }

    /** @param array<string, mixed> $requester */
    private function assertManagerCanManageRequester(int $actorUserId, array $requester): void
    {
        $statement = $this->connection->prepare(
            'SELECT id, role, status, department_id FROM users WHERE id = :id LIMIT 1 FOR UPDATE'
        );
        $statement->execute([':id' => $actorUserId]);
        $actor = $statement->fetch();

        if ($actor === false || ($actor['status'] ?? '') !== 'active') {
            throw new LeaveOperationException('authorization_denied');
        }

        if (($actor['role'] ?? '') === 'admin') {
            return;
        }

        $actorDepartmentId = isset($actor['department_id']) ? (int) $actor['department_id'] : 0;
        $requesterDepartmentId = isset($requester['department_id']) ? (int) $requester['department_id'] : 0;
        if (
            ($actor['role'] ?? '') === 'department_head'
            && ($requester['role'] ?? '') === 'employee'
            && (int) ($actor['id'] ?? 0) !== (int) ($requester['id'] ?? 0)
            && $actorDepartmentId > 0
            && $actorDepartmentId === $requesterDepartmentId
        ) {
            return;
        }

        throw new LeaveOperationException('authorization_denied');
    }

    private function findLeave(int $leaveId, bool $lock): ?array
    {
        $sql = 'SELECT id, user_id, leave_type, reason_category, start_date, end_date, duration_days, reason, status FROM leave_requests WHERE id = :id LIMIT 1';
        if ($lock) {
            $sql .= ' FOR UPDATE';
        }
        $statement = $this->connection->prepare($sql);
        $statement->execute([':id' => $leaveId]);
        $leave = $statement->fetch();

        return $leave === false ? null : $leave;
    }

    private function hasOverlappingRequest(int $userId, string $startDate, string $endDate, ?int $excludeId): bool
    {
        $sql = 'SELECT id FROM leave_requests WHERE user_id = :user_id AND start_date <= :end_date AND end_date >= :start_date AND status IN (\'pending\', \'approved\')';
        if ($excludeId !== null) {
            $sql .= ' AND id != :exclude_id';
        }
        $sql .= ' LIMIT 1 FOR UPDATE';
        $statement = $this->connection->prepare($sql);
        $params = [':user_id' => $userId, ':start_date' => $startDate, ':end_date' => $endDate];
        if ($excludeId !== null) {
            $params[':exclude_id'] = $excludeId;
        }
        $statement->execute($params);

        return $statement->fetchColumn() !== false;
    }

    private function hasAttendanceConflict(int $userId, string $startDate, string $endDate): bool
    {
        $statement = $this->connection->prepare(
            'SELECT id FROM attendance_records WHERE user_id = :user_id AND attendance_date BETWEEN :start_date AND :end_date AND status IN (\'present\', \'absent\') LIMIT 1 FOR UPDATE'
        );
        $statement->execute([':user_id' => $userId, ':start_date' => $startDate, ':end_date' => $endDate]);

        return $statement->fetchColumn() !== false;
    }

    private function transactional(string $operation, callable $callback): mixed
    {
        $this->connection->beginTransaction();
        try {
            $result = $callback();
            $this->connection->commit();

            return $result;
        } catch (PDOException $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw DatabaseOperationException::forOperation(self::class, $operation, $exception);
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $exception;
        }
    }
}
