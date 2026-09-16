<?php

declare(strict_types=1);

use ScanId\Config\Database;
use ScanId\Http\Cors;
use ScanId\Http\Request;
use ScanId\Http\Response;
use ScanId\Http\Router;

/*
|--------------------------------------------------------------------------
| Bootstrap
|--------------------------------------------------------------------------
*/

$application = require dirname(__DIR__) . '/bootstrap.php';

/*
|--------------------------------------------------------------------------
| CORS
|--------------------------------------------------------------------------
*/

Cors::apply();

/*
|--------------------------------------------------------------------------
| Router
|--------------------------------------------------------------------------
*/

$router = new Router();

/*
|--------------------------------------------------------------------------
| API Root
|--------------------------------------------------------------------------
*/

$router->get('/api', function () use ($application): never {
    Response::success([
        'app' => $application['name'],
        'message' => 'SCAN-ID API is running.',
        'version' => '1.0.0',
    ]);
});

/*
|--------------------------------------------------------------------------
| Health Check
|--------------------------------------------------------------------------
*/

$router->get('/api/health', function (): never {
    Response::success([
        'app' => $_ENV['APP_NAME'] ?? 'SCAN-ID',
        'status' => 'healthy',
        'database' => Database::ping()
            ? 'connected'
            : 'unavailable',
        'timestamp' => date(DATE_ATOM),
    ]);
});

/*
|--------------------------------------------------------------------------
| Dispatch Request
|--------------------------------------------------------------------------
*/

$router->dispatch(
    Request::method(),
    Request::path()
);
