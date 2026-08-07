<?php

declare(strict_types=1);

use App\Autoloader;

$root = dirname(__DIR__);

$composerAutoload = $root . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require_once $composerAutoload;
}

require_once $root . '/src/Autoloader.php';
Autoloader::register();

$sessionPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sapas_test_sessions';
if (!is_dir($sessionPath)) {
    mkdir($sessionPath, 0777, true);
}

ini_set('session.save_path', $sessionPath);
ini_set('session.use_cookies', '0');
ini_set('session.cache_limiter', '');

function project_path(string $path = ''): string
{
    $root = dirname(__DIR__);
    $path = ltrim($path, '/\\');

    return $path === '' ? $root : $root . DIRECTORY_SEPARATOR . $path;
}

function reset_test_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION = [];
        session_destroy();
    }

    if (headers_sent()) {
        $_SESSION = [];
        return;
    }

    session_id('sapastest-' . bin2hex(random_bytes(8)));
    $_SESSION = [];
}
