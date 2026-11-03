<?php

declare(strict_types=1);

use App\Autoloader;
use App\Services\WorkScheduleAssignmentService;

require __DIR__ . '/../src/Autoloader.php';
Autoloader::register();

$settings = require __DIR__ . '/../config/database.php';
$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', $settings['host'], $settings['name'], $settings['charset']),
    $settings['user'],
    $settings['pass'],
    $settings['options'],
);

$result = (new WorkScheduleAssignmentService($pdo))->reconcileUpcomingNotifications();

echo sprintf(
    "Policy notification reconciliation complete: %d assignment(s), %d recipient(s).%s",
    $result['assignments'],
    $result['recipients'],
    PHP_EOL,
);
