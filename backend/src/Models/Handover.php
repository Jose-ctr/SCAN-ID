<?php

declare(strict_types=1);

namespace ScanId\Models;

use PDO;
use RuntimeException;

final class Handover
{
    public function __construct(
        private readonly PDO $db
    ) {
    }

    /**
     * Create a handover record for a recovery request.
     *
     * One recovery request can have only one handover.
     */
    public function create(
        string $recoveryRequestId,
        ?string $safeLocation = null,
        ?string $scheduledAt = null,
        ?string $notes = null
    ): array {
        $this->assertRecoveryRequestExists($recoveryRequestId);

        $existing = $this->findByRecoveryRequest($recoveryRequestId);

        if ($existing !== null) {
            return $existing;
        }

        $statement = $this->db->prepare(
            <<<'SQL'
                INSERT INTO handovers (
                    recovery_request_id,
                    safe_location,
                    scheduled_at,
                    status,
                    notes
                )
                VALUES (
                    :recovery_request_id,
                    :safe_location,
                    :scheduled_at,
                    'pending',
                    :notes
                )
                RETURNING
                    id,
                    recovery_request_id,
                    finder_consent,
                    safe_location,
                    scheduled_at,
                    completed_at,
                    status,
                    notes,
                    created_at,
                    updated_at
            SQL
        );

        $statement->execute([
            'recovery_request_id' => $recoveryRequestId,
            'safe_location' => $this->nullableString($safeLocation),
            'scheduled_at' => $this->nullableString($scheduledAt),
            'notes' => $this->nullableString($notes),
        ]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            throw new RuntimeException(
                'Failed to create handover.'
            );
        }

        return $row;
    }

    /**
     * Find a handover by ID.
     */
    public function findById(
        string $id
    ): ?array {
        $statement = $this->db->prepare(
            <<<'SQL'
                SELECT
                    id,
                    recovery_request_id,
                    finder_consent,
                    safe_location,
                    scheduled_at,
                    completed_at,
                    status,
                    notes,
                    created_at,
                    updated_at
                FROM handovers
                WHERE id = :id
                LIMIT 1
            SQL
        );

        $statement->execute([
            'id' => $id,
        ]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * Find the handover belonging to a recovery request.
     */
    public function findByRecoveryRequest(
        string $recoveryRequestId
    ): ?array {
        $statement = $this->db->prepare(
            <<<'SQL'
                SELECT
                    id,
                    recovery_request_id,
                    finder_consent,
                    safe_location,
                    scheduled_at,
                    completed_at,
                    status,
                    notes,
                    created_at,
                    updated_at
                FROM handovers
                WHERE recovery_request_id = :recovery_request_id
                LIMIT 1
            SQL
        );

        $statement->execute([
            'recovery_request_id' => $recoveryRequestId,
        ]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * Record finder consent for the handover.
     */
    public function grantFinderConsent(
        string $id
    ): bool {
        return $this->updateFinderConsent($id, true);
    }

    /**
     * Revoke finder consent.
     */
    public function revokeFinderConsent(
        string $id
    ): bool {
        return $this->updateFinderConsent($id, false);
    }

    /**
     * Check finder consent.
     */
    public function hasFinderConsent(
        string $id
    ): bool {
        $statement = $this->db->prepare(
            <<<'SQL'
                SELECT finder_consent
                FROM handovers
                WHERE id = :id
                LIMIT 1
            SQL
        );

        $statement->execute([
            'id' => $id,
        ]);

        $value = $statement->fetchColumn();

        if ($value === false) {
            return false;
        }

        return filter_var(
            $value,
            FILTER_VALIDATE_BOOLEAN
        );
    }

    /**
     * Schedule a handover.
     */
    public function schedule(
        string $id,
        string $scheduledAt,
        ?string $safeLocation = null,
        ?string $notes = null
    ): bool {
        $statement = $this->db->prepare(
            <<<'SQL'
                UPDATE handovers
                SET
                    scheduled_at = :scheduled_at,
                    safe_location = COALESCE(
                        :safe_location,
                        safe_location
                    ),
                    notes = COALESCE(
                        :notes,
                        notes
                    ),
                    status = 'scheduled'
                WHERE id = :id
                  AND status IN ('pending', 'scheduled')
                RETURNING id
            SQL
        );

        $statement->execute([
            'id' => $id,
            'scheduled_at' => $scheduledAt,
            'safe_location' => $this->nullableString($safeLocation),
            'notes' => $this->nullableString($notes),
        ]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * Mark the handover as completed.
     */
    public function complete(
        string $id,
        ?string $notes = null
    ): bool {
        $statement = $this->db->prepare(
            <<<'SQL'
                UPDATE handovers
                SET
                    completed_at = COALESCE(
                        completed_at,
                        NOW()
                    ),
                    status = 'completed',
                    notes = COALESCE(
                        :notes,
                        notes
                    )
                WHERE id = :id
                  AND status IN ('pending', 'scheduled')
                RETURNING id
            SQL
        );

        $statement->execute([
            'id' => $id,
            'notes' => $this->nullableString($notes),
        ]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * Cancel a handover.
     */
    public function cancel(
        string $id,
        ?string $notes = null
    ): bool {
        $statement = $this->db->prepare(
            <<<'SQL'
                UPDATE handovers
                SET
                    status = 'cancelled',
                    notes = COALESCE(
                        :notes,
                        notes
                    )
                WHERE id = :id
                  AND status IN ('pending', 'scheduled')
                RETURNING id
            SQL
        );

        $statement->execute([
            'id' => $id,
            'notes' => $this->nullableString($notes),
        ]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * Update the safe handover location.
     */
    public function updateSafeLocation(
        string $id,
        ?string $safeLocation
    ): bool {
        $statement = $this->db->prepare(
            <<<'SQL'
                UPDATE handovers
                SET safe_location = :safe_location
                WHERE id = :id
                  AND status IN ('pending', 'scheduled')
                RETURNING id
            SQL
        );

        $statement->execute([
            'id' => $id,
            'safe_location' => $this->nullableString($safeLocation),
        ]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * Update notes.
     */
    public function updateNotes(
        string $id,
        ?string $notes
    ): bool {
        $statement = $this->db->prepare(
            <<<'SQL'
                UPDATE handovers
                SET notes = :notes
                WHERE id = :id
                  AND status IN ('pending', 'scheduled')
                RETURNING id
            SQL
        );

        $statement->execute([
            'id' => $id,
            'notes' => $this->nullableString($notes),
        ]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * Check whether the handover has been completed.
     */
    public function isCompleted(
        string $recoveryRequestId
    ): bool {
        $statement = $this->db->prepare(
            <<<'SQL'
                SELECT 1
                FROM handovers
                WHERE recovery_request_id = :recovery_request_id
                  AND status = 'completed'
                LIMIT 1
            SQL
        );

        $statement->execute([
            'recovery_request_id' => $recoveryRequestId,
        ]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * Ensure the recovery request exists.
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

    /**
     * Update finder consent.
     */
    private function updateFinderConsent(
        string $id,
        bool $consent
    ): bool {
        $statement = $this->db->prepare(
            <<<'SQL'
                UPDATE handovers
                SET finder_consent = :finder_consent
                WHERE id = :id
                RETURNING id
            SQL
        );

        $statement->execute([
            'id' => $id,
            'finder_consent' => $consent,
        ]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * Convert empty strings to NULL.
     */
    private function nullableString(
        ?string $value
    ): ?string {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
