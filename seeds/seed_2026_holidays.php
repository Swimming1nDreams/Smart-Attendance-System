<?php

declare(strict_types=1);

if ((string) getenv('SAPAS_ALLOW_DEMO_SEED') !== '1') {
    fwrite(STDERR, "Holiday seed blocked. Set SAPAS_ALLOW_DEMO_SEED=1 to run this dev-only script.\n");
    exit(1);
}

$root = dirname(__DIR__);
$config = require $root . '/config/database.php';
$holidays = require __DIR__ . '/data/holidays_2026.php';

$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['host'], $config['name'], $config['charset']),
    $config['user'],
    $config['pass'],
    $config['options'],
);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec("SET time_zone = " . $pdo->quote((string) ($config['timezone'] ?? '+06:30')));

$statement = $pdo->prepare(
    'INSERT INTO holidays (holiday_name, holiday_date, description)
     VALUES (:name, :date, :description)
     ON DUPLICATE KEY UPDATE holiday_name = VALUES(holiday_name), description = VALUES(description)'
);

$pdo->beginTransaction();
try {
    foreach ($holidays as $holiday) {
        $statement->execute([
            ':name' => $holiday['name'],
            ':date' => $holiday['date'],
            ':description' => $holiday['description'],
        ]);
    }
    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Holiday seed failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

printf("2026 holiday seed complete for database `%s`: %d dates upserted.\n", (string) $config['name'], count($holidays));
