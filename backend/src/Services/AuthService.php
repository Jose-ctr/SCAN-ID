<?php

declare(strict_types=1);

namespace ScanId\Services;

use RuntimeException;
use ScanId\Config\Database;
use ScanId\Models\User;

final class AuthService
{
    /**
     * Register a new SCAN-ID user.
     *
     * @param array{
     *     full_name: string,
     *     phone: string,
     *     email?: string|null,
     *     password?: string|null
     * } $data
     *
     * @return array<string, mixed>
     */
    public static function register(array $data): array
    {
        $fullName = trim($data['full_name'] ?? '');
        $phone = trim($data['phone'] ?? '');
        $email = isset($data['email'])
            ? trim((string) $data['email'])
            : null;

        $password = $data['password'] ?? null;

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

        if ($password === null || $password === '') {
            throw new RuntimeException(
                'Password is required.'
            );
        }

        if (strlen($password) < 8) {
            throw new RuntimeException(
                'Password must be at least 8 characters.'
            );
        }

        if (User::findByPhone($phone) !== null) {
            throw new RuntimeException(
                'A user with this phone number already exists.'
            );
        }

        if ($email !== null && $email !== '') {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException(
                    'Invalid email address.'
                );
            }

            if (User::findByEmail($email) !== null) {
                throw new RuntimeException(
                    'A user with this email address already exists.'
                );
            }
        }

        return User::create([
            'full_name' => $fullName,
            'phone' => $phone,
            'email' => $email,
            'password' => $password,
            'role' => 'user',
        ]);
    }

    /**
     * Authenticate a user using phone number and password.
     *
     * @return array<string, mixed>
     */
    public static function login(
        string $phone,
        string $password,
        ?string $deviceName = null
    ): array {
        $phone = trim($phone);

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

        $user = User::findByPhone($phone);

        if ($user === null) {
            throw new RuntimeException(
                'Invalid phone number or password.'
            );
        }

        if (!(bool) $user['is_active']) {
            throw new RuntimeException(
                'This account is inactive.'
            );
        }

        $passwordHash = $user['password_hash'] ?? null;

        if (
            !is_string($passwordHash) ||
            $passwordHash === ''
        ) {
            throw new RuntimeException(
                'This account does not have a password configured.'
            );
        }

        if (!User::verifyPassword($password, $passwordHash)) {
            throw new RuntimeException(
                'Invalid phone number or password.'
            );
        }

        $token = self::generateToken();

        self::createSession(
            (string) $user['id'],
            $token,
            $deviceName
        );

        unset($user['password_hash']);

        return [
            'user' => $user,
            'token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => self::sessionLifetime(),
        ];
    }

    /**
     * Create a persistent authentication session.
     */
    public static function createSession(
        string $userId,
        string $token,
        ?string $deviceName = null
    ): void {
        if ($userId === '') {
            throw new RuntimeException(
                'User ID is required.'
            );
        }

        if ($token === '') {
            throw new RuntimeException(
                'Authentication token is required.'
            );
        }

        $tokenHash = hash('sha256', $token);

        $database = Database::connection();

        $statement = $database->prepare(
            '
            INSERT INTO user_sessions (
                user_id,
                token_hash,
                device_name,
                ip_address,
                user_agent,
                expires_at,
                last_used_at
            )
            VALUES (
                :user_id,
                :token_hash,
                :device_name,
                :ip_address,
                :user_agent,
                NOW() + (:lifetime * INTERVAL \'1 second\'),
                NOW()
            )
            '
        );

        $statement->execute([
            ':user_id' => $userId,
            ':token_hash' => $tokenHash,
            ':device_name' => $deviceName,
            ':ip_address' => self::clientIp(),
            ':user_agent' => self::userAgent(),
            ':lifetime' => self::sessionLifetime(),
        ]);
    }

    /**
     * Retrieve the authenticated user from a bearer token.
     *
     * @return array<string, mixed>|null
     */
    public static function userFromToken(
        string $token
    ): ?array {
        $token = trim($token);

        if ($token === '') {
            return null;
        }

        $tokenHash = hash('sha256', $token);

        $database = Database::connection();

        $statement = $database->prepare(
            '
            SELECT
                u.id,
                u.full_name,
                u.phone,
                u.email,
                u.role,
                u.phone_verified_at,
                u.is_active,
                u.created_at,
                u.updated_at
            FROM user_sessions AS s
            INNER JOIN users AS u
                ON u.id = s.user_id
            WHERE s.token_hash = :token_hash
              AND s.expires_at > NOW()
              AND u.is_active = TRUE
            LIMIT 1
            '
        );

        $statement->execute([
            ':token_hash' => $tokenHash,
        ]);

        $user = $statement->fetch();

        if (!is_array($user)) {
            return null;
        }

        self::touchSession($tokenHash);

        return $user;
    }

    /**
     * Revoke an authentication session.
     */
    public static function logout(string $token): bool
    {
        $token = trim($token);

        if ($token === '') {
            return false;
        }

        $tokenHash = hash('sha256', $token);

        $database = Database::connection();

        $statement = $database->prepare(
            '
            DELETE FROM user_sessions
            WHERE token_hash = :token_hash
            '
        );

        $statement->execute([
            ':token_hash' => $tokenHash,
        ]);

        return $statement->rowCount() === 1;
    }

    /**
     * Generate a cryptographically secure authentication token.
     */
    private static function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Update the last-used timestamp for a session.
     */
    private static function touchSession(
        string $tokenHash
    ): void {
        $database = Database::connection();

        $statement = $database->prepare(
            '
            UPDATE user_sessions
            SET last_used_at = NOW()
            WHERE token_hash = :token_hash
            '
        );

        $statement->execute([
            ':token_hash' => $tokenHash,
        ]);
    }

    /**
     * Return configured session lifetime in seconds.
     */
    private static function sessionLifetime(): int
    {
        $lifetime = filter_var(
            $_ENV['SESSION_LIFETIME'] ?? 86400,
            FILTER_VALIDATE_INT
        );

        if ($lifetime === false || $lifetime < 300) {
            throw new RuntimeException(
                'Invalid session lifetime configuration.'
            );
        }

        return $lifetime;
    }

    /**
     * Get the client IP address.
     */
    private static function clientIp(): ?string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;

        return is_string($ip) && $ip !== ''
            ? $ip
            : null;
    }

    /**
     * Get the client user-agent.
     */
    private static function userAgent(): ?string
    {
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

        return is_string($userAgent) && $userAgent !== ''
            ? substr($userAgent, 0, 1000)
            : null;
    }

    private function __construct()
    {
    }
}
