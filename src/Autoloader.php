<?php

declare(strict_types=1);

namespace App;

class Autoloader
{
    public static function register(): void
    {
        spl_autoload_register(static function (string $class): void {
            $prefix = 'App\\';

            if (!str_starts_with($class, $prefix)) {
                return;
            }

            $relativeClass = substr($class, strlen($prefix));
            $file = __DIR__ . '/' . str_replace('\\', '/', $relativeClass) . '.php';

            if (is_file($file)) {
                require $file;
            }
        });
    }
}
