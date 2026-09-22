<?php

declare(strict_types=1);

namespace ScanId\Models;

use PDO;
use RuntimeException;

final class PhoneVerification
{
    private PDO $database;

    public function __construct(PDO $database)
    {
        $this->database = $database;
    }

    /**
     * Create a new phone verification challenge.
     *
     * @return array<string, mixed>
     */
    public function create(
        string $phone,
        ?string $userId = null,
        int $ttlSeconds = 600,
        int $maxAttempts = 5
    ): array {
        $phone = self::normalizePhone($phone);

        if ($ttlSeconds < 60) {
            throw new RuntimeException(
                'Verification expiry must be at least 60 seconds.'
            );
        }

        if ($maxAttempts < 1) {
            throw new RuntimeException(
                'Maximum verification attempts must be at least 1.'
            );
        }

        if ($userId !== null) {
            $userId = trim($userId);

            if ($userId === '') {
                $userId = null;
            }
        }

        /*
         * Invalidate previous active challenges for this phone.
         */
        $invalidate = $this->database->prepare(
            'UPDATE phone_verifications
             SET used_at = NOW()
             WHERE phone = :phone
               AND verified_at IS NULL
               AND used_at IS NULL
               AND expires_at > NOW()'
        );

        $invalidate->execute([
            'phone' => $phone,
        ]);

        /*
         * Generate a cryptographically secure six-digit OTP.
         */
        $code = str_pad(
            (string) random_int(0, 999999),
            6,
            '0',
            STR_PAD_LEFT
        );

        $otpHash = password_hash(
            $code,
            PASSWORD_DEFAULT
        );

        if ($otpHash === false) {
            throw new RuntimeException(
                'Unable to securely generate verification code.'
            );
        }

        $statement = $this->database->prepare(
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
                NOW() + (:ttl_seconds * INTERVAL \'1 second\')
             )
             RETURNING
                id,
                user_id,
                phone,
                attempts,
                max_attempts,
                expires_at,
                created_at'
        );

        $statement->execute([
            'user_id' => $userId,
            'phone' => $phone,
            'otp_hash' => $otpHash,
            'max_attempts' => $maxAttempts,
            'ttl_seconds' => $ttlSeconds,
        ]);

        $verification = $statement->fetch(
            PDO::FETCH_ASSOC
        );

        if (!is_array($verification)) {
            throw new RuntimeException(
                'Unable to create phone verification challenge.'
            );
        }

        /*
         * The raw OTP is returned only to the service layer so it
         * can be sent through the configured SMS provider.
         * It is never stored in plaintext.
         */
        $verification['code'] = $code;

        return $verification;
    }

    /**
     * Create a verification challenge for a specific user.
     *
     * @return array<string, mixed>
     */
    public function createForUser(
        string $userId,
        int $ttlSeconds = 600,
        int $maxAttempts = 5
    ): array {
        $userId = trim($userId);

        if ($userId === '') {
            throw new RuntimeException(
                'User ID is required.'
            );
        }

        $statement = $this->database->prepare(
            'SELECT phone
             FROM users
             WHERE id = :id
               AND is_active = TRUE
             LIMIT 1'
        );

        $statement->execute([
            'id' => $userId,
        ]);

        $phone = $statement->fetchColumn();

        if (!is_string($phone) || $phone === '') {
            throw new RuntimeException(
                'Active user with a phone number was not found.'
            );
        }

        return $this->create(
            $phone,
            $userId,
            $ttlSeconds,
            $maxAttempts
        );
    }

    /**
     * Verify an OTP.
     *
     * The operation is transactional and locks the active
     * verification row to prevent concurrent verification races.
     *
     * @return array<string, mixed>|null
     */
    public function verify(
        string $phone,
        string $code
    ): ?array {
        $phone = self::normalizePhone($phone);
        $code = trim($code);

        if (!preg_match('/^\d{6}$/', $code)) {
            return null;
        }

        $this->database->beginTransaction();

        try {
            $statement = $this->database->prepare(
                'SELECT
                    id,
                    user_id,
                    phone,
                    otp_hash,
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
                 FOR UPDATE'
            );

            $statement->execute([
                'phone' => $phone,
            ]);

            $verification = $statement->fetch(
                PDO::FETCH_ASSOC
            );

            if (!is_array($verification)) {
                $this->database->rollBack();

                return null;
            }

            if (
                strtotime(
                    (string) $verification['expires_at']
                ) <= time()
            ) {
                $expire = $this->database->prepare(
                    'UPDATE phone_verifications
                     SET used_at = NOW()
                     WHERE id = :id
                       AND used_at IS NULL'
                );

                $expire->execute([
                    'id' => $verification['id'],
                ]);

                $this->database->commit();

                return null;
            }

            $attempts = (int) $verification['attempts'];
            $maxAttempts = (int) $verification['max_attempts'];

            if ($attempts >= $maxAttempts) {
                $this->database->rollBack();

                return null;
            }

            /*
             * Count every verification attempt, including failures.
             */
            $attempt = $this->database->prepare(
                'UPDATE phone_verifications
                 SET attempts = attempts + 1
                 WHERE id = :id'
            );

            $attempt->execute([
                'id' => $verification['id'],
            ]);

            if (
                !password_verify(
                    $code,
                    (string) $verification['otp_hash']
                )
            ) {
                /*
                 * If this was the final permitted attempt,
                 * invalidate the challenge immediately.
                 */
                if (($attempts + 1) >= $maxAttempts) {
                    $invalidate = $this->database->prepare(
                        'UPDATE phone_verifications
                         SET used_at = NOW()
                         WHERE id = :id
                           AND used_at IS NULL'
                    );

                    $invalidate->execute([
                        'id' => $verification['id'],
                    ]);
                }

                $this->database->commit();

                return null;
            }

            /*
             * Mark the OTP as both verified and consumed.
             */
            $verified = $this->database->prepare(
                'UPDATE phone_verifications
                 SET
                    verified_at = NOW(),
                    used_at = NOW()
                 WHERE id = :id
                   AND verified_at IS NULL
                   AND used_at IS NULL'
            );

            $verified->execute([
                'id' => $verification['id'],
            ]);

            /*
             * Mark the user's phone as verified when the
             * verification belongs to a registered user.
             */
            if (
                !empty($verification['user_id'])
            ) {
                $user = $this->database->prepare(
                    'UPDATE users
                     SET
                        phone_verified_at = COALESCE(
                            phone_verified_at,
                            NOW()
                        ),
                        updated_at = NOW()
                     WHERE id = :id'
                );

                $user->execute([
                    'id' => $verification['user_id'],
                ]);
            }

            $this->database->commit();

            unset($verification['otp_hash']);

            $verification['verified_at'] =
                date('Y-m-d H:i:s');

            $verification['used_at'] =
                date('Y-m-d H:i:s');

            return $verification;
        } catch (\Throwable $exception) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * Invalidate a verification record.
     *
     * Used when an OTP was generated but SMS delivery failed.
     */
    public function invalidate(string $id): bool
    {
        $id = trim($id);

        if ($id === '') {
            return false;
        }

        $statement = $this->database->prepare(
            'UPDATE phone_verifications
             SET used_at = NOW()
             WHERE id = :id
               AND used_at IS NULL'
        );

        $statement->execute([
            'id' => $id,
        ]);

        return $statement->rowCount() > 0;
    }

    /**
     * Get the latest verification challenge for a phone.
     *
     * OTP hashes are never returned.
     *
     * @return array<string, mixed>|null
     */
    public function latest(
        string $phone
    ): ?array {
        $phone = self::normalizePhone($phone);

        $statement = $this->database->prepare(
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

        $verification = $statement->fetch(
            PDO::FETCH_ASSOC
        );

        return is_array($verification)
            ? $verification
            : null;
    }

    /**
     * Normalize a Kenyan phone number.
     *
     * Supported examples:
     * 0712345678
     * 0112345678
     * 254712345678
     * +254712345678
     */
    public static function normalizePhone(
        string $phone
    ): string {
        $phone = trim($phone);

        if ($phone === '') {
            throw new RuntimeException(
                'Phone number is required.'
            );
        }

        $phone = preg_replace(
            '/[\s().-]+/',
            '',
            $phone
        );

        if ($phone === null) {
            throw new RuntimeException(
                'Invalid phone number.'
            );
        }

        if (str_starts_with($phone, '+254')) {
            $phone = substr($phone, 1);
        }

        if (
            str_starts_with($phone, '07') ||
            str_starts_with($phone, '01')
        ) {
            $phone = '254' . substr($phone, 1);
        }

        if (
            !preg_match(
                '/^254(7\d{8}|1\d{8})$/',
                $phone
            )
        ) {
            throw new RuntimeException(
                'Invalid Kenyan phone number.'
            );
        }

        return $phone;
    }
}
