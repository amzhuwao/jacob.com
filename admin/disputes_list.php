<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
// Admin disputes list with dashboard layout
require_once '../config/database.php';
require_once '../includes/auth.php';

requireRole('admin');

$statusFilter = $_GET['status'] ?? 'open';
$sortBy = $_GET['sort'] ?? 'opened_at_desc';
$searchQuery = trim($_GET['search'] ?? '');
$pageNum = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;

// Build filters
$where = 'WHERE 1=1';
$params = [];
if ($statusFilter !== 'all') {
    $where .= ' AND d.status = ?';
    $params[] = $statusFilter;
}
if ($searchQuery !== '') {
    $where .= ' AND (p.title LIKE ? OR u_opener.full_name LIKE ? OR CAST(d.id AS CHAR) LIKE ?)';
    $like = "%{$searchQuery}%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$orderBy = 'd.opened_at DESC';
switch ($sortBy) {
    case 'opened_at_asc':
        $orderBy = 'd.opened_at ASC';
        break;
    case 'amount_high':
        $orderBy = 'e.amount DESC';
        break;
    case 'amount_low':
        $orderBy = 'e.amount ASC';
        break;
    case 'messages_count':
        $orderBy = 'message_count DESC';
        break;
}

$baseSelect = "
        SELECT d.*, e.amount, p.title AS project_title,
            u_opener.full_name AS opener_name,
            u_buyer.full_name AS buyer_name,
            u_seller.full_name AS seller_name,
           COUNT(DISTINCT dm.id) AS message_count,
           COUNT(DISTINCT de.id) AS evidence_count
    FROM disputes d
    JOIN escrow e ON d.escrow_id = e.id
    JOIN projects p ON e.project_id = p.id
    JOIN users u_opener ON d.opened_by = u_opener.id
    JOIN users u_buyer ON e.buyer_id = u_buyer.id
    JOIN users u_seller ON e.seller_id = u_seller.id
    LEFT JOIN dispute_messages dm ON d.id = dm.dispute_id
    LEFT JOIN dispute_evidence de ON d.id = de.dispute_id
    $where
    GROUP BY d.id
";

// Total count
$countSql = "SELECT COUNT(*) AS total FROM ($baseSelect) AS sub";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalDisputes = (int)($countStmt->fetchColumn() ?: 0);
$totalPages = max(1, (int)ceil($totalDisputes / $perPage));

$offset = ($pageNum - 1) * $perPage;
$dataSql = $baseSelect . " ORDER BY $orderBy LIMIT $perPage OFFSET $offset";
$dataStmt = $pdo->prepare($dataSql);
$dataStmt->execute($params);
$disputes = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

// Stats
$statsStmt = $pdo->query("SELECT COUNT(*) AS total_disputes,
    SUM(CASE WHEN d.status = 'open' THEN 1 ELSE 0 END) AS open_disputes,
    SUM(CASE WHEN d.status = 'resolved' THEN 1 ELSE 0 END) AS resolved_disputes,
    ROUND(AVG(e.amount), 2) AS avg_amount,
    MAX(e.amount) AS max_amount,
    MIN(e.amount) AS min_amount
    FROM disputes d JOIN escrow e ON d.escrow_id = e.id");
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: ['total_disputes' => 0, 'open_disputes' => 0, 'resolved_disputes' => 0, 'avg_amount' => 0, 'max_amount' => 0, 'min_amount' => 0];

$resStmt = $pdo->query("SELECT d.resolution, COUNT(*) AS count FROM disputes d WHERE d.status = 'resolved' GROUP BY d.resolution");
$resolutionBreakdown = [];
foreach ($resStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $resolutionBreakdown[$row['resolution']] = $row['count'];
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Disputes - Jacob</title>
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
                <li><a href="/admin/disputes_list.php" class="active"><span>⚖️</span> <span>Disputes</span></a></li>
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

            <section class="section dashboard-content">
                <h2 class="section-title">⚖️ Disputes Management</h2>

                <div class="kpi-grid" style="margin-bottom: 1.5rem;">
                    <div class="kpi-card">
                        <div class="kpi-label">Total Disputes</div>
                        <div class="kpi-value"><?php echo $stats['total_disputes']; ?></div>
                    </div>
                    <div class="kpi-card warning">
                        <div class="kpi-label">Open</div>
                        <div class="kpi-value"><?php echo $stats['open_disputes']; ?></div>
                    </div>
                    <div class="kpi-card success">
                        <div class="kpi-label">Resolved</div>
                        <div class="kpi-value"><?php echo $stats['resolved_disputes']; ?></div>
                    </div>
                    <div class="kpi-card info">
                        <div class="kpi-label">Avg Amount</div>
                        <div class="kpi-value">$<?php echo number_format((float)($stats['avg_amount'] ?? 0), 0); ?></div>
                    </div>
                    <div class="kpi-card warning">
                        <div class="kpi-label">Max Amount</div>
                        <div class="kpi-value">$<?php echo number_format((float)($stats['max_amount'] ?? 0), 0); ?></div>
                    </div>
                </div>

                <?php if ($stats['resolved_disputes'] > 0): ?>
                    <div style="background: white; padding: 1.5rem; border-radius: 1rem; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); margin-bottom: 1.5rem;">
                        <h3 style="margin-bottom: 1rem; font-weight: 600; color: var(--dark);">Resolution Breakdown</h3>
                        <div class="kpi-grid">
                            <div class="kpi-card danger">
                                <div class="kpi-label">Refund to Buyer</div>
                                <div class="kpi-value"><?php echo $resolutionBreakdown['refund_buyer'] ?? 0; ?></div>
                            </div>
                            <div class="kpi-card success">
                                <div class="kpi-label">Release to Seller</div>
                                <div class="kpi-value"><?php echo $resolutionBreakdown['release_to_seller'] ?? 0; ?></div>
                            </div>
                            <div class="kpi-card warning">
                                <div class="kpi-label">Split Payment</div>
                                <div class="kpi-value"><?php echo $resolutionBreakdown['split'] ?? 0; ?></div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <div style="background: white; padding: 1.5rem; border-radius: 1rem; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); margin-bottom: 1.5rem;">
                    <form method="GET" class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label"><strong>Status</strong></label>
                            <select name="status" class="form-select">
                                <option value="all" <?php echo ($statusFilter === 'all') ? 'selected' : ''; ?>>All Disputes</option>
                                <option value="open" <?php echo ($statusFilter === 'open') ? 'selected' : ''; ?>>Open Only</option>
                                <option value="resolved" <?php echo ($statusFilter === 'resolved') ? 'selected' : ''; ?>>Resolved Only</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label"><strong>Sort By</strong></label>
                            <select name="sort" class="form-select">
                                <option value="opened_at_desc" <?php echo ($sortBy === 'opened_at_desc') ? 'selected' : ''; ?>>Newest First</option>
                                <option value="opened_at_asc" <?php echo ($sortBy === 'opened_at_asc') ? 'selected' : ''; ?>>Oldest First</option>
                                <option value="amount_high" <?php echo ($sortBy === 'amount_high') ? 'selected' : ''; ?>>Highest Amount</option>
                                <option value="amount_low" <?php echo ($sortBy === 'amount_low') ? 'selected' : ''; ?>>Lowest Amount</option>
                                <option value="messages_count" <?php echo ($sortBy === 'messages_count') ? 'selected' : ''; ?>>Most Activity</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label"><strong>Search</strong></label>
                            <input type="text" name="search" class="form-control" placeholder="Project, opener, or dispute ID..." value="<?php echo htmlspecialchars($searchQuery); ?>">
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary w-100">Search</button>
                        </div>
                    </form>
                </div>

                <div style="background: white; padding: 0; border-radius: 1rem; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 80px;">ID</th>
                                    <th>Project</th>
                                    <th style="width: 120px;">Amount</th>
                                    <th style="width: 120px;">Opened By</th>
                                    <th style="width: 100px;">Status</th>
                                    <th style="width: 80px;">Messages</th>
                                    <th style="width: 80px;">Evidence</th>
                                    <th style="width: 140px;">Opened</th>
                                    <th style="width: 100px;">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($disputes)): ?>
                                    <tr>
                                        <td colspan="9" class="text-center py-4 text-muted">No disputes found</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($disputes as $dispute): ?>
                                        <tr>
                                            <td><strong>#<?php echo $dispute['id']; ?></strong></td>
                                            <td>
                                                <span title="<?php echo htmlspecialchars($dispute['project_title']); ?>">
                                                    <?php echo htmlspecialchars(substr($dispute['project_title'], 0, 30)); ?>
                                                    <?php if (strlen($dispute['project_title']) > 30) echo '...'; ?>
                                                </span>
                                            </td>
                                            <td><strong>$<?php echo number_format($dispute['amount'], 2); ?></strong></td>
                                            <td><small><?php echo htmlspecialchars($dispute['opener_name']); ?></small></td>
                                            <td>
                                                <span class="badge <?php echo ($dispute['status'] === 'open') ? 'bg-danger' : 'bg-success'; ?>">
                                                    <?php echo ucfirst($dispute['status']); ?>
                                                </span>
                                            </td>
                                            <td><span class="badge bg-info"><?php echo $dispute['message_count']; ?></span></td>
                                            <td><span class="badge bg-secondary"><?php echo $dispute['evidence_count']; ?></span></td>
                                            <td><small><?php echo date('M d, Y', strtotime($dispute['opened_at'])); ?></small></td>
                                            <td>
                                                <a href="/admin/dispute_review.php?id=<?php echo $dispute['id']; ?>" class="btn btn-sm btn-primary">
                                                    Review
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <?php if ($totalPages > 1): ?>
                    <nav aria-label="Page navigation" class="mt-4">
                        <ul class="pagination justify-content-center">
                            <li class="page-item <?php echo ($pageNum <= 1) ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo max(1, $pageNum - 1); ?>&status=<?php echo $statusFilter; ?>&sort=<?php echo $sortBy; ?>&search=<?php echo urlencode($searchQuery); ?>">Previous</a>
                            </li>

                            <?php
                            $startPage = max(1, $pageNum - 2);
                            $endPage = min($totalPages, $pageNum + 2);

                            if ($startPage > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=1&status=<?php echo $statusFilter; ?>&sort=<?php echo $sortBy; ?>&search=<?php echo urlencode($searchQuery); ?>">1</a>
                                </li>
                                <?php if ($startPage > 2): ?>
                                    <li class="page-item disabled"><span class="page-link">...</span></li>
                                <?php endif; ?>
                            <?php endif; ?>

                            <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                                <li class="page-item <?php echo ($i === $pageNum) ? 'active' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $i; ?>&status=<?php echo $statusFilter; ?>&sort=<?php echo $sortBy; ?>&search=<?php echo urlencode($searchQuery); ?>">
                                        <?php echo $i; ?>
                                    </a>
                                </li>
                            <?php endfor; ?>

                            <?php if ($endPage < $totalPages): ?>
                                <?php if ($endPage < $totalPages - 1): ?>
                                    <li class="page-item disabled"><span class="page-link">...</span></li>
                                <?php endif; ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?php echo $totalPages; ?>&status=<?php echo $statusFilter; ?>&sort=<?php echo $sortBy; ?>&search=<?php echo urlencode($searchQuery); ?>">
                                        <?php echo $totalPages; ?>
                                    </a>
                                </li>
                            <?php endif; ?>

                            <li class="page-item <?php echo ($pageNum >= $totalPages) ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo min($totalPages, $pageNum + 1); ?>&status=<?php echo $statusFilter; ?>&sort=<?php echo $sortBy; ?>&search=<?php echo urlencode($searchQuery); ?>">Next</a>
                            </li>
                        </ul>
                    </nav>
                <?php endif; ?>
            </section>
        </main>
    </div>

</body>

</html>