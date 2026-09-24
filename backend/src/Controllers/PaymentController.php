<?php

declare(strict_types=1);

namespace ScanId\Controllers;

use PDO;
use RuntimeException;
use ScanId\Http\AuthMiddleware;
use ScanId\Http\Request;
use ScanId\Http\Response;
use ScanId\Models\RecoveryPayment;
use ScanId\Models\RecoveryRequest;
use ScanId\Services\MpesaService;

final class PaymentController
{
    private PDO $connection;

    private MpesaService $mpesa;
    private RecoveryRequest $recoveryRequest;
    private RecoveryPayment $payment;

    public function __construct(PDO $connection)
    {
        $this->connection = $connection;

        $this->mpesa = new MpesaService($connection);
        $this->recoveryRequest = new RecoveryRequest($connection);
        $this->payment = new RecoveryPayment($connection);
    }

    /**
     * Initiate the KSh 300 recovery payment.
     *
     * POST /api/payments
     */
    public function create(): void
    {
        $user = AuthMiddleware::requireUser();

        $recoveryRequestId =
            Request::input('recovery_request_id');

        if (
            !is_string($recoveryRequestId) ||
            !$this->isUuid($recoveryRequestId)
        ) {
            Response::error(
                'A valid recovery_request_id is required.',
                422
            );
        }

        $recovery = $this->recoveryRequest->findById(
            $recoveryRequestId
        );

        if ($recovery === null) {
            Response::error(
                'Recovery request not found.',
                404
            );
        }

        if (
            ($recovery['owner_user_id'] ?? null) !==
            $user['id']
        ) {
            Response::error(
                'You are not authorized to pay for this recovery.',
                403
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
            Response::error(
                'This recovery is not available for payment.',
                409
            );
        }

        $ownerPhone = $recovery['owner_phone'] ?? null;

        if (
            !is_string($ownerPhone) ||
            trim($ownerPhone) === ''
        ) {
            Response::error(
                'Recovery owner phone number is missing.',
                409
            );
        }

        try {
            $result = $this->mpesa->initiateRecoveryPayment(
                $recoveryRequestId,
                $ownerPhone
            );

            Response::success(
                $this->publicPaymentInitiation($result),
                'M-Pesa payment request sent. Check your phone and enter your M-Pesa PIN.'
            );
        } catch (RuntimeException $exception) {
            Response::error(
                $exception->getMessage(),
                422
            );
        }
    }

    /**
     * Get one payment.
     *
     * GET /api/payments/{id}
     */
    public function show(): void
    {
        $user = AuthMiddleware::requireUser();

        $paymentId = $this->routeId();

        $payment = $this->payment->findById(
            $paymentId
        );

        if ($payment === null) {
            Response::error(
                'Payment not found.',
                404
            );
        }

        $recovery = $this->recoveryRequest->findById(
            $payment['recovery_request_id']
        );

        if ($recovery === null) {
            Response::error(
                'Associated recovery request not found.',
                404
            );
        }

        if (
            ($recovery['owner_user_id'] ?? null) !==
            $user['id']
        ) {
            Response::error(
                'You are not authorized to view this payment.',
                403
            );
        }

        Response::success(
            $this->publicPayment($payment),
            'Payment retrieved successfully.'
        );
    }

    /**
     * Get the authenticated owner's recovery payments.
     *
     * GET /api/payments
     */
    public function mine(): void
    {
        $user = AuthMiddleware::requireUser();

        $recoveries =
            $this->recoveryRequest->findByOwnerUser(
                $user['id']
            );

        $payments = [];

        foreach ($recoveries as $recovery) {
            $payment = $this->payment
                ->findByRecoveryRequest(
                    $recovery['id']
                );

            if ($payment === null) {
                continue;
            }

            $payments[] = $this->publicPayment(
                $payment
            );
        }

        Response::success(
            [
                'payments' => $payments,
            ],
            'Payments retrieved successfully.'
        );
    }

    /**
     * Daraja STK callback.
     *
     * POST /api/payments/callback
     *
     * This endpoint must NOT require normal user authentication.
     * Safaricom calls it directly.
     */
    public function callback(): void
    {
        $payload = Request::json();

        if (!is_array($payload) || $payload === []) {
            Response::error(
                'Invalid M-Pesa callback payload.',
                400
            );
        }

        try {
            $result = $this->mpesa->processCallback(
                $payload
            );

            /*
             * Daraja should receive a successful HTTP response
             * after the callback has been processed.
             *
             * Do not expose payment internals in the callback
             * response.
             */
            Response::success(
                [
                    'received' => true,
                    'processed' =>
                        (bool) ($result['completed'] ?? false),
                ],
                'M-Pesa callback received.'
            );
        } catch (RuntimeException $exception) {
            /*
             * Return a controlled response without exposing
             * provider credentials, database details, or
             * internal stack information.
             */
            Response::error(
                $exception->getMessage(),
                400
            );
        }
    }

    /**
     * Safe public representation of payment initiation.
     *
     * Never expose:
     * - checkout credentials
     * - M-Pesa callback internals
     * - raw provider response
     * - owner phone
     */
    private function publicPaymentInitiation(
        array $result
    ): array {
        $payment =
            $result['payment'] ?? [];

        return [
            'payment' => $this->publicPayment(
                is_array($payment)
                    ? $payment
                    : []
            ),
            'amount_kes' =>
                (int) ($result['amount_kes'] ?? 300),
            'breakdown' => [
                'finder_reward_kes' => 150,
                'platform_kes' => 150,
                'total_kes' => 300,
            ],
            'checkout_request_id' =>
                $result['checkout_request_id']
                    ?? $payment['checkout_request_id']
                    ?? null,
            'customer_message' =>
                $result['customer_message']
                    ?? 'Check your phone and enter your M-Pesa PIN.',
        ];
    }

    /**
     * Safe payment representation.
     *
     * Sensitive payment fields are deliberately excluded.
     */
    private function publicPayment(
        array $payment
    ): array {
        return [
            'id' =>
                $payment['id'] ?? null,

            'recovery_request_id' =>
                $payment['recovery_request_id'] ?? null,

            'amount_kes' =>
                (int) ($payment['amount_kes'] ?? 300),

            'provider' =>
                $payment['provider'] ?? 'mpesa',

            'status' =>
                $payment['status'] ?? null,

            'checkout_request_id' =>
                $payment['checkout_request_id'] ?? null,

            'paid_at' =>
                $payment['paid_at'] ?? null,

            'created_at' =>
                $payment['created_at'] ?? null,

            'updated_at' =>
                $payment['updated_at'] ?? null,
        ];
    }

    /**
     * Extract and validate route UUID.
     */
    private function routeId(): string
    {
        $id = Request::routeParam('id');

        if (!is_string($id) || !$this->isUuid($id)) {
            Response::error(
                'Invalid payment ID.',
                422
            );
        }

        return $id;
    }

    private function isUuid(string $value): bool
    {
        return preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $value
        ) === 1;
    }

    private function __construct()
    {
    }
}
