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

        $originAllowed = (
            $allowedOrigin !== '' &&
            $requestOrigin !== '' &&
            hash_equals($allowedOrigin, $requestOrigin)
        );

        /*
         * Only allow the explicitly configured frontend origin.
         */
        if ($originAllowed) {
            header("Access-Control-Allow-Origin: {$allowedOrigin}");
            header('Access-Control-Allow-Credentials: true');
            header('Vary: Origin');
        }

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
            if ($requestOrigin !== '' && !$originAllowed) {
                http_response_code(403);

                exit;
            }

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
