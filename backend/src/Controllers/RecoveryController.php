<?php

declare(strict_types=1);

namespace ScanId\Controllers;

use ScanId\Http\AuthMiddleware;
use ScanId\Http\Request;
use ScanId\Http\Response;
use ScanId\Models\RecoveryRequest;
use ScanId\Services\LostDocumentService;
use ScanId\Services\FoundDocumentService;
use Throwable;

final class RecoveryController
{
    /**
     * Create a recovery request for a matched lost/found document.
     */
    public static function create(): void
    {
        try {
            $user = AuthMiddleware::requireUser();

            $data = Request::json();

            $lostDocumentId = self::requiredString(
                $data,
                'lost_document_id'
            );

            $foundDocumentId = self::requiredString(
                $data,
                'found_document_id'
            );

            $lostDocument = LostDocumentService::findById(
                $lostDocumentId
            );

            if ($lostDocument === null) {
                Response::error(
                    'Lost document not found.',
                    404
                );
            }

            $foundDocument = FoundDocumentService::findById(
                $foundDocumentId
            );

            if ($foundDocument === null) {
                Response::error(
                    'Found document not found.',
                    404
                );
            }

            if (
                isset($lostDocument['owner_user_id'])
                && (string) $lostDocument['owner_user_id']
                    !== (string) $user['id']
            ) {
                Response::error(
                    'You are not the owner of the lost document report.',
                    403
                );
            }

            $ownerPhone = $lostDocument['owner_phone'] ?? null;

            if (!is_string($ownerPhone) || trim($ownerPhone) === '') {
                Response::error(
                    'Owner phone number is missing from the lost document report.',
                    422
                );
            }

            $existing = RecoveryRequest::findActiveByFoundDocument(
                $foundDocumentId
            );

            if ($existing !== null) {
                Response::success(
                    [
                        'recovery_request' => $existing,
                    ],
                    'An active recovery request already exists.'
                );
            }

            $recovery = RecoveryRequest::create(
                $lostDocumentId,
                $foundDocumentId,
                (string) $user['id'],
                $ownerPhone
            );

            Response::success(
                [
                    'recovery_request' => $recovery,
                ],
                'Recovery request created successfully.',
                201
            );
        } catch (Throwable $exception) {
            self::handleException($exception);
        }
    }

    /**
     * Show a recovery request owned by the authenticated user.
     */
    public static function show(): void
    {
        try {
            $user = AuthMiddleware::requireUser();

            $id = self::routeId();

            $recovery = RecoveryRequest::findById($id);

            if ($recovery === null) {
                Response::error(
                    'Recovery request not found.',
                    404
                );
            }

            if (
                isset($recovery['owner_user_id'])
                && (string) $recovery['owner_user_id']
                    !== (string) $user['id']
            ) {
                Response::error(
                    'You are not allowed to access this recovery request.',
                    403
                );
            }

            Response::success(
                [
                    'recovery_request' => $recovery,
                ]
            );
        } catch (Throwable $exception) {
            self::handleException($exception);
        }
    }

    /**
     * List the authenticated user's recovery requests.
     */
    public static function mine(): void
    {
        try {
            $user = AuthMiddleware::requireUser();

            $recoveryRequests = RecoveryRequest::findByOwnerUser(
                (string) $user['id']
            );

            Response::success(
                [
                    'recovery_requests' => $recoveryRequests,
                    'count' => count($recoveryRequests),
                ]
            );
        } catch (Throwable $exception) {
            self::handleException($exception);
        }
    }

    /**
     * Cancel an active recovery request.
     */
    public static function cancel(): void
    {
        try {
            $user = AuthMiddleware::requireUser();

            $id = self::routeId();

            $recovery = RecoveryRequest::findById($id);

            if ($recovery === null) {
                Response::error(
                    'Recovery request not found.',
                    404
                );
            }

            if (
                isset($recovery['owner_user_id'])
                && (string) $recovery['owner_user_id']
                    !== (string) $user['id']
            ) {
                Response::error(
                    'You are not allowed to cancel this recovery request.',
                    403
                );
            }

            if (
                isset($recovery['status'])
                && !in_array(
                    $recovery['status'],
                    [
                        'pending',
                        'notified',
                        'payment_pending',
                    ],
                    true
                )
            ) {
                Response::error(
                    'This recovery request cannot be cancelled in its current state.',
                    409
                );
            }

            $updated = RecoveryRequest::cancel($id);

            Response::success(
                [
                    'recovery_request' => $updated,
                ],
                'Recovery request cancelled.'
            );
        } catch (Throwable $exception) {
            self::handleException($exception);
        }
    }

    private static function requiredString(
        array $data,
        string $field
    ): string {
        $value = $data[$field] ?? null;

        if (!is_string($value) || trim($value) === '') {
            Response::error(
                ucfirst(str_replace('_', ' ', $field))
                . ' is required.',
                422
            );
        }

        return trim($value);
    }

    private static function routeId(): string
    {
        $id = Request::input('id');

        if (!is_string($id) || trim($id) === '') {
            Response::error(
                'Recovery request ID is required.',
                400
            );
        }

        $id = trim($id);

        if (
            !preg_match(
                '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/',
                $id
            )
        ) {
            Response::error(
                'Invalid recovery request ID.',
                400
            );
        }

        return $id;
    }

    private static function handleException(Throwable $exception): void
    {
        if (
            filter_var(
                $_ENV['APP_DEBUG'] ?? false,
                FILTER_VALIDATE_BOOLEAN
            )
        ) {
            Response::error(
                $exception->getMessage(),
                400
            );
        }

        Response::error(
            'Unable to process the recovery request.',
            400
        );
    }

    private function __construct()
    {
    }
}
