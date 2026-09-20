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
            $result = AuthService::register($data);

            Response::success(
                $result,
                'Account created successfully.',
                201
            );
        } catch (RuntimeException $exception) {
            Response::error(
                $exception->getMessage(),
                422
            );
        } catch (\Throwable $exception) {
            Response::error(
                'Unable to create account.',
                500
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
            $result = AuthService::login(
                $phone,
                $password,
                $deviceName
            );

            Response::success(
                $result,
                'Login successful.'
            );
        } catch (RuntimeException $exception) {
            Response::error(
                $exception->getMessage(),
                401
            );
        } catch (\Throwable $exception) {
            Response::error(
                'Unable to authenticate user.',
                500
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

        Response::success(
            [
                'user' => $user,
            ],
            'Authenticated user.'
        );
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

        /*
         * Confirm that the session is valid before
         * revoking it.
         */
        AuthMiddleware::requireUser();

        AuthService::logout($token);

        Response::success(
            null,
            'Logged out successfully.'
        );
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
