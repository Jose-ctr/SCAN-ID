<?php

declare(strict_types=1);

namespace ScanId\Services;

use RuntimeException;
use ScanId\Config\Database;
use ScanId\Models\PhoneVerification;
use ScanId\Models\User;

final class PhoneVerificationService
{
    /**
     * Send a phone verification code to an authenticated user.
     *
     * @return array<string, mixed>
     */
    public static function sendForUser(
        string $userId
    ): array {
        $userId = trim($userId);

        if ($userId === '') {
            throw new RuntimeException(
                'User ID is required.'
            );
        }

        $database = Database::connection();
        $userModel = new User($database);

        $user = $userModel->findById($userId);

        if ($user === null) {
            throw new RuntimeException(
                'User account was not found.'
            );
        }

        if (!(bool) $user['is_active']) {
            throw new RuntimeException(
                'This account is inactive.'
            );
        }

        $phone = (string) $user['phone'];

        if ($phone === '') {
            throw new RuntimeException(
                'No phone number is attached to this account.'
            );
        }

        if (
            !empty($user['phone_verified_at'])
        ) {
            return [
                'status' => 'already_verified',
                'message' => 'Phone number is already verified.',
            ];
        }

        $verificationModel = new PhoneVerification(
            $database
        );

        $verification = $verificationModel->createForUser(
            $userId
        );

        $code = (string) $verification['code'];

        $message = sprintf(
            'SCAN-ID verification code: %s. '
            . 'This code expires in 10 minutes. '
            . 'Never share this code with anyone.',
            $code
        );

        try {
            $sms = SmsService::send(
                $phone,
                $message
            );
        } catch (\Throwable $exception) {
            /*
             * The OTP record remains server-side but is invalidated
             * if delivery fails so that an undelivered code cannot
             * later be used.
             */
            $verificationModel->invalidate(
                (string) $verification['id']
            );

            throw new RuntimeException(
                'Unable to send verification code.'
            );
        }

        return [
            'status' => 'sent',
            'expires_at' => $verification['expires_at'],
            'sms_status' => $sms['status'] ?? 'sent',
        ];
    }

    /**
     * Verify an authenticated user's OTP.
     *
     * @return array<string, mixed>
     */
    public static function verifyForUser(
        string $userId,
        string $code
    ): array {
        $userId = trim($userId);
        $code = trim($code);

        if ($userId === '') {
            throw new RuntimeException(
                'User ID is required.'
            );
        }

        if ($code === '') {
            throw new RuntimeException(
                'Verification code is required.'
            );
        }

        if (!preg_match('/^\d{6}$/', $code)) {
            throw new RuntimeException(
                'Verification code must contain 6 digits.'
            );
        }

        $database = Database::connection();
        $userModel = new User($database);

        $user = $userModel->findById($userId);

        if ($user === null) {
            throw new RuntimeException(
                'User account was not found.'
            );
        }

        if (!(bool) $user['is_active']) {
            throw new RuntimeException(
                'This account is inactive.'
            );
        }

        if (!empty($user['phone_verified_at'])) {
            return [
                'verified' => true,
                'message' => 'Phone number is already verified.',
                'user' => $userModel->publicData($user),
            ];
        }

        $verificationModel = new PhoneVerification(
            $database
        );

        /*
         * The model performs the transactional OTP lookup,
         * expiry check, attempt limiting and password_hash
         * verification.
         */
        $verification = $verificationModel->verify(
            (string) $user['phone'],
            $code
        );

        if ($verification === null) {
            throw new RuntimeException(
                'Invalid or expired verification code.'
            );
        }

        $updatedUser = $userModel->findById($userId);

        if ($updatedUser === null) {
            throw new RuntimeException(
                'Unable to reload verified user account.'
            );
        }

        return [
            'verified' => true,
            'message' => 'Phone number verified successfully.',
            'user' => $userModel->publicData($updatedUser),
        ];
    }

    /**
     * Check whether an authenticated user's phone is verified.
     */
    public static function isVerified(
        string $userId
    ): bool {
        $userId = trim($userId);

        if ($userId === '') {
            return false;
        }

        $database = Database::connection();
        $userModel = new User($database);

        $user = $userModel->findById($userId);

        if ($user === null) {
            return false;
        }

        return !empty($user['phone_verified_at']);
    }

    private function __construct()
    {
    }
}
