<?php

declare(strict_types=1);

namespace ScanId\Services;

use RuntimeException;

final class SmsService
{
    /**
     * Send an SMS through Africa's Talking.
     *
     * The provider response is never exposed to the caller.
     */
    public static function send(string $phone, string $message): array
    {
        $phone = self::normalizePhone($phone);

        $message = trim($message);

        if ($message === '') {
            throw new RuntimeException('SMS message cannot be empty.');
        }

        if (mb_strlen($message) > 480) {
            throw new RuntimeException(
                'SMS message exceeds the maximum supported length.'
            );
        }

        $username = trim((string) ($_ENV['AFRICASTALKING_USERNAME'] ?? ''));
        $apiKey = trim((string) ($_ENV['AFRICASTALKING_API_KEY'] ?? ''));
        $senderId = trim((string) ($_ENV['AFRICASTALKING_SENDER_ID'] ?? 'SCAN-ID'));

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

        $environment = strtolower(
            trim((string) ($_ENV['APP_ENV'] ?? 'local'))
        );

        $endpoint = $environment === 'production'
            ? 'https://api.africastalking.com/version1/messaging'
            : 'https://api.sandbox.africastalking.com/version1/messaging';

        $postFields = http_build_query(
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
            throw new RuntimeException('Unable to initialize SMS provider.');
        }

        curl_setopt_array(
            $curl,
            [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $postFields,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'Content-Type: application/x-www-form-urlencoded',
                    'apiKey: ' . $apiKey,
                ],
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]
        );

        $responseBody = curl_exec($curl);
        $curlError = curl_error($curl);
        $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);

        curl_close($curl);

        if ($responseBody === false) {
            throw new RuntimeException(
                'SMS provider request failed.'
                . ($curlError !== '' ? ' ' . $curlError : '')
            );
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new RuntimeException(
                'SMS provider rejected the request.'
            );
        }

        try {
            $response = json_decode(
                $responseBody,
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (\JsonException) {
            throw new RuntimeException(
                'SMS provider returned an invalid response.'
            );
        }

        if (!is_array($response)) {
            throw new RuntimeException(
                'SMS provider returned an unexpected response.'
            );
        }

        $recipients = $response['SMSMessageData']['Recipients'] ?? null;

        if (!is_array($recipients) || count($recipients) === 0) {
            throw new RuntimeException(
                'SMS provider did not confirm the recipient.'
            );
        }

        $recipient = $recipients[0];

        if (!is_array($recipient)) {
            throw new RuntimeException(
                'SMS provider returned invalid recipient data.'
            );
        }

        $status = strtolower(
            trim((string) ($recipient['status'] ?? ''))
        );

        if ($status === '') {
            throw new RuntimeException(
                'SMS provider did not return a delivery status.'
            );
        }

        $messageId = isset($recipient['messageId'])
            ? trim((string) $recipient['messageId'])
            : null;

        $number = isset($recipient['number'])
            ? trim((string) $recipient['number'])
            : $phone;

        return [
            'success' => in_array(
                $status,
                ['sent', 'submitted', 'queued'],
                true
            ),
            'phone' => $number,
            'status' => $status,
            'message_id' => $messageId !== '' ? $messageId : null,
        ];
    }

    /**
     * Send a six-digit phone verification code.
     */
    public static function sendVerificationCode(
        string $phone,
        string $code,
        int $expiresInMinutes = 10
    ): array {
        if (!preg_match('/^\d{6}$/', $code)) {
            throw new RuntimeException(
                'Verification code must contain exactly 6 digits.'
            );
        }

        if ($expiresInMinutes < 1 || $expiresInMinutes > 60) {
            throw new RuntimeException(
                'Verification code expiry must be between 1 and 60 minutes.'
            );
        }

        $message = sprintf(
            'SCAN-ID verification code: %s. This code expires in %d minutes. Do not share it with anyone.',
            $code,
            $expiresInMinutes
        );

        return self::send($phone, $message);
    }

    /**
     * Normalize Kenyan mobile numbers to 254XXXXXXXXX.
     */
    public static function normalizePhone(string $phone): string
    {
        $phone = trim($phone);

        if ($phone === '') {
            throw new RuntimeException('Phone number is required.');
        }

        $phone = preg_replace('/[\s().-]+/', '', $phone);

        if ($phone === null) {
            throw new RuntimeException('Invalid phone number.');
        }

        if (str_starts_with($phone, '+254')) {
            $phone = substr($phone, 1);
        } elseif (str_starts_with($phone, '07')) {
            $phone = '254' . substr($phone, 1);
        } elseif (str_starts_with($phone, '01')) {
            $phone = '254' . substr($phone, 1);
        }

        if (!preg_match('/^254(?:7\d{8}|1\d{8})$/', $phone)) {
            throw new RuntimeException(
                'Invalid Kenyan phone number.'
            );
        }

        return $phone;
    }

    private function __construct()
    {
    }
}
