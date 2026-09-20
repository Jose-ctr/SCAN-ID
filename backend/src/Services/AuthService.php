<?php

declare(strict_types=1);

namespace ScanId\Services;

use PDO;
use RuntimeException;
use ScanId\Config\Database;
use ScanId\Models\User;

final class AuthService
{
    /**
     * Register a new SCAN-ID user.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function register(array $data): array
    {
        $fullName = trim((string) ($data['full_name'] ?? ''));
        $phone = trim((string) ($data['phone'] ?? ''));
        $email = isset($data['email'])
            ? trim((string) $data['email'])
            : null;
        $password = (string) ($data['password'] ?? '');

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

        if ($phone === '') {
            throw new RuntimeException(
                'Phone number is required.'
            );
        }

        if (mb_strlen($phone) > 30) {
            throw new RuntimeException(
                'Phone number is too long.'
            );
        }

        if ($password === '') {
            throw new RuntimeException(
                'Password is required.'
            );
        }

        if (strlen($password) < 8) {
            throw new RuntimeException(
                'Password must contain at least 8 characters.'
            );
        }

        if ($email === '') {
            $email = null;
        }

        if ($email !== null) {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException(
                    'Invalid email address.'
                );
            }

            if (mb_strlen($email) > 255) {
                throw new RuntimeException(
                    'Email address is too long.'
                );
            }
        }

        $database = Database::connection();
        $userModel = new User($database);

        if ($userModel->findByPhone($phone) !== null) {
            throw new RuntimeException(
                'A user with this phone number already exists.'
            );
        }

        if (
            $email !== null &&
            $userModel->findByEmail($email) !== null
        ) {
            throw new RuntimeException(
                'A user with this email address already exists.'
            );
        }

        $user = $userModel->create(
            $fullName,
            $phone,
            $password,
            $email
        );

        return [
            'user' => $userModel->publicData($user),
        ];
    }

    /**
     * Authenticate a user and create a session.
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

        $database = Database::connection();
        $userModel = new User($database);

        $user = $userModel->findForAuthentication($phone);

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

        if (
            !$userModel->verifyPassword(
                $user,
                $password
            )
        ) {
            throw new RuntimeException(
                'Invalid phone number or password.'
            );
        }

        $token = bin2hex(
            random_bytes(32)
        );

        $expiresIn = self::sessionLifetime();

        self::createSession(
            $database,
            (string) $user['id'],
            $token,
            $deviceName,
            $expiresIn
        );

        return [
            'user' => $userModel->publicData($user),
            'token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => $expiresIn,
        ];
    }

    /**
     * Resolve an authenticated user from a session token.
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

        $tokenHash = hash(
            'sha256',
            $token
        );

        $database = Database::connection();

        $statement = $database->prepare(
            'SELECT
                u.id,
                u.full_name,
                u.phone,
                u.email,
                u.role,
                u.phone_verified_at,
                u.is_active,
                u.created_at,
                u.updated_at,
                s.id AS session_id
             FROM user_sessions AS s
             INNER JOIN users AS u
                ON u.id = s.user_id
             WHERE s.token_hash = :token_hash
               AND s.expires_at > NOW()
               AND u.is_active = TRUE
             LIMIT 1'
        );

        $statement->execute([
            'token_hash' => $tokenHash,
        ]);

        $user = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($user)) {
            return null;
        }

        $sessionId = (string) $user['session_id'];

        $update = $database->prepare(
            'UPDATE user_sessions
             SET last_used_at = NOW()
             WHERE id = :id'
        );

        $update->execute([
            'id' => $sessionId,
        ]);

        unset($user['session_id']);

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

        $tokenHash = hash(
            'sha256',
            $token
        );

        $database = Database::connection();

        $statement = $database->prepare(
            'DELETE FROM user_sessions
             WHERE token_hash = :token_hash'
        );

        $statement->execute([
            'token_hash' => $tokenHash,
        ]);

        return $statement->rowCount() > 0;
    }

    /**
     * Create a persistent authentication session.
     */
    private static function createSession(
        PDO $database,
        string $userId,
        string $token,
        ?string $deviceName,
        int $expiresIn
    ): void {
        $tokenHash = hash(
            'sha256',
            $token
        );

        $deviceName = $deviceName !== null
            ? trim($deviceName)
            : null;

        if (
            $deviceName !== null &&
            $deviceName === ''
        ) {
            $deviceName = null;
        }

        if (
            $deviceName !== null &&
            mb_strlen($deviceName) > 150
        ) {
            $deviceName = mb_substr(
                $deviceName,
                0,
                150
            );
        }

        $statement = $database->prepare(
            'INSERT INTO user_sessions (
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
                NOW() + (:expires_in * INTERVAL \'1 second\'),
                NOW()
             )'
        );

        $statement->execute([
            'user_id' => $userId,
            'token_hash' => $tokenHash,
            'device_name' => $deviceName,
            'ip_address' => self::clientIp(),
            'user_agent' => self::userAgent(),
            'expires_in' => $expiresIn,
        ]);
    }

    /**
     * Get configured session lifetime.
     */
    private static function sessionLifetime(): int
    {
        $value = $_ENV['SESSION_LIFETIME']
            ?? '86400';

        $lifetime = filter_var(
            $value,
            FILTER_VALIDATE_INT
        );

        if (
            $lifetime === false ||
            $lifetime < 300
        ) {
            return 86400;
        }

        return $lifetime;
    }

    /**
     * Get the client IP address.
     */
    private static function clientIp(): ?string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;

        if (
            !is_string($ip) ||
            $ip === '' ||
            filter_var(
                $ip,
                FILTER_VALIDATE_IP
            ) === false
        ) {
            return null;
        }

        return $ip;
    }

    /**
     * Get the client user-agent.
     */
    private static function userAgent(): ?string
    {
        $userAgent = $_SERVER['HTTP_USER_AGENT']
            ?? null;

        if (
            !is_string($userAgent) ||
            $userAgent === ''
        ) {
            return null;
        }

        return mb_substr(
            $userAgent,
            0,
            1000
        );
    }

    private function __construct()
    {
    }
}
