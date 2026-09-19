<?php

declare(strict_types=1);

namespace ScanId\Services;

use RuntimeException;
use ScanId\Models\PhoneVerification;
use ScanId\Models\User;

final class PhoneVerificationService
{
    /**
     * Create an OTP for a user's phone number.
     *
     * @return array{
     *     id: string,
     *     phone: string,
     *     otp: string,
     *     expires_at: string
     * }
     */
    public static function createForUser(
        string $userId
    ): array {
        $userId = trim($userId);

        if ($userId === '') {
            throw new RuntimeException(
                'User ID is required.'
            );
        }

        $user = User::findById($userId);

        if ($user === null) {
            throw new RuntimeException(
                'User account not found.'
            );
        }

        if (!(bool) $user['is_active']) {
            throw new RuntimeException(
                'This account is inactive.'
            );
        }

        $phone = trim(
            (string) $user['phone']
        );

        if ($phone === '') {
            throw new RuntimeException(
                'User phone number is not configured.'
            );
        }

        return PhoneVerification::create(
            $phone,
            $userId
        );
    }

    /**
     * Create an OTP directly for a phone number.
     *
     * @return array{
     *     id: string,
     *     phone: string,
     *     otp: string,
     *     expires_at: string
     * }
     */
    public static function createForPhone(
        string $phone
    ): array {
        $phone = trim($phone);

        if ($phone === '') {
            throw new RuntimeException(
                'Phone number is required.'
            );
        }

        $user = User::findByPhone($phone);

        return PhoneVerification::create(
            $phone,
            $user['id'] ?? null
        );
    }

    /**
     * Verify a submitted OTP.
     *
     * @return array<string, mixed>
     */
    public static function verify(
        string $phone,
        string $otp
    ): array {
        $phone = trim($phone);
        $otp = trim($otp);

        if ($phone === '') {
            throw new RuntimeException(
                'Phone number is required.'
            );
        }

        if ($otp === '') {
            throw new RuntimeException(
                'Verification code is required.'
            );
        }

        if (!preg_match('/^\d{6}$/', $otp)) {
            throw new RuntimeException(
                'Verification code must contain 6 digits.'
            );
        }

        $verification = PhoneVerification::verify(
            $phone,
            $otp
        );

        if ($verification === null) {
            throw new RuntimeException(
                'Invalid, expired, or already used verification code.'
            );
        }

        return $verification;
    }

    /**
     * Check whether a user's phone number is verified.
     */
    public static function isUserVerified(
        string $userId
    ): bool {
        $userId = trim($userId);

        if ($userId === '') {
            return false;
        }

        return User::isPhoneVerified(
            $userId
        );
    }

    private function __construct()
    {
    }
}
