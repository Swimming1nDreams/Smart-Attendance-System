<?php

declare(strict_types=1);

namespace App\Core;

class Router
{
    private array $routes = [];

    public function addRoute(string $method, string $path, callable $handler): void
    {
        $method = strtoupper($method);
        $path = $this->normalizePath($path);

        $this->routes[$method][$path] = $handler;
    }

    public function dispatch(Request $request): void
    {
        $method = $request->getMethod();
        $path = $request->getPath();

        if (!isset($this->routes[$method][$path])) {
            http_response_code(404);
            echo 'Page not found.';
            return;
        }

        call_user_func($this->routes[$method][$path], $request);
    }

    private function normalizePath(string $path): string
    {
        $path = parse_url($path, PHP_URL_PATH) ?: '/';
        $path = rtrim($path, '/');

        return $path === '' ? '/' : $path;
    }
}
