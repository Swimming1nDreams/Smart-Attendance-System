<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\DatabaseOperationException;
use PDO;
use PDOException;

class LeaveRequest
{
    private PDO $connection;

    public function __construct(PDO $connection)
    {
        $this->connection = $connection;
    }

    public function create(int $userId, string $leaveType, string $startDate, string $endDate, int $durationDays, ?string $reason, string $status = 'pending'): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO leave_requests (user_id, leave_type, start_date, end_date, duration_days, reason, status) VALUES (:user_id, :leave_type, :start_date, :end_date, :duration_days, :reason, :status)'
        );
        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $statement->bindValue(':leave_type', $leaveType, PDO::PARAM_STR);
        $statement->bindValue(':start_date', $startDate, PDO::PARAM_STR);
        $statement->bindValue(':end_date', $endDate, PDO::PARAM_STR);
        $statement->bindValue(':duration_days', $durationDays, PDO::PARAM_INT);
        $statement->bindValue(':reason', $reason, $reason === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $statement->bindValue(':status', $status, PDO::PARAM_STR);

        try {
            $statement->execute();
            return (int) $this->connection->lastInsertId();
        } catch (PDOException $exception) {
            throw DatabaseOperationException::forOperation(self::class, 'create', $exception);
        }
    }

    public function findById(int $id): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT lr.id, lr.user_id, lr.leave_type, lr.start_date, lr.end_date, lr.duration_days, lr.reason, lr.status, lr.reviewed_by, lr.reviewed_at, lr.review_note, lr.cancelled_by, lr.cancelled_at, lr.cancel_reason, lr.created_at, lr.updated_at, u.name AS employee_name, u.email AS employee_email, u.staff_code, reviewer.name AS reviewed_by_name, canceller.name AS cancelled_by_name FROM leave_requests lr JOIN users u ON u.id = lr.user_id LEFT JOIN users reviewer ON reviewer.id = lr.reviewed_by LEFT JOIN users canceller ON canceller.id = lr.cancelled_by WHERE lr.id = :id LIMIT 1'
        );
        $statement->bindValue(':id', $id, PDO::PARAM_INT);

        try {
            $statement->execute();
        } catch (PDOException $exception) {
            throw DatabaseOperationException::forOperation(self::class, 'findById', $exception);
        }

        $leaveRequest = $statement->fetch();

        return $leaveRequest === false ? null : $leaveRequest;
    }

    public function findRequestsForEmployee(int $userId, int $page = 1, int $perPage = 20, ?string $status = null, ?string $leaveType = null, ?string $fromDate = null, ?string $toDate = null): array
    {
        $offset = max(0, ($page - 1) * $perPage);
        $sql = 'SELECT lr.id, lr.user_id, lr.leave_type, lr.start_date, lr.end_date, lr.duration_days, lr.reason, lr.status, lr.reviewed_by, lr.reviewed_at, lr.review_note, lr.cancelled_by, lr.cancelled_at, lr.cancel_reason, lr.created_at, lr.updated_at, u.name AS employee_name, u.staff_code FROM leave_requests lr JOIN users u ON u.id = lr.user_id WHERE lr.user_id = :user_id';
        $params = [':user_id' => $userId];

        if ($status !== null && $status !== '') {
            $sql .= ' AND lr.status = :status';
            $params[':status'] = $status;
        }

        if ($leaveType !== null && $leaveType !== '') {
            $sql .= ' AND lr.leave_type = :leave_type';
            $params[':leave_type'] = $leaveType;
        }

        if ($fromDate !== null && $fromDate !== '') {
            $sql .= ' AND lr.end_date >= :from_date';
            $params[':from_date'] = $fromDate;
        }

        if ($toDate !== null && $toDate !== '') {
            $sql .= ' AND lr.start_date <= :to_date';
            $params[':to_date'] = $toDate;
        }

        $sql .= ' ORDER BY lr.start_date DESC, lr.created_at DESC LIMIT :limit OFFSET :offset';

        try {
            $statement = $this->connection->prepare($sql);
            foreach ($params as $key => $value) {
                $statement->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
            }
            $statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
            $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
            $statement->execute();
            return $statement->fetchAll();
        } catch (PDOException $exception) {
            throw DatabaseOperationException::forOperation(self::class, 'findRequestsForEmployee', $exception);
        }
    }

    public function countRequestsForEmployee(int $userId, ?string $status = null, ?string $leaveType = null, ?string $fromDate = null, ?string $toDate = null): int
    {
        $sql = 'SELECT COUNT(*) FROM leave_requests WHERE user_id = :user_id';
        $params = [':user_id' => $userId];

        if ($status !== null && $status !== '') {
            $sql .= ' AND status = :status';
            $params[':status'] = $status;
        }

        if ($leaveType !== null && $leaveType !== '') {
            $sql .= ' AND leave_type = :leave_type';
            $params[':leave_type'] = $leaveType;
        }

        if ($fromDate !== null && $fromDate !== '') {
            $sql .= ' AND end_date >= :from_date';
            $params[':from_date'] = $fromDate;
        }

        if ($toDate !== null && $toDate !== '') {
            $sql .= ' AND start_date <= :to_date';
            $params[':to_date'] = $toDate;
        }

        $statement = $this->connection->prepare($sql);

        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }

        try {
            $statement->execute();
            return (int) $statement->fetchColumn();
        } catch (PDOException $exception) {
            throw DatabaseOperationException::forOperation(self::class, 'countRequestsForEmployee', $exception);
        }
    }

    public function findAllRequestsForAdminListing(?int $userId = null, ?string $status = null, ?string $leaveType = null, string $search = '', ?string $fromDate = null, ?string $toDate = null, int $page = 1, int $perPage = 20): array
    {
        $offset = max(0, ($page - 1) * $perPage);
        $sql = 'SELECT lr.id, lr.user_id, lr.leave_type, lr.start_date, lr.end_date, lr.duration_days, lr.reason, lr.status, lr.reviewed_by, lr.reviewed_at, lr.review_note, lr.cancelled_by, lr.cancelled_at, lr.cancel_reason, lr.created_at, lr.updated_at, u.name AS employee_name, u.email AS employee_email, u.staff_code FROM leave_requests lr JOIN users u ON u.id = lr.user_id WHERE 1=1';
        $params = [];

        if ($userId !== null) {
            $sql .= ' AND lr.user_id = :user_id';
            $params[':user_id'] = $userId;
        }

        if ($status !== null && $status !== '') {
            $sql .= ' AND lr.status = :status';
            $params[':status'] = $status;
        }

        if ($leaveType !== null && $leaveType !== '') {
            $sql .= ' AND lr.leave_type = :leave_type';
            $params[':leave_type'] = $leaveType;
        }

        if ($search !== '') {
            $sql .= ' AND (u.name LIKE :search_name OR u.email LIKE :search_email OR u.staff_code LIKE :search_staff_code)';
            $searchPattern = '%' . $search . '%';
            $params[':search_name'] = $searchPattern;
            $params[':search_email'] = $searchPattern;
            $params[':search_staff_code'] = $searchPattern;
        }

        if ($fromDate !== null && $fromDate !== '') {
            $sql .= ' AND lr.end_date >= :from_date';
            $params[':from_date'] = $fromDate;
        }

        if ($toDate !== null && $toDate !== '') {
            $sql .= ' AND lr.start_date <= :to_date';
            $params[':to_date'] = $toDate;
        }

        $sql .= ' ORDER BY lr.start_date DESC, lr.created_at DESC LIMIT :limit OFFSET :offset';

        $statement = $this->connection->prepare($sql);

        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }

        $statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);

        try {
            $statement->execute();
            return $statement->fetchAll();
        } catch (PDOException $exception) {
            throw DatabaseOperationException::forOperation(self::class, 'findAllRequestsForAdminListing', $exception);
        }
    }

    public function countAllRequestsForAdminListing(?int $userId = null, ?string $status = null, ?string $leaveType = null, string $search = '', ?string $fromDate = null, ?string $toDate = null): int
    {
        $sql = 'SELECT COUNT(*) FROM leave_requests lr JOIN users u ON u.id = lr.user_id WHERE 1=1';
        $params = [];

        if ($userId !== null) {
            $sql .= ' AND lr.user_id = :user_id';
            $params[':user_id'] = $userId;
        }

        if ($status !== null && $status !== '') {
            $sql .= ' AND lr.status = :status';
            $params[':status'] = $status;
        }

        if ($leaveType !== null && $leaveType !== '') {
            $sql .= ' AND lr.leave_type = :leave_type';
            $params[':leave_type'] = $leaveType;
        }

        if ($search !== '') {
            $sql .= ' AND (u.name LIKE :search_name OR u.email LIKE :search_email OR u.staff_code LIKE :search_staff_code)';
            $searchPattern = '%' . $search . '%';
            $params[':search_name'] = $searchPattern;
            $params[':search_email'] = $searchPattern;
            $params[':search_staff_code'] = $searchPattern;
        }

        if ($fromDate !== null && $fromDate !== '') {
            $sql .= ' AND lr.end_date >= :from_date';
            $params[':from_date'] = $fromDate;
        }

        if ($toDate !== null && $toDate !== '') {
            $sql .= ' AND lr.start_date <= :to_date';
            $params[':to_date'] = $toDate;
        }

        $statement = $this->connection->prepare($sql);

        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }

        try {
            $statement->execute();
            return (int) $statement->fetchColumn();
        } catch (PDOException $exception) {
            throw DatabaseOperationException::forOperation(self::class, 'countAllRequestsForAdminListing', $exception);
        }
    }

    public function findOverlappingRequests(int $userId, string $startDate, string $endDate, ?int $excludeId = null): array
    {
        $sql = 'SELECT id, user_id, leave_type, start_date, end_date, duration_days, status, created_at FROM leave_requests WHERE user_id = :user_id AND start_date <= :end_date AND end_date >= :start_date AND status IN (\'pending\', \'approved\')';

        if ($excludeId !== null) {
            $sql .= ' AND id != :id';
        }

        $sql .= ' ORDER BY start_date ASC, end_date ASC';

        $statement = $this->connection->prepare($sql);
        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $statement->bindValue(':start_date', $startDate, PDO::PARAM_STR);
        $statement->bindValue(':end_date', $endDate, PDO::PARAM_STR);

        if ($excludeId !== null) {
            $statement->bindValue(':id', $excludeId, PDO::PARAM_INT);
        }

        try {
            $statement->execute();
            return $statement->fetchAll();
        } catch (PDOException $exception) {
            throw DatabaseOperationException::forOperation(self::class, 'findOverlappingRequests', $exception);
        }
    }

    public function existsApprovedLeaveForDate(int $userId, string $date): bool
    {
        $statement = $this->connection->prepare(
            'SELECT COUNT(*) FROM leave_requests WHERE user_id = :user_id AND status = :status AND start_date <= :date_start AND end_date >= :date_end'
        );
        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $statement->bindValue(':status', 'approved', PDO::PARAM_STR);
        $statement->bindValue(':date_start', $date, PDO::PARAM_STR);
        $statement->bindValue(':date_end', $date, PDO::PARAM_STR);

        try {
            $statement->execute();
            return (int) $statement->fetchColumn() > 0;
        } catch (PDOException $exception) {
            throw DatabaseOperationException::forOperation(self::class, 'existsApprovedLeaveForDate', $exception);
        }
    }

    public function findApprovedRequestsForEmployeeInRange(int $userId, string $fromDate, string $toDate): array
    {
        $statement = $this->connection->prepare(
            'SELECT id, user_id, leave_type, start_date, end_date, duration_days, status FROM leave_requests WHERE user_id = :user_id AND status = :status AND start_date <= :to_date AND end_date >= :from_date ORDER BY start_date ASC, end_date ASC'
        );
        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $statement->bindValue(':status', 'approved', PDO::PARAM_STR);
        $statement->bindValue(':from_date', $fromDate, PDO::PARAM_STR);
        $statement->bindValue(':to_date', $toDate, PDO::PARAM_STR);

        try {
            $statement->execute();
            return $statement->fetchAll();
        } catch (PDOException $exception) {
            throw DatabaseOperationException::forOperation(self::class, 'findApprovedRequestsForEmployeeInRange', $exception);
        }
    }

    /** @param list<int> $userIds */
    public function findApprovedForUsersInRange(array $userIds, string $fromDate, string $toDate): array
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn (int $id): bool => $id > 0)));
        if ($userIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $statement = $this->connection->prepare(
            "SELECT id, user_id, leave_type, start_date, end_date, duration_days, status
             FROM leave_requests
             WHERE status = 'approved' AND user_id IN ($placeholders)
               AND start_date <= ? AND end_date >= ?
             ORDER BY user_id ASC, start_date ASC, end_date ASC"
        );
        $statement->execute([...$userIds, $toDate, $fromDate]);

        return $statement->fetchAll();
    }

    public function updateStatusAndReview(int $id, string $status, ?int $reviewedBy = null, ?string $reviewedAt = null, ?string $reviewNote = null): bool
    {
        $statement = $this->connection->prepare(
            'UPDATE leave_requests SET status = :status, reviewed_by = :reviewed_by, reviewed_at = :reviewed_at, review_note = :review_note, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
        );
        $statement->bindValue(':status', $status, PDO::PARAM_STR);
        $statement->bindValue(':reviewed_by', $reviewedBy, $reviewedBy === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $statement->bindValue(':reviewed_at', $reviewedAt, $reviewedAt === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $statement->bindValue(':review_note', $reviewNote, $reviewNote === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $statement->bindValue(':id', $id, PDO::PARAM_INT);

        try {
            return $statement->execute();
        } catch (PDOException) {
            return false;
        }
    }

    public function approvePendingRequest(int $id, int $reviewedBy, string $reviewedAt): bool
    {
        $statement = $this->connection->prepare(
            'UPDATE leave_requests SET status = :status, reviewed_by = :reviewed_by, reviewed_at = :reviewed_at, review_note = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND status = :current_status'
        );
        $statement->bindValue(':status', 'approved', PDO::PARAM_STR);
        $statement->bindValue(':reviewed_by', $reviewedBy, PDO::PARAM_INT);
        $statement->bindValue(':reviewed_at', $reviewedAt, PDO::PARAM_STR);
        $statement->bindValue(':id', $id, PDO::PARAM_INT);
        $statement->bindValue(':current_status', 'pending', PDO::PARAM_STR);

        try {
            $statement->execute();
            return $statement->rowCount() === 1;
        } catch (PDOException) {
            return false;
        }
    }

    public function rejectPendingRequest(int $id, int $reviewedBy, string $reviewedAt, string $reviewNote): bool
    {
        $statement = $this->connection->prepare(
            'UPDATE leave_requests SET status = :status, reviewed_by = :reviewed_by, reviewed_at = :reviewed_at, review_note = :review_note, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND status = :current_status'
        );
        $statement->bindValue(':status', 'rejected', PDO::PARAM_STR);
        $statement->bindValue(':reviewed_by', $reviewedBy, PDO::PARAM_INT);
        $statement->bindValue(':reviewed_at', $reviewedAt, PDO::PARAM_STR);
        $statement->bindValue(':review_note', $reviewNote, PDO::PARAM_STR);
        $statement->bindValue(':id', $id, PDO::PARAM_INT);
        $statement->bindValue(':current_status', 'pending', PDO::PARAM_STR);

        try {
            $statement->execute();
            return $statement->rowCount() === 1;
        } catch (PDOException) {
            return false;
        }
    }

    public function cancelPendingOrApprovedRequestByAdmin(int $id, int $cancelledBy, string $cancelledAt, string $cancelReason): bool
    {
        $statement = $this->connection->prepare(
            'UPDATE leave_requests SET status = :status, cancelled_by = :cancelled_by, cancelled_at = :cancelled_at, cancel_reason = :cancel_reason, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND status IN (\'pending\', \'approved\')'
        );
        $statement->bindValue(':status', 'cancelled', PDO::PARAM_STR);
        $statement->bindValue(':cancelled_by', $cancelledBy, PDO::PARAM_INT);
        $statement->bindValue(':cancelled_at', $cancelledAt, PDO::PARAM_STR);
        $statement->bindValue(':cancel_reason', $cancelReason, PDO::PARAM_STR);
        $statement->bindValue(':id', $id, PDO::PARAM_INT);

        try {
            $statement->execute();
            return $statement->rowCount() === 1;
        } catch (PDOException) {
            return false;
        }
    }

    public function cancelRequest(int $id, ?int $cancelledBy = null, ?string $cancelledAt = null, ?string $cancelReason = null): bool
    {
        $statement = $this->connection->prepare(
            'UPDATE leave_requests SET status = :status, cancelled_by = :cancelled_by, cancelled_at = :cancelled_at, cancel_reason = :cancel_reason, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
        );
        $statement->bindValue(':status', 'cancelled', PDO::PARAM_STR);
        $statement->bindValue(':cancelled_by', $cancelledBy, $cancelledBy === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $statement->bindValue(':cancelled_at', $cancelledAt, $cancelledAt === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $statement->bindValue(':cancel_reason', $cancelReason, $cancelReason === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $statement->bindValue(':id', $id, PDO::PARAM_INT);

        try {
            return $statement->execute();
        } catch (PDOException) {
            return false;
        }
    }

    public function cancelPendingRequestForEmployee(int $id, int $userId, ?string $cancelledAt = null, ?string $cancelReason = null): bool
    {
        $statement = $this->connection->prepare(
            'UPDATE leave_requests SET status = :status, cancelled_by = :cancelled_by, cancelled_at = :cancelled_at, cancel_reason = :cancel_reason, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND user_id = :user_id AND status = :current_status'
        );
        $statement->bindValue(':status', 'cancelled', PDO::PARAM_STR);
        $statement->bindValue(':cancelled_by', $userId, PDO::PARAM_INT);
        $statement->bindValue(':cancelled_at', $cancelledAt, $cancelledAt === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $statement->bindValue(':cancel_reason', $cancelReason, $cancelReason === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $statement->bindValue(':id', $id, PDO::PARAM_INT);
        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $statement->bindValue(':current_status', 'pending', PDO::PARAM_STR);

        try {
            $statement->execute();
            return $statement->rowCount() === 1;
        } catch (PDOException) {
            return false;
        }
    }

    public function updateCancellation(int $id, ?int $cancelledBy = null, ?string $cancelledAt = null, ?string $cancelReason = null): bool
    {
        return $this->cancelRequest($id, $cancelledBy, $cancelledAt, $cancelReason);
    }

    public function findByUserId(int $userId, int $page = 1, int $perPage = 20): array
    {
        return $this->findRequestsForEmployee($userId, $page, $perPage);
    }

    public function countByUserId(int $userId): int
    {
        return $this->countRequestsForEmployee($userId);
    }

    public function findAll(?int $userId = null, ?string $status = null, ?string $leaveType = null, string $search = '', ?string $fromDate = null, ?string $toDate = null, int $page = 1, int $perPage = 20): array
    {
        return $this->findAllRequestsForAdminListing($userId, $status, $leaveType, $search, $fromDate, $toDate, $page, $perPage);
    }

    public function countAll(?int $userId = null, ?string $status = null, ?string $leaveType = null, string $search = '', ?string $fromDate = null, ?string $toDate = null): int
    {
        return $this->countAllRequestsForAdminListing($userId, $status, $leaveType, $search, $fromDate, $toDate);
    }

    public function findRecentForAdminDashboard(int $limit = 5): array
    {
        $statement = $this->connection->prepare(
            'SELECT lr.id, lr.user_id, lr.leave_type, lr.start_date, lr.end_date, lr.duration_days, lr.status, lr.created_at, u.name AS employee_name
             FROM leave_requests lr
             JOIN users u ON u.id = lr.user_id
             ORDER BY lr.created_at DESC, lr.id DESC
             LIMIT :limit'
        );
        $statement->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);

        try {
            $statement->execute();
            return $statement->fetchAll();
        } catch (PDOException $exception) {
            throw DatabaseOperationException::forOperation(self::class, 'findRecentForAdminDashboard', $exception);
        }
    }

    public function findOverlapping(int $userId, string $startDate, string $endDate, ?int $excludeId = null): array
    {
        return $this->findOverlappingRequests($userId, $startDate, $endDate, $excludeId);
    }
}
