<?php

declare(strict_types=1);

namespace ScanId\Models;

use PDO;
use RuntimeException;

final class RecoveryToken
{
    public function __construct(
        private readonly PDO $database
    ) {
    }

    /**
     * Create a secure recovery token.
     *
     * The plain token is returned once to the caller.
     * Only its SHA-256 hash is stored in the database.
     */
    public function create(
        string $recoveryRequestId,
        string $tokenType = 'recovery',
        int $expiresInSeconds = 1800
    ): array {
        $recoveryRequestId = trim($recoveryRequestId);
        $tokenType = trim($tokenType);

        if ($recoveryRequestId === '') {
            throw new RuntimeException(
                'Recovery request ID is required.'
            );
        }

        if (!in_array(
            $tokenType,
            ['recovery', 'handover'],
            true
        )) {
            throw new RuntimeException(
                'Invalid recovery token type.'
            );
        }

        if ($expiresInSeconds < 300) {
            throw new RuntimeException(
                'Token expiry must be at least 5 minutes.'
            );
        }

        $this->assertRecoveryRequestExists(
            $recoveryRequestId
        );

        /*
         * Generate a high-entropy URL-safe token.
         * 32 random bytes = 256 bits of entropy.
         */
        $plainToken = rtrim(
            strtr(
                base64_encode(
                    random_bytes(32)
                ),
                '+/',
                '-_'
            ),
            '='
        );

        $tokenHash = hash(
            'sha256',
            $plainToken
        );

        $statement = $this->database->prepare(
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
                NOW() + (
                    :expires_in_seconds
                    * INTERVAL '1 second'
                )
            )
            RETURNING
                id,
                recovery_request_id,
                token_type,
                expires_at,
                created_at
            SQL
        );

        $statement->execute([
            'recovery_request_id' => $recoveryRequestId,
            'token_hash' => $tokenHash,
            'token_type' => $tokenType,
            'expires_in_seconds' => $expiresInSeconds,
        ]);

        $token = $statement->fetch();

        if (!is_array($token)) {
            throw new RuntimeException(
                'Unable to create recovery token.'
            );
        }

        /*
         * The plain token is intentionally returned only here.
         * It must never be stored in the database.
         */
        $token['token'] = $plainToken;

        return $token;
    }

    /**
     * Find a valid unused token using the plain token.
     *
     * The database only receives the SHA-256 hash.
     */
    public function findValid(
        string $plainToken,
        string $tokenType = 'recovery'
    ): ?array {
        $plainToken = trim($plainToken);
        $tokenType = trim($tokenType);

        if ($plainToken === '') {
            return null;
        }

        if (!in_array(
            $tokenType,
            ['recovery', 'handover'],
            true
        )) {
            return null;
        }

        $tokenHash = hash(
            'sha256',
            $plainToken
        );

        $statement = $this->database->prepare(
            <<<'SQL'
            SELECT
                id,
                recovery_request_id,
                token_type,
                expires_at,
                used_at,
                created_at
            FROM recovery_tokens
            WHERE token_hash = :token_hash
              AND token_type = :token_type
              AND used_at IS NULL
              AND expires_at > NOW()
            LIMIT 1
            SQL
        );

        $statement->execute([
            'token_hash' => $tokenHash,
            'token_type' => $tokenType,
        ]);

        $token = $statement->fetch();

        return is_array($token)
            ? $token
            : null;
    }

    /**
     * Consume a token.
     *
     * This operation is protected by a transaction and row lock
     * so the same token cannot be consumed twice concurrently.
     */
    public function consume(
        string $plainToken,
        string $tokenType = 'recovery'
    ): ?array {
        $plainToken = trim($plainToken);
        $tokenType = trim($tokenType);

        if ($plainToken === '') {
            return null;
        }

        if (!in_array(
            $tokenType,
            ['recovery', 'handover'],
            true
        )) {
            return null;
        }

        $tokenHash = hash(
            'sha256',
            $plainToken
        );

        $this->database->beginTransaction();

        try {
            $statement = $this->database->prepare(
                <<<'SQL'
                SELECT
                    id,
                    recovery_request_id,
                    token_type,
                    expires_at,
                    used_at,
                    created_at
                FROM recovery_tokens
                WHERE token_hash = :token_hash
                  AND token_type = :token_type
                  AND used_at IS NULL
                  AND expires_at > NOW()
                LIMIT 1
                FOR UPDATE
                SQL
            );

            $statement->execute([
                'token_hash' => $tokenHash,
                'token_type' => $tokenType,
            ]);

            $token = $statement->fetch();

            if (!is_array($token)) {
                $this->database->rollBack();

                return null;
            }

            $update = $this->database->prepare(
                <<<'SQL'
                UPDATE recovery_tokens
                SET used_at = NOW()
                WHERE id = :id
                  AND used_at IS NULL
                SQL
            );

            $update->execute([
                'id' => $token['id'],
            ]);

            if ($update->rowCount() !== 1) {
                $this->database->rollBack();

                return null;
            }

            $this->database->commit();

            $token['used_at'] = date(
                DATE_ATOM
            );

            return $token;
        } catch (\Throwable $exception) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }

            throw new RuntimeException(
                'Unable to consume recovery token.',
                0,
                $exception
            );
        }
    }

    /**
     * Revoke an unused token.
     */
    public function revoke(
        string $plainToken
    ): bool {
        $plainToken = trim($plainToken);

        if ($plainToken === '') {
            return false;
        }

        $tokenHash = hash(
            'sha256',
            $plainToken
        );

        $statement = $this->database->prepare(
            <<<'SQL'
            UPDATE recovery_tokens
            SET used_at = NOW()
            WHERE token_hash = :token_hash
              AND used_at IS NULL
            SQL
        );

        $statement->execute([
            'token_hash' => $tokenHash,
        ]);

        return $statement->rowCount() > 0;
    }

    /**
     * Confirm that the recovery request exists.
     */
    private function assertRecoveryRequestExists(
        string $id
    ): void {
        $statement = $this->database->prepare(
            <<<'SQL'
            SELECT 1
            FROM recovery_requests
            WHERE id = :id
            LIMIT 1
            SQL
        );

        $statement->execute([
            'id' => $id,
        ]);

        if ($statement->fetchColumn() === false) {
            throw new RuntimeException(
                'Recovery request was not found.'
            );
        }
    }
}
