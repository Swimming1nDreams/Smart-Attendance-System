<?php

declare(strict_types=1);

namespace App\Models;

use PDO;
use PDOException;

class User
{
    private PDO $connection;

    public function __construct(PDO $connection)
    {
        $this->connection = $connection;
    }

    public function findByEmail(string $email): ?array
    {
        $sql = 'SELECT id, name, email, password, role, status, session_version, department_id, email_verified_at, email_verification_code_hash, email_verification_expires_at, email_verification_sent_at, email_verification_attempts, created_at FROM users WHERE email = :email LIMIT 1';
        $statement = $this->connection->prepare($sql);
        $statement->bindValue(':email', $email, PDO::PARAM_STR);

        try {
            $statement->execute();
        } catch (PDOException $exception) {
            return null;
        }

        $user = $statement->fetch();

        return $user === false ? null : $user;
    }

    public function findAuthenticationStateById(int $id): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, name, email, role, status, session_version, department_id FROM users WHERE id = :id LIMIT 1'
        );
        $statement->bindValue(':id', $id, PDO::PARAM_INT);

        try {
            $statement->execute();
        } catch (PDOException) {
            return null;
        }

        $user = $statement->fetch();

        return $user === false ? null : $user;
    }

    public function createVerificationCode(int $userId, int $expiresInSeconds = 900): ?array
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expiresAt = (new \DateTimeImmutable('now'))->modify('+' . $expiresInSeconds . ' seconds')->format('Y-m-d H:i:s');
        $sentAt = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $statement = $this->connection->prepare(
            'UPDATE users
             SET email_verification_token = NULL,
                 email_verification_code_hash = :code_hash,
                 email_verification_expires_at = :expires_at,
                 email_verification_sent_at = :sent_at,
                 email_verification_attempts = 0
             WHERE id = :id
               AND email_verified_at IS NULL'
        );
        $statement->bindValue(':code_hash', password_hash($code, PASSWORD_DEFAULT), PDO::PARAM_STR);
        $statement->bindValue(':expires_at', $expiresAt, PDO::PARAM_STR);
        $statement->bindValue(':sent_at', $sentAt, PDO::PARAM_STR);
        $statement->bindValue(':id', $userId, PDO::PARAM_INT);

        try {
            if (!$statement->execute() || $statement->rowCount() === 0) {
                return null;
            }
        } catch (PDOException) {
            return null;
        }

        return [
            'code' => $code,
            'expires_at' => $expiresAt,
        ];
    }

    public function verificationCodeMatches(array $user, string $code): bool
    {
        $hash = (string) ($user['email_verification_code_hash'] ?? '');

        return $hash !== '' && password_verify($code, $hash);
    }

    public function recordFailedVerificationAttempt(int $userId): int
    {
        $statement = $this->connection->prepare(
            'UPDATE users
             SET email_verification_attempts = LEAST(email_verification_attempts + 1, 255)
             WHERE id = :id
               AND email_verified_at IS NULL'
        );
        $statement->bindValue(':id', $userId, PDO::PARAM_INT);

        try {
            if (!$statement->execute()) {
                return 0;
            }

            $lookup = $this->connection->prepare('SELECT email_verification_attempts FROM users WHERE id = :id');
            $lookup->bindValue(':id', $userId, PDO::PARAM_INT);
            $lookup->execute();
            return (int) $lookup->fetchColumn();
        } catch (PDOException) {
            return 0;
        }
    }

    public function markEmailVerified(int $userId): bool
    {
        $statement = $this->connection->prepare(
            'UPDATE users
             SET email_verified_at = :verified_at,
                 email_verification_token = NULL,
                 email_verification_code_hash = NULL,
                 email_verification_expires_at = NULL,
                 email_verification_sent_at = NULL,
                 email_verification_attempts = 0
             WHERE id = :id
               AND email_verified_at IS NULL'
        );
        $statement->bindValue(':verified_at', (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s'), PDO::PARAM_STR);
        $statement->bindValue(':id', $userId, PDO::PARAM_INT);

        try {
            return $statement->execute() && $statement->rowCount() > 0;
        } catch (PDOException) {
            return false;
        }
    }

    public function hasRecentVerificationSend(array $user, int $cooldownSeconds = 600): bool
    {
        $sentAt = (string) ($user['email_verification_sent_at'] ?? '');

        if ($sentAt === '') {
            return false;
        }

        $sentTimestamp = strtotime($sentAt);

        return $sentTimestamp !== false && ($sentTimestamp + $cooldownSeconds) > time();
    }

    public function clearVerificationCode(int $userId): bool
    {
        $statement = $this->connection->prepare(
            'UPDATE users
             SET email_verification_code_hash = NULL,
                 email_verification_expires_at = NULL,
                 email_verification_sent_at = NULL,
                 email_verification_attempts = 0
             WHERE id = :id
               AND email_verified_at IS NULL'
        );
        $statement->bindValue(':id', $userId, PDO::PARAM_INT);

        try {
            return $statement->execute();
        } catch (PDOException) {
            return false;
        }
    }

    public function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

}
