<?php

require_once __DIR__ . '/../config/stripe.php';
require_once __DIR__ . '/../config/database.php';

class StripeService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Create Stripe transfer (payout) for escrow release.
     * Uses idempotency key to prevent duplicates.
     * Does NOT change escrow state; webhook will finalize.
     */
    public function createPayout(int $escrowId): array
    {
        $stmt = $this->pdo->prepare("SELECT e.*, u.stripe_account_id FROM escrow e JOIN users u ON e.seller_id = u.id WHERE e.id = ?");
        $stmt->execute([$escrowId]);
        $escrow = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$escrow) {
            throw new RuntimeException("Escrow not found");
        }
        if (empty($escrow['stripe_account_id'])) {
            throw new RuntimeException("Seller must have a connected Stripe account");
        }

        $payoutData = [
            'amount' => (int)($escrow['amount'] * 100),
            'currency' => 'usd',
            'destination' => $escrow['stripe_account_id'],
            'metadata' => [
                'escrow_id' => $escrowId,
                'project_id' => $escrow['project_id'],
                'seller_id' => $escrow['seller_id'],
            ]
        ];

        $headers = [
            'Authorization: Bearer ' . STRIPE_SECRET_KEY,
            'Content-Type: application/x-www-form-urlencoded',
            'Idempotency-Key: escrow_' . $escrowId . '_release',
        ];

        $ch = curl_init('https://api.stripe.com/v1/transfers');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($payoutData),
            CURLOPT_HTTPHEADER => $headers,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            $error = json_decode($response, true);
            throw new RuntimeException("Stripe payout failed: " . ($error['error']['message'] ?? 'Unknown error'));
        }

        $payout = json_decode($response, true);

        // Store payout ID (optional but useful for auditing)
        $update = $this->pdo->prepare("UPDATE escrow SET stripe_payout_id = ?, updated_at = NOW() WHERE id = ?");
        $update->execute([$payout['id'] ?? null, $escrowId]);

        return $payout;
    }

    /**
     * Create Stripe refund using PaymentIntent ID.
     * Uses idempotency key to prevent duplicates.
     * Does NOT change escrow state; webhook will finalize.
     */
    public function createRefund(int $escrowId, ?int $amountCents = null, ?string $reason = 'requested_by_customer'): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM escrow WHERE id = ?");
        $stmt->execute([$escrowId]);
        $escrow = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$escrow) {
            throw new RuntimeException("Escrow not found");
        }
        if (empty($escrow['stripe_payment_intent_id'])) {
            throw new RuntimeException("Missing Stripe PaymentIntent ID");
        }

        $refundData = [
            'payment_intent' => $escrow['stripe_payment_intent_id'],
            'metadata' => [
                'escrow_id' => $escrowId,
                'project_id' => $escrow['project_id'],
                'buyer_id' => $escrow['buyer_id'],
                'seller_id' => $escrow['seller_id'],
            ]
        ];
        $refundData['amount'] = $amountCents !== null ? (int)$amountCents : (int)($escrow['amount'] * 100);
        if ($reason) $refundData['reason'] = $reason;

        $headers = [
            'Authorization: Bearer ' . STRIPE_SECRET_KEY,
            'Content-Type: application/x-www-form-urlencoded',
            'Idempotency-Key: escrow_' . $escrowId . '_refund',
        ];

        $ch = curl_init('https://api.stripe.com/v1/refunds');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($refundData),
            CURLOPT_HTTPHEADER => $headers,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            $error = json_decode($response, true);
            throw new RuntimeException("Stripe refund failed: " . ($error['error']['message'] ?? 'Unknown error'));
        }

        $refund = json_decode($response, true);

        // Store refund ID (optional but useful for auditing)
        $update = $this->pdo->prepare("UPDATE escrow SET stripe_refund_id = ?, updated_at = NOW() WHERE id = ?");
        $update->execute([$refund['id'] ?? null, $escrowId]);

        return $refund;
    }
}
