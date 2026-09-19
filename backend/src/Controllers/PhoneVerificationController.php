<?php

declare(strict_types=1);

namespace ScanId\Controllers;

use RuntimeException;
use ScanId\Http\AuthMiddleware;
use ScanId\Http\Request;
use ScanId\Http\Response;
use ScanId\Services\PhoneVerificationService;
use ScanId\Services\SmsService;

final class PhoneVerificationController
{
    /**
     * Send a verification code to the authenticated user's phone.
     *
     * @param array<string, mixed> $params
     */
    public static function send(
        array $params = []
    ): never {
        $user = AuthMiddleware::requireUser();

        try {
            $verification = PhoneVerificationService::createForUser(
                (string) $user['id']
            );

            $sms = SmsService::sendVerificationCode(
                $verification['phone'],
                $verification['otp']
            );

            Response::success([
                'message' => 'Verification code sent successfully.',
                'expires_at' => $verification['expires_at'],
                'sms_status' => $sms['status'],
            ]);
        } catch (RuntimeException $exception) {
            Response::error(
                $exception->getMessage(),
                422
            );
        }
    }

    /**
     * Verify the authenticated user's phone number.
     *
     * @param array<string, mixed> $params
     */
    public static function verify(
        array $params = []
    ): never {
        $user = AuthMiddleware::requireUser();

        $data = Request::json();

        $phone = trim(
            (string) ($user['phone'] ?? '')
        );

        $otp = trim(
            (string) ($data['otp'] ?? '')
        );

        try {
            $verification = PhoneVerificationService::verify(
                $phone,
                $otp
            );

            Response::success([
                'message' => 'Phone number verified successfully.',
                'verification' => $verification,
            ]);
        } catch (RuntimeException $exception) {
            Response::error(
                $exception->getMessage(),
                422
            );
        }
    }

    /**
     * Return the current phone verification status.
     *
     * @param array<string, mixed> $params
     */
    public static function status(
        array $params = []
    ): never {
        $user = AuthMiddleware::requireUser();

        $verified = PhoneVerificationService::isUserVerified(
            (string) $user['id']
        );

        Response::success([
            'phone' => $user['phone'],
            'verified' => $verified,
        ]);
    }

    private function __construct()
    {
    }
}
