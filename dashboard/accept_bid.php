<?php
require_once "../includes/auth.php";
require_once "../config/database.php";
require_once "../services/EmailService.php";

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

    // Enforce: one escrow per project
    $existingEscrow = $pdo->prepare("SELECT id FROM escrow WHERE project_id = ? LIMIT 1 FOR UPDATE");
    $existingEscrow->execute([$bid['project_id']]);
    if ($existingEscrow->fetch()) {
        $pdo->rollBack();
        header("Location: project_view.php?id=" . $bid['project_id']);
        exit;
    }

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

    // Send emails after successful transaction
    $emailService = new EmailService($pdo);

    // Get project and seller details
    $projStmt = $pdo->prepare("SELECT title FROM projects WHERE id = ?");
    $projStmt->execute([$bid['project_id']]);
    $project = $projStmt->fetch();

    $sellerStmt = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
    $sellerStmt->execute([$bid['seller_id']]);
    $seller = $sellerStmt->fetch();

    $buyerStmt = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
    $buyerStmt->execute([$bid['buyer_id']]);
    $buyer = $buyerStmt->fetch();

    // Send email to seller
    $emailService->projectAccepted($bid['seller_id'], $bid['project_id'], $project['title'], $buyer['full_name']);

    // Send email to buyer
    $emailService->projectAcceptedBuyer($bid['buyer_id'], $bid['project_id'], $project['title'], $seller['full_name'], $bid['amount']);
} catch (Exception $e) {
    $pdo->rollBack();
    die("Error accepting bid: " . $e->getMessage());
}

header("Location: project_view.php?id=" . $bid['project_id']);
exit;
