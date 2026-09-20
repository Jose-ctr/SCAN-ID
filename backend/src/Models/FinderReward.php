<?php

declare(strict_types=1);

namespace ScanId\Models;

use PDO;
use RuntimeException;

final class FinderReward
{
    public function __construct(
        private readonly PDO $database
    ) {
    }

    /**
     * Create a finder reward for a recovery request.
     *
     * The reward is not paid at creation time.
     * It remains pending until the handover is verified.
     */
    public function create(
        string $recoveryRequestId,
        string $finderPhone,
        int $amountKes = 150
    ): array {
        $recoveryRequestId = trim($recoveryRequestId);
        $finderPhone = trim($finderPhone);

        if ($recoveryRequestId === '') {
            throw new RuntimeException(
                'Recovery request ID is required.'
            );
        }

        if ($finderPhone === '') {
            throw new RuntimeException(
                'Finder phone number is required.'
            );
        }

        if (strlen($finderPhone) > 30) {
            throw new RuntimeException(
                'Finder phone number is too long.'
            );
        }

        if ($amountKes <= 0) {
            throw new RuntimeException(
                'Finder reward must be greater than zero.'
            );
        }

        if (!$this->recoveryRequestExists($recoveryRequestId)) {
            throw new RuntimeException(
                'Recovery request not found.'
            );
        }

        /*
         * Prevent duplicate rewards for the same recovery.
         */
        $existing = $this->findByRecoveryRequest(
            $recoveryRequestId
        );

        if ($existing !== null) {
            return $existing;
        }

        $statement = $this->database->prepare(
            '
            INSERT INTO finder_rewards (
                recovery_request_id,
                finder_phone,
                amount_kes,
                status
            )
            VALUES (
                CAST(:recovery_request_id AS UUID),
                :finder_phone,
                :amount_kes,
                \'pending\'
            )
            RETURNING
                id,
                recovery_request_id,
                finder_phone,
                amount_kes,
                status,
                payout_reference,
                paid_at,
                created_at,
                updated_at
            '
        );

        $statement->execute([
            'recovery_request_id' => $recoveryRequestId,
            'finder_phone' => $finderPhone,
            'amount_kes' => $amountKes,
        ]);

        $reward = $statement->fetch();

        if (!is_array($reward)) {
            throw new RuntimeException(
                'Unable to create finder reward.'
            );
        }

        return $reward;
    }

    /**
     * Find a reward by ID.
     */
    public function findById(
        string $id
    ): ?array {
        $id = trim($id);

        if ($id === '') {
            return null;
        }

        $statement = $this->database->prepare(
            '
            SELECT
                id,
                recovery_request_id,
                finder_phone,
                amount_kes,
                status,
                payout_reference,
                paid_at,
                created_at,
                updated_at
            FROM finder_rewards
            WHERE id = CAST(:id AS UUID)
            LIMIT 1
            '
        );

        $statement->execute([
            'id' => $id,
        ]);

        $reward = $statement->fetch();

        return is_array($reward)
            ? $reward
            : null;
    }

    /**
     * Find the reward belonging to a recovery request.
     */
    public function findByRecoveryRequest(
        string $recoveryRequestId
    ): ?array {
        $recoveryRequestId = trim(
            $recoveryRequestId
        );

        if ($recoveryRequestId === '') {
            return null;
        }

        $statement = $this->database->prepare(
            '
            SELECT
                id,
                recovery_request_id,
                finder_phone,
                amount_kes,
                status,
                payout_reference,
                paid_at,
                created_at,
                updated_at
            FROM finder_rewards
            WHERE recovery_request_id =
                CAST(:recovery_request_id AS UUID)
            LIMIT 1
            '
        );

        $statement->execute([
            'recovery_request_id' => $recoveryRequestId,
        ]);

        $reward = $statement->fetch();

        return is_array($reward)
            ? $reward
            : null;
    }

    /**
     * Make a pending reward eligible for payout.
     *
     * This should only be called after verified handover.
     */
    public function markPayable(
        string $id
    ): ?array {
        $id = trim($id);

        if ($id === '') {
            throw new RuntimeException(
                'Finder reward ID is required.'
            );
        }

        $statement = $this->database->prepare(
            '
            UPDATE finder_rewards
            SET
                status = \'payable\',
                updated_at = NOW()
            WHERE id = CAST(:id AS UUID)
              AND status = \'pending\'
            RETURNING
                id,
                recovery_request_id,
                finder_phone,
                amount_kes,
                status,
                payout_reference,
                paid_at,
                created_at,
                updated_at
            '
        );

        $statement->execute([
            'id' => $id,
        ]);

        $reward = $statement->fetch();

        return is_array($reward)
            ? $reward
            : null;
    }

    /**
     * Mark the reward as paid.
     *
     * payout_reference must come from the verified payment provider.
     */
    public function markPaid(
        string $id,
        string $payoutReference
    ): ?array {
        $id = trim($id);
        $payoutReference = trim($payoutReference);

        if ($id === '') {
            throw new RuntimeException(
                'Finder reward ID is required.'
            );
        }

        if ($payoutReference === '') {
            throw new RuntimeException(
                'Payout reference is required.'
            );
        }

        if (strlen($payoutReference) > 100) {
            throw new RuntimeException(
                'Payout reference is too long.'
            );
        }

        $statement = $this->database->prepare(
            '
            UPDATE finder_rewards
            SET
                status = \'paid\',
                payout_reference = :payout_reference,
                paid_at = COALESCE(paid_at, NOW()),
                updated_at = NOW()
            WHERE id = CAST(:id AS UUID)
              AND status = \'payable\'
            RETURNING
                id,
                recovery_request_id,
                finder_phone,
                amount_kes,
                status,
                payout_reference,
                paid_at,
                created_at,
                updated_at
            '
        );

        $statement->execute([
            'id' => $id,
            'payout_reference' => $payoutReference,
        ]);

        $reward = $statement->fetch();

        return is_array($reward)
            ? $reward
            : null;
    }

    /**
     * Mark a reward as failed.
     */
    public function markFailed(
        string $id
    ): ?array {
        $id = trim($id);

        if ($id === '') {
            throw new RuntimeException(
                'Finder reward ID is required.'
            );
        }

        $statement = $this->database->prepare(
            '
            UPDATE finder_rewards
            SET
                status = \'failed\',
                updated_at = NOW()
            WHERE id = CAST(:id AS UUID)
              AND status IN (\'pending\', \'payable\')
            RETURNING
                id,
                recovery_request_id,
                finder_phone,
                amount_kes,
                status,
                payout_reference,
                paid_at,
                created_at,
                updated_at
            '
        );

        $statement->execute([
            'id' => $id,
        ]);

        $reward = $statement->fetch();

        return is_array($reward)
            ? $reward
            : null;
    }

    /**
     * Check whether the reward has already been paid.
     */
    public function isPaid(
        string $id
    ): bool {
        $reward = $this->findById($id);

        return $reward !== null
            && $reward['status'] === 'paid';
    }

    /**
     * Check whether a recovery request exists.
     */
    private function recoveryRequestExists(
        string $recoveryRequestId
    ): bool {
        $statement = $this->database->prepare(
            '
            SELECT 1
            FROM recovery_requests
            WHERE id = CAST(:id AS UUID)
            LIMIT 1
            '
        );

        $statement->execute([
            'id' => $recoveryRequestId,
        ]);

        return $statement->fetchColumn() !== false;
    }
}
