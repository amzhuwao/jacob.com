<?php

/**
 * Disputes Dashboard
 * 
 * Admin view of all disputes with filtering and quick actions
 */

require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/header.php';

$userId = $_SESSION['user_id'] ?? null;
if ($_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo 'Admin access required';
    exit;
}

// Get filters
$statusFilter = $_GET['status'] ?? 'open';
$sortBy = $_GET['sort'] ?? 'opened_at';

// Get disputes based on filter
$query = "
    SELECT d.*, e.amount, p.title as project_title,
           u_opener.username as opener_name,
           u_buyer.username as buyer_name,
           u_seller.username as seller_name,
           COUNT(dm.id) as message_count,
           COUNT(de.id) as evidence_count
    FROM disputes d
    JOIN escrow e ON d.escrow_id = e.id
    JOIN projects p ON e.project_id = p.id
    JOIN users u_opener ON d.opened_by = u_opener.id
    JOIN users u_buyer ON e.buyer_id = u_buyer.id
    JOIN users u_seller ON e.seller_id = u_seller.id
    LEFT JOIN dispute_messages dm ON d.id = dm.dispute_id
    LEFT JOIN dispute_evidence de ON d.id = de.dispute_id
";

if ($statusFilter !== 'all') {
    $query .= " WHERE d.status = '{$statusFilter}'";
}

$query .= " GROUP BY d.id";

// Sorting
switch ($sortBy) {
    case 'amount_high':
        $query .= " ORDER BY e.amount DESC";
        break;
    case 'amount_low':
        $query .= " ORDER BY e.amount ASC";
        break;
    case 'messages':
        $query .= " ORDER BY message_count DESC";
        break;
    default:
        $query .= " ORDER BY d.opened_at DESC";
}

$stmt = $pdo->prepare($query);
$stmt->execute();
$disputes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get dispute statistics
$statsStmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) as open_count,
        SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) as resolved_count,
        ROUND(AVG(e.amount), 2) as avg_amount
    FROM disputes d
    JOIN escrow e ON d.escrow_id = e.id
");
$statsStmt->execute();
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);
?>

<div class="container mt-5">
    <div class="row mb-4">
        <div class="col-md-12">
            <h1><i class="fas fa-gavel"></i> Disputes Dashboard</h1>
            <p class="text-muted">Manage and resolve transaction disputes</p>
        </div>
    </div>

    <!-- Statistics -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <h5 class="card-title">Total Disputes</h5>
                    <h3 class="text-primary"><?php echo $stats['total']; ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <h5 class="card-title">Open</h5>
                    <h3 class="text-danger"><?php echo $stats['open_count']; ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <h5 class="card-title">Resolved</h5>
                    <h3 class="text-success"><?php echo $stats['resolved_count']; ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <h5 class="card-title">Avg Amount</h5>
                    <h3 class="text-info">$<?php echo number_format($stats['avg_amount'], 2); ?></h3>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-body">
            <div class="row">
                <div class="col-md-4">
                    <label>Status</label>
                    <select class="form-select" onchange="window.location.href='?status=' + this.value + '&sort=<?php echo $sortBy; ?>'">
                        <option value="all" <?php echo ($statusFilter === 'all') ? 'selected' : ''; ?>>All</option>
                        <option value="open" <?php echo ($statusFilter === 'open') ? 'selected' : ''; ?>>Open</option>
                        <option value="resolved" <?php echo ($statusFilter === 'resolved') ? 'selected' : ''; ?>>Resolved</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label>Sort By</label>
                    <select class="form-select" onchange="window.location.href='?status=<?php echo $statusFilter; ?>&sort=' + this.value">
                        <option value="opened_at" <?php echo ($sortBy === 'opened_at') ? 'selected' : ''; ?>>Newest First</option>
                        <option value="amount_high" <?php echo ($sortBy === 'amount_high') ? 'selected' : ''; ?>>Highest Amount</option>
                        <option value="amount_low" <?php echo ($sortBy === 'amount_low') ? 'selected' : ''; ?>>Lowest Amount</option>
                        <option value="messages" <?php echo ($sortBy === 'messages') ? 'selected' : ''; ?>>Most Activity</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <!-- Disputes Table -->
    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Dispute ID</th>
                        <th>Project</th>
                        <th>Amount</th>
                        <th>Opener</th>
                        <th>Status</th>
                        <th>Messages</th>
                        <th>Evidence</th>
                        <th>Opened</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($disputes as $dispute): ?>
                        <tr>
                            <td><strong>#<?php echo $dispute['id']; ?></strong></td>
                            <td><?php echo htmlspecialchars($dispute['project_title']); ?></td>
                            <td>
                                <strong>$<?php echo number_format($dispute['amount'], 2); ?></strong>
                            </td>
                            <td><?php echo htmlspecialchars($dispute['opener_name']); ?></td>
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
                                <a href="/disputes/dispute_view.php?id=<?php echo $dispute['id']; ?>"
                                    class="btn btn-sm btn-primary">
                                    <i class="fas fa-eye"></i> Review
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php if (empty($disputes)): ?>
                <div class="text-center p-5 text-muted">
                    <p>No disputes found</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>