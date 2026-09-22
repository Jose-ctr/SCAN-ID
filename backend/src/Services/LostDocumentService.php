<?php

declare(strict_types=1);

namespace ScanId\Services;

use RuntimeException;
use ScanId\Config\Database;
use ScanId\Models\LostDocument;
use ScanId\Models\User;

final class LostDocumentService
{
    /**
     * Report a lost document.
     *
     * The owner may be authenticated or may report using
     * their phone number.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function report(
        array $data,
        ?string $userId = null
    ): array {
        $documentType = trim(
            (string) ($data['document_type'] ?? '')
        );

        $documentNumber = trim(
            (string) ($data['document_number'] ?? '')
        );

        $ownerPhone = trim(
            (string) ($data['owner_phone'] ?? '')
        );

        $location = isset($data['last_known_location_general'])
            ? trim(
                (string) $data['last_known_location_general']
            )
            : null;

        $lostAt = isset($data['lost_at'])
            ? trim((string) $data['lost_at'])
            : null;

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

        /*
         * Authenticated users may use the phone number stored
         * on their account. Anonymous reports must provide one.
         */
        $database = Database::connection();
        $userModel = new User($database);

        if ($userId !== null) {
            $user = $userModel->findById($userId);

            if ($user === null) {
                throw new RuntimeException(
                    'Authenticated user was not found.'
                );
            }

            if (!(bool) $user['is_active']) {
                throw new RuntimeException(
                    'This account is inactive.'
                );
            }

            if ($ownerPhone === '') {
                $ownerPhone = (string) $user['phone'];
            }
        }

        if ($ownerPhone === '') {
            throw new RuntimeException(
                'Owner phone number is required.'
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

        /*
         * Do not create duplicate active lost-document reports
         * for the same protected document identifier.
         */
        $model = new LostDocument($database);

        $existing = $model->findByHash(
            $documentData['document_number_hash']
        );

        if (
            $existing !== null &&
            $model->isActive($existing)
        ) {
            throw new RuntimeException(
                'An active lost-document report already exists for this document.'
            );
        }

        $document = $model->create(
            $userId,
            $ownerPhone,
            $documentType,
            $documentNumber,
            $location !== '' ? $location : null,
            $lostAt !== '' ? $lostAt : null
        );

        return $model->publicData($document);
    }

    /**
     * Find a lost document using its protected document number.
     *
     * No raw document number is stored or returned.
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
        $model = new LostDocument($database);

        $document = $model->findByHash($hash);

        if ($document === null) {
            return null;
        }

        return $model->publicData($document);
    }

    /**
     * Get a lost-document report by ID.
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
        $model = new LostDocument($database);

        $document = $model->findById($id);

        if ($document === null) {
            return null;
        }

        return $model->publicData($document);
    }

    /**
     * Get the authenticated user's lost-document reports.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function findByOwner(
        string $userId
    ): array {
        $userId = trim($userId);

        if ($userId === '') {
            throw new RuntimeException(
                'User ID is required.'
            );
        }

        $database = Database::connection();
        $model = new LostDocument($database);

        $documents = $model->findByOwnerUser($userId);

        return array_map(
            static fn(array $document): array =>
                $model->publicData($document),
            $documents
        );
    }

    /**
     * Cancel a lost-document report belonging to the user.
     *
     * @return array<string, mixed>
     */
    public static function cancel(
        string $documentId,
        string $userId
    ): array {
        $documentId = trim($documentId);
        $userId = trim($userId);

        if ($documentId === '' || $userId === '') {
            throw new RuntimeException(
                'Document ID and user ID are required.'
            );
        }

        $database = Database::connection();
        $model = new LostDocument($database);

        $document = $model->findById($documentId);

        if ($document === null) {
            throw new RuntimeException(
                'Lost-document report not found.'
            );
        }

        if (
            (string) ($document['owner_user_id'] ?? '') !==
            $userId
        ) {
            throw new RuntimeException(
                'You are not allowed to modify this report.'
            );
        }

        if (!$model->isActive($document)) {
            throw new RuntimeException(
                'This lost-document report is no longer active.'
            );
        }

        $updated = $model->cancel($documentId);

        if ($updated === null) {
            throw new RuntimeException(
                'Unable to cancel the lost-document report.'
            );
        }

        return $model->publicData($updated);
    }

    /**
     * Mark a lost document as matched.
     *
     * This is called by the recovery matching workflow,
     * not directly by an unauthenticated client.
     *
     * @return array<string, mixed>
     */
    public static function markMatched(
        string $documentId
    ): array {
        $documentId = trim($documentId);

        if ($documentId === '') {
            throw new RuntimeException(
                'Document ID is required.'
            );
        }

        $database = Database::connection();
        $model = new LostDocument($database);

        $document = $model->findById($documentId);

        if ($document === null) {
            throw new RuntimeException(
                'Lost-document report not found.'
            );
        }

        if (!$model->isActive($document)) {
            throw new RuntimeException(
                'This lost-document report is no longer active.'
            );
        }

        $updated = $model->markMatched($documentId);

        if ($updated === null) {
            throw new RuntimeException(
                'Unable to mark the document as matched.'
            );
        }

        return $model->publicData($updated);
    }

    /**
     * Mark a lost document as recovery pending.
     *
     * @return array<string, mixed>
     */
    public static function markRecoveryPending(
        string $documentId
    ): array {
        $documentId = trim($documentId);

        if ($documentId === '') {
            throw new RuntimeException(
                'Document ID is required.'
            );
        }

        $database = Database::connection();
        $model = new LostDocument($database);

        $document = $model->findById($documentId);

        if ($document === null) {
            throw new RuntimeException(
                'Lost-document report not found.'
            );
        }

        $updated = $model->markRecoveryPending(
            $documentId
        );

        if ($updated === null) {
            throw new RuntimeException(
                'Unable to update the lost-document status.'
            );
        }

        return $model->publicData($updated);
    }

    /**
     * Mark a lost document as recovered.
     *
     * This should only be called after successful,
     * verified handover.
     *
     * @return array<string, mixed>
     */
    public static function markRecovered(
        string $documentId
    ): array {
        $documentId = trim($documentId);

        if ($documentId === '') {
            throw new RuntimeException(
                'Document ID is required.'
            );
        }

        $database = Database::connection();
        $model = new LostDocument($database);

        $document = $model->findById($documentId);

        if ($document === null) {
            throw new RuntimeException(
                'Lost-document report not found.'
            );
        }

        $updated = $model->markRecovered(
            $documentId
        );

        if ($updated === null) {
            throw new RuntimeException(
                'Unable to mark the document as recovered.'
            );
        }

        return $model->publicData($updated);
    }

    private function __construct()
    {
    }
}
