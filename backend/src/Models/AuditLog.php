<?php

declare(strict_types=1);

namespace ScanId\Models;

use PDO;
use RuntimeException;

final class AuditLog
{
    public function __construct(
        private readonly PDO $database
    ) {
    }

    /**
     * Record an auditable system action.
     *
     * IMPORTANT:
     * Never pass passwords, OTPs, raw document numbers,
     * recovery tokens, API keys, or M-Pesa secrets in metadata.
     */
    public function create(
        ?string $userId,
        string $action,
        string $entityType,
        ?string $entityId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        array $metadata = []
    ): array {
        $action = trim($action);
        $entityType = trim($entityType);

        if ($action === '') {
            throw new RuntimeException(
                'Audit action is required.'
            );
        }

        if (strlen($action) > 100) {
            throw new RuntimeException(
                'Audit action is too long.'
            );
        }

        if ($entityType === '') {
            throw new RuntimeException(
                'Audit entity type is required.'
            );
        }

        if (strlen($entityType) > 100) {
            throw new RuntimeException(
                'Audit entity type is too long.'
            );
        }

        if ($userId !== null) {
            $userId = trim($userId);

            if ($userId === '') {
                $userId = null;
            }
        }

        if ($entityId !== null) {
            $entityId = trim($entityId);

            if ($entityId === '') {
                $entityId = null;
            }
        }

        if ($ipAddress !== null) {
            $ipAddress = trim($ipAddress);

            if ($ipAddress === '') {
                $ipAddress = null;
            }

            if (
                $ipAddress !== null &&
                filter_var(
                    $ipAddress,
                    FILTER_VALIDATE_IP
                ) === false
            ) {
                throw new RuntimeException(
                    'Invalid audit IP address.'
                );
            }
        }

        if ($userAgent !== null) {
            $userAgent = trim($userAgent);

            if ($userAgent === '') {
                $userAgent = null;
            }

            if (
                $userAgent !== null &&
                strlen($userAgent) > 1000
            ) {
                $userAgent = substr($userAgent, 0, 1000);
            }
        }

        $encodedMetadata = json_encode(
            $metadata,
            JSON_THROW_ON_ERROR
        );

        $statement = $this->database->prepare(
            '
            INSERT INTO audit_logs (
                user_id,
                action,
                entity_type,
                entity_id,
                ip_address,
                user_agent,
                metadata
            )
            VALUES (
                CAST(:user_id AS UUID),
                :action,
                :entity_type,
                CAST(:entity_id AS UUID),
                CAST(:ip_address AS INET),
                :user_agent,
                CAST(:metadata AS JSONB)
            )
            RETURNING
                id,
                user_id,
                action,
                entity_type,
                entity_id,
                ip_address,
                user_agent,
                metadata,
                created_at
            '
        );

        $statement->execute([
            'user_id' => $userId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'metadata' => $encodedMetadata,
        ]);

        $log = $statement->fetch();

        if (!is_array($log)) {
            throw new RuntimeException(
                'Unable to create audit log.'
            );
        }

        return $log;
    }

    /**
     * Find one audit log entry.
     */
    public function findById(
        string $id
    ): ?array {
        $id = trim($id);

        if ($id === '') {
            return null;
        }

        $statement = $this->database->prepare(
            '
            SELECT
                id,
                user_id,
                action,
                entity_type,
                entity_id,
                ip_address,
                user_agent,
                metadata,
                created_at
            FROM audit_logs
            WHERE id = CAST(:id AS UUID)
            LIMIT 1
            '
        );

        $statement->execute([
            'id' => $id,
        ]);

        $log = $statement->fetch();

        return is_array($log)
            ? $log
            : null;
    }

    /**
     * Get audit history for an entity.
     */
    public function forEntity(
        string $entityType,
        string $entityId,
        int $limit = 50
    ): array {
        $entityType = trim($entityType);
        $entityId = trim($entityId);

        if (
            $entityType === '' ||
            $entityId === ''
        ) {
            throw new RuntimeException(
                'Entity type and entity ID are required.'
            );
        }

        $limit = max(1, min($limit, 200));

        $statement = $this->database->prepare(
            '
            SELECT
                id,
                user_id,
                action,
                entity_type,
                entity_id,
                ip_address,
                user_agent,
                metadata,
                created_at
            FROM audit_logs
            WHERE entity_type = :entity_type
              AND entity_id = CAST(:entity_id AS UUID)
            ORDER BY created_at DESC
            LIMIT :limit
            '
        );

        $statement->bindValue(
            ':entity_type',
            $entityType,
            PDO::PARAM_STR
        );

        $statement->bindValue(
            ':entity_id',
            $entityId,
            PDO::PARAM_STR
        );

        $statement->bindValue(
            ':limit',
            $limit,
            PDO::PARAM_INT
        );

        $statement->execute();

        return $statement->fetchAll();
    }

    /**
     * Get recent audit history for a user.
     */
    public function forUser(
        string $userId,
        int $limit = 50
    ): array {
        $userId = trim($userId);

        if ($userId === '') {
            throw new RuntimeException(
                'User ID is required.'
            );
        }

        $limit = max(1, min($limit, 200));

        $statement = $this->database->prepare(
            '
            SELECT
                id,
                user_id,
                action,
                entity_type,
                entity_id,
                ip_address,
                user_agent,
                metadata,
                created_at
            FROM audit_logs
            WHERE user_id = CAST(:user_id AS UUID)
            ORDER BY created_at DESC
            LIMIT :limit
            '
        );

        $statement->bindValue(
            ':user_id',
            $userId,
            PDO::PARAM_STR
        );

        $statement->bindValue(
            ':limit',
            $limit,
            PDO::PARAM_INT
        );

        $statement->execute();

        return $statement->fetchAll();
    }

    private function __clone()
    {
    }

    private function __wakeup()
    {
    }
}
