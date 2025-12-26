<?php

/**
 * Stripe Webhook Handler
 * 
 * Processes Stripe webhooks for payment events
 * Implements idempotency and proper error handling
 * 
 * Configure in Stripe Dashboard:
 * Endpoint URL: https://your domain.com/webhooks/stripe.php
 * Events to send:
 * - payment_intent.created
 * - payment_intent.succeeded
 * - payment_intent.payment_failed
 * - payment_intent.canceled
 * - charge.refunded
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/stripe.php';
require_once __DIR__ . '/../includes/EscrowStateMachine.php';

// Set webhook secret (get from Stripe Dashboard)
define('STRIPE_WEBHOOK_SECRET', getenv('STRIPE_WEBHOOK_SECRET') ?: 'whsec_c24232838f54b4dd7fea891e0e37a918f45ca894b51f9a96deebc10bda1bcab2');

/**
 * Verify Stripe webhook signature (no Stripe SDK required)
 */
function verifyStripeSignature(string $payload, string $sigHeader, string $secret, int $tolerance = 300): bool
{
    // Stripe-Signature header format: t=timestamp,v1=signature
    $parts = [];
    foreach (explode(',', $sigHeader) as $pair) {
        [$k, $v] = array_map('trim', explode('=', $pair, 2));
        $parts[$k] = $v;
    }

    if (empty($parts['t']) || empty($parts['v1'])) {
        return false;
    }

    $timestamp = (int) $parts['t'];
    $signature = $parts['v1'];

    // Enforce replay protection
    if (abs(time() - $timestamp) > $tolerance) {
        return false;
    }

    $signedPayload = $timestamp . '.' . $payload;
    $expectedSig = hash_hmac('sha256', $signedPayload, $secret);

    return hash_equals($expectedSig, $signature);
}

/**
 * Log webhook event for debugging and audit
 */
function logWebhookEvent(PDO $pdo, string $eventId, string $eventType, array $payload): void
{
    $stmt = $pdo->prepare(
        "INSERT INTO stripe_webhook_events (stripe_event_id, event_type, payload, processed)
         VALUES (?, ?, ?, FALSE)
         ON DUPLICATE KEY UPDATE processing_attempts = processing_attempts + 1"
    );
    $stmt->execute([$eventId, $eventType, json_encode($payload)]);
}

/**
 * Mark webhook event as processing (before side effects)
 */
function markEventProcessing(PDO $pdo, string $eventId): void
{
    $stmt = $pdo->prepare(
        "UPDATE stripe_webhook_events 
         SET processing = TRUE, processing_attempts = processing_attempts + 1
         WHERE stripe_event_id = ?"
    );
    $stmt->execute([$eventId]);
}

/**
 * Mark webhook event as processed (after side effects succeed)
 */
function markEventProcessed(PDO $pdo, string $eventId, bool $success, ?string $error = null): void
{
    $stmt = $pdo->prepare(
        "UPDATE stripe_webhook_events 
         SET processed = ?, processing = FALSE, processed_at = NOW(), last_error = ?
         WHERE stripe_event_id = ?"
    );
    $stmt->execute([$success, $error, $eventId]);
}

/**
 * Check if webhook event is already being processed or has been processed
 */
function eventAlreadyProcessed(PDO $pdo, string $eventId): bool
{
    $stmt = $pdo->prepare(
        "SELECT processed, processing FROM stripe_webhook_events WHERE stripe_event_id = ?"
    );
    $stmt->execute([$eventId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row && ((int)$row['processed'] === 1 || (int)$row['processing'] === 1);
}

/**
 * Log payment transaction for audit trail
 */
function logPaymentTransaction(
    PDO $pdo,
    int $escrowId,
    int $projectId,
    int $buyerId,
    int $sellerId,
    string $paymentIntentId,
    ?string $chargeId,
    float $amount,
    string $type,
    string $status,
    ?string $failureReason = null,
    ?array $rawData = null
): void {
    $stmt = $pdo->prepare(
        "INSERT INTO payment_transactions 
         (escrow_id, project_id, buyer_id, seller_id, stripe_payment_intent_id, 
          stripe_charge_id, amount, transaction_type, status, failure_reason, stripe_raw_data, processed_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
    );
    $stmt->execute([
        $escrowId,
        $projectId,
        $buyerId,
        $sellerId,
        $paymentIntentId,
        $chargeId,
        $amount,
        $type,
        $status,
        $failureReason,
        $rawData ? json_encode($rawData) : null
    ]);
}

/**
 * Handle payment_intent.created event
 */
function handlePaymentIntentCreated(PDO $pdo, array $paymentIntent): void
{
    $paymentIntentId = $paymentIntent['id'];
    $escrowId = $paymentIntent['metadata']['escrow_id'] ?? null;

    if (!$escrowId) {
        error_log("No escrow_id in PaymentIntent metadata: {$paymentIntentId}");
        return;
    }

    // Only update if escrow still pending (prevent overwriting progress on retries)
    try {
        $checkStmt = $pdo->prepare("SELECT payment_status FROM escrow WHERE id = ?");
        $checkStmt->execute([(int)$escrowId]);
        $current = $checkStmt->fetchColumn();

        if ($current === false) {
            error_log("Escrow #{$escrowId} not found");
            return;
        }

        if ($current === 'pending') {
            $stateMachine = new EscrowStateMachine($pdo);
            $stateMachine->updatePaymentStatus((int)$escrowId, 'processing', $paymentIntentId);
            error_log("PaymentIntent created for escrow #{$escrowId}: {$paymentIntentId}");
        } else {
            error_log("PaymentIntent.created ignored: escrow #{$escrowId} already in {$current} status");
        }
    } catch (Exception $e) {
        error_log("Failed to process payment_intent.created: " . $e->getMessage());
        throw $e;
    }
}

/**
 * Handle payment_intent.succeeded event
 */
function handlePaymentIntentSucceeded(PDO $pdo, array $paymentIntent): void
{
    $paymentIntentId = $paymentIntent['id'];
    $escrowId = $paymentIntent['metadata']['escrow_id'] ?? null;
    $projectId = $paymentIntent['metadata']['project_id'] ?? null;

    if (!$escrowId || !$projectId) {
        error_log("Missing metadata in PaymentIntent: {$paymentIntentId}");
        return;
    }

    try {
        $pdo->beginTransaction();

        // Get escrow details
        $stmt = $pdo->prepare("SELECT * FROM escrow WHERE id = ? FOR UPDATE");
        $stmt->execute([(int)$escrowId]);
        $escrow = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$escrow) {
            throw new RuntimeException("Escrow #{$escrowId} not found");
        }

        // Transition escrow to funded state
        $stateMachine = new EscrowStateMachine($pdo);

        // Update payment status
        $stateMachine->updatePaymentStatus((int)$escrowId, 'succeeded', $paymentIntentId);

        // Transition escrow status from pending to funded
        $stateMachine->transition(
            (int)$escrowId,
            'funded',
            'webhook',
            null,
            'Payment succeeded via Stripe webhook',
            [
                'payment_intent_id' => $paymentIntentId,
                'amount' => $paymentIntent['amount'] / 100,
                'currency' => $paymentIntent['currency']
            ]
        );

        // Log transaction
        $chargeId = $paymentIntent['charges']['data'][0]['id'] ?? null;
        logPaymentTransaction(
            $pdo,
            (int)$escrowId,
            (int)$projectId,
            (int)$escrow['buyer_id'],
            (int)$escrow['seller_id'],
            $paymentIntentId,
            $chargeId,
            $paymentIntent['amount'] / 100,
            'charge',
            'succeeded',
            null,
            $paymentIntent
        );

        $pdo->commit();
        error_log("Escrow #{$escrowId} funded successfully via PaymentIntent {$paymentIntentId}");
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Failed to process payment_intent.succeeded: " . $e->getMessage());
        throw $e;
    }
}

/**
 * Handle payment_intent.payment_failed event
 */
function handlePaymentIntentFailed(PDO $pdo, array $paymentIntent): void
{
    $paymentIntentId = $paymentIntent['id'];
    $escrowId = $paymentIntent['metadata']['escrow_id'] ?? null;
    $projectId = $paymentIntent['metadata']['project_id'] ?? null;

    if (!$escrowId || !$projectId) {
        error_log("Missing metadata in failed PaymentIntent: {$paymentIntentId}");
        return;
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT * FROM escrow WHERE id = ? FOR UPDATE");
        $stmt->execute([(int)$escrowId]);
        $escrow = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$escrow) {
            throw new RuntimeException("Escrow #{$escrowId} not found");
        }

        // Update payment status to failed
        $stateMachine = new EscrowStateMachine($pdo);
        $stateMachine->updatePaymentStatus((int)$escrowId, 'failed', $paymentIntentId);

        // Log failed transaction
        $failureReason = $paymentIntent['last_payment_error']['message'] ?? 'Unknown error';
        logPaymentTransaction(
            $pdo,
            (int)$escrowId,
            (int)$projectId,
            (int)$escrow['buyer_id'],
            (int)$escrow['seller_id'],
            $paymentIntentId,
            null,
            $paymentIntent['amount'] / 100,
            'charge',
            'failed',
            $failureReason,
            $paymentIntent
        );

        $pdo->commit();
        error_log("Escrow #{$escrowId} payment failed: {$failureReason}");
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Failed to process payment_intent.payment_failed: " . $e->getMessage());
        throw $e;
    }
}

/**
 * Handle charge.refunded event
 */
function handleChargeRefunded(PDO $pdo, array $charge): void
{
    $paymentIntentId = $charge['payment_intent'] ?? null;

    if (!$paymentIntentId) {
        error_log("No payment_intent in refunded charge");
        return;
    }

    try {
        $pdo->beginTransaction();

        // Find escrow by payment intent
        $stmt = $pdo->prepare("SELECT * FROM escrow WHERE stripe_payment_intent_id = ? FOR UPDATE");
        $stmt->execute([$paymentIntentId]);
        $escrow = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$escrow) {
            $pdo->rollBack();
            error_log("No escrow found for PaymentIntent: {$paymentIntentId}");
            return;
        }

        // Only honor refund when previously requested
        if ($escrow['status'] !== 'refund_requested') {
            $pdo->commit();
            error_log("Refund webhook ignored: escrow not in refund_requested (escrow #{$escrow['id']} status {$escrow['status']})");
            return;
        }

        // Detect partial refund (refunded < charged)
        $isPartialRefund = (float)$charge['amount_refunded'] < (float)$charge['amount'];

        if ($isPartialRefund) {
            // Partial refunds move to disputed; require admin resolution
            $stateMachine = new EscrowStateMachine($pdo);
            $stateMachine->transition(
                (int)$escrow['id'],
                'disputed',
                'stripe_webhook',
                null,
                "Partial refund: {$charge['amount_refunded']} of {$charge['amount']} cents refunded via charge {$charge['id']}"
            );
            error_log("Escrow #{$escrow['id']} moved to disputed due to partial refund");
        } else {
            // Full refund → mark as refunded
            $stateMachine = new EscrowStateMachine($pdo);
            $stateMachine->transition(
                (int)$escrow['id'],
                'refunded',
                'stripe_webhook',
                null,
                "Charge fully refunded: {$charge['id']}"
            );
            error_log("Escrow #{$escrow['id']} fully refunded via charge {$charge['id']}");
        }

        // Log refund transaction
        logPaymentTransaction(
            $pdo,
            (int)$escrow['id'],
            (int)$escrow['project_id'],
            (int)$escrow['buyer_id'],
            (int)$escrow['seller_id'],
            $paymentIntentId,
            $charge['id'],
            $charge['amount_refunded'] / 100,
            'refund',
            $isPartialRefund ? 'partial' : 'succeeded',
            null,
            $charge
        );

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Failed to process charge.refunded: " . $e->getMessage());
        throw $e;
    }
}

/**
 * Handle transfer.paid event (when payout to seller succeeds)
 */
function handleTransferPaid(PDO $pdo, array $transfer): void
{
    $escrowId = $transfer['metadata']['escrow_id'] ?? null;
    $projectId = $transfer['metadata']['project_id'] ?? null;
    $sellerId = $transfer['metadata']['seller_id'] ?? null;

    if (!$escrowId || !$projectId || !$sellerId) {
        error_log("Missing metadata in transfer: {$transfer['id']}");
        return;
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT * FROM escrow WHERE id = ? FOR UPDATE");
        $stmt->execute([(int)$escrowId]);
        $escrow = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$escrow) {
            throw new RuntimeException("Escrow #{$escrowId} not found");
        }

        // Verify escrow is in release_requested state
        if ($escrow['status'] !== 'release_requested') {
            $pdo->rollBack();
            error_log("Transfer paid but escrow #{$escrowId} not in release_requested (current: {$escrow['status']})");
            return;
        }

        // Transition to released
        $stateMachine = new EscrowStateMachine($pdo);
        $stateMachine->transition(
            (int)$escrowId,
            'released',
            'stripe_webhook',
            null,
            "Payout successful: {$transfer['id']}"
        );

        // Log transaction
        logPaymentTransaction(
            $pdo,
            (int)$escrowId,
            (int)$projectId,
            (int)$escrow['buyer_id'],
            (int)$sellerId,
            $escrow['stripe_payment_intent_id'] ?? '',
            $transfer['id'],
            $transfer['amount'] / 100,
            'payout',
            'succeeded',
            null,
            $transfer
        );

        $pdo->commit();
        error_log("Escrow #{$escrowId} released via transfer {$transfer['id']}");
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Failed to process transfer.paid: " . $e->getMessage());
        throw $e;
    }
}

/**
 * Handle transfer.failed event
 */
function handleTransferFailed(PDO $pdo, array $transfer): void
{
    $escrowId = $transfer['metadata']['escrow_id'] ?? null;

    if (!$escrowId) {
        error_log("Missing escrow_id in failed transfer: {$transfer['id']}");
        return;
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT * FROM escrow WHERE id = ? FOR UPDATE");
        $stmt->execute([(int)$escrowId]);
        $escrow = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$escrow) {
            throw new RuntimeException("Escrow #{$escrowId} not found");
        }

        // Rollback to funded state if transfer fails
        if ($escrow['status'] === 'release_requested') {
            $stateMachine = new EscrowStateMachine($pdo);
            $failureReason = $transfer['failure_message'] ?? 'Unknown payout failure';
            $stateMachine->transition(
                (int)$escrowId,
                'funded',
                'stripe_webhook',
                null,
                "Payout failed: {$failureReason}"
            );

            // Log failed transaction
            logPaymentTransaction(
                $pdo,
                (int)$escrowId,
                (int)$escrow['project_id'],
                (int)$escrow['buyer_id'],
                (int)$escrow['seller_id'],
                $escrow['stripe_payment_intent_id'] ?? '',
                $transfer['id'],
                $transfer['amount'] / 100,
                'payout',
                'failed',
                $failureReason,
                $transfer
            );
        }

        $pdo->commit();
        error_log("Escrow #{$escrowId} payout failed: {$transfer['failure_message']}");
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Failed to process transfer.failed: " . $e->getMessage());
        throw $e;
    }
}

// =====================================================
// MAIN WEBHOOK HANDLER
// =====================================================

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

// Get webhook payload
$payload = @file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

if (!$payload || !$sigHeader) {
    http_response_code(400);
    exit('Missing payload or signature');
}

// Verify webhook signature (if secret is configured)
if (STRIPE_WEBHOOK_SECRET && !str_contains(STRIPE_WEBHOOK_SECRET, 'your_webhook_secret')) {
    $validSignature = verifyStripeSignature($payload, $sigHeader, STRIPE_WEBHOOK_SECRET);
    if (!$validSignature) {
        http_response_code(400);
        exit('Invalid webhook signature');
    }
}

// Parse event
$event = json_decode($payload, true);
if (!$event || !isset($event['type']) || !isset($event['id'])) {
    http_response_code(400);
    exit('Invalid event data');
}

$eventId = $event['id'];
$eventType = $event['type'];
$eventData = $event['data']['object'] ?? [];

// Log the webhook event
try {
    logWebhookEvent($pdo, $eventId, $eventType, $event);
} catch (Exception $e) {
    error_log("Failed to log webhook event: " . $e->getMessage());
}

// Idempotency gate: skip processing if already marked processed
if (eventAlreadyProcessed($pdo, $eventId)) {
    http_response_code(200);
    echo json_encode(['status' => 'duplicate', 'event_id' => $eventId]);
    exit;
}

// Mark event as processing BEFORE handlers (crash-safe)
try {
    markEventProcessing($pdo, $eventId);
} catch (Exception $e) {
    error_log("Failed to mark event processing: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Failed to mark event processing']);
    exit;
}

// Process event
try {
    switch ($eventType) {
        case 'payment_intent.created':
            handlePaymentIntentCreated($pdo, $eventData);
            break;

        case 'payment_intent.succeeded':
            handlePaymentIntentSucceeded($pdo, $eventData);
            break;

        case 'payment_intent.payment_failed':
            handlePaymentIntentFailed($pdo, $eventData);
            break;

        case 'charge.refunded':
            handleChargeRefunded($pdo, $eventData);
            break;

        case 'transfer.paid':
            handleTransferPaid($pdo, $eventData);
            break;

        case 'transfer.failed':
            handleTransferFailed($pdo, $eventData);
            break;

        default:
            error_log("Unhandled webhook event type: {$eventType}");
    }

    // Mark as successfully processed
    markEventProcessed($pdo, $eventId, true);

    http_response_code(200);
    echo json_encode(['status' => 'success', 'event_id' => $eventId]);
} catch (Exception $e) {
    error_log("Webhook processing error: " . $e->getMessage());
    markEventProcessed($pdo, $eventId, false, $e->getMessage());

    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
