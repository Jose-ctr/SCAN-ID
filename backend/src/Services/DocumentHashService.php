<?php

declare(strict_types=1);

namespace ScanId\Services;

use RuntimeException;

final class DocumentHashService
{
    /**
     * Create a protected lookup hash for a document number.
     *
     * The raw document number is never stored.
     * HMAC-SHA256 is used instead of plain SHA-256 so that
     * predictable document numbers cannot be easily brute-forced.
     */
    public static function hash(
        string $documentNumber
    ): string {
        $normalized = self::normalize($documentNumber);
        $secret = self::secret();

        return hash_hmac(
            'sha256',
            $normalized,
            $secret
        );
    }

    /**
     * Normalize a document number before hashing.
     *
     * Spaces, dashes and letter case differences are ignored.
     * Example:
     *
     *     "A-123 456"
     *     "a123456"
     *
     * produce the same lookup value.
     */
    public static function normalize(
        string $documentNumber
    ): string {
        $documentNumber = trim($documentNumber);

        if ($documentNumber === '') {
            throw new RuntimeException(
                'Document number is required.'
            );
        }

        $documentNumber = strtoupper($documentNumber);

        $documentNumber = preg_replace(
            '/[\s\-]+/',
            '',
            $documentNumber
        );

        if ($documentNumber === null) {
            throw new RuntimeException(
                'Unable to normalize document number.'
            );
        }

        if ($documentNumber === '') {
            throw new RuntimeException(
                'Document number is invalid.'
            );
        }

        if (strlen($documentNumber) > 100) {
            throw new RuntimeException(
                'Document number is too long.'
            );
        }

        return $documentNumber;
    }

    /**
     * Return the last four characters of the normalized
     * document number for controlled display.
     */
    public static function lastFour(
        string $documentNumber
    ): string {
        $normalized = self::normalize($documentNumber);

        if (strlen($normalized) < 4) {
            throw new RuntimeException(
                'Document number must contain at least 4 characters.'
            );
        }

        return substr($normalized, -4);
    }

    /**
     * Compare a supplied document number with a stored hash.
     *
     * The comparison uses the same server-side secret.
     */
    public static function matches(
        string $documentNumber,
        string $storedHash
    ): bool {
        $storedHash = strtolower(trim($storedHash));

        if (
            $storedHash === '' ||
            !preg_match('/^[a-f0-9]{64}$/', $storedHash)
        ) {
            return false;
        }

        $calculatedHash = self::hash(
            $documentNumber
        );

        return hash_equals(
            $storedHash,
            $calculatedHash
        );
    }

    /**
     * Get the server-side document hashing secret.
     */
    private static function secret(): string
    {
        $secret = trim(
            (string) (
                $_ENV['DOCUMENT_HASH_SECRET']
                ?? ''
            )
        );

        if ($secret === '') {
            throw new RuntimeException(
                'DOCUMENT_HASH_SECRET is not configured.'
            );
        }

        if (strlen($secret) < 32) {
            throw new RuntimeException(
                'DOCUMENT_HASH_SECRET must contain at least 32 characters.'
            );
        }

        return $secret;
    }

    private function __construct()
    {
    }
}
