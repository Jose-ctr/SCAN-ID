<?php

declare(strict_types=1);

namespace ScanId\Models;

use DateTimeImmutable;
use PDO;
use RuntimeException;

final class RecoveryToken
{
    private const TOKEN_BYTES = 32;

    public function __construct(
        private readonly PDO $db
    ) {
    }

    /**
     * Create a secure recovery or handover token.
     *
     * The raw token is returned once to the caller.
     * Only its SHA-256 hash is stored in the database.
     *
     * @return array{
     *     id: string,
     *     token: string,
     *     token_type: string,
     *     expires_at: string
     * }
     */
    public function create(
        string $recoveryRequestId,
        string $tokenType = 'recovery',
        int $expiresInSeconds = 1800
    ): array {
        $this->validateTokenType($tokenType);

        if ($expiresInSeconds < 60) {
            throw new RuntimeException(
                'Recovery token expiration must be at least 60 seconds.'
            );
        }

        $this->assertRecoveryRequestExists($recoveryRequestId);

        $token = bin2hex(random_bytes(self::TOKEN_BYTES));
        $tokenHash = self::hashToken($token);

        $expiresAt = new DateTimeImmutable(
            '+' . $expiresInSeconds . ' seconds'
        );

        $statement = $this->db->prepare(
            <<<'SQL'
                INSERT INTO recovery_tokens (
                    recovery_request_id,
                    token_hash,
                    token_type,
                    expires_at
                )
                VALUES (
                    :recovery_request_id,
                    :token_hash,
                    :token_type,
                    :expires_at
                )
                RETURNING
                    id,
                    token_type,
                    expires_at,
                    created_at
            SQL
        );

        $statement->execute([
            'recovery_request_id' => $recoveryRequestId,
            'token_hash' => $tokenHash,
            'token_type' => $tokenType,
            'expires_at' => $expiresAt->format('Y-m-d H:i:sP'),
        ]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            throw new RuntimeException(
                'Failed to create recovery token.'
            );
        }

        return [
            'id' => (string) $row['id'],
            'token' => $token,
            'token_type' => (string) $row['token_type'],
            'expires_at' => (string) $row['expires_at'],
        ];
    }

    /**
     * Find a valid token using the raw token supplied by the client.
     *
     * Expired and used tokens are never returned.
     */
    public function findValid(
        string $token,
        ?string $tokenType = null
    ): ?array {
        $token = trim($token);

        if ($token === '') {
            return null;
        }

        if ($tokenType !== null) {
            $this->validateTokenType($tokenType);
        }

        $tokenHash = self::hashToken($token);

        $sql = <<<'SQL'
            SELECT
                id,
                recovery_request_id,
                token_type,
                expires_at,
                used_at,
                created_at
            FROM recovery_tokens
            WHERE token_hash = :token_hash
              AND used_at IS NULL
              AND expires_at > NOW()
        SQL;

        $parameters = [
            'token_hash' => $tokenHash,
        ];

        if ($tokenType !== null) {
            $sql .= ' AND token_type = :token_type';
            $parameters['token_type'] = $tokenType;
        }

        $sql .= ' LIMIT 1';

        $statement = $this->db->prepare($sql);
        $statement->execute($parameters);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * Find a token by its database ID.
     */
    public function findById(
        string $id
    ): ?array {
        $statement = $this->db->prepare(
            <<<'SQL'
                SELECT
                    id,
                    recovery_request_id,
                    token_type,
                    expires_at,
                    used_at,
                    created_at
                FROM recovery_tokens
                WHERE id = :id
                LIMIT 1
            SQL
        );

        $statement->execute([
            'id' => $id,
        ]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * Find all tokens for a recovery request.
     */
    public function findByRecoveryRequest(
        string $recoveryRequestId
    ): array {
        $statement = $this->db->prepare(
            <<<'SQL'
                SELECT
                    id,
                    recovery_request_id,
                    token_type,
                    expires_at,
                    used_at,
                    created_at
                FROM recovery_tokens
                WHERE recovery_request_id = :recovery_request_id
                ORDER BY created_at DESC
            SQL
        );

        $statement->execute([
            'recovery_request_id' => $recoveryRequestId,
        ]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Mark a token as used.
     *
     * The operation is idempotent for an already-used token.
     */
    public function markUsed(
        string $id
    ): bool {
        $statement = $this->db->prepare(
            <<<'SQL'
                UPDATE recovery_tokens
                SET used_at = COALESCE(used_at, NOW())
                WHERE id = :id
                RETURNING id
            SQL
        );

        $statement->execute([
            'id' => $id,
        ]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * Mark a raw token as used.
     */
    public function consume(
        string $token,
        ?string $tokenType = null
    ): bool {
        $validToken = $this->findValid($token, $tokenType);

        if ($validToken === null) {
            return false;
        }

        return $this->markUsed((string) $validToken['id']);
    }

    /**
     * Check whether a raw token is valid.
     */
    public function isValid(
        string $token,
        ?string $tokenType = null
    ): bool {
        return $this->findValid($token, $tokenType) !== null;
    }

    /**
     * Delete expired tokens.
     *
     * This is maintenance only. It does not affect active tokens.
     */
    public function deleteExpired(): int
    {
        $statement = $this->db->prepare(
            <<<'SQL'
                DELETE FROM recovery_tokens
                WHERE expires_at <= NOW()
                RETURNING id
            SQL
        );

        $statement->execute();

        return count($statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Hash a raw token for database lookup.
     */
    public static function hashToken(
        string $token
    ): string {
        return hash('sha256', $token);
    }

    /**
     * Validate supported token types.
     */
    private function validateTokenType(
        string $tokenType
    ): void {
        if (!in_array(
            $tokenType,
            ['recovery', 'handover'],
            true
        )) {
            throw new RuntimeException(
                'Invalid recovery token type.'
            );
        }
    }

    /**
     * Ensure the recovery request exists.
     */
    private function assertRecoveryRequestExists(
        string $recoveryRequestId
    ): void {
        $statement = $this->db->prepare(
            <<<'SQL'
                SELECT 1
                FROM recovery_requests
                WHERE id = :id
                LIMIT 1
            SQL
        );

        $statement->execute([
            'id' => $recoveryRequestId,
        ]);

        if ($statement->fetchColumn() === false) {
            throw new RuntimeException(
                'Recovery request not found.'
            );
        }
    }
}
