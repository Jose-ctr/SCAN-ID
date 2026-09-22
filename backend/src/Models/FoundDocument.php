<?php

declare(strict_types=1);

namespace ScanId\Models;

use PDO;
use RuntimeException;

final class FoundDocument
{
    private const STATUSES = [
        'found',
        'owner_notified',
        'recovery_requested',
        'handover_pending',
        'returned',
        'expired',
        'cancelled',
    ];

    private const ACTIVE_STATUSES = [
        'found',
        'owner_notified',
        'recovery_requested',
        'handover_pending',
    ];

    private const DOCUMENT_TYPES = [
        'national_id',
        'passport',
        'driving_licence',
        'student_id',
        'staff_id',
        'bank_card',
        'insurance_card',
        'other',
    ];

    public function __construct(
        private readonly PDO $db
    ) {
    }

    /**
     * Create a found-document report.
     *
     * A finder can either be an authenticated SCAN-ID user
     * or an anonymous person providing a phone number.
     *
     * The raw document number is never stored.
     */
    public function create(
        ?string $finderUserId,
        ?string $finderPhone,
        string $documentType,
        string $documentNumber,
        ?string $foundLocationGeneral = null,
        ?string $foundAt = null,
        bool $finderConsentToContact = false
    ): array {
        if (
            $finderUserId === null &&
            ($finderPhone === null || trim($finderPhone) === '')
        ) {
            throw new RuntimeException(
                'A finder user ID or finder phone number is required.'
            );
        }

        if ($finderPhone !== null) {
            $finderPhone = $this->normalizePhone(
                $finderPhone
            );
        }

        if ($finderUserId !== null) {
            $this->assertUserExists($finderUserId);

            if ($finderPhone === null) {
                $finderPhone = $this->getUserPhone(
                    $finderUserId
                );
            }
        }

        $documentType = $this->validateDocumentType(
            $documentType
        );

        $documentNumber = LostDocument::normalizeDocumentNumber(
            $documentNumber
        );

        if ($documentNumber === '') {
            throw new RuntimeException(
                'Document number is required.'
            );
        }

        $hash = LostDocument::hashDocumentNumber(
            $documentNumber
        );

        $lastFour = LostDocument::lastFour(
            $documentNumber
        );

        $statement = $this->db->prepare(
            <<<'SQL'
                INSERT INTO found_documents (
                    finder_user_id,
                    finder_phone,
                    document_type,
                    document_number_hash,
                    document_number_last4,
                    found_location_general,
                    found_at,
                    status,
                    finder_consent_to_contact,
                    finder_consent_at
                )
                VALUES (
                    :finder_user_id,
                    :finder_phone,
                    :document_type,
                    :document_number_hash,
                    :document_number_last4,
                    :found_location_general,
                    COALESCE(
                        :found_at,
                        NOW()
                    ),
                    'found',
                    :finder_consent_to_contact,
                    CASE
                        WHEN :finder_consent_to_contact = TRUE
                        THEN NOW()
                        ELSE NULL
                    END
                )
                RETURNING
                    id,
                    finder_user_id,
                    finder_phone,
                    document_type,
                    document_number_last4,
                    found_location_general,
                    found_at,
                    status,
                    finder_consent_to_contact,
                    finder_consent_at,
                    created_at,
                    updated_at
            SQL
        );

        $statement->execute([
            'finder_user_id' => $finderUserId,
            'finder_phone' => $finderPhone,
            'document_type' => $documentType,
            'document_number_hash' => $hash,
            'document_number_last4' => $lastFour,
            'found_location_general' =>
                $this->nullableString(
                    $foundLocationGeneral
                ),
            'found_at' => $this->nullableString($foundAt),
            'finder_consent_to_contact' =>
                $finderConsentToContact,
        ]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            throw new RuntimeException(
                'Failed to create found-document report.'
            );
        }

        return $row;
    }

    /**
     * Find an active found document by protected hash.
     */
    public function findByHash(
        string $documentNumberHash,
        ?string $documentType = null,
        bool $activeOnly = true
    ): ?array {
        $sql = <<<'SQL'
            SELECT
                id,
                finder_user_id,
                finder_phone,
                document_type,
                document_number_last4,
                found_location_general,
                found_at,
                status,
                finder_consent_to_contact,
                finder_consent_at,
                created_at,
                updated_at
            FROM found_documents
            WHERE document_number_hash = :document_number_hash
        SQL;

        $parameters = [
            'document_number_hash' => $documentNumberHash,
        ];

        if ($documentType !== null) {
            $documentType = $this->validateDocumentType(
                $documentType
            );

            $sql .= ' AND document_type = :document_type';
            $parameters['document_type'] = $documentType;
        }

        if ($activeOnly) {
            $placeholders = $this->statusPlaceholders(
                self::ACTIVE_STATUSES
            );

            $sql .= " AND status IN ({$placeholders})";

            foreach (self::ACTIVE_STATUSES as $index => $status) {
                $parameters['status_' . $index] = $status;
            }
        }

        $sql .= ' ORDER BY created_at DESC LIMIT 1';

        $statement = $this->db->prepare($sql);
        $statement->execute($parameters);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * Find a found document by ID.
     */
    public function findById(
        string $id
    ): ?array {
        $statement = $this->db->prepare(
            <<<'SQL'
                SELECT
                    id,
                    finder_user_id,
                    finder_phone,
                    document_type,
                    document_number_last4,
                    found_location_general,
                    found_at,
                    status,
                    finder_consent_to_contact,
                    finder_consent_at,
                    created_at,
                    updated_at
                FROM found_documents
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
     * Find reports created by an authenticated finder.
     */
    public function findByFinderUser(
        string $finderUserId,
        int $limit = 100
    ): array {
        $limit = $this->normalizeLimit($limit);

        $statement = $this->db->prepare(
            <<<SQL
                SELECT
                    id,
                    finder_user_id,
                    finder_phone,
                    document_type,
                    document_number_last4,
                    found_location_general,
                    found_at,
                    status,
                    finder_consent_to_contact,
                    finder_consent_at,
                    created_at,
                    updated_at
                FROM found_documents
                WHERE finder_user_id = :finder_user_id
                ORDER BY created_at DESC
                LIMIT {$limit}
            SQL
        );

        $statement->execute([
            'finder_user_id' => $finderUserId,
        ]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Find reports created using a finder phone number.
     */
    public function findByFinderPhone(
        string $finderPhone,
        int $limit = 100
    ): array {
        $finderPhone = $this->normalizePhone(
            $finderPhone
        );

        $limit = $this->normalizeLimit($limit);

        $statement = $this->db->prepare(
            <<<SQL
                SELECT
                    id,
                    finder_user_id,
                    finder_phone,
                    document_type,
                    document_number_last4,
                    found_location_general,
                    found_at,
                    status,
                    finder_consent_to_contact,
                    finder_consent_at,
                    created_at,
                    updated_at
                FROM found_documents
                WHERE finder_phone = :finder_phone
                ORDER BY created_at DESC
                LIMIT {$limit}
            SQL
        );

        $statement->execute([
            'finder_phone' => $finderPhone,
        ]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Update the found-document status.
     */
    public function updateStatus(
        string $id,
        string $status
    ): bool {
        if (!in_array($status, self::STATUSES, true)) {
            throw new RuntimeException(
                'Invalid found-document status.'
            );
        }

        $statement = $this->db->prepare(
            <<<'SQL'
                UPDATE found_documents
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
    public function markOwnerNotified(
        string $id
    ): bool {
        return $this->updateStatus(
            $id,
            'owner_notified'
        );
    }

    /**
     * Mark recovery as requested.
     */
    public function markRecoveryRequested(
        string $id
    ): bool {
        return $this->updateStatus(
            $id,
            'recovery_requested'
        );
    }

    /**
     * Mark handover as pending.
     */
    public function markHandoverPending(
        string $id
    ): bool {
        return $this->updateStatus(
            $id,
            'handover_pending'
        );
    }

    /**
     * Mark the document as returned.
     */
    public function markReturned(
        string $id
    ): bool {
        return $this->updateStatus(
            $id,
            'returned'
        );
    }

    /**
     * Cancel a found-document report.
     */
    public function cancel(
        string $id
    ): bool {
        return $this->updateStatus(
            $id,
            'cancelled'
        );
    }

    /**
     * Expire a found-document report.
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
     * Grant finder consent to contact.
     */
    public function grantContactConsent(
        string $id
    ): bool {
        $statement = $this->db->prepare(
            <<<'SQL'
                UPDATE found_documents
                SET
                    finder_consent_to_contact = TRUE,
                    finder_consent_at = COALESCE(
                        finder_consent_at,
                        NOW()
                    )
                WHERE id = :id
                RETURNING id
            SQL
        );

        $statement->execute([
            'id' => $id,
        ]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * Revoke finder contact consent.
     */
    public function revokeContactConsent(
        string $id
    ): bool {
        $statement = $this->db->prepare(
            <<<'SQL'
                UPDATE found_documents
                SET finder_consent_to_contact = FALSE
                WHERE id = :id
                RETURNING id
            SQL
        );

        $statement->execute([
            'id' => $id,
        ]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * Check finder contact consent.
     */
    public function hasContactConsent(
        string $id
    ): bool {
        $statement = $this->db->prepare(
            <<<'SQL'
                SELECT finder_consent_to_contact
                FROM found_documents
                WHERE id = :id
                LIMIT 1
            SQL
        );

        $statement->execute([
            'id' => $id,
        ]);

        $value = $statement->fetchColumn();

        if ($value === false) {
            return false;
        }

        return filter_var(
            $value,
            FILTER_VALIDATE_BOOLEAN
        );
    }

    /**
     * Return the finder's contact phone.
     *
     * Uses the explicitly supplied finder_phone first.
     * Falls back to the linked user's phone.
     */
    public function getFinderPhone(
        string $id
    ): ?string {
        $statement = $this->db->prepare(
            <<<'SQL'
                SELECT
                    fd.finder_phone,
                    u.phone AS user_phone
                FROM found_documents fd
                LEFT JOIN users u
                    ON u.id = fd.finder_user_id
                WHERE fd.id = :id
                LIMIT 1
            SQL
        );

        $statement->execute([
            'id' => $id,
        ]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        if (
            isset($row['finder_phone']) &&
            $row['finder_phone'] !== null
        ) {
            return (string) $row['finder_phone'];
        }

        if (
            isset($row['user_phone']) &&
            $row['user_phone'] !== null
        ) {
            return (string) $row['user_phone'];
        }

        return null;
    }

    /**
     * Check whether a found document belongs to a user.
     */
    public function belongsToUser(
        string $id,
        string $userId
    ): bool {
        $statement = $this->db->prepare(
            <<<'SQL'
                SELECT 1
                FROM found_documents
                WHERE id = :id
                  AND finder_user_id = :user_id
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
     * Check whether the report is active.
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
                FROM found_documents
                WHERE id = :id
                  AND status IN ({$placeholders})
                LIMIT 1
            SQL
        );

        $statement->execute($parameters);

        return $statement->fetchColumn() !== false;
    }

    /**
     * Validate supported document types.
     */
    private function validateDocumentType(
        string $documentType
    ): string {
        $documentType = strtolower(
            trim($documentType)
        );

        if (!in_array(
            $documentType,
            self::DOCUMENT_TYPES,
            true
        )) {
            throw new RuntimeException(
                'Unsupported document type.'
            );
        }

        return $documentType;
    }

    /**
     * Ensure the authenticated finder exists.
     */
    private function assertUserExists(
        string $userId
    ): void {
        $statement = $this->db->prepare(
            <<<'SQL'
                SELECT 1
                FROM users
                WHERE id = :id
                  AND is_active = TRUE
                LIMIT 1
            SQL
        );

        $statement->execute([
            'id' => $userId,
        ]);

        if ($statement->fetchColumn() === false) {
            throw new RuntimeException(
                'Finder account not found or inactive.'
            );
        }
    }

    /**
     * Get the phone number attached to a user.
     */
    private function getUserPhone(
        string $userId
    ): string {
        $statement = $this->db->prepare(
            <<<'SQL'
                SELECT phone
                FROM users
                WHERE id = :id
                  AND is_active = TRUE
                LIMIT 1
            SQL
        );

        $statement->execute([
            'id' => $userId,
        ]);

        $phone = $statement->fetchColumn();

        if ($phone === false || $phone === null) {
            throw new RuntimeException(
                'Finder phone number could not be determined.'
            );
        }

        return $this->normalizePhone(
            (string) $phone
        );
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
     * Convert empty strings to NULL.
     */
    private function nullableString(
        ?string $value
    ): ?string {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * Keep query limits within a safe range.
     */
    private function normalizeLimit(
        int $limit
    ): int {
        return max(
            1,
            min($limit, 500)
        );
    }

    /**
     * Create named placeholders for a status IN clause.
     */
    private function statusPlaceholders(
        array $statuses
    ): string {
        $placeholders = [];

        foreach ($statuses as $index => $status) {
            $placeholders[] = ':status_' . $index;
        }

        return implode(
            ', ',
            $placeholders
        );
    }
}
