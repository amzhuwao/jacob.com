<?php
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../services/WalletService.php';

requireRole('admin');

$walletService = new WalletService($pdo);

function getEligibleEscrows(PDO $pdo): array
{
    $sql = "
        SELECT e.id as escrow_id, e.project_id, e.seller_id, e.amount, e.buyer_approved_at
        FROM escrow e
        LEFT JOIN wallet_transactions wt 
            ON wt.escrow_id = e.id AND wt.type = 'credit'
        WHERE e.status = 'released' 
          AND wt.id IS NULL
        ORDER BY e.id ASC
        LIMIT 200
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Handle AJAX backfill run
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run'])) {
    header('Content-Type: application/json');
    $eligible = getEligibleEscrows($pdo);

    $results = [
        'total' => count($eligible),
        'credited' => 0,
        'skipped' => 0,
        'errors' => []
    ];

    foreach ($eligible as $row) {
        // Double-check not already credited
        $check = $pdo->prepare("SELECT id FROM wallet_transactions WHERE escrow_id = ? AND type = 'credit' LIMIT 1");
        $check->execute([$row['escrow_id']]);
        if ($check->fetch()) {
            $results['skipped']++;
            continue;
        }

        try {
            $walletService->creditEarnings(
                (int)$row['seller_id'],
                (float)$row['amount'],
                (int)$row['escrow_id'],
                (int)$row['project_id'],
                'Backfill: earnings from completed project prior to wallet system'
            );
            $results['credited']++;
        } catch (Exception $e) {
            $results['errors'][] = [
                'escrow_id' => $row['escrow_id'],
                'message' => $e->getMessage()
            ];
        }
    }

    echo json_encode(['status' => 'success', 'results' => $results]);
    exit;
}

$eligible = getEligibleEscrows($pdo);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Wallet Backfill - Jacob</title>
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
                <li><a href="/dashboard/admin_escrows.php"><span>📋</span> <span>Escrows</span></a></li>
                <li><a href="/admin/disputes_list.php"><span>⚖️</span> <span>Disputes</span></a></li>
                <li><strong style="color: rgba(255,255,255,0.6); padding: 1rem; margin-top: 0.5rem; display: block; font-size: 0.85rem;">FINANCIAL</strong></li>
                <li><a href="/admin/financials.php"><span>💰</span> <span>Financials</span></a></li>
                <li><a href="/dashboard/admin_dashboard.php#withdrawals"><span>💸</span> <span>Withdrawals</span></a></li>
                <li><a href="/dashboard/admin_wallet_backfill.php" class="active"><span>🔄</span> <span>Wallet Backfill</span></a></li>
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
                        <input type="text" placeholder="Search admin tools...">
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
                <h2 class="section-title">🔄 Wallet Backfill</h2>

                <div style="background: white; padding: 1.5rem; border-radius: 1rem; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); margin-bottom: 1.5rem;">
                    <h3 style="margin-bottom: 1rem; font-weight: 600; color: var(--dark);">Eligible Escrows</h3>
                    <p style="font-size: 1.1em;">Found <strong><?php echo count($eligible); ?></strong> escrows ready to credit.</p>
                    <button id="run-btn" class="btn btn-primary">Run Backfill</button>
                    <div id="result" style="margin-top: 15px; display:none;"></div>
                </div>

                <?php if (!empty($eligible)): ?>
                    <div style="background: white; padding: 1.5rem; border-radius: 1rem; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);">
                        <h3 style="margin-bottom: 1rem; font-weight: 600; color: var(--dark);">Preview (first <?php echo min(10, count($eligible)); ?>)</h3>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Escrow</th>
                                        <th>Project</th>
                                        <th>Seller</th>
                                        <th>Amount</th>
                                        <th>Buyer Approved</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (array_slice($eligible, 0, 10) as $row): ?>
                                        <tr>
                                            <td>#<?php echo $row['escrow_id']; ?></td>
                                            <td>#<?php echo $row['project_id']; ?></td>
                                            <td>#<?php echo $row['seller_id']; ?></td>
                                            <td><strong>$<?php echo number_format($row['amount'], 2); ?></strong></td>
                                            <td><?php echo $row['buyer_approved_at'] ? date('M d, Y', strtotime($row['buyer_approved_at'])) : '-'; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>
            </section>
        </main>
    </div>

    <script>
        const btn = document.getElementById('run-btn');
        const resultDiv = document.getElementById('result');

        btn.addEventListener('click', async () => {
            btn.disabled = true;
            btn.textContent = 'Running...';
            resultDiv.style.display = 'none';

            try {
                const res = await fetch('admin_wallet_backfill.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: 'run=1'
                });
                const data = await res.json();

                resultDiv.style.display = 'block';
                if (data.status === 'success') {
                    const r = data.results;
                    resultDiv.className = 'alert alert-success';
                    resultDiv.innerHTML = `Credited: <strong>${r.credited}</strong>, Skipped: <strong>${r.skipped}</strong>, Total considered: <strong>${r.total}</strong>`;

                    setTimeout(() => window.location.reload(), 2000);
                } else {
                    resultDiv.className = 'alert alert-danger';
                    resultDiv.textContent = data.message || 'Backfill failed';
                }
            } catch (e) {
                resultDiv.style.display = 'block';
                resultDiv.className = 'alert alert-danger';
                resultDiv.textContent = 'Error: ' + e.message;
            } finally {
                btn.disabled = false;
                btn.textContent = 'Run Backfill';
            }
        });
    </script>

    <!-- Sidebar Navigation Script -->
    <script src="/assets/js/sidebar.js"></script>

    <?php include '../includes/footer.php'; ?>