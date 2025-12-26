<?php

/**
 * Process Withdrawal - Admin Only
 * 
 * Allows admin to process pending withdrawal requests
 * Executes Stripe payout and updates withdrawal status
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
require_once '../services/WalletService.php';

// Only admins can process withdrawals
if (($_SESSION['role'] ?? null) !== 'admin') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Admin access required']);
    exit;
}

$withdrawalId = (int)($_POST['withdrawal_id'] ?? 0);

if (!$withdrawalId) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing withdrawal_id']);
    exit;
}

try {
    $walletService = new WalletService($pdo);

    // Process the withdrawal (creates Stripe payout)
    $result = $walletService->processWithdrawal($withdrawalId);

    if (!$result) {
        throw new Exception('Failed to process withdrawal');
    }

    echo json_encode([
        'status' => 'success',
        'message' => 'Withdrawal processed successfully! Payout initiated via Stripe.',
        'withdrawal_id' => $withdrawalId,
        'payout_id' => $result['payout_id'] ?? null
    ]);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
} catch (Exception $e) {
    error_log("Withdrawal processing error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Failed to process withdrawal: ' . $e->getMessage()]);
}
