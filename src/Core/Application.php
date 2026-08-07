<?php

declare(strict_types=1);

namespace App\Core;

use App\Config\Database;

class Application
{
    private array $config;
    private Database $database;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->database = Database::create($config['db']);
    }

    public function getConfig(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    public function getDatabase(): Database
    {
        return $this->database;
    }
}
