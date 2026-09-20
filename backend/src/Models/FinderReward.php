<?php

declare(strict_types=1);

namespace ScanId\Models;

use PDO;
use RuntimeException;

final class FinderReward
{
    public function __construct(
        private readonly PDO $db
    ) {
    }

    /**
     * Create a finder reward for a completed recovery request.
     *
     * The reward is NOT payable immediately.
     * It becomes payable only after SCAN-ID verifies the handover.
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

        if ($amountKes <= 0) {
            throw new RuntimeException(
                'Finder reward amount must be greater than zero.'
            );
        }

        $this->assertRecoveryRequestExists($recoveryRequestId);

        $sql = <<<'SQL'
            INSERT INTO finder_rewards (
                recovery_request_id,
                finder_phone,
                amount_kes,
                status
            )
            VALUES (
                :recovery_request_id,
                :finder_phone,
                :amount_kes,
                'pending'
            )
            ON CONFLICT (recovery_request_id)
            DO UPDATE SET
                finder_phone = EXCLUDED.finder_phone,
                amount_kes = EXCLUDED.amount_kes,
                updated_at = NOW()
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
        SQL;

        $statement = $this->db->prepare($sql);

        $statement->execute([
            'recovery_request_id' => $recoveryRequestId,
            'finder_phone' => $finderPhone,
            'amount_kes' => $amountKes,
        ]);

        $reward = $statement->fetch(PDO::FETCH_ASSOC);

        if ($reward === false) {
            throw new RuntimeException(
                'Finder reward could not be created.'
            );
        }

        return $reward;
    }

    /**
     * Find a reward by ID.
     */
    public function findById(string $id): ?array
    {
        $id = trim($id);

        if ($id === '') {
            return null;
        }

        $statement = $this->db->prepare(
            <<<'SQL'
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
                WHERE id = :id
                LIMIT 1
            SQL
        );

        $statement->execute([
            'id' => $id,
        ]);

        $reward = $statement->fetch(PDO::FETCH_ASSOC);

        return $reward === false ? null : $reward;
    }

    /**
     * Find the reward belonging to a recovery request.
     */
    public function findByRecoveryRequest(
        string $recoveryRequestId
    ): ?array {
        $recoveryRequestId = trim($recoveryRequestId);

        if ($recoveryRequestId === '') {
            return null;
        }

        $statement = $this->db->prepare(
            <<<'SQL'
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
                WHERE recovery_request_id = :recovery_request_id
                LIMIT 1
            SQL
        );

        $statement->execute([
            'recovery_request_id' => $recoveryRequestId,
        ]);

        $reward = $statement->fetch(PDO::FETCH_ASSOC);

        return $reward === false ? null : $reward;
    }

    /**
     * Mark reward as payable after verified successful handover.
     */
    public function markPayable(string $id): array
    {
        $id = trim($id);

        if ($id === '') {
            throw new RuntimeException(
                'Finder reward ID is required.'
            );
        }

        $statement = $this->db->prepare(
            <<<'SQL'
                UPDATE finder_rewards
                SET
                    status = 'payable',
                    updated_at = NOW()
                WHERE id = :id
                  AND status = 'pending'
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
            SQL
        );

        $statement->execute([
            'id' => $id,
        ]);

        $reward = $statement->fetch(PDO::FETCH_ASSOC);

        if ($reward === false) {
            $existing = $this->findById($id);

            if ($existing === null) {
                throw new RuntimeException(
                    'Finder reward not found.'
                );
            }

            if ($existing['status'] === 'payable') {
                return $existing;
            }

            if ($existing['status'] === 'paid') {
                return $existing;
            }

            throw new RuntimeException(
                'Finder reward cannot be marked payable from its current status.'
            );
        }

        return $reward;
    }

    /**
     * Mark reward as successfully paid.
     *
     * payout_reference must be unique so the same payout
     * cannot accidentally be recorded twice.
     */
    public function markPaid(
        string $id,
        string $payoutReference
    ): array {
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

        $statement = $this->db->prepare(
            <<<'SQL'
                UPDATE finder_rewards
                SET
                    status = 'paid',
                    payout_reference = :payout_reference,
                    paid_at = COALESCE(paid_at, NOW()),
                    updated_at = NOW()
                WHERE id = :id
                  AND status = 'payable'
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
            SQL
        );

        $statement->execute([
            'id' => $id,
            'payout_reference' => $payoutReference,
        ]);

        $reward = $statement->fetch(PDO::FETCH_ASSOC);

        if ($reward !== false) {
            return $reward;
        }

        $existing = $this->findById($id);

        if ($existing === null) {
            throw new RuntimeException(
                'Finder reward not found.'
            );
        }

        /*
         * Idempotency:
         * If the reward was already marked paid with the same
         * payout reference, return the existing record instead
         * of creating another payment.
         */
        if (
            $existing['status'] === 'paid'
            && $existing['payout_reference'] === $payoutReference
        ) {
            return $existing;
        }

        if ($existing['status'] === 'paid') {
            throw new RuntimeException(
                'Finder reward has already been paid.'
            );
        }

        throw new RuntimeException(
            'Finder reward is not currently payable.'
        );
    }

    /**
     * Mark a reward payout as failed.
     */
    public function markFailed(string $id): array
    {
        $id = trim($id);

        if ($id === '') {
            throw new RuntimeException(
                'Finder reward ID is required.'
            );
        }

        $statement = $this->db->prepare(
            <<<'SQL'
                UPDATE finder_rewards
                SET
                    status = 'failed',
                    updated_at = NOW()
                WHERE id = :id
                  AND status IN ('pending', 'payable')
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
            SQL
        );

        $statement->execute([
            'id' => $id,
        ]);

        $reward = $statement->fetch(PDO::FETCH_ASSOC);

        if ($reward === false) {
            $existing = $this->findById($id);

            if ($existing === null) {
                throw new RuntimeException(
                    'Finder reward not found.'
                );
            }

            if ($existing['status'] === 'failed') {
                return $existing;
            }

            if ($existing['status'] === 'paid') {
                throw new RuntimeException(
                    'A paid finder reward cannot be marked as failed.'
                );
            }

            throw new RuntimeException(
                'Finder reward cannot be marked as failed from its current status.'
            );
        }

        return $reward;
    }

    /**
     * Check whether a reward has already been paid.
     */
    public function isPaid(string $id): bool
    {
        $reward = $this->findById($id);

        if ($reward === null) {
            return false;
        }

        return $reward['status'] === 'paid';
    }

    /**
     * Get rewards by status.
     */
    public function findByStatus(
        string $status,
        int $limit = 100
    ): array {
        $allowedStatuses = [
            'pending',
            'payable',
            'paid',
            'failed',
            'cancelled',
        ];

        if (!in_array($status, $allowedStatuses, true)) {
            throw new RuntimeException(
                'Invalid finder reward status.'
            );
        }

        $limit = max(1, min($limit, 500));

        $sql = sprintf(
            <<<'SQL'
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
                WHERE status = :status
                ORDER BY created_at ASC
                LIMIT %d
            SQL,
            $limit
        );

        $statement = $this->db->prepare($sql);

        $statement->execute([
            'status' => $status,
        ]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Confirm that the recovery request exists.
     */
    private function assertRecoveryRequestExists(
        string $recoveryRequestId
    ): void {
        $statement = $this->db->prepare(
            <<<'SQL'
                SELECT 1
                FROM recovery_requests
                WHERE id = :id
                LIMIT 1
            SQL
        );

        $statement->execute([
            'id' => $recoveryRequestId,
        ]);

        if ($statement->fetchColumn() === false) {
            throw new RuntimeException(
                'Recovery request not found.'
            );
        }
    }

    private function __clone(): void
    {
    }
}
