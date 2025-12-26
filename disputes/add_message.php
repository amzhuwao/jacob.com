<?php

/**
 * Add Message to Dispute
 * 
 * AJAX/POST endpoint to add a message to a dispute thread
 */

require_once '../config/database.php';
require_once '../includes/auth.php';

$userId = $_SESSION['user_id'] ?? null;
if (!$userId) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$disputeId = (int)($_POST['dispute_id'] ?? 0);
$message = trim($_POST['message'] ?? '');

if (!$disputeId || !$message) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing dispute ID or message']);
    exit;
}

try {
    // Verify user is a participant in this dispute
    $stmt = $pdo->prepare("
        SELECT e.buyer_id, e.seller_id
        FROM disputes d
        JOIN escrow e ON d.escrow_id = e.id
        WHERE d.id = ?
    ");
    $stmt->execute([$disputeId]);
    $dispute = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$dispute) {
        http_response_code(404);
        echo json_encode(['error' => 'Dispute not found']);
        exit;
    }

    // Check authorization (participant or admin)
    $isParticipant = ($userId === $dispute['buyer_id'] || $userId === $dispute['seller_id']);
    if (!$isParticipant && ($_SESSION['role'] ?? null) !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied']);
        exit;
    }

    // Insert message
    $stmt = $pdo->prepare("
        INSERT INTO dispute_messages (dispute_id, user_id, message, created_at)
        VALUES (?, ?, ?, NOW())
    ");
    $stmt->execute([$disputeId, $userId, $message]);

    // Redirect back to dispute view
    header("Location: /disputes/dispute_view.php?id={$disputeId}#messages", true, 303);
    exit;
} catch (Exception $e) {
    error_log("Error adding dispute message: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Failed to add message']);
    exit;
}
