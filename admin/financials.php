<?php
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../services/AdminAuditService.php';
require_once '../services/WalletService.php';

requireRole('admin');

$auditService = new AdminAuditService($pdo);
$walletService = new WalletService($pdo);

// Get filter parameters
$typeFilter = $_GET['type'] ?? '';
$pageNum = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;

// Build query based on type filter
$where = 'WHERE 1=1';
$params = [];

if ($typeFilter) {
    $where .= ' AND t.type = ?';
    $params[] = $typeFilter;
}

// Count total
$countSql = "SELECT COUNT(*) FROM wallet_transactions t $where";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalTrans = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalTrans / $perPage));

// Get transactions
$offset = ($pageNum - 1) * $perPage;
$dataSql = "SELECT t.*, 
           u.full_name as user_name, u.email as user_email, u.role
    FROM wallet_transactions t 
    JOIN users u ON t.user_id = u.id
    $where
    ORDER BY t.created_at DESC
    LIMIT :limit OFFSET :offset";
$dataStmt = $pdo->prepare($dataSql);
foreach ($params as $key => $value) {
    $dataStmt->bindValue($key + 1, $value);
}
$dataStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$dataStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$dataStmt->execute();
$transactions = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

// Get financial summary
$summaryStmt = $pdo->query("
    SELECT 
        SUM(CASE WHEN type = 'credit_escrow_release' THEN amount ELSE 0 END) as total_released,
        SUM(CASE WHEN type = 'debit_withdrawal' THEN amount ELSE 0 END) as total_withdrawn,
        SUM(CASE WHEN type = 'credit_platform_fee' THEN amount ELSE 0 END) as total_fees_earned,
        SUM(CASE WHEN type = 'credit_refund' THEN amount ELSE 0 END) as total_refunds_issued,
        COUNT(DISTINCT user_id) as unique_sellers
    FROM wallet_transactions
");
$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);

// Get seller wallet status
$walletStmt = $pdo->query("
    SELECT 
        u.id, u.full_name, u.email,
        COALESCE(w.balance, 0) as balance,
        COALESCE((SELECT COUNT(*) FROM wallet_transactions WHERE user_id = u.id AND status = 'pending'), 0) as pending_txns
    FROM users u 
    LEFT JOIN wallets w ON u.id = w.user_id 
    WHERE u.role = 'seller'
    ORDER BY w.balance DESC
    LIMIT 20
");
$sellers = $walletStmt->fetchAll(PDO::FETCH_ASSOC);

// Handle actions
$message = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'retry_payout') {
        $sellerId = (int)($_POST['seller_id'] ?? 0);
        // Log retry action (actual payout logic would integrate with Stripe)
        $auditService->logAction(
            $_SESSION['user_id'],
            'retry_payout',
            'seller',
            $sellerId,
            "Initiated payout retry for seller",
            [],
            ['payout_initiated' => true]
        );
        $message = "✅ Payout retry initiated";
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Financials - Jacob Admin</title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="stylesheet" href="/assets/css/dashboard.css">
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
                <li><strong style="color: rgba(255,255,255,0.6); padding: 1rem; margin-top: 0.5rem; display: block; font-size: 0.85rem;">MANAGEMENT</strong></li>
                <li><a href="/admin/users.php"><span>👥</span> <span>Users</span></a></li>
                <li><a href="/admin/projects.php"><span>📦</span> <span>Projects</span></a></li>
                <li><a href="/dashboard/admin_escrows.php"><span>📋</span> <span>Escrows</span></a></li>
                <li><a href="/admin/disputes_list.php"><span>⚖️</span> <span>Disputes</span></a></li>
                <li><strong style="color: rgba(255,255,255,0.6); padding: 1rem; margin-top: 0.5rem; display: block; font-size: 0.85rem;">FINANCIAL</strong></li>
                <li><a href="/admin/financials.php" class="active"><span>💰</span> <span>Financials</span></a></li>
                <li><a href="/dashboard/admin_dashboard.php#withdrawals"><span>💸</span> <span>Withdrawals</span></a></li>
                <li><a href="/dashboard/admin_wallet_backfill.php"><span>🔄</span> <span>Wallet Backfill</span></a></li>
                <li><strong style="color: rgba(255,255,255,0.6); padding: 1rem; margin-top: 0.5rem; display: block; font-size: 0.85rem;">COMPLIANCE</strong></li>
                <li><a href="/admin/audit_logs.php"><span>📝</span> <span>Audit Logs</span></a></li>
                <li><a href="/admin/settings.php"><span>⚙️</span> <span>Settings</span></a></li>
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
                </div>
                <div class="header-right">
                    <div class="user-profile">
                        <div class="avatar"><?php echo strtoupper(substr($_SESSION['name'] ?? 'A', 0, 1)); ?></div>
                        <span><?php echo htmlspecialchars($_SESSION['name'] ?? 'Admin'); ?></span>
                    </div>
                </div>
            </div>

            <section class="section dashboard-content">
                <h2 class="section-title">💰 Financials</h2>

                <?php if ($message): ?>
                    <div style="background: #d4edda; color: #155724; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1rem;"><?php echo $message; ?></div>
                <?php endif; ?>

                <!-- Summary KPIs -->
                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <div style="background: white; padding: 1.5rem; border-radius: 1rem; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);">
                            <div style="color: #999; font-size: 0.85rem; margin-bottom: 0.5rem;">Total Released to Sellers</div>
                            <div style="font-size: 1.75rem; font-weight: bold; color: #1976d2;">$<?php echo number_format($summary['total_released'] ?? 0, 2); ?></div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div style="background: white; padding: 1.5rem; border-radius: 1rem; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);">
                            <div style="color: #999; font-size: 0.85rem; margin-bottom: 0.5rem;">Total Withdrawn</div>
                            <div style="font-size: 1.75rem; font-weight: bold; color: #0f5132;">$<?php echo number_format($summary['total_withdrawn'] ?? 0, 2); ?></div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div style="background: white; padding: 1.5rem; border-radius: 1rem; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);">
                            <div style="color: #999; font-size: 0.85rem; margin-bottom: 0.5rem;">Platform Fees Earned</div>
                            <div style="font-size: 1.75rem; font-weight: bold; color: #d97706;">$<?php echo number_format($summary['total_fees_earned'] ?? 0, 2); ?></div>
                        </div>
                    </div>
                </div>

                <!-- Seller Wallet Status -->
                <div style="background: white; padding: 1.5rem; border-radius: 1rem; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); margin-bottom: 1.5rem;">
                    <h3 style="margin-top: 0; margin-bottom: 1rem;">💳 Seller Wallet Status (Top 20)</h3>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Seller</th>
                                    <th>Email</th>
                                    <th>Wallet Balance</th>
                                    <th>Pending Txns</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($sellers)): ?>
                                    <tr>
                                        <td colspan="5" class="text-center py-3 text-muted">No sellers found</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($sellers as $seller): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($seller['username']); ?></strong></td>
                                            <td><small><?php echo htmlspecialchars($seller['email']); ?></small></td>
                                            <td><strong>$<?php echo number_format($seller['balance'], 2); ?></strong></td>
                                            <td><span style="background: #cfe2ff; color: #084298; padding: 2px 8px; border-radius: 4px; font-size: 0.8rem;"><?php echo $seller['pending_txns']; ?></span></td>
                                            <td>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="action" value="retry_payout">
                                                    <input type="hidden" name="seller_id" value="<?php echo $seller['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-primary">Payout</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Transaction Filters -->
                <div style="background: white; padding: 1.5rem; border-radius: 1rem; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); margin-bottom: 1.5rem;">
                    <form method="GET" class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label"><strong>Transaction Type</strong></label>
                            <select name="type" class="form-select">
                                <option value="">All Types</option>
                                <option value="credit_escrow_release" <?php echo ($typeFilter === 'credit_escrow_release') ? 'selected' : ''; ?>>Escrow Release</option>
                                <option value="debit_withdrawal" <?php echo ($typeFilter === 'debit_withdrawal') ? 'selected' : ''; ?>>Withdrawal</option>
                                <option value="credit_platform_fee" <?php echo ($typeFilter === 'credit_platform_fee') ? 'selected' : ''; ?>>Platform Fee</option>
                                <option value="credit_refund" <?php echo ($typeFilter === 'credit_refund') ? 'selected' : ''; ?>>Refund</option>
                            </select>
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary w-100">Filter</button>
                        </div>
                    </form>
                </div>

                <!-- Transactions Table -->
                <div style="background: white; padding: 0; border-radius: 1rem; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Transaction ID</th>
                                    <th>Seller</th>
                                    <th>Type</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th>Reference</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($transactions)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center py-4 text-muted">No transactions found</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($transactions as $txn): ?>
                                        <tr>
                                            <td><strong>#<?php echo $txn['id']; ?></strong></td>
                                            <td>
                                                <small><?php echo htmlspecialchars($txn['user_name']); ?></small><br>
                                                <small style="color: #999;"><?php echo htmlspecialchars($txn['user_email']); ?></small>
                                            </td>
                                            <td><small><?php echo htmlspecialchars(str_replace('_', ' ', $txn['type'])); ?></small></td>
                                            <td><strong>$<?php echo number_format($txn['amount'], 2); ?></strong></td>
                                            <td>
                                                <span style="background: <?php echo $txn['status'] === 'completed' ? '#d1e7dd' : '#fff3cd'; ?>; color: <?php echo $txn['status'] === 'completed' ? '#0f5132' : '#664d03'; ?>; padding: 4px 8px; border-radius: 4px; font-size: 0.85rem;">
                                                    <?php echo ucfirst($txn['status']); ?>
                                                </span>
                                            </td>
                                            <td><small><?php echo htmlspecialchars($txn['reference_id'] ?? '—'); ?></small></td>
                                            <td><small><?php echo date('M d, Y H:i', strtotime($txn['created_at'])); ?></small></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                    <nav aria-label="Pagination" class="mt-4">
                        <ul class="pagination justify-content-center">
                            <li class="page-item <?php echo ($pageNum <= 1) ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo max(1, $pageNum - 1); ?>&type=<?php echo $typeFilter; ?>">Previous</a>
                            </li>
                            <?php for ($i = max(1, $pageNum - 2); $i <= min($totalPages, $pageNum + 2); $i++): ?>
                                <li class="page-item <?php echo ($i === $pageNum) ? 'active' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $i; ?>&type=<?php echo $typeFilter; ?>"><?php echo $i; ?></a>
                                </li>
                            <?php endfor; ?>
                            <li class="page-item <?php echo ($pageNum >= $totalPages) ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo min($totalPages, $pageNum + 1); ?>&type=<?php echo $typeFilter; ?>">Next</a>
                            </li>
                        </ul>
                    </nav>
                <?php endif; ?>
            </section>
        </main>
    </div>

</body>

</html>