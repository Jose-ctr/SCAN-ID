<?php

declare(strict_types=1);

namespace ScanId\Models;

use PDO;
use RuntimeException;

final class Handover
{
    public function __construct(
        private readonly PDO $database
    ) {
    }

    /**
     * Create a handover record for a recovery request.
     */
    public function create(
        string $recoveryRequestId,
        string $safeLocation,
        bool $finderConsent = false,
        ?string $scheduledAt = null,
        ?string $notes = null
    ): array {
        $recoveryRequestId = trim(
            $recoveryRequestId
        );
        $safeLocation = trim($safeLocation);

        $notes = $notes !== null
            ? trim($notes)
            : null;

        if ($recoveryRequestId === '') {
            throw new RuntimeException(
                'Recovery request ID is required.'
            );
        }

        if ($safeLocation === '') {
            throw new RuntimeException(
                'Safe handover location is required.'
            );
        }

        if (mb_strlen($safeLocation) > 255) {
            throw new RuntimeException(
                'Safe handover location is too long.'
            );
        }

        if (
            $notes !== null
            && mb_strlen($notes) > 2000
        ) {
            throw new RuntimeException(
                'Handover notes are too long.'
            );
        }

        $this->assertRecoveryRequestExists(
            $recoveryRequestId
        );

        $statement = $this->database->prepare(
            <<<'SQL'
            INSERT INTO handovers (
                recovery_request_id,
                finder_consent,
                safe_location,
                scheduled_at,
                status,
                notes
            )
            VALUES (
                :recovery_request_id,
                :finder_consent,
                :safe_location,
                :scheduled_at,
                CASE
                    WHEN :scheduled_at IS NULL
                    THEN 'pending'
                    ELSE 'scheduled'
                END,
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
            'finder_consent' => $finderConsent,
            'safe_location' => $safeLocation,
            'scheduled_at' => $scheduledAt !== null
                && $scheduledAt !== ''
                ? $scheduledAt
                : null,
            'notes' => $notes,
        ]);

        $handover = $statement->fetch();

        if (!is_array($handover)) {
            throw new RuntimeException(
                'Unable to create handover record.'
            );
        }

        return $handover;
    }

    /**
     * Find a handover by ID.
     */
    public function findById(
        string $id
    ): ?array {
        $id = trim($id);

        if ($id === '') {
            return null;
        }

        $statement = $this->database->prepare(
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

        $handover = $statement->fetch();

        return is_array($handover)
            ? $handover
            : null;
    }

    /**
     * Find the handover belonging to a recovery request.
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

        $handover = $statement->fetch();

        return is_array($handover)
            ? $handover
            : null;
    }

    /**
     * Record finder consent.
     */
    public function grantFinderConsent(
        string $id
    ): bool {
        $id = trim($id);

        if ($id === '') {
            throw new RuntimeException(
                'Handover ID is required.'
            );
        }

        $statement = $this->database->prepare(
            <<<'SQL'
            UPDATE handovers
            SET
                finder_consent = TRUE,
                updated_at = NOW()
            WHERE id = :id
            SQL
        );

        $statement->execute([
            'id' => $id,
        ]);

        return $statement->rowCount() > 0;
    }

    /**
     * Schedule a handover.
     */
    public function schedule(
        string $id,
        string $scheduledAt
    ): bool {
        $id = trim($id);
        $scheduledAt = trim($scheduledAt);

        if ($id === '') {
            throw new RuntimeException(
                'Handover ID is required.'
            );
        }

        if ($scheduledAt === '') {
            throw new RuntimeException(
                'Handover schedule is required.'
            );
        }

        $statement = $this->database->prepare(
            <<<'SQL'
            UPDATE handovers
            SET
                scheduled_at = CAST(
                    :scheduled_at AS TIMESTAMPTZ
                ),
                status = 'scheduled',
                updated_at = NOW()
            WHERE id = :id
              AND status = 'pending'
            SQL
        );

        $statement->execute([
            'id' => $id,
            'scheduled_at' => $scheduledAt,
        ]);

        return $statement->rowCount() > 0;
    }

    /**
     * Complete the physical handover.
     */
    public function complete(
        string $id,
        ?string $notes = null
    ): bool {
        $id = trim($id);

        $notes = $notes !== null
            ? trim($notes)
            : null;

        if ($id === '') {
            throw new RuntimeException(
                'Handover ID is required.'
            );
        }

        if (
            $notes !== null
            && mb_strlen($notes) > 2000
        ) {
            throw new RuntimeException(
                'Handover notes are too long.'
            );
        }

        $statement = $this->database->prepare(
            <<<'SQL'
            UPDATE handovers
            SET
                status = 'completed',
                completed_at = COALESCE(
                    completed_at,
                    NOW()
                ),
                notes = COALESCE(
                    :notes,
                    notes
                ),
                updated_at = NOW()
            WHERE id = :id
              AND status IN (
                  'pending',
                  'scheduled'
              )
            SQL
        );

        $statement->execute([
            'id' => $id,
            'notes' => $notes,
        ]);

        return $statement->rowCount() > 0;
    }

    /**
     * Cancel a handover.
     */
    public function cancel(
        string $id
    ): bool {
        $id = trim($id);

        if ($id === '') {
            throw new RuntimeException(
                'Handover ID is required.'
            );
        }

        $statement = $this->database->prepare(
            <<<'SQL'
            UPDATE handovers
            SET
                status = 'cancelled',
                updated_at = NOW()
            WHERE id = :id
              AND status IN (
                  'pending',
                  'scheduled'
              )
            SQL
        );

        $statement->execute([
            'id' => $id,
        ]);

        return $statement->rowCount() > 0;
    }

    /**
     * Confirm that the recovery request exists.
     */
    private function assertRecoveryRequestExists(
        string $id
    ): void {
        $statement = $this->database->prepare(
            <<<'SQL'
            SELECT 1
            FROM recovery_requests
            WHERE id = :id
            LIMIT 1
            SQL
        );

        $statement->execute([
            'id' => $id,
        ]);

        if ($statement->fetchColumn() === false) {
            throw new RuntimeException(
                'Recovery request was not found.'
            );
        }
    }
}
