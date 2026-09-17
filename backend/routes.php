<?php  
  
declare(strict_types=1);  
  
use ScanId\Config\Database;  
use ScanId\Controllers\AuthController;  
use ScanId\Http\Response;  
use ScanId\Http\Router;  
  
$router = new Router();  
  
/*  
 * ============================================================  
 * SYSTEM  
 * ============================================================  
 */  
  
$router->get('/api', function (): never {  
    Response::success([  
        'app' => $_ENV['APP_NAME'] ?? 'SCAN-ID',  
        'message' => 'SCAN-ID API is running.',  
        'version' => '1.0.0',  
    ]);  
});  
  
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
 * ============================================================  
 * AUTHENTICATION  
 * ============================================================  
 */  
  
$router->post(  
    '/api/auth/register',  
    [AuthController::class, 'register']  
);  
  
$router->post(  
    '/api/auth/login',  
    [AuthController::class, 'login']  
);  
  
$router->get(  
    '/api/auth/me',  
    [AuthController::class, 'me']  
);  
  
$router->post(  
    '/api/auth/logout',  
    [AuthController::class, 'logout']  
);  
  
return $router;  
