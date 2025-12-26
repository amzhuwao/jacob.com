<?php

/**
 * Approve Work
 * 
 * Allows buyer to approve work delivery and trigger auto-release of escrow
 * Sets buyer_approved_at timestamp and automatically releases funds
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
require_once '../includes/EscrowStateMachine.php';
require_once '../services/WalletService.php';

// Only buyers can approve work
if (($_SESSION['role'] ?? null) !== 'buyer') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Only buyers can approve work']);
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

    // Verify escrow exists, buyer is part of it, and work has been delivered
    $stmt = $pdo->prepare(
        "SELECT id, buyer_id, seller_id, project_id, status, work_delivered_at, buyer_approved_at 
         FROM escrow 
         WHERE id = ? AND buyer_id = ? FOR UPDATE"
    );
    $stmt->execute([$escrowId, $userId]);
    $escrow = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$escrow) {
        throw new RuntimeException('Escrow not found or you are not the buyer');
    }

    // Validations
    if ($escrow['status'] !== 'funded') {
        throw new RuntimeException('Escrow must be in funded status');
    }

    if ($escrow['work_delivered_at'] === null) {
        throw new RuntimeException('Seller must mark work as delivered first');
    }

    if ($escrow['buyer_approved_at'] !== null) {
        // Already approved - just return success
        $pdo->commit();
        echo json_encode([
            'status' => 'info',
            'message' => 'Work already approved. Escrow is being released...',
            'buyer_approved_at' => $escrow['buyer_approved_at']
        ]);
        exit;
    }

    // Mark work as approved
    $updateStmt = $pdo->prepare(
        "UPDATE escrow 
         SET buyer_approved_at = NOW() 
         WHERE id = ? AND buyer_id = ?"
    );
    $updateStmt->execute([$escrowId, $userId]);

    // Commit our transaction first (before state machine manages its own)
    $pdo->commit();

    // Credit seller's wallet instead of immediate payout
    $walletService = new WalletService($pdo);

    try {
        $walletService->creditEarnings(
            $escrow['seller_id'],
            $escrow['amount'],
            $escrowId,
            $escrow['project_id'],
            "Earnings from approved project"
        );
        error_log("Wallet credited for seller {$escrow['seller_id']}: \${$escrow['amount']} from escrow {$escrowId}");
    } catch (Exception $walletError) {
        // Log the error but don't fail the approval
        error_log("Wallet credit warning: " . $walletError->getMessage());
    }

    // Update to release_requested state (funds are in wallet, ready for withdrawal)
    $stateMachine = new EscrowStateMachine($pdo);

    try {
        $stateMachine->transition(
            $escrowId,
            'release_requested',
            'buyer_approval',
            $userId,
            'Buyer approved work. Funds credited to seller wallet.'
        );
    } catch (Exception $stateError) {
        // If state transition fails, log but don't fail
        error_log("State transition warning: " . $stateError->getMessage());
    }

    // Response - funds are in seller's wallet
    echo json_encode([
        'status' => 'success',
        'message' => 'Work approved! Funds have been added to seller\'s wallet.',
        'escrow_id' => $escrowId,
        'buyer_approved_at' => date('Y-m-d H:i:s'),
        'redirect' => '/dashboard/buyer.php'
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
