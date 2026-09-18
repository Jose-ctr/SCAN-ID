<?php

declare(strict_types=1);

namespace ScanId\Http;

use JsonException;
use RuntimeException;

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

        return is_string($uri) && $uri !== ''
            ? $uri
            : '/';
    }

    /**
     * Determine whether the request contains JSON.
     */
    public static function isJson(): bool
    {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

        return str_contains(
            strtolower((string) $contentType),
            'application/json'
        );
    }

    /**
     * Read and decode the JSON request body.
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

            throw new RuntimeException(
                'Unreachable JSON decoding state.'
            );
        }

        if (!is_array($data)) {
            Response::error(
                'JSON request body must be an object.',
                400
            );

            throw new RuntimeException(
                'Unreachable JSON validation state.'
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
            $data = self::json();

            return $data[$key] ?? $default;
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
        $normalized = strtoupper(
            str_replace('-', '_', $name)
        );

        $serverKey = 'HTTP_' . $normalized;

        if (isset($_SERVER[$serverKey])) {
            return (string) $_SERVER[$serverKey];
        }

        /*
         * Some CGI/FastCGI configurations expose the
         * Authorization header using this alternate key.
         */
        $redirectedKey = 'REDIRECT_' . $serverKey;

        if (isset($_SERVER[$redirectedKey])) {
            return (string) $_SERVER[$redirectedKey];
        }

        return $default;
    }

    /**
     * Get the Authorization header.
     */
    public static function authorization(): ?string
    {
        return self::header('Authorization');
    }

    /**
     * Get the client's direct IP address.
     *
     * Do not trust forwarded IP headers unless trusted
     * reverse-proxy configuration is introduced.
     */
    public static function ip(): ?string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;

        if (!is_string($ip) || $ip === '') {
            return null;
        }

        return filter_var(
            $ip,
            FILTER_VALIDATE_IP
        ) !== false
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
