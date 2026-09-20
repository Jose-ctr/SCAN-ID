<?php

declare(strict_types=1);

namespace ScanId\Models;

use PDO;
use RuntimeException;

final class RecoveryRequest
{
    private const DEFAULT_EXPIRY_SECONDS = 86400;

    private const STATUSES = [
        'pending',
        'notified',
        'payment_pending',
        'paid',
        'contact_released',
        'completed',
        'cancelled',
        'expired',
    ];

    public function __construct(
        private readonly PDO $database
    ) {
    }

    /**
     * Create a recovery request linking a lost document
     * to a found document.
     *
     * Payment amounts are deliberately NOT stored here.
     * RecoveryPayment owns payment information.
     */
    public function create(
        string $foundDocumentId,
        ?string $lostDocumentId,
        ?string $ownerUserId,
        string $ownerPhone,
        int $expiresInSeconds = self::DEFAULT_EXPIRY_SECONDS
    ): array {
        $foundDocumentId = trim($foundDocumentId);

        $lostDocumentId = $lostDocumentId !== null
            ? trim($lostDocumentId)
            : null;

        $ownerUserId = $ownerUserId !== null
            ? trim($ownerUserId)
            : null;

        $ownerPhone = $this->normalizePhone($ownerPhone);

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

        if ($ownerUserId !== null) {
            $this->assertUserExists(
                $ownerUserId
            );
        }

        $statement = $this->database->prepare(
            <<<'SQL'
            INSERT INTO recovery_requests (
                lost_id_id,
                found_id_id,
                owner_user_id,
                owner_phone,
                status,
                expires_at
            )
            VALUES (
                :lost_id_id,
                :found_id_id,
                :owner_user_id,
                :owner_phone,
                'pending',
                NOW() + (
                    :expires_in_seconds * INTERVAL '1 second'
                )
            )
            RETURNING
                id,
                lost_id_id,
                found_id_id,
                owner_user_id,
                owner_phone,
                status,
                requested_at,
                paid_at,
                contact_released_at,
                completed_at,
                expires_at,
                created_at,
                updated_at
            SQL
        );

        $statement->execute([
            'lost_id_id' => $lostDocumentId,
            'found_id_id' => $foundDocumentId,
            'owner_user_id' => $ownerUserId,
            'owner_phone' => $ownerPhone,
            'expires_in_seconds' => $expiresInSeconds,
        ]);

        $request = $statement->fetch(PDO::FETCH_ASSOC);

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
                lost_id_id,
                found_id_id,
                owner_user_id,
                owner_phone,
                status,
                requested_at,
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

        $request = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($request)
            ? $request
            : null;
    }

    /**
     * Find all recovery requests for a found document.
     */
    public function findByFoundDocument(
        string $foundDocumentId
    ): array {
        $foundDocumentId = trim($foundDocumentId);

        if ($foundDocumentId === '') {
            return [];
        }

        $statement = $this->database->prepare(
            <<<'SQL'
            SELECT
                id,
                lost_id_id,
                found_id_id,
                owner_user_id,
                owner_phone,
                status,
                requested_at,
                paid_at,
                contact_released_at,
                completed_at,
                expires_at,
                created_at,
                updated_at
            FROM recovery_requests
            WHERE found_id_id = :found_id_id
            ORDER BY requested_at DESC
            SQL
        );

        $statement->execute([
            'found_id_id' => $foundDocumentId,
        ]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Find the current active recovery request
     * for a found document.
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
                lost_id_id,
                found_id_id,
                owner_user_id,
                owner_phone,
                status,
                requested_at,
                paid_at,
                contact_released_at,
                completed_at,
                expires_at,
                created_at,
                updated_at
            FROM recovery_requests
            WHERE found_id_id = :found_id_id
              AND status NOT IN (
                  'completed',
                  'cancelled',
                  'expired'
              )
              AND (
                  expires_at IS NULL
                  OR expires_at > CURRENT_TIMESTAMP
              )
            ORDER BY requested_at DESC
            LIMIT 1
            SQL
        );

        $statement->execute([
            'found_id_id' => $foundDocumentId,
        ]);

        $request = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($request)
            ? $request
            : null;
    }

    /**
     * Find all recovery requests for a lost document.
     */
    public function findByLostDocument(
        string $lostDocumentId
    ): array {
        $lostDocumentId = trim($lostDocumentId);

        if ($lostDocumentId === '') {
            return [];
        }

        $statement = $this->database->prepare(
            <<<'SQL'
            SELECT
                id,
                lost_id_id,
                found_id_id,
                owner_user_id,
                owner_phone,
                status,
                requested_at,
                paid_at,
                contact_released_at,
                completed_at,
                expires_at,
                created_at,
                updated_at
            FROM recovery_requests
            WHERE lost_id_id = :lost_id_id
            ORDER BY requested_at DESC
            SQL
        );

        $statement->execute([
            'lost_id_id' => $lostDocumentId,
        ]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Find recovery requests belonging to an owner account.
     */
    public function findByOwnerUser(
        string $ownerUserId
    ): array {
        $ownerUserId = trim($ownerUserId);

        if ($ownerUserId === '') {
            return [];
        }

        $statement = $this->database->prepare(
            <<<'SQL'
            SELECT
                id,
                lost_id_id,
                found_id_id,
                owner_user_id,
                owner_phone,
                status,
                requested_at,
                paid_at,
                contact_released_at,
                completed_at,
                expires_at,
                created_at,
                updated_at
            FROM recovery_requests
            WHERE owner_user_id = :owner_user_id
            ORDER BY requested_at DESC
            SQL
        );

        $statement->execute([
            'owner_user_id' => $ownerUserId,
        ]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Find recovery requests using the owner's phone number.
     */
    public function findByOwnerPhone(
        string $ownerPhone
    ): array {
        $ownerPhone = $this->normalizePhone($ownerPhone);

        if ($ownerPhone === '') {
            return [];
        }

        $statement = $this->database->prepare(
            <<<'SQL'
            SELECT
                id,
                lost_id_id,
                found_id_id,
                owner_user_id,
                owner_phone,
                status,
                requested_at,
                paid_at,
                contact_released_at,
                completed_at,
                expires_at,
                created_at,
                updated_at
            FROM recovery_requests
            WHERE owner_phone = :owner_phone
            ORDER BY requested_at DESC
            SQL
        );

        $statement->execute([
            'owner_phone' => $ownerPhone,
        ]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
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

        if ($id === '') {
            throw new RuntimeException(
                'Recovery request ID is required.'
            );
        }

        $this->assertStatus($status);

        $statement = $this->database->prepare(
            <<<'SQL'
            UPDATE recovery_requests
            SET
                status = :status,
                paid_at = CASE
                    WHEN :status = 'paid'
                    THEN COALESCE(paid_at, CURRENT_TIMESTAMP)
                    ELSE paid_at
                END,
                contact_released_at = CASE
                    WHEN :status = 'contact_released'
                    THEN COALESCE(
                        contact_released_at,
                        CURRENT_TIMESTAMP
                    )
                    ELSE contact_released_at
                END,
                completed_at = CASE
                    WHEN :status = 'completed'
                    THEN COALESCE(
                        completed_at,
                        CURRENT_TIMESTAMP
                    )
                    ELSE completed_at
                END,
                updated_at = CURRENT_TIMESTAMP
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
     * Mark owner notification as completed.
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
     * Mark recovery payment as verified.
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
     * Release finder contact information.
     *
     * The service layer must ensure payment has been
     * verified before calling this method.
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
     * Mark the recovery as successfully completed.
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
     * Cancel a recovery request.
     */
    public function cancel(
        string $id
    ): bool {
        $id = trim($id);

        if ($id === '') {
            throw new RuntimeException(
                'Recovery request ID is required.'
            );
        }

        $statement = $this->database->prepare(
            <<<'SQL'
            UPDATE recovery_requests
            SET
                status = 'cancelled',
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
              AND status NOT IN (
                  'completed',
                  'cancelled',
                  'expired'
              )
            SQL
        );

        $statement->execute([
            'id' => $id,
        ]);

        return $statement->rowCount() > 0;
    }

    /**
     * Expire a request only when its expiry time has passed.
     */
    public function expire(
        string $id
    ): bool {
        $id = trim($id);

        if ($id === '') {
            throw new RuntimeException(
                'Recovery request ID is required.'
            );
        }

        $statement = $this->database->prepare(
            <<<'SQL'
            UPDATE recovery_requests
            SET
                status = 'expired',
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
              AND status NOT IN (
                  'completed',
                  'cancelled',
                  'expired'
              )
              AND expires_at IS NOT NULL
              AND expires_at <= CURRENT_TIMESTAMP
            SQL
        );

        $statement->execute([
            'id' => $id,
        ]);

        return $statement->rowCount() > 0;
    }

    /**
     * Check whether a request belongs to an owner account.
     */
    public function belongsToUser(
        string $id,
        string $ownerUserId
    ): bool {
        $id = trim($id);
        $ownerUserId = trim($ownerUserId);

        if ($id === '' || $ownerUserId === '') {
            return false;
        }

        $statement = $this->database->prepare(
            <<<'SQL'
            SELECT 1
            FROM recovery_requests
            WHERE id = :id
              AND owner_user_id = :owner_user_id
            LIMIT 1
            SQL
        );

        $statement->execute([
            'id' => $id,
            'owner_user_id' => $ownerUserId,
        ]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * Determine whether a request is still active.
     */
    public function isActive(
        string $id
    ): bool {
        $id = trim($id);

        if ($id === '') {
            return false;
        }

        $statement = $this->database->prepare(
            <<<'SQL'
            SELECT 1
            FROM recovery_requests
            WHERE id = :id
              AND status NOT IN (
                  'completed',
                  'cancelled',
                  'expired'
              )
              AND (
                  expires_at IS NULL
                  OR expires_at > CURRENT_TIMESTAMP
              )
            LIMIT 1
            SQL
        );

        $statement->execute([
            'id' => $id,
        ]);

        return $statement->fetchColumn() !== false;
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

    /**
     * Verify that the owner account exists.
     */
    private function assertUserExists(
        string $id
    ): void {
        $statement = $this->database->prepare(
            <<<'SQL'
            SELECT 1
            FROM users
            WHERE id = :id
            LIMIT 1
            SQL
        );

        $statement->execute([
            'id' => $id,
        ]);

        if ($statement->fetchColumn() === false) {
            throw new RuntimeException(
                'Owner user was not found.'
            );
        }
    }

    /**
     * Validate the recovery request status.
     */
    private function assertStatus(
        string $status
    ): void {
        if (!in_array($status, self::STATUSES, true)) {
            throw new RuntimeException(
                'Invalid recovery request status.'
            );
        }
    }

    /**
     * Normalize Kenyan phone numbers.
     */
    private function normalizePhone(
        string $phone
    ): string {
        $phone = trim($phone);

        if ($phone === '') {
            return '';
        }

        $phone = preg_replace(
            '/[\s\-\(\)]/',
            '',
            $phone
        ) ?? '';

        if (str_starts_with($phone, '00')) {
            $phone = '+' . substr($phone, 2);
        }

        if (str_starts_with($phone, '0')) {
            $phone = '+254' . substr($phone, 1);
        } elseif (str_starts_with($phone, '254')) {
            $phone = '+' . $phone;
        }

        if (!preg_match('/^\+2547\d{8}$/', $phone)) {
            throw new RuntimeException(
                'Invalid Kenyan phone number.'
            );
        }

        return $phone;
    }
}
