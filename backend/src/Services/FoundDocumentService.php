<?php

declare(strict_types=1);

namespace ScanId\Services;

use RuntimeException;
use ScanId\Config\Database;
use ScanId\Models\FoundDocument;
use ScanId\Models\User;

final class FoundDocumentService
{
    /**
     * Report a found document.
     *
     * A finder may be:
     * - an authenticated SCAN-ID user, or
     * - an anonymous person providing a phone number.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function report(
        array $data,
        ?string $finderUserId = null
    ): array {
        $documentType = trim(
            (string) ($data['document_type'] ?? '')
        );

        $documentNumber = trim(
            (string) ($data['document_number'] ?? '')
        );

        $finderPhone = trim(
            (string) ($data['finder_phone'] ?? '')
        );

        $location = isset($data['found_location_general'])
            ? trim(
                (string) $data['found_location_general']
            )
            : null;

        $foundAt = isset($data['found_at'])
            ? trim((string) $data['found_at'])
            : null;

        $consent = isset($data['finder_consent_to_contact'])
            ? (bool) $data['finder_consent_to_contact']
            : false;

        if ($documentType === '') {
            throw new RuntimeException(
                'Document type is required.'
            );
        }

        if ($documentNumber === '') {
            throw new RuntimeException(
                'Document number is required.'
            );
        }

        $database = Database::connection();
        $userModel = new User($database);

        /*
         * Authenticated finders may use the phone number
         * attached to their SCAN-ID account.
         */
        if ($finderUserId !== null) {
            $user = $userModel->findById($finderUserId);

            if ($user === null) {
                throw new RuntimeException(
                    'Finder account was not found.'
                );
            }

            if (!(bool) $user['is_active']) {
                throw new RuntimeException(
                    'This account is inactive.'
                );
            }

            if ($finderPhone === '') {
                $finderPhone = (string) $user['phone'];
            }
        }

        /*
         * Anonymous finders must provide a phone number because
         * the recovery workflow needs a safe contact channel and
         * the eventual KSh 150 finder reward must have a recipient.
         */
        if ($finderPhone === '') {
            throw new RuntimeException(
                'Finder phone number is required.'
            );
        }

        $documentType =
            DocumentHashService::validateDocumentType(
                $documentType
            );

        $documentData =
            DocumentHashService::prepare(
                $documentNumber
            );

        $model = new FoundDocument($database);

        /*
         * Prevent duplicate active found-document reports.
         */
        $existing = $model->findByHash(
            $documentData['document_number_hash']
        );

        if (
            $existing !== null &&
            $model->isActive($existing)
        ) {
            throw new RuntimeException(
                'An active found-document report already exists for this document.'
            );
        }

        $document = $model->create(
            $finderUserId,
            $finderPhone,
            $documentType,
            $documentNumber,
            $location !== '' ? $location : null,
            $foundAt !== '' ? $foundAt : null
        );

        /*
         * Contact consent is deliberately stored separately
         * from the initial report. This makes consent explicit
         * and auditable.
         */
        if ($consent) {
            $document = $model->grantContactConsent(
                (string) $document['id']
            ) ?? $document;
        }

        return $model->publicData($document);
    }

    /**
     * Find a found-document report by protected document number.
     *
     * @return array<string, mixed>|null
     */
    public static function findByDocumentNumber(
        string $documentNumber
    ): ?array {
        $hash =
            DocumentHashService::hashDocumentNumber(
                $documentNumber
            );

        $database = Database::connection();
        $model = new FoundDocument($database);

        $document = $model->findByHash($hash);

        if ($document === null) {
            return null;
        }

        return $model->publicData($document);
    }

    /**
     * Find a found-document report by ID.
     *
     * @return array<string, mixed>|null
     */
    public static function findById(
        string $id
    ): ?array {
        $id = trim($id);

        if ($id === '') {
            return null;
        }

        $database = Database::connection();
        $model = new FoundDocument($database);

        $document = $model->findById($id);

        if ($document === null) {
            return null;
        }

        return $model->publicData($document);
    }

    /**
     * Get reports created by an authenticated finder.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function findByFinder(
        string $finderUserId
    ): array {
        $finderUserId = trim($finderUserId);

        if ($finderUserId === '') {
            throw new RuntimeException(
                'Finder user ID is required.'
            );
        }

        $database = Database::connection();
        $model = new FoundDocument($database);

        $documents = $model->findByFinderUser(
            $finderUserId
        );

        return array_map(
            static fn(array $document): array =>
                $model->publicData($document),
            $documents
        );
    }

    /**
     * Grant permission for the finder to be contacted.
     *
     * @return array<string, mixed>
     */
    public static function grantContactConsent(
        string $documentId
    ): array {
        $documentId = trim($documentId);

        if ($documentId === '') {
            throw new RuntimeException(
                'Document ID is required.'
            );
        }

        $database = Database::connection();
        $model = new FoundDocument($database);

        $document = $model->findById($documentId);

        if ($document === null) {
            throw new RuntimeException(
                'Found-document report not found.'
            );
        }

        $updated = $model->grantContactConsent(
            $documentId
        );

        if ($updated === null) {
            throw new RuntimeException(
                'Unable to grant contact consent.'
            );
        }

        return $model->publicData($updated);
    }

    /**
     * Revoke finder contact consent.
     *
     * @return array<string, mixed>
     */
    public static function revokeContactConsent(
        string $documentId
    ): array {
        $documentId = trim($documentId);

        if ($documentId === '') {
            throw new RuntimeException(
                'Document ID is required.'
            );
        }

        $database = Database::connection();
        $model = new FoundDocument($database);

        $document = $model->findById($documentId);

        if ($document === null) {
            throw new RuntimeException(
                'Found-document report not found.'
            );
        }

        $updated = $model->revokeContactConsent(
            $documentId
        );

        if ($updated === null) {
            throw new RuntimeException(
                'Unable to revoke contact consent.'
            );
        }

        return $model->publicData($updated);
    }

    /**
     * Mark the owner as notified.
     *
     * This should be called by the notification/recovery
     * workflow after a successful owner notification.
     *
     * @return array<string, mixed>
     */
    public static function markOwnerNotified(
        string $documentId
    ): array {
        $documentId = trim($documentId);

        if ($documentId === '') {
            throw new RuntimeException(
                'Document ID is required.'
            );
        }

        $database = Database::connection();
        $model = new FoundDocument($database);

        $document = $model->findById($documentId);

        if ($document === null) {
            throw new RuntimeException(
                'Found-document report not found.'
            );
        }

        $updated = $model->markOwnerNotified(
            $documentId
        );

        if ($updated === null) {
            throw new RuntimeException(
                'Unable to update the found-document status.'
            );
        }

        return $model->publicData($updated);
    }

    /**
     * Mark recovery as requested.
     *
     * @return array<string, mixed>
     */
    public static function markRecoveryRequested(
        string $documentId
    ): array {
        $documentId = trim($documentId);

        if ($documentId === '') {
            throw new RuntimeException(
                'Document ID is required.'
            );
        }

        $database = Database::connection();
        $model = new FoundDocument($database);

        $document = $model->findById($documentId);

        if ($document === null) {
            throw new RuntimeException(
                'Found-document report not found.'
            );
        }

        $updated = $model->markRecoveryRequested(
            $documentId
        );

        if ($updated === null) {
            throw new RuntimeException(
                'Unable to update the found-document status.'
            );
        }

        return $model->publicData($updated);
    }

    /**
     * Mark a found document as ready for handover.
     *
     * @return array<string, mixed>
     */
    public static function markHandoverPending(
        string $documentId
    ): array {
        $documentId = trim($documentId);

        if ($documentId === '') {
            throw new RuntimeException(
                'Document ID is required.'
            );
        }

        $database = Database::connection();
        $model = new FoundDocument($database);

        $document = $model->findById($documentId);

        if ($document === null) {
            throw new RuntimeException(
                'Found-document report not found.'
            );
        }

        $updated = $model->markHandoverPending(
            $documentId
        );

        if ($updated === null) {
            throw new RuntimeException(
                'Unable to update the found-document status.'
            );
        }

        return $model->publicData($updated);
    }

    /**
     * Mark the document as returned after verified handover.
     *
     * @return array<string, mixed>
     */
    public static function markReturned(
        string $documentId
    ): array {
        $documentId = trim($documentId);

        if ($documentId === '') {
            throw new RuntimeException(
                'Document ID is required.'
            );
        }

        $database = Database::connection();
        $model = new FoundDocument($database);

        $document = $model->findById($documentId);

        if ($document === null) {
            throw new RuntimeException(
                'Found-document report not found.'
            );
        }

        $updated = $model->markReturned(
            $documentId
        );

        if ($updated === null) {
            throw new RuntimeException(
                'Unable to mark the document as returned.'
            );
        }

        return $model->publicData($updated);
    }

    /**
     * Cancel a found-document report.
     *
     * @return array<string, mixed>
     */
    public static function cancel(
        string $documentId,
        ?string $finderUserId = null
    ): array {
        $documentId = trim($documentId);

        if ($documentId === '') {
            throw new RuntimeException(
                'Document ID is required.'
            );
        }

        $database = Database::connection();
        $model = new FoundDocument($database);

        $document = $model->findById($documentId);

        if ($document === null) {
            throw new RuntimeException(
                'Found-document report not found.'
            );
        }

        if ($finderUserId !== null) {
            if (
                (string) ($document['finder_user_id'] ?? '') !==
                $finderUserId
            ) {
                throw new RuntimeException(
                    'You are not allowed to modify this report.'
                );
            }
        }

        if (!$model->isActive($document)) {
            throw new RuntimeException(
                'This found-document report is no longer active.'
            );
        }

        $updated = $model->cancel($documentId);

        if ($updated === null) {
            throw new RuntimeException(
                'Unable to cancel the found-document report.'
            );
        }

        return $model->publicData($updated);
    }

    private function __construct()
    {
    }
}
