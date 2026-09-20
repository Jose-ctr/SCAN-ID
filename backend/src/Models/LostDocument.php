<?php

declare(strict_types=1);

namespace ScanId\Models;

use PDO;
use RuntimeException;

final class LostDocument
{
    public function __construct(
        private readonly PDO $database
    ) {
    }

    /**
     * Create a lost-document report.
     *
     * IMPORTANT:
     * - document_number_hash must be generated server-side.
     * - Never store the raw document number.
     * - Never return the raw document number.
     */
    public function create(
        ?string $ownerUserId,
        string $ownerPhone,
        string $documentType,
        string $documentNumberHash,
        string $documentNumberLast4,
        string $lostLocation,
        ?string $lostAt = null
    ): array {
        $ownerPhone = trim($ownerPhone);
        $documentType = trim($documentType);
        $documentNumberHash = trim($documentNumberHash);
        $documentNumberLast4 = trim($documentNumberLast4);
        $lostLocation = trim($lostLocation);

        if ($ownerPhone === '') {
            throw new RuntimeException(
                'Owner phone number is required.'
            );
        }

        if ($documentType === '') {
            throw new RuntimeException(
                'Document type is required.'
            );
        }

        if ($documentNumberHash === '') {
            throw new RuntimeException(
                'Document identifier is required.'
            );
        }

        if (!preg_match('/^[a-f0-9]{64}$/i', $documentNumberHash)) {
            throw new RuntimeException(
                'Invalid document identifier.'
            );
        }

        if (!preg_match('/^\d{4}$/', $documentNumberLast4)) {
            throw new RuntimeException(
                'Document identifier suffix must contain 4 digits.'
            );
        }

        if ($lostLocation === '') {
            throw new RuntimeException(
                'Location where the document was lost is required.'
            );
        }

        if (mb_strlen($ownerPhone) > 30) {
            throw new RuntimeException(
                'Owner phone number is too long.'
            );
        }

        if (mb_strlen($documentType) > 50) {
            throw new RuntimeException(
                'Document type is too long.'
            );
        }

        if (mb_strlen($lostLocation) > 255) {
            throw new RuntimeException(
                'Lost location is too long.'
            );
        }

        if (
            $ownerUserId !== null
            && trim($ownerUserId) === ''
        ) {
            $ownerUserId = null;
        }

        $lostAtValue = $lostAt !== null
            ? trim($lostAt)
            : '';

        if ($lostAtValue === '') {
            $lostAtValue = null;
        }

        $sql = <<<'SQL'
            INSERT INTO lost_documents (
                owner_user_id,
                owner_phone,
                document_type,
                document_number_hash,
                document_number_last4,
                lost_location,
                lost_at,
                status
            )
            VALUES (
                :owner_user_id,
                :owner_phone,
                :document_type,
                :document_number_hash,
                :document_number_last4,
                :lost_location,
                COALESCE(
                    CAST(:lost_at AS TIMESTAMPTZ),
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
                lost_location,
                lost_at,
                status,
                created_at,
                updated_at
        SQL;

        $statement = $this->database->prepare($sql);

        $statement->execute([
            'owner_user_id' => $ownerUserId,
            'owner_phone' => $ownerPhone,
            'document_type' => $documentType,
            'document_number_hash' => strtolower(
                $documentNumberHash
            ),
            'document_number_last4' => $documentNumberLast4,
            'lost_location' => $lostLocation,
            'lost_at' => $lostAtValue,
        ]);

        $document = $statement->fetch();

        if (!is_array($document)) {
            throw new RuntimeException(
                'Unable to create lost-document report.'
            );
        }

        return $document;
    }

    /**
     * Find a lost document by its ID.
     *
     * Raw document identifiers are never returned.
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
                owner_user_id,
                owner_phone,
                document_type,
                document_number_last4,
                lost_location,
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

        $document = $statement->fetch();

        return is_array($document)
            ? $document
            : null;
    }

    /**
     * Find lost documents matching a protected identifier.
     *
     * Matching happens entirely on the server.
     */
    public function findMatches(
        string $documentNumberHash,
        ?string $documentType = null
    ): array {
        $documentNumberHash = trim(
            $documentNumberHash
        );

        if (
            !preg_match(
                '/^[a-f0-9]{64}$/i',
                $documentNumberHash
            )
        ) {
            throw new RuntimeException(
                'Invalid document identifier.'
            );
        }

        $documentType = $documentType !== null
            ? trim($documentType)
            : null;

        if ($documentType === '') {
            $documentType = null;
        }

        $sql = <<<'SQL'
            SELECT
                id,
                owner_user_id,
                owner_phone,
                document_type,
                document_number_last4,
                lost_location,
                lost_at,
                status,
                created_at,
                updated_at
            FROM lost_documents
            WHERE document_number_hash = :document_number_hash
              AND status IN (
                  'lost',
                  'matched',
                  'recovery_pending'
              )
        SQL;

        $parameters = [
            'document_number_hash' => strtolower(
                $documentNumberHash
            ),
        ];

        if ($documentType !== null) {
            $sql .= ' AND document_type = :document_type';

            $parameters['document_type'] = $documentType;
        }

        $sql .= ' ORDER BY lost_at DESC';

        $statement = $this->database->prepare($sql);
        $statement->execute($parameters);

        $documents = $statement->fetchAll();

        return is_array($documents)
            ? $documents
            : [];
    }

    /**
     * Update the lost-document status.
     */
    public function updateStatus(
        string $id,
        string $status
    ): bool {
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
                'Invalid lost-document status.'
            );
        }

        $statement = $this->database->prepare(
            <<<'SQL'
            UPDATE lost_documents
            SET
                status = :status,
                updated_at = NOW()
            WHERE id = :id
            SQL
        );

        $statement->execute([
            'status' => $status,
            'id' => $id,
        ]);

        return $statement->rowCount() > 0;
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
     * Mark a lost document as awaiting recovery.
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
}
