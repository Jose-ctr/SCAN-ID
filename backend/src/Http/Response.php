<?php

declare(strict_types=1);

namespace ScanId\Http;

final class Response
{
    /**
     * Return a successful JSON response.
     *
     * @param array<string, mixed> $data
     */
    public static function success(
        array $data = [],
        int $status = 200
    ): never {
        self::json([
            'success' => true,
            'data' => $data,
        ], $status);
    }

    /**
     * Return an error JSON response.
     *
     * @param array<string, mixed> $errors
     */
    public static function error(
        string $message,
        int $status = 400,
        array $errors = []
    ): never {
        $response = [
            'success' => false,
            'message' => $message,
        ];

        if ($errors !== []) {
            $response['errors'] = $errors;
        }

        self::json($response, $status);
    }

    /**
     * Return a JSON response.
     *
     * @param array<string, mixed> $payload
     */
    public static function json(
        array $payload,
        int $status = 200
    ): never {
        http_response_code($status);

        header('Content-Type: application/json; charset=utf-8');

        echo json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES |
            JSON_UNESCAPED_UNICODE |
            JSON_THROW_ON_ERROR
        );

        exit;
    }

    /**
     * Return a 204 No Content response.
     */
    public static function noContent(): never
    {
        http_response_code(204);

        exit;
    }
}
