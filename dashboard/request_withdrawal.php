<?php

/**
 * Request Withdrawal
 * 
 * Allows seller to request withdrawal from their wallet balance
 * Minimum withdrawal: $10.00
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

// Only sellers can request withdrawals
if (($_SESSION['role'] ?? null) !== 'seller') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Only sellers can request withdrawals']);
    exit;
}

$userId = $_SESSION['user_id'];
$amount = floatval($_POST['amount'] ?? 0);

// Validate amount
if ($amount <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid amount']);
    exit;
}

if ($amount < 10) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Minimum withdrawal is $10.00']);
    exit;
}

try {
    $walletService = new WalletService($pdo);

    // Request withdrawal (deducts from balance, adds to pending)
    $withdrawalId = $walletService->requestWithdrawal($userId, $amount);

    if (!$withdrawalId) {
        throw new Exception('Failed to create withdrawal request');
    }

    // Get updated wallet info
    $wallet = $walletService->getWallet($userId);

    echo json_encode([
        'status' => 'success',
        'message' => 'Withdrawal request submitted successfully! Funds will be processed within 1-3 business days.',
        'withdrawal_id' => $withdrawalId,
        'new_balance' => number_format($wallet['balance'], 2),
        'pending_balance' => number_format($wallet['pending_balance'], 2)
    ]);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
} catch (Exception $e) {
    error_log("Withdrawal request error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Failed to process withdrawal request: ' . $e->getMessage()]);
}
