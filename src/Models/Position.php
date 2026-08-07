<?php

declare(strict_types=1);

namespace App\Models;

use PDO;
use PDOException;

class Position
{
    private PDO $connection;

    public function __construct(PDO $connection)
    {
        $this->connection = $connection;
    }

    public function findAll(string $search = ''): array
    {
        $sql = 'SELECT id, name FROM positions';

        if ($search !== '') {
            $sql .= ' WHERE name LIKE :search';
        }

        $sql .= ' ORDER BY name ASC';
        $statement = $this->connection->prepare($sql);

        if ($search !== '') {
            $statement->bindValue(':search', '%' . $search . '%', PDO::PARAM_STR);
        }

        $statement->execute();

        return $statement->fetchAll();
    }

    public function findById(int $id): ?array
    {
        $statement = $this->connection->prepare('SELECT id, name FROM positions WHERE id = :id LIMIT 1');
        $statement->bindValue(':id', $id, PDO::PARAM_INT);
        $statement->execute();

        $position = $statement->fetch();

        return $position === false ? null : $position;
    }

    public function existsByName(string $name, ?int $excludeId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM positions WHERE name = :name';

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

    public function create(string $name): bool
    {
        $statement = $this->connection->prepare('INSERT INTO positions (name) VALUES (:name)');
        $statement->bindValue(':name', $name, PDO::PARAM_STR);

        return $statement->execute();
    }

    public function update(int $id, string $name): bool
    {
        $statement = $this->connection->prepare('UPDATE positions SET name = :name WHERE id = :id');
        $statement->bindValue(':name', $name, PDO::PARAM_STR);
        $statement->bindValue(':id', $id, PDO::PARAM_INT);

        return $statement->execute();
    }

    public function delete(int $id): bool
    {
        $statement = $this->connection->prepare('DELETE FROM positions WHERE id = :id');
        $statement->bindValue(':id', $id, PDO::PARAM_INT);

        return $statement->execute();
    }

    public function countAssignedUsers(int $id): int
    {
        try {
            $statement = $this->connection->prepare('SELECT COUNT(*) FROM users WHERE position_id = :id');
            $statement->bindValue(':id', $id, PDO::PARAM_INT);
            $statement->execute();

            return (int) $statement->fetchColumn();
        } catch (PDOException $exception) {
            return 0;
        }
    }
}
