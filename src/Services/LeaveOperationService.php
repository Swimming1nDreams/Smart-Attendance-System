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

    public function createRequest(int $userId, string $leaveType, string $startDate, string $endDate, int $durationDays, ?string $reason, ?array $attachmentData = null): int
    {
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

        return $this->transactional('create_leave_request', function () use ($userId, $leaveType, $startDate, $endDate, $durationDays, $reason, $attachmentData): int {
            $this->lockEmployee($userId, true);
            if ($this->hasOverlappingRequest($userId, $startDate, $endDate, null)) {
                throw new LeaveOperationException('overlap');
            }

            $statement = $this->connection->prepare(
                'INSERT INTO leave_requests (user_id, leave_type, start_date, end_date, duration_days, reason, status) VALUES (:user_id, :leave_type, :start_date, :end_date, :duration_days, :reason, :status)'
            );
            $statement->execute([
                ':user_id' => $userId,
                ':leave_type' => $leaveType,
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

            $this->lockEmployee((int) $snapshot['user_id'], true);
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
        return $this->transitionPending($leaveId, 'reject_leave_request', function (array $leave) use ($reviewedBy, $reviewedAt, $reviewNote, $auditContext): void {
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
        });
    }

    public function cancelByAdmin(int $leaveId, int $cancelledBy, string $cancelledAt, string $cancelReason, array $auditContext = []): array
    {
        return $this->transactional('cancel_leave_request_by_admin', function () use ($leaveId, $cancelledBy, $cancelledAt, $cancelReason, $auditContext): array {
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
            $this->auditLog?->record($cancelledBy, 'leave.cancelled_by_admin', 'leave_request', $leaveId, 'Administrator cancelled leave request',
                ['status' => $leave['status']], ['status' => 'cancelled', 'cancelled_by' => $cancelledBy, 'cancelled_at' => $cancelledAt, 'cancel_reason' => $cancelReason], $auditContext);

            return $leave;
        });
    }

    public function cancelByEmployee(int $leaveId, int $userId, string $cancelledAt, string $cancelReason, array $auditContext = []): array
    {
        return $this->transactional('cancel_leave_request_by_employee', function () use ($leaveId, $userId, $cancelledAt, $cancelReason, $auditContext): array {
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

    private function transitionPending(int $leaveId, string $operation, callable $transition): array
    {
        return $this->transactional($operation, function () use ($leaveId, $transition): array {
            $leave = $this->findLeave($leaveId, true);
            if ($leave === null) {
                throw new LeaveOperationException('not_found');
            }
            if ($leave['status'] !== 'pending') {
                throw new LeaveOperationException('concurrent_update');
            }
            $transition($leave);

            return $leave;
        });
    }

    private function lockEmployee(int $userId, bool $requireActive): void
    {
        $statement = $this->connection->prepare('SELECT id, role, status FROM users WHERE id = :id LIMIT 1 FOR UPDATE');
        $statement->execute([':id' => $userId]);
        $employee = $statement->fetch();
        if ($employee === false || $employee['role'] !== 'employee' || ($requireActive && $employee['status'] !== 'active')) {
            throw new LeaveOperationException('employee_invalid');
        }
    }

    private function findLeave(int $leaveId, bool $lock): ?array
    {
        $sql = 'SELECT id, user_id, leave_type, start_date, end_date, duration_days, reason, status FROM leave_requests WHERE id = :id LIMIT 1';
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
