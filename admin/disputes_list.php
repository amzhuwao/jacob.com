<?php

/**
 * Admin - Disputes List
 * 
 * Comprehensive disputes management page for admins
 * Filter, search, and manage all disputes in the system
 */

require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/header.php';

// Check admin role
if (($_SESSION['role'] ?? null) !== 'admin') {
    http_response_code(403);
    echo 'Admin access required';
    exit;
}

// Get filters from query string
$statusFilter = $_GET['status'] ?? 'open';
$sortBy = $_GET['sort'] ?? 'opened_at_desc';
$searchQuery = $_GET['search'] ?? '';
$pageNum = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;

// Build query
$baseQuery = "
    SELECT d.*, e.amount, p.title as project_title,
           u_opener.username as opener_name,
           u_buyer.username as buyer_name,
           u_seller.username as seller_name,
           COUNT(DISTINCT dm.id) as message_count,
           COUNT(DISTINCT de.id) as evidence_count
    FROM disputes d
    JOIN escrow e ON d.escrow_id = e.id
    JOIN projects p ON e.project_id = p.id
    JOIN users u_opener ON d.opened_by = u_opener.id
    JOIN users u_buyer ON e.buyer_id = u_buyer.id
    JOIN users u_seller ON e.seller_id = u_seller.id
    LEFT JOIN dispute_messages dm ON d.id = dm.dispute_id
    LEFT JOIN dispute_evidence de ON d.id = de.dispute_id
    WHERE 1=1
";

// Apply status filter
if ($statusFilter !== 'all') {
    $baseQuery .= " AND d.status = '{$statusFilter}'";
}

// Apply search filter
if (!empty($searchQuery)) {
    $baseQuery .= " AND (p.title LIKE '%{$searchQuery}%' OR u_opener.username LIKE '%{$searchQuery}%' OR d.id LIKE '%{$searchQuery}%')";
}

$baseQuery .= " GROUP BY d.id";

// Apply sorting
switch ($sortBy) {
    case 'amount_high':
        $baseQuery .= " ORDER BY e.amount DESC";
        break;
    case 'amount_low':
        $baseQuery .= " ORDER BY e.amount ASC";
        break;
    case 'messages_count':
        $baseQuery .= " ORDER BY message_count DESC";
        break;
    case 'opened_at_asc':
        $baseQuery .= " ORDER BY d.opened_at ASC";
        break;
    default: // opened_at_desc
        $baseQuery .= " ORDER BY d.opened_at DESC";
}

// Get total count for pagination
$countQuery = str_replace(
    'SELECT d.*, e.amount, p.title as project_title,
           u_opener.username as opener_name,
           u_buyer.username as buyer_name,
           u_seller.username as seller_name,
           COUNT(DISTINCT dm.id) as message_count,
           COUNT(DISTINCT de.id) as evidence_count',
    'SELECT COUNT(DISTINCT d.id) as total',
    $baseQuery
);
$countStmt = $pdo->query($countQuery);
$totalDisputes = $countStmt->fetch()['total'] ?? 0;
$totalPages = ceil($totalDisputes / $perPage);

// Get disputes for current page
$offset = ($pageNum - 1) * $perPage;
$paginatedQuery = $baseQuery . " LIMIT {$offset}, {$perPage}";
$stmt = $pdo->query($paginatedQuery);
$disputes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get statistics
$statsQuery = "
    SELECT 
        COUNT(*) as total_disputes,
        SUM(CASE WHEN d.status = 'open' THEN 1 ELSE 0 END) as open_disputes,
        SUM(CASE WHEN d.status = 'resolved' THEN 1 ELSE 0 END) as resolved_disputes,
        ROUND(AVG(e.amount), 2) as avg_amount,
        MAX(e.amount) as max_amount,
        MIN(e.amount) as min_amount
    FROM disputes d
    JOIN escrow e ON d.escrow_id = e.id
";
$statsStmt = $pdo->query($statsQuery);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

// Get resolution breakdown
$resolutionQuery = "
    SELECT 
        d.resolution,
        COUNT(*) as count
    FROM disputes d
    WHERE d.status = 'resolved'
    GROUP BY d.resolution
";
$resolutionStmt = $pdo->query($resolutionQuery);
$resolutions = $resolutionStmt->fetchAll(PDO::FETCH_ASSOC);
$resolutionBreakdown = [];
foreach ($resolutions as $res) {
    $resolutionBreakdown[$res['resolution']] = $res['count'];
}
?>

<div class="container-fluid mt-5" style="max-width: 1400px; margin-left: auto; margin-right: auto;">
    <!-- Header -->
    <div class="row mb-4">
        <div class="col-md-8">
            <h1><i class="fas fa-gavel"></i> Disputes Management</h1>
            <p class="text-muted">Comprehensive admin panel for dispute management</p>
        </div>
        <div class="col-md-4 text-end">
            <a href="/admin/disputes_list.php" class="btn btn-primary">
                <i class="fas fa-sync"></i> Refresh
            </a>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-md-2">
            <div class="card text-center border-0 shadow-sm">
                <div class="card-body">
                    <h6 class="card-title text-muted mb-2">Total Disputes</h6>
                    <h2 class="text-primary mb-0"><?php echo $stats['total_disputes']; ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card text-center border-0 shadow-sm">
                <div class="card-body">
                    <h6 class="card-title text-muted mb-2">Open</h6>
                    <h2 class="text-danger mb-0"><?php echo $stats['open_disputes']; ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card text-center border-0 shadow-sm">
                <div class="card-body">
                    <h6 class="card-title text-muted mb-2">Resolved</h6>
                    <h2 class="text-success mb-0"><?php echo $stats['resolved_disputes']; ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card text-center border-0 shadow-sm">
                <div class="card-body">
                    <h6 class="card-title text-muted mb-2">Avg Amount</h6>
                    <h2 class="text-info mb-0" style="font-size: 1.3em;">$<?php echo number_format($stats['avg_amount'], 0); ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card text-center border-0 shadow-sm">
                <div class="card-body">
                    <h6 class="card-title text-muted mb-2">Max Amount</h6>
                    <h2 class="text-warning mb-0" style="font-size: 1.3em;">$<?php echo number_format($stats['max_amount'], 0); ?></h2>
                </div>
            </div>
        </div>
    </div>

    <!-- Resolution Breakdown -->
    <?php if ($stats['resolved_disputes'] > 0): ?>
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-light">
                        <h5 class="mb-0">Resolution Breakdown</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-4">
                                <p><strong>Refund to Buyer:</strong>
                                    <span class="badge bg-danger">
                                        <?php echo $resolutionBreakdown['refund_buyer'] ?? 0; ?>
                                    </span>
                                </p>
                            </div>
                            <div class="col-md-4">
                                <p><strong>Release to Seller:</strong>
                                    <span class="badge bg-success">
                                        <?php echo $resolutionBreakdown['release_to_seller'] ?? 0; ?>
                                    </span>
                                </p>
                            </div>
                            <div class="col-md-4">
                                <p><strong>Split Payment:</strong>
                                    <span class="badge bg-warning">
                                        <?php echo $resolutionBreakdown['split'] ?? 0; ?>
                                    </span>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Filters & Search -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <!-- Status Filter -->
                <div class="col-md-3">
                    <label class="form-label"><strong>Status</strong></label>
                    <select name="status" class="form-select">
                        <option value="all" <?php echo ($statusFilter === 'all') ? 'selected' : ''; ?>>All Disputes</option>
                        <option value="open" <?php echo ($statusFilter === 'open') ? 'selected' : ''; ?>>Open Only</option>
                        <option value="resolved" <?php echo ($statusFilter === 'resolved') ? 'selected' : ''; ?>>Resolved Only</option>
                    </select>
                </div>

                <!-- Sort -->
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

                <!-- Search -->
                <div class="col-md-4">
                    <label class="form-label"><strong>Search</strong></label>
                    <input type="text" name="search" class="form-control" placeholder="Project, opener, or dispute ID..." value="<?php echo htmlspecialchars($searchQuery); ?>">
                </div>

                <!-- Buttons -->
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-search"></i> Search
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Disputes Table -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-light">
            <div class="d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Disputes (<?php echo $totalDisputes; ?> total)</h5>
                <small class="text-muted">Page <?php echo $pageNum; ?> of <?php echo $totalPages; ?></small>
            </div>
        </div>
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
                            <td colspan="9" class="text-center py-4 text-muted">
                                No disputes found
                            </td>
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
                                <td>
                                    <span class="badge bg-info"><?php echo $dispute['message_count']; ?></span>
                                </td>
                                <td>
                                    <span class="badge bg-secondary"><?php echo $dispute['evidence_count']; ?></span>
                                </td>
                                <td>
                                    <small><?php echo date('M d, Y', strtotime($dispute['opened_at'])); ?></small>
                                </td>
                                <td>
                                    <a href="/admin/dispute_review.php?id=<?php echo $dispute['id']; ?>"
                                        class="btn btn-sm btn-primary">
                                        <i class="fas fa-eye"></i> Review
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
        <div class="d-flex justify-content-center mt-4 mb-4">
            <nav aria-label="Page navigation">
                <ul class="pagination">
                    <!-- Previous -->
                    <li class="page-item <?php echo ($pageNum <= 1) ? 'disabled' : ''; ?>">
                        <a class="page-link" href="?page=<?php echo max(1, $pageNum - 1); ?>&status=<?php echo $statusFilter; ?>&sort=<?php echo $sortBy; ?>&search=<?php echo urlencode($searchQuery); ?>">Previous</a>
                    </li>

                    <!-- Page numbers -->
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

                    <!-- Next -->
                    <li class="page-item <?php echo ($pageNum >= $totalPages) ? 'disabled' : ''; ?>">
                        <a class="page-link" href="?page=<?php echo min($totalPages, $pageNum + 1); ?>&status=<?php echo $statusFilter; ?>&sort=<?php echo $sortBy; ?>&search=<?php echo urlencode($searchQuery); ?>">Next</a>
                    </li>
                </ul>
            </nav>
        </div>
    <?php endif; ?>
</div>

<?php require_once '../includes/footer.php'; ?>