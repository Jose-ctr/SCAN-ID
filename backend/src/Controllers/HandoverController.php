<?php

declare(strict_types=1);

namespace ScanId\Controllers;

use ScanId\Http\AuthMiddleware;
use ScanId\Http\Request;
use ScanId\Http\Response;
use ScanId\Models\Handover;
use ScanId\Models\RecoveryRequest;
use Throwable;

final class HandoverController
{
    /**
     * Create a safe handover record for a recovery request.
     */
    public static function create(): void
    {
        try {
            $user = AuthMiddleware::requireUser();

            $data = Request::json();

            $recoveryRequestId = self::requiredString(
                $data,
                'recovery_request_id'
            );

            $recovery = RecoveryRequest::findById(
                $recoveryRequestId
            );

            if ($recovery === null) {
                Response::error(
                    'Recovery request not found.',
                    404
                );
            }

            self::assertOwner(
                $recovery,
                (string) $user['id']
            );

            if (
                !in_array(
                    $recovery['status'] ?? '',
                    [
                        'paid',
                        'contact_released',
                    ],
                    true
                )
            ) {
                Response::error(
                    'Handover cannot be created before payment and recovery verification.',
                    409
                );
            }

            $existing = Handover::findByRecoveryRequest(
                $recoveryRequestId
            );

            if ($existing !== null) {
                Response::success(
                    [
                        'handover' => self::publicHandover($existing),
                    ],
                    'A handover record already exists.'
                );
            }

            $safeLocation = self::optionalString(
                $data,
                'safe_location'
            );

            $scheduledAt = self::optionalString(
                $data,
                'scheduled_at'
            );

            $notes = self::optionalString(
                $data,
                'notes'
            );

            $handover = Handover::create(
                $recoveryRequestId,
                $safeLocation,
                $scheduledAt,
                $notes
            );

            Response::success(
                [
                    'handover' => self::publicHandover($handover),
                ],
                'Safe handover created successfully.',
                201
            );
        } catch (Throwable $exception) {
            self::handleException($exception);
        }
    }

    /**
     * Show a handover belonging to the authenticated user's recovery.
     */
    public static function show(): void
    {
        try {
            $user = AuthMiddleware::requireUser();

            $id = self::routeId();

            $handover = Handover::findById($id);

            if ($handover === null) {
                Response::error(
                    'Handover not found.',
                    404
                );
            }

            $recoveryRequestId =
                $handover['recovery_request_id'] ?? null;

            if (!is_string($recoveryRequestId)) {
                Response::error(
                    'Handover recovery request is invalid.',
                    500
                );
            }

            $recovery = RecoveryRequest::findById(
                $recoveryRequestId
            );

            if ($recovery === null) {
                Response::error(
                    'Recovery request not found.',
                    404
                );
            }

            self::assertOwner(
                $recovery,
                (string) $user['id']
            );

            Response::success(
                [
                    'handover' => self::publicHandover($handover),
                ]
            );
        } catch (Throwable $exception) {
            self::handleException($exception);
        }
    }

    /**
     * Schedule a handover.
     */
    public static function schedule(): void
    {
        try {
            $user = AuthMiddleware::requireUser();

            $id = self::routeId();

            $data = Request::json();

            $handover = Handover::findById($id);

            if ($handover === null) {
                Response::error(
                    'Handover not found.',
                    404
                );
            }

            $recoveryRequestId =
                $handover['recovery_request_id'] ?? null;

            if (!is_string($recoveryRequestId)) {
                Response::error(
                    'Handover recovery request is invalid.',
                    500
                );
            }

            $recovery = RecoveryRequest::findById(
                $recoveryRequestId
            );

            if ($recovery === null) {
                Response::error(
                    'Recovery request not found.',
                    404
                );
            }

            self::assertOwner(
                $recovery,
                (string) $user['id']
            );

            $scheduledAt = self::requiredString(
                $data,
                'scheduled_at'
            );

            $updated = Handover::schedule(
                $id,
                $scheduledAt
            );

            Response::success(
                [
                    'handover' => self::publicHandover($updated),
                ],
                'Handover scheduled successfully.'
            );
        } catch (Throwable $exception) {
            self::handleException($exception);
        }
    }

    /**
     * Grant finder consent for the handover.
     */
    public static function grantFinderConsent(): void
    {
        try {
            $user = AuthMiddleware::requireUser();

            $id = self::routeId();

            $handover = Handover::findById($id);

            if ($handover === null) {
                Response::error(
                    'Handover not found.',
                    404
                );
            }

            $recoveryRequestId =
                $handover['recovery_request_id'] ?? null;

            if (!is_string($recoveryRequestId)) {
                Response::error(
                    'Handover recovery request is invalid.',
                    500
                );
            }

            $recovery = RecoveryRequest::findById(
                $recoveryRequestId
            );

            if ($recovery === null) {
                Response::error(
                    'Recovery request not found.',
                    404
                );
            }

            self::assertOwner(
                $recovery,
                (string) $user['id']
            );

            $updated = Handover::grantFinderConsent($id);

            Response::success(
                [
                    'handover' => self::publicHandover($updated),
                ],
                'Finder consent recorded.'
            );
        } catch (Throwable $exception) {
            self::handleException($exception);
        }
    }

    /**
     * Complete a handover.
     *
     * The final handover verification will later be strengthened
     * with recovery-token validation before this operation is allowed.
     */
    public static function complete(): void
    {
        try {
            $user = AuthMiddleware::requireUser();

            $id = self::routeId();

            $handover = Handover::findById($id);

            if ($handover === null) {
                Response::error(
                    'Handover not found.',
                    404
                );
            }

            $recoveryRequestId =
                $handover['recovery_request_id'] ?? null;

            if (!is_string($recoveryRequestId)) {
                Response::error(
                    'Handover recovery request is invalid.',
                    500
                );
            }

            $recovery = RecoveryRequest::findById(
                $recoveryRequestId
            );

            if ($recovery === null) {
                Response::error(
                    'Recovery request not found.',
                    404
                );
            }

            self::assertOwner(
                $recovery,
                (string) $user['id']
            );

            if (
                !in_array(
                    $recovery['status'] ?? '',
                    [
                        'paid',
                        'contact_released',
                    ],
                    true
                )
            ) {
                Response::error(
                    'Recovery is not ready for handover completion.',
                    409
                );
            }

            if (
                !Handover::hasFinderConsent($id)
            ) {
                Response::error(
                    'Finder consent is required before completing handover.',
                    409
                );
            }

            $updated = Handover::complete($id);

            RecoveryRequest::markCompleted(
                $recoveryRequestId
            );

            Response::success(
                [
                    'handover' => self::publicHandover($updated),
                    'recovery_status' => 'completed',
                ],
                'Handover completed successfully.'
            );
        } catch (Throwable $exception) {
            self::handleException($exception);
        }
    }

    /**
     * Cancel a handover.
     */
    public static function cancel(): void
    {
        try {
            $user = AuthMiddleware::requireUser();

            $id = self::routeId();

            $handover = Handover::findById($id);

            if ($handover === null) {
                Response::error(
                    'Handover not found.',
                    404
                );
            }

            $recoveryRequestId =
                $handover['recovery_request_id'] ?? null;

            if (!is_string($recoveryRequestId)) {
                Response::error(
                    'Handover recovery request is invalid.',
                    500
                );
            }

            $recovery = RecoveryRequest::findById(
                $recoveryRequestId
            );

            if ($recovery === null) {
                Response::error(
                    'Recovery request not found.',
                    404
                );
            }

            self::assertOwner(
                $recovery,
                (string) $user['id']
            );

            $updated = Handover::cancel($id);

            Response::success(
                [
                    'handover' => self::publicHandover($updated),
                ],
                'Handover cancelled.'
            );
        } catch (Throwable $exception) {
            self::handleException($exception);
        }
    }

    private static function assertOwner(
        array $recovery,
        string $userId
    ): void {
        if (
            !isset($recovery['owner_user_id'])
            || (string) $recovery['owner_user_id'] !== $userId
        ) {
            Response::error(
                'You are not allowed to manage this handover.',
                403
            );
        }
    }

    private static function publicHandover(
        array $handover
    ): array {
        return [
            'id' => $handover['id'] ?? null,
            'recovery_request_id' =>
                $handover['recovery_request_id'] ?? null,
            'finder_consent' =>
                (bool) ($handover['finder_consent'] ?? false),
            'safe_location' =>
                $handover['safe_location'] ?? null,
            'scheduled_at' =>
                $handover['scheduled_at'] ?? null,
            'completed_at' =>
                $handover['completed_at'] ?? null,
            'status' =>
                $handover['status'] ?? null,
            'notes' =>
                $handover['notes'] ?? null,
            'created_at' =>
                $handover['created_at'] ?? null,
            'updated_at' =>
                $handover['updated_at'] ?? null,
        ];
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

    private static function optionalString(
        array $data,
        string $field
    ): ?string {
        $value = $data[$field] ?? null;

        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            Response::error(
                ucfirst(str_replace('_', ' ', $field))
                . ' must be a string.',
                422
            );
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private static function routeId(): string
    {
        $id = Request::input('id');

        if (!is_string($id) || trim($id) === '') {
            Response::error(
                'Handover ID is required.',
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
                'Invalid handover ID.',
                400
            );
        }

        return $id;
    }

    private static function handleException(
        Throwable $exception
    ): void {
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
            'Unable to process the handover request.',
            400
        );
    }

    private function __construct()
    {
    }
}
