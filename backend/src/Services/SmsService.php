<?php

declare(strict_types=1);

namespace ScanId\Services;

use RuntimeException;

final class SmsService
{
    /**
     * Send an SMS through Africa's Talking.
     *
     * IMPORTANT:
     * - API credentials must remain server-side.
     * - Provider responses are never returned in full.
     * - Provider/network errors are not exposed to API clients.
     */
    public static function send(
        string $phone,
        string $message
    ): array {
        $phone = trim($phone);
        $message = trim($message);

        if ($phone === '') {
            throw new RuntimeException(
                'Recipient phone number is required.'
            );
        }

        if ($message === '') {
            throw new RuntimeException(
                'SMS message is required.'
            );
        }

        if (mb_strlen($message) > 1600) {
            throw new RuntimeException(
                'SMS message is too long.'
            );
        }

        $username = trim(
            (string) ($_ENV['AT_USERNAME'] ?? '')
        );

        $apiKey = trim(
            (string) ($_ENV['AT_API_KEY'] ?? '')
        );

        $senderId = trim(
            (string) ($_ENV['AT_SENDER_ID'] ?? 'SCAN-ID')
        );

        if ($username === '') {
            throw new RuntimeException(
                'Africa\'s Talking username is not configured.'
            );
        }

        if ($apiKey === '') {
            throw new RuntimeException(
                'Africa\'s Talking API key is not configured.'
            );
        }

        if ($senderId === '') {
            throw new RuntimeException(
                'Africa\'s Talking sender ID is not configured.'
            );
        }

        if (!function_exists('curl_init')) {
            throw new RuntimeException(
                'PHP cURL extension is required for SMS delivery.'
            );
        }

        $environment = strtolower(
            trim(
                (string) (
                    $_ENV['APP_ENV'] ?? 'local'
                )
            )
        );

        $endpoint = $environment === 'production'
            ? 'https://api.africastalking.com/version1/messaging'
            : 'https://api.sandbox.africastalking.com/version1/messaging';

        $payload = http_build_query(
            [
                'username' => $username,
                'to' => $phone,
                'message' => $message,
                'from' => $senderId,
            ],
            '',
            '&',
            PHP_QUERY_RFC3986
        );

        $curl = curl_init($endpoint);

        if ($curl === false) {
            throw new RuntimeException(
                'Unable to initialize SMS connection.'
            );
        }

        curl_setopt_array(
            $curl,
            [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'Content-Type: application/x-www-form-urlencoded',
                    'apiKey: ' . $apiKey,
                ],
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 30,
            ]
        );

        $responseBody = curl_exec($curl);

        $httpStatus = (int) curl_getinfo(
            $curl,
            CURLINFO_HTTP_CODE
        );

        $curlError = curl_error($curl);

        curl_close($curl);

        if ($responseBody === false) {
            /*
             * Do not expose the provider's internal error to
             * the API client. Logging can be added later.
             */
            unset($curlError);

            throw new RuntimeException(
                'SMS provider connection failed.'
            );
        }

        $response = json_decode(
            $responseBody,
            true
        );

        if (!is_array($response)) {
            throw new RuntimeException(
                'SMS provider returned an invalid response.'
            );
        }

        if ($httpStatus < 200 || $httpStatus >= 300) {
            throw new RuntimeException(
                'SMS provider rejected the request.'
            );
        }

        $recipient = $response['SMSMessageData']['Recipients'][0]
            ?? null;

        if (!is_array($recipient)) {
            throw new RuntimeException(
                'SMS provider did not return delivery information.'
            );
        }

        $status = trim(
            (string) (
                $recipient['status'] ?? ''
            )
        );

        if ($status === '') {
            $status = 'Unknown';
        }

        return [
            'phone' => $phone,
            'status' => $status,
            'message_id' => isset($recipient['messageId'])
                ? (string) $recipient['messageId']
                : null,
            'cost' => isset($recipient['cost'])
                ? (string) $recipient['cost']
                : null,
        ];
    }

    /**
     * Send a phone verification OTP.
     */
    public static function sendVerificationCode(
        string $phone,
        string $otp
    ): array {
        $phone = trim($phone);
        $otp = trim($otp);

        if ($phone === '') {
            throw new RuntimeException(
                'Recipient phone number is required.'
            );
        }

        if (!preg_match('/^\d{6}$/', $otp)) {
            throw new RuntimeException(
                'Invalid verification code.'
            );
        }

        $message = sprintf(
            'SCAN-ID verification code: %s. '
            . 'It expires in 10 minutes. '
            . 'Do not share this code with anyone.',
            $otp
        );

        return self::send(
            $phone,
            $message
        );
    }

    private function __construct()
    {
    }
}
