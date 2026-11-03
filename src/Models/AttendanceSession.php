<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\DatabaseOperationException;
use PDO;
use PDOException;

final class AttendanceSession
{
    public function __construct(private PDO $connection)
    {
    }

    /** @return list<array<string, mixed>> */
    public function findByAttendanceId(int $attendanceId, bool $forUpdate = false): array
    {
        $sql = 'SELECT id, attendance_id, check_in_at, check_out_at, created_at, updated_at
                FROM attendance_sessions
                WHERE attendance_id = :attendance_id
                ORDER BY check_in_at ASC, id ASC';
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        $statement = $this->connection->prepare($sql);
        $statement->bindValue(':attendance_id', $attendanceId, PDO::PARAM_INT);

        try {
            $statement->execute();
            return $statement->fetchAll();
        } catch (PDOException $exception) {
            throw DatabaseOperationException::forOperation(self::class, 'findByAttendanceId', $exception);
        }
    }

    /**
     * @param list<int> $attendanceIds
     * @return array<int, list<array<string, mixed>>>
     */
    public function findByAttendanceIds(array $attendanceIds): array
    {
        $attendanceIds = array_values(array_unique(array_filter(array_map('intval', $attendanceIds), static fn (int $id): bool => $id > 0)));
        if ($attendanceIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($attendanceIds), '?'));
        $statement = $this->connection->prepare(
            'SELECT id, attendance_id, check_in_at, check_out_at, created_at, updated_at
             FROM attendance_sessions
             WHERE attendance_id IN (' . $placeholders . ')
             ORDER BY attendance_id ASC, check_in_at ASC, id ASC'
        );

        try {
            $statement->execute($attendanceIds);
            $grouped = [];
            foreach ($statement->fetchAll() as $row) {
                $grouped[(int) $row['attendance_id']][] = $row;
            }
            return $grouped;
        } catch (PDOException $exception) {
            throw DatabaseOperationException::forOperation(self::class, 'findByAttendanceIds', $exception);
        }
    }

    public function findOpenByAttendanceId(int $attendanceId, bool $forUpdate = false): ?array
    {
        $sql = 'SELECT id, attendance_id, check_in_at, check_out_at, created_at, updated_at
                FROM attendance_sessions
                WHERE attendance_id = :attendance_id AND check_out_at IS NULL
                ORDER BY check_in_at DESC, id DESC
                LIMIT 1';
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }

        $statement = $this->connection->prepare($sql);
        $statement->bindValue(':attendance_id', $attendanceId, PDO::PARAM_INT);

        try {
            $statement->execute();
            $session = $statement->fetch();
            return $session === false ? null : $session;
        } catch (PDOException $exception) {
            throw DatabaseOperationException::forOperation(self::class, 'findOpenByAttendanceId', $exception);
        }
    }

    public function findOpenForEmployee(int $userId, bool $forUpdate = false): ?array
    {
        $sql = 'SELECT s.id, s.attendance_id, s.check_in_at, s.check_out_at, s.created_at, s.updated_at,
                       ar.user_id, ar.attendance_date
                FROM attendance_sessions s
                JOIN attendance_records ar ON ar.id = s.attendance_id
                WHERE ar.user_id = :user_id AND s.check_out_at IS NULL
                ORDER BY s.check_in_at DESC, s.id DESC
                LIMIT 1';
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }

        $statement = $this->connection->prepare($sql);
        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);

        try {
            $statement->execute();
            $session = $statement->fetch();
            return $session === false ? null : $session;
        } catch (PDOException $exception) {
            throw DatabaseOperationException::forOperation(self::class, 'findOpenForEmployee', $exception);
        }
    }

    public function hasOpenForEmployee(int $userId): bool
    {
        return $this->findOpenForEmployee($userId) !== null;
    }

    public function create(int $attendanceId, string $checkInAt): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO attendance_sessions (attendance_id, check_in_at)
             VALUES (:attendance_id, :check_in_at)'
        );
        $statement->bindValue(':attendance_id', $attendanceId, PDO::PARAM_INT);
        $statement->bindValue(':check_in_at', $checkInAt, PDO::PARAM_STR);

        try {
            $statement->execute();
            return (int) $this->connection->lastInsertId();
        } catch (PDOException $exception) {
            throw DatabaseOperationException::forOperation(self::class, 'create', $exception);
        }
    }

    public function createCompleted(int $attendanceId, string $checkInAt, string $checkOutAt): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO attendance_sessions (attendance_id, check_in_at, check_out_at)
             VALUES (:attendance_id, :check_in_at, :check_out_at)'
        );
        $statement->bindValue(':attendance_id', $attendanceId, PDO::PARAM_INT);
        $statement->bindValue(':check_in_at', $checkInAt, PDO::PARAM_STR);
        $statement->bindValue(':check_out_at', $checkOutAt, PDO::PARAM_STR);

        try {
            $statement->execute();
            return (int) $this->connection->lastInsertId();
        } catch (PDOException $exception) {
            throw DatabaseOperationException::forOperation(self::class, 'createCompleted', $exception);
        }
    }

    public function close(int $sessionId, string $checkOutAt): bool
    {
        $statement = $this->connection->prepare(
            'UPDATE attendance_sessions
             SET check_out_at = :check_out_at, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND check_out_at IS NULL'
        );
        $statement->bindValue(':check_out_at', $checkOutAt, PDO::PARAM_STR);
        $statement->bindValue(':id', $sessionId, PDO::PARAM_INT);

        try {
            $statement->execute();
            return $statement->rowCount() === 1;
        } catch (PDOException $exception) {
            throw DatabaseOperationException::forOperation(self::class, 'close', $exception);
        }
    }

    public function countByAttendanceId(int $attendanceId): int
    {
        $statement = $this->connection->prepare(
            'SELECT COUNT(*) FROM attendance_sessions WHERE attendance_id = :attendance_id'
        );
        $statement->bindValue(':attendance_id', $attendanceId, PDO::PARAM_INT);

        try {
            $statement->execute();
            return (int) $statement->fetchColumn();
        } catch (PDOException $exception) {
            throw DatabaseOperationException::forOperation(self::class, 'countByAttendanceId', $exception);
        }
    }
}
