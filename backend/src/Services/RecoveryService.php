<?php

declare(strict_types=1);

namespace ScanId\Services;

use PDO;
use RuntimeException;
use ScanId\Models\AuditLog;
use ScanId\Models\FoundDocument;
use ScanId\Models\LostDocument;
use ScanId\Models\RecoveryRequest;

final class RecoveryService
{
    public function __construct(
        private readonly PDO $db
    ) {
    }

    /**
     * Create a recovery request after secure server-side matching.
     *
     * Flow:
     * 1. Authenticate owner externally.
     * 2. Load lost and found documents.
     * 3. Verify ownership.
     * 4. Verify document type.
     * 5. Compare protected document hashes.
     * 6. Prevent duplicate/self recovery.
     * 7. Create recovery request.
     * 8. Update lost/found statuses atomically.
     * 9. Attempt owner SMS notification after commit.
     */
    public function create(
        string $lostDocumentId,
        string $foundDocumentId,
        string $ownerUserId,
        ?string $ownerPhone = null
    ): array {
        $lostDocumentId = trim($lostDocumentId);
        $foundDocumentId = trim($foundDocumentId);
        $ownerUserId = trim($ownerUserId);

        if (
            !self::isUuid($lostDocumentId)
            || !self::isUuid($foundDocumentId)
            || !self::isUuid($ownerUserId)
        ) {
            throw new RuntimeException(
                'Invalid recovery identifiers.'
            );
        }

        if ($lostDocumentId === $foundDocumentId) {
            throw new RuntimeException(
                'Lost and found document references must be different.'
            );
        }

        $lostModel = new LostDocument($this->db);
        $foundModel = new FoundDocument($this->db);
        $recoveryModel = new RecoveryRequest($this->db);

        $lost = $lostModel->findById(
            $lostDocumentId
        );

        if ($lost === null) {
            throw new RuntimeException(
                'Lost document not found.'
            );
        }

        $found = $foundModel->findById(
            $foundDocumentId
        );

        if ($found === null) {
            throw new RuntimeException(
                'Found document not found.'
            );
        }

        /*
         * The authenticated user must own the lost report.
         */
        if (
            ($lost['owner_user_id'] ?? null)
            !== $ownerUserId
        ) {
            throw new RuntimeException(
                'You are not authorized to recover this lost document.'
            );
        }

        /*
         * Prevent a user from recovering a document
         * they reported as the finder.
         */
        if (
            ($found['finder_user_id'] ?? null) !== null
            && ($found['finder_user_id'] ?? null)
                === $ownerUserId
        ) {
            throw new RuntimeException(
                'A document cannot be matched to its own finder report.'
            );
        }

        /*
         * Anonymous finders must still provide a contact number.
         */
        $finderPhone = trim(
            (string) (
                $found['finder_phone'] ?? ''
            )
        );

        if (
            ($found['finder_user_id'] ?? null) === null
            && $finderPhone === ''
        ) {
            throw new RuntimeException(
                'The found document has no valid finder contact.'
            );
        }

        /*
         * Document type must match.
         */
        if (
            ($lost['document_type'] ?? null)
            !== ($found['document_type'] ?? null)
        ) {
            throw new RuntimeException(
                'The document types do not match.'
            );
        }

        /*
         * CRITICAL MATCHING CHECK.
         *
         * Only the protected SHA-256 document identifier
         * is compared. Raw document numbers are never
         * exposed through this service.
         */
        $lostHash = (string) (
            $lost['document_number_hash'] ?? ''
        );

        $foundHash = (string) (
            $found['document_number_hash'] ?? ''
        );

        if (
            $lostHash === ''
            || $foundHash === ''
            || !hash_equals($lostHash, $foundHash)
        ) {
            throw new RuntimeException(
                'The lost and found documents do not match.'
            );
        }

        /*
         * Only active reports can enter recovery.
         */
        $lostStatus = (string) (
            $lost['status'] ?? ''
        );

        $foundStatus = (string) (
            $found['status'] ?? ''
        );

        if (
            !in_array(
                $lostStatus,
                ['lost', 'matched'],
                true
            )
        ) {
            throw new RuntimeException(
                'The lost document is no longer available for recovery.'
            );
        }

        if (
            !in_array(
                $foundStatus,
                ['found', 'owner_notified'],
                true
            )
        ) {
            throw new RuntimeException(
                'The found document is no longer available for recovery.'
            );
        }

        /*
         * Prevent duplicate active recovery requests
         * for the same found document.
         */
        $existing = $recoveryModel->findActiveByFoundDocument(
            $foundDocumentId
        );

        if ($existing !== null) {
            if (
                ($existing['owner_user_id'] ?? null)
                === $ownerUserId
            ) {
                return $existing;
            }

            throw new RuntimeException(
                'This found document is already associated with an active recovery request.'
            );
        }

        /*
         * Prevent multiple active recoveries for the
         * same lost-document report.
         */
        $existingLost = $recoveryModel->findByLostDocument(
            $lostDocumentId
        );

        foreach ($existingLost as $existingRequest) {
            if (
                self::isActiveRecoveryStatus(
                    (string) (
                        $existingRequest['status'] ?? ''
                    )
                )
            ) {
                if (
                    ($existingRequest['owner_user_id'] ?? null)
                    === $ownerUserId
                ) {
                    return $existingRequest;
                }

                throw new RuntimeException(
                    'This lost document already has an active recovery request.'
                );
            }
        }

        /*
         * Use the phone from the lost-document report unless
         * the caller explicitly supplies the same owner's phone.
         */
        $phone = $ownerPhone !== null
            ? LostDocument::normalizePhone(
                $ownerPhone
            )
            : trim(
                (string) (
                    $lost['owner_phone'] ?? ''
                )
            );

        if ($phone === '') {
            throw new RuntimeException(
                'The lost document has no owner phone number.'
            );
        }

        /*
         * The authenticated user's supplied phone must not
         * silently replace a different phone stored on the
         * lost report.
         */
        if (
            $ownerPhone !== null
            && $phone !== (
                string) (
                    $lost['owner_phone'] ?? ''
                )
        ) {
            throw new RuntimeException(
                'The supplied owner phone does not match the lost-document report.'
            );
        }

        $this->db->beginTransaction();

        try {
            $recovery = $recoveryModel->create(
                $lostDocumentId,
                $foundDocumentId,
                $ownerUserId,
                $phone
            );

            $lostModel->markMatched(
                $lostDocumentId
            );

            $foundModel->markRecoveryRequested(
                $foundDocumentId
            );

            AuditLog::create(
                $this->db,
                $ownerUserId,
                'recovery_request_created',
                'recovery_request',
                (string) $recovery['id'],
                null,
                [
                    'lost_document_id' =>
                        $lostDocumentId,
                    'found_document_id' =>
                        $foundDocumentId,
                ]
            );

            $this->db->commit();
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }

        /*
         * SMS is deliberately sent AFTER the database transaction
         * commits. A slow/failing SMS provider must never roll back
         * a valid document match.
         *
         * The recovery remains pending if notification fails and
         * can safely be retried.
         */
        try {
            $notificationService =
                new RecoveryNotificationService(
                    $this->db
                );

            $notification =
                $notificationService->notifyOwner(
                    (string) $recovery['id']
                );

            $recovery['status'] =
                'notified';

            $recovery['notification'] =
                $notification;
        } catch (\Throwable $exception) {
            /*
             * Do not expose SMS provider details to the client.
             *
             * The recovery itself remains valid. A later retry
             * can send the notification.
             */
            $recovery['notification'] = [
                'sent' => false,
                'pending_retry' => true,
            ];
        }

        return $recovery;
    }

    /**
     * Find a recovery request by ID.
     */
    public function findById(
        string $recoveryRequestId
    ): ?array {
        if (!self::isUuid($recoveryRequestId)) {
            return null;
        }

        $model = new RecoveryRequest(
            $this->db
        );

        return $model->findById(
            $recoveryRequestId
        );
    }

    /**
     * Find the active recovery for a found document.
     */
    public function findActiveByFoundDocument(
        string $foundDocumentId
    ): ?array {
        if (!self::isUuid($foundDocumentId)) {
            return null;
        }

        $model = new RecoveryRequest(
            $this->db
        );

        return $model->findActiveByFoundDocument(
            $foundDocumentId
        );
    }

    /**
     * Retry owner notification.
     */
    public function notifyOwner(
        string $recoveryRequestId
    ): array {
        if (!self::isUuid($recoveryRequestId)) {
            throw new RuntimeException(
                'Invalid recovery request ID.'
            );
        }

        $service =
            new RecoveryNotificationService(
                $this->db
            );

        return $service->notifyOwner(
            $recoveryRequestId
        );
    }

    /**
     * Mark recovery as notified.
     */
    public function markNotified(
        string $recoveryRequestId
    ): array {
        $model = new RecoveryRequest(
            $this->db
        );

        $recovery = $model->findById(
            $recoveryRequestId
        );

        if ($recovery === null) {
            throw new RuntimeException(
                'Recovery request not found.'
            );
        }

        $model->markNotified(
            $recoveryRequestId
        );

        return $this->reloadRecovery(
            $model,
            $recoveryRequestId
        );
    }

    /**
     * Mark recovery as payment pending.
     */
    public function markPaymentPending(
        string $recoveryRequestId
    ): array {
        $model = new RecoveryRequest(
            $this->db
        );

        $recovery = $model->findById(
            $recoveryRequestId
        );

        if ($recovery === null) {
            throw new RuntimeException(
                'Recovery request not found.'
            );
        }

        $model->markPaymentPending(
            $recoveryRequestId
        );

        return $this->reloadRecovery(
            $model,
            $recoveryRequestId
        );
    }

    /**
     * Mark recovery as paid.
     */
    public function markPaid(
        string $recoveryRequestId
    ): array {
        $model = new RecoveryRequest(
            $this->db
        );

        $recovery = $model->findById(
            $recoveryRequestId
        );

        if ($recovery === null) {
            throw new RuntimeException(
                'Recovery request not found.'
            );
        }

        $model->markPaid(
            $recoveryRequestId
        );

        return $this->reloadRecovery(
            $model,
            $recoveryRequestId
        );
    }

    /**
     * Release contact only after verified payment.
     */
    public function releaseContact(
        string $recoveryRequestId
    ): array {
        $model = new RecoveryRequest(
            $this->db
        );

        $recovery = $model->findById(
            $recoveryRequestId
        );

        if ($recovery === null) {
            throw new RuntimeException(
                'Recovery request not found.'
            );
        }

        if (
            !in_array(
                (string) (
                    $recovery['status'] ?? ''
                ),
                ['paid', 'contact_released'],
                true
            )
        ) {
            throw new RuntimeException(
                'Contact cannot be released before payment is verified.'
            );
        }

        if (
            (string) (
                $recovery['status'] ?? ''
            ) !== 'contact_released'
        ) {
            $model->markContactReleased(
                $recoveryRequestId
            );
        }

        return $this->reloadRecovery(
            $model,
            $recoveryRequestId
        );
    }

    /**
     * Complete recovery.
     *
     * Final handover verification must be performed by
     * HandoverService before this method is called.
     */
    public function complete(
        string $recoveryRequestId
    ): array {
        $model = new RecoveryRequest(
            $this->db
        );

        $recovery = $model->findById(
            $recoveryRequestId
        );

        if ($recovery === null) {
            throw new RuntimeException(
                'Recovery request not found.'
            );
        }

        if (
            !in_array(
                (string) (
                    $recovery['status'] ?? ''
                ),
                ['paid', 'contact_released'],
                true
            )
        ) {
            throw new RuntimeException(
                'Recovery cannot be completed in its current status.'
            );
        }

        $model->markCompleted(
            $recoveryRequestId
        );

        return $this->reloadRecovery(
            $model,
            $recoveryRequestId
        );
    }

    /**
     * Cancel recovery.
     */
    public function cancel(
        string $recoveryRequestId,
        string $ownerUserId
    ): array {
        if (
            !self::isUuid($recoveryRequestId)
            || !self::isUuid($ownerUserId)
        ) {
            throw new RuntimeException(
                'Invalid recovery identifiers.'
            );
        }

        $model = new RecoveryRequest(
            $this->db
        );

        $recovery = $model->findById(
            $recoveryRequestId
        );

        if ($recovery === null) {
            throw new RuntimeException(
                'Recovery request not found.'
            );
        }

        if (
            ($recovery['owner_user_id'] ?? null)
            !== $ownerUserId
        ) {
            throw new RuntimeException(
                'You are not authorized to cancel this recovery request.'
            );
        }

        $model->cancel(
            $recoveryRequestId
        );

        return $this->reloadRecovery(
            $model,
            $recoveryRequestId
        );
    }

    /**
     * Reload a recovery request after a state change.
     */
    private function reloadRecovery(
        RecoveryRequest $model,
        string $recoveryRequestId
    ): array {
        $updated = $model->findById(
            $recoveryRequestId
        );

        if ($updated === null) {
            throw new RuntimeException(
                'Recovery request could not be reloaded.'
            );
        }

        return $updated;
    }

    /**
     * Determine whether a recovery is still active.
     */
    private static function isActiveRecoveryStatus(
        string $status
    ): bool {
        return in_array(
            $status,
            [
                'pending',
                'notified',
                'payment_pending',
                'paid',
                'contact_released',
            ],
            true
        );
    }

    /**
     * Validate UUID format.
     */
    private static function isUuid(
        string $value
    ): bool {
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
