<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\DatabaseOperationException;
use PDO;
use PDOException;

class Notification
{
    private PDO $connection;

    public function __construct(PDO $connection)
    {
        $this->connection = $connection;
    }

    public function create(
        int $userId,
        string $type,
        string $titleKey,
        string $messageKey,
        ?string $linkUrl = null,
        ?int $relatedUserId = null,
        ?int $relatedLeaveRequestId = null,
        ?int $relatedWorkScheduleAssignmentId = null,
        ?string $availableAt = null,
        ?string $category = null,
        string $priority = 'normal',
        ?array $context = null,
        ?string $deduplicationKey = null,
    ): int
    {
        $category = $this->normalizeCategory($category ?? $this->categoryForType($type));
        $priority = $this->normalizePriority($priority);
        $statement = $this->connection->prepare(
            'INSERT INTO notifications
             (user_id, type, category, priority, title_key, message_key, context_json, deduplication_key,
              link_url, related_user_id, related_leave_request_id, related_work_schedule_assignment_id, available_at)
             VALUES (:user_id, :type, :category, :priority, :title_key, :message_key, :context_json, :deduplication_key,
                     :link_url, :related_user_id, :related_leave_request_id, :related_work_schedule_assignment_id, :available_at)'
        );
        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $statement->bindValue(':type', $type, PDO::PARAM_STR);
        $statement->bindValue(':category', $category, PDO::PARAM_STR);
        $statement->bindValue(':priority', $priority, PDO::PARAM_STR);
        $statement->bindValue(':title_key', $titleKey, PDO::PARAM_STR);
        $statement->bindValue(':message_key', $messageKey, PDO::PARAM_STR);
        $encodedContext = $context === null ? null : json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $statement->bindValue(':context_json', $encodedContext, $encodedContext === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $statement->bindValue(':deduplication_key', $deduplicationKey, $deduplicationKey === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $statement->bindValue(':link_url', $linkUrl, $linkUrl === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $statement->bindValue(':related_user_id', $relatedUserId, $relatedUserId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $statement->bindValue(':related_leave_request_id', $relatedLeaveRequestId, $relatedLeaveRequestId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $statement->bindValue(':related_work_schedule_assignment_id', $relatedWorkScheduleAssignmentId, $relatedWorkScheduleAssignmentId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $statement->bindValue(':available_at', $availableAt ?? date('Y-m-d H:i:s'), PDO::PARAM_STR);

        try {
            $statement->execute();
            return (int) $this->connection->lastInsertId();
        } catch (PDOException $exception) {
            throw DatabaseOperationException::forOperation(self::class, 'create', $exception);
        }
    }

    /**
     * @param list<int> $userIds
     */
    public function createForUsers(
        array $userIds,
        string $type,
        string $titleKey,
        string $messageKey,
        ?string $linkUrl = null,
        ?int $relatedUserId = null,
        ?int $relatedLeaveRequestId = null,
        ?int $relatedWorkScheduleAssignmentId = null,
        ?string $availableAt = null,
        ?string $category = null,
        string $priority = 'normal',
        ?array $context = null,
    ): void
    {
        $uniqueUserIds = array_values(array_unique(array_filter($userIds, static fn (int $userId): bool => $userId > 0)));

        foreach ($uniqueUserIds as $userId) {
            $this->create($userId, $type, $titleKey, $messageKey, $linkUrl, $relatedUserId, $relatedLeaveRequestId, $relatedWorkScheduleAssignmentId, $availableAt, $category, $priority, $context);
        }
    }

    public function countUnreadForUser(int $userId): int
    {
        $statement = $this->connection->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = :user_id AND available_at <= CURRENT_TIMESTAMP AND read_at IS NULL AND archived_at IS NULL AND cancelled_at IS NULL');
        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);

        try {
            $statement->execute();
            return (int) $statement->fetchColumn();
        } catch (PDOException $exception) {
            throw DatabaseOperationException::forOperation(self::class, 'countUnreadForUser', $exception);
        }
    }

    public function findForUser(int $userId, int $limit = 50): array
    {
        return $this->paginateForUser($userId, [], 1, max(1, $limit))['records'];
    }

    /** @param array<string, mixed> $filters @return array{records: array, total: int, page: int, per_page: int, filters: array} */
    public function paginateForUser(int $userId, array $filters, int $page = 1, int $perPage = 20): array
    {
        $page = max(1, $page);
        $perPage = in_array($perPage, [20, 50, 100], true) ? $perPage : 20;
        $category = $this->normalizeOptionalCategory((string) ($filters['category'] ?? ''));
        $priority = $this->normalizeOptionalPriority((string) ($filters['priority'] ?? ''));
        $status = in_array((string) ($filters['status'] ?? ''), ['unread', 'read', 'archived'], true)
            ? (string) $filters['status']
            : '';

        $where = ['user_id = :user_id', 'available_at <= CURRENT_TIMESTAMP', 'cancelled_at IS NULL'];
        $params = [':user_id' => $userId];
        if ($status === 'archived') {
            $where[] = 'archived_at IS NOT NULL';
        } else {
            $where[] = 'archived_at IS NULL';
            if ($status === 'unread') {
                $where[] = 'read_at IS NULL';
            } elseif ($status === 'read') {
                $where[] = 'read_at IS NOT NULL';
            }
        }
        if ($category !== '') {
            $where[] = 'category = :category';
            $params[':category'] = $category;
        }
        if ($priority !== '') {
            $where[] = 'priority = :priority';
            $params[':priority'] = $priority;
        }

        $whereSql = implode(' AND ', $where);
        try {
            $count = $this->connection->prepare('SELECT COUNT(*) FROM notifications WHERE ' . $whereSql);
            $count->execute($params);
            $total = (int) $count->fetchColumn();
            $pages = max(1, (int) ceil($total / $perPage));
            $page = min($page, $pages);

            $statement = $this->connection->prepare(
                'SELECT id, user_id, type, category, priority, title_key, message_key, context_json, deduplication_key,
                        link_url, related_user_id, related_leave_request_id, related_work_schedule_assignment_id,
                        read_at, archived_at, cancelled_at, available_at, created_at
                 FROM notifications WHERE ' . $whereSql . '
                 ORDER BY FIELD(priority, "urgent", "high", "normal", "low"), available_at DESC, id DESC
                 LIMIT :limit OFFSET :offset'
            );
            foreach ($params as $key => $value) {
                $statement->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
            }
            $statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
            $statement->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
            $statement->execute();
        } catch (PDOException $exception) {
            throw DatabaseOperationException::forOperation(self::class, 'paginateForUser', $exception);
        }

        return [
            'records' => $statement->fetchAll(),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'filters' => ['category' => $category, 'priority' => $priority, 'status' => $status],
        ];
    }

    public function markAsRead(int $id, int $userId): bool
    {
        if (!$this->existsForUser($id, $userId)) {
            return false;
        }

        $statement = $this->connection->prepare(
            'UPDATE notifications SET read_at = CURRENT_TIMESTAMP
             WHERE id = :id AND user_id = :user_id AND available_at <= CURRENT_TIMESTAMP AND read_at IS NULL AND archived_at IS NULL AND cancelled_at IS NULL'
        );
        $statement->bindValue(':id', $id, PDO::PARAM_INT);
        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);

        try {
            return $statement->execute();
        } catch (PDOException $exception) {
            throw DatabaseOperationException::forOperation(self::class, 'markAsRead', $exception);
        }
    }

    public function markAsUnread(int $id, int $userId): bool
    {
        $statement = $this->connection->prepare(
            'UPDATE notifications SET read_at = NULL
             WHERE id = :id AND user_id = :user_id AND available_at <= CURRENT_TIMESTAMP AND archived_at IS NULL AND cancelled_at IS NULL'
        );
        try {
            return $statement->execute([':id' => $id, ':user_id' => $userId]) && $statement->rowCount() > 0;
        } catch (PDOException $exception) {
            throw DatabaseOperationException::forOperation(self::class, 'markAsUnread', $exception);
        }
    }

    public function archiveForUser(int $id, int $userId): bool
    {
        $statement = $this->connection->prepare(
            'UPDATE notifications SET archived_at = COALESCE(archived_at, CURRENT_TIMESTAMP), read_at = COALESCE(read_at, CURRENT_TIMESTAMP)
             WHERE id = :id AND user_id = :user_id AND available_at <= CURRENT_TIMESTAMP AND cancelled_at IS NULL'
        );
        try {
            return $statement->execute([':id' => $id, ':user_id' => $userId]) && $statement->rowCount() > 0;
        } catch (PDOException $exception) {
            throw DatabaseOperationException::forOperation(self::class, 'archiveForUser', $exception);
        }
    }

    public function markAllAsReadForUser(int $userId): bool
    {
        $statement = $this->connection->prepare(
            'UPDATE notifications SET read_at = COALESCE(read_at, CURRENT_TIMESTAMP)
             WHERE user_id = :user_id AND available_at <= CURRENT_TIMESTAMP AND read_at IS NULL AND archived_at IS NULL AND cancelled_at IS NULL'
        );
        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);

        try {
            return $statement->execute();
        } catch (PDOException $exception) {
            throw DatabaseOperationException::forOperation(self::class, 'markAllAsReadForUser', $exception);
        }
    }

    public function existsForUser(int $id, int $userId): bool
    {
        $statement = $this->connection->prepare('SELECT COUNT(*) FROM notifications WHERE id = :id AND user_id = :user_id AND available_at <= CURRENT_TIMESTAMP AND cancelled_at IS NULL');
        $statement->bindValue(':id', $id, PDO::PARAM_INT);
        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);

        try {
            $statement->execute();
            return (int) $statement->fetchColumn() > 0;
        } catch (PDOException $exception) {
            throw DatabaseOperationException::forOperation(self::class, 'existsForUser', $exception);
        }
    }

    private function categoryForType(string $type): string
    {
        if (str_starts_with($type, 'work_policy')) return 'policy';
        if (str_starts_with($type, 'leave')) return 'leave';
        if (str_starts_with($type, 'overtime')) return 'overtime';
        if (str_starts_with($type, 'attendance') || str_contains($type, 'early_departure')) return 'attendance';
        if (str_contains($type, 'password') || str_contains($type, 'security') || str_contains($type, 'verification')) return 'security';
        return 'system';
    }

    private function normalizeCategory(string $category): string
    {
        return in_array($category, ['action', 'policy', 'attendance', 'absence', 'leave', 'overtime', 'security', 'system'], true)
            ? $category
            : 'system';
    }

    private function normalizeOptionalCategory(string $category): string
    {
        return in_array($category, ['action', 'policy', 'attendance', 'absence', 'leave', 'overtime', 'security', 'system'], true)
            ? $category
            : '';
    }

    private function normalizePriority(string $priority): string
    {
        return in_array($priority, ['urgent', 'high', 'normal', 'low'], true) ? $priority : 'normal';
    }

    private function normalizeOptionalPriority(string $priority): string
    {
        return in_array($priority, ['urgent', 'high', 'normal', 'low'], true) ? $priority : '';
    }
}
