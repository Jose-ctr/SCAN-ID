<?php

declare(strict_types=1);

namespace ScanId\Config;

use PDO;
use PDOException;
use RuntimeException;

final class Database
{
    private static ?PDO $connection = null;

    /**
     * Get the shared database connection.
     */
    public static function connection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $driver = $_ENV['DB_CONNECTION'] ?? 'pgsql';

        if ($driver !== 'pgsql') {
            throw new RuntimeException(
                "Unsupported database connection: {$driver}"
            );
        }

        $host = $_ENV['DB_HOST'] ?? '127.0.0.1';
        $port = $_ENV['DB_PORT'] ?? '5432';
        $database = $_ENV['DB_DATABASE'] ?? '';
        $username = $_ENV['DB_USERNAME'] ?? '';
        $password = $_ENV['DB_PASSWORD'] ?? '';

        if ($database === '' || $username === '') {
            throw new RuntimeException(
                'Database configuration is incomplete.'
            );
        }

        if (
            filter_var(
                $port,
                FILTER_VALIDATE_INT,
                [
                    'options' => [
                        'min_range' => 1,
                        'max_range' => 65535,
                    ],
                ]
            ) === false
        ) {
            throw new RuntimeException(
                'Invalid database port configuration.'
            );
        }

        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            $host,
            $port,
            $database
        );

        try {
            self::$connection = new PDO(
                $dsn,
                $username,
                $password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        } catch (PDOException $exception) {
            throw new RuntimeException(
                'Unable to connect to the SCAN-ID database.',
                0,
                $exception
            );
        }

        return self::$connection;
    }

    /**
     * Test whether the database connection is available.
     */
    public static function ping(): bool
    {
        try {
            self::connection()->query('SELECT 1');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Prevent accidental instantiation.
     */
    private function __construct()
    {
    }
}
