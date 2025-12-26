<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../services/AdminAuditService.php';

requireRole('admin');

$auditService = new AdminAuditService($pdo);

// Get filter parameters
$search = trim($_GET['search'] ?? '');
$roleFilter = $_GET['role'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$pageNum = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;

// Build query
$where = 'WHERE 1=1';
$params = [];

if ($search) {
    $where .= ' AND (u.full_name LIKE :search_name OR u.email LIKE :search_email)';
    $like = "%{$search}%";
    $params[':search_name'] = $like;
    $params[':search_email'] = $like;
}
if ($roleFilter) {
    $where .= ' AND u.role = :role';
    $params[':role'] = $roleFilter;
}
if ($statusFilter) {
    $where .= ' AND u.status = :status';
    $params[':status'] = $statusFilter;
}

// Count total
$countSql = "SELECT COUNT(*) FROM users u $where";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalUsers = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalUsers / $perPage));

// Get users
$offset = ($pageNum - 1) * $perPage;
$dataSql = "SELECT u.*, 
           (SELECT COUNT(*) FROM projects WHERE buyer_id = u.id) as projects_posted,
           (SELECT COUNT(*) FROM escrow WHERE seller_id = u.id) as projects_completed,
           (SELECT COUNT(*) FROM escrow WHERE buyer_id = u.id OR seller_id = u.id) as escrows_involved,
           (SELECT COUNT(*) FROM disputes WHERE opened_by = u.id) as disputes_opened
    FROM users u 
    $where
    ORDER BY u.created_at DESC
    LIMIT :limit OFFSET :offset";
$dataStmt = $pdo->prepare($dataSql);
foreach ($params as $key => $value) {
    $dataStmt->bindValue($key, $value);
}
$dataStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$dataStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$dataStmt->execute();
$users = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

// Handle actions
$message = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $userId = (int)($_POST['user_id'] ?? 0);
    $user = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $user->execute([$userId]);
    $userData = $user->fetch(PDO::FETCH_ASSOC);
    if ($userData && !array_key_exists('kyc_verified', $userData)) {
        $userData['kyc_verified'] = 0;
    }

    if ($userData) {
        $oldData = ['role' => $userData['role'], 'status' => $userData['status'], 'kyc_verified' => ($userData['kyc_verified'] ?? 0)];

        switch ($action) {
            case 'change_role':
                $newRole = $_POST['new_role'] ?? '';
                if (in_array($newRole, ['admin', 'buyer', 'seller'])) {
                    $stmt = $pdo->prepare("UPDATE users SET role = ? WHERE id = ?");
                    $stmt->execute([$newRole, $userId]);
                    $auditService->logAction(
                        $_SESSION['user_id'],
                        'change_user_role',
                        'user',
                        $userId,
                        "Changed user role from {$userData['role']} to {$newRole}",
                        $oldData,
                        ['role' => $newRole, 'status' => $userData['status'], 'kyc_verified' => $userData['kyc_verified']]
                    );
                    $message = "✅ User role updated to {$newRole}";
                }
                break;

            case 'suspend':
                $stmt = $pdo->prepare("UPDATE users SET status = 'suspended' WHERE id = ?");
                $stmt->execute([$userId]);
                $auditService->logAction(
                    $_SESSION['user_id'],
                    'suspend_user',
                    'user',
                    $userId,
                    "Suspended user {$userData['full_name']}",
                    $oldData,
                    ['role' => $userData['role'], 'status' => 'suspended', 'kyc_verified' => $userData['kyc_verified']]
                );
                $message = "✅ User suspended";
                break;

            case 'unsuspend':
                $stmt = $pdo->prepare("UPDATE users SET status = 'active' WHERE id = ?");
                $stmt->execute([$userId]);
                $auditService->logAction(
                    $_SESSION['user_id'],
                    'unsuspend_user',
                    'user',
                    $userId,
                    "Reactivated user {$userData['full_name']}",
                    $oldData,
                    ['role' => $userData['role'], 'status' => 'active', 'kyc_verified' => $userData['kyc_verified']]
                );
                $message = "✅ User reactivated";
                break;

            case 'ban':
                $stmt = $pdo->prepare("UPDATE users SET status = 'banned' WHERE id = ?");
                $stmt->execute([$userId]);
                $auditService->logAction(
                    $_SESSION['user_id'],
                    'ban_user',
                    'user',
                    $userId,
                    "Banned user {$userData['full_name']}",
                    $oldData,
                    ['role' => $userData['role'], 'status' => 'banned', 'kyc_verified' => $userData['kyc_verified']]
                );
                $message = "✅ User banned";
                break;

            case 'verify_kyc':
                $stmt = $pdo->prepare("UPDATE users SET kyc_verified = 1 WHERE id = ?");
                $stmt->execute([$userId]);
                $auditService->logAction(
                    $_SESSION['user_id'],
                    'verify_kyc',
                    'user',
                    $userId,
                    "Manually verified KYC for {$userData['full_name']}",
                    $oldData,
                    ['role' => $userData['role'], 'status' => $userData['status'], 'kyc_verified' => 1]
                );
                $message = "✅ KYC verified for user";
                break;
        }

        // Reload users
        foreach ($params as $key => $value) {
            $dataStmt->bindValue($key, $value);
        }
        $dataStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $dataStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $dataStmt->execute();
        $users = $dataStmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - Jacob Admin</title>
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
                <li><a href="/admin/users.php" class="active"><span>👥</span> <span>Users</span></a></li>
                <li><a href="/admin/projects.php"><span>📦</span> <span>Projects</span></a></li>
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
                <h2 class="section-title">👥 User Management</h2>

                <?php if ($message): ?>
                    <div style="background: #d4edda; color: #155724; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1rem;"><?php echo $message; ?></div>
                <?php endif; ?>

                <!-- Filters -->
                <div style="background: white; padding: 1.5rem; border-radius: 1rem; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); margin-bottom: 1.5rem;">
                    <form method="GET" class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label"><strong>Search</strong></label>
                            <input type="text" name="search" class="form-control" placeholder="Username, email, or name..." value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label"><strong>Role</strong></label>
                            <select name="role" class="form-select">
                                <option value="">All Roles</option>
                                <option value="admin" <?php echo ($roleFilter === 'admin') ? 'selected' : ''; ?>>Admin</option>
                                <option value="seller" <?php echo ($roleFilter === 'seller') ? 'selected' : ''; ?>>Seller</option>
                                <option value="buyer" <?php echo ($roleFilter === 'buyer') ? 'selected' : ''; ?>>Buyer</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label"><strong>Status</strong></label>
                            <select name="status" class="form-select">
                                <option value="">All Statuses</option>
                                <option value="active" <?php echo ($statusFilter === 'active') ? 'selected' : ''; ?>>Active</option>
                                <option value="suspended" <?php echo ($statusFilter === 'suspended') ? 'selected' : ''; ?>>Suspended</option>
                                <option value="banned" <?php echo ($statusFilter === 'banned') ? 'selected' : ''; ?>>Banned</option>
                            </select>
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary w-100">Filter</button>
                        </div>
                    </form>
                </div>

                <!-- Users Table -->
                <div style="background: white; padding: 0; border-radius: 1rem; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>User ID</th>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Role</th>
                                    <th>Status</th>
                                    <th>KYC</th>
                                    <th>Activity</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($users)): ?>
                                    <tr>
                                        <td colspan="8" class="text-center py-4 text-muted">No users found</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($users as $user): ?>
                                        <tr>
                                            <td><strong>#<?php echo $user['id']; ?></strong></td>
                                            <td><strong><?php echo htmlspecialchars($user['full_name']); ?></strong></td>
                                            <td><small style="color: #666;"><?php echo htmlspecialchars($user['email']); ?></small></td>
                                            <td><span style="background: #e3f2fd; color: #1976d2; padding: 4px 8px; border-radius: 4px; font-size: 0.85rem;"><?php echo ucfirst($user['role']); ?></span></td>
                                            <td>
                                                <span style="background: <?php echo $user['status'] === 'active' ? '#d4edda' : ($user['status'] === 'suspended' ? '#fff3cd' : '#f8d7da'); ?>; color: <?php echo $user['status'] === 'active' ? '#155724' : ($user['status'] === 'suspended' ? '#856404' : '#721c24'); ?>; padding: 4px 8px; border-radius: 4px; font-size: 0.85rem;">
                                                    <?php echo ucfirst($user['status']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if (!empty($user['kyc_verified'])): ?>
                                                    <span style="background: #d4edda; color: #155724; padding: 4px 8px; border-radius: 4px; font-size: 0.85rem;">✓ Verified</span>
                                                <?php else: ?>
                                                    <span style="background: #f8d7da; color: #721c24; padding: 4px 8px; border-radius: 4px; font-size: 0.85rem;">✗ Pending</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <small>
                                                    <?php if ($user['role'] === 'buyer'): ?>
                                                        📦 <?php echo $user['projects_posted']; ?>
                                                    <?php elseif ($user['role'] === 'seller'): ?>
                                                        ✓ <?php echo $user['projects_completed']; ?>
                                                    <?php endif; ?>
                                                    · ⚖️ <?php echo $user['disputes_opened']; ?>
                                                </small>
                                            </td>
                                            <td style="font-size: 0.85rem;">
                                                <button onclick="showUserModal(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['full_name'], ENT_QUOTES); ?>', '<?php echo $user['role']; ?>', '<?php echo $user['status']; ?>', <?php echo !empty($user['kyc_verified']) ? 1 : 0; ?>)" class="btn btn-sm btn-primary">Manage</button>
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
                                <a class="page-link" href="?page=<?php echo max(1, $pageNum - 1); ?>&search=<?php echo urlencode($search); ?>&role=<?php echo $roleFilter; ?>&status=<?php echo $statusFilter; ?>">Previous</a>
                            </li>
                            <?php for ($i = max(1, $pageNum - 2); $i <= min($totalPages, $pageNum + 2); $i++): ?>
                                <li class="page-item <?php echo ($i === $pageNum) ? 'active' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&role=<?php echo $roleFilter; ?>&status=<?php echo $statusFilter; ?>"><?php echo $i; ?></a>
                                </li>
                            <?php endfor; ?>
                            <li class="page-item <?php echo ($pageNum >= $totalPages) ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo min($totalPages, $pageNum + 1); ?>&search=<?php echo urlencode($search); ?>&role=<?php echo $roleFilter; ?>&status=<?php echo $statusFilter; ?>">Next</a>
                            </li>
                        </ul>
                    </nav>
                <?php endif; ?>
            </section>
        </main>
    </div>

    <!-- User Action Modal -->
    <div id="userModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999; justify-content: center; align-items: center;">
        <div id="userModalContent" style="background: #f0f9ff; padding: 1.5rem; border-radius: 1rem; width: 92%; max-width: 520px; max-height: 90vh; overflow-y: auto; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1); border: 1px solid #e5e7eb;">
            <h3 id="modalTitle" style="margin: 0 0 1rem;">Manage User</h3>
            <div style="background: #ffffff; border: 1px solid #e5e7eb; border-radius: 0.75rem; padding: 1rem; margin-bottom: 0.75rem;">
                <form method="POST" id="userForm">
                    <input type="hidden" name="user_id" id="userId">
                    <input type="hidden" name="action" id="action">

                    <div id="roleSection" class="mb-3">
                        <label class="form-label" style="color: #111;"><strong>Change Role</strong></label>
                        <select id="newRole" name="new_role" class="form-select" style="width: 100%; background: #fff; color: #111; border: 1px solid #e5e7eb; border-radius: 0.5rem; padding: 0.5rem 0.75rem;">
                            <option value="">-- Select new role --</option>
                            <option value="admin">Admin</option>
                            <option value="seller">Seller</option>
                            <option value="buyer">Buyer</option>
                        </select>
                    </div>

                    <div id="actionButtons" style="display: flex; flex-wrap: wrap; gap: 0.5rem;">
                        <button type="button" id="btnChangeRole" onclick="submitAction('change_role')" class="btn" style="flex: 1 1 48%; background-color: #0ea5e9; border-color: #0ea5e9; color: white;">Change Role</button>
                        <button type="button" id="btnSuspend" onclick="submitAction('suspend')" class="btn" style="flex: 1 1 48%; background-color: #06b6d4; border-color: #06b6d4; color: white;">Suspend</button>
                        <button type="button" id="btnUnsuspend" onclick="submitAction('unsuspend')" class="btn" style="flex: 1 1 48%; background-color: #0ea5e9; border-color: #0ea5e9; color: white;">Reactivate</button>
                        <button type="button" id="btnBan" onclick="submitAction('ban')" class="btn" style="flex: 1 1 48%; background-color: #ef4444; border-color: #ef4444; color: white;">Ban</button>
                        <button type="button" id="btnVerifyKyc" onclick="submitAction('verify_kyc')" class="btn" style="flex: 1 1 48%; background-color: #0ea5e9; border-color: #0ea5e9; color: white;">Verify KYC</button>
                    </div>
                </form>
            </div>
            <button onclick="closeModal()" class="btn" style="width: 100%; margin-top: 0.75rem; background-color: #6b7280; border-color: #6b7280; color: white;">Close</button>
        </div>
    </div>

    <style>
        /* Scoped styles for modal to ensure good contrast */
        #userModalContent .form-label {
            color: #111;
        }

        #userModalContent input[type="text"],
        #userModalContent input[type="email"],
        #userModalContent select,
        #userModalContent textarea {
            background: #fff;
            color: #111;
            border: 1px solid #e5e7eb;
            border-radius: 0.5rem;
            padding: 0.5rem 0.75rem;
        }

        /* Increase spacing between table columns */
        .table td,
        .table th {
            padding: 0.75rem 1.25rem;
        }
    </style>

    <script>
        function showUserModal(userId, username, role, status, kycVerified) {
            const modal = document.getElementById('userModal');
            const roleSelect = document.getElementById('newRole');

            document.getElementById('userId').value = userId;
            document.getElementById('modalTitle').textContent = `Manage User: ${username}`;

            // Preselect current role for convenience
            if (roleSelect) {
                roleSelect.value = role || '';
            }

            // Toggle action buttons based on current status/KYC
            const isActive = status === 'active';
            const isSuspended = status === 'suspended';
            const isBanned = status === 'banned';

            const btnSuspend = document.getElementById('btnSuspend');
            const btnUnsuspend = document.getElementById('btnUnsuspend');
            const btnBan = document.getElementById('btnBan');
            const btnVerifyKyc = document.getElementById('btnVerifyKyc');

            if (btnSuspend) btnSuspend.style.display = isActive ? 'inline-block' : 'none';
            if (btnUnsuspend) btnUnsuspend.style.display = isSuspended ? 'inline-block' : 'none';
            if (btnBan) btnBan.style.display = isBanned ? 'none' : 'inline-block';
            if (btnVerifyKyc) btnVerifyKyc.style.display = kycVerified ? 'none' : 'inline-block';

            // Show modal and lock scroll
            modal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }

        function closeModal() {
            const modal = document.getElementById('userModal');
            modal.style.display = 'none';
            document.body.style.overflow = '';
        }

        function submitAction(action) {
            document.getElementById('action').value = action;
            if (action === 'change_role' && !document.getElementById('newRole').value) {
                alert('Please select a new role');
                return;
            }
            document.getElementById('userForm').submit();
        }

        // Close modal when clicking outside content
        (function() {
            const overlay = document.getElementById('userModal');
            const content = document.getElementById('userModalContent');
            if (!overlay || !content) return;
            overlay.addEventListener('click', function(e) {
                if (!content.contains(e.target)) {
                    closeModal();
                }
            });
            // ESC to close
            window.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && overlay.style.display === 'flex') {
                    closeModal();
                }
            });
        })();
    </script>

</body>

</html>