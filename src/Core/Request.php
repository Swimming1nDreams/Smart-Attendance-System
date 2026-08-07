<?php

declare(strict_types=1);

namespace App\Core;

final class Request
{
    private string $method;
    private string $path;
    private array $queryParams;
    private array $bodyParams;
    private array $serverParams;

    public function __construct(array $server, array $get = [], array $post = [])
    {
        $this->method = strtoupper($server['REQUEST_METHOD'] ?? 'GET');
        $this->path = $this->normalizePath((string) ($server['REQUEST_URI'] ?? '/'));
        $this->queryParams = $get;
        $this->bodyParams = $post;
        $this->serverParams = $server;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getQueryParams(): array
    {
        return $this->queryParams;
    }

    public function getBodyParams(): array
    {
        return $this->bodyParams;
    }


    public function query(string $key, mixed $default = null): mixed
    {
        return $this->queryParams[$key] ?? $default;
    }


    public function input(string $key, mixed $default = null): mixed
    {
        return $this->bodyParams[$key] ?? $default;
    }


    public function hasQuery(string $key): bool
    {
        return array_key_exists($key, $this->queryParams);
    }


    public function hasInput(string $key): bool
    {
        return array_key_exists($key, $this->bodyParams);
    }


    public function getServerParam(string $name, mixed $default = null): mixed
    {
        return $this->serverParams[$name] ?? $default;
    }


    public function getServerParams(): array
    {
        return $this->serverParams;
    }


    private function normalizePath(string $path): string
    {
        $path = parse_url($path, PHP_URL_PATH) ?: '/';
        $path = rtrim($path, '/');

        return $path === '' ? '/' : $path;
    }
}