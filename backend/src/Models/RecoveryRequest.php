<?php

declare(strict_types=1);

namespace ScanId\Models;

use PDO;
use RuntimeException;

final class RecoveryRequest
{
    public function __construct(
        private readonly PDO $database
    ) {
    }

    /**
     * Create a recovery request linking a lost document
     * to a found document.
     */
    public function create(
        string $foundDocumentId,
        ?string $lostDocumentId,
        ?string $ownerUserId,
        string $ownerPhone,
        int $recoveryFeeKes,
        int $expiresInSeconds = 86400
    ): array {
        $foundDocumentId = trim($foundDocumentId);
        $lostDocumentId = $lostDocumentId !== null
            ? trim($lostDocumentId)
            : null;
        $ownerUserId = $ownerUserId !== null
            ? trim($ownerUserId)
            : null;
        $ownerPhone = trim($ownerPhone);

        if ($foundDocumentId === '') {
            throw new RuntimeException(
                'Found document ID is required.'
            );
        }

        if ($lostDocumentId === '') {
            $lostDocumentId = null;
        }

        if ($ownerUserId === '') {
            $ownerUserId = null;
        }

        if ($ownerPhone === '') {
            throw new RuntimeException(
                'Owner phone number is required.'
            );
        }

        if ($recoveryFeeKes < 0) {
            throw new RuntimeException(
                'Recovery fee cannot be negative.'
            );
        }

        if ($expiresInSeconds < 300) {
            throw new RuntimeException(
                'Recovery request expiry must be at least 5 minutes.'
            );
        }

        $this->assertFoundDocumentExists(
            $foundDocumentId
        );

        if ($lostDocumentId !== null) {
            $this->assertLostDocumentExists(
                $lostDocumentId
            );
        }

        $statement = $this->database->prepare(
            <<<'SQL'
            INSERT INTO recovery_requests (
                lost_document_id,
                found_document_id,
                owner_user_id,
                owner_phone,
                status,
                payment_required_at,
                expires_at
            )
            VALUES (
                :lost_document_id,
                :found_document_id,
                :owner_user_id,
                :owner_phone,
                'pending',
                CASE
                    WHEN :recovery_fee_kes > 0
                    THEN NOW()
                    ELSE NULL
                END,
                NOW() + (
                    :expires_in_seconds * INTERVAL '1 second'
                )
            )
            RETURNING
                id,
                lost_document_id,
                found_document_id,
                owner_user_id,
                owner_phone,
                status,
                notified_at,
                payment_required_at,
                paid_at,
                contact_released_at,
                completed_at,
                expires_at,
                created_at,
                updated_at
            SQL
        );

        $statement->execute([
            'lost_document_id' => $lostDocumentId,
            'found_document_id' => $foundDocumentId,
            'owner_user_id' => $ownerUserId,
            'owner_phone' => $ownerPhone,
            'recovery_fee_kes' => $recoveryFeeKes,
            'expires_in_seconds' => $expiresInSeconds,
        ]);

        $request = $statement->fetch();

        if (!is_array($request)) {
            throw new RuntimeException(
                'Unable to create recovery request.'
            );
        }

        return $request;
    }

    /**
     * Find a recovery request by ID.
     */
    public function findById(
        string $id
    ): ?array {
        $id = trim($id);

        if ($id === '') {
            return null;
        }

        $statement = $this->database->prepare(
            <<<'SQL'
            SELECT
                id,
                lost_document_id,
                found_document_id,
                owner_user_id,
                owner_phone,
                status,
                notified_at,
                payment_required_at,
                paid_at,
                contact_released_at,
                completed_at,
                expires_at,
                created_at,
                updated_at
            FROM recovery_requests
            WHERE id = :id
            LIMIT 1
            SQL
        );

        $statement->execute([
            'id' => $id,
        ]);

        $request = $statement->fetch();

        return is_array($request)
            ? $request
            : null;
    }

    /**
     * Find an existing active request for a found document.
     */
    public function findActiveByFoundDocument(
        string $foundDocumentId
    ): ?array {
        $foundDocumentId = trim($foundDocumentId);

        if ($foundDocumentId === '') {
            return null;
        }

        $statement = $this->database->prepare(
            <<<'SQL'
            SELECT
                id,
                lost_document_id,
                found_document_id,
                owner_user_id,
                owner_phone,
                status,
                notified_at,
                payment_required_at,
                paid_at,
                contact_released_at,
                completed_at,
                expires_at,
                created_at,
                updated_at
            FROM recovery_requests
            WHERE found_document_id = :found_document_id
              AND status NOT IN (
                  'completed',
                  'cancelled',
                  'expired'
              )
            ORDER BY created_at DESC
            LIMIT 1
            SQL
        );

        $statement->execute([
            'found_document_id' => $foundDocumentId,
        ]);

        $request = $statement->fetch();

        return is_array($request)
            ? $request
            : null;
    }

    /**
     * Update recovery request status.
     */
    public function updateStatus(
        string $id,
        string $status
    ): bool {
        $id = trim($id);
        $status = trim($status);

        $allowedStatuses = [
            'pending',
            'notified',
            'payment_pending',
            'paid',
            'contact_released',
            'completed',
            'cancelled',
            'expired',
        ];

        if ($id === '') {
            throw new RuntimeException(
                'Recovery request ID is required.'
            );
        }

        if (!in_array($status, $allowedStatuses, true)) {
            throw new RuntimeException(
                'Invalid recovery request status.'
            );
        }

        $statement = $this->database->prepare(
            <<<'SQL'
            UPDATE recovery_requests
            SET
                status = :status,
                notified_at = CASE
                    WHEN :status = 'notified'
                    THEN COALESCE(notified_at, NOW())
                    ELSE notified_at
                END,
                paid_at = CASE
                    WHEN :status = 'paid'
                    THEN COALESCE(paid_at, NOW())
                    ELSE paid_at
                END,
                contact_released_at = CASE
                    WHEN :status = 'contact_released'
                    THEN COALESCE(contact_released_at, NOW())
                    ELSE contact_released_at
                END,
                completed_at = CASE
                    WHEN :status = 'completed'
                    THEN COALESCE(completed_at, NOW())
                    ELSE completed_at
                END,
                updated_at = NOW()
            WHERE id = :id
            SQL
        );

        $statement->execute([
            'id' => $id,
            'status' => $status,
        ]);

        return $statement->rowCount() > 0;
    }

    /**
     * Mark the owner as notified.
     */
    public function markNotified(
        string $id
    ): bool {
        return $this->updateStatus(
            $id,
            'notified'
        );
    }

    /**
     * Mark payment as pending.
     */
    public function markPaymentPending(
        string $id
    ): bool {
        return $this->updateStatus(
            $id,
            'payment_pending'
        );
    }

    /**
     * Mark recovery as paid.
     */
    public function markPaid(
        string $id
    ): bool {
        return $this->updateStatus(
            $id,
            'paid'
        );
    }

    /**
     * Release finder contact after verification/payment rules
     * have been satisfied.
     */
    public function markContactReleased(
        string $id
    ): bool {
        return $this->updateStatus(
            $id,
            'contact_released'
        );
    }

    /**
     * Complete the recovery.
     */
    public function markCompleted(
        string $id
    ): bool {
        return $this->updateStatus(
            $id,
            'completed'
        );
    }

    /**
     * Mark expired requests.
     */
    public function expire(
        string $id
    ): bool {
        return $this->updateStatus(
            $id,
            'expired'
        );
    }

    /**
     * Verify that the found document exists.
     */
    private function assertFoundDocumentExists(
        string $id
    ): void {
        $statement = $this->database->prepare(
            <<<'SQL'
            SELECT 1
            FROM found_documents
            WHERE id = :id
            LIMIT 1
            SQL
        );

        $statement->execute([
            'id' => $id,
        ]);

        if ($statement->fetchColumn() === false) {
            throw new RuntimeException(
                'Found document was not found.'
            );
        }
    }

    /**
     * Verify that the lost document exists.
     */
    private function assertLostDocumentExists(
        string $id
    ): void {
        $statement = $this->database->prepare(
            <<<'SQL'
            SELECT 1
            FROM lost_documents
            WHERE id = :id
            LIMIT 1
            SQL
        );

        $statement->execute([
            'id' => $id,
        ]);

        if ($statement->fetchColumn() === false) {
            throw new RuntimeException(
                'Lost document was not found.'
            );
        }
    }
}
