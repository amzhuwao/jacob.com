<?php
require_once "../includes/auth.php";
require_once "../config/database.php";
require_once "../config/stripe.php";

if ($_SESSION['role'] !== 'buyer') {
    die("Access denied");
}

$escrow_id = isset($_GET['escrow_id']) ? (int) $_GET['escrow_id'] : 0;
$session_id = $_GET['session_id'] ?? '';

if ($escrow_id <= 0 || empty($session_id)) {
    die('Missing parameters');
}

// Fetch escrow to confirm ownership
$stmt = $pdo->prepare("SELECT * FROM escrow WHERE id = ?");
$stmt->execute([$escrow_id]);
$escrow = $stmt->fetch();

if (!$escrow || (int) $escrow['buyer_id'] !== (int) $_SESSION['user_id']) {
    die('Unauthorized');
}

try {
    $session = stripe_request('GET', '/v1/checkout/sessions/' . urlencode($session_id));
} catch (Exception $e) {
    die('Stripe error: ' . $e->getMessage());
}

if (($session['payment_status'] ?? '') === 'paid') {
    // Mark escrow as funded
    $update = $pdo->prepare("UPDATE escrow SET status = 'funded' WHERE id = ?");
    $update->execute([$escrow_id]);
}

header('Location: project_view.php?id=' . $escrow['project_id']);
exit;
