<?php

declare(strict_types=1);

namespace ScanId\Models;

use PDO;
use RuntimeException;

final class LostId
{
    public function __construct(
        private readonly PDO $db
    ) {
    }

    /**
     * Create a lost document record.
     *
     * The raw document number must never be stored.
     * Only its SHA-256 hash is persisted.
     */
    public function create(
        ?string $ownerUserId,
        string $ownerPhone,
        string $idType,
        string $idNumberHash,
        ?string $idNumberLast4 = null,
        ?string $lastKnownLocationGeneral = null,
        ?string $lostAt = null
    ): array {
        $ownerPhone = trim($ownerPhone);
        $idType = trim($idType);
        $idNumberHash = trim($idNumberHash);

        if ($ownerPhone === '') {
            throw new RuntimeException(
                'Owner phone number is required.'
            );
        }

        if ($idType === '') {
            throw new RuntimeException(
                'Document type is required.'
            );
        }

        if ($idNumberHash === '') {
            throw new RuntimeException(
                'Document number hash is required.'
            );
        }

        if (
            $idNumberLast4 !== null
            && !preg_match('/^\d{4}$/', $idNumberLast4)
        ) {
            throw new RuntimeException(
                'Document last four digits must contain exactly four digits.'
            );
        }

        if (
            $lastKnownLocationGeneral !== null
            && mb_strlen($lastKnownLocationGeneral) > 255
        ) {
            throw new RuntimeException(
                'Location is too long.'
            );
        }

        $sql = <<<'SQL'
            INSERT INTO lost_ids (
                owner_user_id,
                owner_phone,
                id_type,
                id_number_hash,
                id_number_last4,
                last_known_location_general,
                lost_at,
                status
            )
            VALUES (
                :owner_user_id,
                :owner_phone,
                :id_type,
                :id_number_hash,
                :id_number_last4,
                :last_known_location_general,
                :lost_at,
                'lost'
            )
            RETURNING
                id,
                owner_user_id,
                owner_phone,
                id_type,
                id_number_last4,
                last_known_location_general,
                lost_at,
                status,
                created_at,
                updated_at
        SQL;

        $statement = $this->db->prepare($sql);

        $statement->execute([
            'owner_user_id' => $ownerUserId,
            'owner_phone' => $ownerPhone,
            'id_type' => $idType,
            'id_number_hash' => $idNumberHash,
            'id_number_last4' => $idNumberLast4,
            'last_known_location_general' => $lastKnownLocationGeneral,
            'lost_at' => $lostAt,
        ]);

        $lostId = $statement->fetch(PDO::FETCH_ASSOC);

        if ($lostId === false) {
            throw new RuntimeException(
                'Lost document could not be created.'
            );
        }

        return $lostId;
    }

    /**
     * Find a lost document by its protected identifier hash.
     *
     * This is used by the matching service.
     */
    public function findByHash(
        string $idNumberHash,
        ?string $idType = null
    ): ?array {
        $idNumberHash = trim($idNumberHash);

        if ($idNumberHash === '') {
            return null;
        }

        if ($idType !== null) {
            $idType = trim($idType);
        }

        if ($idType !== null && $idType !== '') {
            $statement = $this->db->prepare(
                <<<'SQL'
                    SELECT
                        id,
                        owner_user_id,
                        owner_phone,
                        id_type,
                        id_number_last4,
                        last_known_location_general,
                        lost_at,
                        status,
                        created_at,
                        updated_at
                    FROM lost_ids
                    WHERE id_number_hash = :id_number_hash
                      AND id_type = :id_type
                      AND status IN (
                          'lost',
                          'matched',
                          'recovery_pending'
                      )
                    ORDER BY created_at DESC
                    LIMIT 1
                SQL
            );

            $statement->execute([
                'id_number_hash' => $idNumberHash,
                'id_type' => $idType,
            ]);
        } else {
            $statement = $this->db->prepare(
                <<<'SQL'
                    SELECT
                        id,
                        owner_user_id,
                        owner_phone,
                        id_type,
                        id_number_last4,
                        last_known_location_general,
                        lost_at,
                        status,
                        created_at,
                        updated_at
                    FROM lost_ids
                    WHERE id_number_hash = :id_number_hash
                      AND status IN (
                          'lost',
                          'matched',
                          'recovery_pending'
                      )
                    ORDER BY created_at DESC
                    LIMIT 1
                SQL
            );

            $statement->execute([
                'id_number_hash' => $idNumberHash,
            ]);
        }

        $lostId = $statement->fetch(PDO::FETCH_ASSOC);

        return $lostId === false ? null : $lostId;
    }

    /**
     * Find a lost document by UUID.
     */
    public function findById(string $id): ?array
    {
        $id = trim($id);

        if ($id === '') {
            return null;
        }

        $statement = $this->db->prepare(
            <<<'SQL'
                SELECT
                    id,
                    owner_user_id,
                    owner_phone,
                    id_type,
                    id_number_last4,
                    last_known_location_general,
                    lost_at,
                    status,
                    created_at,
                    updated_at
                FROM lost_ids
                WHERE id = :id
                LIMIT 1
            SQL
        );

        $statement->execute([
            'id' => $id,
        ]);

        $lostId = $statement->fetch(PDO::FETCH_ASSOC);

        return $lostId === false ? null : $lostId;
    }

    /**
     * Get active lost documents belonging to a user.
     */
    public function findByOwnerUser(
        string $ownerUserId,
        int $limit = 100
    ): array {
        $ownerUserId = trim($ownerUserId);

        if ($ownerUserId === '') {
            return [];
        }

        $limit = max(1, min($limit, 500));

        $sql = sprintf(
            <<<'SQL'
                SELECT
                    id,
                    owner_user_id,
                    owner_phone,
                    id_type,
                    id_number_last4,
                    last_known_location_general,
                    lost_at,
                    status,
                    created_at,
                    updated_at
                FROM lost_ids
                WHERE owner_user_id = :owner_user_id
                ORDER BY created_at DESC
                LIMIT %d
            SQL,
            $limit
        );

        $statement = $this->db->prepare($sql);

        $statement->execute([
            'owner_user_id' => $ownerUserId,
        ]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get lost documents reported using a phone number.
     */
    public function findByPhone(
        string $ownerPhone,
        int $limit = 100
    ): array {
        $ownerPhone = trim($ownerPhone);

        if ($ownerPhone === '') {
            return [];
        }

        $limit = max(1, min($limit, 500));

        $sql = sprintf(
            <<<'SQL'
                SELECT
                    id,
                    owner_user_id,
                    owner_phone,
                    id_type,
                    id_number_last4,
                    last_known_location_general,
                    lost_at,
                    status,
                    created_at,
                    updated_at
                FROM lost_ids
                WHERE owner_phone = :owner_phone
                ORDER BY created_at DESC
                LIMIT %d
            SQL,
            $limit
        );

        $statement = $this->db->prepare($sql);

        $statement->execute([
            'owner_phone' => $ownerPhone,
        ]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Update the status of a lost document.
     */
    public function updateStatus(
        string $id,
        string $status
    ): array {
        $id = trim($id);
        $status = trim($status);

        $allowedStatuses = [
            'lost',
            'matched',
            'recovery_pending',
            'recovered',
            'cancelled',
            'expired',
        ];

        if ($id === '') {
            throw new RuntimeException(
                'Lost document ID is required.'
            );
        }

        if (!in_array($status, $allowedStatuses, true)) {
            throw new RuntimeException(
                'Invalid lost document status.'
            );
        }

        $statement = $this->db->prepare(
            <<<'SQL'
                UPDATE lost_ids
                SET
                    status = :status,
                    updated_at = NOW()
                WHERE id = :id
                RETURNING
                    id,
                    owner_user_id,
                    owner_phone,
                    id_type,
                    id_number_last4,
                    last_known_location_general,
                    lost_at,
                    status,
                    created_at,
                    updated_at
            SQL
        );

        $statement->execute([
            'id' => $id,
            'status' => $status,
        ]);

        $lostId = $statement->fetch(PDO::FETCH_ASSOC);

        if ($lostId === false) {
            throw new RuntimeException(
                'Lost document not found.'
            );
        }

        return $lostId;
    }

    /**
     * Mark a lost document as matched.
     */
    public function markMatched(string $id): array
    {
        return $this->updateStatus($id, 'matched');
    }

    /**
     * Mark a lost document as awaiting recovery.
     */
    public function markRecoveryPending(string $id): array
    {
        return $this->updateStatus($id, 'recovery_pending');
    }

    /**
     * Mark a lost document as recovered.
     */
    public function markRecovered(string $id): array
    {
        return $this->updateStatus($id, 'recovered');
    }

    /**
     * Cancel a lost document report.
     */
    public function cancel(string $id): array
    {
        return $this->updateStatus($id, 'cancelled');
    }

    /**
     * Expire a lost document report.
     */
    public function expire(string $id): array
    {
        return $this->updateStatus($id, 'expired');
    }

    /**
     * Check whether the supplied user owns this lost document.
     */
    public function belongsToUser(
        string $id,
        string $userId
    ): bool {
        $id = trim($id);
        $userId = trim($userId);

        if ($id === '' || $userId === '') {
            return false;
        }

        $statement = $this->db->prepare(
            <<<'SQL'
                SELECT 1
                FROM lost_ids
                WHERE id = :id
                  AND owner_user_id = :owner_user_id
                LIMIT 1
            SQL
        );

        $statement->execute([
            'id' => $id,
            'owner_user_id' => $userId,
        ]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * Hash a document number consistently.
     *
     * The raw document number is never returned or stored.
     */
    public static function hashDocumentNumber(
        string $idNumber
    ): string {
        $idNumber = trim($idNumber);

        if ($idNumber === '') {
            throw new RuntimeException(
                'Document number is required.'
            );
        }

        return hash(
            'sha256',
            self::normalizeDocumentNumber($idNumber)
        );
    }

    /**
     * Return the final four digits of a document number.
     */
    public static function lastFour(
        string $idNumber
    ): string {
        $normalized = self::normalizeDocumentNumber($idNumber);

        if (mb_strlen($normalized) < 4) {
            throw new RuntimeException(
                'Document number must contain at least four characters.'
            );
        }

        return substr($normalized, -4);
    }

    /**
     * Normalize an identifier before hashing.
     *
     * Spaces, hyphens and letter casing are normalized so that
     * harmless formatting differences produce the same hash.
     */
    public static function normalizeDocumentNumber(
        string $idNumber
    ): string {
        $idNumber = trim($idNumber);

        if ($idNumber === '') {
            throw new RuntimeException(
                'Document number is required.'
            );
        }

        $idNumber = preg_replace(
            '/[\s\-]+/',
            '',
            $idNumber
        );

        if ($idNumber === null || $idNumber === '') {
            throw new RuntimeException(
                'Invalid document number.'
            );
        }

        return strtoupper($idNumber);
    }

    private function __clone(): void
    {
    }
}
