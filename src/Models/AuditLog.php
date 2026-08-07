<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

final class AuditLog
{
    public function __construct(private PDO $connection)
    {
    }

    /** @param array<string, mixed> $filters */
    public function findAll(array $filters, int $page = 1, int $perPage = 20): array
    {
        [$where, $params] = $this->whereClause($filters);
        $sql = 'SELECT al.*, u.name AS actor_name, u.staff_code AS actor_staff_code
                FROM audit_logs al
                LEFT JOIN users u ON u.id = al.actor_user_id' . $where . '
                ORDER BY al.created_at DESC, al.id DESC LIMIT :limit OFFSET :offset';
        $statement = $this->connection->prepare($sql);
        $this->bind($statement, $params);
        $statement->bindValue(':limit', max(1, $perPage), PDO::PARAM_INT);
        $statement->bindValue(':offset', max(0, ($page - 1) * $perPage), PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    /** @param array<string, mixed> $filters */
    public function countAll(array $filters): int
    {
        [$where, $params] = $this->whereClause($filters);
        $statement = $this->connection->prepare('SELECT COUNT(*) FROM audit_logs al LEFT JOIN users u ON u.id = al.actor_user_id' . $where);
        $this->bind($statement, $params);
        $statement->execute();

        return (int) $statement->fetchColumn();
    }

    public function findById(int $id): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT al.*, u.name AS actor_name, u.staff_code AS actor_staff_code
             FROM audit_logs al LEFT JOIN users u ON u.id = al.actor_user_id
             WHERE al.id = :id LIMIT 1'
        );
        $statement->execute([':id' => $id]);
        $record = $statement->fetch();

        return $record === false ? null : $record;
    }

    public function distinctActions(): array
    {
        return $this->connection->query('SELECT DISTINCT action FROM audit_logs ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);
    }

    public function distinctEntityTypes(): array
    {
        return $this->connection->query('SELECT DISTINCT entity_type FROM audit_logs ORDER BY entity_type')->fetchAll(PDO::FETCH_COLUMN);
    }

    /** @param array<string, mixed> $filters @return array{0: string, 1: array<string, mixed>} */
    private function whereClause(array $filters): array
    {
        $conditions = [];
        $params = [];
        foreach ([
            'actor_user_id' => ['al.actor_user_id = :actor_user_id', PDO::PARAM_INT],
            'action' => ['al.action = :action', PDO::PARAM_STR],
            'entity_type' => ['al.entity_type = :entity_type', PDO::PARAM_STR],
            'entity_id' => ['al.entity_id = :entity_id', PDO::PARAM_STR],
        ] as $key => [$condition, $type]) {
            if (($filters[$key] ?? null) !== null && $filters[$key] !== '') {
                $conditions[] = $condition;
                $params[':' . $key] = [$filters[$key], $type];
            }
        }
        if (($filters['date_from'] ?? null) !== null) {
            $conditions[] = 'al.created_at >= :date_from';
            $params[':date_from'] = [$filters['date_from'] . ' 00:00:00', PDO::PARAM_STR];
        }
        if (($filters['date_to'] ?? null) !== null) {
            $conditions[] = 'al.created_at <= :date_to';
            $params[':date_to'] = [$filters['date_to'] . ' 23:59:59', PDO::PARAM_STR];
        }
        if (($filters['search'] ?? '') !== '') {
            $conditions[] = '(al.description LIKE :search_description OR al.entity_id LIKE :search_entity OR u.name LIKE :search_actor)';
            $pattern = '%' . $filters['search'] . '%';
            $params[':search_description'] = [$pattern, PDO::PARAM_STR];
            $params[':search_entity'] = [$pattern, PDO::PARAM_STR];
            $params[':search_actor'] = [$pattern, PDO::PARAM_STR];
        }

        return [$conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions), $params];
    }

    /** @param array<string, array{0: mixed, 1: int}> $params */
    private function bind(\PDOStatement $statement, array $params): void
    {
        foreach ($params as $key => [$value, $type]) {
            $statement->bindValue($key, $value, $type);
        }
    }
}
