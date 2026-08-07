<?php

declare(strict_types=1);

require __DIR__ . '/MigrationRunner.php';

$dbConfig = require __DIR__ . '/../config/database.php';

try {
    $runner = new MigrationRunner($dbConfig, __DIR__);
    $runner->run();
    echo "Migrations complete." . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, "Migration failed: " . $exception->getMessage() . PHP_EOL);
    exit(1);
}
