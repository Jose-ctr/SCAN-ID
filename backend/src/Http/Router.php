<?php

declare(strict_types=1);

namespace ScanId\Http;

use Closure;
use Throwable;

final class Router
{
    /**
     * @var array<string, array<int, array<string, mixed>>>
     */
    private array $routes = [];

    /**
     * Register a GET route.
     */
    public function get(string $path, Closure $handler): self
    {
        return $this->add('GET', $path, $handler);
    }

    /**
     * Register a POST route.
     */
    public function post(string $path, Closure $handler): self
    {
        return $this->add('POST', $path, $handler);
    }

    /**
     * Register a PUT route.
     */
    public function put(string $path, Closure $handler): self
    {
        return $this->add('PUT', $path, $handler);
    }

    /**
     * Register a PATCH route.
     */
    public function patch(string $path, Closure $handler): self
    {
        return $this->add('PATCH', $path, $handler);
    }

    /**
     * Register a DELETE route.
     */
    public function delete(string $path, Closure $handler): self
    {
        return $this->add('DELETE', $path, $handler);
    }

    /**
     * Register a route.
     */
    private function add(
        string $method,
        string $path,
        Closure $handler
    ): self {
        $normalizedPath = $this->normalizePath($path);

        $this->routes[$method][] = [
            'path' => $normalizedPath,
            'handler' => $handler,
        ];

        return $this;
    }

    /**
     * Dispatch the current request.
     */
    public function dispatch(
        ?string $method = null,
        ?string $path = null
    ): never {
        $requestMethod = strtoupper(
            $method ?? Request::method()
        );

        $requestPath = $this->normalizePath(
            $path ?? Request::path()
        );

        foreach ($this->routes[$requestMethod] ?? [] as $route) {
            if ($route['path'] !== $requestPath) {
                continue;
            }

            try {
                ($route['handler'])();

                return;
            } catch (Throwable $exception) {
                $this->handleException($exception);

                return;
            }
        }

        Response::error(
            'API endpoint not found.',
            404
        );
    }

    /**
     * Normalize a route path.
     */
    private function normalizePath(string $path): string
    {
        $path = parse_url($path, PHP_URL_PATH) ?: '/';

        $path = '/' . trim($path, '/');

        return $path === '//' ? '/' : $path;
    }

    /**
     * Handle unexpected route exceptions.
     */
    private function handleException(
        Throwable $exception
    ): never {
        $debug = filter_var(
            $_ENV['APP_DEBUG'] ?? false,
            FILTER_VALIDATE_BOOLEAN
        );

        if ($debug) {
            Response::error(
                $exception->getMessage(),
                500
            );
        }

        Response::error(
            'An unexpected server error occurred.',
            500
        );
    }
}
