<?php

declare(strict_types=1);

namespace ScanId\Services;

use PDO;
use RuntimeException;
use ScanId\Models\AuditLog;
use ScanId\Models\FoundDocument;
use ScanId\Models\Handover;
use ScanId\Models\LostDocument;
use ScanId\Models\RecoveryRequest;
use ScanId\Models\RecoveryToken;

final class HandoverService
{
    public function __construct(
        private readonly PDO $db
    ) {
    }

    /**
     * Create a handover record for a paid recovery.
     */
    public function create(
        string $recoveryRequestId,
        string $ownerUserId,
        ?string $safeLocation = null,
        ?string $scheduledAt = null,
        ?string $notes = null
    ): array {
        $this->assertUuid(
            $recoveryRequestId,
            'Invalid recovery request ID.'
        );

        $this->assertUuid(
            $ownerUserId,
            'Invalid owner user ID.'
        );

        $recoveryModel = new RecoveryRequest(
            $this->db
        );

        $handoverModel = new Handover(
            $this->db
        );

        $recovery = $this->getOwnedRecovery(
            $recoveryModel,
            $recoveryRequestId,
            $ownerUserId
        );

        $status = (string) (
            $recovery['status'] ?? ''
        );

        if (
            !in_array(
                $status,
                ['paid', 'contact_released'],
                true
            )
        ) {
            throw new RuntimeException(
                'Handover cannot be created before payment is verified.'
            );
        }

        $existing = $handoverModel->findByRecoveryRequest(
            $recoveryRequestId
        );

        if ($existing !== null) {
            return $existing;
        }

        $handover = $handoverModel->create(
            $recoveryRequestId,
            $safeLocation,
            $scheduledAt,
            $notes
        );

        AuditLog::create(
            $this->db,
            $ownerUserId,
            'handover_created',
            'handover',
            (string) $handover['id'],
            null,
            [
                'recovery_request_id' =>
                    $recoveryRequestId,
            ]
        );

        return $handover;
    }

    /**
     * Get a handover belonging to the authenticated owner.
     */
    public function findForOwner(
        string $handoverId,
        string $ownerUserId
    ): ?array {
        $this->assertUuid(
            $handoverId,
            'Invalid handover ID.'
        );

        $this->assertUuid(
            $ownerUserId,
            'Invalid owner user ID.'
        );

        $handoverModel = new Handover(
            $this->db
        );

        $handover = $handoverModel->findById(
            $handoverId
        );

        if ($handover === null) {
            return null;
        }

        $recoveryRequestId = (string) (
            $handover['recovery_request_id'] ?? ''
        );

        if (!self::isUuid($recoveryRequestId)) {
            throw new RuntimeException(
                'Handover has an invalid recovery reference.'
            );
        }

        $recoveryModel = new RecoveryRequest(
            $this->db
        );

        $recovery = $recoveryModel->findById(
            $recoveryRequestId
        );

        if ($recovery === null) {
            throw new RuntimeException(
                'Associated recovery request not found.'
            );
        }

        if (
            ($recovery['owner_user_id'] ?? null)
            !== $ownerUserId
        ) {
            throw new RuntimeException(
                'You are not authorized to access this handover.'
            );
        }

        return $handover;
    }

    /**
     * Schedule a handover.
     */
    public function schedule(
        string $handoverId,
        string $ownerUserId,
        string $scheduledAt
    ): array {
        $handover = $this->findForOwner(
            $handoverId,
            $ownerUserId
        );

        if ($handover === null) {
            throw new RuntimeException(
                'Handover not found.'
            );
        }

        $scheduledAt = trim($scheduledAt);

        if ($scheduledAt === '') {
            throw new RuntimeException(
                'Scheduled handover time is required.'
            );
        }

        $timestamp = strtotime($scheduledAt);

        if ($timestamp === false) {
            throw new RuntimeException(
                'Invalid scheduled handover time.'
            );
        }

        if ($timestamp <= time()) {
            throw new RuntimeException(
                'Handover time must be in the future.'
            );
        }

        $handoverModel = new Handover(
            $this->db
        );

        $handoverModel->schedule(
            $handoverId,
            date(
                'Y-m-d H:i:s',
                $timestamp
            )
        );

        return $this->reload(
            $handoverModel,
            $handoverId
        );
    }

    /**
     * Update the safe handover location.
     */
    public function updateLocation(
        string $handoverId,
        string $ownerUserId,
        string $safeLocation
    ): array {
        $handover = $this->findForOwner(
            $handoverId,
            $ownerUserId
        );

        if ($handover === null) {
            throw new RuntimeException(
                'Handover not found.'
            );
        }

        $safeLocation = trim($safeLocation);

        if ($safeLocation === '') {
            throw new RuntimeException(
                'Safe handover location is required.'
            );
        }

        if (mb_strlen($safeLocation) > 500) {
            throw new RuntimeException(
                'Safe handover location is too long.'
            );
        }

        $handoverModel = new Handover(
            $this->db
        );

        $handoverModel->updateSafeLocation(
            $handoverId,
            $safeLocation
        );

        return $this->reload(
            $handoverModel,
            $handoverId
        );
    }

    /**
     * Update handover notes.
     */
    public function updateNotes(
        string $handoverId,
        string $ownerUserId,
        ?string $notes
    ): array {
        $handover = $this->findForOwner(
            $handoverId,
            $ownerUserId
        );

        if ($handover === null) {
            throw new RuntimeException(
                'Handover not found.'
            );
        }

        if ($notes !== null) {
            $notes = trim($notes);

            if (mb_strlen($notes) > 2000) {
                throw new RuntimeException(
                    'Handover notes are too long.'
                );
            }
        }

        $handoverModel = new Handover(
            $this->db
        );

        $handoverModel->updateNotes(
            $handoverId,
            $notes
        );

        return $this->reload(
            $handoverModel,
            $handoverId
        );
    }

    /**
     * Create a secure handover token.
     *
     * The raw token is returned once to the caller.
     * Only its SHA-256 hash is stored.
     */
    public function createHandoverToken(
        string $handoverId,
        string $ownerUserId,
        int $expiresInSeconds = 1800
    ): array {
        $handover = $this->findForOwner(
            $handoverId,
            $ownerUserId
        );

        if ($handover === null) {
            throw new RuntimeException(
                'Handover not found.'
            );
        }

        $status = (string) (
            $handover['status'] ?? ''
        );

        if (
            in_array(
                $status,
                ['completed', 'cancelled'],
                true
            )
        ) {
            throw new RuntimeException(
                'A token cannot be created for a closed handover.'
            );
        }

        if (
            $expiresInSeconds < 300
            || $expiresInSeconds > 3600
        ) {
            throw new RuntimeException(
                'Handover token expiry must be between 5 and 60 minutes.'
            );
        }

        $recoveryRequestId = (string) (
            $handover['recovery_request_id'] ?? ''
        );

        $tokenModel = new RecoveryToken(
            $this->db
        );

        return $tokenModel->create(
            $recoveryRequestId,
            'handover',
            $expiresInSeconds
        );
    }

    /**
     * Verify and consume a handover token.
     */
    public function verifyHandoverToken(
        string $rawToken
    ): array {
        $rawToken = trim($rawToken);

        if ($rawToken === '') {
            throw new RuntimeException(
                'Handover token is required.'
            );
        }

        $tokenModel = new RecoveryToken(
            $this->db
        );

        $token = $tokenModel->findValid(
            $rawToken,
            'handover'
        );

        if ($token === null) {
            throw new RuntimeException(
                'Invalid or expired handover token.'
            );
        }

        $tokenId = (string) (
            $token['id'] ?? ''
        );

        $recoveryRequestId = (string) (
            $token['recovery_request_id'] ?? ''
        );

        if (
            !self::isUuid($tokenId)
            || !self::isUuid($recoveryRequestId)
        ) {
            throw new RuntimeException(
                'Invalid handover token reference.'
            );
        }

        return [
            'token' => $token,
            'token_id' => $tokenId,
            'recovery_request_id' =>
                $recoveryRequestId,
        ];
    }

    /**
     * Complete a verified handover.
     *
     * This method requires:
     * - valid recovery
     * - verified payment
     * - handover record
     * - finder consent
     * - valid one-time handover token
     *
     * The token is consumed in the same transaction as
     * the handover and recovery state changes.
     */
    public function complete(
        string $handoverId,
        string $ownerUserId,
        string $rawToken
    ): array {
        $handover = $this->findForOwner(
            $handoverId,
            $ownerUserId
        );

        if ($handover === null) {
            throw new RuntimeException(
                'Handover not found.'
            );
        }

        if (
            ($handover['status'] ?? null)
            === 'completed'
        ) {
            return $this->buildCompletionResult(
                $handover,
                null
            );
        }

        if (
            ($handover['status'] ?? null)
            === 'cancelled'
        ) {
            throw new RuntimeException(
                'This handover has been cancelled.'
            );
        }

        if (
            !$handover['finder_consent']
        ) {
            throw new RuntimeException(
                'Finder consent is required before handover.'
            );
        }

        $recoveryRequestId = (string) (
            $handover['recovery_request_id'] ?? ''
        );

        $recoveryModel = new RecoveryRequest(
            $this->db
        );

        $recovery = $recoveryModel->findById(
            $recoveryRequestId
        );

        if ($recovery === null) {
            throw new RuntimeException(
                'Associated recovery request not found.'
            );
        }

        if (
            ($recovery['owner_user_id'] ?? null)
            !== $ownerUserId
        ) {
            throw new RuntimeException(
                'You are not authorized to complete this recovery.'
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
                'Recovery payment has not been verified.'
            );
        }

        $tokenData = $this->verifyHandoverToken(
            $rawToken
        );

        if (
            $tokenData['recovery_request_id']
            !== $recoveryRequestId
        ) {
            throw new RuntimeException(
                'Handover token does not belong to this recovery.'
            );
        }

        $this->db->beginTransaction();

        try {
            $tokenModel = new RecoveryToken(
                $this->db
            );

            $consumed = $tokenModel->consume(
                $rawToken,
                'handover'
            );

            if ($consumed === null) {
                throw new RuntimeException(
                    'Handover token is invalid, expired, or already used.'
                );
            }

            $handoverModel = new Handover(
                $this->db
            );

            $handoverModel->complete(
                $handoverId
            );

            $recoveryModel->markCompleted(
                $recoveryRequestId
            );

            $lostModel = new LostDocument(
                $this->db
            );

            $foundModel = new FoundDocument(
                $this->db
            );

            $lostDocumentId = (string) (
                $recovery['lost_document_id'] ?? ''
            );

            $foundDocumentId = (string) (
                $recovery['found_document_id'] ?? ''
            );

            if (self::isUuid($lostDocumentId)) {
                $lostModel->markRecovered(
                    $lostDocumentId
                );
            }

            if (self::isUuid($foundDocumentId)) {
                $foundModel->markReturned(
                    $foundDocumentId
                );
            }

            AuditLog::create(
                $this->db,
                $ownerUserId,
                'recovery_completed',
                'recovery_request',
                $recoveryRequestId,
                null,
                [
                    'handover_id' =>
                        $handoverId,
                    'token_type' =>
                        'handover',
                ]
            );

            $this->db->commit();

            $updatedHandover =
                $handoverModel->findById(
                    $handoverId
                );

            if ($updatedHandover === null) {
                throw new RuntimeException(
                    'Completed handover could not be reloaded.'
                );
            }

            return $this->buildCompletionResult(
                $updatedHandover,
                $recoveryModel->findById(
                    $recoveryRequestId
                )
            );
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * Grant finder consent.
     *
     * This method is intentionally separated from owner operations.
     * A future finder-facing endpoint can authenticate using a
     * secure recovery/handover token rather than exposing finder
     * credentials.
     */
    public function grantFinderConsent(
        string $handoverId,
        string $rawToken
    ): array {
        $rawToken = trim($rawToken);

        if ($rawToken === '') {
            throw new RuntimeException(
                'Finder consent token is required.'
            );
        }

        $handoverModel = new Handover(
            $this->db
        );

        $handover = $handoverModel->findById(
            $handoverId
        );

        if ($handover === null) {
            throw new RuntimeException(
                'Handover not found.'
            );
        }

        if (
            ($handover['status'] ?? null)
            === 'cancelled'
        ) {
            throw new RuntimeException(
                'This handover has been cancelled.'
            );
        }

        if (
            ($handover['status'] ?? null)
            === 'completed'
        ) {
            throw new RuntimeException(
                'This handover has already been completed.'
            );
        }

        $tokenModel = new RecoveryToken(
            $this->db
        );

        $token = $tokenModel->findValid(
            $rawToken,
            'handover'
        );

        if ($token === null) {
            throw new RuntimeException(
                'Invalid or expired finder consent token.'
            );
        }

        if (
            ($token['recovery_request_id'] ?? null)
            !== ($handover['recovery_request_id'] ?? null)
        ) {
            throw new RuntimeException(
                'Token does not belong to this handover.'
            );
        }

        $recoveryModel = new RecoveryRequest(
            $this->db
        );

        $recovery = $recoveryModel->findById(
            (string) $handover['recovery_request_id']
        );

        if ($recovery === null) {
            throw new RuntimeException(
                'Associated recovery request not found.'
            );
        }

        $foundDocumentId = (string) (
            $recovery['found_document_id'] ?? ''
        );

        if (!self::isUuid($foundDocumentId)) {
            throw new RuntimeException(
                'Invalid found document reference.'
            );
        }

        $foundModel = new FoundDocument(
            $this->db
        );

        $found = $foundModel->findById(
            $foundDocumentId
        );

        if ($found === null) {
            throw new RuntimeException(
                'Found document not found.'
            );
        }

        /*
         * The finder must have explicitly consented to contact
         * before the handover can proceed.
         */
        $foundModel->grantContactConsent(
            $foundDocumentId
        );

        $handoverModel->grantFinderConsent(
            $handoverId
        );

        return $this->reload(
            $handoverModel,
            $handoverId
        );
    }

    /**
     * Cancel a handover.
     */
    public function cancel(
        string $handoverId,
        string $ownerUserId
    ): array {
        $handover = $this->findForOwner(
            $handoverId,
            $ownerUserId
        );

        if ($handover === null) {
            throw new RuntimeException(
                'Handover not found.'
            );
        }

        if (
            ($handover['status'] ?? null)
            === 'completed'
        ) {
            throw new RuntimeException(
                'A completed handover cannot be cancelled.'
            );
        }

        $handoverModel = new Handover(
            $this->db
        );

        $handoverModel->cancel(
            $handoverId
        );

        AuditLog::create(
            $this->db,
            $ownerUserId,
            'handover_cancelled',
            'handover',
            $handoverId
        );

        return $this->reload(
            $handoverModel,
            $handoverId
        );
    }

    /**
     * Load a recovery and verify owner authorization.
     */
    private function getOwnedRecovery(
        RecoveryRequest $model,
        string $recoveryRequestId,
        string $ownerUserId
    ): array {
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
                'You are not authorized to access this recovery request.'
            );
        }

        return $recovery;
    }

    /**
     * Reload handover after a state change.
     */
    private function reload(
        Handover $model,
        string $handoverId
    ): array {
        $handover = $model->findById(
            $handoverId
        );

        if ($handover === null) {
            throw new RuntimeException(
                'Handover could not be reloaded.'
            );
        }

        return $handover;
    }

    /**
     * Build completion response.
     */
    private function buildCompletionResult(
        array $handover,
        ?array $recovery
    ): array {
        return [
            'handover' => self::publicHandover(
                $handover
            ),
            'recovery' => $recovery !== null
                ? self::publicRecovery($recovery)
                : null,
            'completed' => true,
        ];
    }

    /**
     * Never expose private document or contact data.
     */
    private static function publicHandover(
        array $handover
    ): array {
        return [
            'id' =>
                $handover['id'] ?? null,

            'recovery_request_id' =>
                $handover['recovery_request_id']
                ?? null,

            'finder_consent' =>
                (bool) (
                    $handover['finder_consent']
                    ?? false
                ),

            'safe_location' =>
                $handover['safe_location']
                ?? null,

            'scheduled_at' =>
                $handover['scheduled_at']
                ?? null,

            'completed_at' =>
                $handover['completed_at']
                ?? null,

            'status' =>
                $handover['status']
                ?? null,

            'notes' =>
                $handover['notes']
                ?? null,

            'created_at' =>
                $handover['created_at']
                ?? null,

            'updated_at' =>
                $handover['updated_at']
                ?? null,
        ];
    }

    /**
     * Public recovery representation.
     */
    private static function publicRecovery(
        array $recovery
    ): array {
        return [
            'id' =>
                $recovery['id'] ?? null,

            'lost_document_id' =>
                $recovery['lost_document_id']
                ?? null,

            'found_document_id' =>
                $recovery['found_document_id']
                ?? null,

            'status' =>
                $recovery['status']
                ?? null,

            'requested_at' =>
                $recovery['requested_at']
                ?? null,

            'paid_at' =>
                $recovery['paid_at']
                ?? null,

            'contact_released_at' =>
                $recovery['contact_released_at']
                ?? null,

            'completed_at' =>
                $recovery['completed_at']
                ?? null,

            'expires_at' =>
                $recovery['expires_at']
                ?? null,

            'created_at' =>
                $recovery['created_at']
                ?? null,

            'updated_at' =>
                $recovery['updated_at']
                ?? null,
        ];
    }

    /**
     * Validate a UUID and throw a controlled error.
     */
    private function assertUuid(
        string $value,
        string $message
    ): void {
        if (!self::isUuid($value)) {
            throw new RuntimeException(
                $message
            );
        }
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
