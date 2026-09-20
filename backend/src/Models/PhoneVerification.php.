<?php

declare(strict_types=1);

namespace ScanId\Models;

use PDO;
use RuntimeException;
use ScanId\Config\Database;

final class PhoneVerification
{
    /**
     * Create a new phone verification OTP.
     *
     * The plain OTP is returned once for the SMS service.
     * Only the password hash is stored in PostgreSQL.
     *
     * @return array{
     *     id: string,
     *     phone: string,
     *     otp: string,
     *     expires_at: string
     * }
     */
    public static function create(
        string $phone,
        ?string $userId = null,
        int $ttlSeconds = 600,
        int $maxAttempts = 5
    ): array {
        $phone = trim($phone);

        if ($phone === '') {
            throw new RuntimeException(
                'Phone number is required.'
            );
        }

        if ($ttlSeconds < 60) {
            throw new RuntimeException(
                'Invalid OTP expiry configuration.'
            );
        }

        if ($maxAttempts < 1) {
            throw new RuntimeException(
                'Invalid OTP attempt configuration.'
            );
        }

        $otp = self::generateOtp();

        $otpHash = password_hash(
            $otp,
            PASSWORD_DEFAULT
        );

        if ($otpHash === false) {
            throw new RuntimeException(
                'Unable to securely create verification code.'
            );
        }

        $database = Database::connection();

        /*
         * Invalidate previous unused OTPs for this phone.
         */
        $invalidate = $database->prepare(
            '
            UPDATE phone_verifications
            SET used_at = NOW()
            WHERE phone = :phone
              AND used_at IS NULL
              AND verified_at IS NULL
            '
        );

        $invalidate->execute([
            ':phone' => $phone,
        ]);

        /*
         * Create the new OTP.
         */
        $statement = $database->prepare(
            '
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
                NOW() + (:ttl * INTERVAL \'1 second\')
            )
            RETURNING
                id,
                phone,
                expires_at
            '
        );

        $statement->execute([
            ':user_id' =>
                $userId !== null && trim($userId) !== ''
                    ? trim($userId)
                    : null,

            ':phone' => $phone,

            ':otp_hash' => $otpHash,

            ':max_attempts' => $maxAttempts,

            ':ttl' => $ttlSeconds,
        ]);

        $verification = $statement->fetch(
            PDO::FETCH_ASSOC
        );

        if (!is_array($verification)) {
            throw new RuntimeException(
                'Unable to create phone verification request.'
            );
        }

        return [
            'id' => (string) $verification['id'],
            'phone' => (string) $verification['phone'],
            'otp' => $otp,
            'expires_at' =>
                (string) $verification['expires_at'],
        ];
    }

    /**
     * Verify an OTP.
     *
     * The verification row is locked during the transaction
     * so the same OTP cannot be successfully consumed twice
     * by concurrent requests.
     *
     * @return array{
     *     id: string,
     *     user_id: string|null,
     *     phone: string,
     *     verified_at: string
     * }|null
     */
    public static function verify(
        string $phone,
        string $otp
    ): ?array {
        $phone = trim($phone);
        $otp = trim($otp);

        if ($phone === '' || $otp === '') {
            return null;
        }

        $database = Database::connection();

        $database->beginTransaction();

        try {
            $statement = $database->prepare(
                '
                SELECT
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
                FOR UPDATE
                '
            );

            $statement->execute([
                ':phone' => $phone,
            ]);

            $verification = $statement->fetch(
                PDO::FETCH_ASSOC
            );

            if (!is_array($verification)) {
                $database->rollBack();

                return null;
            }

            $verificationId =
                (string) $verification['id'];

            $expiresAt = strtotime(
                (string) $verification['expires_at']
            );

            if (
                $expiresAt === false ||
                $expiresAt <= time()
            ) {
                self::markUsed(
                    $database,
                    $verificationId
                );

                $database->commit();

                return null;
            }

            $attempts =
                (int) $verification['attempts'];

            $maxAttempts =
                (int) $verification['max_attempts'];

            if ($attempts >= $maxAttempts) {
                self::markUsed(
                    $database,
                    $verificationId
                );

                $database->commit();

                return null;
            }

            $valid = password_verify(
                $otp,
                (string) $verification['otp_hash']
            );

            if (!$valid) {
                self::incrementAttempts(
                    $database,
                    $verificationId
                );

                $database->commit();

                return null;
            }

            $verified = self::markVerified(
                $database,
                $verificationId
            );

            if (!$verified) {
                $database->rollBack();

                return null;
            }

            $userId = $verification['user_id'];

            $database->commit();

            /*
             * Update the user only after the verification
             * transaction has completed successfully.
             */
            if (
                is_string($userId) &&
                $userId !== ''
            ) {
                $userModel = new User($database);

                $userModel->markPhoneVerified(
                    $userId
                );
            }

            return [
                'id' => $verificationId,

                'user_id' =>
                    $userId !== null
                        ? (string) $userId
                        : null,

                'phone' =>
                    (string) $verification['phone'],

                'verified_at' =>
                    date('Y-m-d H:i:s'),
            ];
        } catch (\Throwable $exception) {
            if ($database->inTransaction()) {
                $database->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * Find the latest verification for a phone number.
     *
     * The OTP hash is never returned.
     *
     * @return array<string, mixed>|null
     */
    public static function latest(
        string $phone
    ): ?array {
        $phone = trim($phone);

        if ($phone === '') {
            return null;
        }

        $statement = Database::connection()->prepare(
            '
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
            ORDER BY created_at DESC
            LIMIT 1
            '
        );

        $statement->execute([
            ':phone' => $phone,
        ]);

        $verification = $statement->fetch(
            PDO::FETCH_ASSOC
        );

        return is_array($verification)
            ? $verification
            : null;
    }

    /**
     * Mark a verification as successfully verified.
     */
    private static function markVerified(
        PDO $database,
        string $id
    ): bool {
        $statement = $database->prepare(
            '
            UPDATE phone_verifications
            SET verified_at = NOW()
            WHERE id = :id
              AND verified_at IS NULL
              AND used_at IS NULL
            RETURNING id
            '
        );

        $statement->execute([
            ':id' => $id,
        ]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * Mark a verification as consumed.
     */
    private static function markUsed(
        PDO $database,
        string $id
    ): bool {
        $statement = $database->prepare(
            '
            UPDATE phone_verifications
            SET used_at = NOW()
            WHERE id = :id
              AND used_at IS NULL
            RETURNING id
            '
        );

        $statement->execute([
            ':id' => $id,
        ]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * Increase failed-attempt counter.
     */
    private static function incrementAttempts(
        PDO $database,
        string $id
    ): void {
        $statement = $database->prepare(
            '
            UPDATE phone_verifications
            SET attempts = attempts + 1
            WHERE id = :id
              AND used_at IS NULL
              AND verified_at IS NULL
            '
        );

        $statement->execute([
            ':id' => $id,
        ]);
    }

    /**
     * Generate a cryptographically secure six-digit OTP.
     */
    private static function generateOtp(): string
    {
        return str_pad(
            (string) random_int(0, 999999),
            6,
            '0',
            STR_PAD_LEFT
        );
    }

    private function __construct()
    {
    }
}
