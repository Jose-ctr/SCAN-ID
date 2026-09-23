<?php

declare(strict_types=1);

namespace ScanId\Services;

use PDO;
use RuntimeException;
use ScanId\Models\RecoveryRequest;
use ScanId\Models\SmsNotification;
use Throwable;

final class RecoveryNotificationService
{
    public function __construct(
        private readonly PDO $db
    ) {
    }

    /**
     * Notify the owner that a matching found document
     * has been reported.
     *
     * No raw document number is included in the SMS.
     */
    public function notifyOwner(
        string $recoveryRequestId
    ): array {
        if (!self::isUuid($recoveryRequestId)) {
            throw new RuntimeException(
                'Invalid recovery request ID.'
            );
        }

        $recoveryModel = new RecoveryRequest(
            $this->db
        );

        $notificationModel = new SmsNotification(
            $this->db
        );

        $smsService = new SmsService(
            $this->db
        );

        $recovery = $recoveryModel->findById(
            $recoveryRequestId
        );

        if ($recovery === null) {
            throw new RuntimeException(
                'Recovery request not found.'
            );
        }

        $status = (string) (
            $recovery['status'] ?? ''
        );

        if (
            !in_array(
                $status,
                [
                    'pending',
                    'notified',
                    'payment_pending',
                ],
                true
            )
        ) {
            throw new RuntimeException(
                'This recovery request cannot send an owner notification in its current status.'
            );
        }

        $ownerPhone = trim(
            (string) (
                $recovery['owner_phone'] ?? ''
            )
        );

        if ($ownerPhone === '') {
            throw new RuntimeException(
                'Recovery request has no owner phone number.'
            );
        }

        /*
         * Prevent accidental duplicate SMS notifications
         * when the same recovery request is retried.
         */
        $existingNotifications =
            $notificationModel->findByRecoveryRequest(
                $recoveryRequestId
            );

        foreach ($existingNotifications as $existing) {
            $existingStatus = (string) (
                $existing['status'] ?? ''
            );

            if (
                in_array(
                   
