<?php

declare(strict_types=1);

/**
 * ============================================================
 * SCAN-ID
 * Application Bootstrap
 * ============================================================
 *
 * Responsibilities:
 * - Load Composer autoloader
 * - Load environment variables
 * - Configure application timezone
 * - Configure PHP error handling
 * - Provide a single application bootstrap point
 *
 * IMPORTANT:
 * - Never hard-code production secrets here.
 * - Never commit .env.
 */

use Dotenv\Dotenv;

/*
|--------------------------------------------------------------------------
| Composer Autoloader
|--------------------------------------------------------------------------
*/

$autoload = __DIR__ . '/vendor/autoload.php';

if (!file_exists($autoload)) {
    throw new RuntimeException(
        'Composer dependencies are not installed. Run: composer install'
    );
}

require_once $autoload;

/*
|--------------------------------------------------------------------------
| Environment Configuration
|--------------------------------------------------------------------------
*/

$envFile = __DIR__ . '/.env';

if (!file_exists($envFile)) {
    throw new RuntimeException(
        'Environment file not found. Copy .env.example to .env and configure it.'
    );
}

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

/*
|--------------------------------------------------------------------------
| Timezone
|--------------------------------------------------------------------------
*/

$timezone = $_ENV['APP_TIMEZONE'] ?? 'Africa/Nairobi';

if (!date_default_timezone_set($timezone)) {
    throw new RuntimeException(
        "Invalid application timezone: {$timezone}"
    );
}

/*
|--------------------------------------------------------------------------
| Application Environment
|--------------------------------------------------------------------------
*/

$appEnvironment = $_ENV['APP_ENV'] ?? 'local';

if ($appEnvironment === 'production') {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    ini_set('log_errors', '1');
} else {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    ini_set('log_errors', '1');
}

/*
|--------------------------------------------------------------------------
| Bootstrap Complete
|--------------------------------------------------------------------------
*/

return [
    'name' => $_ENV['APP_NAME'] ?? 'SCAN-ID',

    'environment' => $appEnvironment,

    'debug' => filter_var(
        $_ENV['APP_DEBUG'] ?? false,
        FILTER_VALIDATE_BOOLEAN
    ),

    'url' => rtrim(
        $_ENV['APP_URL'] ?? 'http://localhost:8000',
        '/'
    ),

    'timezone' => $timezone,
];
