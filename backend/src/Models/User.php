<?php

declare(strict_types=1);

namespace ScanId\Models;

use PDO;
use ScanId\Config\Database;
use RuntimeException;

final class User
{
    /**
     * Create a new user.
     *
     * @param array{
     *     full_name: string,
     *     phone: string,
     *     email?: string|null,
     *     password?: string|null,
     *     role?: string
     * } $data
     *
     * @return array<string, mixed>
     */
    public static function create(array $data): array
    {
        $fullName = trim($data['full_name'] ?? '');
        $phone = trim($data['phone'] ?? '');
        $email = isset($data['email'])
            ? trim((string) $data['email'])
            : null;

        $password = $data['password'] ?? null;
        $role = $data['role'] ?? 'user';

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

        if (!in_array($role, ['user', 'admin'], true)) {
            throw new RuntimeException(
                'Invalid user role.'
            );
        }

        $passwordHash = null;

        if ($password !== null && $password !== '') {
            $passwordHash = password_hash(
                $password,
                PASSWORD_DEFAULT
            );

            if ($passwordHash === false) {
                throw new RuntimeException(
                    'Unable to secure the password.'
                );
            }
        }

        $database = Database::connection();

        $statement = $database->prepare(
            '
            INSERT INTO users (
                full_name,
                phone,
                email,
                password_hash,
                role
            )
            VALUES (
                :full_name,
                :phone,
                :email,
                :password_hash,
                :role
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
            '
        );

        $statement->execute([
            ':full_name' => $fullName,
            ':phone' => $phone,
            ':email' => $email !== '' ? $email : null,
            ':password_hash' => $passwordHash,
            ':role' => $role,
        ]);

        $user = $statement->fetch();

        if (!is_array($user)) {
            throw new RuntimeException(
                'Unable to create user.'
            );
        }

        return $user;
    }

    /**
     * Find a user by ID.
     *
     * @return array<string, mixed>|null
     */
    public static function findById(string $id): ?array
    {
        $database = Database::connection();

        $statement = $database->prepare(
            '
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
            '
        );

        $statement->execute([
            ':id' => $id,
        ]);

        $user = $statement->fetch();

        return is_array($user)
            ? $user
            : null;
    }

    /**
     * Find a user by phone number.
     *
     * @return array<string, mixed>|null
     */
    public static function findByPhone(string $phone): ?array
    {
        $database = Database::connection();

        $statement = $database->prepare(
            '
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
            '
        );

        $statement->execute([
            ':phone' => trim($phone),
        ]);

        $user = $statement->fetch();

        return is_array($user)
            ? $user
            : null;
    }

    /**
     * Find a user by email.
     *
     * @return array<string, mixed>|null
     */
    public static function findByEmail(string $email): ?array
    {
        $database = Database::connection();

        $statement = $database->prepare(
            '
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
            WHERE email = :email
            LIMIT 1
            '
        );

        $statement->execute([
            ':email' => trim($email),
        ]);

        $user = $statement->fetch();

        return is_array($user)
            ? $user
            : null;
    }

    /**
     * Verify a user's password.
     */
    public static function verifyPassword(
        string $password,
        string $passwordHash
    ): bool {
        return password_verify(
            $password,
            $passwordHash
        );
    }

    /**
     * Mark a user's phone as verified.
     */
    public static function verifyPhone(
        string $id
    ): bool {
        $database = Database::connection();

        $statement = $database->prepare(
            '
            UPDATE users
            SET phone_verified_at = NOW()
            WHERE id = :id
            '
        );

        $statement->execute([
            ':id' => $id,
        ]);

        return $statement->rowCount() === 1;
    }

    /**
     * Prevent accidental instantiation.
     */
    private function __construct()
    {
    }
}
