<?php

declare(strict_types=1);

final class QueryCountingPDO extends PDO
{
    private int $queryCount = 0;

    public function __construct(string $dsn, ?string $username = null, ?string $password = null, array $options = [])
    {
        parent::__construct($dsn, $username, $password, $options);
        $this->setAttribute(PDO::ATTR_STATEMENT_CLASS, [QueryCountingPDOStatement::class, [$this]]);
    }

    public function resetQueryCount(): void
    {
        $this->queryCount = 0;
    }

    public function queryCount(): int
    {
        return $this->queryCount;
    }

    public function incrementQueryCount(): void
    {
        $this->queryCount++;
    }

    public function exec(string $statement): int|false
    {
        $this->incrementQueryCount();

        return parent::exec($statement);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $this->incrementQueryCount();

        if ($fetchMode === null) {
            return parent::query($query);
        }

        return parent::query($query, $fetchMode, ...$fetchModeArgs);
    }
}

final class QueryCountingPDOStatement extends PDOStatement
{
    private QueryCountingPDO $connection;

    protected function __construct(QueryCountingPDO $connection)
    {
        $this->connection = $connection;
    }

    public function execute(?array $params = null): bool
    {
        $this->connection->incrementQueryCount();

        return $params === null ? parent::execute() : parent::execute($params);
    }
}
