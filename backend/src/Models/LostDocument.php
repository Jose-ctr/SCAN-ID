<?php

declare(strict_types=1);

namespace ScanId\Models;

use PDO;
use RuntimeException;

final class LostDocument
{
    private const STATUSES = [
        'lost',
        'matched',
        'recovery_pending',
        'recovered',
        'cancelled',
        'expired',
    ];

    private const ACTIVE_STATUSES = [
        'lost',
        'matched',
        'recovery_pending',
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
     * Create a lost-document report.
     *
     * The raw document number is never stored.
     */
    public function create(
        ?string $ownerUserId,
        string $ownerPhone,
        string $documentType,
        string $documentNumber,
        ?string $lastKnownLocationGeneral = null,
        ?string $lostAt = null
    ): array {
        $ownerPhone = $this->normalizePhone($ownerPhone);
        $documentType = $this->validateDocumentType($documentType);

        $documentNumber = self::normalizeDocumentNumber(
            $documentNumber
        );

        if ($documentNumber === '') {
            throw new RuntimeException(
                'Document number is required.'
            );
        }

        $hash = self::hashDocumentNumber(
            $documentNumber
        );

        $lastFour = self::lastFour(
            $documentNumber
        );

        $statement = $this->db->prepare(
            <<<'SQL'
                INSERT INTO lost_documents (
                    owner_user_id,
                    owner_phone,
                    document_type,
                    document_number_hash,
                    document_number_last4,
                    last_known_location_general,
                    lost_at,
                    status
                )
                VALUES (
                    :owner_user_id,
                    :owner_phone,
                    :document_type,
                    :document_number_hash,
                    :document_number_last4,
                    :last_known_location_general,
                    COALESCE(
                        :lost_at,
                        NOW()
                    ),
                    'lost'
                )
                RETURNING
                    id,
                    owner_user_id,
                    owner_phone,
                    document_type,
                    document_number_last4,
                    last_known_location_general,
                    lost_at,
                    status,
                    created_at,
                    updated_at
            SQL
        );

        $statement->execute([
            'owner_user_id' => $ownerUserId,
            'owner_phone' => $ownerPhone,
            'document_type' => $documentType,
            'document_number_hash' => $hash,
            'document_number_last4' => $lastFour,
            'last_known_location_general' =>
                $this->nullableString(
                    $lastKnownLocationGeneral
                ),
            'lost_at' => $this->nullableString($lostAt),
        ]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            throw new RuntimeException(
                'Failed to create lost-document report.'
            );
        }

        return $row;
    }

    /**
     * Find a lost document by its protected document hash.
     */
    public function findByHash(
        string $documentNumberHash,
        ?string $documentType = null,
        bool $activeOnly = true
    ): ?array {
        $sql = <<<'SQL'
            SELECT
                id,
                owner_user_id,
                owner_phone,
                document_type,
                document_number_last4,
                last_known_location_general,
                lost_at,
                status,
                created_at,
                updated_at
            FROM lost_documents
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
     * Find a lost document by ID.
     */
    public function findById(
        string $id
    ): ?array {
        $statement = $this->db->prepare(
            <<<'SQL'
                SELECT
                    id,
                    owner_user_id,
                    owner_phone,
                    document_type,
                    document_number_last4,
                    last_known_location_general,
                    lost_at,
                    status,
                    created_at,
                    updated_at
                FROM lost_documents
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
     * Find lost documents reported by a user.
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
                    owner_user_id,
                    owner_phone,
                    document_type,
                    document_number_last4,
                    last_known_location_general,
                    lost_at,
                    status,
                    created_at,
                    updated_at
                FROM lost_documents
                WHERE owner_user_id = :owner_user_id
                ORDER BY created_at DESC
                LIMIT {$limit}
            SQL
        );

        $statement->execute([
            'owner_user_id' => $ownerUserId,
        ]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Find lost-document reports by owner phone.
     */
    public function findByPhone(
        string $ownerPhone,
        int $limit = 100
    ): array {
        $ownerPhone = $this->normalizePhone($ownerPhone);
        $limit = $this->normalizeLimit($limit);

        $statement = $this->db->prepare(
            <<<SQL
                SELECT
                    id,
                    owner_user_id,
                    owner_phone,
                    document_type,
                    document_number_last4,
                    last_known_location_general,
                    lost_at,
                    status,
                    created_at,
                    updated_at
                FROM lost_documents
                WHERE owner_phone = :owner_phone
                ORDER BY created_at DESC
                LIMIT {$limit}
            SQL
        );

        $statement->execute([
            'owner_phone' => $ownerPhone,
        ]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Update the document status.
     */
    public function updateStatus(
        string $id,
        string $status
    ): bool {
        if (!in_array($status, self::STATUSES, true)) {
            throw new RuntimeException(
                'Invalid lost-document status.'
            );
        }

        $statement = $this->db->prepare(
            <<<'SQL'
                UPDATE lost_documents
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
     * Mark a lost document as matched.
     */
    public function markMatched(
        string $id
    ): bool {
        return $this->updateStatus(
            $id,
            'matched'
        );
    }

    /**
     * Mark a lost document as recovery pending.
     */
    public function markRecoveryPending(
        string $id
    ): bool {
        return $this->updateStatus(
            $id,
            'recovery_pending'
        );
    }

    /**
     * Mark a lost document as recovered.
     */
    public function markRecovered(
        string $id
    ): bool {
        return $this->updateStatus(
            $id,
            'recovered'
        );
    }

    /**
     * Cancel a lost-document report.
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
     * Expire a lost-document report.
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
     * Check whether a lost document belongs to a user.
     */
    public function belongsToUser(
        string $id,
        string $userId
    ): bool {
        $statement = $this->db->prepare(
            <<<'SQL'
                SELECT 1
                FROM lost_documents
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
                FROM lost_documents
                WHERE id = :id
                  AND status IN ({$placeholders})
                LIMIT 1
            SQL
        );

        $statement->execute($parameters);

        return $statement->fetchColumn() !== false;
    }

    /**
     * Normalize a document number before hashing.
     *
     * Removes spaces, dashes and other formatting characters.
     * The raw value is never persisted.
     */
    public static function normalizeDocumentNumber(
        string $documentNumber
    ): string {
        return strtoupper(
            preg_replace(
                '/[^A-Z0-9]/',
                '',
                trim($documentNumber)
            ) ?? ''
        );
    }

    /**
     * Hash a normalized document number.
     */
    public static function hashDocumentNumber(
        string $documentNumber
    ): string {
        $normalized = self::normalizeDocumentNumber(
            $documentNumber
        );

        if ($normalized === '') {
            throw new RuntimeException(
                'Document number is required.'
            );
        }

        return hash(
            'sha256',
            $normalized
        );
    }

    /**
     * Return the last four characters of the normalized number.
     */
    public static function lastFour(
        string $documentNumber
    ): string {
        $normalized = self::normalizeDocumentNumber(
            $documentNumber
        );

        if ($normalized === '') {
            throw new RuntimeException(
                'Document number is required.'
            );
        }

        return substr(
            $normalized,
            -4
        );
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
