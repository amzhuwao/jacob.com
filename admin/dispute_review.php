<?php

/**
 * Admin - Dispute Review & Resolution
 * 
 * Detailed view for admin to review dispute and resolve it
 * Combines messages, evidence, and resolution interface
 */

require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/EscrowStateMachine.php';

// Require admin using shared helper
requireRole('admin');

$userId = $_SESSION['user_id'];
$disputeId = (int)($_GET['id'] ?? 0);

if (!$disputeId) {
    header('Location: /admin/disputes_list.php');
    exit;
}

// Get dispute details
$stmt = $pdo->prepare("
    SELECT d.*, e.*, p.title as project_title,
           u_opener.username as opener_name, u_opener.full_name as opener_fullname,
           u_buyer.username as buyer_name, u_buyer.full_name as buyer_fullname,
           u_seller.username as seller_name, u_seller.full_name as seller_fullname,
           u_resolved.username as resolved_by_name
    FROM disputes d
    JOIN escrow e ON d.escrow_id = e.id
    JOIN projects p ON e.project_id = p.id
    JOIN users u_opener ON d.opened_by = u_opener.id
    JOIN users u_buyer ON e.buyer_id = u_buyer.id
    JOIN users u_seller ON e.seller_id = u_seller.id
    LEFT JOIN users u_resolved ON d.resolved_by = u_resolved.id
    WHERE d.id = ?
");
$stmt->execute([$disputeId]);
$dispute = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$dispute) {
    http_response_code(404);
    echo 'Dispute not found';
    exit;
}

// Get messages
$stmt = $pdo->prepare("
    SELECT dm.*, u.username, u.full_name
    FROM dispute_messages dm
    JOIN users u ON dm.user_id = u.id
    WHERE dm.dispute_id = ?
    ORDER BY dm.created_at ASC
");
$stmt->execute([$disputeId]);
$messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get evidence
$stmt = $pdo->prepare("
    SELECT de.*, u.username
    FROM dispute_evidence de
    JOIN users u ON de.uploaded_by = u.id
    WHERE de.dispute_id = ?
    ORDER BY de.uploaded_at DESC
");
$stmt->execute([$disputeId]);
$evidence = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle resolution
$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $dispute['status'] === 'open') {
    $action = $_POST['action'] ?? '';
    $resolution_notes = trim($_POST['resolution_notes'] ?? '');

    if (!in_array($action, ['refund_buyer', 'release_to_seller', 'split'])) {
        $error = 'Invalid resolution action';
    } elseif ($action === 'split' && empty($_POST['split_ratio'])) {
        $error = 'Split ratio required';
    } else {
        try {
            $pdo->beginTransaction();

            // Update dispute
            $stmt = $pdo->prepare("
                UPDATE disputes 
                SET status = 'resolved', 
                    resolved_by = ?,
                    resolved_at = NOW(),
                    resolution = ?
                WHERE id = ?
            ");
            $stmt->execute([$userId, $action, $disputeId]);

            // State machine transition
            $stateMachine = new EscrowStateMachine($pdo);

            switch ($action) {
                case 'refund_buyer':
                    $stateMachine->transition(
                        (int)$dispute['escrow_id'],
                        'refunded',
                        'admin',
                        $userId,
                        "Dispute #{$disputeId} resolved: Full refund to buyer. Notes: {$resolution_notes}"
                    );
                    break;

                case 'release_to_seller':
                    $stateMachine->transition(
                        (int)$dispute['escrow_id'],
                        'released',
                        'admin',
                        $userId,
                        "Dispute #{$disputeId} resolved: Full release to seller. Notes: {$resolution_notes}"
                    );
                    break;

                case 'split':
                    $split_ratio = (float)$_POST['split_ratio'] / 100;
                    $buyer_amount = $dispute['amount'] * $split_ratio;
                    $seller_amount = $dispute['amount'] * (1 - $split_ratio);

                    // Record split resolution
                    $stmt = $pdo->prepare("
                        INSERT INTO dispute_resolutions (dispute_id, resolution_type, buyer_amount, seller_amount)
                        VALUES (?, 'split', ?, ?)
                    ");
                    $stmt->execute([$disputeId, $buyer_amount, $seller_amount]);
                    break;
            }

            // Add resolution message
            $stmt = $pdo->prepare("
                INSERT INTO dispute_messages (dispute_id, user_id, message, created_at)
                VALUES (?, ?, ?, NOW())
            ");
            $resolutionMsg = "ADMIN RESOLUTION: {$action}. Notes: {$resolution_notes}";
            $stmt->execute([$disputeId, $userId, $resolutionMsg]);

            $pdo->commit();
            $success = "Dispute resolved successfully";

            // Refresh dispute data
            $stmt = $pdo->prepare("SELECT d.*, e.* FROM disputes d JOIN escrow e ON d.escrow_id = e.id WHERE d.id = ?");
            $stmt->execute([$disputeId]);
            $dispute = $stmt->fetch(PDO::FETCH_ASSOC);

            // Refresh messages
            $stmt = $pdo->prepare("
                SELECT dm.*, u.username, u.full_name
                FROM dispute_messages dm
                JOIN users u ON dm.user_id = u.id
                WHERE dm.dispute_id = ?
                ORDER BY dm.created_at ASC
            ");
            $stmt->execute([$disputeId]);
            $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Error resolving dispute: ' . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dispute Review - Jacob</title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="stylesheet" href="/assets/css/dashboard.css">
    <script src="https://kit.fontawesome.com/a2e0e6a6e8.js" crossorigin="anonymous"></script>
</head>

<body>

    <div class="dashboard-wrapper">
        <aside class="sidebar">
            <div class="sidebar-brand">
                <span>✨</span>
                <span>Jacob Admin</span>
            </div>
            <ul class="sidebar-nav">
                <li><a href="/dashboard/admin_dashboard.php"><span>📊</span> <span>Dashboard</span></a></li>
                <li><a href="/dashboard/admin_escrows.php"><span>📋</span> <span>Escrows</span></a></li>
                <li><a href="/dashboard/admin_dashboard.php#withdrawals"><span>💸</span> <span>Withdrawals</span></a></li>
                <li><a href="/dashboard/admin_wallet_backfill.php"><span>🔄</span> <span>Wallet Backfill</span></a></li>
                <li><a href="/admin/disputes_list.php" class="active"><span>⚖️</span> <span>Disputes</span></a></li>
            </ul>
            <div style="margin-top: auto; padding-top: 1rem; border-top: 1px solid rgba(255,255,255,0.1);">
                <a href="/auth/logout.php" style="display: flex; align-items: center; gap: 1rem; color: rgba(255,255,255,0.7); text-decoration: none; padding: 0.75rem 1rem; border-radius: 0.75rem;">
                    <span>🚪</span>
                    <span>Logout</span>
                </a>
            </div>
        </aside>

        <main class="main-content">
            <div class="dashboard-header">
                <div class="header-left">
                    <button class="toggle-sidebar" onclick="toggleSidebar()">☰</button>
                    <div class="search-bar">
                        <input type="text" placeholder="Search disputes...">
                    </div>
                </div>
                <div class="header-right">
                    <div class="user-profile">
                        <div class="avatar"><?php echo strtoupper(substr($_SESSION['name'] ?? 'A', 0, 1)); ?></div>
                        <span><?php echo htmlspecialchars($_SESSION['name'] ?? 'Admin'); ?></span>
                    </div>
                </div>
            </div>

            <section class="section dashboard-content" style="max-width: 1200px;">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <h2 class="section-title">⚖️ Dispute Review #<?php echo $disputeId; ?></h2>
                    <a href="/admin/disputes_list.php" class="btn btn-secondary">← Back to List</a>
                </div>

                <?php if ($error): ?>
                    <div class="alert alert-danger" role="alert">
                        <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="alert alert-success" role="alert">
                        <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
                    </div>
                <?php endif; ?>
                <div class="row">
                    <div class="col-md-8">
                        <!-- Dispute Summary Card -->
                        <div class="card border-0 shadow-sm mb-4">
                            <div class="card-header bg-warning text-dark">
                                <h5 class="mb-0">
                                    <i class="fas fa-info-circle"></i> Dispute Summary
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <p class="mb-2">
                                            <strong>Project:</strong><br>
                                            <?php echo htmlspecialchars($dispute['project_title']); ?>
                                        </p>
                                    </div>
                                    <div class="col-md-6">
                                        <p class="mb-2">
                                            <strong>Amount:</strong><br>
                                            <span style="font-size: 1.3em; color: #d32f2f;">$<?php echo number_format($dispute['amount'], 2); ?></span>
                                        </p>
                                    </div>
                                </div>

                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <p class="mb-2">
                                            <strong>Status:</strong><br>
                                            <span class="badge <?php echo ($dispute['status'] === 'open') ? 'bg-danger' : 'bg-success'; ?>" style="font-size: 0.9em;">
                                                <?php echo ucfirst($dispute['status']); ?>
                                            </span>
                                        </p>
                                    </div>
                                    <div class="col-md-6">
                                        <p class="mb-2">
                                            <strong>Opened:</strong><br>
                                            <?php echo date('M d, Y H:i', strtotime($dispute['opened_at'])); ?>
                                        </p>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-6">
                                        <p class="mb-0">
                                            <strong>Opened By:</strong><br>
                                            <?php echo htmlspecialchars($dispute['opener_fullname'] ?? $dispute['opener_name']); ?>
                                            <small class="text-muted">(@<?php echo $dispute['opener_name']; ?>)</small>
                                        </p>
                                    </div>
                                    <div class="col-md-6">
                                        <p class="mb-0">
                                            <strong>Parties:</strong><br>
                                            Buyer: <?php echo htmlspecialchars($dispute['buyer_fullname'] ?? $dispute['buyer_name']); ?><br>
                                            Seller: <?php echo htmlspecialchars($dispute['seller_fullname'] ?? $dispute['seller_name']); ?>
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="card border-0 shadow-sm mb-4">
                            <div class="card-header bg-light">
                                <h5 class="mb-0">Initial Dispute Reason</h5>
                            </div>
                            <div class="card-body">
                                <div style="background: #f8f9fa; padding: 15px; border-radius: 5px; border-left: 4px solid #0d6efd;">
                                    <?php echo nl2br(htmlspecialchars($dispute['reason'])); ?>
                                </div>
                            </div>
                        </div>

                        <!-- Messages Thread -->
                        <div class="card border-0 shadow-sm mb-4">
                            <div class="card-header bg-light">
                                <h5 class="mb-0">
                                    <i class="fas fa-comments"></i> Message Thread (<?php echo count($messages); ?> messages)
                                </h5>
                            </div>
                            <div class="card-body" style="max-height: 500px; overflow-y: auto;">
                                <?php foreach ($messages as $msg): ?>
                                    <div class="mb-3 p-3 rounded" style="background: #f8f9fa; border-left: 3px solid #0d6efd;">
                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                            <strong>
                                                <?php echo htmlspecialchars($msg['full_name'] ?? $msg['username']); ?>
                                                <small class="text-muted">(@<?php echo $msg['username']; ?>)</small>
                                            </strong>
                                            <small class="text-muted">
                                                <?php echo date('M d, Y H:i', strtotime($msg['created_at'])); ?>
                                            </small>
                                        </div>
                                        <p class="mb-0">
                                            <?php
                                            $msgText = htmlspecialchars($msg['message']);
                                            // Highlight admin resolutions
                                            if (strpos($msg['message'], 'ADMIN RESOLUTION') !== false) {
                                                $msgText = '<strong style="color: #d32f2f;">' . $msgText . '</strong>';
                                            }
                                            echo nl2br($msgText);
                                            ?>
                                        </p>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Evidence Files -->
                        <div class="card border-0 shadow-sm">
                            <div class="card-header bg-light">
                                <h5 class="mb-0">
                                    <i class="fas fa-paperclip"></i> Evidence Files (<?php echo count($evidence); ?> files)
                                </h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($evidence)): ?>
                                    <p class="text-muted mb-0">No evidence files uploaded</p>
                                <?php else: ?>
                                    <div class="list-group">
                                        <?php foreach ($evidence as $file): ?>
                                            <div class="list-group-item d-flex justify-content-between align-items-center">
                                                <div>
                                                    <h6 class="mb-1">
                                                        <i class="fas fa-file"></i> <?php echo htmlspecialchars($file['filename']); ?>
                                                    </h6>
                                                    <small class="text-muted">
                                                        By <?php echo htmlspecialchars($file['username']); ?> on
                                                        <?php echo date('M d, Y H:i', strtotime($file['uploaded_at'])); ?>
                                                        (<?php echo number_format($file['file_size'] / 1024, 0); ?> KB)
                                                    </small>
                                                </div>
                                                <a href="<?php echo htmlspecialchars($file['file_path']); ?>"
                                                    class="btn btn-sm btn-outline-primary" download>
                                                    <i class="fas fa-download"></i> Download
                                                </a>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <?php if ($dispute['status'] === 'open'): ?>
                            <div class="card border-danger shadow-sm mb-4">
                                <div class="card-header bg-danger text-white">
                                    <h5 class="mb-0"><i class="fas fa-gavel"></i> Resolve Dispute</h5>
                                </div>
                                <div class="card-body">
                                    <form method="POST" class="needs-validation">
                                        <!-- Action Selection -->
                                        <div class="mb-3">
                                            <label for="action" class="form-label"><strong>Resolution Action</strong></label>
                                            <select name="action" id="action" class="form-select" required>
                                                <option value="">-- Select action --</option>
                                                <option value="refund_buyer">
                                                    <i class="fas fa-undo"></i> Full Refund to Buyer
                                                </option>
                                                <option value="release_to_seller">
                                                    <i class="fas fa-check"></i> Release to Seller
                                                </option>
                                                <option value="split">
                                                    <i class="fas fa-columns"></i> Split Payment
                                                </option>
                                            </select>
                                        </div>

                                        <!-- Split Ratio (shown only for split) -->
                                        <div class="mb-3" id="split-options" style="display: none;">
                                            <label for="split_ratio" class="form-label"><strong>Buyer's Share (%)</strong></label>
                                            <input type="number" name="split_ratio" id="split_ratio"
                                                class="form-control" min="0" max="100" value="50" step="5">
                                            <small class="text-muted d-block mt-1">
                                                Seller gets: <span id="seller_share">50</span>%
                                            </small>
                                        </div>

                                        <!-- Notes -->
                                        <div class="mb-3">
                                            <label for="resolution_notes" class="form-label"><strong>Resolution Notes</strong></label>
                                            <textarea name="resolution_notes" id="resolution_notes"
                                                class="form-control" rows="4"
                                                placeholder="Explain your decision and reasoning..."></textarea>
                                        </div>

                                        <!-- Submit -->
                                        <button type="submit" class="btn btn-danger w-100">
                                            <i class="fas fa-check"></i> Resolve Dispute
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php else: ?>
                            <!-- Resolution Summary (if already resolved) -->
                            <div class="card border-success shadow-sm">
                                <div class="card-header bg-success text-white">
                                    <h5 class="mb-0"><i class="fas fa-check-circle"></i> Resolved</h5>
                                </div>
                                <div class="card-body">
                                    <p class="mb-2">
                                        <strong>Resolution Type:</strong><br>
                                        <span class="badge bg-success">
                                            <?php echo ucfirst(str_replace('_', ' ', $dispute['resolution'])); ?>
                                        </span>
                                    </p>
                                    <p class="mb-2">
                                        <strong>Resolved By:</strong><br>
                                        <?php echo htmlspecialchars($dispute['resolved_by_name'] ?? 'Unknown'); ?>
                                    </p>
                                    <p class="mb-0">
                                        <strong>Resolved On:</strong><br>
                                        <?php echo date('M d, Y H:i', strtotime($dispute['resolved_at'])); ?>
                                    </p>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Quick Info -->
                        <div class="card border-0 shadow-sm bg-light">
                            <div class="card-body">
                                <h6 class="mb-3"><i class="fas fa-info-circle"></i> Quick Info</h6>
                                <p class="small mb-2">
                                    <strong>Escrow Status:</strong><br>
                                    <span class="text-capitalize"><?php echo $dispute['status']; ?></span>
                                </p>
                                <p class="small mb-2">
                                    <strong>Messages:</strong> <span class="badge bg-info"><?php echo count($messages); ?></span>
                                </p>
                                <p class="small mb-0">
                                    <strong>Evidence:</strong> <span class="badge bg-secondary"><?php echo count($evidence); ?></span>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </main>
    </div>

    <script>
        // Handle split ratio calculation
        document.getElementById('action')?.addEventListener('change', function() {
            const splitOptions = document.getElementById('split-options');
            if (this.value === 'split') {
                splitOptions.style.display = 'block';
            } else {
                splitOptions.style.display = 'none';
            }
        });

        document.getElementById('split_ratio')?.addEventListener('input', function() {
            document.getElementById('seller_share').textContent = (100 - this.value);
        });
    </script>

</body>

</html>