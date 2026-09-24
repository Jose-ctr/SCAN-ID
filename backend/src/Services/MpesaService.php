<?php

declare(strict_types=1);

namespace ScanId\Services;

use PDO;
use RuntimeException;
use ScanId\Config\App;
use ScanId\Models\RecoveryPayment;
use ScanId\Models\RecoveryRequest;

final class MpesaService
{
    private const PROVIDER = 'mpesa';

    private PDO $connection;
    private RecoveryPayment $payment;
    private RecoveryRequest $recoveryRequest;

    public function __construct(PDO $connection)
    {
        $this->connection = $connection;
        $this->payment = new RecoveryPayment($connection);
        $this->recoveryRequest = new RecoveryRequest($connection);
    }

    /**
     * Initiate the SCAN-ID recovery payment.
     *
     * The configured recovery amount must remain KSh 300.
     */
    public function initiateRecoveryPayment(
        string $recoveryRequestId,
        string $phone
    ): array {
        $this->assertUuid($recoveryRequestId);

        $phone = self::normalizePhone($phone);

        $amount = App::recoveryFeeKes();

        /*
         * Validate the complete pricing configuration before
         * starting any M-Pesa transaction.
         */
        App::validateRecoveryPricing();

        $recovery = $this->recoveryRequest->findById(
            $recoveryRequestId
        );

        if ($recovery === null) {
            throw new RuntimeException(
                'Recovery request not found.'
            );
        }

        if (
            !in_array(
                $recovery['status'] ?? null,
                [
                    'pending',
                    'notified',
                    'payment_pending',
                ],
                true
            )
        ) {
            throw new RuntimeException(
                'This recovery is not available for payment.'
            );
        }

        $ownerPhone = self::normalizePhone(
            (string) ($recovery['owner_phone'] ?? '')
        );

        if ($ownerPhone !== $phone) {
            throw new RuntimeException(
                'Payment phone number does not match the recovery owner.'
            );
        }

        /*
         * Reuse an existing pending payment when possible.
         * This prevents duplicate STK requests when the user
         * taps Pay more than once.
         */
        $existing = $this->payment
            ->findPendingByRecoveryRequest(
                $recoveryRequestId
            );

        if (
            $existing !== null &&
            !empty($existing['checkout_request_id'])
        ) {
            return [
                'payment' => $existing,
                'amount_kes' => $amount,
                'checkout_request_id' =>
                    $existing['checkout_request_id'],
                'customer_message' =>
                    'A payment request is already pending. Check your phone.',
                'breakdown' => [
                    'finder_reward_kes' =>
                        App::finderRewardKes(),
                    'platform_kes' =>
                        App::platformFeeKes(),
                    'total_kes' => $amount,
                ],
            ];
        }

        /*
         * Create the local payment record first so the callback
         * can always be correlated to a known recovery.
         */
        $payment = $this->payment->create(
            $recoveryRequestId,
            $phone
        );

        try {
            $accessToken = $this->accessToken();

            $shortcode = App::required(
                'MPESA_SHORTCODE'
            );

            $passkey = App::required(
                'MPESA_PASSKEY'
            );

            $callbackUrl = App::required(
                'MPESA_CALLBACK_URL'
            );

            if (
                !filter_var(
                    $callbackUrl,
                    FILTER_VALIDATE_URL
                )
            ) {
                throw new RuntimeException(
                    'MPESA_CALLBACK_URL is invalid.'
                );
            }

            if (
                !str_starts_with(
                    strtolower($callbackUrl),
                    'https://'
                )
            ) {
                throw new RuntimeException(
                    'MPESA_CALLBACK_URL must use HTTPS.'
                );
            }

            $timestamp = gmdate('YmdHis');

            $password = base64_encode(
                $shortcode .
                $passkey .
                $timestamp
            );

            $accountReference =
                'SCANID-' .
                strtoupper(
                    substr(
                        str_replace(
                            '-',
                            '',
                            $recoveryRequestId
                        ),
                        0,
                        12
                    )
                );

            $payload = [
                'BusinessShortCode' =>
                    $shortcode,

                'Password' =>
                    $password,

                'Timestamp' =>
                    $timestamp,

                'TransactionType' =>
                    'CustomerPayBillOnline',

                'Amount' =>
                    $amount,

                'PartyA' =>
                    $phone,

                'PartyB' =>
                    $shortcode,

                'PhoneNumber' =>
                    $phone,

                'CallBackURL' =>
                    $callbackUrl,

                'AccountReference' =>
                    $accountReference,

                'TransactionDesc' =>
                    'SCAN-ID document recovery',
            ];

            $response = $this->request(
                'POST',
                '/mpesa/stkpush/v1/processrequest',
                $payload,
                $accessToken
            );

            $responseCode =
                (string) (
                    $response['ResponseCode']
                    ?? ''
                );

            if ($responseCode !== '0') {
                throw new RuntimeException(
                    'M-Pesa payment request was rejected.'
                );
            }

            $checkoutRequestId =
                $response['CheckoutRequestID']
                ?? null;

            $merchantRequestId =
                $response['MerchantRequestID']
                ?? null;

            if (
                !is_string($checkoutRequestId) ||
                trim($checkoutRequestId) === ''
            ) {
                throw new RuntimeException(
                    'M-Pesa did not return a checkout request ID.'
                );
            }

            $payment = $this->payment->setCheckoutDetails(
                $payment['id'],
                $checkoutRequestId,
                is_string($merchantRequestId)
                    ? $merchantRequestId
                    : null
            );

            $this->recoveryRequest->markPaymentPending(
                $recoveryRequestId
            );

            return [
                'payment' => $payment,
                'amount_kes' => $amount,
                'checkout_request_id' =>
                    $checkoutRequestId,
                'customer_message' =>
                    $response['CustomerMessage']
                    ?? 'Check your phone and enter your M-Pesa PIN.',
                'breakdown' => [
                    'finder_reward_kes' =>
                        App::finderRewardKes(),
                    'platform_kes' =>
                        App::platformFeeKes(),
                    'total_kes' => $amount,
                ],
            ];
        } catch (\Throwable $exception) {
            /*
             * The local payment was created before the provider
             * request. If the provider request fails, do not leave
             * it looking like a usable pending transaction.
             */
            $this->payment->markFailed(
                $payment['id'],
                $exception->getMessage()
            );

            throw $exception;
        }
    }

    /**
     * Process a Daraja STK callback.
     *
     * Callback verification is based on:
     * - known CheckoutRequestID
     * - successful ResultCode
     * - exact payment amount
     * - matching phone number
     * - unique M-Pesa receipt
     * - idempotent database update
     */
    public function processCallback(
        array $callback
    ): array {
        $stkCallback =
            $callback['Body']['stkCallback']
            ?? null;

        if (!is_array($stkCallback)) {
            throw new RuntimeException(
                'Invalid M-Pesa callback structure.'
            );
        }

        $checkoutRequestId =
            $stkCallback['CheckoutRequestID']
            ?? null;

        if (
            !is_string($checkoutRequestId) ||
            trim($checkoutRequestId) === ''
        ) {
            throw new RuntimeException(
                'M-Pesa callback is missing CheckoutRequestID.'
            );
        }

        $payment =
            $this->findPaymentByCheckoutRequest(
                $checkoutRequestId
            );

        if ($payment === null) {
            throw new RuntimeException(
                'Payment associated with this callback was not found.'
            );
        }

        $resultCode = (int) (
            $stkCallback['ResultCode']
            ?? -1
        );

        /*
         * Non-zero result means the STK transaction failed,
         * was cancelled, timed out, or otherwise did not complete.
         */
        if ($resultCode !== 0) {
            $description =
                (string) (
                    $stkCallback['ResultDesc']
                    ?? 'M-Pesa payment failed.'
                );

            $this->payment->markFailed(
                $payment['id'],
                $description
            );

            return [
                'completed' => false,
                'payment' =>
                    $this->payment->findById(
                        $payment['id']
                    ),
            ];
        }

        $metadata = $this->metadataToArray(
            $stkCallback['CallbackMetadata']
                ?? []
        );

        $amount =
            $metadata['Amount']
            ?? null;

        $receipt =
            $metadata['MpesaReceiptNumber']
            ?? null;

        $callbackPhone =
            $metadata['PhoneNumber']
            ?? null;

        $expectedAmount =
            App::recoveryFeeKes();

        if (
            !is_numeric($amount) ||
            (int) $amount !== $expectedAmount
        ) {
            $this->payment->markFailed(
                $payment['id'],
                'M-Pesa callback amount did not match the recovery amount.'
            );

            throw new RuntimeException(
                'M-Pesa callback amount does not match the recovery amount.'
            );
        }

        if (
            !is_string($receipt) ||
            trim($receipt) === ''
        ) {
            $this->payment->markFailed(
                $payment['id'],
                'M-Pesa callback did not contain a receipt number.'
            );

            throw new RuntimeException(
                'M-Pesa receipt number is missing.'
            );
        }

        if (
            !is_numeric($callbackPhone) &&
            !is_string($callbackPhone)
        ) {
            $this->payment->markFailed(
                $payment['id'],
                'M-Pesa callback did not contain a valid phone number.'
            );

            throw new RuntimeException(
                'M-Pesa callback phone number is missing.'
            );
        }

        $callbackPhone =
            self::normalizePhone(
                (string) $callbackPhone
            );

        $paymentPhone =
            self::normalizePhone(
                (string) ($payment['phone'] ?? '')
            );

        if ($callbackPhone !== $paymentPhone) {
            $this->payment->markFailed(
                $payment['id'],
                'M-Pesa callback phone did not match the payment phone.'
            );

            throw new RuntimeException(
                'M-Pesa callback phone does not match the payment.'
            );
        }

        /*
         * markCompleted() is responsible for receipt uniqueness
         * and idempotent completion.
         */
        $completed = $this->payment->markCompleted(
            $payment['id'],
            $receipt,
            $expectedAmount
        );

        $recoveryRequestId =
            $payment['recovery_request_id'];

        $this->recoveryRequest->markPaid(
            $recoveryRequestId
        );

        return [
            'completed' => true,
            'payment' => $completed,
            'recovery_request_id' =>
                $recoveryRequestId,
            'amount_kes' =>
                $expectedAmount,
        ];
    }

    /**
     * Get the Daraja OAuth access token.
     */
    private function accessToken(): string
    {
        $consumerKey = App::required(
            'MPESA_CONSUMER_KEY'
        );

        $consumerSecret = App::required(
            'MPESA_CONSUMER_SECRET'
        );

        $credentials = base64_encode(
            $consumerKey .
            ':' .
            $consumerSecret
        );

        $response = $this->request(
            'GET',
            '/oauth/v1/generate?grant_type=client_credentials',
            null,
            null,
            [
                'Authorization: Basic ' . $credentials,
            ]
        );

        $token =
            $response['access_token']
            ?? null;

        if (
            !is_string($token) ||
            trim($token) === ''
        ) {
            throw new RuntimeException(
                'M-Pesa access token was not returned.'
            );
        }

        return $token;
    }

    /**
     * Perform an HTTP request to Daraja.
     *
     * Provider response bodies are never exposed directly
     * to the caller.
     */
    private function request(
        string $method,
        string $path,
        ?array $payload = null,
        ?string $bearerToken = null,
        array $extraHeaders = []
    ): array {
        $url = $this->apiUrl() . $path;

        $curl = curl_init($url);

        if ($curl === false) {
            throw new RuntimeException(
                'Unable to initialize M-Pesa connection.'
            );
        }

        $headers = [
            'Accept: application/json',
        ];

        if ($payload !== null) {
            $headers[] =
                'Content-Type: application/json';

            $body = json_encode(
                $payload,
                JSON_THROW_ON_ERROR
            );
        } else {
            $body = null;
        }

        if (
            $bearerToken !== null &&
            trim($bearerToken) !== ''
        ) {
            $headers[] =
                'Authorization: Bearer ' .
                $bearerToken;
        }

        foreach ($extraHeaders as $header) {
            $headers[] = $header;
        }

        curl_setopt_array(
            $curl,
            [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]
        );

        if ($body !== null) {
            curl_setopt(
                $curl,
                CURLOPT_POSTFIELDS,
                $body
            );
        }

        $responseBody =
            curl_exec($curl);

        $curlError =
            curl_error($curl);

        $status =
            (int) curl_getinfo(
                $curl,
                CURLINFO_HTTP_CODE
            );

        curl_close($curl);

        if ($responseBody === false) {
            throw new RuntimeException(
                'M-Pesa connection failed.'
            );
        }

        if ($curlError !== '') {
            throw new RuntimeException(
                'M-Pesa connection failed.'
            );
        }

        $decoded = json_decode(
            $responseBody,
            true
        );

        if (!is_array($decoded)) {
            throw new RuntimeException(
                'M-Pesa returned an invalid response.'
            );
        }

        if ($status < 200 || $status >= 300) {
            throw new RuntimeException(
                'M-Pesa request was rejected.'
            );
        }

        return $decoded;
    }

    /**
     * Select sandbox or production Daraja URL.
     */
    private function apiUrl(): string
    {
        $environment = strtolower(
            trim(
                $_ENV['MPESA_ENV']
                    ?? $_SERVER['MPESA_ENV']
                    ?? 'sandbox'
            )
        );

        if ($environment === 'production') {
            return 'https://api.safaricom.co.ke';
        }

        return 'https://sandbox.safaricom.co.ke';
    }

    /**
     * Locate payment by Daraja checkout request ID.
     */
    private function findPaymentByCheckoutRequest(
        string $checkoutRequestId
    ): ?array {
        $statement = $this->connection->prepare(
            'SELECT *
             FROM recovery_payments
             WHERE checkout_request_id = :checkout_request_id
             LIMIT 1'
        );

        $statement->execute([
            'checkout_request_id' =>
                $checkoutRequestId,
        ]);

        $payment = $statement->fetch();

        return $payment === false
            ? null
            : $payment;
    }

    /**
     * Convert CallbackMetadata.Item into a simple associative array.
     */
    private function metadataToArray(
        mixed $metadata
    ): array {
        if (!is_array($metadata)) {
            return [];
        }

        $items =
            $metadata['Item']
            ?? [];

        if (!is_array($items)) {
            return [];
        }

        $result = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $name =
                $item['Name']
                ?? null;

            if (!is_string($name) || $name === '') {
                continue;
            }

            $result[$name] =
                $item['Value']
                ?? null;
        }

        return $result;
    }

    /**
     * Normalize a Kenyan mobile number to 254XXXXXXXXX.
     */
    public static function normalizePhone(
        string $phone
    ): string {
        $phone = preg_replace(
            '/[\s\-\(\)]/',
            '',
            trim($phone)
        );

        if ($phone === null || $phone === '') {
            throw new RuntimeException(
                'Phone number is required.'
            );
        }

        if (str_starts_with($phone, '+254')) {
            $phone = substr($phone, 1);
        }

        if (str_starts_with($phone, '254')) {
            // Already normalized.
        } elseif (
            str_starts_with($phone, '07') ||
            str_starts_with($phone, '01')
        ) {
            $phone =
                '254' .
                substr($phone, 1);
        } else {
            throw new RuntimeException(
                'Invalid Kenyan phone number.'
            );
        }

        if (
            preg_match(
                '/^254(7\d{8}|1\d{8})$/',
                $phone
            ) !== 1
        ) {
            throw new RuntimeException(
                'Invalid Kenyan phone number.'
            );
        }

        return $phone;
    }

    /**
     * Validate UUID.
     */
    private function assertUuid(
        string $value
    ): void {
        if (
            preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
                $value
            ) !== 1
        ) {
            throw new RuntimeException(
                'Invalid recovery request ID.'
            );
        }
    }
}
