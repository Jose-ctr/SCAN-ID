<?php

declare(strict_types=1);

/**
 * ============================================================
 * SCAN-ID
 * Database Migration Runner
 * ============================================================
 *
 * Runs the SCAN-ID database schema against PostgreSQL.
 *
 * Usage:
 *     php database/migrate.php
 *
 * IMPORTANT:
 * - Never run this against production without reviewing
 *   the schema first.
 * - Database credentials come from backend/.env.
 */

$backendPath = dirname(__DIR__);

$bootstrap = $backendPath . '/bootstrap.php';
$schema = $backendPath . '/database/schema.sql';

if (!file_exists($bootstrap)) {
    fwrite(
        STDERR,
        "ERROR: Bootstrap file not found.\n"
    );

    exit(1);
}

if (!file_exists($schema)) {
    fwrite(
        STDERR,
        "ERROR: Database schema not found.\n"
    );

    exit(1);
}

require_once $bootstrap;

use ScanId\Config\Database;

try {
    $connection = Database::connection();

    $sql = file_get_contents($schema);

    if ($sql === false || trim($sql) === '') {
        throw new RuntimeException(
            'Database schema is empty or could not be read.'
        );
    }

    $connection->exec($sql);

    echo "SCAN-ID database migration completed successfully.\n";
    echo "Schema version: 1\n";

    exit(0);
} catch (Throwable $exception) {
    fwrite(
        STDERR,
        "SCAN-ID database migration failed.\n"
    );

    if (
        filter_var(
            $_ENV['APP_DEBUG'] ?? false,
            FILTER_VALIDATE_BOOLEAN
        )
    ) {
        fwrite(
            STDERR,
            $exception->getMessage() . "\n"
        );
    }

    exit(1);
}
