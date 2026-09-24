<?php

declare(strict_types=1);

use ScanId\Controllers\AuthController;
use ScanId\Controllers\FoundDocumentController;
use ScanId\Controllers\FinderRewardController;
use ScanId\Controllers\HandoverController;
use ScanId\Controllers\LostDocumentController;
use ScanId\Controllers\PaymentController;
use ScanId\Controllers\PhoneVerificationController;
use ScanId\Controllers\RecoveryController;
use ScanId\Controllers\SmsController;
use ScanId\Http\AuthMiddleware;
use ScanId\Http\Request;
use ScanId\Http\Response;
use ScanId\Http\Router;

/*
|--------------------------------------------------------------------------
| SCAN-ID API Routes
|--------------------------------------------------------------------------
|
| Base:
|   /api
|
| Public:
|   - Health
|   - Register
|   - Login
|   - Found-document reporting
|   - M-Pesa callback
|   - Finder consent
|
| Authenticated:
|   - Current user
|   - Logout
|   - Phone verification
|   - Lost documents
|   - Recovery requests
|   - Payments
|   - Handovers
|
|--------------------------------------------------------------------------
*/

$router = new Router();

/*
|--------------------------------------------------------------------------
| API Health
|--------------------------------------------------------------------------
*/

$router->get('/api', static function (): void {
    Response::success([
        'name' => 'SCAN-ID',
        'service' => 'Lost Document Recovery Network Kenya',
        'status' => 'online',
    ]);
});

$router->get('/api/health', static function (): void {
    Response::success([
        'status' => 'healthy',
        'service' => 'scan-id-api',
    ]);
});

/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
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
    [AuthController::class, 'me'],
    [AuthMiddleware::class, 'handle']
);

$router->post(
    '/api/auth/logout',
    [AuthController::class, 'logout'],
    [AuthMiddleware::class, 'handle']
);

/*
|--------------------------------------------------------------------------
| Phone Verification
|--------------------------------------------------------------------------
*/

$router->post(
    '/api/auth/phone/send',
    [PhoneVerificationController::class, 'send'],
    [AuthMiddleware::class, 'handle']
);

$router->post(
    '/api/auth/phone/verify',
    [PhoneVerificationController::class, 'verify'],
    [AuthMiddleware::class, 'handle']
);

$router->get(
    '/api/auth/phone/status',
    [PhoneVerificationController::class, 'status'],
    [AuthMiddleware::class, 'handle']
);

/*
|--------------------------------------------------------------------------
| Lost Documents
|--------------------------------------------------------------------------
*/

$router->post(
    '/api/lost-documents',
    [LostDocumentController::class, 'report'],
    [AuthMiddleware::class, 'handle']
);

$router->get(
    '/api/lost-documents/mine',
    [LostDocumentController::class, 'mine'],
    [AuthMiddleware::class, 'handle']
);

$router->get(
    '/api/lost-documents/{id}',
    [LostDocumentController::class, 'show'],
    [AuthMiddleware::class, 'handle']
);

$router->delete(
    '/api/lost-documents/{id}',
    [LostDocumentController::class, 'cancel'],
    [AuthMiddleware::class, 'handle']
);

/*
|--------------------------------------------------------------------------
| Found Documents
|--------------------------------------------------------------------------
|
| A found document may be reported anonymously.
|
*/

$router->post(
    '/api/found-documents',
    [FoundDocumentController::class, 'report']
);

$router->get(
    '/api/found-documents/mine',
    [FoundDocumentController::class, 'mine'],
    [AuthMiddleware::class, 'handle']
);

$router->get(
    '/api/found-documents/{id}',
    [FoundDocumentController::class, 'show']
);

$router->post(
    '/api/found-documents/{id}/consent',
    [FoundDocumentController::class, 'consent']
);

$router->delete(
    '/api/found-documents/{id}/consent',
    [FoundDocumentController::class, 'revokeConsent']
);

$router->delete(
    '/api/found-documents/{id}',
    [FoundDocumentController::class, 'cancel']
);

/*
|--------------------------------------------------------------------------
| Recovery Requests
|--------------------------------------------------------------------------
*/

$router->post(
    '/api/recoveries',
    [RecoveryController::class, 'create'],
    [AuthMiddleware::class, 'handle']
);

$router->get(
    '/api/recoveries/mine',
    [RecoveryController::class, 'mine'],
    [AuthMiddleware::class, 'handle']
);

$router->get(
    '/api/recoveries/{id}',
    [RecoveryController::class, 'show'],
    [AuthMiddleware::class, 'handle']
);

$router->delete(
    '/api/recoveries/{id}',
    [RecoveryController::class, 'cancel'],
    [AuthMiddleware::class, 'handle']
);

/*
|--------------------------------------------------------------------------
| Recovery SMS
|--------------------------------------------------------------------------
*/

$router->post(
    '/api/recoveries/{id}/notify',
    [SmsController::class, 'notifyOwner'],
    [AuthMiddleware::class, 'handle']
);

$router->get(
    '/api/recoveries/{id}/sms',
    [SmsController::class, 'history'],
    [AuthMiddleware::class, 'handle']
);

/*
|--------------------------------------------------------------------------
| M-Pesa Recovery Payments
|--------------------------------------------------------------------------
|
| Callback is intentionally unauthenticated because Safaricom
| calls this endpoint directly.
|
*/

$router->post(
    '/api/payments/recovery',
    [PaymentController::class, 'create'],
    [AuthMiddleware::class, 'handle']
);

$router->get(
    '/api/payments/{id}',
    [PaymentController::class, 'show'],
    [AuthMiddleware::class, 'handle']
);

$router->get(
    '/api/payments/mine',
    [PaymentController::class, 'mine'],
    [AuthMiddleware::class, 'handle']
);

$router->post(
    '/api/payments/mpesa/callback',
    [PaymentController::class, 'callback']
);

/*
|--------------------------------------------------------------------------
| Handovers
|--------------------------------------------------------------------------
*/

$router->post(
    '/api/handovers',
    [HandoverController::class, 'create'],
    [AuthMiddleware::class, 'handle']
);

$router->get(
    '/api/handovers/{id}',
    [HandoverController::class, 'show'],
    [AuthMiddleware::class, 'handle']
);

$router->post(
    '/api/handovers/{id}/schedule',
    [HandoverController::class, 'schedule'],
    [AuthMiddleware::class, 'handle']
);

$router->patch(
    '/api/handovers/{id}/location',
    [HandoverController::class, 'updateLocation'],
    [AuthMiddleware::class, 'handle']
);

$router->patch(
    '/api/handovers/{id}/notes',
    [HandoverController::class, 'updateNotes'],
    [AuthMiddleware::class, 'handle']
);

$router->post(
    '/api/handovers/{id}/finder-consent-token',
    [HandoverController::class, 'createFinderConsentToken'],
    [AuthMiddleware::class, 'handle']
);

/*
|--------------------------------------------------------------------------
| Finder Consent
|--------------------------------------------------------------------------
|
| The finder uses the secure recovery token.
| No normal account is required.
|
*/

$router->post(
    '/api/handovers/finder-consent',
    [HandoverController::class, 'grantFinderConsent']
);

/*
|--------------------------------------------------------------------------
| Final Handover Token
|--------------------------------------------------------------------------
*/

$router->post(
    '/api/handovers/{id}/handover-token',
    [HandoverController::class, 'createHandoverToken'],
    [AuthMiddleware::class, 'handle']
);

$router->post(
    '/api/handovers/{id}/complete',
    [HandoverController::class, 'complete'],
    [AuthMiddleware::class, 'handle']
);

$router->delete(
    '/api/handovers/{id}',
    [HandoverController::class, 'cancel'],
    [AuthMiddleware::class, 'handle']
);

/*
|--------------------------------------------------------------------------
| Finder Rewards
|--------------------------------------------------------------------------
*/

$router->get(
    '/api/rewards/{id}',
    [FinderRewardController::class, 'show'],
    [AuthMiddleware::class, 'handle']
);

$router->get(
    '/api/rewards/mine',
    [FinderRewardController::class, 'mine'],
    [AuthMiddleware::class, 'handle']
);

$router->post(
    '/api/rewards/{id}/payable',
    [FinderRewardController::class, 'makePayable'],
    [AuthMiddleware::class, 'handle']
);

/*
|--------------------------------------------------------------------------
| Route Dispatch
|--------------------------------------------------------------------------
|
| The public/index.php entry point should call:
|
|   $router->dispatch(
|       Request::method(),
|       Request::path()
|   );
|
|--------------------------------------------------------------------------
*/

return $router;
