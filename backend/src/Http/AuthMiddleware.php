<?php

declare(strict_types=1);

namespace ScanId\Http;

use ScanId\Services\AuthService;

final class AuthMiddleware
{
    /**
     * Require an authenticated user.
     *
     * @return array<string, mixed>
     */
    public static function requireUser(): array
    {
        $authorization = Request::authorization();

        if ($authorization === null || $authorization === '') {
            Response::error(
                'Authentication required.',
                401
            );
        }

        $token = self::extractBearerToken($authorization);

        if ($token === null) {
            Response::error(
                'Invalid authorization header.',
                401
            );
        }

        $user = AuthService::userFromToken($token);

        if ($user === null) {
            Response::error(
                'Invalid or expired authentication session.',
                401
            );
        }

        return $user;
    }

    /**
     * Extract a bearer token from an Authorization header.
     */
    private static function extractBearerToken(
        string $authorization
    ): ?string {
        $authorization = trim($authorization);

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
