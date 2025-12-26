<?php

/**
 * WalletService - Manages seller virtual wallets
 * Credits earnings from completed projects and handles withdrawals
 */

class WalletService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Get or create wallet for a user
     */
    public function getWallet(int $userId): array
    {
        // Try to get existing wallet
        $stmt = $this->pdo->prepare("SELECT * FROM seller_wallets WHERE user_id = ?");
        $stmt->execute([$userId]);
        $wallet = $stmt->fetch(PDO::FETCH_ASSOC);

        // Create if doesn't exist
        if (!$wallet) {
            $insertStmt = $this->pdo->prepare(
                "INSERT INTO seller_wallets (user_id, balance, pending_balance) 
                 VALUES (?, 0.00, 0.00)"
            );
            $insertStmt->execute([$userId]);

            return $this->getWallet($userId); // Recursive call to get the new wallet
        }

        return $wallet;
    }

    /**
     * Credit wallet with earnings from completed escrow
     */
    public function creditEarnings(int $userId, float $amount, int $escrowId, int $projectId, string $description = 'Project earnings'): bool
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException("Amount must be positive");
        }

        $this->pdo->beginTransaction();

        try {
            // Get wallet with lock
            $wallet = $this->getWallet($userId);

            $lockStmt = $this->pdo->prepare("SELECT * FROM seller_wallets WHERE user_id = ? FOR UPDATE");
            $lockStmt->execute([$userId]);

            // Update balance
            $newBalance = $wallet['balance'] + $amount;
            $updateStmt = $this->pdo->prepare(
                "UPDATE seller_wallets SET balance = ?, updated_at = NOW() WHERE user_id = ?"
            );
            $updateStmt->execute([$newBalance, $userId]);

            // Record transaction
            $txnStmt = $this->pdo->prepare(
                "INSERT INTO wallet_transactions 
                 (user_id, type, amount, balance_after, escrow_id, project_id, description, status) 
                 VALUES (?, 'credit', ?, ?, ?, ?, ?, 'completed')"
            );
            $txnStmt->execute([$userId, $amount, $newBalance, $escrowId, $projectId, $description]);

            $this->pdo->commit();
            return true;
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Get wallet balance
     */
    public function getBalance(int $userId): float
    {
        $wallet = $this->getWallet($userId);
        return (float)$wallet['balance'];
    }

    /**
     * Get transaction history
     */
    public function getTransactions(int $userId, int $limit = 50, int $offset = 0): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT wt.*, p.title as project_title 
             FROM wallet_transactions wt
             LEFT JOIN projects p ON wt.project_id = p.id
             WHERE wt.user_id = ?
             ORDER BY wt.created_at DESC
             LIMIT ? OFFSET ?"
        );
        $stmt->execute([$userId, $limit, $offset]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Request withdrawal
     */
    public function requestWithdrawal(int $userId, float $amount): int
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException("Withdrawal amount must be positive");
        }

        $balance = $this->getBalance($userId);
        if ($amount > $balance) {
            throw new RuntimeException("Insufficient balance. Available: $" . number_format($balance, 2));
        }

        // Minimum withdrawal check (optional)
        if ($amount < 10) {
            throw new RuntimeException("Minimum withdrawal amount is $10.00");
        }

        $this->pdo->beginTransaction();

        try {
            // Create withdrawal request
            $stmt = $this->pdo->prepare(
                "INSERT INTO withdrawal_requests (user_id, amount, status) 
                 VALUES (?, ?, 'pending')"
            );
            $stmt->execute([$userId, $amount]);
            $withdrawalId = (int)$this->pdo->lastInsertId();

            // Deduct from balance immediately (prevents double withdrawal)
            $wallet = $this->getWallet($userId);
            $newBalance = $wallet['balance'] - $amount;

            $updateStmt = $this->pdo->prepare(
                "UPDATE seller_wallets SET balance = ?, updated_at = NOW() WHERE user_id = ?"
            );
            $updateStmt->execute([$newBalance, $userId]);

            // Record debit transaction
            $txnStmt = $this->pdo->prepare(
                "INSERT INTO wallet_transactions 
                 (user_id, type, amount, balance_after, withdrawal_id, description, status) 
                 VALUES (?, 'withdrawal', ?, ?, ?, 'Withdrawal request', 'pending')"
            );
            $txnStmt->execute([$userId, $amount, $newBalance, $withdrawalId]);

            $this->pdo->commit();
            return $withdrawalId;
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Process withdrawal (create Stripe payout)
     */
    public function processWithdrawal(int $withdrawalId): bool
    {
        require_once __DIR__ . '/StripeService.php';

        $stmt = $this->pdo->prepare(
            "SELECT wr.*, u.stripe_account_id 
             FROM withdrawal_requests wr
             JOIN users u ON wr.user_id = u.id
             WHERE wr.id = ?"
        );
        $stmt->execute([$withdrawalId]);
        $withdrawal = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$withdrawal) {
            throw new RuntimeException("Withdrawal request not found");
        }

        if ($withdrawal['status'] !== 'pending') {
            throw new RuntimeException("Withdrawal already processed or failed");
        }

        if (empty($withdrawal['stripe_account_id'])) {
            throw new RuntimeException("Seller must connect Stripe account first");
        }

        $this->pdo->beginTransaction();

        try {
            // Update to processing
            $updateStmt = $this->pdo->prepare(
                "UPDATE withdrawal_requests SET status = 'processing' WHERE id = ?"
            );
            $updateStmt->execute([$withdrawalId]);

            $this->pdo->commit();

            // Create Stripe payout (external API call, not in transaction)
            $stripeService = new StripeService($this->pdo);
            $payout = $this->createStripePayout($withdrawal);

            // Update with success
            $this->pdo->beginTransaction();

            $successStmt = $this->pdo->prepare(
                "UPDATE withdrawal_requests 
                 SET status = 'completed', stripe_payout_id = ?, processed_at = NOW() 
                 WHERE id = ?"
            );
            $successStmt->execute([$payout['id'] ?? null, $withdrawalId]);

            // Update transaction status
            $txnUpdateStmt = $this->pdo->prepare(
                "UPDATE wallet_transactions SET status = 'completed' WHERE withdrawal_id = ?"
            );
            $txnUpdateStmt->execute([$withdrawalId]);

            $this->pdo->commit();
            return true;
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            // Mark as failed
            $failStmt = $this->pdo->prepare(
                "UPDATE withdrawal_requests 
                 SET status = 'failed', error_message = ?, processed_at = NOW() 
                 WHERE id = ?"
            );
            $failStmt->execute([$e->getMessage(), $withdrawalId]);

            // Refund to wallet
            $this->refundFailedWithdrawal($withdrawalId);

            throw $e;
        }
    }

    /**
     * Create Stripe payout for withdrawal
     */
    private function createStripePayout(array $withdrawal): array
    {
        $payoutData = [
            'amount' => (int)($withdrawal['amount'] * 100),
            'currency' => 'usd',
            'destination' => $withdrawal['stripe_account_id'],
            'metadata' => [
                'withdrawal_id' => $withdrawal['id'],
                'user_id' => $withdrawal['user_id'],
            ]
        ];

        $headers = [
            'Authorization: Bearer ' . STRIPE_SECRET_KEY,
            'Content-Type: application/x-www-form-urlencoded',
            'Idempotency-Key: withdrawal_' . $withdrawal['id'],
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

        return json_decode($response, true);
    }

    /**
     * Refund failed withdrawal back to wallet
     */
    private function refundFailedWithdrawal(int $withdrawalId): void
    {
        $stmt = $this->pdo->prepare("SELECT * FROM withdrawal_requests WHERE id = ?");
        $stmt->execute([$withdrawalId]);
        $withdrawal = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$withdrawal) return;

        $this->pdo->beginTransaction();

        try {
            // Add amount back to wallet
            $wallet = $this->getWallet($withdrawal['user_id']);
            $newBalance = $wallet['balance'] + $withdrawal['amount'];

            $updateStmt = $this->pdo->prepare(
                "UPDATE seller_wallets SET balance = ? WHERE user_id = ?"
            );
            $updateStmt->execute([$newBalance, $withdrawal['user_id']]);

            // Record refund transaction
            $txnStmt = $this->pdo->prepare(
                "INSERT INTO wallet_transactions 
                 (user_id, type, amount, balance_after, withdrawal_id, description, status) 
                 VALUES (?, 'refund', ?, ?, ?, 'Withdrawal failed - refunded', 'completed')"
            );
            $txnStmt->execute([$withdrawal['user_id'], $withdrawal['amount'], $newBalance, $withdrawalId]);

            // Mark original withdrawal transaction as failed
            $failTxnStmt = $this->pdo->prepare(
                "UPDATE wallet_transactions SET status = 'failed' WHERE withdrawal_id = ? AND type = 'withdrawal'"
            );
            $failTxnStmt->execute([$withdrawalId]);

            $this->pdo->commit();
        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log("Failed to refund withdrawal {$withdrawalId}: " . $e->getMessage());
        }
    }

    /**
     * Get pending withdrawals (for admin/cron processing)
     */
    public function getPendingWithdrawals(int $limit = 50): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT wr.*, u.full_name, u.email, u.stripe_account_id
             FROM withdrawal_requests wr
             JOIN users u ON wr.user_id = u.id
             WHERE wr.status = 'pending'
             ORDER BY wr.requested_at ASC
             LIMIT :limit"
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
