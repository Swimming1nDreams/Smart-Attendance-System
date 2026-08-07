<?php

declare(strict_types=1);

namespace App\Config;

use PDO;
use PDOException;

class Database
{
    private PDO $connection;

    public function __construct(array $settings)
    {
        $this->validateSettings($settings);

        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=%s',
            $settings['host'],
            $settings['name'],
            $settings['charset'],
        );

        try {
            $this->connection = new PDO(
                $dsn,
                $settings['user'],
                $settings['pass'],
                $settings['options'],
            );
            $this->configureSessionTimezone((string) $settings['timezone']);
        } catch (PDOException $exception) {
            throw new \RuntimeException('Database connection failed.', 0, $exception);
        }
    }

    public function getConnection(): PDO
    {
        return $this->connection;
    }

    public static function create(array $settings): self
    {
        return new self($settings);
    }


    private function configureSessionTimezone(string $timezone): void
    {
        $this->connection->exec("SET time_zone = '" . $timezone . "'");
    }

    private function validateSettings(array $settings): void
    {
        $requiredKeys = ['host', 'name', 'user', 'pass', 'charset', 'timezone', 'options'];

        foreach ($requiredKeys as $key) {
            if (!array_key_exists($key, $settings)) {
                throw new \InvalidArgumentException(sprintf('Missing database setting: %s', $key));
            }
        }

        if (!is_string($settings['timezone']) || preg_match('/^[+-](?:0\d|1[0-4]):[0-5]\d$/', $settings['timezone']) !== 1) {
            throw new \InvalidArgumentException('Invalid database timezone offset configuration.');
        }
    }
}
