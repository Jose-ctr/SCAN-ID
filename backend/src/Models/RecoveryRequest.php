<?php

declare(strict_types=1);

namespace ScanId\Models;

use PDO;
use RuntimeException;

final class RecoveryRequest
{
    private const ACTIVE_STATUSES = [
        'pending',
        'notified',
        'payment_pending',
        'paid',
        'contact_released',
    ];

    public function __construct(
        private readonly PDO $db
    ) {
    }

    /**
     * Create a recovery request.
     *
     * One recovery request connects one lost document
     * to one found document.
     */
    public function create(
        string $lostDocumentId,
        string $foundDocumentId,
        ?string $ownerUserId,
        string $ownerPhone,
        int $expiresInSeconds = 86400
    ): array {
        $ownerPhone = $this->normalizePhone($ownerPhone);

        if ($expiresInSeconds < 300) {
            throw new RuntimeException(
                'Recovery request expiration must be at least 5 minutes.'
            );
        }

        $this->assertLostDocumentExists($lostDocumentId);
        $this->assertFoundDocumentExists($foundDocumentId);

        $existing = $this->findActiveByFoundDocument(
            $foundDocumentId
        );

        if ($existing !== null) {
            return $existing;
        }

        $expiresAt = new \DateTimeImmutable(
            '+' . $expiresInSeconds . ' seconds'
        );

        $statement = $this->db->prepare(
            <<<'SQL'
                INSERT INTO recovery_requests (
                    lost_document_id,
                    found_document_id,
                    owner_user_id,
                    owner_phone,
                    status,
                    expires_at
                )
                VALUES (
                    :lost_document_id,
                    :found_document_id,
                    :owner_user_id,
                    :owner_phone,
                    'pending',
                    :expires_at
                )
                RETURNING
                    id,
                    lost_document_id,
                    found_document_id,
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

        try {
            $statement->execute([
                'lost_document_id' => $lostDocumentId,
                'found_document_id' => $foundDocumentId,
                'owner_user_id' => $ownerUserId,
                'owner_phone' => $ownerPhone,
                'expires_at' => $expiresAt->format('Y-m-d H:i:sP'),
            ]);
        } catch (\PDOException $exception) {
            if ($this->isDuplicateActiveRequestError($exception)) {
                $existing = $this->findActiveByFoundDocument(
                    $foundDocumentId
                );

                if ($existing !== null) {
                    return $existing;
                }
            }

            throw $exception;
        }

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            throw new RuntimeException(
                'Failed to create recovery request.'
            );
        }

        return $row;
    }

    /**
     * Find a recovery request by ID.
     */
    public function findById(
        string $id
    ): ?array {
        $statement = $this->db->prepare(
            <<<'SQL'
                SELECT
                    id,
                    lost_document_id,
                    found_document_id,
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

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * Find a request by found document.
     */
    public function findByFoundDocument(
        string $foundDocumentId
    ): ?array {
        $statement = $this->db->prepare(
            <<<'SQL'
                SELECT
                    id,
                    lost_document_id,
                    found_document_id,
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
                WHERE found_document_id = :found_document_id
                ORDER BY requested_at DESC
                LIMIT 1
            SQL
        );

        $statement->execute([
            'found_document_id' => $foundDocumentId,
        ]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * Find an active request by found document.
     */
    public function findActiveByFoundDocument(
        string $foundDocumentId
    ): ?array {
        $placeholders = $this->statusPlaceholders(
            self::ACTIVE_STATUSES
        );

        $parameters = [
            'found_document_id' => $foundDocumentId,
        ];

        foreach (self::ACTIVE_STATUSES as $index => $status) {
            $parameters['status_' . $index] = $status;
        }

        $statement = $this->db->prepare(
            <<<SQL
                SELECT
                    id,
                    lost_document_id,
                    found_document_id,
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
                WHERE found_document_id = :found_document_id
                  AND status IN ({$placeholders})
                  AND expires_at > NOW()
                ORDER BY requested_at DESC
                LIMIT 1
            SQL
        );

        $statement->execute($parameters);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * Find a request by lost document.
     */
    public function findByLostDocument(
        string $lostDocumentId
    ): ?array {
        $statement = $this->db->prepare(
            <<<'SQL'
                SELECT
                    id,
                    lost_document_id,
                    found_document_id,
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
                WHERE lost_document_id = :lost_document_id
                ORDER BY requested_at DESC
                LIMIT 1
            SQL
        );

        $statement->execute([
            'lost_document_id' => $lostDocumentId,
        ]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * Find requests belonging to a user.
     */
    public function findByOwnerUser(
        string $ownerUserId,
        int $limit = 100
    ): array {
        $limit = $this->normalizeLimit($limit);

        $statement = $this->db->prepare(
            <<<SQL
                SELECT
                    id,
                    lost_document_id,
                    found_document_id,
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
                LIMIT {$limit}
            SQL
        );

        $statement->execute([
            'owner_user_id' => $ownerUserId,
        ]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Find requests using the owner's phone number.
     */
    public function findByOwnerPhone(
        string $ownerPhone,
        int $limit = 100
    ): array {
        $ownerPhone = $this->normalizePhone($ownerPhone);
        $limit = $this->normalizeLimit($limit);

        $statement = $this->db->prepare(
            <<<SQL
                SELECT
                    id,
                    lost_document_id,
                    found_document_id,
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
                LIMIT {$limit}
            SQL
        );

        $statement->execute([
            'owner_phone' => $ownerPhone,
        ]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Update request status.
     */
    public function updateStatus(
        string $id,
        string $status
    ): bool {
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

        if (!in_array($status, $allowedStatuses, true)) {
            throw new RuntimeException(
                'Invalid recovery request status.'
            );
        }

        $statement = $this->db->prepare(
            <<<'SQL'
                UPDATE recovery_requests
                SET status = :status
                WHERE id = :id
                RETURNING id
            SQL
        );

        $statement->execute([
            'id' => $id,
            'status' => $status,
        ]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * Mark owner notification as sent.
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
     * Mark recovery payment as completed.
     */
    public function markPaid(
        string $id
    ): bool {
        $statement = $this->db->prepare(
            <<<'SQL'
                UPDATE recovery_requests
                SET
                    status = 'paid',
                    paid_at = COALESCE(paid_at, NOW())
                WHERE id = :id
                  AND status IN ('pending', 'notified', 'payment_pending')
                RETURNING id
            SQL
        );

        $statement->execute([
            'id' => $id,
        ]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * Release verified contact information.
     */
    public function markContactReleased(
        string $id
    ): bool {
        $statement = $this->db->prepare(
            <<<'SQL'
                UPDATE recovery_requests
                SET
                    status = 'contact_released',
                    contact_released_at = COALESCE(
                        contact_released_at,
                        NOW()
                    )
                WHERE id = :id
                  AND status = 'paid'
                RETURNING id
            SQL
        );

        $statement->execute([
            'id' => $id,
        ]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * Mark the recovery as completed after handover.
     */
    public function markCompleted(
        string $id
    ): bool {
        $statement = $this->db->prepare(
            <<<'SQL'
                UPDATE recovery_requests
                SET
                    status = 'completed',
                    completed_at = COALESCE(
                        completed_at,
                        NOW()
                    )
                WHERE id = :id
                  AND status IN ('paid', 'contact_released')
                RETURNING id
            SQL
        );

        $statement->execute([
            'id' => $id,
        ]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * Cancel a recovery request.
     */
    public function cancel(
        string $id
    ): bool {
        $statement = $this->db->prepare(
            <<<'SQL'
                UPDATE recovery_requests
                SET status = 'cancelled'
                WHERE id = :id
                  AND status IN (
                      'pending',
                      'notified',
                      'payment_pending'
                  )
                RETURNING id
            SQL
        );

        $statement->execute([
            'id' => $id,
        ]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * Expire an overdue recovery request.
     */
    public function expire(
        string $id
    ): bool {
        $statement = $this->db->prepare(
            <<<'SQL'
                UPDATE recovery_requests
                SET status = 'expired'
                WHERE id = :id
                  AND expires_at <= NOW()
                  AND status IN (
                      'pending',
                      'notified',
                      'payment_pending'
                  )
                RETURNING id
            SQL
        );

        $statement->execute([
            'id' => $id,
        ]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * Check whether the request belongs to a user.
     */
    public function belongsToUser(
        string $id,
        string $userId
    ): bool {
        $statement = $this->db->prepare(
            <<<'SQL'
                SELECT 1
                FROM recovery_requests
                WHERE id = :id
                  AND owner_user_id = :user_id
                LIMIT 1
            SQL
        );

        $statement->execute([
            'id' => $id,
            'user_id' => $userId,
        ]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * Determine whether the request is currently active.
     */
    public function isActive(
        string $id
    ): bool {
        $placeholders = $this->statusPlaceholders(
            self::ACTIVE_STATUSES
        );

        $parameters = [
            'id' => $id,
        ];

        foreach (self::ACTIVE_STATUSES as $index => $status) {
            $parameters['status_' . $index] = $status;
        }

        $statement = $this->db->prepare(
            <<<SQL
                SELECT 1
                FROM recovery_requests
                WHERE id = :id
                  AND status IN ({$placeholders})
                  AND expires_at > NOW()
                LIMIT 1
            SQL
        );

        $statement->execute($parameters);

        return $statement->fetchColumn() !== false;
    }

    /**
     * Normalize Kenyan phone numbers.
     */
    private function normalizePhone(
        string $phone
    ): string {
        $phone = preg_replace(
            '/[\s().-]+/',
            '',
            trim($phone)
        );

        if ($phone === null || $phone === '') {
            throw new RuntimeException(
                'Phone number is required.'
            );
        }

        if (str_starts_with($phone, '+254')) {
            $normalized = $phone;
        } elseif (str_starts_with($phone, '254')) {
            $normalized = '+' . $phone;
        } elseif (
            str_starts_with($phone, '07') ||
            str_starts_with($phone, '01')
        ) {
            $normalized = '+254' . substr($phone, 1);
        } else {
            throw new RuntimeException(
                'Invalid Kenyan phone number.'
            );
        }

        if (!preg_match(
            '/^\+254(?:7|1)\d{8}$/',
            $normalized
        )) {
            throw new RuntimeException(
                'Invalid Kenyan phone number.'
            );
        }

        return $normalized;
    }

    /**
     * Ensure the lost document exists.
     */
    private function assertLostDocumentExists(
        string $id
    ): void {
        $statement = $this->db->prepare(
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
                'Lost document not found.'
            );
        }
    }

    /**
     * Ensure the found document exists.
     */
    private function assertFoundDocumentExists(
        string $id
    ): void {
        $statement = $this->db->prepare(
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
                'Found document not found.'
            );
        }
    }

    /**
     * Build named placeholders for an IN clause.
     *
     * Values remain bound parameters; no user input is
     * interpolated into SQL.
     */
    private function statusPlaceholders(
        array $statuses
    ): string {
        $placeholders = [];

        foreach ($statuses as $index => $status) {
            $placeholders[] = ':status_' . $index;
        }

        return implode(', ', $placeholders);
    }

    /**
     * Keep query limits within a safe range.
     */
    private function normalizeLimit(
        int $limit
    ): int {
        return max(1, min($limit, 500));
    }

    /**
     * Detect PostgreSQL unique/exclusion conflicts.
     */
    private function isDuplicateActiveRequestError(
        \PDOException $exception
    ): bool {
        return $exception->getCode() === '23505';
    }
}
