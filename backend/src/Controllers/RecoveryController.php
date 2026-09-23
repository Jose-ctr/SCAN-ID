<?php

declare(strict_types=1);

namespace ScanId\Controllers;

use ScanId\Config\Database;
use ScanId\Http\AuthMiddleware;
use ScanId\Http\Request;
use ScanId\Http\Response;
use ScanId\Models\RecoveryRequest;
use ScanId\Services\RecoveryService;
use Throwable;

final class RecoveryController
{
    /**
     * Create a recovery request.
     *
     * RecoveryService performs the actual server-side
     * document matching and authorization checks.
     */
    public static function create(): void
    {
        $user = AuthMiddleware::requireUser();

        $input = Request::input();

        $lostDocumentId = trim(
            (string) ($input['lost_document_id'] ?? '')
        );

        $foundDocumentId = trim(
            (string) ($input['found_document_id'] ?? '')
        );

        if (!self::isUuid($lostDocumentId)) {
            Response::error(
                'A valid lost document ID is required.',
                422
            );
        }

        if (!self::isUuid($foundDocumentId)) {
            Response::error(
                'A valid found document ID is required.',
                422
            );
        }

        $ownerUserId = (string) ($user['id'] ?? '');

        if (!self::isUuid($ownerUserId)) {
            Response::error(
                'Authenticated user session is invalid.',
                401
            );
        }

        try {
            $db = Database::connection();

            $service = new RecoveryService($db);

            $recovery = $service->create(
                $lostDocumentId,
                $foundDocumentId,
                $ownerUserId,
                isset($input['owner_phone'])
                    ? (string) $input['owner_phone']
                    : null
            );

            Response::success(
                [
                    'recovery' => self::publicRecovery(
                        $recovery
                    ),
                    'message' =>
                        'Document match verified and recovery request created.',
                ],
                201
            );
        } catch (Throwable $exception) {
            if (
                $exception instanceof \RuntimeException
            ) {
                Response::error(
                    $exception->getMessage(),
                    422
                );
            }

            Response::error(
                'Unable to create the recovery request.',
                500
            );
        }
    }

    /**
     * Show one recovery request.
     */
    public static function show(): void
    {
        $user = AuthMiddleware::requireUser();

        $recoveryRequestId = trim(
            (string) Request::routeParam(
                'id'
            )
        );

        if (!self::isUuid($recoveryRequestId)) {
            Response::error(
                'Invalid recovery request ID.',
                422
            );
        }

        try {
            $db = Database::connection();

            $model = new RecoveryRequest($db);

            $recovery = $model->findById(
                $recoveryRequestId
            );

            if ($recovery === null) {
                Response::error(
                    'Recovery request not found.',
                    404
                );
            }

            if (
                ($recovery['owner_user_id'] ?? null)
                !== ($user['id'] ?? null)
            ) {
                Response::error(
                    'You are not authorized to view this recovery request.',
                    403
                );
            }

            Response::success([
                'recovery' => self::publicRecovery(
                    $recovery
                ),
            ]);
        } catch (Throwable $exception) {
            if (
                $exception instanceof \RuntimeException
            ) {
                Response::error(
                    $exception->getMessage(),
                    422
                );
            }

            Response::error(
                'Unable to load the recovery request.',
                500
            );
        }
    }

    /**
     * List the authenticated user's recovery requests.
     */
    public static function mine(): void
    {
        $user = AuthMiddleware::requireUser();

        $ownerUserId = (string) ($user['id'] ?? '');

        if (!self::isUuid($ownerUserId)) {
            Response::error(
                'Authenticated user session is invalid.',
                401
            );
        }

        try {
            $db = Database::connection();

            $model = new RecoveryRequest($db);

            $recoveries = $model->findByOwnerUser(
                $ownerUserId
            );

            $publicRecoveries = array_map(
                static function (array $recovery): array {
                    return self::publicRecovery(
                        $recovery
                    );
                },
                $recoveries
            );

            Response::success([
                'recoveries' => $publicRecoveries,
            ]);
        } catch (Throwable $exception) {
            if (
                $exception instanceof \RuntimeException
            ) {
                Response::error(
                    $exception->getMessage(),
                    422
                );
            }

            Response::error(
                'Unable to load recovery requests.',
                500
            );
        }
    }

    /**
     * Cancel a recovery request.
     */
    public static function cancel(): void
    {
        $user = AuthMiddleware::requireUser();

        $recoveryRequestId = trim(
            (string) Request::routeParam(
                'id'
            )
        );

        if (!self::isUuid($recoveryRequestId)) {
            Response::error(
                'Invalid recovery request ID.',
                422
            );
        }

        $ownerUserId = (string) ($user['id'] ?? '');

        if (!self::isUuid($ownerUserId)) {
            Response::error(
                'Authenticated user session is invalid.',
                401
            );
        }

        try {
            $db = Database::connection();

            $service = new RecoveryService($db);

            $recovery = $service->cancel(
                $recoveryRequestId,
                $ownerUserId
            );

            Response::success([
                'recovery' => self::publicRecovery(
                    $recovery
                ),
                'message' =>
                    'Recovery request cancelled.',
            ]);
        } catch (Throwable $exception) {
            if (
                $exception instanceof \RuntimeException
            ) {
                Response::error(
                    $exception->getMessage(),
                    422
                );
            }

            Response::error(
                'Unable to cancel the recovery request.',
                500
            );
        }
    }

    /**
     * Never expose document hashes, document numbers,
     * finder phone numbers, or owner phone numbers.
     */
    private static function publicRecovery(
        array $recovery
    ): array {
        return [
            'id' => $recovery['id'] ?? null,

            'lost_document_id' =>
                $recovery['lost_document_id'] ?? null,

            'found_document_id' =>
                $recovery['found_document_id'] ?? null,

            'status' =>
                $recovery['status'] ?? null,

            'requested_at' =>
                $recovery['requested_at'] ?? null,

            'paid_at' =>
                $recovery['paid_at'] ?? null,

            'contact_released_at' =>
                $recovery['contact_released_at'] ?? null,

            'completed_at' =>
                $recovery['completed_at'] ?? null,

            'expires_at' =>
                $recovery['expires_at'] ?? null,

            'created_at' =>
                $recovery['created_at'] ?? null,

            'updated_at' =>
                $recovery['updated_at'] ?? null,
        ];
    }

    /**
     * Validate UUID format.
     */
    private static function isUuid(string $value): bool
    {
        return preg_match(
            '/^[0-9a-fA-F]{8}-'
            . '[0-9a-fA-F]{4}-'
            . '[1-5][0-9a-fA-F]{3}-'
            . '[89abAB][0-9a-fA-F]{3}-'
            . '[0-9a-fA-F]{12}$/',
            $value
        ) === 1;
    }

    private function __construct()
    {
    }
}
