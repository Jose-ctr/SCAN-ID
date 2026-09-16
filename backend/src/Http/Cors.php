<?php

declare(strict_types=1);

namespace ScanId\Http;

final class Cors
{
    /**
     * Apply the SCAN-ID CORS policy.
     */
    public static function apply(): void
    {
        $allowedOrigin = $_ENV['CORS_ORIGIN'] ?? '';

        $requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';

        /*
         * Only allow the configured frontend origin.
         */
        if (
            $allowedOrigin !== '' &&
            $requestOrigin !== '' &&
            hash_equals($allowedOrigin, $requestOrigin)
        ) {
            header("Access-Control-Allow-Origin: {$allowedOrigin}");
            header('Vary: Origin');
        }

        /*
         * Credentials are required for secure session-based
         * authentication.
         */
        header('Access-Control-Allow-Credentials: true');

        /*
         * HTTP methods supported by the SCAN-ID API.
         */
        header(
            'Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS'
        );

        /*
         * Headers accepted by the API.
         */
        header(
            'Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-CSRF-Token'
        );

        /*
         * Allow browsers to cache the preflight result briefly.
         */
        header('Access-Control-Max-Age: 600');

        /*
         * Handle browser preflight requests.
         */
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
            http_response_code(204);

            exit;
        }
    }

    /**
     * Prevent accidental instantiation.
     */
    private function __construct()
    {
    }
}
