<?php

declare(strict_types=1);

namespace App\Models;

use PDO;
use PDOException;

class Department
{
    private PDO $connection;

    public function __construct(PDO $connection)
    {
        $this->connection = $connection;
    }

    public function findAll(string $search = ''): array
    {
        $sql = 'SELECT id, name, description FROM departments';

        if ($search !== '') {
            $sql .= ' WHERE name REGEXP :name_search OR description REGEXP :description_search';
        }

        $sql .= ' ORDER BY name ASC';
        $statement = $this->connection->prepare($sql);

        if ($search !== '') {
            // Match the start of a word, not arbitrary text inside another word (for example IT in Quality).
            $searchPattern = '(^|[^[:alnum:]_])' . preg_quote($search, '/');
            $statement->bindValue(':name_search', $searchPattern, PDO::PARAM_STR);
            $statement->bindValue(':description_search', $searchPattern, PDO::PARAM_STR);
        }

        $statement->execute();

        return $statement->fetchAll();
    }

    public function findById(int $id): ?array
    {
        $statement = $this->connection->prepare('SELECT id, name, description FROM departments WHERE id = :id LIMIT 1');
        $statement->bindValue(':id', $id, PDO::PARAM_INT);
        $statement->execute();

        $department = $statement->fetch();

        return $department === false ? null : $department;
    }

    public function existsByName(string $name, ?int $excludeId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM departments WHERE name = :name';

        if ($excludeId !== null) {
            $sql .= ' AND id != :id';
        }

        $statement = $this->connection->prepare($sql);
        $statement->bindValue(':name', $name, PDO::PARAM_STR);

        if ($excludeId !== null) {
            $statement->bindValue(':id', $excludeId, PDO::PARAM_INT);
        }

        $statement->execute();

        return (int) $statement->fetchColumn() > 0;
    }

    public function create(string $name, string $description): bool
    {
        $statement = $this->connection->prepare('INSERT INTO departments (name, description) VALUES (:name, :description)');
        $statement->bindValue(':name', $name, PDO::PARAM_STR);
        $statement->bindValue(':description', $description, PDO::PARAM_STR);

        return $statement->execute();
    }

    public function update(int $id, string $name, string $description): bool
    {
        $statement = $this->connection->prepare('UPDATE departments SET name = :name, description = :description WHERE id = :id');
        $statement->bindValue(':name', $name, PDO::PARAM_STR);
        $statement->bindValue(':description', $description, PDO::PARAM_STR);
        $statement->bindValue(':id', $id, PDO::PARAM_INT);

        return $statement->execute();
    }

    public function delete(int $id): bool
    {
        $statement = $this->connection->prepare('DELETE FROM departments WHERE id = :id');
        $statement->bindValue(':id', $id, PDO::PARAM_INT);

        return $statement->execute();
    }

    public function countAssignedUsers(int $id): int
    {
        try {
            $statement = $this->connection->prepare('SELECT COUNT(*) FROM users WHERE department_id = :id');
            $statement->bindValue(':id', $id, PDO::PARAM_INT);
            $statement->execute();

            return (int) $statement->fetchColumn();
        } catch (PDOException $exception) {
            return 0;
        }
    }
}
