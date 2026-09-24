<?php

declare(strict_types=1);

namespace ScanId\Models;

use PDO;
use RuntimeException;
use ScanId\Config\App;

final class RecoveryPayment
{
    private const TABLE = 'recovery_payments';
    private const PROVIDER = 'mpesa';

    public function __construct(
        private readonly PDO $db
    ) {
    }

    /**
     * Create or reuse a pending KSh 300 recovery payment.
     */
    public function create(
        string $recoveryRequestId,
        string $phone
    ): array {
        $this->assertUuid($recoveryRequestId);

        $phone = self::normalizePhone($phone);

        App::validateRecoveryPricing();

        $amountKes = App::recoveryFeeKes();

        if ($amountKes !== 300) {
            throw new RuntimeException(
                'Recovery payment amount must be exactly KSh 300.'
            );
        }

        $this->assertRecoveryRequestExists($recoveryRequestId);

        $existing = $this->findPendingByRecoveryRequest(
            $recoveryRequestId
        );

        if ($existing !== null) {
            if (
                self::normalizePhone((string) $existing['phone'])
                !== $phone
            ) {
                throw new RuntimeException(
                    'Payment phone does not match the existing recovery payment.'
                );
            }

            return $existing;
        }

        $statement = $this->db->prepare(
            'INSERT INTO ' . self::TABLE . ' (
                recovery_request_id,
                amount_kes,
                provider,
                phone,
                status
            ) VALUES (
                :recovery_request_id,
                :amount_kes,
                :provider,
                :phone,
                \'pending\'
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
                updated_at'
        );

        $statement->execute([
            'recovery_request_id' => $recoveryRequestId,
            'amount_kes' => $amountKes,
            'provider' => self::PROVIDER,
            'phone' => $phone,
        ]);

        $payment = $statement->fetch(PDO::FETCH_ASSOC);

        if ($payment === false) {
            throw new RuntimeException(
                'Unable to create recovery payment.'
            );
        }

        return $payment;
    }

    public function findById(string $paymentId): ?array
    {
        $this->assertUuid($paymentId);

        $statement = $this->db->prepare(
            'SELECT
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
             FROM ' . self::TABLE . '
             WHERE id = :id
             LIMIT 1'
        );

        $statement->execute([
            'id' => $paymentId,
        ]);

        $payment = $statement->fetch(PDO::FETCH_ASSOC);

        return $payment === false ? null : $payment;
    }

    public function findByRecoveryRequest(
        string $recoveryRequestId
    ): ?array {
        $this->assertUuid($recoveryRequestId);

        $statement = $this->db->prepare(
            'SELECT
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
             FROM ' . self::TABLE . '
             WHERE recovery_request_id = :recovery_request_id
             ORDER BY created_at DESC
             LIMIT 1'
        );

        $statement->execute([
            'recovery_request_id' => $recoveryRequestId,
        ]);

        $payment = $statement->fetch(PDO::FETCH_ASSOC);

        return $payment === false ? null : $payment;
    }

    public function findPendingByRecoveryRequest(
        string $recoveryRequestId
    ): ?array {
        $this->assertUuid($recoveryRequestId);

        $statement = $this->db->prepare(
            'SELECT
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
             FROM ' . self::TABLE . '
             WHERE recovery_request_id = :recovery_request_id
               AND status = \'pending\'
             ORDER BY created_at DESC
             LIMIT 1'
        );

        $statement->execute([
            'recovery_request_id' => $recoveryRequestId,
        ]);

        $payment = $statement->fetch(PDO::FETCH_ASSOC);

        return $payment === false ? null : $payment;
    }

    public function findByCheckoutRequest(
        string $checkoutRequestId
    ): ?array {
        $checkoutRequestId = trim($checkoutRequestId);

        if ($checkoutRequestId === '') {
            throw new RuntimeException(
                'Checkout request ID is required.'
            );
        }

        $statement = $this->db->prepare(
            'SELECT
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
             FROM ' . self::TABLE . '
             WHERE checkout_request_id = :checkout_request_id
             LIMIT 1'
        );

        $statement->execute([
            'checkout_request_id' => $checkoutRequestId,
        ]);

        $payment = $statement->fetch(PDO::FETCH_ASSOC);

        return $payment === false ? null : $payment;
    }

    public function findByReceipt(
        string $receipt
    ): ?array {
        $receipt = trim($receipt);

        if ($receipt === '') {
            throw new RuntimeException(
                'M-Pesa receipt number is required.'
            );
        }

        $statement = $this->db->prepare(
            'SELECT
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
             FROM ' . self::TABLE . '
             WHERE mpesa_receipt_number = :receipt
             LIMIT 1'
        );

        $statement->execute([
            'receipt' => $receipt,
        ]);

        $payment = $statement->fetch(PDO::FETCH_ASSOC);

        return $payment === false ? null : $payment;
    }

    /**
     * Store Daraja STK Push identifiers.
     */
    public function setCheckoutDetails(
        string $paymentId,
        string $checkoutRequestId,
        ?string $merchantRequestId = null
    ): array {
        $this->assertUuid($paymentId);

        $checkoutRequestId = trim($checkoutRequestId);

        if ($checkoutRequestId === '') {
            throw new RuntimeException(
                'Checkout request ID is required.'
            );
        }

        $merchantRequestId = $merchantRequestId !== null
            ? trim($merchantRequestId)
            : null;

        $statement = $this->db->prepare(
            'UPDATE ' . self::TABLE . '
             SET
                checkout_request_id = :checkout_request_id,
                merchant_request_id = :merchant_request_id,
                updated_at = NOW()
             WHERE id = :id
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
                updated_at'
        );

        $statement->execute([
            'id' => $paymentId,
            'checkout_request_id' => $checkoutRequestId,
            'merchant_request_id' => $merchantRequestId,
        ]);

        $payment = $statement->fetch(PDO::FETCH_ASSOC);

        if ($payment === false) {
            throw new RuntimeException(
                'Recovery payment not found.'
            );
        }

        return $payment;
    }

    /**
     * Mark payment as pending after STK Push has been accepted.
     */
    public function markPending(
        string $paymentId
    ): array {
        $this->assertUuid($paymentId);

        $statement = $this->db->prepare(
            'UPDATE ' . self::TABLE . '
             SET
                status = \'pending\',
                updated_at = NOW()
             WHERE id = :id
               AND status = \'pending\'
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
                updated_at'
        );

        $statement->execute([
            'id' => $paymentId,
        ]);

        $payment = $statement->fetch(PDO::FETCH_ASSOC);

        if ($payment === false) {
            $existing = $this->findById($paymentId);

            if ($existing === null) {
                throw new RuntimeException(
                    'Recovery payment not found.'
                );
            }

            return $existing;
        }

        return $payment;
    }

    /**
     * Complete a payment from a verified Daraja callback.
     *
     * Idempotent:
     * - Replaying the same successful callback is safe.
     * - A receipt cannot be attached to another payment.
     */
    public function markCompleted(
        string $paymentId,
        string $receipt,
        int $amountKes
    ): array {
        $this->assertUuid($paymentId);

        $receipt = trim($receipt);

        if ($receipt === '') {
            throw new RuntimeException(
                'M-Pesa receipt number is required.'
            );
        }

        App::validateRecoveryPricing();

        $expectedAmount = App::recoveryFeeKes();

        if ($expectedAmount !== 300) {
            throw new RuntimeException(
                'Configured recovery amount is invalid.'
            );
        }

        if ($amountKes !== $expectedAmount) {
            throw new RuntimeException(
                'M-Pesa payment amount does not match KSh 300.'
            );
        }

        $payment = $this->findById($paymentId);

        if ($payment === null) {
            throw new RuntimeException(
                'Recovery payment not found.'
            );
        }

        if ((int) $payment['amount_kes'] !== $expectedAmount) {
            throw new RuntimeException(
                'Stored recovery payment amount is invalid.'
            );
        }

        if ($payment['status'] === 'completed') {
            if (
                (string) $payment['mpesa_receipt_number']
                !== $receipt
            ) {
                throw new RuntimeException(
                    'Payment is already completed with another receipt.'
                );
            }

            return $payment;
        }

        $receiptPayment = $this->findByReceipt($receipt);

        if ($receiptPayment !== null) {
            if (
                (string) $receiptPayment['id']
                !== $paymentId
            ) {
                throw new RuntimeException(
                    'M-Pesa receipt is already linked to another payment.'
                );
            }

            if ($receiptPayment['status'] === 'completed') {
                return $receiptPayment;
            }
        }

        $statement = $this->db->prepare(
            'UPDATE ' . self::TABLE . '
             SET
                amount_kes = :amount_kes,
                mpesa_receipt_number = :receipt,
                status = \'completed\',
                result_code = 0,
                result_description = \'Payment completed successfully.\',
                paid_at = COALESCE(paid_at, NOW()),
                updated_at = NOW()
             WHERE id = :id
               AND status IN (\'pending\', \'failed\')
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
                updated_at'
        );

        try {
            $statement->execute([
                'id' => $paymentId,
                'amount_kes' => $expectedAmount,
                'receipt' => $receipt,
            ]);
        } catch (\PDOException $exception) {
            throw new RuntimeException(
                'Unable to complete recovery payment.',
                0,
                $exception
            );
        }

        $completed = $statement->fetch(PDO::FETCH_ASSOC);

        if ($completed === false) {
            $existing = $this->findById($paymentId);

            if (
                $existing !== null
                && $existing['status'] === 'completed'
                && (string) $existing['mpesa_receipt_number'] === $receipt
            ) {
                return $existing;
            }

            throw new RuntimeException(
                'Recovery payment could not be completed.'
            );
        }

        return $completed;
    }

    public function markFailed(
        string $paymentId,
        ?int $resultCode = null,
        ?string $resultDescription = null
    ): array {
        $this->assertUuid($paymentId);

        $description = $resultDescription !== null
            ? trim($resultDescription)
            : null;

        if ($description !== null && strlen($description) > 1000) {
            $description = substr($description, 0, 1000);
        }

        $statement = $this->db->prepare(
            'UPDATE ' . self::TABLE . '
             SET
                status = \'failed\',
                result_code = :result_code,
                result_description = :result_description,
                updated_at = NOW()
             WHERE id = :id
               AND status <> \'completed\'
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
                updated_at'
        );

        $statement->execute([
            'id' => $paymentId,
            'result_code' => $resultCode,
            'result_description' => $description,
        ]);

        $payment = $statement->fetch(PDO::FETCH_ASSOC);

        if ($payment !== false) {
            return $payment;
        }

        $existing = $this->findById($paymentId);

        if ($existing === null) {
            throw new RuntimeException(
                'Recovery payment not found.'
            );
        }

        return $existing;
    }

    public function markCancelled(
        string $paymentId,
        ?string $reason = null
    ): array {
        $this->assertUuid($paymentId);

        $reason = $reason !== null
            ? trim($reason)
            : null;

        if ($reason !== null && strlen($reason) > 1000) {
            $reason = substr($reason, 0, 1000);
        }

        $statement = $this->db->prepare(
            'UPDATE ' . self::TABLE . '
             SET
                status = \'cancelled\',
                result_description = :reason,
                updated_at = NOW()
             WHERE id = :id
               AND status <> \'completed\'
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
                updated_at'
        );

        $statement->execute([
            'id' => $paymentId,
            'reason' => $reason,
        ]);

        $payment = $statement->fetch(PDO::FETCH_ASSOC);

        if ($payment !== false) {
            return $payment;
        }

        $existing = $this->findById($paymentId);

        if ($existing === null) {
            throw new RuntimeException(
                'Recovery payment not found.'
            );
        }

        return $existing;
    }

    public function isCompleted(
        string $recoveryRequestId
    ): bool {
        $this->assertUuid($recoveryRequestId);

        $statement = $this->db->prepare(
            'SELECT EXISTS (
                SELECT 1
                FROM ' . self::TABLE . '
                WHERE recovery_request_id = :recovery_request_id
                  AND status = \'completed\'
            )'
        );

        $statement->execute([
            'recovery_request_id' => $recoveryRequestId,
        ]);

        return (bool) $statement->fetchColumn();
    }

    public function isCorrectAmount(
        array $payment
    ): bool {
        App::validateRecoveryPricing();

        return isset($payment['amount_kes'])
            && (int) $payment['amount_kes']
                === App::recoveryFeeKes();
    }

    private function assertRecoveryRequestExists(
        string $recoveryRequestId
    ): void {
        $statement = $this->db->prepare(
            'SELECT 1
             FROM recovery_requests
             WHERE id = :id
             LIMIT 1'
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

    private function assertUuid(string $value): void
    {
        if (
            !preg_match(
                '/^[0-9a-fA-F]{8}-'
                . '[0-9a-fA-F]{4}-'
                . '[1-5][0-9a-fA-F]{3}-'
                . '[89abAB][0-9a-fA-F]{3}-'
                . '[0-9a-fA-F]{12}$/',
                $value
            )
        ) {
            throw new RuntimeException(
                'Invalid UUID.'
            );
        }
    }

    public static function normalizePhone(
        string $phone
    ): string {
        $phone = trim($phone);

        if (str_starts_with($phone, '+254')) {
            $phone = substr($phone, 1);
        }

        if (str_starts_with($phone, '254')) {
            // Already normalized.
        } elseif (str_starts_with($phone, '07')) {
            $phone = '254' . substr($phone, 1);
        } elseif (str_starts_with($phone, '01')) {
            $phone = '254' . substr($phone, 1);
        } else {
            throw new RuntimeException(
                'Invalid Kenyan phone number.'
            );
        }

        if (
            !preg_match(
                '/^254(7\d{8}|1\d{8})$/',
                $phone
            )
        ) {
            throw new RuntimeException(
                'Invalid Kenyan phone number.'
