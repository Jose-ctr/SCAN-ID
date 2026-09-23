<?php

declare(strict_types=1);

namespace ScanId\Models;

use PDO;
use RuntimeException;

final class SmsNotification
{
    private const TABLE = 'sms_notifications';

    private const PROVIDER = 'africastalking';

    private const STATUSES = [
        'queued',
        'sent',
        'delivered',
        'failed',
    ];

    public function __construct(
        private readonly PDO $db
    ) {
    }

    /**
     * Create a queued SMS notification.
     */
    public function create(
        string $recipientPhone,
        string $message,
        ?string $recoveryRequestId = null
    ): array {
        $phone = self::normalizePhone($recipientPhone);

        $message = trim($message);

        if ($message === '') {
            throw new RuntimeException('SMS message cannot be empty.');
        }

        if (mb_strlen($message) > 1600) {
            throw new RuntimeException('SMS message is too long.');
        }

        if (
            $recoveryRequestId !== null
            && !self::isUuid($recoveryRequestId)
        ) {
            throw new RuntimeException('Invalid recovery request ID.');
        }

        $statement = $this->db->prepare(
            'INSERT INTO ' . self::TABLE . ' (
                recovery_request_id,
                recipient_phone,
                message,
                provider,
                status
            )
            VALUES (
                :recovery_request_id,
                :recipient_phone,
                :message,
                :provider,
                :status
            )
            RETURNING
                id,
                recovery_request_id,
                recipient_phone,
                message,
                provider,
                provider_message_id,
                status,
                sent_at,
                delivered_at,
                created_at'
        );

        $statement->execute([
            ':recovery_request_id' => $recoveryRequestId,
            ':recipient_phone' => $phone,
            ':message' => $message,
            ':provider' => self::PROVIDER,
            ':status' => 'queued',
        ]);

        $notification = $statement->fetch(PDO::FETCH_ASSOC);

        if ($notification === false) {
            throw new RuntimeException(
                'Failed to create SMS notification.'
            );
        }

        return $notification;
    }

    /**
     * Find an SMS notification by ID.
     */
    public function findById(string $id): ?array
    {
        if (!self::isUuid($id)) {
            return null;
        }

        $statement = $this->db->prepare(
            'SELECT
                id,
                recovery_request_id,
                recipient_phone,
                message,
                provider,
                provider_message_id,
                status,
                sent_at,
                delivered_at,
                created_at
             FROM ' . self::TABLE . '
             WHERE id = :id
             LIMIT 1'
        );

        $statement->execute([
            ':id' => $id,
        ]);

        $notification = $statement->fetch(PDO::FETCH_ASSOC);

        return $notification === false
            ? null
            : $notification;
    }

    /**
     * Find notifications belonging to a recovery request.
     */
    public function findByRecoveryRequest(
        string $recoveryRequestId
    ): array {
        if (!self::isUuid($recoveryRequestId)) {
            throw new RuntimeException(
                'Invalid recovery request ID.'
            );
        }

        $statement = $this->db->prepare(
            'SELECT
                id,
                recovery_request_id,
                recipient_phone,
                message,
                provider,
                provider_message_id,
                status,
                sent_at,
                delivered_at,
                created_at
             FROM ' . self::TABLE . '
             WHERE recovery_request_id = :recovery_request_id
             ORDER BY created_at DESC'
        );

        $statement->execute([
            ':recovery_request_id' => $recoveryRequestId,
        ]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Mark an SMS as sent.
     */
    public function markSent(
        string $id,
        ?string $providerMessageId = null
    ): bool {
        if (!self::isUuid($id)) {
            return false;
        }

        $providerMessageId = $providerMessageId !== null
            ? trim($providerMessageId)
            : null;

        if (
            $providerMessageId !== null
            && $providerMessageId === ''
        ) {
            $providerMessageId = null;
        }

        $statement = $this->db->prepare(
            'UPDATE ' . self::TABLE . '
             SET
                status = :status,
                provider_message_id = COALESCE(
                    :provider_message_id,
                    provider_message_id
                ),
                sent_at = COALESCE(sent_at, NOW())
             WHERE id = :id
             AND status IN (\'queued\', \'sent\')'
        );

        $statement->execute([
            ':status' => 'sent',
            ':provider_message_id' => $providerMessageId,
            ':id' => $id,
        ]);

        return $statement->rowCount() > 0;
    }

    /**
     * Mark an SMS as delivered.
     */
    public function markDelivered(string $id): bool
    {
        if (!self::isUuid($id)) {
            return false;
        }

        $statement = $this->db->prepare(
            'UPDATE ' . self::TABLE . '
             SET
                status = :status,
                delivered_at = COALESCE(
                    delivered_at,
                    NOW()
                ),
                sent_at = COALESCE(
                    sent_at,
                    NOW()
                )
             WHERE id = :id
             AND status IN (\'queued\', \'sent\', \'delivered\')'
        );

        $statement->execute([
            ':status' => 'delivered',
            ':id' => $id,
        ]);

        return $statement->rowCount() > 0;
    }

    /**
     * Mark an SMS as failed.
     */
    public function markFailed(string $id): bool
    {
        if (!self::isUuid($id)) {
            return false;
        }

        $statement = $this->db->prepare(
            'UPDATE ' . self::TABLE . '
             SET status = :status
             WHERE id = :id
             AND status IN (\'queued\', \'sent\')'
        );

        $statement->execute([
            ':status' => 'failed',
            ':id' => $id,
        ]);

        return $statement->rowCount() > 0;
    }

    /**
     * Find recent notifications by status.
     */
    public function findByStatus(
        string $status,
        int $limit = 100
    ): array {
        if (!in_array($status, self::STATUSES, true)) {
            throw new RuntimeException('Invalid SMS status.');
        }

        $limit = max(1, min($limit, 500));

        $statement = $this->db->prepare(
            'SELECT
                id,
                recovery_request_id,
                recipient_phone,
                message,
                provider,
                provider_message_id,
                status,
                sent_at,
                delivered_at,
                created_at
             FROM ' . self::TABLE . '
             WHERE status = :status
             ORDER BY created_at DESC
             LIMIT ' . $limit
        );

        $statement->execute([
            ':status' => $status,
        ]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Check whether a notification was successfully sent.
     */
    public function isSent(string $id): bool
    {
        if (!self::isUuid($id)) {
            return false;
        }

        $statement = $this->db->prepare(
            'SELECT 1
             FROM ' . self::TABLE . '
             WHERE id = :id
             AND status IN (\'sent\', \'delivered\')
             LIMIT 1'
        );

        $statement->execute([
            ':id' => $id,
        ]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * Normalize a Kenyan mobile number.
     */
    public static function normalizePhone(string $phone): string
    {
        $phone = trim($phone);

        if (str_starts_with($phone, '+254')) {
            $phone = substr($phone, 1);
        }

        if (str_starts_with($phone, '254')) {
            $normalized = $phone;
        } elseif (str_starts_with($phone, '07')) {
            $normalized = '254' . substr($phone, 1);
        } elseif (str_starts_with($phone, '01')) {
            $normalized = '254' . substr($phone, 1);
        } else {
            throw new RuntimeException(
                'Invalid Kenyan phone number.'
            );
        }

        if (
            !preg_match(
                '/^254(7\d{8}|1\d{8})$/',
                $normalized
            )
        ) {
            throw new RuntimeException(
                'Invalid Kenyan phone number.'
            );
        }

        return $normalized;
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
