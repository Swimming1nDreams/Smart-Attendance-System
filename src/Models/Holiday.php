<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

class Holiday
{
    private PDO $connection;

    public function __construct(PDO $connection)
    {
        $this->connection = $connection;
    }

    public function findAll(string $search = ''): array
    {
        $sql = 'SELECT id, holiday_name, holiday_date, description FROM holidays';

        if ($search !== '') {
            $sql .= ' WHERE holiday_name LIKE :search_name OR holiday_date LIKE :search_date OR description LIKE :search_description';
        }

        $sql .= ' ORDER BY holiday_date ASC';
        $statement = $this->connection->prepare($sql);

        if ($search !== '') {
            $searchPattern = '%' . $search . '%';
            $statement->bindValue(':search_name', $searchPattern, PDO::PARAM_STR);
            $statement->bindValue(':search_date', $searchPattern, PDO::PARAM_STR);
            $statement->bindValue(':search_description', $searchPattern, PDO::PARAM_STR);
        }

        $statement->execute();

        return $statement->fetchAll();
    }

    public function findById(int $id): ?array
    {
        $statement = $this->connection->prepare('SELECT id, holiday_name, holiday_date, description FROM holidays WHERE id = :id LIMIT 1');
        $statement->bindValue(':id', $id, PDO::PARAM_INT);
        $statement->execute();

        $holiday = $statement->fetch();

        return $holiday === false ? null : $holiday;
    }

    public function existsByDate(string $date, ?int $excludeId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM holidays WHERE holiday_date = :date';

        if ($excludeId !== null) {
            $sql .= ' AND id != :id';
        }

        $statement = $this->connection->prepare($sql);
        $statement->bindValue(':date', $date, PDO::PARAM_STR);

        if ($excludeId !== null) {
            $statement->bindValue(':id', $excludeId, PDO::PARAM_INT);
        }

        $statement->execute();

        return (int) $statement->fetchColumn() > 0;
    }

    public function create(string $name, string $date, string $description): bool
    {
        $statement = $this->connection->prepare('INSERT INTO holidays (holiday_name, holiday_date, description) VALUES (:name, :date, :description)');
        $statement->bindValue(':name', $name, PDO::PARAM_STR);
        $statement->bindValue(':date', $date, PDO::PARAM_STR);
        $statement->bindValue(':description', $description, PDO::PARAM_STR);

        return $statement->execute();
    }

    public function update(int $id, string $name, string $date, string $description): bool
    {
        $statement = $this->connection->prepare('UPDATE holidays SET holiday_name = :name, holiday_date = :date, description = :description WHERE id = :id');
        $statement->bindValue(':name', $name, PDO::PARAM_STR);
        $statement->bindValue(':date', $date, PDO::PARAM_STR);
        $statement->bindValue(':description', $description, PDO::PARAM_STR);
        $statement->bindValue(':id', $id, PDO::PARAM_INT);

        return $statement->execute();
    }

    public function delete(int $id): bool
    {
        $statement = $this->connection->prepare('DELETE FROM holidays WHERE id = :id');
        $statement->bindValue(':id', $id, PDO::PARAM_INT);

        return $statement->execute();
    }

    public function findInRange(string $fromDate, string $toDate): array
    {
        $statement = $this->connection->prepare(
            'SELECT id, holiday_name, holiday_date, description
             FROM holidays WHERE holiday_date BETWEEN :from_date AND :to_date ORDER BY holiday_date ASC'
        );
        $statement->execute([':from_date' => $fromDate, ':to_date' => $toDate]);

        return $statement->fetchAll();
    }

    public function countInRange(string $fromDate, string $toDate): int
    {
        $statement = $this->connection->prepare(
            'SELECT COUNT(*) FROM holidays WHERE holiday_date >= :from_date AND holiday_date <= :to_date'
        );
        $statement->bindValue(':from_date', $fromDate, PDO::PARAM_STR);
        $statement->bindValue(':to_date', $toDate, PDO::PARAM_STR);

        try {
            $statement->execute();
            return (int) $statement->fetchColumn();
        } catch (\PDOException) {
            return 0;
        }
    }

    public function findUpcoming(string $fromDate, int $limit = 3): array
    {
        $statement = $this->connection->prepare(
            'SELECT id, holiday_name, holiday_date, description
             FROM holidays
             WHERE holiday_date >= :from_date
             ORDER BY holiday_date ASC
             LIMIT :limit'
        );
        $statement->bindValue(':from_date', $fromDate, PDO::PARAM_STR);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);

        try {
            $statement->execute();
            return $statement->fetchAll();
        } catch (\PDOException) {
            return [];
        }
    }
}
