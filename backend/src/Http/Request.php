<?php

declare(strict_types=1);

namespace ScanId\Http;

use JsonException;

final class Request
{
    /**
     * Get the HTTP request method.
     */
    public static function method(): string
    {
        return strtoupper(
            $_SERVER['REQUEST_METHOD'] ?? 'GET'
        );
    }

    /**
     * Get the current request URI path.
     */
    public static function path(): string
    {
        $uri = parse_url(
            $_SERVER['REQUEST_URI'] ?? '/',
            PHP_URL_PATH
        );

        return $uri ?: '/';
    }

    /**
     * Determine whether the request contains JSON.
     */
    public static function isJson(): bool
    {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

        return str_contains(
            strtolower($contentType),
            'application/json'
        );
    }

    /**
     * Read the JSON request body.
     *
     * @return array<string, mixed>
     */
    public static function json(): array
    {
        $body = file_get_contents('php://input');

        if ($body === false || trim($body) === '') {
            return [];
        }

        try {
            $data = json_decode(
                $body,
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException) {
            Response::error(
                'Invalid JSON request body.',
                400
            );
        }

        if (!is_array($data)) {
            Response::error(
                'JSON request body must be an object.',
                400
            );
        }

        return $data;
    }

    /**
     * Get a value from the query string.
     */
    public static function query(
        string $key,
        mixed $default = null
    ): mixed {
        return $_GET[$key] ?? $default;
    }

    /**
     * Get a value from the request body.
     *
     * Supports JSON and standard form requests.
     */
    public static function input(
        string $key,
        mixed $default = null
    ): mixed {
        if (self::isJson()) {
            return self::json()[$key] ?? $default;
        }

        return $_POST[$key] ?? $default;
    }

    /**
     * Get an HTTP request header.
     */
    public static function header(
        string $name,
        ?string $default = null
    ): ?string {
        $serverKey = 'HTTP_' . strtoupper(
            str_replace('-', '_', $name)
        );

        return isset($_SERVER[$serverKey])
            ? (string) $_SERVER[$serverKey]
            : $default;
    }

    /**
     * Get the Authorization header.
     */
    public static function authorization(): ?string
    {
        return self::header('Authorization');
    }

    /**
     * Get the client's IP address.
     */
    public static function ip(): ?string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;

        return is_string($ip) && $ip !== ''
            ? $ip
            : null;
    }

    /**
     * Prevent accidental instantiation.
     */
    private function __construct()
    {
    }
}
