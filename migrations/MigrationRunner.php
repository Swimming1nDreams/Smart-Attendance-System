<?php

declare(strict_types=1);

class MigrationRunner
{
    private PDO $connection;
    private string $migrationsPath;

    public function __construct(array $dbConfig, string $migrationsPath)
    {
        $this->validateDbConfig($dbConfig);

        $this->migrationsPath = rtrim($migrationsPath, DIRECTORY_SEPARATOR);
        $this->connection = new PDO(
            sprintf('mysql:host=%s;dbname=%s;charset=%s', $dbConfig['host'], $dbConfig['name'], $dbConfig['charset']),
            $dbConfig['user'],
            $dbConfig['pass'],
            $dbConfig['options'],
        );
        $this->connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->connection->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    public function run(): void
    {
        $this->ensureMigrationsTable();

        $migrationFiles = $this->loadMigrationFiles();

        foreach ($migrationFiles as $filePath) {
            $version = $this->resolveVersionFromFile($filePath);
            $description = $this->resolveDescriptionFromFile($filePath);

            if ($this->isMigrationApplied($version)) {
                continue;
            }

            $sql = file_get_contents($filePath);
            if ($sql === false) {
                throw new RuntimeException(sprintf('Unable to read migration file: %s', $filePath));
            }

            foreach ($this->splitSqlStatements($sql) as $statement) {
                if ($statement === '') {
                    continue;
                }

                $this->connection->exec($statement);
            }

            $this->recordMigration($version, $description);
            printf("Applied migration %s: %s\n", $version, $description);
        }
    }

    private function ensureMigrationsTable(): void
    {
        $sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS migrations (
    version VARCHAR(100) NOT NULL PRIMARY KEY,
    description VARCHAR(255) NOT NULL,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
SQL;

        $this->connection->exec($sql);
    }

    private function loadMigrationFiles(): array
    {
        $pattern = $this->migrationsPath . DIRECTORY_SEPARATOR . '*.sql';
        $files = glob($pattern);

        if ($files === false) {
            return [];
        }

        sort($files, SORT_STRING);

        return $files;
    }

    private function resolveVersionFromFile(string $filePath): string
    {
        return pathinfo($filePath, PATHINFO_FILENAME);
    }

    private function resolveDescriptionFromFile(string $filePath): string
    {
        $fileName = pathinfo($filePath, PATHINFO_FILENAME);
        $parts = explode('__', $fileName, 2);

        return $parts[1] ?? $fileName;
    }

    private function isMigrationApplied(string $version): bool
    {
        $statement = $this->connection->prepare('SELECT COUNT(*) FROM migrations WHERE version = :version');
        $statement->bindValue(':version', $version, PDO::PARAM_STR);
        $statement->execute();

        return (int) $statement->fetchColumn() > 0;
    }

    private function recordMigration(string $version, string $description): void
    {
        $statement = $this->connection->prepare('INSERT INTO migrations (version, description) VALUES (:version, :description)');
        $statement->bindValue(':version', $version, PDO::PARAM_STR);
        $statement->bindValue(':description', $description, PDO::PARAM_STR);
        $statement->execute();
    }

    private function splitSqlStatements(string $sql): array
    {
        $lines = preg_split('/\R/', $sql);
        $statements = [];
        $buffer = '';

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '--') || str_starts_with($trimmed, '#')) {
                continue;
            }

            $buffer .= $line . "\n";

            if (str_ends_with($trimmed, ';')) {
                $statements[] = trim(substr($buffer, 0, -1));
                $buffer = '';
            }
        }

        if (trim($buffer) !== '') {
            $statements[] = trim($buffer);
        }

        return $statements;
    }

    private function validateDbConfig(array $dbConfig): void
    {
        $requiredKeys = ['host', 'name', 'user', 'pass', 'charset', 'options'];

        foreach ($requiredKeys as $key) {
            if (!array_key_exists($key, $dbConfig)) {
                throw new InvalidArgumentException(sprintf('Missing database configuration key: %s', $key));
            }
        }
    }
}
