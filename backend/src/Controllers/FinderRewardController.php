<?php

declare(strict_types=1);

namespace ScanId\Controllers;

use ScanId\Http\AuthMiddleware;
use ScanId\Http\Request;
use ScanId\Http\Response;
use ScanId\Models\FinderReward;
use ScanId\Models\RecoveryRequest;
use Throwable;

final class FinderRewardController
{
    /**
     * Show a finder reward.
     *
     * Finder contact details and payout references are only exposed
     * to an authenticated user who owns the related recovery request.
     */
    public static function show(): void
    {
        try {
            $user = AuthMiddleware::requireUser();

            $id = self::routeId();

            $reward = FinderReward::findById($id);

            if ($reward === null) {
                Response::error(
                    'Finder reward not found.',
                    404
                );
            }

            $recoveryRequestId = $reward['recovery_request_id'] ?? null;

            if (
                !is_string($recoveryRequestId)
                || trim($recoveryRequestId) === ''
            ) {
                Response::error(
                    'Finder reward recovery request is invalid.',
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

            if (
                isset($recovery['owner_user_id'])
                && (string) $recovery['owner_user_id']
                    !== (string) $user['id']
            ) {
                Response::error(
                    'You are not allowed to access this finder reward.',
                    403
                );
            }

            Response::success(
                [
                    'reward' => self::publicReward($reward),
                ]
            );
        } catch (Throwable $exception) {
            self::handleException($exception);
        }
    }

    /**
     * List finder rewards associated with the authenticated user's
     * recovery requests.
     */
    public static function mine(): void
    {
        try {
            $user = AuthMiddleware::requireUser();

            $recoveryRequests = RecoveryRequest::findByOwnerUser(
                (string) $user['id']
            );

            $rewards = [];

            foreach ($recoveryRequests as $recovery) {
                $recoveryRequestId = $recovery['id'] ?? null;

                if (!is_string($recoveryRequestId)) {
                    continue;
                }

                $reward = FinderReward::findByRecoveryRequest(
                    $recoveryRequestId
                );

                if ($reward !== null) {
                    $rewards[] = self::publicReward($reward);
                }
            }

            Response::success(
                [
                    'rewards' => $rewards,
                    'count' => count($rewards),
                ]
            );
        } catch (Throwable $exception) {
            self::handleException($exception);
        }
    }

    /**
     * Return a reward to payable status after the recovery workflow
     * has verified the successful handover.
     *
     * This endpoint is intentionally restricted to authenticated
     * users and does not itself transfer money.
     */
    public static function makePayable(): void
    {
        try {
            $user = AuthMiddleware::requireUser();

            $id = self::routeId();

            $reward = FinderReward::findById($id);

            if ($reward === null) {
                Response::error(
                    'Finder reward not found.',
                    404
                );
            }

            $recoveryRequestId = $reward['recovery_request_id'] ?? null;

            if (
                !is_string($recoveryRequestId)
                || trim($recoveryRequestId) === ''
            ) {
                Response::error(
                    'Finder reward recovery request is invalid.',
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

            if (
                isset($recovery['owner_user_id'])
                && (string) $recovery['owner_user_id']
                    !== (string) $user['id']
            ) {
                Response::error(
                    'You are not allowed to manage this finder reward.',
                    403
                );
            }

            /*
             * A reward must only become payable after the handover
             * service verifies successful document handover.
             *
             * The actual verification will be implemented in the
             * handover service. This controller therefore does not
             * bypass that workflow.
             */
            if (($recovery['status'] ?? '') !== 'completed') {
                Response::error(
                    'Finder reward cannot become payable before recovery is completed.',
                    409
                );
            }

            $updated = FinderReward::markPayable($id);

            Response::success(
                [
                    'reward' => self::publicReward($updated),
                ],
                'Finder reward is now payable.'
            );
        } catch (Throwable $exception) {
            self::handleException($exception);
        }
    }

    /**
     * Return only safe reward information.
     */
    private static function publicReward(array $reward): array
    {
        return [
            'id' => $reward['id'] ?? null,
            'recovery_request_id' => $reward['recovery_request_id'] ?? null,
            'amount_kes' => (int) ($reward['amount_kes'] ?? 150),
            'status' => $reward['status'] ?? null,
            'paid_at' => $reward['paid_at'] ?? null,
            'created_at' => $reward['created_at'] ?? null,
            'updated_at' => $reward['updated_at'] ?? null,
        ];
    }

    private static function routeId(): string
    {
        $id = Request::input('id');

        if (!is_string($id) || trim($id) === '') {
            Response::error(
                'Finder reward ID is required.',
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
                'Invalid finder reward ID.',
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
            'Unable to process the finder reward request.',
            400
        );
    }

    private function __construct()
    {
    }
}
