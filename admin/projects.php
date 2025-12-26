<?php
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../services/AdminAuditService.php';

requireRole('admin');

$auditService = new AdminAuditService($pdo);

// Get filter parameters
$search = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status'] ?? '';
$pageNum = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;

// Build query
$where = 'WHERE 1=1';
$params = [];

if ($search) {
    $where .= ' AND (p.title LIKE ? OR p.description LIKE ? OR b.username LIKE ?)';
    $like = "%{$search}%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}
if ($statusFilter) {
    $where .= ' AND p.status = ?';
    $params[] = $statusFilter;
}

// Count total
$countSql = "SELECT COUNT(*) FROM projects p JOIN users b ON p.buyer_id = b.id $where";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalProjects = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalProjects / $perPage));

// Get projects
$offset = ($pageNum - 1) * $perPage;
$dataSql = "SELECT p.*, 
           b.full_name as buyer_name, b.email as buyer_email,
           s.full_name as seller_name, s.email as seller_email,
           (SELECT COUNT(*) FROM escrow WHERE project_id = p.id) as escrow_count,
           (SELECT COUNT(*) FROM disputes WHERE project_id = p.id) as dispute_count,
           (SELECT created_at FROM escrow WHERE project_id = p.id LIMIT 1) as escrow_created
    FROM projects p 
    JOIN users b ON p.buyer_id = b.id 
    LEFT JOIN users s ON p.seller_id = s.id
    $where
    ORDER BY p.created_at DESC
    LIMIT :limit OFFSET :offset";
$dataStmt = $pdo->prepare($dataSql);
foreach ($params as $key => $value) {
    $dataStmt->bindValue($key + 1, $value);
}
$dataStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$dataStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$dataStmt->execute();
$projects = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

// Handle actions
$message = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $projectId = (int)($_POST['project_id'] ?? 0);
    $project = $pdo->prepare("SELECT * FROM projects WHERE id = ?");
    $project->execute([$projectId]);
    $projectData = $project->fetch(PDO::FETCH_ASSOC);

    if ($projectData) {
        switch ($action) {
            case 'cancel_project':
                $reason = $_POST['reason'] ?? '';
                $oldData = ['status' => $projectData['status']];
                $stmt = $pdo->prepare("UPDATE projects SET status = 'cancelled' WHERE id = ?");
                $stmt->execute([$projectId]);
                $auditService->logAction(
                    $_SESSION['user_id'],
                    'cancel_project',
                    'project',
                    $projectId,
                    "Cancelled project: {$reason}",
                    $oldData,
                    ['status' => 'cancelled']
                );
                $message = "✅ Project cancelled";
                break;

            case 'reassign_seller':
                $newSellerId = (int)($_POST['new_seller_id'] ?? 0);
                if ($newSellerId) {
                    $oldData = ['seller_id' => $projectData['seller_id']];
                    $stmt = $pdo->prepare("UPDATE projects SET seller_id = ? WHERE id = ?");
                    $stmt->execute([$newSellerId, $projectId]);
                    $auditService->logAction(
                        $_SESSION['user_id'],
                        'reassign_seller',
                        'project',
                        $projectId,
                        "Reassigned seller from {$projectData['seller_id']} to {$newSellerId}",
                        $oldData,
                        ['seller_id' => $newSellerId]
                    );
                    $message = "✅ Seller reassigned";
                }
                break;
        }

        // Reload projects
        foreach ($params as $key => $value) {
            $dataStmt->bindValue($key + 1, $value);
        }
        $dataStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $dataStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $dataStmt->execute();
        $projects = $dataStmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Get all sellers for reassign dropdown
$sellerStmt = $pdo->query("SELECT id, full_name as username FROM users WHERE role = 'seller' ORDER BY full_name");
$sellers = $sellerStmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Project Management - Jacob Admin</title>
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
                <li><a href="/admin/projects.php" class="active"><span>📦</span> <span>Projects</span></a></li>
                <li><a href="/dashboard/admin_escrows.php"><span>📋</span> <span>Escrows</span></a></li>
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
                <h2 class="section-title">📦 Project Management</h2>

                <?php if ($message): ?>
                    <div style="background: #d4edda; color: #155724; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1rem;"><?php echo $message; ?></div>
                <?php endif; ?>

                <!-- Filters -->
                <div style="background: white; padding: 1.5rem; border-radius: 1rem; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); margin-bottom: 1.5rem;">
                    <form method="GET" class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label"><strong>Search</strong></label>
                            <input type="text" name="search" class="form-control" placeholder="Project title, description, or buyer..." value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label"><strong>Status</strong></label>
                            <select name="status" class="form-select">
                                <option value="">All Statuses</option>
                                <option value="open" <?php echo ($statusFilter === 'open') ? 'selected' : ''; ?>>Open</option>
                                <option value="assigned" <?php echo ($statusFilter === 'assigned') ? 'selected' : ''; ?>>Assigned</option>
                                <option value="in_progress" <?php echo ($statusFilter === 'in_progress') ? 'selected' : ''; ?>>In Progress</option>
                                <option value="completed" <?php echo ($statusFilter === 'completed') ? 'selected' : ''; ?>>Completed</option>
                                <option value="cancelled" <?php echo ($statusFilter === 'cancelled') ? 'selected' : ''; ?>>Cancelled</option>
                            </select>
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary w-100">Filter</button>
                        </div>
                    </form>
                </div>

                <!-- Projects Table -->
                <div style="background: white; padding: 0; border-radius: 1rem; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Project ID</th>
                                    <th>Title</th>
                                    <th>Buyer</th>
                                    <th>Seller</th>
                                    <th>Budget</th>
                                    <th>Status</th>
                                    <th>Linked Escrow</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($projects)): ?>
                                    <tr>
                                        <td colspan="8" class="text-center py-4 text-muted">No projects found</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($projects as $project): ?>
                                        <tr>
                                            <td><strong>#<?php echo $project['id']; ?></strong></td>
                                            <td>
                                                <strong><?php echo htmlspecialchars(substr($project['title'], 0, 30)); ?></strong>
                                                <?php if (strlen($project['title']) > 30): ?>...<?php endif; ?>
                                            </td>
                                            <td>
                                                <small><?php echo htmlspecialchars($project['buyer_name']); ?></small><br>
                                                <small style="color: #999;"><?php echo htmlspecialchars($project['buyer_email']); ?></small>
                                            </td>
                                            <td>
                                                <?php if ($project['seller_name']): ?>
                                                    <small><?php echo htmlspecialchars($project['seller_name']); ?></small><br>
                                                    <small style="color: #999;"><?php echo htmlspecialchars($project['seller_email']); ?></small>
                                                <?php else: ?>
                                                    <small style="color: #999;">— Unassigned</small>
                                                <?php endif; ?>
                                            </td>
                                            <td><strong><?php echo '$' . number_format($project['budget'], 2); ?></strong></td>
                                            <td>
                                                <span style="background: <?php echo $project['status'] === 'open' ? '#cfe2ff' : ($project['status'] === 'completed' ? '#d1e7dd' : '#fff3cd'); ?>; color: <?php echo $project['status'] === 'open' ? '#084298' : ($project['status'] === 'completed' ? '#0f5132' : '#664d03'); ?>; padding: 4px 8px; border-radius: 4px; font-size: 0.85rem;">
                                                    <?php echo ucfirst(str_replace('_', ' ', $project['status'])); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($project['escrow_count']): ?>
                                                    <span style="background: #d4edda; color: #155724; padding: 4px 8px; border-radius: 4px; font-size: 0.85rem;">✓ Yes</span>
                                                <?php else: ?>
                                                    <span style="background: #f8d7da; color: #721c24; padding: 4px 8px; border-radius: 4px; font-size: 0.85rem;">✗ No</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <button onclick="showProjectModal(<?php echo $project['id']; ?>, '<?php echo htmlspecialchars($project['title'], ENT_QUOTES); ?>', '<?php echo $project['status']; ?>')" class="btn btn-sm btn-primary">Manage</button>
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
                    <nav aria-label="Pagination" class="mt-4">
                        <ul class="pagination justify-content-center">
                            <li class="page-item <?php echo ($pageNum <= 1) ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo max(1, $pageNum - 1); ?>&search=<?php echo urlencode($search); ?>&status=<?php echo $statusFilter; ?>">Previous</a>
                            </li>
                            <?php for ($i = max(1, $pageNum - 2); $i <= min($totalPages, $pageNum + 2); $i++): ?>
                                <li class="page-item <?php echo ($i === $pageNum) ? 'active' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo $statusFilter; ?>"><?php echo $i; ?></a>
                                </li>
                            <?php endfor; ?>
                            <li class="page-item <?php echo ($pageNum >= $totalPages) ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo min($totalPages, $pageNum + 1); ?>&search=<?php echo urlencode($search); ?>&status=<?php echo $statusFilter; ?>">Next</a>
                            </li>
                        </ul>
                    </nav>
                <?php endif; ?>
            </section>
        </main>
    </div>

    <!-- Project Action Modal -->
    <div id="projectModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; justify-content: center; align-items: center; overflow-y: auto;">
        <div style="background: white; padding: 2rem; border-radius: 1rem; width: 90%; max-width: 500px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1); margin: 2rem auto;">
            <h3 id="modalTitle" style="margin-top: 0; margin-bottom: 1rem;">Manage Project</h3>
            <form method="POST" id="projectForm">
                <input type="hidden" name="project_id" id="projectId">
                <input type="hidden" name="action" id="action">

                <div class="mb-3">
                    <label class="form-label"><strong>Cancel Project</strong></label>
                    <textarea name="reason" class="form-control" placeholder="Reason for cancellation..." style="height: 100px;"></textarea>
                </div>

                <div class="mb-3">
                    <label class="form-label"><strong>Reassign Seller</strong></label>
                    <select name="new_seller_id" class="form-select">
                        <option value="">-- Select seller --</option>
                        <?php foreach ($sellers as $seller): ?>
                            <option value="<?php echo $seller['id']; ?>"><?php echo htmlspecialchars($seller['username']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="display: flex; gap: 0.5rem;">
                    <button type="button" onclick="submitAction('cancel_project')" class="btn btn-danger flex-1">Cancel Project</button>
                    <button type="button" onclick="submitAction('reassign_seller')" class="btn btn-warning flex-1">Reassign Seller</button>
                </div>
            </form>
            <button onclick="closeModal()" class="btn btn-secondary w-100 mt-3">Close</button>
        </div>
    </div>

    <script>
        function showProjectModal(projectId, title, status) {
            document.getElementById('projectId').value = projectId;
            document.getElementById('modalTitle').textContent = `Manage Project: ${title}`;
            document.getElementById('projectModal').style.display = 'flex';
        }

        function closeModal() {
            document.getElementById('projectModal').style.display = 'none';
        }

        function submitAction(action) {
            document.getElementById('action').value = action;
            document.getElementById('projectForm').submit();
        }
    </script>

</body>

</html>