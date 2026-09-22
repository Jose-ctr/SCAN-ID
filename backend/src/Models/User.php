<?php

declare(strict_types=1);

namespace ScanId\Models;

use PDO;
use RuntimeException;

final class User
{
    public function __construct(
        private readonly PDO $db
    ) {
    }

    /**
     * Find a user by ID.
     *
     * Password hashes are intentionally excluded.
     */
    public function findById(string $id): ?array
    {
        $statement = $this->db->prepare(
            <<<'SQL'
                SELECT
                    id,
                    full_name,
                    phone,
                    email,
                    role,
                    phone_verified_at,
                    is_active,
                    created_at,
                    updated_at
                FROM users
                WHERE id = :id
                LIMIT 1
            SQL
        );

        $statement->execute([
            'id' => $id,
        ]);

        $user = $statement->fetch(PDO::FETCH_ASSOC);

        return $user !== false ? $user : null;
    }

    /**
     * Find a user by phone for authentication.
     *
     * The password hash is included because AuthService
     * needs it to verify the supplied password.
     */
    public function findByPhone(
        string $phone
    ): ?array {
        $phone = $this->normalizePhone($phone);

        $statement = $this->db->prepare(
            <<<'SQL'
                SELECT
                    id,
                    full_name,
                    phone,
                    email,
                    password_hash,
                    role,
                    phone_verified_at,
                    is_active,
                    created_at,
                    updated_at
                FROM users
                WHERE phone = :phone
                LIMIT 1
            SQL
        );

        $statement->execute([
            'phone' => $phone,
        ]);

        $user = $statement->fetch(PDO::FETCH_ASSOC);

        return $user !== false ? $user : null;
    }

    /**
     * Find a user by email.
     *
     * Password hashes are intentionally excluded.
     */
    public function findByEmail(
        string $email
    ): ?array {
        $email = strtolower(trim($email));

        if (
            $email === '' ||
            !filter_var($email, FILTER_VALIDATE_EMAIL)
        ) {
            return null;
        }

        $statement = $this->db->prepare(
            <<<'SQL'
                SELECT
                    id,
                    full_name,
                    phone,
                    email,
                    role,
                    phone_verified_at,
                    is_active,
                    created_at,
                    updated_at
                FROM users
                WHERE LOWER(email) = :email
                LIMIT 1
            SQL
        );

        $statement->execute([
            'email' => $email,
        ]);

        $user = $statement->fetch(PDO::FETCH_ASSOC);

        return $user !== false ? $user : null;
    }

    /**
     * Create a new user.
     */
    public function create(
        string $fullName,
        string $phone,
        ?string $email,
        string $password
    ): array {
        $fullName = trim($fullName);
        $phone = $this->normalizePhone($phone);

        if ($fullName === '') {
            throw new RuntimeException(
                'Full name is required.'
            );
        }

        if (mb_strlen($fullName) > 150) {
            throw new RuntimeException(
                'Full name is too long.'
            );
        }

        if (
            $email !== null &&
            trim($email) !== ''
        ) {
            $email = strtolower(trim($email));

            if (!filter_var(
                $email,
                FILTER_VALIDATE_EMAIL
            )) {
                throw new RuntimeException(
                    'Invalid email address.'
                );
            }
        } else {
            $email = null;
        }

        if (mb_strlen($password) < 8) {
            throw new RuntimeException(
                'Password must be at least 8 characters.'
            );
        }

        $passwordHash = password_hash(
            $password,
            PASSWORD_DEFAULT
        );

        if ($passwordHash === false) {
            throw new RuntimeException(
                'Failed to secure password.'
            );
        }

        $statement = $this->db->prepare(
            <<<'SQL'
                INSERT INTO users (
                    full_name,
                    phone,
                    email,
                    password_hash,
                    role,
                    is_active
                )
                VALUES (
                    :full_name,
                    :phone,
                    :email,
                    :password_hash,
                    'user',
                    TRUE
                )
                RETURNING
                    id,
                    full_name,
                    phone,
                    email,
                    role,
                    phone_verified_at,
                    is_active,
                    created_at,
                    updated_at
            SQL
        );

        try {
            $statement->execute([
                'full_name' => $fullName,
                'phone' => $phone,
                'email' => $email,
                'password_hash' => $passwordHash,
            ]);
        } catch (\PDOException $exception) {
            if ($exception->getCode() === '23505') {
                throw new RuntimeException(
                    'A user with this phone or email already exists.',
                    0,
                    $exception
                );
            }

            throw $exception;
        }

        $user = $statement->fetch(PDO::FETCH_ASSOC);

        if ($user === false) {
            throw new RuntimeException(
                'Failed to create user.'
            );
        }

        return $user;
    }

    /**
     * Verify a supplied password against a stored hash.
     */
    public function verifyPassword(
        string $password,
        string $passwordHash
    ): bool {
        if ($password === '' || $passwordHash === '') {
            return false;
        }

        return password_verify(
            $password,
            $passwordHash
        );
    }

    /**
     * Mark the user's phone as verified.
     */
    public function markPhoneVerified(
        string $id
    ): bool {
        $statement = $this->db->prepare(
            <<<'SQL'
                UPDATE users
                SET phone_verified_at = COALESCE(
                    phone_verified_at,
                    NOW()
                )
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
     * Check whether a user's phone is verified.
     */
    public function isPhoneVerified(
        string $id
    ): bool {
        $statement = $this->db->prepare(
            <<<'SQL'
                SELECT phone_verified_at
                FROM users
                WHERE id = :id
                LIMIT 1
            SQL
        );

        $statement->execute([
            'id' => $id,
        ]);

        $value = $statement->fetchColumn();

        return $value !== false && $value !== null;
    }

    /**
     * Activate a user account.
     */
    public function activate(
        string $id
    ): bool {
        return $this->setActive($id, true);
    }

    /**
     * Deactivate a user account.
     */
    public function deactivate(
        string $id
    ): bool {
        return $this->setActive($id, false);
    }

    /**
     * Return only safe public/authenticated user data.
     */
    public function publicData(
        array $user
    ): array {
        unset(
            $user['password_hash'],
            $user['password']
        );

        return $user;
    }

    /**
     * Set account active state.
     */
    private function setActive(
        string $id,
        bool $active
    ): bool {
        $statement = $this->db->prepare(
            <<<'SQL'
                UPDATE users
                SET is_active = :is_active
                WHERE id = :id
                RETURNING id
            SQL
        );

        $statement->execute([
            'id' => $id,
            'is_active' => $active,
        ]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * Normalize Kenyan phone numbers to +254 format.
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
