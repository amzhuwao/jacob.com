<?php

/**
 * Mark Work Delivered
 * 
 * Allows seller to notify buyer that work has been delivered
 * Sets work_delivered_at timestamp on escrow
 */

// Always return JSON
header('Content-Type: application/json');

// Catch fatal errors
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Fatal error: ' . $error['message']]);
    }
});

// Set error handling to catch non-fatal errors
set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Server error: ' . $errstr]);
    exit;
});

require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../services/EmailService.php';

// Only sellers can mark work as delivered
if (($_SESSION['role'] ?? null) !== 'seller') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Only sellers can mark work delivered']);
    exit;
}

$userId = $_SESSION['user_id'];
$escrowId = (int)($_POST['escrow_id'] ?? 0);

if (!$escrowId) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing escrow_id']);
    exit;
}

try {
    $pdo->beginTransaction();

    // Verify escrow exists and seller is part of it
    $stmt = $pdo->prepare(
        "SELECT id, seller_id, project_id, status, work_delivered_at 
         FROM escrow 
         WHERE id = ? AND seller_id = ? FOR UPDATE"
    );
    $stmt->execute([$escrowId, $userId]);
    $escrow = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$escrow) {
        throw new RuntimeException('Escrow not found or you are not the seller');
    }

    // Can only mark as delivered if funded and not yet delivered
    if ($escrow['status'] !== 'funded') {
        throw new RuntimeException('Escrow must be in funded status to mark work delivered');
    }

    if ($escrow['work_delivered_at'] !== null) {
        // Already marked as delivered
        $pdo->commit();
        echo json_encode([
            'status' => 'info',
            'message' => 'Work already marked as delivered',
            'work_delivered_at' => $escrow['work_delivered_at']
        ]);
        exit;
    }

    // Mark work as delivered
    $updateStmt = $pdo->prepare(
        "UPDATE escrow 
         SET work_delivered_at = NOW() 
         WHERE id = ? AND seller_id = ?"
    );
    $updateStmt->execute([$escrowId, $userId]);

    // Log to dispute_messages as a system message (optional - for transparency)
    $logStmt = $pdo->prepare(
        "INSERT INTO dispute_messages (dispute_id, user_id, message, created_at)
         SELECT id, ?, 'System: Seller marked work as delivered', NOW()
         FROM disputes WHERE escrow_id = ?"
    );
    // Note: This will fail if no dispute exists yet - that's OK, it's optional
    @$logStmt->execute([$userId, $escrowId]);

    $pdo->commit();

    // Send email to buyer about work delivery
    try {
        $emailService = new EmailService($pdo);

        // Get project and seller info
        $projStmt = $pdo->prepare("SELECT p.id, p.title, p.buyer_id, u.full_name FROM projects p JOIN users u ON p.id = u.id WHERE p.id = ?");
        $projStmt->execute([$escrow['project_id']]);
        $projInfo = $projStmt->fetch(PDO::FETCH_ASSOC);

        $sellerStmt = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
        $sellerStmt->execute([$userId]);
        $sellerInfo = $sellerStmt->fetch(PDO::FETCH_ASSOC);

        $projectStmt = $pdo->prepare("SELECT buyer_id, title FROM projects WHERE id = ?");
        $projectStmt->execute([$escrow['project_id']]);
        $projectInfo = $projectStmt->fetch(PDO::FETCH_ASSOC);

        $emailService->workDelivered($projectInfo['buyer_id'], $escrow['project_id'], $projectInfo['title'], $sellerInfo['full_name']);
    } catch (Exception $e) {
        // Email failure shouldn't fail the whole operation
        error_log("Email send failed in mark_work_delivered: " . $e->getMessage());
    }

    echo json_encode([
        'status' => 'success',
        'message' => 'Work marked as delivered. Waiting for buyer approval...',
        'escrow_id' => $escrowId,
        'work_delivered_at' => date('Y-m-d H:i:s')
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
