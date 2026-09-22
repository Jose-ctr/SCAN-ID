<?php

declare(strict_types=1);

namespace ScanId\Models;

use PDO;
use RuntimeException;

final class AuditLog
{
    public function __construct(
        private readonly PDO $db
    ) {
    }

    /**
     * Create an audit log entry.
     *
     * Sensitive values such as passwords, OTPs, raw document numbers,
     * authentication tokens and M-Pesa secrets must never be placed
     * in metadata.
     *
     * @param array<string, mixed>|null $metadata
     */
    public function create(
        ?string $userId,
        string $action,
        string $entityType,
        ?string $entityId = null,
        ?string $ipAddress = null,
        ?array $metadata = null
    ): array {
        $action = trim($action);
        $entityType = trim($entityType);

        if ($action === '') {
            throw new RuntimeException(
                'Audit action is required.'
            );
        }

        if ($entityType === '') {
            throw new RuntimeException(
                'Audit entity type is required.'
            );
        }

        $metadataJson = null;

        if ($metadata !== null) {
            try {
                $metadataJson = json_encode(
                    $metadata,
                    JSON_THROW_ON_ERROR |
                    JSON_UNESCAPED_SLASHES |
                    JSON_UNESCAPED_UNICODE
                );
            } catch (\JsonException $exception) {
                throw new RuntimeException(
                    'Audit metadata could not be encoded.',
                    0,
                    $exception
                );
            }
        }

        $statement = $this->db->prepare(
            <<<'SQL'
                INSERT INTO audit_logs (
                    user_id,
                    action,
                    entity_type,
                    entity_id,
                    ip_address,
                    metadata
                )
                VALUES (
                    :user_id,
                    :action,
                    :entity_type,
                    :entity_id,
                    :ip_address,
                    CAST(:metadata AS JSONB)
                )
                RETURNING
                    id,
                    user_id,
                    action,
                    entity_type,
                    entity_id,
                    ip_address,
                    metadata,
                    created_at
            SQL
        );

        $statement->execute([
            'user_id' => $this->nullableString($userId),
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $this->nullableString($entityId),
            'ip_address' => $this->nullableString($ipAddress),
            'metadata' => $metadataJson,
        ]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            throw new RuntimeException(
                'Failed to create audit log.'
            );
        }

        return $row;
    }

    /**
     * Find an audit log by ID.
     */
    public function findById(
        string $id
    ): ?array {
        $statement = $this->db->prepare(
            <<<'SQL'
                SELECT
                    id,
                    user_id,
                    action,
                    entity_type,
                    entity_id,
                    ip_address,
                    metadata,
                    created_at
                FROM audit_logs
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
     * Get audit logs for a user.
     */
    public function findByUser(
        string $userId,
        int $limit = 100
    ): array {
        $limit = $this->normalizeLimit($limit);

        $statement = $this->db->prepare(
            <<<SQL
                SELECT
                    id,
                    user_id,
                    action,
                    entity_type,
                    entity_id,
                    ip_address,
                    metadata,
                    created_at
                FROM audit_logs
                WHERE user_id = :user_id
                ORDER BY created_at DESC
                LIMIT {$limit}
            SQL
        );

        $statement->execute([
            'user_id' => $userId,
        ]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get audit logs for an entity.
     */
    public function findByEntity(
        string $entityType,
        string $entityId,
        int $limit = 100
    ): array {
        $entityType = trim($entityType);
        $entityId = trim($entityId);
        $limit = $this->normalizeLimit($limit);

        if ($entityType === '' || $entityId === '') {
            return [];
        }

        $statement = $this->db->prepare(
            <<<SQL
                SELECT
                    id,
                    user_id,
                    action,
                    entity_type,
                    entity_id,
                    ip_address,
                    metadata,
                    created_at
                FROM audit_logs
                WHERE entity_type = :entity_type
                  AND entity_id = :entity_id
                ORDER BY created_at DESC
                LIMIT {$limit}
            SQL
        );

        $statement->execute([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
        ]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get recent audit activity.
     */
    public function recent(
        int $limit = 100
    ): array {
        $limit = $this->normalizeLimit($limit);

        $statement = $this->db->query(
            <<<SQL
                SELECT
                    id,
                    user_id,
                    action,
                    entity_type,
                    entity_id,
                    ip_address,
                    metadata,
                    created_at
                FROM audit_logs
                ORDER BY created_at DESC
                LIMIT {$limit}
            SQL
        );

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Delete audit records older than the supplied number of days.
     *
     * This should only be used if the application's retention policy
     * permits deletion.
     */
    public function deleteOlderThan(
        int $days
    ): int {
        if ($days < 1) {
            throw new RuntimeException(
                'Audit retention must be at least 1 day.'
            );
        }

        $statement = $this->db->prepare(
            <<<'SQL'
                DELETE FROM audit_logs
                WHERE created_at < NOW() - (
                    :days * INTERVAL '1 day'
                )
                RETURNING id
            SQL
        );

        $statement->execute([
            'days' => $days,
        ]);

        return count(
            $statement->fetchAll(PDO::FETCH_COLUMN)
        );
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
        return max(1, min($limit, 500));
    }
}
