<?php

declare(strict_types=1);

namespace ScanId\Controllers;

use ScanId\Config\Database;
use ScanId\Http\AuthMiddleware;
use ScanId\Http\Request;
use ScanId\Http\Response;
use ScanId\Models\RecoveryRequest;
use ScanId\Models\SmsNotification;
use ScanId\Services\SmsService;
use Throwable;

final class SmsController
{
    /**
     * Send the recovery notification to the document owner.
     *
     * Only the authenticated owner of the recovery request
     * can trigger this operation.
     */
    public static function notifyOwner(): void
    {
        $user = AuthMiddleware::requireUser();

        $input = Request::input();

        $recoveryRequestId = trim(
            (string) ($input['recovery_request_id'] ?? '')
        );

        if (!self::isUuid($recoveryRequestId)) {
            Response::error(
                'A valid recovery request ID is required.',
                422
            );
        }

        try {
            $db = Database::connection();

            $recoveryModel = new RecoveryRequest($db);
            $notificationModel = new SmsNotification($db);
            $smsService = new SmsService($db);

            $recovery = $recoveryModel->findById(
                $recoveryRequestId
            );

            if ($recovery === null) {
                Response::error(
                    'Recovery request not found.',
                    404
                );
            }

            if (
                ($recovery['owner_user_id'] ?? null)
                !== ($user['id'] ?? null)
            ) {
                Response::error(
                    'You are not authorized to access this recovery request.',
                    403
                );
            }

            $status = (string) ($recovery['status'] ?? '');

            if (
                !in_array(
                    $status,
                    ['pending', 'notified', 'payment_pending'],
                    true
                )
            ) {
                Response::error(
                    'This recovery request cannot receive a new notification in its current status.',
                    409
                );
            }

            $ownerPhone = (string) ($recovery['owner_phone'] ?? '');

            if ($ownerPhone === '') {
                Response::error(
                    'Recovery request has no owner phone number.',
                    422
                );
            }

            /*
             * Do not allow arbitrary message content from the client.
             * The recovery notification is generated server-side.
             */
            $message = self::buildRecoveryMessage(
                $recoveryRequestId
            );

            $notification = $notificationModel->create(
                $ownerPhone,
                $message,
                $recoveryRequestId
            );

            try {
                $providerResult = $smsService->send(
                    $ownerPhone,
                    $message
                );

                $providerMessageId =
                    $providerResult['provider_message_id']
                    ?? null;

                $notificationModel->markSent(
                    (string) $notification['id'],
                    $providerMessageId
                );

                /*
                 * Only mark the recovery request as notified after
                 * Africa's Talking accepts the SMS successfully.
                 */
                $recoveryModel->markNotified(
                    $recoveryRequestId
                );

                $updatedNotification =
                    $notificationModel->findById(
                        (string) $notification['id']
                    );

                Response::success([
                    'notification' => self::publicNotification(
                        $updatedNotification
                        ?? $notification
                    ),
                    'message' =>
                        'Recovery notification sent successfully.',
                ]);
            } catch (Throwable $exception) {
                $notificationModel->markFailed(
                    (string) $notification['id']
                );

                throw $exception;
            }
        } catch (Throwable $exception) {
            if (
                $exception instanceof \RuntimeException
            ) {
                Response::error(
                    $exception->getMessage(),
                    422
                );
            }

            Response::error(
                'Unable to send the recovery notification.',
                500
            );
        }
    }

    /**
     * View SMS notifications belonging to a recovery request.
     *
     * Only the authenticated recovery owner may view them.
     */
    public static function history(): void
    {
        $user = AuthMiddleware::requireUser();

        $recoveryRequestId = trim(
            (string) Request::query(
                'recovery_request_id',
                ''
            )
        );

        if (!self::isUuid($recoveryRequestId)) {
            Response::error(
                'A valid recovery request ID is required.',
                422
            );
        }

        try {
            $db = Database::connection();

            $recoveryModel = new RecoveryRequest($db);
            $notificationModel = new SmsNotification($db);

            $recovery = $recoveryModel->findById(
                $recoveryRequestId
            );

            if ($recovery === null) {
                Response::error(
                    'Recovery request not found.',
                    404
                );
            }

            if (
                ($recovery['owner_user_id'] ?? null)
                !== ($user['id'] ?? null)
            ) {
                Response::error(
                    'You are not authorized to view these notifications.',
                    403
                );
            }

            $notifications =
                $notificationModel->findByRecoveryRequest(
                    $recoveryRequestId
                );

            $publicNotifications = array_map(
                static function (array $notification): array {
                    return self::publicNotification(
                        $notification
                    );
                },
                $notifications
            );

            Response::success([
                'notifications' => $publicNotifications,
            ]);
        } catch (Throwable $exception) {
            if (
                $exception instanceof \RuntimeException
            ) {
                Response::error(
                    $exception->getMessage(),
                    422
                );
            }

            Response::error(
                'Unable to load SMS notification history.',
                500
            );
        }
    }

    /**
     * Build a fixed recovery notification.
     *
     * Never include the raw document number.
     */
    private static function buildRecoveryMessage(
        string $recoveryRequestId
    ): string {
        $reference = strtoupper(
            substr(
                str_replace('-', '', $recoveryRequestId),
                0,
                8
            )
        );

        return sprintf(
            'SCAN-ID: A found document matching your lost-document report has been located. Recovery reference: %s. Open your secure SCAN-ID recovery link to verify and continue. Do not share OTPs or M-Pesa PINs.',
            $reference
        );
    }

    /**
     * Remove sensitive SMS data from API responses.
     */
    private static function publicNotification(
        array $notification
    ): array {
        return [
            'id' => $notification['id'] ?? null,
            'recovery_request_id' =>
                $notification['recovery_request_id'] ?? null,
            'provider' =>
                $notification['provider'] ?? null,
            'status' =>
                $notification['status'] ?? null,
            'sent_at' =>
                $notification['sent_at'] ?? null,
            'delivered_at' =>
                $notification['delivered_at'] ?? null,
            'created_at' =>
                $notification['created_at'] ?? null,
        ];
    }

    private static function isUuid(string $value): bool
    {
        return preg_match(
            '/^[0-9a-fA-F]{8}-'
            . '[0-9a-fA-F]{4}-'
            . '[1-5][0-9a-fA-F]{3}-'
            . '[89abAB][0-9a-fA-F]{3}-'
            . '[0-9a-fA-F]{12}$/',
            $value
        ) === 1;
    }

    private function __construct()
    {
    }
}
