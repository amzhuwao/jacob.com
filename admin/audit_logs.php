<?php
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../services/AdminAuditService.php';

requireRole('admin');

$auditService = new AdminAuditService($pdo);

// Get filter parameters
$actionFilter = $_GET['action'] ?? '';
$entityFilter = $_GET['entity_type'] ?? '';
$adminFilter = $_GET['admin_id'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$pageNum = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;

// Build where clause
$where = 'WHERE 1=1';
$params = [];

if ($actionFilter) {
    $where .= ' AND a.action = ?';
    $params[] = $actionFilter;
}
if ($entityFilter) {
    $where .= ' AND a.entity_type = ?';
    $params[] = $entityFilter;
}
if ($adminFilter) {
    $where .= ' AND a.admin_user_id = ?';
    $params[] = $adminFilter;
}
if ($statusFilter) {
    $where .= ' AND a.status = ?';
    $params[] = $statusFilter;
}

// Count total
$countSql = "SELECT COUNT(*) FROM admin_activity_logs a $where";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalLogs = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalLogs / $perPage));

// Get activity logs
$offset = ($pageNum - 1) * $perPage;
$dataSql = "SELECT a.*, u.full_name as admin_name, u.email as admin_email
    FROM admin_activity_logs a 
    JOIN users u ON a.admin_user_id = u.id
    $where
    ORDER BY a.created_at DESC
    LIMIT :limit OFFSET :offset";
$dataStmt = $pdo->prepare($dataSql);
foreach ($params as $key => $value) {
    $dataStmt->bindValue($key + 1, $value);
}
$dataStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$dataStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$dataStmt->execute();
$logs = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

// Get available filters
$adminsStmt = $pdo->query("SELECT DISTINCT a.admin_user_id, u.full_name as username FROM admin_activity_logs a JOIN users u ON a.admin_user_id = u.id ORDER BY u.full_name");
$admins = $adminsStmt->fetchAll(PDO::FETCH_ASSOC);

$actionsStmt = $pdo->query("SELECT DISTINCT action FROM admin_activity_logs ORDER BY action");
$actions = $actionsStmt->fetchAll(PDO::FETCH_COLUMN);

$entitiesStmt = $pdo->query("SELECT DISTINCT entity_type FROM admin_activity_logs ORDER BY entity_type");
$entities = $entitiesStmt->fetchAll(PDO::FETCH_COLUMN);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audit Logs - Jacob Admin</title>
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
                <li><a href="/admin/financials.php"><span>💰</span> <span>Financials</span></a></li>
                <li><a href="/dashboard/admin_dashboard.php#withdrawals"><span>💸</span> <span>Withdrawals</span></a></li>
                <li><a href="/dashboard/admin_wallet_backfill.php"><span>🔄</span> <span>Wallet Backfill</span></a></li>
                <li><strong style="color: rgba(255,255,255,0.6); padding: 1rem; margin-top: 0.5rem; display: block; font-size: 0.85rem;">COMPLIANCE</strong></li>
                <li><a href="/admin/audit_logs.php" class="active"><span>📝</span> <span>Audit Logs</span></a></li>
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
                <h2 class="section-title">📝 Audit Logs</h2>

                <!-- Filters -->
                <div style="background: white; padding: 1.5rem; border-radius: 1rem; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); margin-bottom: 1.5rem;">
                    <form method="GET" class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label"><strong>Action</strong></label>
                            <select name="action" class="form-select">
                                <option value="">All Actions</option>
                                <?php foreach ($actions as $action): ?>
                                    <option value="<?php echo htmlspecialchars($action); ?>" <?php echo ($actionFilter === $action) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars(str_replace('_', ' ', ucfirst($action))); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label"><strong>Entity Type</strong></label>
                            <select name="entity_type" class="form-select">
                                <option value="">All Types</option>
                                <?php foreach ($entities as $entity): ?>
                                    <option value="<?php echo htmlspecialchars($entity); ?>" <?php echo ($entityFilter === $entity) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars(ucfirst($entity)); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label"><strong>Admin</strong></label>
                            <select name="admin_id" class="form-select">
                                <option value="">All Admins</option>
                                <?php foreach ($admins as $admin): ?>
                                    <option value="<?php echo $admin['admin_user_id']; ?>" <?php echo ($adminFilter == $admin['admin_user_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($admin['username']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary w-100">Filter</button>
                        </div>
                    </form>
                </div>

                <!-- Logs Table -->
                <div style="background: white; padding: 0; border-radius: 1rem; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Timestamp</th>
                                    <th>Admin</th>
                                    <th>Action</th>
                                    <th>Entity</th>
                                    <th>Description</th>
                                    <th>Status</th>
                                    <th>Details</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($logs)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center py-4 text-muted">No audit logs found</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($logs as $log): ?>
                                        <tr>
                                            <td><small><?php echo date('M d, Y H:i:s', strtotime($log['created_at'])); ?></small></td>
                                            <td>
                                                <small><strong><?php echo htmlspecialchars($log['admin_name']); ?></strong></small><br>
                                                <small style="color: #999;"><?php echo htmlspecialchars($log['admin_email']); ?></small>
                                            </td>
                                            <td><small style="background: #e3f2fd; color: #1976d2; padding: 4px 8px; border-radius: 4px; display: inline-block;"><?php echo htmlspecialchars(str_replace('_', ' ', $log['action'])); ?></small></td>
                                            <td>
                                                <small>
                                                    <strong><?php echo htmlspecialchars($log['entity_type']); ?></strong>
                                                    <br>ID: <?php echo $log['entity_id']; ?>
                                                </small>
                                            </td>
                                            <td><small><?php echo htmlspecialchars(substr($log['description'], 0, 50)); ?></small></td>
                                            <td>
                                                <span style="background: <?php echo $log['status'] === 'success' ? '#d1e7dd' : '#f8d7da'; ?>; color: <?php echo $log['status'] === 'success' ? '#0f5132' : '#721c24'; ?>; padding: 4px 8px; border-radius: 4px; font-size: 0.85rem;">
                                                    <?php echo ucfirst($log['status']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <button type="button" onclick="showDetails(<?php echo $log['id']; ?>)" class="btn btn-sm btn-outline-primary">View</button>
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
                                <a class="page-link" href="?page=<?php echo max(1, $pageNum - 1); ?>&action=<?php echo $actionFilter; ?>&entity_type=<?php echo $entityFilter; ?>&admin_id=<?php echo $adminFilter; ?>">Previous</a>
                            </li>
                            <?php for ($i = max(1, $pageNum - 2); $i <= min($totalPages, $pageNum + 2); $i++): ?>
                                <li class="page-item <?php echo ($i === $pageNum) ? 'active' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $i; ?>&action=<?php echo $actionFilter; ?>&entity_type=<?php echo $entityFilter; ?>&admin_id=<?php echo $adminFilter; ?>"><?php echo $i; ?></a>
                                </li>
                            <?php endfor; ?>
                            <li class="page-item <?php echo ($pageNum >= $totalPages) ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo min($totalPages, $pageNum + 1); ?>&action=<?php echo $actionFilter; ?>&entity_type=<?php echo $entityFilter; ?>&admin_id=<?php echo $adminFilter; ?>">Next</a>
                            </li>
                        </ul>
                    </nav>
                <?php endif; ?>
            </section>
        </main>
    </div>

    <!-- Details Modal -->
    <div id="detailsModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; justify-content: center; align-items: center; overflow-y: auto;">
        <div id="detailsContent" style="background: white; padding: 2rem; border-radius: 1rem; width: 90%; max-width: 600px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1); margin: 2rem auto;">
            <!-- Details will be loaded here -->
        </div>
    </div>

    <script>
        function showDetails(logId) {
            fetch('/api/admin/audit_log_details.php?id=' + logId)
                .then(r => r.text())
                .then(html => {
                    document.getElementById('detailsContent').innerHTML = html + '<button onclick="closeModal()" class="btn btn-secondary w-100 mt-3">Close</button>';
                    document.getElementById('detailsModal').style.display = 'flex';
                });
        }

        function closeModal() {
            document.getElementById('detailsModal').style.display = 'none';
        }
    </script>

</body>

</html>