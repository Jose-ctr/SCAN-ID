<?php

declare(strict_types=1);

namespace ScanId\Services;

use RuntimeException;
use ScanId\Config\Database;
use ScanId\Models\FoundDocument;

final class FoundDocumentService
{
    /**
     * Supported SCAN-ID document types.
     */
    private const DOCUMENT_TYPES = [
        'national-id',
        'passport',
        'driving-licence',
        'student-id',
        'staff-id',
        'bank-card',
        'insurance-card',
        'other',
    ];

    /**
     * Create a found-document report.
     *
     * The raw document number is used only during processing.
     * It is never stored in the database.
     */
    public static function createReport(
        ?string $finderUserId,
        string $documentType,
        string $documentNumber,
        string $foundLocation,
        string $finderPhone,
        bool $finderConsentToContact = false
    ): array {
        $documentType = self::normalizeDocumentType(
            $documentType
        );

        $foundLocation = trim($foundLocation);
        $finderPhone = trim($finderPhone);

        if ($foundLocation === '') {
            throw new RuntimeException(
                'Found location is required.'
            );
        }

        if (strlen($foundLocation) > 255) {
            throw new RuntimeException(
                'Found location is too long.'
            );
        }

        if ($finderPhone === '') {
            throw new RuntimeException(
                'Finder phone number is required.'
            );
        }

        if (strlen($finderPhone) > 30) {
            throw new RuntimeException(
                'Finder phone number is too long.'
            );
        }

        if ($finderUserId !== null) {
            $finderUserId = trim($finderUserId);

            if ($finderUserId === '') {
                $finderUserId = null;
            }
        }

        /*
         * The frontend must never submit a pre-computed hash.
         * The backend creates it using the secret hashing service.
         */
        $documentHash = DocumentHashService::hash(
            $documentNumber
        );

        $documentLast4 = DocumentHashService::lastFour(
            $documentNumber
        );

        $database = Database::connection();

        $model = new FoundDocument($database);

        return $model->create(
            $finderUserId,
            $documentType,
            $documentHash,
            $documentLast4,
            $foundLocation,
            $finderPhone,
            $finderConsentToContact
        );
    }

    /**
     * Find a found-document report by ID.
     */
    public static function findById(
        string $id
    ): ?array {
        $id = trim($id);

        if ($id === '') {
            throw new RuntimeException(
                'Found document ID is required.'
            );
        }

        $database = Database::connection();

        $model = new FoundDocument($database);

        return $model->findById($id);
    }

    /**
     * Find potential lost-document matches.
     *
     * The document number is converted into the same protected
     * server-side hash used when the lost report was created.
     */
    public static function findMatches(
        string $documentNumber,
        ?string $documentType = null
    ): array {
        $documentHash = DocumentHashService::hash(
            $documentNumber
        );

        if ($documentType !== null) {
            $documentType = self::normalizeDocumentType(
                $documentType
            );
        }

        $database = Database::connection();

        $model = new FoundDocument($database);

        return $model->findMatches(
            $documentHash,
            $documentType
        );
    }

    /**
     * Update a found-document status.
     */
    public static function updateStatus(
        string $id,
        string $status
    ): array {
        $id = trim($id);
        $status = strtolower(trim($status));

        if ($id === '') {
            throw new RuntimeException(
                'Found document ID is required.'
            );
        }

        if ($status === '') {
            throw new RuntimeException(
                'Found document status is required.'
            );
        }

        $database = Database::connection();

        $model = new FoundDocument($database);

        $result = $model->updateStatus(
            $id,
            $status
        );

        if ($result === null) {
            throw new RuntimeException(
                'Found document report not found.'
            );
        }

        return $result;
    }

    /**
     * Grant the finder permission to be contacted.
     */
    public static function grantContactConsent(
        string $id
    ): array {
        $id = trim($id);

        if ($id === '') {
            throw new RuntimeException(
                'Found document ID is required.'
            );
        }

        $database = Database::connection();

        $model = new FoundDocument($database);

        $result = $model->grantContactConsent($id);

        if ($result === null) {
            throw new RuntimeException(
                'Found document report not found.'
            );
        }

        return $result;
    }

    /**
     * Check whether finder contact consent has been granted.
     */
    public static function hasContactConsent(
        string $id
    ): bool {
        $id = trim($id);

        if ($id === '') {
            return false;
        }

        $database = Database::connection();

        $model = new FoundDocument($database);

        return $model->hasContactConsent($id);
    }

    /**
     * Return supported document types.
     */
    public static function documentTypes(): array
    {
        return self::DOCUMENT_TYPES;
    }

    /**
     * Normalize and validate document type.
     */
    public static function normalizeDocumentType(
        string $documentType
    ): string {
        $documentType = strtolower(
            trim($documentType)
        );

        if (
            !in_array(
                $documentType,
                self::DOCUMENT_TYPES,
                true
            )
        ) {
            throw new RuntimeException(
                'Unsupported document type.'
            );
        }

        return $documentType;
    }

    private function __construct()
    {
    }
}
