<?php

declare(strict_types=1);

namespace ScanId\Models;

use PDO;
use RuntimeException;

final class RecoveryPayment
{
    private const RECOVERY_AMOUNT_KES = 300;

    public function __construct(
        private readonly PDO $database
    ) {
    }

    /**
     * Create a pending KSh 300 recovery payment.
     *
     * The payment service is responsible for initiating
     * the actual M-Pesa STK Push.
     *
     * KSh 300 total:
     * - KSh 150 finder reward
     * - KSh 150 SCAN-ID platform
     */
    public function create(
        string $recoveryRequestId,
        string $phone
    ): array {
        $recoveryRequestId = trim($recoveryRequestId);
        $phone = $this->normalizePhone($phone);

        if ($recoveryRequestId === '') {
            throw new RuntimeException(
                'Recovery request ID is required.'
            );
        }

        if ($phone === '') {
            throw new RuntimeException(
                'Payment phone number is required.'
            );
        }

        $this->assertRecoveryRequestExists(
            $recoveryRequestId
        );

        $existing = $this->findPendingByRecoveryRequest(
            $recoveryRequestId
        );

        if ($existing !== null) {
            return $existing;
        }

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
                mpesa_receipt_number,
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
            'amount_kes' => self::RECOVERY_AMOUNT_KES,
            'phone' => $phone,
        ]);

        $payment = $statement->fetch(PDO::FETCH_ASSOC);

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
                mpesa_receipt_number,
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

        $payment = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($payment)
            ? $payment
            : null;
    }

    /**
     * Find the latest payment for a recovery request.
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
                mpesa_receipt_number,
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

        $payment = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($payment)
            ? $payment
            : null;
    }

    /**
     * Find an existing pending payment.
     */
    public function findPendingByRecoveryRequest(
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
                mpesa_receipt_number,
                phone,
                status,
                result_code,
                result_description,
                paid_at,
                created_at,
                updated_at
            FROM recovery_payments
            WHERE recovery_request_id = :recovery_request_id
              AND status = 'pending'
            ORDER BY created_at DESC
            LIMIT 1
            SQL
        );

        $statement->execute([
            'recovery_request_id' => $recoveryRequestId,
        ]);

        $payment = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($payment)
            ? $payment
            : null;
    }

    /**
     * Store M-Pesa STK Push identifiers.
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
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
              AND status = 'pending'
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
     * Find a payment using an M-Pesa receipt number.
     */
    public function findByReceipt(
        string $receiptNumber
    ): ?array {
        $receiptNumber = trim($receiptNumber);

        if ($receiptNumber === '') {
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
                mpesa_receipt_number,
                phone,
                status,
                result_code,
                result_description,
                paid_at,
                created_at,
                updated_at
            FROM recovery_payments
            WHERE mpesa_receipt_number = :receipt_number
            LIMIT 1
            SQL
        );

        $statement->execute([
            'receipt_number' => $receiptNumber,
        ]);

        $payment = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($payment)
            ? $payment
            : null;
    }

    /**
     * Mark payment as completed after a verified M-Pesa callback.
     *
     * Receipt numbers are unique in the database, providing
     * an additional protection against duplicate callbacks.
     */
    public function markCompleted(
        string $id,
        string $mpesaReceiptNumber,
        ?int $resultCode = 0,
        ?string $resultDescription = null
    ): bool {
        $id = trim($id);
        $mpesaReceiptNumber = trim(
            $mpesaReceiptNumber
        );

        if ($id === '') {
            throw new RuntimeException(
                'Payment ID is required.'
            );
        }

        if ($mpesaReceiptNumber === '') {
            throw new RuntimeException(
                'M-Pesa receipt number is required.'
            );
        }

        $existing = $this->findByReceipt(
            $mpesaReceiptNumber
        );

        if ($existing !== null) {
            if (
                (string) $existing['id'] === $id
                && $existing['status'] === 'completed'
            ) {
                return true;
            }

            throw new RuntimeException(
                'This M-Pesa receipt has already been processed.'
            );
        }

        $statement = $this->database->prepare(
            <<<'SQL'
            UPDATE recovery_payments
            SET
                status = 'completed',
                mpesa_receipt_number = :mpesa_receipt_number,
                result_code = :result_code,
                result_description = :result_description,
                paid_at = COALESCE(
                    paid_at,
                    CURRENT_TIMESTAMP
                ),
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
              AND status = 'pending'
            SQL
        );

        $statement->execute([
            'id' => $id,
            'mpesa_receipt_number' => $mpesaReceiptNumber,
            'result_code' => $resultCode,
            'result_description' => $resultDescription,
        ]);

        return $statement->rowCount() > 0;
    }

    /**
     * Mark a pending payment as failed.
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
                updated_at = CURRENT_TIMESTAMP
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
     * Cancel a pending payment.
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
                updated_at = CURRENT_TIMESTAMP
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
     * Check whether a recovery request has a completed payment.
     */
    public function isCompleted(
        string $recoveryRequestId
    ): bool {
        $recoveryRequestId = trim(
            $recoveryRequestId
        );

        if ($recoveryRequestId === '') {
            return false;
        }

        $statement = $this->database->prepare(
            <<<'SQL'
            SELECT 1
            FROM recovery_payments
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
     * Verify that the payment amount is the locked
     * SCAN-ID recovery amount.
     */
    public function isCorrectAmount(
        int $amountKes
    ): bool {
        return $amountKes === self::RECOVERY_AMOUNT_KES;
    }

    /**
     * Verify that the recovery request exists.
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

    /**
     * Normalize Kenyan phone numbers.
     */
    private function normalizePhone(
        string $phone
    ): string {
        $phone = trim($phone);

        if ($phone === '') {
            return '';
        }

        $phone = preg_replace(
            '/[\s\-\(\)]/',
            '',
            $phone
        ) ?? '';

        if (str_starts_with($phone, '00')) {
            $phone = '+' . substr($phone, 2);
        }

        if (str_starts_with($phone, '0')) {
            $phone = '+254' . substr($phone, 1);
        } elseif (str_starts_with($phone, '254')) {
            $phone = '+' . $phone;
        }

        if (!preg_match('/^\+2547\d{8}$/', $phone)) {
            throw new RuntimeException(
                'Invalid Kenyan phone number.'
            );
        }

        return $phone;
    }
}
