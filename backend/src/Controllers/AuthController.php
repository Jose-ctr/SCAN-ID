<?php

declare(strict_types=1);

namespace ScanId\Controllers;

use RuntimeException;
use ScanId\Http\AuthMiddleware;
use ScanId\Http\Request;
use ScanId\Http\Response;
use ScanId\Services\AuthService;

final class AuthController
{
    /**
     * Register a new SCAN-ID account.
     *
     * @param array<string, mixed> $params
     */
    public static function register(
        array $params = []
    ): never {
        $data = Request::json();

        try {
            $user = AuthService::register($data);

            Response::success([
                'user' => $user,
                'message' => 'Account created successfully.',
            ], 201);
        } catch (RuntimeException $exception) {
            Response::error(
                $exception->getMessage(),
                422
            );
        }
    }

    /**
     * Authenticate an existing user.
     *
     * @param array<string, mixed> $params
     */
    public static function login(
        array $params = []
    ): never {
        $data = Request::json();

        $phone = trim(
            (string) ($data['phone'] ?? '')
        );

        $password = (string) (
            $data['password'] ?? ''
        );

        $deviceName = isset($data['device_name'])
            ? trim((string) $data['device_name'])
            : null;

        try {
            $authentication = AuthService::login(
                $phone,
                $password,
                $deviceName
            );

            Response::success([
                'user' => $authentication['user'],
                'token' => $authentication['token'],
                'token_type' => $authentication['token_type'],
                'expires_in' => $authentication['expires_in'],
            ]);
        } catch (RuntimeException $exception) {
            Response::error(
                $exception->getMessage(),
                401
            );
        }
    }

    /**
     * Return the currently authenticated user.
     *
     * @param array<string, mixed> $params
     */
    public static function me(
        array $params = []
    ): never {
        $user = AuthMiddleware::requireUser();

        Response::success([
            'user' => $user,
        ]);
    }

    /**
     * Log out the current authentication session.
     *
     * @param array<string, mixed> $params
     */
    public static function logout(
        array $params = []
    ): never {
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

        AuthMiddleware::requireUser();

        AuthService::logout($token);

        Response::success([
            'message' => 'Logged out successfully.',
        ]);
    }

    /**
     * Extract a Bearer token from the Authorization header.
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

        return $token !== ''
            ? $token
            : null;
    }

    private function __construct()
    {
    }
}
