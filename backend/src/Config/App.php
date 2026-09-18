<?php

declare(strict_types=1);

namespace ScanId\Config;

use RuntimeException;

final class App
{
    /**
     * Get the application name.
     */
    public static function name(): string
    {
        return self::required('APP_NAME');
    }

    /**
     * Get the application environment.
     */
    public static function environment(): string
    {
        return $_ENV['APP_ENV'] ?? 'local';
    }

    /**
     * Determine whether the application is running in production.
     */
    public static function isProduction(): bool
    {
        return self::environment() === 'production';
    }

    /**
     * Determine whether debug mode is enabled.
     */
    public static function debug(): bool
    {
        return filter_var(
            $_ENV['APP_DEBUG'] ?? false,
            FILTER_VALIDATE_BOOLEAN
        );
    }

    /**
     * Get the application URL.
     */
    public static function url(): string
    {
        return rtrim(
            self::required('APP_URL'),
            '/'
        );
    }

    /**
     * Get the application timezone.
     */
    public static function timezone(): string
    {
        return $_ENV['APP_TIMEZONE'] ?? 'Africa/Nairobi';
    }

    /**
     * Get the configured recovery fee in Kenyan shillings.
     */
    public static function recoveryFee(): int
    {
        $fee = filter_var(
            $_ENV['RECOVERY_FEE_KES'] ?? 150,
            FILTER_VALIDATE_INT
        );

        if ($fee === false || $fee < 0) {
            throw new RuntimeException(
                'Invalid recovery fee configuration.'
            );
        }

        return $fee;
    }

    /**
     * Get a required environment variable.
     */
    private static function required(string $key): string
    {
        $value = $_ENV[$key] ?? '';

        if ($value === '') {
            throw new RuntimeException(
                "Missing required environment variable: {$key}"
            );
        }

        return $value;
    }

    /**
     * Prevent accidental instantiation.
     */
    private function __construct()
    {
    }
}
