<?php
require_once '../includes/auth.php';
require_once '../config/database.php';
requireRole('admin');

$stmt = $pdo->query(
    "SELECT e.*, p.title, u1.full_name AS buyer_name, u2.full_name AS seller_name
     FROM escrow e
     JOIN projects p ON e.project_id = p.id
     JOIN users u1 ON e.buyer_id = u1.id
     JOIN users u2 ON e.seller_id = u2.id
     ORDER BY e.created_at DESC"
);

$escrows = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Escrow Management - Jacob Admin</title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="stylesheet" href="/assets/css/dashboard.css">
</head>

<body>

    <!-- Sidebar Overlay for Mobile -->
    <div id="sidebar-overlay" class="sidebar-overlay"></div>

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
                <li><a href="/dashboard/admin_escrows.php" class="active"><span>📋</span> <span>Escrows</span></a></li>
                <li><a href="/admin/disputes_list.php"><span>⚖️</span> <span>Disputes</span></a></li>
                <li><strong style="color: rgba(255,255,255,0.6); padding: 1rem; margin-top: 0.5rem; display: block; font-size: 0.85rem;">FINANCIAL</strong></li>
                <li><a href="/admin/financials.php"><span>💰</span> <span>Financials</span></a></li>
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

        <!-- Main Content -->
        <main class="main-content">
            <!-- Header -->
            <div class="dashboard-header">
                <div class="header-left">
                    <button class="toggle-sidebar" onclick="toggleSidebar()">☰</button>
                    <div class="search-bar">
                        <input type="text" placeholder="Search escrows...">
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
                <h2 class="section-title">📋 Escrow Management</h2>

                <?php
                // Display success messages
                if (isset($_GET['success'])) {
                    if ($_GET['success'] === 'marked_disputed') {
                        $escrowId = (int)($_GET['escrow_id'] ?? 0);
                        echo '<div class="alert alert-success alert-dismissible fade show" role="alert">
                <strong>Success!</strong> Escrow #' . $escrowId . ' has been marked as disputed. 
                Buyer or seller can now open a formal dispute case.
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>';
                    }
                }

                // Display error messages
                if (isset($_GET['error'])) {
                    $errorMsg = '';
                    switch ($_GET['error']) {
                        case 'already_disputed':
                            $errorMsg = 'This escrow is already in disputed status.';
                            break;
                        case 'invalid_status':
                            $errorMsg = 'Escrow must be in "funded" status to mark as disputed.';
                            break;
                        case 'transition_failed':
                            $errorMsg = 'Failed to mark as disputed: ' . htmlspecialchars($_GET['message'] ?? 'Unknown error');
                            break;
                        case 'invalid_request':
                            $errorMsg = 'Invalid request parameters.';
                            break;
                        default:
                            $errorMsg = 'An error occurred.';
                    }
                    if ($errorMsg) {
                        echo '<div class="alert alert-danger alert-dismissible fade show" role="alert">
                <strong>Error!</strong> ' . $errorMsg . '
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>';
                    }
                }
                ?>

                <?php if (empty($escrows)): ?>
                    <div class="empty-state">
                        <div class="empty-icon">📂</div>
                        <div class="empty-title">No escrows yet</div>
                        <div class="empty-description">New escrows will appear here as projects are funded</div>
                    </div>
                <?php endif; ?>

                <?php foreach ($escrows as $escrow): ?>
                    <div class="project-card" style="margin-bottom: 1rem;">
                        <div class="project-header">
                            <div>
                                <div class="project-title"><?php echo htmlspecialchars($escrow['title']); ?></div>
                                <div class="project-client">👤 Buyer: <?php echo htmlspecialchars($escrow['buyer_name']); ?> · 🧑‍💻 Seller: <?php echo htmlspecialchars($escrow['seller_name']); ?></div>
                            </div>
                            <span class="project-status">
                                <?php
                                $statusColors = [
                                    'pending' => 'secondary',
                                    'funded' => 'success',
                                    'released' => 'primary',
                                    'refunded' => 'info',
                                    'disputed' => 'danger',
                                    'held' => 'warning',
                                    'canceled' => 'dark'
                                ];
                                echo strtoupper($escrow['status']);
                                ?>
                            </span>
                        </div>

                        <div class="project-meta" style="margin-top: 0.5rem;">
                            <div class="project-meta-item">
                                <span class="meta-label">Amount</span>
                                <span class="meta-value">$<?php echo number_format($escrow['amount'], 2); ?></span>
                            </div>
                            <div class="project-meta-item">
                                <span class="meta-label">Status</span>
                                <span class="badge bg-<?php echo $statusColors[$escrow['status']] ?? 'secondary'; ?>"><?php echo ucfirst($escrow['status']); ?></span>
                            </div>
                        </div>

                        <div style="margin-top: 0.75rem; display: flex; gap: 0.5rem;">
                            <?php if ($escrow['status'] === 'funded'): ?>
                                <a href="admin_escrow_action.php?escrow_id=<?php echo $escrow['id']; ?>&action=release" class="btn btn-success">Release</a>
                                <a href="admin_escrow_action.php?escrow_id=<?php echo $escrow['id']; ?>&action=hold" class="btn btn-warning">Hold</a>
                                <a href="admin_mark_disputed.php?escrow_id=<?php echo $escrow['id']; ?>&action=mark_disputed" class="btn btn-danger">Mark as Disputed</a>
                            <?php elseif ($escrow['status'] === 'held'): ?>
                                <a href="admin_escrow_action.php?escrow_id=<?php echo $escrow['id']; ?>&action=release" class="btn btn-success">Release</a>
                            <?php elseif ($escrow['status'] === 'disputed'): ?>
                                <a href="/disputes/index.php" class="btn btn-info">View Disputes</a>
                                <a href="/admin/disputes_list.php" class="btn btn-primary">Admin Disputes Panel</a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </section>
        </main>
    </div>

    <!-- Sidebar Navigation Script -->
    <script src="/assets/js/sidebar.js"></script>

</body>

</html>