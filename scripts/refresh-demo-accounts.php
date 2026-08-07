<?php

declare(strict_types=1);

use App\Autoloader;
use App\Config\Database;
use App\Services\AuditLogService;
use App\Services\DemoAccountRefreshService;
use App\Services\PasswordPolicyService;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require $root . '/src/Autoloader.php';
Autoloader::register();

$arguments = array_slice($argv, 1);
$unknown = array_values(array_diff($arguments, ['--dry-run']));
if ($unknown !== []) {
    fwrite(STDERR, 'Unknown option: ' . implode(', ', $unknown) . PHP_EOL);
    exit(1);
}
$dryRun = in_array('--dry-run', $arguments, true);
$settings = require $root . '/config/database.php';
$pdo = (new Database($settings))->getConnection();
$service = new DemoAccountRefreshService($pdo, new PasswordPolicyService(), new AuditLogService($pdo));

try {
    if ($dryRun) {
        $accounts = $service->preview();
        echo 'DRY RUN - no database or file changes were made.' . PHP_EOL;
        echo 'Demo email domain: ' . $service->domain() . PHP_EOL . PHP_EOL;
        echo $service->credentialText($accounts);
        exit(0);
    }
    $result = $service->apply($root . '/storage/private');
    echo $service->credentialText($result['accounts']);
    echo 'Private credential file: ' . $result['credential_path'] . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, 'Demo account refresh failed and was rolled back: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
