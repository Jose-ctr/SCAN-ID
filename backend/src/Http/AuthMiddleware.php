<?php

declare(strict_types=1);

namespace ScanId\Http;

use ScanId\Services\AuthService;

final class AuthMiddleware
{
    /**
     * Require an authenticated user.
     *
     * This method is used directly by controllers
     * when the authenticated user data is needed.
     *
     * @return array<string, mixed>
     */
    public static function requireUser(): array
    {
        $authorization = Request::authorization();

        if (
            $authorization === null ||
            $authorization === ''
        ) {
            Response::error(
                'Authentication required.',
                401
            );
        }

        $token = self::extractBearerToken(
            $authorization
        );

        if ($token === null) {
            Response::error(
                'Invalid authorization header.',
                401
            );
        }

        $user = AuthService::userFromToken(
            $token
        );

        if ($user === null) {
            Response::error(
                'Invalid or expired authentication session.',
                401
            );
        }

        return $user;
    }

    /**
     * Router-compatible middleware entry point.
     *
     * The Router calls this method when a protected
     * route uses AuthMiddleware::class.
     */
    public static function handle(): void
    {
        self::requireUser();
    }

    /**
     * Extract a Bearer token from the Authorization header.
     */
    private static function extractBearerToken(
        string $authorization
    ): ?string {
        $authorization = trim(
            $authorization
        );

        if (
            !str_starts_with(
                strtolower($authorization),
                'bearer '
            )
        ) {
            return null;
        }

        $token = trim(
            substr($authorization, 7)
        );

        if ($token === '') {
            return null;
        }

        return $token;
    }

    private function __construct()
    {
    }
}
