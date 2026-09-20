<?php

declare(strict_types=1);

namespace ScanId\Models;

use PDO;
use RuntimeException;

final class RecoveryPayment
{
    public function __construct(
        private readonly PDO $database
    ) {
    }

    /**
     * Create a pending recovery payment.
     *
     * The actual M-Pesa request is handled by a service.
     */
    public function create(
        string $recoveryRequestId,
        int $amountKes,
        string $phone
    ): array {
        $recoveryRequestId = trim($recoveryRequestId);
        $phone = trim($phone);

        if ($recoveryRequestId === '') {
            throw new RuntimeException(
                'Recovery request ID is required.'
            );
        }

        if ($amountKes < 0) {
            throw new RuntimeException(
                'Payment amount cannot be negative.'
            );
        }

        if ($phone === '') {
            throw new RuntimeException(
                'Payment phone number is required.'
            );
        }

        if (mb_strlen($phone) > 30) {
            throw new RuntimeException(
                'Payment phone number is too long.'
            );
        }

        $this->assertRecoveryRequestExists(
            $recoveryRequestId
        );

        $statement = $this->database->prepare(
            <<<'SQL'
            INSERT INTO recovery_payments (
                recovery_request_id,
                amount_kes,
                provider,
                phone,
                status
            )
            VALUES (
                :recovery_request_id,
                :amount_kes,
                'mpesa',
                :phone,
                'pending'
            )
            RETURNING
                id,
                recovery_request_id,
                amount_kes,
                provider,
                checkout_request_id,
                merchant_request_id,
                mpesa_receipt,
                phone,
                status,
                result_code,
                result_description,
                paid_at,
                created_at,
                updated_at
            SQL
        );

        $statement->execute([
            'recovery_request_id' => $recoveryRequestId,
            'amount_kes' => $amountKes,
            'phone' => $phone,
        ]);

        $payment = $statement->fetch();

        if (!is_array($payment)) {
            throw new RuntimeException(
                'Unable to create recovery payment.'
            );
        }

        return $payment;
    }

    /**
     * Find a payment by ID.
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
                amount_kes,
                provider,
                checkout_request_id,
                merchant_request_id,
                mpesa_receipt,
                phone,
                status,
                result_code,
                result_description,
                paid_at,
                created_at,
                updated_at
            FROM recovery_payments
            WHERE id = :id
            LIMIT 1
            SQL
        );

        $statement->execute([
            'id' => $id,
        ]);

        $payment = $statement->fetch();

        return is_array($payment)
            ? $payment
            : null;
    }

    /**
     * Find a payment belonging to a recovery request.
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
                amount_kes,
                provider,
                checkout_request_id,
                merchant_request_id,
                mpesa_receipt,
                phone,
                status,
                result_code,
                result_description,
                paid_at,
                created_at,
                updated_at
            FROM recovery_payments
            WHERE recovery_request_id = :recovery_request_id
            ORDER BY created_at DESC
            LIMIT 1
            SQL
        );

        $statement->execute([
            'recovery_request_id' => $recoveryRequestId,
        ]);

        $payment = $statement->fetch();

        return is_array($payment)
            ? $payment
            : null;
    }

    /**
     * Store M-Pesa checkout identifiers.
     */
    public function setCheckoutDetails(
        string $id,
        string $checkoutRequestId,
        ?string $merchantRequestId = null
    ): bool {
        $id = trim($id);
        $checkoutRequestId = trim(
            $checkoutRequestId
        );

        $merchantRequestId = $merchantRequestId !== null
            ? trim($merchantRequestId)
            : null;

        if ($id === '') {
            throw new RuntimeException(
                'Payment ID is required.'
            );
        }

        if ($checkoutRequestId === '') {
            throw new RuntimeException(
                'Checkout request ID is required.'
            );
        }

        $statement = $this->database->prepare(
            <<<'SQL'
            UPDATE recovery_payments
            SET
                checkout_request_id = :checkout_request_id,
                merchant_request_id = :merchant_request_id,
                updated_at = NOW()
            WHERE id = :id
            SQL
        );

        $statement->execute([
            'id' => $id,
            'checkout_request_id' => $checkoutRequestId,
            'merchant_request_id' => $merchantRequestId,
        ]);

        return $statement->rowCount() > 0;
    }

    /**
     * Mark a payment as completed.
     *
     * M-Pesa receipt is unique and prevents duplicate
     * successful callback processing.
     */
    public function markCompleted(
        string $id,
        string $mpesaReceipt,
        ?int $resultCode = 0,
        ?string $resultDescription = null
    ): bool {
        $id = trim($id);
        $mpesaReceipt = trim($mpesaReceipt);

        if ($id === '') {
            throw new RuntimeException(
                'Payment ID is required.'
            );
        }

        if ($mpesaReceipt === '') {
            throw new RuntimeException(
                'M-Pesa receipt is required.'
            );
        }

        $statement = $this->database->prepare(
            <<<'SQL'
            UPDATE recovery_payments
            SET
                status = 'completed',
                mpesa_receipt = :mpesa_receipt,
                result_code = :result_code,
                result_description = :result_description,
                paid_at = COALESCE(paid_at, NOW()),
                updated_at = NOW()
            WHERE id = :id
              AND status <> 'completed'
            SQL
        );

        $statement->execute([
            'id' => $id,
            'mpesa_receipt' => $mpesaReceipt,
            'result_code' => $resultCode,
            'result_description' => $resultDescription,
        ]);

        return $statement->rowCount() > 0;
    }

    /**
     * Mark a payment as failed.
     */
    public function markFailed(
        string $id,
        ?int $resultCode = null,
        ?string $resultDescription = null
    ): bool {
        $id = trim($id);

        if ($id === '') {
            throw new RuntimeException(
                'Payment ID is required.'
            );
        }

        $statement = $this->database->prepare(
            <<<'SQL'
            UPDATE recovery_payments
            SET
                status = 'failed',
                result_code = :result_code,
                result_description = :result_description,
                updated_at = NOW()
            WHERE id = :id
              AND status = 'pending'
            SQL
        );

        $statement->execute([
            'id' => $id,
            'result_code' => $resultCode,
            'result_description' => $resultDescription,
        ]);

        return $statement->rowCount() > 0;
    }

    /**
     * Mark a payment as cancelled.
     */
    public function markCancelled(
        string $id
    ): bool {
        $id = trim($id);

        if ($id === '') {
            throw new RuntimeException(
                'Payment ID is required.'
            );
        }

        $statement = $this->database->prepare(
            <<<'SQL'
            UPDATE recovery_payments
            SET
                status = 'cancelled',
                updated_at = NOW()
            WHERE id = :id
              AND status = 'pending'
            SQL
        );

        $statement->execute([
            'id' => $id,
        ]);

        return $statement->rowCount() > 0;
    }

    /**
     * Check whether a payment has already completed.
     */
    public function isCompleted(
        string $id
    ): bool {
        $payment = $this->findById($id);

        return $payment !== null
            && $payment['status'] === 'completed';
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
