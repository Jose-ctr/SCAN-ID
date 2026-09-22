<?php

declare(strict_types=1);

namespace ScanId\Services;

use RuntimeException;

final class DocumentHashService
{
    /**
     * Supported SCAN-ID document types.
     *
     * @return array<int, string>
     */
    public static function supportedDocumentTypes(): array
    {
        return [
            'national_id',
            'passport',
            'driving_licence',
            'student_id',
            'staff_id',
            'bank_card',
            'insurance_card',
            'other',
        ];
    }

    /**
     * Normalize a document number before hashing.
     *
     * Spaces, hyphens and other formatting differences are removed.
     * Letters are converted to uppercase.
     */
    public static function normalizeDocumentNumber(
        string $documentNumber
    ): string {
        $documentNumber = trim($documentNumber);

        if ($documentNumber === '') {
            throw new RuntimeException(
                'Document number is required.'
            );
        }

        /*
         * Keep only letters and numbers.
         *
         * This means values such as:
         * AB 123-456
         * AB-123456
         * ab123456
         *
         * resolve to the same protected identifier.
         */
        $normalized = preg_replace(
            '/[^A-Za-z0-9]/',
            '',
            $documentNumber
        );

        if (
            $normalized === null ||
            $normalized === ''
        ) {
            throw new RuntimeException(
                'Invalid document number.'
            );
        }

        $normalized = strtoupper($normalized);

        if (strlen($normalized) > 100) {
            throw new RuntimeException(
                'Document number is too long.'
            );
        }

        return $normalized;
    }

    /**
     * Create a protected SHA-256 document identifier.
     *
     * The raw document number must never be stored in the database.
     */
    public static function hashDocumentNumber(
        string $documentNumber
    ): string {
        $normalized = self::normalizeDocumentNumber(
            $documentNumber
        );

        return hash(
            'sha256',
            $normalized
        );
    }

    /**
     * Return only the final four characters for safe display.
     */
    public static function lastFour(
        string $documentNumber
    ): string {
        $normalized = self::normalizeDocumentNumber(
            $documentNumber
        );

        return substr(
            $normalized,
            -4
        );
    }

    /**
     * Validate a document type.
     */
    public static function validateDocumentType(
        string $documentType
    ): string {
        $documentType = strtolower(
            trim($documentType)
        );

        if (
            !in_array(
                $documentType,
                self::supportedDocumentTypes(),
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
     * Build the protected document data used by models.
     *
     * @return array{
     *     document_number_hash: string,
     *     document_number_last4: string
     * }
     */
    public static function prepare(
        string $documentNumber
    ): array {
        return [
            'document_number_hash' =>
                self::hashDocumentNumber($documentNumber),

            'document_number_last4' =>
                self::lastFour($documentNumber),
        ];
    }

    private function __construct()
    {
    }
}
