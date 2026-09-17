<?php

declare(strict_types=1);

namespace ScanId\Http;

use Throwable;

final class Router
{
    /**
     * @var array<int, array{
     *     method: string,
     *     path: string,
     *     handler: callable,
     *     middleware: array<int, callable|string>,
     *     regex: string
     * }>
     */
    private array $routes = [];

    private string $prefix = '';

    /**
     * @var array<int, callable|string>
     */
    private array $groupMiddleware = [];

    /**
     * Register a GET route.
     *
     * @param callable|array{class-string, string} $handler
     * @param array<int, callable|string> $middleware
     */
    public function get(
        string $path,
        callable|array $handler,
        array $middleware = []
    ): self {
        return $this->add(
            'GET',
            $path,
            $handler,
            $middleware
        );
    }

    /**
     * Register a POST route.
     *
     * @param callable|array{class-string, string} $handler
     * @param array<int, callable|string> $middleware
     */
    public function post(
        string $path,
        callable|array $handler,
        array $middleware = []
    ): self {
        return $this->add(
            'POST',
            $path,
            $handler,
            $middleware
        );
    }

    /**
     * Register a PUT route.
     *
     * @param callable|array{class-string, string} $handler
     * @param array<int, callable|string> $middleware
     */
    public function put(
        string $path,
        callable|array $handler,
        array $middleware = []
    ): self {
        return $this->add(
            'PUT',
            $path,
            $handler,
            $middleware
        );
    }

    /**
     * Register a PATCH route.
     *
     * @param callable|array{class-string, string} $handler
     * @param array<int, callable|string> $middleware
     */
    public function patch(
        string $path,
        callable|array $handler,
        array $middleware = []
    ): self {
        return $this->add(
            'PATCH',
            $path,
            $handler,
            $middleware
        );
    }

    /**
     * Register a DELETE route.
     *
     * @param callable|array{class-string, string} $handler
     * @param array<int, callable|string> $middleware
     */
    public function delete(
        string $path,
        callable|array $handler,
        array $middleware = []
    ): self {
        return $this->add(
            'DELETE',
            $path,
            $handler,
            $middleware
        );
    }

    /**
     * Register a group of routes with a shared prefix
     * and optional middleware.
     *
     * @param array<int, callable|string> $middleware
     */
    public function group(
        string $prefix,
        callable $callback,
        array $middleware = []
    ): self {
        $previousPrefix = $this->prefix;
        $previousMiddleware = $this->groupMiddleware;

        $this->prefix = $this->joinPaths(
            $this->prefix,
            $prefix
        );

        $this->groupMiddleware = array_merge(
            $this->groupMiddleware,
            $middleware
        );

        $callback($this);

        $this->prefix = $previousPrefix;
        $this->groupMiddleware = $previousMiddleware;

        return $this;
    }

    /**
     * Add a route.
     *
     * @param callable|array{class-string, string} $handler
     * @param array<int, callable|string> $middleware
     */
    private function add(
        string $method,
        string $path,
        callable|array $handler,
        array $middleware = []
    ): self {
        $fullPath = $this->joinPaths(
            $this->prefix,
            $path
        );

        $fullMiddleware = array_merge(
            $this->groupMiddleware,
            $middleware
        );

        $this->routes[] = [
            'method' => strtoupper($method),
            'path' => $fullPath,
            'handler' => $handler,
            'middleware' => $fullMiddleware,
            'regex' => $this->pathToRegex($fullPath),
        ];

        return $this;
    }

    /**
     * Convert route parameters such as /users/{id}
     * into a regular expression.
     */
    private function pathToRegex(
        string $path
    ): string {
        $quoted = preg_quote(
            $path,
            '#'
        );

        $pattern = preg_replace_callback(
            '#\\\\\{([a-zA-Z_][a-zA-Z0-9_]*)\\\\\}#',
            static function (array $matches): string {
                return '(?P<' . $matches[1] . '>[^/]+)';
            },
            $quoted
        );

        return '#^' . $pattern . '$#';
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

        foreach ($this->routes as $route) {
            if ($route['method'] !== $requestMethod) {
                continue;
            }

            $matches = [];

            if (
                preg_match(
                    $route['regex'],
                    $requestPath,
                    $matches
                ) !== 1
            ) {
                continue;
            }

            $params = array_filter(
                $matches,
                'is_string',
                ARRAY_FILTER_USE_KEY
            );

            try {
                $this->runMiddleware(
                    $route['middleware']
                );

                $this->runHandler(
                    $route['handler'],
                    $params
                );

                return;
            } catch (Throwable $exception) {
                $this->handleException(
                    $exception
                );
            }
        }

        Response::error(
            'API endpoint not found.',
            404
        );
    }

    /**
     * Execute route middleware.
     *
     * Middleware may be:
     *
     * - a callable
     * - a class name with a static handle() method
     */
    private function runMiddleware(
        array $middleware
    ): void {
        foreach ($middleware as $item) {
            if (is_string($item)) {
                if (
                    !class_exists($item) ||
                    !method_exists($item, 'handle')
                ) {
                    throw new \RuntimeException(
                        "Invalid middleware: {$item}"
                    );
                }

                $item::handle();

                continue;
            }

            if (is_callable($item)) {
                $result = $item();

                if ($result === false) {
                    return;
                }

                continue;
            }

            throw new \RuntimeException(
                'Invalid middleware definition.'
            );
        }
    }

    /**
     * Execute a route controller or closure.
     *
     * Controller methods receive route parameters.
     */
    private function runHandler(
        callable|array $handler,
        array $params
    ): void {
        if (is_array($handler)) {
            if (count($handler) !== 2) {
                throw new \RuntimeException(
                    'Invalid controller handler.'
                );
            }

            [$class, $method] = $handler;

            if (
                !is_string($class) ||
                !is_string($method)
            ) {
                throw new \RuntimeException(
                    'Invalid controller definition.'
                );
            }

            if (!class_exists($class)) {
                throw new \RuntimeException(
                    "Controller not found: {$class}"
                );
            }

            if (!method_exists($class, $method)) {
                throw new \RuntimeException(
                    "Controller method not found: {$class}::{$method}"
                );
            }

            $reflection = new \ReflectionMethod(
                $class,
                $method
            );

            if ($reflection->isStatic()) {
                $reflection->invokeArgs(
                    null,
                    [$params]
                );

                return;
            }

            $controller = new $class();

            $reflection->invokeArgs(
                $controller,
                [$params]
            );

            return;
        }

        $handler($params);
    }

    /**
     * Join a group prefix and route path.
     */
    private function joinPaths(
        string $prefix,
        string $path
    ): string {
        return $this->normalizePath(
            $prefix . '/' . trim(
                $path,
                '/'
            )
        );
    }

    /**
     * Normalize a route path.
     */
    private function normalizePath(
        string $path
    ): string {
        $path = parse_url(
            $path,
            PHP_URL_PATH
        ) ?: '/';

        $path = '/' . trim(
            $path,
            '/'
        );

        return $path === '//' ? '/' : $path;
    }

    /**
     * List registered routes.
     *
     * @return array<int, array<string, mixed>>
     */
    public function list(): array
    {
        return $this->routes;
    }

    /**
     * Convert unexpected exceptions into API responses.
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

    private function __construct()
    {
    }
}
