<?php

declare(strict_types=1);

namespace ScanId\Models;

use PDO;
use PDOException;
use RuntimeException;

final class User
{
    public function __construct(
        private readonly PDO $db
    ) {
    }

    /**
     * Find a user by UUID.
     *
     * Password hash is intentionally excluded.
     */
    public function findById(string $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT
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
             LIMIT 1'
        );

        $stmt->execute([
            'id' => $id,
        ]);

        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        return $user !== false ? $user : null;
    }

    /**
     * Find a user by phone number.
     *
     * Includes password hash for authentication.
     */
    public function findByPhone(string $phone): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT
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
             LIMIT 1'
        );

        $stmt->execute([
            'phone' => trim($phone),
        ]);

        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        return $user !== false ? $user : null;
    }

    /**
     * Find a user by email address.
     *
     * Password hash is intentionally excluded.
     */
    public function findByEmail(string $email): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT
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
             WHERE LOWER(email) = LOWER(:email)
             LIMIT 1'
        );

        $stmt->execute([
            'email' => strtolower(trim($email)),
        ]);

        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        return $user !== false ? $user : null;
    }

    /**
     * Create a new SCAN-ID user.
     */
    public function create(
        string $fullName,
        string $phone,
        string $password,
        ?string $email = null
    ): array {
        $fullName = trim($fullName);
        $phone = trim($phone);
        $password = trim($password);

        if ($fullName === '') {
            throw new RuntimeException(
                'Full name is required.'
            );
        }

        if ($phone === '') {
            throw new RuntimeException(
                'Phone number is required.'
            );
        }

        if ($password === '') {
            throw new RuntimeException(
                'Password is required.'
            );
        }

        if (strlen($password) < 8) {
            throw new RuntimeException(
                'Password must be at least 8 characters.'
            );
        }

        $email = $email !== null
            ? strtolower(trim($email))
            : null;

        if ($email === '') {
            $email = null;
        }

        $passwordHash = password_hash(
            $password,
            PASSWORD_DEFAULT
        );

        if ($passwordHash === false) {
            throw new RuntimeException(
                'Unable to securely create password hash.'
            );
        }

        try {
            $stmt = $this->db->prepare(
                'INSERT INTO users (
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
                    :role,
                    :is_active
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
                    updated_at'
            );

            $stmt->execute([
                'full_name' => $fullName,
                'phone' => $phone,
                'email' => $email,
                'password_hash' => $passwordHash,
                'role' => 'user',
                'is_active' => true,
            ]);

            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user === false) {
                throw new RuntimeException(
                    'User was created but could not be returned.'
                );
            }

            return $user;
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23505') {
                throw new RuntimeException(
                    'A user with this phone number or email already exists.'
                );
            }

            throw $exception;
        }
    }

    /**
     * Find a user specifically for authentication.
     *
     * This is the only normal lookup that returns
     * the password hash.
     */
    public function findForAuthentication(
        string $phone
    ): ?array {
        return $this->findByPhone($phone);
    }

    /**
     * Verify a user's password.
     */
    public function verifyPassword(
        array $user,
        string $password
    ): bool {
        if (
            !isset($user['password_hash']) ||
            $user['password_hash'] === '' ||
            $password === ''
        ) {
            return false;
        }

        return password_verify(
            $password,
            $user['password_hash']
        );
    }

    /**
     * Mark a user's phone number as verified.
     */
    public function markPhoneVerified(
        string $userId
    ): bool {
        $stmt = $this->db->prepare(
            'UPDATE users
             SET phone_verified_at = NOW()
             WHERE id = :id
             RETURNING id'
        );

        $stmt->execute([
            'id' => $userId,
        ]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Check whether a user's phone number is verified.
     */
    public function isPhoneVerified(
        string $userId
    ): bool {
        $stmt = $this->db->prepare(
            'SELECT phone_verified_at
             FROM users
             WHERE id = :id
             LIMIT 1'
        );

        $stmt->execute([
            'id' => $userId,
        ]);

        $verifiedAt = $stmt->fetchColumn();

        return $verifiedAt !== false
            && $verifiedAt !== null;
    }

    /**
     * Deactivate a user account.
     */
    public function deactivate(
        string $userId
    ): bool {
        $stmt = $this->db->prepare(
            'UPDATE users
             SET is_active = FALSE
             WHERE id = :id
             RETURNING id'
        );

        $stmt->execute([
            'id' => $userId,
        ]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Reactivate a user account.
     */
    public function activate(
        string $userId
    ): bool {
        $stmt = $this->db->prepare(
            'UPDATE users
             SET is_active = TRUE
             WHERE id = :id
             RETURNING id'
        );

        $stmt->execute([
            'id' => $userId,
        ]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Remove sensitive authentication data.
     */
    public function publicData(
        array $user
    ): array {
        unset(
            $user['password_hash']
        );

        return $user;
    }
}
