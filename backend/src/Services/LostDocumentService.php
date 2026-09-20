<?php

declare(strict_types=1);

namespace ScanId\Services;

use RuntimeException;
use ScanId\Config\Database;
use ScanId\Models\LostDocument;

final class LostDocumentService
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
     * Create a lost-document report.
     *
     * The raw document number is accepted only for processing.
     * It is immediately converted into a server-side HMAC hash.
     */
    public static function createReport(
        ?string $ownerUserId,
        string $ownerPhone,
        string $documentType,
        string $documentNumber,
        string $lostLocation,
        ?string $lostAt = null
    ): array {
        $ownerPhone = trim($ownerPhone);
        $documentType = self::normalizeDocumentType(
            $documentType
        );
        $lostLocation = trim($lostLocation);

        if ($ownerPhone === '') {
            throw new RuntimeException(
                'Owner phone number is required.'
            );
        }

        if (strlen($ownerPhone) > 30) {
            throw new RuntimeException(
                'Owner phone number is too long.'
            );
        }

        if ($lostLocation === '') {
            throw new RuntimeException(
                'Lost location is required.'
            );
        }

        if (strlen($lostLocation) > 255) {
            throw new RuntimeException(
                'Lost location is too long.'
            );
        }

        if ($ownerUserId !== null) {
            $ownerUserId = trim($ownerUserId);

            if ($ownerUserId === '') {
                $ownerUserId = null;
            }
        }

        /*
         * Never accept a document hash from the frontend.
         */
        $documentHash = DocumentHashService::hash(
            $documentNumber
        );

        $documentLast4 = DocumentHashService::lastFour(
            $documentNumber
        );

        $database = Database::connection();

        $model = new LostDocument($database);

        return $model->create(
            $ownerUserId,
            $ownerPhone,
            $documentType,
            $documentHash,
            $documentLast4,
            $lostLocation,
            $lostAt
        );
    }

    /**
     * Find a lost-document report by ID.
     */
    public static function findById(
        string $id
    ): ?array {
        $id = trim($id);

        if ($id === '') {
            throw new RuntimeException(
                'Lost document ID is required.'
            );
        }

        $database = Database::connection();

        $model = new LostDocument($database);

        return $model->findById($id);
    }

    /**
     * Find potential found-document matches.
     *
     * Matching is performed by the server using the same
     * protected document hash.
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

        $model = new LostDocument($database);

        return $model->findMatches(
            $documentHash,
            $documentType
        );
    }

    /**
     * Mark a report as matched.
     */
    public static function markMatched(
        string $id
    ): array {
        $model = self::model();

        $result = $model->markMatched($id);

        if ($result === null) {
            throw new RuntimeException(
                'Lost document report not found.'
            );
        }

        return $result;
    }

    /**
     * Mark a report as awaiting recovery.
     */
    public static function markRecoveryPending(
        string $id
    ): array {
        $model = self::model();

        $result = $model->markRecoveryPending($id);

        if ($result === null) {
            throw new RuntimeException(
                'Lost document report not found.'
            );
        }

        return $result;
    }

    /**
     * Mark a report as recovered.
     */
    public static function markRecovered(
        string $id
    ): array {
        $model = self::model();

        $result = $model->markRecovered($id);

        if ($result === null) {
            throw new RuntimeException(
                'Lost document report not found.'
            );
        }

        return $result;
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

    /**
     * Return supported document types.
     */
    public static function documentTypes(): array
    {
        return self::DOCUMENT_TYPES;
    }

    private static function model(): LostDocument
    {
        return new LostDocument(
            Database::connection()
        );
    }

    private function __construct()
    {
    }
}
