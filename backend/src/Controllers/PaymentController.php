<?php

declare(strict_types=1);

namespace ScanId\Controllers;

use ScanId\Http\AuthMiddleware;
use ScanId\Http\Request;
use ScanId\Http\Response;
use ScanId\Models\RecoveryPayment;
use ScanId\Models\RecoveryRequest;
use ScanId\Models\LostDocument;
use Throwable;

final class PaymentController
{
    /**
     * Create a pending recovery payment.
     *
     * The frontend cannot choose the payment amount.
     * SCAN-ID recovery payment is fixed at KSh 300.
     */
    public static function create(): void
    {
        try {
            $user = AuthMiddleware::requireUser();

            $data = Request::json();

            $recoveryRequestId = self::requiredString(
                $data,
                'recovery_request_id'
            );

            $recovery = RecoveryRequest::findById(
                $recoveryRequestId
            );

            if ($recovery === null) {
                Response::error(
                    'Recovery request not found.',
                    404
                );
            }

            if (
                isset($recovery['owner_user_id'])
                && (string) $recovery['owner_user_id']
                    !== (string) $user['id']
            ) {
                Response::error(
                    'You are not allowed to pay for this recovery request.',
                    403
                );
            }

            if (
                !in_array(
                    $recovery['status'],
                    [
                        'pending',
                        'notified',
                        'payment_pending',
                    ],
                    true
                )
            ) {
                Response::error(
                    'This recovery request is not available for payment.',
                    409
                );
            }

            $phone = $recovery['owner_phone'] ?? null;

            if (!is_string($phone) || trim($phone) === '') {
                Response::error(
                    'Owner phone number is missing.',
                    422
                );
            }

            $payment = RecoveryPayment::create(
                $recoveryRequestId,
                $phone
            );

            RecoveryRequest::markPaymentPending(
                $recoveryRequestId
            );

            Response::success(
                [
                    'payment' => $payment,
                    'amount_kes' => 300,
                    'breakdown' => [
                        'finder_reward_kes' => 150,
                        'scan_id_platform_kes' => 150,
                    ],
                    'provider' => 'mpesa',
                ],
                'Recovery payment created.'
            );
        } catch (Throwable $exception) {
            self::handleException($exception);
        }
    }

    /**
     * Return the current payment status.
     */
    public static function show(): void
    {
        try {
            $user = AuthMiddleware::requireUser();

            $id = self::routeId();

            $payment = RecoveryPayment::findById($id);

            if ($payment === null) {
                Response::error(
                    'Payment not found.',
                    404
                );
            }

            $recoveryRequestId = $payment['recovery_request_id'] ?? null;

            if (
                !is_string($recoveryRequestId)
                || trim($recoveryRequestId) === ''
            ) {
                Response::error(
                    'Payment recovery request is invalid.',
                    500
                );
            }

            $recovery = RecoveryRequest::findById(
                $recoveryRequestId
            );

            if ($recovery === null) {
                Response::error(
                    'Recovery request not found.',
                    404
                );
            }

            if (
                isset($recovery['owner_user_id'])
                && (string) $recovery['owner_user_id']
                    !== (string) $user['id']
            ) {
                Response::error(
                    'You are not allowed to access this payment.',
                    403
                );
            }

            Response::success(
                [
                    'payment' => $payment,
                ]
            );
        } catch (Throwable $exception) {
            self::handleException($exception);
        }
    }

    /**
     * List payments belonging to the authenticated user.
     */
    public static function mine(): void
    {
        try {
            $user = AuthMiddleware::requireUser();

            $recoveryRequests = RecoveryRequest::findByOwnerUser(
                (string) $user['id']
            );

            $payments = [];

            foreach ($recoveryRequests as $recovery) {
                $recoveryId = $recovery['id'] ?? null;

                if (!is_string($recoveryId)) {
                    continue;
                }

                $payment = RecoveryPayment::findByRecoveryRequest(
                    $recoveryId
                );

                if ($payment !== null) {
                    $payments[] = $payment;
                }
            }

            Response::success(
                [
                    'payments' => $payments,
                    'count' => count($payments),
                ]
            );
        } catch (Throwable $exception) {
            self::handleException($exception);
        }
    }

    /**
     * M-Pesa callback endpoint.
     *
     * This endpoint must NOT require normal user authentication.
     *
     * The actual Daraja callback verification and idempotent payment
     * processing will be handled by the M-Pesa service.
     */
    public static function callback(): void
    {
        try {
            $data = Request::json();

            if ($data === []) {
                Response::error(
                    'Empty M-Pesa callback.',
                    400
                );
            }

            /*
             * Deliberately do not mark a payment completed here.
             *
             * Daraja callback processing will be added in the
             * dedicated M-Pesa service. Completion must only happen
             * after validating:
             *
             * - checkout request
             * - result code
             * - amount = KSh 300
             * - M-Pesa receipt
             * - phone number
             * - idempotency
             */

            Response::success(
                [
                    'received' => true,
                ],
                'Payment callback received.'
            );
        } catch (Throwable $exception) {
            self::handleException($exception);
        }
    }

    private static function requiredString(
        array $data,
        string $field
    ): string {
        $value = $data[$field] ?? null;

        if (!is_string($value) || trim($value) === '') {
            Response::error(
                ucfirst(str_replace('_', ' ', $field))
                . ' is required.',
                422
            );
        }

        return trim($value);
    }

    private static function routeId(): string
    {
        $id = Request::input('id');

        if (!is_string($id) || trim($id) === '') {
            Response::error(
                'Payment ID is required.',
                400
            );
        }

        $id = trim($id);

        if (
            !preg_match(
                '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/',
                $id
            )
        ) {
            Response::error(
                'Invalid payment ID.',
                400
            );
        }

        return $id;
    }

    private static function handleException(Throwable $exception): void
    {
        if (
            filter_var(
                $_ENV['APP_DEBUG'] ?? false,
                FILTER_VALIDATE_BOOLEAN
            )
        ) {
            Response::error(
                $exception->getMessage(),
                400
            );
        }

        Response::error(
            'Unable to process the payment request.',
            400
        );
    }

    private function __construct()
    {
    }
}
