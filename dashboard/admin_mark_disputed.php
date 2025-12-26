<?php

/**
 * Admin - Mark Escrow as Disputed
 * 
 * Allows admin to manually transition an escrow to 'disputed' status
 * This creates the dispute entry that users can then populate with details
 */

require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/EscrowStateMachine.php';

// Check admin role
if (($_SESSION['role'] ?? null) !== 'admin') {
    http_response_code(403);
    die('Admin access required');
}

$userId = $_SESSION['user_id'];
$escrowId = (int)($_GET['escrow_id'] ?? $_POST['escrow_id'] ?? 0);
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Handle GET request - show confirmation form
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'mark_disputed' && $escrowId > 0) {
    // Get escrow details
    $stmt = $pdo->prepare("
        SELECT e.*, p.title as project_title, p.id as project_id,
               u_buyer.username as buyer_name, u_buyer.full_name as buyer_fullname,
               u_seller.username as seller_name, u_seller.full_name as seller_fullname
        FROM escrow e
        JOIN projects p ON e.project_id = p.id
        JOIN users u_buyer ON e.buyer_id = u_buyer.id
        JOIN users u_seller ON e.seller_id = u_seller.id
        WHERE e.id = ?
    ");
    $stmt->execute([$escrowId]);
    $escrow = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$escrow) {
        die('Escrow not found');
    }

    // Check if already disputed
    if ($escrow['status'] === 'disputed') {
        header('Location: /dashboard/admin_escrows.php?error=already_disputed');
        exit;
    }

    // Check if in valid state for dispute
    if ($escrow['status'] !== 'funded') {
        header('Location: /dashboard/admin_escrows.php?error=invalid_status');
        exit;
    }

    require_once '../includes/header.php';
?>

    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card border-warning">
                    <div class="card-header bg-warning text-dark">
                        <h4 class="mb-0">
                            <i class="fas fa-exclamation-triangle"></i>
                            Mark Escrow as Disputed
                        </h4>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-warning">
                            <strong>Warning:</strong> This will transition the escrow to disputed status, allowing buyer or seller to open a formal dispute case.
                        </div>

                        <h5>Escrow Details</h5>
                        <table class="table table-bordered">
                            <tr>
                                <th width="30%">Project</th>
                                <td><?php echo htmlspecialchars($escrow['project_title']); ?></td>
                            </tr>
                            <tr>
                                <th>Amount</th>
                                <td class="text-danger"><strong>$<?php echo number_format($escrow['amount'], 2); ?></strong></td>
                            </tr>
                            <tr>
                                <th>Buyer</th>
                                <td><?php echo htmlspecialchars($escrow['buyer_fullname']); ?> (@<?php echo htmlspecialchars($escrow['buyer_name']); ?>)</td>
                            </tr>
                            <tr>
                                <th>Seller</th>
                                <td><?php echo htmlspecialchars($escrow['seller_fullname']); ?> (@<?php echo htmlspecialchars($escrow['seller_name']); ?>)</td>
                            </tr>
                            <tr>
                                <th>Current Status</th>
                                <td><span class="badge bg-success"><?php echo ucfirst($escrow['status']); ?></span></td>
                            </tr>
                        </table>

                        <form method="POST" action="/dashboard/admin_mark_disputed.php">
                            <input type="hidden" name="escrow_id" value="<?php echo $escrowId; ?>">
                            <input type="hidden" name="action" value="confirm_mark_disputed">

                            <div class="mb-3">
                                <label for="reason" class="form-label">Admin Reason for Marking as Disputed</label>
                                <textarea name="reason" id="reason" class="form-control" rows="4" required
                                    placeholder="Explain why you're marking this escrow as disputed..."></textarea>
                                <small class="text-muted">
                                    This will be recorded in the audit log. The buyer/seller will provide their own dispute details.
                                </small>
                            </div>

                            <div class="d-flex justify-content-between">
                                <a href="/dashboard/admin_escrows.php" class="btn btn-secondary">
                                    <i class="fas fa-times"></i> Cancel
                                </a>
                                <button type="submit" class="btn btn-warning">
                                    <i class="fas fa-exclamation-triangle"></i> Confirm - Mark as Disputed
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

<?php
    require_once '../includes/footer.php';
    exit;
}

// Handle POST request - process the dispute marking
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'confirm_mark_disputed' && $escrowId > 0) {
    $reason = trim($_POST['reason'] ?? '');

    if (empty($reason)) {
        header('Location: /dashboard/admin_mark_disputed.php?escrow_id=' . $escrowId . '&action=mark_disputed&error=missing_reason');
        exit;
    }

    try {
        $pdo->beginTransaction();

        // Get escrow details
        $stmt = $pdo->prepare("SELECT id, status, project_id FROM escrow WHERE id = ? FOR UPDATE");
        $stmt->execute([$escrowId]);
        $escrow = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$escrow) {
            throw new RuntimeException('Escrow not found');
        }

        if ($escrow['status'] === 'disputed') {
            throw new RuntimeException('Escrow is already disputed');
        }

        if ($escrow['status'] !== 'funded') {
            throw new RuntimeException('Can only mark funded escrows as disputed');
        }

        // Use state machine to transition
        $stateMachine = new EscrowStateMachine($pdo);
        $stateMachine->transition(
            $escrowId,
            'disputed',
            'admin',
            $userId,
            "Admin marked as disputed: {$reason}"
        );

        $pdo->commit();

        // Redirect to admin escrows with success message
        header('Location: /dashboard/admin_escrows.php?success=marked_disputed&escrow_id=' . $escrowId);
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Failed to mark escrow as disputed: " . $e->getMessage());
        header('Location: /dashboard/admin_escrows.php?error=transition_failed&message=' . urlencode($e->getMessage()));
        exit;
    }
}

// Invalid request
header('Location: /dashboard/admin_escrows.php?error=invalid_request');
exit;
