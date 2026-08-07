<?php

declare(strict_types=1);

namespace App\Models;

use PDO;
use PDOException;

class Notification
{
    private PDO $connection;

    public function __construct(PDO $connection)
    {
        $this->connection = $connection;
    }

    public function create(int $userId, string $type, string $titleKey, string $messageKey, ?string $linkUrl = null, ?int $relatedUserId = null, ?int $relatedLeaveRequestId = null): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO notifications (user_id, type, title_key, message_key, link_url, related_user_id, related_leave_request_id) VALUES (:user_id, :type, :title_key, :message_key, :link_url, :related_user_id, :related_leave_request_id)'
        );
        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $statement->bindValue(':type', $type, PDO::PARAM_STR);
        $statement->bindValue(':title_key', $titleKey, PDO::PARAM_STR);
        $statement->bindValue(':message_key', $messageKey, PDO::PARAM_STR);
        $statement->bindValue(':link_url', $linkUrl, $linkUrl === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $statement->bindValue(':related_user_id', $relatedUserId, $relatedUserId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $statement->bindValue(':related_leave_request_id', $relatedLeaveRequestId, $relatedLeaveRequestId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);

        try {
            $statement->execute();
            return (int) $this->connection->lastInsertId();
        } catch (PDOException) {
            return 0;
        }
    }

    /**
     * @param list<int> $userIds
     */
    public function createForUsers(array $userIds, string $type, string $titleKey, string $messageKey, ?string $linkUrl = null, ?int $relatedUserId = null, ?int $relatedLeaveRequestId = null): void
    {
        $uniqueUserIds = array_values(array_unique(array_filter($userIds, static fn (int $userId): bool => $userId > 0)));

        foreach ($uniqueUserIds as $userId) {
            $this->create($userId, $type, $titleKey, $messageKey, $linkUrl, $relatedUserId, $relatedLeaveRequestId);
        }
    }

    public function countUnreadForUser(int $userId): int
    {
        $statement = $this->connection->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = :user_id AND read_at IS NULL');
        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);

        try {
            $statement->execute();
            return (int) $statement->fetchColumn();
        } catch (PDOException) {
            return 0;
        }
    }

    public function findForUser(int $userId, int $limit = 50): array
    {
        $statement = $this->connection->prepare(
            'SELECT id, user_id, type, title_key, message_key, link_url, related_user_id, related_leave_request_id, read_at, created_at FROM notifications WHERE user_id = :user_id ORDER BY created_at DESC, id DESC LIMIT :limit'
        );
        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $statement->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);

        try {
            $statement->execute();
            return $statement->fetchAll();
        } catch (PDOException) {
            return [];
        }
    }

    public function markAsRead(int $id, int $userId): bool
    {
        if (!$this->existsForUser($id, $userId)) {
            return false;
        }

        $statement = $this->connection->prepare(
            'UPDATE notifications SET read_at = CURRENT_TIMESTAMP WHERE id = :id AND user_id = :user_id AND read_at IS NULL'
        );
        $statement->bindValue(':id', $id, PDO::PARAM_INT);
        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);

        try {
            return $statement->execute();
        } catch (PDOException) {
            return false;
        }
    }

    public function markAllAsReadForUser(int $userId): bool
    {
        $statement = $this->connection->prepare(
            'UPDATE notifications SET read_at = COALESCE(read_at, CURRENT_TIMESTAMP) WHERE user_id = :user_id AND read_at IS NULL'
        );
        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);

        try {
            return $statement->execute();
        } catch (PDOException) {
            return false;
        }
    }

    public function existsForUser(int $id, int $userId): bool
    {
        $statement = $this->connection->prepare('SELECT COUNT(*) FROM notifications WHERE id = :id AND user_id = :user_id');
        $statement->bindValue(':id', $id, PDO::PARAM_INT);
        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);

        try {
            $statement->execute();
            return (int) $statement->fetchColumn() > 0;
        } catch (PDOException) {
            return false;
        }
    }
}
