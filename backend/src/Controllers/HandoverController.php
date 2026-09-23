<?php

declare(strict_types=1);

namespace ScanId\Controllers;

use PDO;
use ScanId\Http\AuthMiddleware;
use ScanId\Http\Request;
use ScanId\Http\Response;
use ScanId\Services\HandoverService;

final class HandoverController
{
    private HandoverService $service;

    public function __construct(PDO $connection)
    {
        $this->service = new HandoverService($connection);
    }

    /**
     * Create a handover record for a paid recovery.
     *
     * POST /api/handovers
     */
    public function create(): void
    {
        $user = AuthMiddleware::requireUser();

        $recoveryRequestId = Request::input('recovery_request_id');
        $safeLocation = Request::input('safe_location');
        $scheduledAt = Request::input('scheduled_at');
        $notes = Request::input('notes');

        if (!is_string($recoveryRequestId) || $recoveryRequestId === '') {
            Response::error(
                'recovery_request_id is required.',
                422
            );
        }

        $handover = $this->service->create(
            $recoveryRequestId,
            $user['id'],
            is_string($safeLocation) ? $safeLocation : null,
            is_string($scheduledAt) ? $scheduledAt : null,
            is_string($notes) ? $notes : null
        );

        Response::success(
            $this->publicHandover($handover),
            'Handover created successfully.',
            201
        );
    }

    /**
     * Show a handover owned by the authenticated recovery owner.
     *
     * GET /api/handovers/{id}
     */
    public function show(): void
    {
        $user = AuthMiddleware::requireUser();

        $handoverId = $this->routeId();

        $handover = $this->service->findForOwner(
            $handoverId,
            $user['id']
        );

        Response::success(
            $this->publicHandover($handover),
            'Handover retrieved successfully.'
        );
    }

    /**
     * Schedule a handover.
     *
     * PATCH /api/handovers/{id}/schedule
     */
    public function schedule(): void
    {
        $user = AuthMiddleware::requireUser();

        $handoverId = $this->routeId();
        $scheduledAt = Request::input('scheduled_at');

        if (!is_string($scheduledAt) || trim($scheduledAt) === '') {
            Response::error(
                'scheduled_at is required.',
                422
            );
        }

        $handover = $this->service->schedule(
            $handoverId,
            $user['id'],
            $scheduledAt
        );

        Response::success(
            $this->publicHandover($handover),
            'Handover scheduled successfully.'
        );
    }

    /**
     * Update safe handover location.
     *
     * PATCH /api/handovers/{id}/location
     */
    public function updateLocation(): void
    {
        $user = AuthMiddleware::requireUser();

        $handoverId = $this->routeId();
        $safeLocation = Request::input('safe_location');

        if (!is_string($safeLocation) || trim($safeLocation) === '') {
            Response::error(
                'safe_location is required.',
                422
            );
        }

        $handover = $this->service->updateLocation(
            $handoverId,
            $user['id'],
            $safeLocation
        );

        Response::success(
            $this->publicHandover($handover),
            'Handover location updated successfully.'
        );
    }

    /**
     * Update handover notes.
     *
     * PATCH /api/handovers/{id}/notes
     */
    public function updateNotes(): void
    {
        $user = AuthMiddleware::requireUser();

        $handoverId = $this->routeId();
        $notes = Request::input('notes');

        if ($notes !== null && !is_string($notes)) {
            Response::error(
                'notes must be a string or null.',
                422
            );
        }

        $handover = $this->service->updateNotes(
            $handoverId,
            $user['id'],
            $notes
        );

        Response::success(
            $this->publicHandover($handover),
            'Handover notes updated successfully.'
        );
    }

    /**
     * Create a one-time handover token.
     *
     * The raw token is returned only once.
     * It must never be stored by the frontend as a permanent credential.
     *
     * POST /api/handovers/{id}/token
     */
    public function createToken(): void
    {
        $user = AuthMiddleware::requireUser();

        $handoverId = $this->routeId();

        $expiresInSeconds = Request::input('expires_in_seconds');

        if ($expiresInSeconds === null) {
            $expiresInSeconds = 1800;
        }

        if (
            !is_int($expiresInSeconds) &&
            !is_numeric($expiresInSeconds)
        ) {
            Response::error(
                'expires_in_seconds must be a number.',
                422
            );
        }

        $expiresInSeconds = (int) $expiresInSeconds;

        if (
            $expiresInSeconds < 300 ||
            $expiresInSeconds > 86400
        ) {
            Response::error(
                'expires_in_seconds must be between 300 and 86400.',
                422
            );
        }

        $token = $this->service->createHandoverToken(
            $handoverId,
            $user['id'],
            $expiresInSeconds
        );

        Response::success(
            [
                'token' => $token['token'],
                'token_type' => $token['token_type'],
                'expires_at' => $token['expires_at'],
                'handover_id' => $handoverId,
            ],
            'Secure handover token created. Keep it private.'
        );
    }

    /**
     * Finder grants contact/handover consent using a secure token.
     *
     * No owner authentication is required here because the token
     * is the temporary credential delivered to the finder.
     *
     * POST /api/handovers/{id}/finder-consent
     */
    public function grantFinderConsent(): void
    {
        $handoverId = $this->routeId();

        $token = Request::input('token');

        if (!is_string($token) || trim($token) === '') {
            Response::error(
                'Secure handover token is required.',
                422
            );
        }

        $handover = $this->service->grantFinderConsent(
            $handoverId,
            $token
        );

        Response::success(
            $this->publicHandover($handover),
            'Finder consent recorded successfully.'
        );
    }

    /**
     * Complete the handover.
     *
     * The authenticated owner must also provide the secure
     * handover token.
     *
     * POST /api/handovers/{id}/complete
     */
    public function complete(): void
    {
        $user = AuthMiddleware::requireUser();

        $handoverId = $this->routeId();

        $token = Request::input('token');

        if (!is_string($token) || trim($token) === '') {
            Response::error(
                'Secure handover token is required.',
                422
            );
        }

        $result = $this->service->complete(
            $handoverId,
            $user['id'],
            $token
        );

        Response::success(
            [
                'handover' => $this->publicHandover(
                    $result['handover']
                ),
                'recovery' => $result['recovery'],
            ],
            'Handover completed successfully. Recovery marked as recovered.'
        );
    }

    /**
     * Cancel a handover.
     *
     * POST /api/handovers/{id}/cancel
     */
    public function cancel(): void
    {
        $user = AuthMiddleware::requireUser();

        $handoverId = $this->routeId();

        $handover = $this->service->cancel(
            $handoverId,
            $user['id']
        );

        Response::success(
            $this->publicHandover($handover),
            'Handover cancelled successfully.'
        );
    }

    /**
     * Extract and validate route UUID.
     */
    private function routeId(): string
    {
        $id = Request::routeParam('id');

        if (!is_string($id) || !$this->isUuid($id)) {
            Response::error(
                'Invalid handover ID.',
                422
            );
        }

        return $id;
    }

    /**
     * Remove internal fields from handover responses.
     */
    private function publicHandover(array $handover): array
    {
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

    private function isUuid(string $value): bool
    {
        return preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $value
        ) === 1;
    }

    private function __construct()
    {
    }
}
