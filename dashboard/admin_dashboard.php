<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../services/WalletService.php';
require_once '../services/AdminAuditService.php';
require_once '../services/PlatformSettingsService.php';

requireRole('admin');

$auditService = new AdminAuditService($pdo);
$settingsService = new PlatformSettingsService($pdo);
$walletService = new WalletService($pdo);

// Get system stats
$statsStmt = $pdo->prepare("
    SELECT 
        (SELECT COUNT(*) FROM users) as total_users,
        (SELECT COUNT(*) FROM users WHERE role = 'seller') as total_sellers,
        (SELECT COUNT(*) FROM users WHERE role = 'buyer') as total_buyers,
        (SELECT COUNT(*) FROM projects) as total_projects,
        (SELECT COUNT(*) FROM projects WHERE status = 'open') as open_projects,
        (SELECT COUNT(*) FROM escrow) as total_escrows,
        (SELECT COUNT(*) FROM escrow WHERE status = 'funded') as funded_escrows,
        (SELECT COUNT(*) FROM disputes WHERE status = 'open') as open_disputes,
        (SELECT SUM(amount) FROM escrow) as total_escrow_volume,
        (SELECT COUNT(*) FROM escrow WHERE status = 'released') as completed_escrows
");
$statsStmt->execute();
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

// Get pending withdrawals
$pendingWithdrawals = $walletService->getPendingWithdrawals(10);

// Get recent disputes
$recentDisputesStmt = $pdo->prepare("
    SELECT d.*, e.amount, p.title as project_title
    FROM disputes d
    JOIN escrow e ON d.escrow_id = e.id
    JOIN projects p ON e.project_id = p.id
    WHERE d.status = 'open'
    ORDER BY d.opened_at DESC
    LIMIT 5
");
$recentDisputesStmt->execute();
$recentDisputes = $recentDisputesStmt->fetchAll(PDO::FETCH_ASSOC);

// Get recent admin activity
$auditSummary = $auditService->getSummary();
$recentActivity = $auditService->getActivityLogs(null, null, null, 10, 0);

// Get commission percentage
$commissionPct = $settingsService->getCommissionPercentage();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Admin Dashboard - Jacob</title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="stylesheet" href="/assets/css/dashboard.css">
    <style>
        .admin-nav-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .admin-nav-card {
            background: white;
            border-radius: 1rem;
            padding: 1.5rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            transition: transform 0.2s, box-shadow 0.2s;
            text-decoration: none;
            color: inherit;
            border-left: 4px solid #0066cc;
        }

        .admin-nav-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 16px -2px rgba(0, 0, 0, 0.1);
        }

        .admin-nav-card h4 {
            margin: 0 0 0.5rem 0;
            font-size: 1.1rem;
            color: #1a1a1a;
        }

        .admin-nav-card p {
            margin: 0;
            font-size: 0.9rem;
            color: #666;
        }

        .admin-nav-card.danger {
            border-left-color: #dc3545;
        }

        .admin-nav-card.warning {
            border-left-color: #ffc107;
        }

        .admin-nav-card.info {
            border-left-color: #0dcaf0;
        }

        .admin-nav-card.success {
            border-left-color: #198754;
        }
    </style>
</head>

<body>

    <div class="dashboard-wrapper">
        <aside class="sidebar">
            <div class="sidebar-brand">
                <span>✨</span>
                <span>Jacob Admin</span>
            </div>
            <ul class="sidebar-nav">
                <li><a href="/dashboard/admin_dashboard.php" class="active"><span>📊</span> <span>Dashboard</span></a></li>
                <li><strong style="color: rgba(255,255,255,0.6); padding: 1rem; margin-top: 0.5rem; display: block; font-size: 0.85rem;">MANAGEMENT</strong></li>
                <li><a href="/admin/users.php"><span>👥</span> <span>Users</span></a></li>
                <li><a href="/admin/projects.php"><span>📦</span> <span>Projects</span></a></li>
                <li><a href="/dashboard/admin_escrows.php"><span>📋</span> <span>Escrows</span></a></li>
                <li><a href="/admin/disputes_list.php"><span>⚖️</span> <span>Disputes</span></a></li>
                <li><strong style="color: rgba(255,255,255,0.6); padding: 1rem; margin-top: 0.5rem; display: block; font-size: 0.85rem;">FINANCIAL</strong></li>
                <li><a href="/admin/financials.php"><span>💰</span> <span>Financials</span></a></li>
                <li><a href="#withdrawals"><span>💸</span> <span>Withdrawals</span></a></li>
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
                    <div class="search-bar">
                        <input type="text" placeholder="Search...">
                    </div>
                </div>
                <div class="header-right">
                    <div class="user-profile">
                        <div class="avatar"><?php echo strtoupper(substr($_SESSION['name'] ?? 'A', 0, 1)); ?></div>
                        <span><?php echo htmlspecialchars($_SESSION['name'] ?? 'Admin'); ?></span>
                    </div>
                </div>
            </div>

            <section class="section dashboard-content">
                <h2 class="section-title">📊 System Admin Dashboard</h2>

                <!-- Quick Admin Tools -->
                <div style="margin-bottom: 2rem;">
                    <h3 style="margin-bottom: 1rem; color: #1a1a1a;">🛠️ Admin Tools</h3>
                    <div class="admin-nav-grid">
                        <a href="/admin/users.php" class="admin-nav-card">
                            <h4>👥 Users</h4>
                            <p>Manage users, roles, KYC, suspensions</p>
                        </a>
                        <a href="/admin/projects.php" class="admin-nav-card">
                            <h4>📦 Projects</h4>
                            <p>View all projects, cancel, reassign</p>
                        </a>
                        <a href="/dashboard/admin_escrows.php" class="admin-nav-card">
                            <h4>📋 Escrows</h4>
                            <p>Manage escrows, force states, payouts</p>
                        </a>
                        <a href="/admin/disputes_list.php" class="admin-nav-card danger">
                            <h4>⚖️ Disputes</h4>
                            <p>Resolve disputes, manage evidence</p>
                        </a>
                        <a href="/admin/financials.php" class="admin-nav-card warning">
                            <h4>💰 Financials</h4>
                            <p>Payments, refunds, commissions, payouts</p>
                        </a>
                        <a href="/admin/settings.php" class="admin-nav-card info">
                            <h4>⚙️ Settings</h4>
                            <p>Commissions, limits, policies, webhooks</p>
                        </a>
                    </div>
                </div>

                <!-- Platform KPIs -->
                <div style="margin-bottom: 2rem;">
                    <h3 style="margin-bottom: 1rem; color: #1a1a1a;">📈 Platform Metrics</h3>
                    <div class="kpi-grid">
                        <div class="kpi-card">
                            <div class="kpi-label">👥 Total Users</div>
                            <div class="kpi-value"><?php echo $stats['total_users']; ?></div>
                            <div class="kpi-subtext">Sellers: <?php echo $stats['total_sellers']; ?> | Buyers: <?php echo $stats['total_buyers']; ?></div>
                        </div>
                        <div class="kpi-card">
                            <div class="kpi-label">📦 Projects</div>
                            <div class="kpi-value"><?php echo $stats['total_projects']; ?></div>
                            <div class="kpi-subtext">Open: <?php echo $stats['open_projects']; ?></div>
                        </div>
                        <div class="kpi-card warning">
                            <div class="kpi-label">📋 Escrows</div>
                            <div class="kpi-value"><?php echo $stats['total_escrows']; ?></div>
                            <div class="kpi-subtext">Funded: <?php echo $stats['funded_escrows']; ?> | Completed: <?php echo $stats['completed_escrows']; ?></div>
                        </div>
                        <div class="kpi-card danger">
                            <div class="kpi-label">⚖️ Open Disputes</div>
                            <div class="kpi-value"><?php echo $stats['open_disputes']; ?></div>
                            <div class="kpi-subtext"><a href="/admin/disputes_list.php">View all →</a></div>
                        </div>
                        <div class="kpi-card info">
                            <div class="kpi-label">💰 Escrow Volume</div>
                            <div class="kpi-value">$<?php echo number_format($stats['total_escrow_volume'] ?? 0, 0); ?></div>
                            <div class="kpi-subtext">Total in system</div>
                        </div>
                        <div class="kpi-card success">
                            <div class="kpi-label">📊 Commission</div>
                            <div class="kpi-value"><?php echo $commissionPct; ?>%</div>
                            <div class="kpi-subtext"><a href="/admin/settings.php">Adjust →</a></div>
                        </div>
                    </div>
                </div>

                <!-- Admin Activity Summary -->
                <?php if (!empty($auditSummary)): ?>
                    <div style="background: white; padding: 1.5rem; border-radius: 1rem; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); margin-bottom: 2rem;">
                        <h3 style="margin-top: 0; margin-bottom: 1rem; color: #1a1a1a;">📝 Admin Activity (Last 30 Days)</h3>
                        <div class="kpi-grid" style="grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));">
                            <div style="padding: 1rem; background: #f0f9ff; border-radius: 0.5rem;">
                                <div style="font-size: 2rem; font-weight: bold; color: #0066cc;"><?php echo $auditSummary['total_actions'] ?? 0; ?></div>
                                <div style="color: #666; font-size: 0.9rem;">Total Actions</div>
                            </div>
                            <div style="padding: 1rem; background: #f0fdf4; border-radius: 0.5rem;">
                                <div style="font-size: 2rem; font-weight: bold; color: #16a34a;"><?php echo $auditSummary['successful_actions'] ?? 0; ?></div>
                                <div style="color: #666; font-size: 0.9rem;">Successful</div>
                            </div>
                            <div style="padding: 1rem; background: #fef2f2; border-radius: 0.5rem;">
                                <div style="font-size: 2rem; font-weight: bold; color: #dc2626;"><?php echo $auditSummary['failed_actions'] ?? 0; ?></div>
                                <div style="color: #666; font-size: 0.9rem;">Failed</div>
                            </div>
                            <div style="padding: 1rem; background: #f5f3ff; border-radius: 0.5rem;">
                                <div style="font-size: 2rem; font-weight: bold; color: #7c3aed;"><?php echo $auditSummary['active_admins'] ?? 0; ?></div>
                                <div style="color: #666; font-size: 0.9rem;">Active Admins</div>
                            </div>
                        </div>
                        <div style="margin-top: 1rem;">
                            <a href="/admin/audit_logs.php" style="color: #0066cc; text-decoration: none;">View full audit trail →</a>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Pending Withdrawals -->
                <?php if (!empty($pendingWithdrawals)): ?>
                    <div id="withdrawals" style="background: white; padding: 1.5rem; border-radius: 1rem; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); margin-bottom: 2rem;">
                        <h3 style="margin-top: 0; margin-bottom: 1rem; color: #1a1a1a;">💸 Pending Withdrawal Requests</h3>
                        <div id="withdrawal-alert" style="display: none; margin-bottom: 1rem;"></div>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead style="background-color: #f0f0f0;">
                                    <tr>
                                        <th>ID</th>
                                        <th>Seller</th>
                                        <th>Amount</th>
                                        <th>Requested</th>
                                        <th>Status</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pendingWithdrawals as $withdrawal): ?>
                                        <tr style="border-bottom: 1px solid #dee2e6;">
                                            <td><strong>#<?php echo $withdrawal['id']; ?></strong></td>
                                            <td>
                                                <?php echo htmlspecialchars($withdrawal['full_name'] ?? 'User #' . $withdrawal['user_id']); ?>
                                                <br><small style="color: #999;"><?php echo htmlspecialchars($withdrawal['email']); ?></small>
                                            </td>
                                            <td><strong>$<?php echo number_format($withdrawal['amount'], 2); ?></strong></td>
                                            <td><?php echo date('M d, Y H:i', strtotime($withdrawal['requested_at'])); ?></td>
                                            <td><span style="background: #ffc107; color: #000; padding: 4px 8px; border-radius: 4px; font-size: 0.85rem;"><?php echo ucfirst($withdrawal['status']); ?></span></td>
                                            <td>
                                                <button onclick="processWithdrawal(<?php echo $withdrawal['id']; ?>, <?php echo $withdrawal['amount']; ?>)" class="btn btn-success btn-sm" id="process-btn-<?php echo $withdrawal['id']; ?>">Process Payout</button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Recent Open Disputes -->
                <?php if (!empty($recentDisputes)): ?>
                    <div style="background: white; padding: 1.5rem; border-radius: 1rem; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); margin-bottom: 2rem;">
                        <h3 style="margin-top: 0; margin-bottom: 1rem; color: #1a1a1a;">⚠️ Recent Open Disputes</h3>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead style="background-color: #f0f0f0;">
                                    <tr>
                                        <th>ID</th>
                                        <th>Project</th>
                                        <th>Amount</th>
                                        <th>Opened</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentDisputes as $dispute): ?>
                                        <tr style="border-bottom: 1px solid #dee2e6;">
                                            <td><strong>#<?php echo $dispute['id']; ?></strong></td>
                                            <td><?php echo htmlspecialchars($dispute['project_title']); ?></td>
                                            <td><strong>$<?php echo number_format($dispute['amount'], 2); ?></strong></td>
                                            <td><?php echo date('M d, Y', strtotime($dispute['opened_at'])); ?></td>
                                            <td>
                                                <a href="/admin/dispute_review.php?id=<?php echo $dispute['id']; ?>" style="background: #007bff; color: white; padding: 5px 10px; border-radius: 4px; text-decoration: none; font-size: 0.9rem;">Review</a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div style="margin-top: 1rem;">
                            <a href="/admin/disputes_list.php" style="color: #0066cc; text-decoration: none;">View all disputes →</a>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Recent Admin Activity -->
                <?php if (!empty($recentActivity)): ?>
                    <div style="background: white; padding: 1.5rem; border-radius: 1rem; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);">
                        <h3 style="margin-top: 0; margin-bottom: 1rem; color: #1a1a1a;">👀 Recent Admin Activity</h3>
                        <div class="table-responsive">
                            <table class="table table-hover" style="font-size: 0.9rem;">
                                <thead style="background-color: #f0f0f0;">
                                    <tr>
                                        <th>Admin</th>
                                        <th>Action</th>
                                        <th>Entity</th>
                                        <th>Time</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentActivity as $activity): ?>
                                        <tr style="border-bottom: 1px solid #dee2e6;">
                                            <td><?php echo htmlspecialchars($activity['username'] ?? 'Unknown'); ?></td>
                                            <td><code style="background: #f0f0f0; padding: 2px 6px; border-radius: 3px; font-size: 0.85rem;"><?php echo htmlspecialchars($activity['action']); ?></code></td>
                                            <td><?php echo htmlspecialchars($activity['entity_type']); ?> #<?php echo $activity['entity_id']; ?></td>
                                            <td><?php echo date('M d H:i', strtotime($activity['created_at'])); ?></td>
                                            <td>
                                                <span style="background: <?php echo $activity['status'] === 'success' ? '#d4edda' : '#f8d7da'; ?>; color: <?php echo $activity['status'] === 'success' ? '#155724' : '#721c24'; ?>; padding: 3px 8px; border-radius: 3px; font-size: 0.85rem;">
                                                    <?php echo ucfirst($activity['status']); ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div style="margin-top: 1rem;">
                            <a href="/admin/audit_logs.php" style="color: #0066cc; text-decoration: none;">View audit logs →</a>
                        </div>
                    </div>
                <?php endif; ?>
            </section>
        </main>
    </div>

    <script>
        async function processWithdrawal(withdrawalId, amount) {
            const btn = document.getElementById(`process-btn-${withdrawalId}`);
            const alertDiv = document.getElementById('withdrawal-alert');
            if (!confirm(`Process withdrawal #${withdrawalId} for $${amount.toFixed(2)}?`)) return;
            btn.disabled = true;
            btn.textContent = 'Processing...';
            try {
                const response = await fetch('/dashboard/process_withdrawal.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: 'withdrawal_id=' + withdrawalId
                });
                const data = await response.json();
                alertDiv.style.display = 'block';
                if (data.status === 'success') {
                    alertDiv.className = 'alert alert-success';
                    alertDiv.innerHTML = `✅ ${data.message}`;
                    btn.textContent = '✓ Completed';
                    btn.className = 'btn btn-secondary btn-sm';
                    setTimeout(() => window.location.reload(), 2000);
                } else {
                    alertDiv.className = 'alert alert-danger';
                    alertDiv.innerHTML = `❌ ${data.message}`;
                    btn.disabled = false;
                    btn.textContent = 'Process Payout';
                }
            } catch (error) {
                alertDiv.style.display = 'block';
                alertDiv.className = 'alert alert-danger';
                alertDiv.innerHTML = `❌ Error: ${error.message}`;
                btn.disabled = false;
                btn.textContent = 'Process Payout';
            }
        }
    </script>

</body>

</html>