<?php

declare(strict_types=1);

namespace ScanId\Services;

use PDO;
use RuntimeException;
use ScanId\Models\AuditLog;
use ScanId\Models\FoundDocument;
use ScanId\Models\LostDocument;
use ScanId\Models\RecoveryRequest;

final class RecoveryService
{
    public function __construct(
        private readonly PDO $db
    ) {
    }

    /**
     * Create a recovery request only when the lost and found
     * documents are a genuine server-side match.
     */
    public function create(
        string $lostDocumentId,
        string $foundDocumentId,
        string $ownerUserId,
        ?string $ownerPhone = null
    ): array {
        $lostDocumentId = trim($lostDocumentId);
        $foundDocumentId = trim($foundDocumentId);
        $ownerUserId = trim($ownerUserId);

        if (
            !self::isUuid($lostDocumentId)
            || !self::isUuid($foundDocumentId)
            || !self::isUuid($ownerUserId)
        ) {
            throw new RuntimeException(
                'Invalid recovery identifiers.'
            );
        }

        if ($lostDocumentId === $foundDocumentId) {
            throw new RuntimeException(
                'Lost and found document references must be different.'
            );
        }

        $lostModel = new LostDocument($this->db);
        $foundModel = new FoundDocument($this->db);
        $recoveryModel = new RecoveryRequest($this->db);

        $lost = $lostModel->findById($lostDocumentId);

        if ($lost === null) {
            throw new RuntimeException(
                'Lost document not found.'
            );
        }

        $found = $foundModel->findById($foundDocumentId);

        if ($found === null) {
            throw new RuntimeException(
                'Found document not found.'
            );
        }

        /*
         * The authenticated user must own the lost-document report.
         */
        if (
            ($lost['owner_user_id'] ?? null)
            !== $ownerUserId
        ) {
            throw new RuntimeException(
                'You are not authorized to recover this lost document.'
            );
        }

        /*
         * A user must never recover their own found report.
         */
        if (
            ($found['finder_user_id'] ?? null)
            !== null
            && ($found['finder_user_id'] ?? null)
            === $ownerUserId
        ) {
            throw new RuntimeException(
                'A document cannot be matched to its own finder report.'
            );
        }

        /*
         * Anonymous finders are supported through finder_phone.
         * Authenticated finders use finder_user_id.
         */
        $finderPhone = trim(
            (string) ($found['finder_phone'] ?? '')
        );

        if (
            ($found['finder_user_id'] ?? null) === null
            && $finderPhone === ''
        ) {
            throw new RuntimeException(
                'The found document has no valid finder contact.'
            );
        }

        /*
         * The document type must match.
         */
        if (
            ($lost['document_type'] ?? null)
            !== ($found['document_type'] ?? null)
        ) {
            throw new RuntimeException(
                'The document types do not match.'
            );
        }

        /*
         * CRITICAL SECURITY CHECK:
         *
         * Only the protected SHA-256 document identifier is compared.
         * Raw document numbers are never returned or exposed.
         */
        $lostHash = (string) (
            $lost['document_number_hash'] ?? ''
        );

        $foundHash = (string) (
            $found['document_number_hash'] ?? ''
        );

        if (
            $lostHash === ''
            || $foundHash === ''
            || !hash_equals($lostHash, $foundHash)
        ) {
            throw new RuntimeException(
                'The lost and found documents do not match.'
            );
        }

        /*
         * Only active reports may enter the recovery process.
         */
        $lostStatus = (string) ($lost['status'] ?? '');
        $foundStatus = (string) ($found['status'] ?? '');

        if (
            !in_array(
                $lostStatus,
                ['lost', 'matched'],
                true
            )
        ) {
            throw new RuntimeException(
                'The lost document is no longer available for recovery.'
            );
