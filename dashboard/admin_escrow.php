<?php
require_once "../includes/auth.php";
require_once "../config/database.php";

if ($_SESSION['role'] !== 'admin') {
    die("Access denied");
}

$stmt = $pdo->query(
    "SELECT e.*, p.title, u1.full_name AS buyer_name, u2.full_name AS seller_name
     FROM escrow e
     JOIN projects p ON e.project_id = p.id
     JOIN users u1 ON e.buyer_id = u1.id
     JOIN users u2 ON e.seller_id = u2.id
     WHERE e.status = 'funded'
     ORDER BY e.created_at DESC"
);
$rows = $stmt->fetchAll();
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
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="sidebar-brand">
                <span>✨</span>
                <span>Jacob Admin</span>
            </div>
            <ul class="sidebar-nav">
                <li><a href="/dashboard/admin_dashboard.php"><span>📊</span> <span>Dashboard</span></a></li>
                <li><a href="/dashboard/admin_escrows.php" class="active"><span>📋</span> <span>Escrows</span></a></li>
                <li><a href="/dashboard/admin_dashboard.php#withdrawals"><span>💸</span> <span>Withdrawals</span></a></li>
                <li><a href="/dashboard/admin_wallet_backfill.php"><span>🔄</span> <span>Wallet Backfill</span></a></li>
                <li><a href="/disputes/index.php"><span>⚖️</span> <span>Disputes</span></a></li>
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
                    <button class="toggle-sidebar">☰</button>
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

                <?php if (empty($rows)): ?>
                    <div class="empty-state">
                        <div class="empty-icon">📂</div>
                        <div class="empty-title">No funded escrows</div>
                        <div class="empty-description">Funded escrows will appear here for processing</div>
                    </div>
                <?php endif; ?>

                <?php foreach ($rows as $row): ?>
                    <div class="project-card" style="margin-bottom: 1rem;">
                        <div class="project-header">
                            <div>
                                <div class="project-title"><?php echo htmlspecialchars($row['title']); ?></div>
                                <div class="project-client">👤 Buyer: <?php echo htmlspecialchars($row['buyer_name']); ?> · 🧑‍💻 Seller: <?php echo htmlspecialchars($row['seller_name']); ?></div>
                            </div>
                            <span class="project-status">FUNDED</span>
                        </div>

                        <div class="project-meta" style="margin-top: 0.5rem;">
                            <div class="project-meta-item">
                                <span class="meta-label">Amount</span>
                                <span class="meta-value">$<?php echo number_format($row['amount'], 2); ?></span>
                            </div>
                        </div>

                        <div style="margin-top: 0.75rem; display: flex; gap: 0.5rem;">
                            <a href="admin_escrow_action.php?escrow_id=<?php echo $row['id']; ?>&action=release" class="btn btn-success">Release</a>
                            <a href="admin_escrow_action.php?escrow_id=<?php echo $row['id']; ?>&action=hold" class="btn btn-warning">Hold</a>
                            <a href="admin_mark_disputed.php?escrow_id=<?php echo $row['id']; ?>&action=mark_disputed" class="btn btn-danger">Mark as Disputed</a>
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