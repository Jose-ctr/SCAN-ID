<?php

declare(strict_types=1);

namespace ScanId\Models;

use PDO;
use RuntimeException;

final class FoundDocument
{
    public function __construct(
        private readonly PDO $database
    ) {
    }

    /**
     * Create a found-document report.
     *
     * IMPORTANT:
     * - document_number_hash must be generated server-side.
     * - Never store the raw document number.
     * - Never return the raw document number.
     */
    public function create(
        ?string $finderUserId,
        string $documentType,
        string $documentNumberHash,
        string $documentNumberLast4,
        string $foundLocation,
        string $finderPhone,
        bool $finderConsentToContact = false
    ): array {
        $documentType = trim($documentType);
        $documentNumberHash = trim($documentNumberHash);
        $documentNumberLast4 = trim($documentNumberLast4);
        $foundLocation = trim($foundLocation);
        $finderPhone = trim($finderPhone);

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

        if ($foundLocation === '') {
            throw new RuntimeException(
                'Location where the document was found is required.'
            );
        }

        if ($finderPhone === '') {
            throw new RuntimeException(
                'Finder phone number is required.'
            );
        }

        if (mb_strlen($documentType) > 50) {
            throw new RuntimeException(
                'Document type is too long.'
            );
        }

        if (mb_strlen($foundLocation) > 255) {
            throw new RuntimeException(
                'Found location is too long.'
            );
        }

        if (mb_strlen($finderPhone) > 30) {
            throw new RuntimeException(
                'Finder phone number is too long.'
            );
        }

        if (
            $finderUserId !== null
            && trim($finderUserId) === ''
        ) {
            $finderUserId = null;
        }

        $statement = $this->database->prepare(
            <<<'SQL'
            INSERT INTO found_documents (
                finder_user_id,
                document_type,
                document_number_hash,
                document_number_last4,
                found_location,
                found_at,
                status,
                finder_phone,
                finder_consent_to_contact,
                finder_consent_at
            )
            VALUES (
                :finder_user_id,
                :document_type,
                :document_number_hash,
                :document_number_last4,
                :found_location,
                NOW(),
                'found',
                :finder_phone,
                :finder_consent_to_contact,
                CASE
                    WHEN :finder_consent_to_contact_at = TRUE
                    THEN NOW()
                    ELSE NULL
                END
            )
            RETURNING
                id,
                finder_user_id,
                document_type,
                document_number_last4,
                found_location,
                found_at,
                status,
                finder_phone,
                finder_consent_to_contact,
                finder_consent_at,
                created_at,
                updated_at
            SQL
        );

        $statement->execute([
            'finder_user_id' => $finderUserId,
            'document_type' => $documentType,
            'document_number_hash' => strtolower(
                $documentNumberHash
            ),
            'document_number_last4' => $documentNumberLast4,
            'found_location' => $foundLocation,
            'finder_phone' => $finderPhone,
            'finder_consent_to_contact' => $finderConsentToContact,
            'finder_consent_to_contact_at' =>
                $finderConsentToContact,
        ]);

        $document = $statement->fetch();

        if (!is_array($document)) {
            throw new RuntimeException(
                'Unable to create found-document report.'
            );
        }

        return $document;
    }

    /**
     * Find a found document by ID.
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
                finder_user_id,
                document_type,
                document_number_last4,
                found_location,
                found_at,
                status,
                finder_phone,
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

        $document = $statement->fetch();

        return is_array($document)
            ? $document
            : null;
    }

    /**
     * Find documents matching a protected document identifier.
     *
     * Matching is performed server-side using the hash.
     * Raw document numbers are never exposed.
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
                finder_user_id,
                document_type,
                document_number_last4,
                found_location,
                found_at,
                status,
                finder_phone,
                finder_consent_to_contact,
                finder_consent_at,
                created_at,
                updated_at
            FROM found_documents
            WHERE document_number_hash = :document_number_hash
              AND status IN (
                  'found',
                  'owner_notified',
                  'recovery_requested',
                  'handover_pending'
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

        $sql .= ' ORDER BY found_at DESC';

        $statement = $this->database->prepare($sql);
        $statement->execute($parameters);

        $documents = $statement->fetchAll();

        return is_array($documents)
            ? $documents
            : [];
    }

    /**
     * Update the document status.
     */
    public function updateStatus(
        string $id,
        string $status
    ): bool {
        $id = trim($id);
        $status = trim($status);

        $allowedStatuses = [
            'found',
            'owner_notified',
            'recovery_requested',
            'handover_pending',
            'returned',
            'expired',
            'cancelled',
        ];

        if ($id === '') {
            throw new RuntimeException(
                'Found document ID is required.'
            );
        }

        if (!in_array($status, $allowedStatuses, true)) {
            throw new RuntimeException(
                'Invalid found-document status.'
            );
        }

        $statement = $this->database->prepare(
            <<<'SQL'
            UPDATE found_documents
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
     * Record finder consent to be contacted.
     */
    public function grantContactConsent(
        string $id
    ): bool {
        $id = trim($id);

        if ($id === '') {
            throw new RuntimeException(
                'Found document ID is required.'
            );
        }

        $statement = $this->database->prepare(
            <<<'SQL'
            UPDATE found_documents
            SET
                finder_consent_to_contact = TRUE,
                finder_consent_at = NOW(),
                updated_at = NOW()
            WHERE id = :id
            SQL
        );

        $statement->execute([
            'id' => $id,
        ]);

        return $statement->rowCount() > 0;
    }

    /**
     * Check whether the finder has consented to contact.
     */
    public function hasContactConsent(
        string $id
    ): bool {
        $document = $this->findById($id);

        if ($document === null) {
            return false;
        }

        return (bool) (
            $document['finder_consent_to_contact']
            ?? false
        );
    }
}
