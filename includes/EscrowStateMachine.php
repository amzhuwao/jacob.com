<?php

/**
 * EscrowStateMachine - Enforces locked state transitions for escrow
 * with dispute awareness
 * 
 * ALLOWED TRANSITIONS:
 * - pending -> funded (via Stripe webhook payment.intent.succeeded)
 * - funded -> release_requested (via admin action - triggers Stripe payout)
 * - release_requested -> released (via Stripe webhook payout.paid)
 * - funded -> refund_requested (via admin action - triggers Stripe refund)
 * - refund_requested -> refunded (via Stripe webhook charge.refunded)
 * - pending -> canceled (via admin/buyer action before payment)
 * 
 * ALL OTHER TRANSITIONS ARE BLOCKED
 */

class EscrowStateMachine
{
    private PDO $pdo;

    private const VALID_TRANSITIONS = [
        'pending' => ['funded', 'canceled'],
        'funded' => ['release_requested', 'refund_requested', 'disputed'],
        'release_requested' => ['released'],
        'refund_requested' => ['refunded', 'disputed'],
        'disputed' => ['released', 'refunded'],
        'released' => [],
        'refunded' => [],
        'canceled' => [],
    ];

    private const PAYMENT_STATUS_TRANSITIONS = [
        'pending' => ['processing', 'canceled'],
        'processing' => ['succeeded', 'failed'],
        'succeeded' => [],
        'failed' => ['pending'],
        'canceled' => [],
    ];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Validate if a state transition is allowed
     */
    public function isTransitionAllowed(string $fromStatus, string $toStatus): bool
    {
        return isset(self::VALID_TRANSITIONS[$fromStatus])
            && in_array($toStatus, self::VALID_TRANSITIONS[$fromStatus], true);
    }

    /**
     * Transition escrow to a new state
     */
    public function transition(
        int $escrowId,
        string $toStatus,
        string $triggeredBy = 'system',
        ?int $userId = null,
        ?string $reason = null,
        ?array $metadata = null
    ): bool {
        try {
            $this->pdo->beginTransaction();

            $stmt = $this->pdo->prepare("SELECT id, project_id, status, amount, payment_status FROM escrow WHERE id = ? FOR UPDATE");
            $stmt->execute([$escrowId]);
            $escrow = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$escrow) {
                throw new RuntimeException("Escrow not found");
            }

            $fromStatus = $escrow['status'];
            if (!$this->isTransitionAllowed($fromStatus, $toStatus)) {
                throw new RuntimeException("Invalid transition: {$fromStatus} -> {$toStatus}");
            }

            if ($fromStatus === $toStatus) {
                $this->pdo->commit();
                return true; // Already in target state
            }

            // Update status and timestamps
            $updateFields = ['status = ?', 'updated_at = NOW()'];
            $updateParams = [$toStatus, $escrowId, $fromStatus];

            if ($toStatus === 'funded') $updateFields[] = 'funded_at = NOW()';
            if ($toStatus === 'released') $updateFields[] = 'released_at = NOW()';

            $updateStmt = $this->pdo->prepare("UPDATE escrow SET " . implode(', ', $updateFields) . " WHERE id = ? AND status = ?");
            $updateStmt->execute($updateParams);

            if ($updateStmt->rowCount() === 0) {
                throw new RuntimeException("Escrow state changed concurrently; transition aborted");
            }

            $this->logTransition($escrowId, $escrow['project_id'], $fromStatus, $toStatus, $triggeredBy, $userId, $reason, $metadata);
            $this->updateProjectStatus($escrow['project_id'], $toStatus);

            $this->pdo->commit();
            return true;
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw new RuntimeException("State transition failed: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Update payment status and handle disputes automatically on partial refunds
     */
    public function updatePaymentStatus(int $escrowId, string $paymentStatus, ?int $amountCents = null, ?string $stripePaymentIntentId = null): void
    {
        $curStmt = $this->pdo->prepare("SELECT id, status, payment_status, amount FROM escrow WHERE id = ? FOR UPDATE");
        $curStmt->execute([$escrowId]);
        $escrow = $curStmt->fetch(PDO::FETCH_ASSOC);

        if (!$escrow) throw new RuntimeException("Escrow not found");

        $allowed = self::PAYMENT_STATUS_TRANSITIONS[$escrow['payment_status']] ?? [];
        if (!in_array($paymentStatus, $allowed, true)) {
            throw new RuntimeException("Invalid payment status transition: {$escrow['payment_status']} -> {$paymentStatus}");
        }

        $this->pdo->beginTransaction();

        $updateStmt = $this->pdo->prepare("
            UPDATE escrow SET payment_status = ?, stripe_payment_intent_id = COALESCE(?, stripe_payment_intent_id), updated_at = NOW()
            WHERE id = ? AND payment_status = ?
        ");
        $updateStmt->execute([$paymentStatus, $stripePaymentIntentId, $escrowId, $escrow['payment_status']]);

        // Check for partial refund: if refunded amount < escrow amount => mark as disputed
        if ($paymentStatus === 'succeeded' && $amountCents !== null && $amountCents < ($escrow['amount'] * 100)) {
            $this->transition($escrowId, 'disputed', 'system', null, 'Partial refund triggered dispute');
        }

        $this->pdo->commit();
    }

    private function logTransition(int $escrowId, int $projectId, string $fromStatus, string $toStatus, string $triggeredBy, ?int $userId, ?string $reason, ?array $metadata): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO escrow_state_transitions 
            (escrow_id, project_id, from_status, to_status, triggered_by, user_id, reason, metadata)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $escrowId,
            $projectId,
            $fromStatus,
            $toStatus,
            $triggeredBy,
            $userId,
            $reason,
            $metadata ? json_encode($metadata) : null
        ]);
    }

    private function updateProjectStatus(int $projectId, string $escrowStatus): void
    {
        $map = [
            'funded' => 'in_progress',
            'release_requested' => 'completed',
            'released' => 'completed',
            'refunded' => 'canceled',
            'canceled' => 'open',
            'disputed' => 'disputed',
        ];

        if (isset($map[$escrowStatus])) {
            $stmt = $this->pdo->prepare("UPDATE projects SET status = ?, updated_at = NOW() WHERE id = ? AND status <> ?");
            $stmt->execute([$map[$escrowStatus], $projectId, $map[$escrowStatus]]);
        }
    }

    // Existing helper methods: getEscrowState, getTransitionHistory, canRefund, canRelease remain unchanged


    /**
     * Get escrow with full state info
     */
    public function getEscrowState(int $escrowId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT e.*, 
                    p.title as project_title,
                    p.status as project_status,
                    b.full_name as buyer_name,
                    s.full_name as seller_name
             FROM escrow e
             JOIN projects p ON e.project_id = p.id
             JOIN users b ON e.buyer_id = b.id
             JOIN users s ON e.seller_id = s.id
             WHERE e.id = ?"
        );
        $stmt->execute([$escrowId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Get transition history for an escrow
     */
    public function getTransitionHistory(int $escrowId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT est.*, u.full_name as user_name
             FROM escrow_state_transitions est
             LEFT JOIN users u ON est.user_id = u.id
             WHERE est.escrow_id = ?
             ORDER BY est.created_at DESC"
        );
        $stmt->execute([$escrowId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Check if escrow can be refunded
     */
    public function canRefund(int $escrowId): bool
    {
        $escrow = $this->getEscrowState($escrowId);
        if (!$escrow) {
            return false;
        }

        // Can only refund if funded and payment succeeded
        return $escrow['status'] === 'funded' &&
            $escrow['payment_status'] === 'succeeded';
    }

    /**
     * Check if escrow can be released
     */
    public function canRelease(int $escrowId): bool
    {
        $escrow = $this->getEscrowState($escrowId);
        if (!$escrow) {
            return false;
        }

        // Can only release if funded
        return $escrow['status'] === 'funded' &&
            $escrow['payment_status'] === 'succeeded';
    }

    /**
     * Create Stripe payout to seller's connected account
     * Called when admin requests release
     */
    public function createPayout(int $escrowId, int $sellerId): array
    {
        $escrow = $this->getEscrowState($escrowId);
        if (!$escrow || !$this->canRelease($escrowId)) {
            throw new RuntimeException("Escrow cannot be released");
        }

        // Get seller's Stripe account ID
        $sellerStmt = $this->pdo->prepare(
            "SELECT stripe_account_id FROM users WHERE id = ? AND role = 'seller'"
        );
        $sellerStmt->execute([$sellerId]);
        $seller = $sellerStmt->fetch(PDO::FETCH_ASSOC);

        if (!$seller || empty($seller['stripe_account_id'])) {
            throw new RuntimeException("Seller must have a connected Stripe account");
        }

        // Load Stripe config
        require_once __DIR__ . '/../config/stripe.php';

        // Create payout via Stripe API
        $payoutData = [
            'amount' => (int)($escrow['amount'] * 100), // Convert to cents
            'currency' => 'usd',
            'destination' => $seller['stripe_account_id'],
            'metadata' => [
                'escrow_id' => $escrowId,
                'project_id' => $escrow['project_id'],
                'seller_id' => $sellerId,
            ]
        ];

        $ch = curl_init('https://api.stripe.com/v1/transfers');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($payoutData),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . STRIPE_SECRET_KEY,
                'Content-Type: application/x-www-form-urlencoded',
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            $error = json_decode($response, true);
            throw new RuntimeException("Stripe payout failed: " . ($error['error']['message'] ?? 'Unknown error'));
        }

        $payout = json_decode($response, true);

        // Store payout ID in escrow
        $updateStmt = $this->pdo->prepare(
            "UPDATE escrow SET stripe_payout_id = ?, updated_at = NOW() WHERE id = ?"
        );
        $updateStmt->execute([$payout['id'], $escrowId]);

        return $payout;
    }

    /**
     * Create Stripe refund for the escrow's PaymentIntent
     * Called when admin requests refund
     */
    public function createRefund(int $escrowId, ?int $amountCents = null, ?string $reason = null): array
    {
        $escrow = $this->getEscrowState($escrowId);
        if (!$escrow || !$this->canRefund($escrowId)) {
            throw new RuntimeException("Escrow cannot be refunded");
        }

        if (empty($escrow['stripe_payment_intent_id'])) {
            throw new RuntimeException("Missing Stripe PaymentIntent ID on escrow");
        }

        require_once __DIR__ . '/../config/stripe.php';

        $refundData = [
            'payment_intent' => $escrow['stripe_payment_intent_id'],
            'metadata' => [
                'escrow_id' => $escrowId,
                'project_id' => $escrow['project_id'],
                'buyer_id' => $escrow['buyer_id'],
                'seller_id' => $escrow['seller_id'],
            ]
        ];

        if ($amountCents !== null) {
            $refundData['amount'] = (int)$amountCents;
        } else {
            $refundData['amount'] = (int)($escrow['amount'] * 100);
        }

        if ($reason) {
            $refundData['reason'] = $reason; // e.g., 'requested_by_customer'
        }

        $ch = curl_init('https://api.stripe.com/v1/refunds');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($refundData),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . STRIPE_SECRET_KEY,
                'Content-Type: application/x-www-form-urlencoded',
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            $error = json_decode($response, true);
            throw new RuntimeException("Stripe refund failed: " . ($error['error']['message'] ?? 'Unknown error'));
        }

        $refund = json_decode($response, true);

        // Store refund ID in escrow for tracking
        $updateStmt = $this->pdo->prepare(
            "UPDATE escrow SET stripe_refund_id = ?, updated_at = NOW() WHERE id = ?"
        );
        $updateStmt->execute([$refund['id'] ?? null, $escrowId]);

        return $refund;
    }
}
