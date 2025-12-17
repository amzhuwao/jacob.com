<?php
require_once "../includes/auth.php";
require_once "../config/database.php";
require_once "../config/stripe.php";

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

if ($escrow['status'] !== 'pending') {
    header("Location: project_view.php?id=" . $escrow['project_id']);
    exit;
}

$amountCents = (int) round($escrow['amount'] * 100);
if ($amountCents <= 0) {
    die("Invalid escrow amount");
}

// Create a Stripe Checkout Session (test mode)
try {
    $session = stripe_request('POST', '/v1/checkout/sessions', [
        'mode' => 'payment',
        'success_url' => absolute_url('/dashboard/fund_escrow_success.php?escrow_id=' . $escrow_id . '&session_id={CHECKOUT_SESSION_ID}'),
        'cancel_url' => absolute_url('/dashboard/project_view.php?id=' . $escrow['project_id']),
        'line_items[0][price_data][currency]' => 'usd',
        'line_items[0][price_data][product_data][name]' => 'Escrow Funding for Project #' . $escrow['project_id'],
        'line_items[0][price_data][unit_amount]' => $amountCents,
        'line_items[0][quantity]' => 1,
        'payment_intent_data[metadata][escrow_id]' => $escrow_id,
        'payment_intent_data[metadata][project_id]' => $escrow['project_id'],
    ]);
} catch (Exception $e) {
    die('Stripe error: ' . $e->getMessage());
}

if (!isset($session['url'])) {
    die('Unable to create Stripe Checkout Session.');
}

header('Location: ' . $session['url']);
exit;
