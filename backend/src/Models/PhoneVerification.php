<?php

declare(strict_types=1);

namespace ScanId\Models;

use PDO;
use RuntimeException;
use Throwable;

final class PhoneVerification
{
    public function __construct(
        private readonly PDO $db
    ) {
    }

    /**
     * Create a new OTP verification record.
     *
     * The plaintext OTP is returned once so the SMS service can send it.
     * Only the hash is stored in the database.
     */
    public function create(
        string $phone,
        ?string $userId = null,
        int $ttlSeconds = 600,
        int $maxAttempts = 5
    ): array {
        $phone = $this->normalizePhone($phone);

        if ($phone === '') {
            throw new RuntimeException('A valid phone number is required.');
        }

        if ($ttlSeconds < 60) {
            throw new RuntimeException(
                'OTP expiry must be at least 60 seconds.'
            );
        }

        if ($maxAttempts < 1) {
            throw new RuntimeException(
                'Maximum OTP attempts must be at least 1.'
            );
        }

        if ($userId !== null && !$this->userExists($userId)) {
            throw new RuntimeException('User not found.');
        }

        $otp = str_pad(
            (string) random_int(0, 999999),
            6,
            '0',
            STR_PAD_LEFT
        );

        $otpHash = password_hash($otp, PASSWORD_DEFAULT);

        if ($otpHash === false) {
            throw new RuntimeException('Unable to secure verification code.');
        }

        $expiresAt = (new \DateTimeImmutable())
            ->modify("+{$ttlSeconds} seconds")
            ->format('Y-m-d H:i:sP');

        $this->db->beginTransaction();

        try {
            // Invalidate previous unused OTPs for this phone.
            $invalidate = $this->db->prepare(
                'UPDATE phone_verifications
                 SET used_at = CURRENT_TIMESTAMP
                 WHERE phone = :phone
                   AND used_at IS NULL
                   AND verified_at IS NULL'
            );

            $invalidate->execute([
                'phone' => $phone,
            ]);

            $statement = $this->db->prepare(
                'INSERT INTO phone_verifications (
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
                 RETURNING id, phone, expires_at, created_at'
            );

            $statement->execute([
                'user_id' => $userId,
                'phone' => $phone,
                'otp_hash' => $otpHash,
                'max_attempts' => $maxAttempts,
                'expires_at' => $expiresAt,
            ]);

            $verification = $statement->fetch(PDO::FETCH_ASSOC);

            if ($verification === false) {
                throw new RuntimeException(
                    'Unable to create phone verification.'
                );
            }

            $this->db->commit();

            return [
                'id' => (string) $verification['id'],
                'phone' => (string) $verification['phone'],
                'otp' => $otp,
                'expires_at' => (string) $verification['expires_at'],
                'created_at' => (string) $verification['created_at'],
            ];
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * Verify the latest active OTP for a phone number.
     */
    public function verify(
        string $phone,
        string $otp
    ): bool {
        $phone = $this->normalizePhone($phone);
        $otp = trim($otp);

        if ($phone === '') {
            return false;
        }

        if (!preg_match('/^\d{6}$/', $otp)) {
            return false;
        }

        $this->db->beginTransaction();

        try {
            $statement = $this->db->prepare(
                'SELECT
                    id,
                    user_id,
                    phone,
                    otp_hash,
                    attempts,
                    max_attempts,
                    expires_at
                 FROM phone_verifications
                 WHERE phone = :phone
                   AND used_at IS NULL
                   AND verified_at IS NULL
                 ORDER BY created_at DESC
                 LIMIT 1
                 FOR UPDATE'
            );

            $statement->execute([
                'phone' => $phone,
            ]);

            $verification = $statement->fetch(PDO::FETCH_ASSOC);

            if ($verification === false) {
                $this->db->rollBack();
                return false;
            }

            $attempts = (int) $verification['attempts'];
            $maxAttempts = (int) $verification['max_attempts'];

            if ($attempts >= $maxAttempts) {
                $this->db->commit();
                return false;
            }

            $expiresAt = new \DateTimeImmutable(
                (string) $verification['expires_at']
            );

            if ($expiresAt <= new \DateTimeImmutable()) {
                $expire = $this->db->prepare(
                    'UPDATE phone_verifications
                     SET used_at = CURRENT_TIMESTAMP
                     WHERE id = :id'
                );

                $expire->execute([
                    'id' => $verification['id'],
                ]);

                $this->db->commit();
                return false;
            }

            $valid = password_verify(
                $otp,
                (string) $verification['otp_hash']
            );

            if (!$valid) {
                $increment = $this->db->prepare(
                    'UPDATE phone_verifications
                     SET attempts = attempts + 1
                     WHERE id = :id'
                );

                $increment->execute([
                    'id' => $verification['id'],
                ]);

                $this->db->commit();
                return false;
            }

            $update = $this->db->prepare(
                'UPDATE phone_verifications
                 SET verified_at = CURRENT_TIMESTAMP,
                     used_at = CURRENT_TIMESTAMP
                 WHERE id = :id'
            );

            $update->execute([
                'id' => $verification['id'],
            ]);

            if ($verification['user_id'] !== null) {
                $userUpdate = $this->db->prepare(
                    'UPDATE users
                     SET phone_verified_at = COALESCE(
                         phone_verified_at,
                         CURRENT_TIMESTAMP
                     ),
                     updated_at = CURRENT_TIMESTAMP
                     WHERE id = :user_id'
                );

                $userUpdate->execute([
                    'user_id' => $verification['user_id'],
                ]);
            }

            $this->db->commit();

            return true;
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * Return the latest active verification for a phone.
     *
     * OTP hash is deliberately excluded.
     */
    public function latest(string $phone): ?array
    {
        $phone = $this->normalizePhone($phone);

        if ($phone === '') {
            return null;
        }

        $statement = $this->db->prepare(
            'SELECT
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
             ORDER BY created_at DESC
             LIMIT 1'
        );

        $statement->execute([
            'phone' => $phone,
        ]);

        $verification = $statement->fetch(PDO::FETCH_ASSOC);

        return $verification !== false
            ? $verification
            : null;
    }

    /**
     * Create an OTP specifically for an existing user.
     */
    public function createForUser(
        string $userId,
        string $phone,
        int $ttlSeconds = 600,
        int $maxAttempts = 5
    ): array {
        return $this->create(
            $phone,
            $userId,
            $ttlSeconds,
            $maxAttempts
        );
    }

    /**
     * Normalize Kenyan phone numbers.
     *
     * Examples:
     * 0712345678 -> +254712345678
     * 712345678 -> +254712345678
     * 254712345678 -> +254712345678
     */
    private function normalizePhone(string $phone): string
    {
        $phone = trim($phone);

        if ($phone === '') {
            return '';
        }

        $phone = preg_replace('/[\s\-\(\)]/', '', $phone) ?? '';

        if (str_starts_with($phone, '00')) {
            $phone = '+' . substr($phone, 2);
        }

        if (str_starts_with($phone, '0')) {
            $phone = '+254' . substr($phone, 1);
        } elseif (str_starts_with($phone, '254')) {
            $phone = '+' . $phone;
        }

        if (!preg_match('/^\+2547\d{8}$/', $phone)) {
            throw new RuntimeException(
                'Invalid Kenyan phone number.'
            );
        }

        return $phone;
    }

    private function userExists(string $userId): bool
    {
        $statement = $this->db->prepare(
            'SELECT 1
             FROM users
             WHERE id = :id
             LIMIT 1'
        );

        $statement->execute([
            'id' => $userId,
        ]);

        return $statement->fetchColumn() !== false;
    }
}
