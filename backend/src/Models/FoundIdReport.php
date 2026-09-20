<?php

declare(strict_types=1);

namespace ScanId\Models;

use DateTimeImmutable;
use PDO;
use ScanId\Config\Database;
use RuntimeException;

final class FoundIdReport
{
    /**
     * Create a new found-ID report.
     *
     * @param array{
     *     id_type: string,
     *     id_number: string,
     *     location_found: string,
     *     reporter_phone: string
     * } $data
     *
     * @return array<string, mixed>
     */
    public static function create(array $data): array
    {
        $pdo = Database::connection();

        $sql = <<<'SQL'
            INSERT INTO found_id_reports (
                id_type,
                id_number,
                location_found,
                reporter_phone,
                status,
                created_at,
                updated_at
            )
            VALUES (
                :id_type,
                :id_number,
                :location_found,
                :reporter_phone,
                :status,
                :created_at,
                :updated_at
            )
            RETURNING
                id,
                id_type,
                id_number,
                location_found,
                reporter_phone,
                status,
                created_at,
                updated_at
        SQL;

        $statement = $pdo->prepare($sql);

        $now = new DateTimeImmutable('now');

        $statement->execute([
            'id_type' => $data['id_type'],
            'id_number' => $data['id_number'],
            'location_found' => $data['location_found'],
            'reporter_phone' => $data['reporter_phone'],
            'status' => 'pending',
            'created_at' => $now->format('Y-m-d H:i:sP'),
            'updated_at' => $now->format('Y-m-d H:i:sP'),
        ]);

        $report = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($report)) {
            throw new RuntimeException(
                'Unable to create found-ID report.'
            );
        }

        return $report;
    }

    /**
     * Find a report by its database ID.
     *
     * @return array<string, mixed>|null
     */
    public static function findById(
        int $id
    ): ?array {
        $pdo = Database::connection();

        $statement = $pdo->prepare(
            <<<'SQL'
                SELECT
                    id,
                    id_type,
                    id_number,
                    location_found,
                    reporter_phone,
                    status,
                    created_at,
                    updated_at
                FROM found_id_reports
                WHERE id = :id
                LIMIT 1
            SQL
        );

        $statement->execute([
            'id' => $id,
        ]);

        $report = $statement->fetch(
            PDO::FETCH_ASSOC
        );

        return is_array($report)
            ? $report
            : null;
    }

    /**
     * Update the status of a report.
     */
    public static function updateStatus(
        int $id,
        string $status
    ): bool {
        $allowedStatuses = [
            'pending',
            'owner_notified',
            'recovery_pending',
            'recovered',
            'cancelled',
        ];

        if (!in_array(
            $status,
            $allowedStatuses,
            true
        )) {
            throw new RuntimeException(
                'Invalid found-ID report status.'
            );
        }

        $pdo = Database::connection();

        $statement = $pdo->prepare(
            <<<'SQL'
                UPDATE found_id_reports
                SET
                    status = :status,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id
            SQL
        );

        $statement->execute([
            'id' => $id,
            'status' => $status,
        ]);

        return $statement->rowCount() > 0;
    }

    /**
     * Prevent accidental instantiation.
     */
    private function __construct()
    {
    }
}
