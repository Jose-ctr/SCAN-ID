<?php

declare(strict_types=1);

namespace ScanId\Models;

use PDO;
use RuntimeException;

final class PhoneVerification
{
    private const OTP_LENGTH = 6;
    private const DEFAULT_TTL_SECONDS = 600;
    private const DEFAULT_MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly PDO $db
    ) {
    }

    /**
     * Create a new phone verification OTP.
     *
     * The raw OTP is returned once to the caller so the SMS service
     * can send it. Only a password hash of the OTP is stored.
     *
     * @return array{
     *     id: string,
     *     phone: string,
     *     otp: string,
     *     expires_at: string,
     *     max_attempts: int
     * }
     */
    public function create(
        string $phone,
        ?string $userId = null,
        int $ttlSeconds = self::DEFAULT_TTL_SECONDS,
        int $maxAttempts = self::DEFAULT_MAX_ATTEMPTS
    ): array {
        $phone = $this->normalizePhone($phone);

        if ($ttlSeconds < 60) {
            throw new RuntimeException(
                'OTP expiration must be at least 60 seconds.'
            );
        }

        if ($maxAttempts < 1 || $maxAttempts > 10) {
            throw new RuntimeException(
                'Invalid OTP attempt limit.'
            );
        }

        if ($userId !== null) {
            $this->assertUserExists($userId);
        }

        $this->invalidateActiveVerifications($phone);

        $otp = $this->generateOtp();

        $otpHash = password_hash(
            $otp,
            PASSWORD_DEFAULT
        );

        if ($otpHash === false) {
            throw new RuntimeException(
                'Failed to secure verification code.'
            );
        }

        $expiresAt = new \DateTimeImmutable(
            '+' . $ttlSeconds . ' seconds'
        );

        $statement = $this->db->prepare(
            <<<'SQL'
                INSERT INTO phone_verifications (
                    user_id,
                    phone,
                    otp_hash,
                    attempts,
                    max_attempts,
                    expires_at
                )
                VALUES (
                    :user_id,
                    :phone,
                    :otp_hash,
                    0,
                    :max_attempts,
                    :expires_at
                )
                RETURNING
                    id,
                    phone,
                    expires_at,
                    max_attempts,
                    created_at
            SQL
        );

        $statement->execute([
            'user_id' => $userId,
            'phone' => $phone,
            'otp_hash' => $otpHash,
            'max_attempts' => $maxAttempts,
            'expires_at' => $expiresAt->format(
                'Y-m-d H:i:sP'
            ),
        ]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            throw new RuntimeException(
                'Failed to create phone verification.'
            );
        }

        return [
            'id' => (string) $row['id'],
            'phone' => (string) $row['phone'],
            'otp' => $otp,
            'expires_at' => (string) $row['expires_at'],
            'max_attempts' => (int) $row['max_attempts'],
        ];
    }

    /**
     * Create a verification for an existing user.
     */
    public function createForUser(
        string $userId,
        int $ttlSeconds = self::DEFAULT_TTL_SECONDS,
        int $maxAttempts = self::DEFAULT_MAX_ATTEMPTS
    ): array {
        $statement = $this->db->prepare(
            <<<'SQL'
                SELECT phone
                FROM users
                WHERE id = :id
                  AND is_active = TRUE
                LIMIT 1
            SQL
        );

        $statement->execute([
            'id' => $userId,
        ]);

        $phone = $statement->fetchColumn();

        if ($phone === false || $phone === null) {
            throw new RuntimeException(
                'Active user phone number not found.'
            );
        }

        return $this->create(
            (string) $phone,
            $userId,
            $ttlSeconds,
            $maxAttempts
        );
    }

    /**
     * Verify an OTP.
     *
     * Uses a database transaction and row locking to prevent
     * concurrent verification attempts from bypassing limits.
     */
    public function verify(
        string $phone,
        string $otp
    ): bool {
        $phone = $this->normalizePhone($phone);
        $otp = trim($otp);

        if (!preg_match(
            '/^\d{6}$/',
            $otp
        )) {
            return false;
        }

        $this->db->beginTransaction();

        try {
            $statement = $this->db->prepare(
                <<<'SQL'
                    SELECT
                        id,
                        user_id,
                        otp_hash,
                        attempts,
                        max_attempts,
                        expires_at
                    FROM phone_verifications
                    WHERE phone = :phone
                      AND verified_at IS NULL
                      AND used_at IS NULL
                    ORDER BY created_at DESC
                    LIMIT 1
                    FOR UPDATE
                SQL
            );

            $statement->execute([
                'phone' => $phone,
            ]);

            $verification = $statement->fetch(
                PDO::FETCH_ASSOC
            );

            if ($verification === false) {
                $this->db->commit();

                return false;
            }

            if (
                strtotime(
                    (string) $verification['expires_at']
                ) <= time()
            ) {
                $this->markExpired(
                    (string) $verification['id']
                );

                $this->db->commit();

                return false;
            }

            $attempts = (int) $verification['attempts'];
            $maxAttempts = (int) $verification['max_attempts'];

            if ($attempts >= $maxAttempts) {
                $this->db->commit();

                return false;
            }

            $passwordMatches = password_verify(
                $otp,
                (string) $verification['otp_hash']
            );

            if (!$passwordMatches) {
                $update = $this->db->prepare(
                    <<<'SQL'
                        UPDATE phone_verifications
                        SET attempts = attempts + 1
                        WHERE id = :id
                    SQL
                );

                $update->execute([
                    'id' => $verification['id'],
                ]);

                $this->db->commit();

                return false;
            }

            $update = $this->db->prepare(
                <<<'SQL'
                    UPDATE phone_verifications
                    SET
                        verified_at = NOW(),
                        used_at = NOW()
                    WHERE id = :id
                SQL
            );

            $update->execute([
                'id' => $verification['id'],
            ]);

            if (
                $verification['user_id'] !== null
            ) {
                $userUpdate = $this->db->prepare(
                    <<<'SQL'
                        UPDATE users
                        SET phone_verified_at = COALESCE(
                            phone_verified_at,
                            NOW()
                        )
                        WHERE id = :id
                    SQL
                );

                $userUpdate->execute([
                    'id' => $verification['user_id'],
                ]);
            }

            $this->db->commit();

            return true;
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * Get the latest active verification for a phone.
     *
     * The OTP hash is intentionally excluded.
     */
    public function latest(
        string $phone
    ): ?array {
        $phone = $this->normalizePhone($phone);

        $statement = $this->db->prepare(
            <<<'SQL'
                SELECT
                    id,
                    user_id,
                    phone,
                    attempts,
                    max_attempts,
                    expires_at,
                    verified_at,
                    used_at,
                    created_at
                FROM phone_verifications
                WHERE phone = :phone
                  AND verified_at IS NULL
                  AND used_at IS NULL
                ORDER BY created_at DESC
                LIMIT 1
            SQL
        );

        $statement->execute([
            'phone' => $phone,
        ]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * Invalidate previous active OTPs for a phone.
     */
    private function invalidateActiveVerifications(
        string $phone
    ): void {
        $statement = $this->db->prepare(
            <<<'SQL'
                UPDATE phone_verifications
                SET used_at = NOW()
                WHERE phone = :phone
                  AND verified_at IS NULL
                  AND used_at IS NULL
            SQL
        );

        $statement->execute([
            'phone' => $phone,
        ]);
    }

    /**
     * Mark an expired verification as used.
     */
    private function markExpired(
        string $id
    ): void {
        $statement = $this->db->prepare(
            <<<'SQL'
                UPDATE phone_verifications
                SET used_at = COALESCE(
                    used_at,
                    NOW()
                )
                WHERE id = :id
            SQL
        );

        $statement->execute([
            'id' => $id,
        ]);
    }

    /**
     * Generate a cryptographically secure six-digit OTP.
     */
    private function generateOtp(): string
    {
        $minimum = 10 ** (self::OTP_LENGTH - 1);
        $maximum = (10 ** self::OTP_LENGTH) - 1;

        return str_pad(
            (string) random_int(
                $minimum,
                $maximum
            ),
            self::OTP_LENGTH,
            '0',
            STR_PAD_LEFT
        );
    }

    /**
     * Ensure the user exists and is active.
     */
    private function assertUserExists(
        string $userId
    ): void {
        $statement = $this->db->prepare(
            <<<'SQL'
                SELECT 1
                FROM users
                WHERE id = :id
                  AND is_active = TRUE
                LIMIT 1
            SQL
        );

        $statement->execute([
            'id' => $userId,
        ]);

        if ($statement->fetchColumn() === false) {
            throw new RuntimeException(
                'User not found or inactive.'
            );
        }
    }

    /**
     * Normalize Kenyan phone numbers.
     */
    private function normalizePhone(
        string $phone
    ): string {
        $phone = preg_replace(
            '/[\s().-]+/',
            '',
            trim($phone)
        );

        if ($phone === null || $phone === '') {
            throw new RuntimeException(
                'Phone number is required.'
            );
        }

        if (str_starts_with($phone, '+254')) {
            $normalized = $phone;
        } elseif (str_starts_with($phone, '254')) {
            $normalized = '+' . $phone;
        } elseif (
            str_starts_with($phone, '07') ||
            str_starts_with($phone, '01')
        ) {
            $normalized = '+254' . substr($phone, 1);
        } else {
            throw new RuntimeException(
                'Invalid Kenyan phone number.'
            );
        }

        if (!preg_match(
            '/^\+254(?:7|1)\d{8}$/',
            $normalized
        )) {
            throw new RuntimeException(
                'Invalid Kenyan phone number.'
            );
        }

        return $normalized;
    }
}
