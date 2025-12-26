<?php
require_once "../includes/auth.php";
require_once "../config/database.php";
require_once "../includes/EscrowStateMachine.php";
require_once "../services/StripeService.php";

if ($_SESSION['role'] !== 'admin') {
    die("Access denied - Admin only");
}

$stateMachine = new EscrowStateMachine($pdo);
$stripeService = new StripeService($pdo);

// Handle admin actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $escrow_id = (int)($_POST['escrow_id'] ?? 0);
    $reason = $_POST['reason'] ?? '';

    if ($escrow_id && $reason) {
        try {
            if ($action === 'release_escrow') {
                $escrow = $stateMachine->getEscrowState($escrow_id);

                // Transition to release_requested
                $stateMachine->transition(
                    $escrow_id,
                    'release_requested',
                    'admin',
                    $_SESSION['user_id'],
                    $reason
                );

                // Create Stripe payout (will trigger webhook when paid)
                try {
                    $payout = $stripeService->createPayout($escrow_id);

                    // Log admin action
                    $logStmt = $pdo->prepare(
                        "INSERT INTO admin_actions 
                         (admin_id, action_type, entity_type, entity_id, reason, previous_state, new_state)
                         VALUES (?, 'release_escrow', 'escrow', ?, ?, ?, 'release_requested')"
                    );
                    $logStmt->execute([$_SESSION['user_id'], $escrow_id, $reason, $escrow['status']]);

                    $successMessage = "Escrow #{$escrow_id} marked for release. Payout created: {$payout['id']}";
                } catch (Exception $payoutError) {
                    // Rollback state transition if payout fails
                    $stateMachine->transition(
                        $escrow_id,
                        'funded',
                        'system',
                        null,
                        'Payout creation failed: ' . $payoutError->getMessage()
                    );
                    throw $payoutError;
                }
            } elseif ($action === 'refund_escrow') {
                $escrow = $stateMachine->getEscrowState($escrow_id);

                // Transition to refund_requested
                $stateMachine->transition(
                    $escrow_id,
                    'refund_requested',
                    'admin',
                    $_SESSION['user_id'],
                    $reason
                );

                // Create Stripe refund (webhook will finalize)
                try {
                    $refund = $stripeService->createRefund($escrow_id, null, 'requested_by_customer');

                    // Log admin action
                    $logStmt = $pdo->prepare(
                        "INSERT INTO admin_actions 
                         (admin_id, action_type, entity_type, entity_id, reason, previous_state, new_state)
                         VALUES (?, 'refund_payment', 'escrow', ?, ?, ?, 'refund_requested')"
                    );
                    $logStmt->execute([$_SESSION['user_id'], $escrow_id, $reason, $escrow['status']]);

                    $successMessage = "Escrow #{$escrow_id} refund requested. Refund created: {$refund['id']}";
                } catch (Exception $refundError) {
                    // Rollback state transition if refund fails to create
                    $stateMachine->transition(
                        $escrow_id,
                        'funded',
                        'system',
                        null,
                        'Refund creation failed: ' . $refundError->getMessage()
                    );
                    throw $refundError;
                }
            }
        } catch (Exception $e) {
            $errorMessage = "Action failed: " . $e->getMessage();
        }
    }
}

// Fetch all escrows with details
$escrowsStmt = $pdo->query(
    "SELECT e.*, 
            p.title as project_title, p.status as project_status,
            b.full_name as buyer_name, b.email as buyer_email,
            s.full_name as seller_name, s.email as seller_email
     FROM escrow e
     JOIN projects p ON e.project_id = p.id
     JOIN users b ON e.buyer_id = b.id
     JOIN users s ON e.seller_id = s.id
     ORDER BY e.created_at DESC"
);
$escrows = $escrowsStmt->fetchAll();

// Fetch recent transactions
$transactionsStmt = $pdo->query(
    "SELECT pt.*, 
            p.title as project_title,
            b.full_name as buyer_name,
            s.full_name as seller_name
     FROM payment_transactions pt
     JOIN projects p ON pt.project_id = p.id
     JOIN users b ON pt.buyer_id = b.id
     JOIN users s ON pt.seller_id = s.id
     ORDER BY pt.created_at DESC
     LIMIT 50"
);
$transactions = $transactionsStmt->fetchAll();

// Fetch webhook events
$webhooksStmt = $pdo->query(
    "SELECT * FROM stripe_webhook_events 
     ORDER BY created_at DESC 
     LIMIT 50"
);
$webhooks = $webhooksStmt->fetchAll();

// Fetch admin actions
$adminActionsStmt = $pdo->query(
    "SELECT aa.*, u.full_name as admin_name
     FROM admin_actions aa
     JOIN users u ON aa.admin_id = u.id
     ORDER BY aa.created_at DESC
     LIMIT 50"
);
$adminActions = $adminActionsStmt->fetchAll();
?>

<!DOCTYPE html>
<html>

<head>
    <title>Transaction Audit - Admin</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f7fa;
            padding: 20px;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
        }

        h1 {
            color: #2d3748;
            margin-bottom: 10px;
        }

        .subtitle {
            color: #718096;
            margin-bottom: 30px;
        }

        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-weight: 600;
        }

        .alert-success {
            background: #c6f6d5;
            color: #22543d;
            border-left: 4px solid #48bb78;
        }

        .alert-error {
            background: #fed7d7;
            color: #742a2a;
            border-left: 4px solid #f56565;
        }

        .tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            border-bottom: 2px solid #e2e8f0;
        }

        .tab {
            padding: 12px 24px;
            background: none;
            border: none;
            cursor: pointer;
            font-size: 1em;
            font-weight: 600;
            color: #718096;
            transition: all 0.3s ease;
            border-bottom: 3px solid transparent;
        }

        .tab.active {
            color: #667eea;
            border-bottom-color: #667eea;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        .card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .card h2 {
            color: #2d3748;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e2e8f0;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            background: #f7fafc;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            color: #2d3748;
            border-bottom: 2px solid #e2e8f0;
        }

        td {
            padding: 12px;
            border-bottom: 1px solid #e2e8f0;
            color: #4a5568;
        }

        tr:hover {
            background: #f7fafc;
        }

        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 0.85em;
            font-weight: 600;
        }

        .badge-pending {
            background: #fef5e7;
            color: #7c4a00;
        }

        .badge-processing {
            background: #e3f2fd;
            color: #0d47a1;
        }

        .badge-funded,
        .badge-succeeded {
            background: #c6f6d5;
            color: #22543d;
        }

        .badge-release_requested {
            background: #bee3f8;
            color: #2c5282;
        }

        .badge-released {
            background: #c3e6cb;
            color: #155724;
        }

        .badge-failed {
            background: #fed7d7;
            color: #742a2a;
        }

        .badge-refunded {
            background: #fff3cd;
            color: #856404;
        }

        .badge-refund_requested {
            background: #fde68a;
            color: #7c2d12;
        }

        .badge-canceled {
            background: #e2e8f0;
            color: #4a5568;
        }

        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            font-size: 0.9em;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s ease;
        }

        .btn-primary {
            background: #667eea;
            color: white;
        }

        .btn-primary:hover {
            background: #5a67d8;
        }

        .btn-danger {
            background: #f56565;
            color: white;
        }

        .btn-danger:hover {
            background: #e53e3e;
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 0.85em;
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: white;
            padding: 30px;
            border-radius: 12px;
            max-width: 500px;
            width: 90%;
        }

        .modal-content h3 {
            margin-bottom: 20px;
            color: #2d3748;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #2d3748;
        }

        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: 10px;
            border: 2px solid #e2e8f0;
            border-radius: 6px;
            font-family: inherit;
        }

        .form-group textarea {
            min-height: 100px;
            resize: vertical;
        }

        .modal-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 25px;
            border-radius: 12px;
        }

        .stat-value {
            font-size: 2.5em;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 0.9em;
            opacity: 0.9;
        }

        .empty-state {
            text-align: center;
            padding: 40px;
            color: #718096;
        }
    </style>
</head>

<body>
    <div class="container">
        <h1>🔒 Transaction Audit & Control</h1>
        <p class="subtitle">Admin Dashboard - Monitor and manage all escrow transactions</p>

        <?php if (isset($successMessage)): ?>
            <div class="alert alert-success"><?= htmlspecialchars($successMessage) ?></div>
        <?php endif; ?>

        <?php if (isset($errorMessage)): ?>
            <div class="alert alert-error"><?= htmlspecialchars($errorMessage) ?></div>
        <?php endif; ?>

        <!-- Statistics -->
        <div class="stats-grid">
            <?php
            $stats = $pdo->query("
                SELECT 
                    COUNT(*) as total_escrows,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN status = 'funded' THEN 1 ELSE 0 END) as funded,
                    SUM(CASE WHEN status = 'released' THEN 1 ELSE 0 END) as released,
                    SUM(amount) as total_value,
                    SUM(CASE WHEN status = 'released' THEN amount ELSE 0 END) as released_value
                FROM escrow
            ")->fetch();
            ?>
            <div class="stat-card">
                <div class="stat-value"><?= $stats['total_escrows'] ?></div>
                <div class="stat-label">Total Escrows</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= $stats['funded'] ?></div>
                <div class="stat-label">Funded Escrows</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">$<?= number_format($stats['total_value'], 2) ?></div>
                <div class="stat-label">Total Value</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">$<?= number_format($stats['released_value'], 2) ?></div>
                <div class="stat-label">Released to Sellers</div>
            </div>
        </div>

        <!-- Tabs -->
        <div class="tabs">
            <button class="tab active" onclick="switchTab('escrows')">Escrows</button>
            <button class="tab" onclick="switchTab('transactions')">Transactions</button>
            <button class="tab" onclick="switchTab('webhooks')">Webhooks</button>
            <button class="tab" onclick="switchTab('admin-actions')">Admin Actions</button>
        </div>

        <!-- Escrows Tab -->
        <div id="escrows" class="tab-content active">
            <div class="card">
                <h2>All Escrow Records</h2>
                <?php if (empty($escrows)): ?>
                    <div class="empty-state">No escrow records found</div>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Project</th>
                                <th>Buyer</th>
                                <th>Seller</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th>Payment</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($escrows as $escrow): ?>
                                <tr>
                                    <td><?= $escrow['id'] ?></td>
                                    <td><?= htmlspecialchars($escrow['project_title']) ?></td>
                                    <td><?= htmlspecialchars($escrow['buyer_name']) ?></td>
                                    <td><?= htmlspecialchars($escrow['seller_name']) ?></td>
                                    <td>$<?= number_format($escrow['amount'], 2) ?></td>
                                    <td><span class="badge badge-<?= $escrow['status'] ?>"><?= str_replace('_', ' ', ucwords($escrow['status'], '_')) ?></span></td>
                                    <td><span class="badge badge-<?= $escrow['payment_status'] ?>"><?= ucfirst($escrow['payment_status']) ?></span></td>
                                    <td><?= date('M j, Y', strtotime($escrow['created_at'])) ?></td>
                                    <td>
                                        <?php if ($escrow['status'] === 'funded'): ?>
                                            <button class="btn btn-primary btn-sm" onclick="openReleaseModal(<?= $escrow['id'] ?>)">Release</button>
                                            <button class="btn btn-danger btn-sm" onclick="openRefundModal(<?= $escrow['id'] ?>)">Refund</button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- Transactions Tab -->
        <div id="transactions" class="tab-content">
            <div class="card">
                <h2>Payment Transactions</h2>
                <?php if (empty($transactions)): ?>
                    <div class="empty-state">No transactions found</div>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Project</th>
                                <th>Buyer</th>
                                <th>Seller</th>
                                <th>Type</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th>Stripe ID</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($transactions as $tx): ?>
                                <tr>
                                    <td><?= $tx['id'] ?></td>
                                    <td><?= htmlspecialchars($tx['project_title']) ?></td>
                                    <td><?= htmlspecialchars($tx['buyer_name']) ?></td>
                                    <td><?= htmlspecialchars($tx['seller_name']) ?></td>
                                    <td><?= ucfirst($tx['transaction_type']) ?></td>
                                    <td>$<?= number_format($tx['amount'], 2) ?></td>
                                    <td><span class="badge badge-<?= $tx['status'] ?>"><?= ucfirst($tx['status']) ?></span></td>
                                    <td><small><?= htmlspecialchars(substr($tx['stripe_payment_intent_id'], 0, 20)) ?>...</small></td>
                                    <td><?= date('M j, g:i A', strtotime($tx['created_at'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- Webhooks Tab -->
        <div id="webhooks" class="tab-content">
            <div class="card">
                <h2>Stripe Webhook Events</h2>
                <?php if (empty($webhooks)): ?>
                    <div class="empty-state">No webhook events found</div>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Event ID</th>
                                <th>Type</th>
                                <th>Processed</th>
                                <th>Attempts</th>
                                <th>Error</th>
                                <th>Created</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($webhooks as $webhook): ?>
                                <tr>
                                    <td><small><?= htmlspecialchars(substr($webhook['stripe_event_id'], 0, 20)) ?>...</small></td>
                                    <td><?= htmlspecialchars($webhook['event_type']) ?></td>
                                    <td><?= $webhook['processed'] ? '✓' : '✗' ?></td>
                                    <td><?= $webhook['processing_attempts'] ?></td>
                                    <td><small><?= htmlspecialchars(substr($webhook['last_error'] ?? '-', 0, 30)) ?></small></td>
                                    <td><?= date('M j, g:i A', strtotime($webhook['created_at'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- Admin Actions Tab -->
        <div id="admin-actions" class="tab-content">
            <div class="card">
                <h2>Admin Action History</h2>
                <?php if (empty($adminActions)): ?>
                    <div class="empty-state">No admin actions yet</div>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Admin</th>
                                <th>Action</th>
                                <th>Entity</th>
                                <th>Reason</th>
                                <th>State Change</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($adminActions as $action): ?>
                                <tr>
                                    <td><?= htmlspecialchars($action['admin_name']) ?></td>
                                    <td><?= str_replace('_', ' ', ucfirst($action['action_type'])) ?></td>
                                    <td><?= ucfirst($action['entity_type']) ?> #<?= $action['entity_id'] ?></td>
                                    <td><?= htmlspecialchars($action['reason']) ?></td>
                                    <td><?= $action['previous_state'] ?> → <?= $action['new_state'] ?></td>
                                    <td><?= date('M j, g:i A', strtotime($action['created_at'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Release Modal -->
    <div id="releaseModal" class="modal">
        <div class="modal-content">
            <h3>Release Escrow</h3>
            <form method="POST">
                <input type="hidden" name="action" value="release_escrow">
                <input type="hidden" name="escrow_id" id="release_escrow_id">

                <div class="form-group">
                    <label>Reason for Release *</label>
                    <textarea name="reason" required placeholder="Enter detailed reason for releasing this escrow..."></textarea>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('releaseModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Confirm Release</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Refund Modal -->
    <div id="refundModal" class="modal">
        <div class="modal-content">
            <h3>Refund Escrow</h3>
            <form method="POST">
                <input type="hidden" name="action" value="refund_escrow">
                <input type="hidden" name="escrow_id" id="refund_escrow_id">

                <div class="form-group">
                    <label>Reason for Refund *</label>
                    <textarea name="reason" required placeholder="Enter detailed reason for refunding this escrow..."></textarea>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('refundModal')">Cancel</button>
                    <button type="submit" class="btn btn-danger">Confirm Refund</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function switchTab(tabName) {
            // Hide all tabs
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });

            // Show selected tab
            document.getElementById(tabName).classList.add('active');
            event.target.classList.add('active');
        }

        function openReleaseModal(escrowId) {
            document.getElementById('release_escrow_id').value = escrowId;
            document.getElementById('releaseModal').classList.add('active');
        }

        function openRefundModal(escrowId) {
            document.getElementById('refund_escrow_id').value = escrowId;
            document.getElementById('refundModal').classList.add('active');
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
        }

        // Close modal on outside click
        document.querySelectorAll('.modal').forEach(modal => {
            modal.addEventListener('click', (e) => {
                if (e.target === modal) {
                    modal.classList.remove('active');
                }
            });
        });
    </script>
</body>

</html>