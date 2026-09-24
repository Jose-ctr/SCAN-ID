<?php

declare(strict_types=1);

namespace ScanId\Config;

use RuntimeException;

final class App
{
    /**
     * Application name.
     */
    public static function name(): string
    {
        return self::required(
            'APP_NAME',
            'SCAN-ID'
        );
    }

    /**
     * Application environment.
     */
    public static function environment(): string
    {
        return strtolower(
            trim(
                $_ENV['APP_ENV']
                    ?? $_SERVER['APP_ENV']
                    ?? 'production'
            )
        );
    }

    /**
     * Whether the application is running in production.
     */
    public static function isProduction(): bool
    {
        return self::environment() === 'production';
    }

    /**
     * Application debug mode.
     */
    public static function debug(): bool
    {
        return filter_var(
            $_ENV['APP_DEBUG']
                ?? $_SERVER['APP_DEBUG']
                ?? 'false',
            FILTER_VALIDATE_BOOLEAN
        );
    }

    /**
     * Public application URL.
     */
    public static function url(): string
    {
        return rtrim(
            trim(
                $_ENV['APP_URL']
                    ?? $_SERVER['APP_URL']
                    ?? 'http://localhost:8000'
            ),
            '/'
        );
    }

    /**
     * Application timezone.
     */
    public static function timezone(): string
    {
        return trim(
            $_ENV['APP_TIMEZONE']
                ?? $_SERVER['APP_TIMEZONE']
                ?? 'Africa/Nairobi'
        );
    }

    /**
     * Total recovery payment in Kenya shillings.
     *
     * Owner pays KSh 300 total.
     */
    public static function recoveryFeeKes(): int
    {
        $value = self::integerEnv(
            'RECOVERY_FEE_KES',
            300
        );

        if ($value !== 300) {
            throw new RuntimeException(
                'RECOVERY_FEE_KES must be exactly 300.'
            );
        }

        return $value;
    }

    /**
     * Finder reward in Kenya shillings.
     */
    public static function finderRewardKes(): int
    {
        $value = self::integerEnv(
            'FINDER_REWARD_KES',
            150
        );

        if ($value !== 150) {
            throw new RuntimeException(
                'FINDER_REWARD_KES must be exactly 150.'
            );
        }

        return $value;
    }

    /**
     * SCAN-ID platform share in Kenya shillings.
     */
    public static function platformFeeKes(): int
    {
        $value = self::integerEnv(
            'PLATFORM_FEE_KES',
            150
        );

        if ($value !== 150) {
            throw new RuntimeException(
                'PLATFORM_FEE_KES must be exactly 150.'
            );
        }

        return $value;
    }

    /**
     * Validate the complete recovery payment breakdown.
     */
    public static function validateRecoveryPricing(): bool
    {
        $recovery = self::recoveryFeeKes();
        $finder = self::finderRewardKes();
        $platform = self::platformFeeKes();

        if ($finder + $platform !== $recovery) {
            throw new RuntimeException(
                'Invalid SCAN-ID recovery pricing configuration.'
            );
        }

        return true;
    }

    /**
     * Read a required environment variable.
     */
    public static function required(
        string $key,
        ?string $default = null
    ): string {
        $value =
            $_ENV[$key]
            ?? $_SERVER[$key]
            ?? $default;

        if (
            $value === null ||
            trim((string) $value) === ''
        ) {
            throw new RuntimeException(
                sprintf(
                    'Required environment variable "%s" is missing.',
                    $key
                )
            );
        }

        return trim((string) $value);
    }

    /**
     * Read an integer environment variable.
     */
    private static function integerEnv(
        string $key,
        int $default
    ): int {
        $value =
            $_ENV[$key]
            ?? $_SERVER[$key]
            ?? (string) $default;

        if (
            filter_var(
                $value,
                FILTER_VALIDATE_INT
            ) === false
        ) {
            throw new RuntimeException(
                sprintf(
                    'Environment variable "%s" must be an integer.',
                    $key
                )
            );
        }

        return (int) $value;
    }

    private function __construct()
    {
    }
}
