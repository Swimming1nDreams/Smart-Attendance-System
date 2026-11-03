<?php

declare(strict_types=1);

namespace App\Models;

use PDO;
use PDOException;

class EmployeeProfile
{
    private PDO $connection;

    public function __construct(PDO $connection)
    {
        $this->connection = $connection;
    }

    public function findByUserId(int $userId): ?array
    {
        $statement = $this->connection->prepare('SELECT id, user_id, phone, address, profile_photo_filename, created_at, updated_at FROM employee_profiles WHERE user_id = :user_id LIMIT 1');
        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);

        try {
            $statement->execute();
        } catch (PDOException) {
            return null;
        }

        $profile = $statement->fetch();
        return $profile === false ? null : $profile;
    }

    public function create(int $userId, ?string $phone, ?string $address): bool
    {
        $statement = $this->connection->prepare('INSERT INTO employee_profiles (user_id, phone, address) VALUES (:user_id, :phone, :address)');
        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $statement->bindValue(':phone', $phone, $phone === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $statement->bindValue(':address', $address, $address === null ? PDO::PARAM_NULL : PDO::PARAM_STR);

        try {
            return $statement->execute();
        } catch (PDOException) {
            return false;
        }
    }

    public function update(int $userId, ?string $phone, ?string $address): bool
    {
        $statement = $this->connection->prepare('UPDATE employee_profiles SET phone = :phone, address = :address, updated_at = CURRENT_TIMESTAMP WHERE user_id = :user_id');
        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $statement->bindValue(':phone', $phone, $phone === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $statement->bindValue(':address', $address, $address === null ? PDO::PARAM_NULL : PDO::PARAM_STR);

        try {
            return $statement->execute();
        } catch (PDOException) {
            return false;
        }
    }

    public function save(int $userId, ?string $phone, ?string $address): bool
    {
        $statement = $this->connection->prepare(
            'INSERT INTO employee_profiles (user_id, phone, address)
             VALUES (:user_id, :phone, :address)
             ON DUPLICATE KEY UPDATE phone = VALUES(phone), address = VALUES(address), updated_at = CURRENT_TIMESTAMP'
        );
        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $statement->bindValue(':phone', $phone, $phone === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $statement->bindValue(':address', $address, $address === null ? PDO::PARAM_NULL : PDO::PARAM_STR);

        try {
            return $statement->execute();
        } catch (PDOException) {
            return false;
        }
    }

    public function updatePhotoFilename(int $userId, ?string $filename): bool
    {
        $statement = $this->connection->prepare(
            'INSERT INTO employee_profiles (user_id, profile_photo_filename)
             VALUES (:user_id, :profile_photo_filename)
             ON DUPLICATE KEY UPDATE profile_photo_filename = VALUES(profile_photo_filename), updated_at = CURRENT_TIMESTAMP'
        );
        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $statement->bindValue(':profile_photo_filename', $filename, $filename === null ? PDO::PARAM_NULL : PDO::PARAM_STR);

        try {
            return $statement->execute();
        } catch (PDOException) {
            return false;
        }
    }
}
