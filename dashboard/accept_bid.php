<?php
require_once "../includes/auth.php";
require_once "../config/database.php";

if ($_SESSION['role'] !== 'buyer') {
    die("Access denied");
}

$bid_id = isset($_GET['bid_id']) ? (int) $_GET['bid_id'] : 0;
if ($bid_id <= 0) {
    die("Invalid bid");
}

// Fetch bid with project to ensure ownership
$stmt = $pdo->prepare(
    "SELECT bids.*, projects.buyer_id, projects.status AS project_status
   FROM bids
   JOIN projects ON bids.project_id = projects.id
   WHERE bids.id = ?"
);
$stmt->execute([$bid_id]);
$bid = $stmt->fetch();

if (!$bid) {
    die("Bid not found");
}

if ((int) $bid['buyer_id'] !== (int) $_SESSION['user_id']) {
    die("Not your project");
}

if ($bid['project_status'] !== 'open') {
    header("Location: project_view.php?id=" . $bid['project_id']);
    exit;
}

try {
    $pdo->beginTransaction();

    // Reject other bids
    $reject = $pdo->prepare("UPDATE bids SET status = 'rejected' WHERE project_id = ?");
    $reject->execute([$bid['project_id']]);

    // Accept chosen bid
    $accept = $pdo->prepare("UPDATE bids SET status = 'accepted' WHERE id = ?");
    $accept->execute([$bid_id]);

    // Create escrow record (pending)
    $escrow = $pdo->prepare(
        "INSERT INTO escrow (project_id, buyer_id, seller_id, amount, status, created_at)
     VALUES (?, ?, ?, ?, 'pending', NOW())"
    );
    $escrow->execute([
        $bid['project_id'],
        $bid['buyer_id'],
        $bid['seller_id'],
        $bid['amount']
    ]);

    // Move project to in_progress
    $proj = $pdo->prepare("UPDATE projects SET status = 'in_progress' WHERE id = ?");
    $proj->execute([$bid['project_id']]);

    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    die("Error accepting bid: " . $e->getMessage());
}

header("Location: project_view.php?id=" . $bid['project_id']);
exit;
