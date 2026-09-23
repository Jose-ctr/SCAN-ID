<?php

declare(strict_types=1);

namespace ScanId\Services;

use ScanId\Config\App;
use ScanId\Config\Database;
use ScanId\Models\RecoveryPayment;
use ScanId\Models\RecoveryRequest;
use RuntimeException;

final class MpesaService
{
    private const RECOVERY_AMOUNT_KES = 300;

    public static function initiateRecoveryPayment(
        string $recoveryRequestId,
        string $phone
    ): array {
        $phone = self::normalizePhone($phone);

        $recovery = RecoveryRequest::findById($recoveryRequestId);

        if ($recovery === null) {
            throw new RuntimeException(
                'Recovery request not found.'
            );
        }

        if (
            !in_array(
                $recovery['status'] ?? '',
                [
                    'pending',
                    'notified',
                    'payment_pending',
                ],
                true
            )
        ) {
            throw new RuntimeException(
                'Recovery request is not available for payment.'
            );
        }

        $ownerPhone = self::normalizePhone(
            (string) ($recovery['owner_phone'] ?? '')
        );

        if ($phone !== $ownerPhone) {
            throw new RuntimeException(
                'Payment phone number must match the recovery owner phone.'
            );
        }

        $existing = RecoveryPayment::findPendingByRecoveryRequest(
            $recoveryRequestId
        );

        if ($existing !== null) {
            $checkoutRequestId = $existing['checkout_request_id'] ?? null;

            if (
                is_string($checkoutRequestId)
                && trim($checkoutRequestId) !== ''
            ) {
                return [
                    'payment' => $existing,
                    'amount_kes' => self::RECOVERY_AMOUNT_KES,
                    'status' => 'pending',
                    'duplicate' => true,
                ];
            }
        }

        $payment = RecoveryPayment::create(
            $recoveryRequestId,
            $phone
        );

        $accessToken = self::accessToken();

        $timestamp = date('YmdHis');

        $shortcode = trim(
            (string) ($_ENV['MPESA_SHORTCODE'] ?? '')
        );

        $passkey = trim(
            (string) ($_ENV['MPESA_PASSKEY'] ?? '')
        );

        if ($shortcode === '' || $passkey === '') {
            throw new RuntimeException(
                'M-Pesa shortcode or passkey is not configured.'
            );
        }

        $password = base64_encode(
            $shortcode . $passkey . $timestamp
        );

        $callbackUrl = trim(
            (string) ($_ENV['MPESA_CALLBACK_URL'] ?? '')
        );

        if ($callbackUrl === '') {
            throw new RuntimeException(
                'M-Pesa callback URL is not configured.'
            );
        }

        $accountReference = 'SCANID-' .
            strtoupper(
                substr(
                    str_replace('-', '', $recoveryRequestId),
                    0,
                    12
                )
            );

        $payload = [
            'BusinessShortCode' => $shortcode,
            'Password' => $password,
            'Timestamp' => $timestamp,
            'TransactionType' => 'CustomerPayBillOnline',
            'Amount' => self::RECOVERY_AMOUNT_KES,
            'PartyA' => $phone,
            'PartyB' => $shortcode,
            'PhoneNumber' => $phone,
            'CallBackURL' => $callbackUrl,
            'AccountReference' => $accountReference,
            'TransactionDesc' => 'SCAN-ID document recovery',
        ];

        $response = self::request(
            'POST',
            self::apiUrl('mpesa/stkpush/v1/processrequest'),
            $accessToken,
            $payload
        );

        $responseCode = (string) (
            $response['ResponseCode'] ?? ''
        );

        if ($responseCode !== '0') {
            throw new RuntimeException(
                'M-Pesa rejected the STK Push request.'
            );
        }

        $checkoutRequestId = trim(
            (string) ($response['CheckoutRequestID'] ?? '')
        );

        $merchantRequestId = trim(
            (string) ($response['MerchantRequestID'] ?? '')
        );

        if ($checkoutRequestId === '') {
            throw new RuntimeException(
                'M-Pesa did not return a checkout request ID.'
            );
        }

        RecoveryPayment::setCheckoutDetails(
            (string) $payment['id'],
            $checkoutRequestId,
            $merchantRequestId !== ''
                ? $merchantRequestId
                : null
        );

        RecoveryRequest::markPaymentPending(
            $recoveryRequestId
        );

        $payment = RecoveryPayment::findById(
            (string) $payment['id']
        );

        return [
            'payment' => $payment,
            'amount_kes' => self::RECOVERY_AMOUNT_KES,
            'status' => 'pending',
            'duplicate' => false,
        ];
    }

    /**
     * Process a successful Daraja callback.
     *
     * Completion is idempotent through the payment model's
     * unique M-Pesa receipt number.
     */
    public static function processCallback(array $callback): array
    {
        $stkCallback = $callback['Body']['stkCallback'] ?? null;

        if (!is_array($stkCallback)) {
            throw new RuntimeException(
                'Invalid M-Pesa callback structure.'
            );
        }

        $checkoutRequestId = trim(
            (string) ($stkCallback['CheckoutRequestID'] ?? '')
        );

        if ($checkoutRequestId === '') {
            throw new RuntimeException(
                'M-Pesa callback is missing CheckoutRequestID.'
            );
        }

        $resultCode = (int) (
            $stkCallback['ResultCode'] ?? -1
        );

        $resultDescription = trim(
            (string) (
                $stkCallback['ResultDesc']
                ?? 'M-Pesa transaction failed.'
            )
        );

        $payment = self::findPaymentByCheckoutRequest(
            $checkoutRequestId
        );

        if ($payment === null) {
            throw new RuntimeException(
                'No SCAN-ID payment matches the M-Pesa checkout request.'
            );
        }

        if ($resultCode !== 0) {
            RecoveryPayment::markFailed(
                (string) $payment['id'],
                $resultCode,
                $resultDescription
            );

            return [
                'success' => false,
                'payment_id' => $payment['id'],
                'status' => 'failed',
            ];
        }

        $items = $stkCallback['CallbackMetadata']['Item'] ?? [];

        if (!is_array($items)) {
            throw new RuntimeException(
                'M-Pesa callback metadata is missing.'
            );
        }

        $metadata = self::metadataToArray($items);

        $amount = (int) round(
            (float) ($metadata['Amount'] ?? 0)
        );

        $receipt = trim(
            (string) ($metadata['MpesaReceiptNumber'] ?? '')
        );

        $phone = self::normalizePhone(
            (string) ($metadata['PhoneNumber'] ?? '')
        );

        if ($amount !== self::RECOVERY_AMOUNT_KES) {
            RecoveryPayment::markFailed(
                (string) $payment['id'],
                -2,
                'Invalid recovery payment amount.'
            );

            throw new RuntimeException(
                'Invalid M-Pesa payment amount.'
            );
        }

        if ($receipt === '') {
            throw new RuntimeException(
                'M-Pesa receipt number is missing.'
            );
        }

        $expectedPhone = self::normalizePhone(
            (string) ($payment['phone'] ?? '')
        );

        if ($phone !== $expectedPhone) {
            RecoveryPayment::markFailed(
                (string) $payment['id'],
                -3,
                'Payment phone number does not match the recovery request.'
            );

            throw new RuntimeException(
                'M-Pesa phone number does not match the recovery payment.'
            );
        }

        $completed = RecoveryPayment::markCompleted(
            (string) $payment['id'],
            $receipt,
            $resultCode,
            $resultDescription
        );

        if ($completed === null) {
            throw new RuntimeException(
                'Unable to complete the SCAN-ID payment.'
            );
        }

        RecoveryRequest::markPaid(
            (string) $payment['recovery_request_id']
        );

        return [
            'success' => true,
            'payment_id' => $completed['id'],
            'status' => 'completed',
            'receipt' => $receipt,
        ];
    }

    private static function accessToken(): string
    {
        $consumerKey = trim(
            (string) ($_ENV['MPESA_CONSUMER_KEY'] ?? '')
        );

        $consumerSecret = trim(
            (string) ($_ENV['MPESA_CONSUMER_SECRET'] ?? '')
        );

        if ($consumerKey === '' || $consumerSecret === '') {
            throw new RuntimeException(
                'M-Pesa consumer credentials are not configured.'
            );
        }

        $credentials = base64_encode(
            $consumerKey . ':' . $consumerSecret
        );

        $response = self::request(
            'GET',
            self::apiUrl(
                'oauth/v1/generate?grant_type=client_credentials'
            ),
            null,
            null,
            [
                'Authorization: Basic ' . $credentials,
            ]
        );

        $token = trim(
            (string) ($response['access_token'] ?? '')
        );

        if ($token === '') {
            throw new RuntimeException(
                'M-Pesa access token was not returned.'
            );
        }

        return $token;
    }

    private static function request(
        string $method,
        string $url,
        ?string $accessToken,
        ?array $payload = null,
        array $additionalHeaders = []
    ): array {
        $curl = curl_init($url);

        if ($curl === false) {
            throw new RuntimeException(
                'Unable to initialize M-Pesa connection.'
            );
        }

        $headers = [
            'Accept: application/json',
        ];

        if ($accessToken !== null) {
            $headers[] = 'Authorization: Bearer ' . $accessToken;
        }

        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
        }

        foreach ($additionalHeaders as $header) {
            $headers[] = $header;
        }

        curl_setopt_array(
            $curl,
            [
                CURLOPT_CUSTOMREQUEST => strtoupper($method),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]
        );

        if ($payload !== null) {
            curl_setopt(
                $curl,
                CURLOPT_POSTFIELDS,
                json_encode(
                    $payload,
                    JSON_THROW_ON_ERROR
                )
            );
        }

        $body = curl_exec($curl);
        $error = curl_error($curl);
        $httpCode = (int) curl_getinfo(
            $curl,
            CURLINFO_HTTP_CODE
        );

        curl_close($curl);

        if ($body === false) {
            throw new RuntimeException(
                'M-Pesa request failed.'
                . ($error !== '' ? ' ' . $error : '')
            );
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new RuntimeException(
                'M-Pesa API request was rejected.'
            );
        }

        try {
            $decoded = json_decode(
                $body,
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (\JsonException) {
            throw new RuntimeException(
                'M-Pesa returned an invalid response.'
            );
        }

        if (!is_array($decoded)) {
            throw new RuntimeException(
                'M-Pesa returned an unexpected response.'
            );
        }

        return $decoded;
    }

    private static function apiUrl(string $path): string
    {
        $environment = strtolower(
            trim((string) ($_ENV['MPESA_ENVIRONMENT'] ?? 'sandbox'))
        );

        $base = $environment === 'production'
            ? 'https://api.safaricom.co.ke/'
            : 'https://sandbox.safaricom.co.ke/';

        return rtrim($base, '/') . '/' . ltrim($path, '/');
    }

    private static function findPaymentByCheckoutRequest(
        string $checkoutRequestId
    ): ?array {
        $connection = Database::connection();

        $statement = $connection->prepare(
            'SELECT *
             FROM recovery_payments
             WHERE checkout_request_id = :checkout_request_id
             LIMIT 1'
        );

        $statement->execute([
            'checkout_request_id' => $checkoutRequestId,
        ]);

        $payment = $statement->fetch();

        return $payment !== false
            ? $payment
            : null;
    }

    private static function metadataToArray(
        array $items
    ): array {
        $result = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $name = trim(
                (string) ($item['Name'] ?? '')
            );

            if ($name === '') {
                continue;
            }

            $result[$name] = $item['Value'] ?? null;
        }

        return $result;
    }

    private static function normalizePhone(
        string $phone
    ): string {
        $phone = trim($phone);

        $phone = preg_replace(
            '/[\s().-]+/',
            '',
            $phone
        );

        if ($phone === null) {
            throw new RuntimeException(
                'Invalid Kenyan phone number.'
            );
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
