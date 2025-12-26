<?php
require_once "../includes/auth.php";
require_once "../config/database.php";
require_once "../config/stripe.php";
require_once "../includes/EscrowStateMachine.php";

if ($_SESSION['role'] !== 'buyer') {
    die("Access denied");
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die("Invalid request");
}

$escrow_id = isset($_POST['escrow_id']) ? (int) $_POST['escrow_id'] : 0;

$stmt = $pdo->prepare("SELECT * FROM escrow WHERE id = ?");
$stmt->execute([$escrow_id]);
$escrow = $stmt->fetch();

if (!$escrow || (int) $escrow['buyer_id'] !== (int) $_SESSION['user_id']) {
    die("Unauthorized");
}

// Validate state transition
$stateMachine = new EscrowStateMachine($pdo);
$escrowState = $stateMachine->getEscrowState($escrow_id);

// Enforce: no funding if escrow already funded/released/refunded
if (in_array($escrowState['status'], ['funded', 'released', 'refunded'], true)) {
    header("Location: project_view.php?id=" . $escrow['project_id']);
    exit;
}

// Enforce: must be pending to initiate payment
if ($escrowState['status'] !== 'pending') {
    header("Location: project_view.php?id=" . $escrow['project_id']);
    exit;
}

// Check if payment already in progress or succeeded
if (in_array($escrowState['payment_status'], ['processing', 'succeeded'], true) && !empty($escrowState['stripe_payment_intent_id'])) {
    // PaymentIntent already exists, redirect to existing checkout
    try {
        $session = stripe_request('GET', '/v1/checkout/sessions/' . $escrowState['stripe_checkout_session_id']);
        if (isset($session['url']) && $session['status'] === 'open') {
            header('Location: ' . $session['url']);
            exit;
        }
    } catch (Exception $e) {
        // Session expired or invalid, create new one
    }
}

$amountCents = (int) round($escrow['amount'] * 100);
if ($amountCents <= 0) {
    die("Invalid escrow amount");
}

// Create Stripe PaymentIntent with proper metadata for webhook processing
try {
    // First create PaymentIntent
    $paymentIntent = stripe_request('POST', '/v1/payment_intents', [
        'amount' => $amountCents,
        'currency' => 'usd',
        'automatic_payment_methods[enabled]' => 'true',
        'metadata[escrow_id]' => $escrow_id,
        'metadata[project_id]' => $escrow['project_id'],
        'metadata[buyer_id]' => $escrow['buyer_id'],
        'metadata[seller_id]' => $escrow['seller_id'],
        'description' => 'Escrow funding for Project #' . $escrow['project_id']
    ]);

    $paymentIntentId = $paymentIntent['id'];

    // Update escrow with PaymentIntent ID
    $stateMachine->updatePaymentStatus($escrow_id, 'processing', $paymentIntentId);

    // Create Checkout Session using the PaymentIntent
    $session = stripe_request('POST', '/v1/checkout/sessions', [
        'mode' => 'payment',
        'payment_intent' => $paymentIntentId,
        'success_url' => absolute_url('/dashboard/fund_escrow_success.php?escrow_id=' . $escrow_id . '&session_id={CHECKOUT_SESSION_ID}'),
        'cancel_url' => absolute_url('/dashboard/project_view.php?id=' . $escrow['project_id']),
        'line_items[0][price_data][currency]' => 'usd',
        'line_items[0][price_data][product_data][name]' => 'Escrow Funding for Project #' . $escrow['project_id'],
        'line_items[0][price_data][unit_amount]' => $amountCents,
        'line_items[0][quantity]' => 1,
    ]);

    // Store checkout session ID for reference
    $updateStmt = $pdo->prepare(
        "UPDATE escrow SET stripe_checkout_session_id = ? WHERE id = ?"
    );
    $updateStmt->execute([$session['id'], $escrow_id]);

    // Log payment attempt
    $logStmt = $pdo->prepare(
        "INSERT INTO payment_transactions 
         (escrow_id, project_id, buyer_id, seller_id, stripe_payment_intent_id, amount, transaction_type, status)
         VALUES (?, ?, ?, ?, ?, ?, 'charge', 'pending')"
    );
    $logStmt->execute([
        $escrow_id,
        $escrow['project_id'],
        $escrow['buyer_id'],
        $escrow['seller_id'],
        $paymentIntentId,
        $escrow['amount']
    ]);
} catch (Exception $e) {
    error_log('Stripe error: ' . $e->getMessage());
    die('Payment system error: ' . $e->getMessage());
}

if (!isset($session['url'])) {
    die('Unable to create Stripe Checkout Session.');
}

// Redirect to Stripe Checkout
header('Location: ' . $session['url']);
exit;
