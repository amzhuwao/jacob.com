<?php
require_once "../includes/auth.php";
require_once "../config/database.php";
require_once "../services/WalletService.php";

if ($_SESSION['role'] !== 'seller') {
    die("Access denied");
}

$userId = $_SESSION['user_id'];
$walletService = new WalletService($pdo);

// Get wallet info (do not zero-out balance if transactions fail)
try {
    $wallet = $walletService->getWallet($userId);
} catch (Exception $e) {
    error_log("Wallet fetch error: " . $e->getMessage());
    $wallet = ['balance' => 0, 'pending_balance' => 0];
}

// Get transaction history separately
try {
    $transactions = $walletService->getTransactions($userId, 50);
} catch (Exception $e) {
    error_log("Wallet transactions error: " . $e->getMessage());
    $transactions = [];
}

// Get pending withdrawal requests
try {
    $withdrawalStmt = $pdo->prepare(
        "SELECT * FROM withdrawal_requests 
         WHERE user_id = ? 
         ORDER BY requested_at DESC 
         LIMIT 10"
    );
    $withdrawalStmt->execute([$userId]);
    $withdrawals = $withdrawalStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Withdrawal query error: " . $e->getMessage());
    $withdrawals = [];
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Wallet - Jacob</title>
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
                <span>Jacob</span>
            </div>
            <ul class="sidebar-nav">
                <li><a href="/dashboard/seller.php#dashboard"><span>📊</span> <span>Dashboard</span></a></li>
                <li><a href="/dashboard/seller.php#opportunities"><span>💼</span> <span>Opportunities</span></a></li>
                <li><a href="/dashboard/seller.php#active"><span>⚡</span> <span>Active Orders</span></a></li>
                <li><a href="/dashboard/seller_wallet.php" class="active"><span>💰</span> <span>My Wallet</span></a></li>
                <li><a href="/dashboard/seller.php#earnings"><span>💸</span> <span>Earnings</span></a></li>
                <li><a href="/disputes/open_dispute.php"><span>⚖️</span> <span>Disputes</span></a></li>
                <li><a href="/dashboard/seller.php#profile"><span>⭐</span> <span>Profile</span></a></li>
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
                        <input type="text" placeholder="Search projects, clients, or help... (Ctrl+K)">
                    </div>
                </div>
                <div class="header-right">
                    <div style="position: relative;">
                        <button style="background: none; border: none; font-size: 1.5rem; cursor: pointer; position: relative;">🔔
                            <span class="notification-badge">0</span>
                        </button>
                    </div>
                    <div class="user-profile" onclick="toggleUserMenu(event)">
                        <div class="avatar"><?php echo strtoupper(substr($_SESSION['name'], 0, 1)); ?></div>
                        <span><?php echo htmlspecialchars($_SESSION['name']); ?></span>
                    </div>
                </div>
            </div>

            <section class="section dashboard-content">
                <h2 class="section-title">💰 My Wallet</h2>

                <!-- Balance Cards -->
                <div class="kpi-grid" style="margin-bottom: 1.5rem;">
                    <div class="kpi-card success">
                        <div class="kpi-label">Available Balance</div>
                        <div class="kpi-value">$<?php echo number_format($wallet['balance'] ?? 0, 2); ?></div>
                        <div class="kpi-subtext">Ready to withdraw</div>
                    </div>
                    <div class="kpi-card warning">
                        <div class="kpi-label">Pending Balance</div>
                        <div class="kpi-value">$<?php echo number_format($wallet['pending_balance'] ?? 0, 2); ?></div>
                        <div class="kpi-subtext">In withdrawal processing</div>
                    </div>
                </div>

                <!-- Balance Cards -->
                <div class="row mb-4">
                    <div class="col-md-6">
                        <div class="card bg-success text-white">
                            <div class="card-body">
                                <h5 class="card-title">Available Balance</h5>
                                <h2 class="mb-0">$<?php echo number_format($wallet['balance'] ?? 0, 2); ?></h2>
                                <small>Ready to withdraw</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card bg-warning text-white">
                            <div class="card-body">
                                <h5 class="card-title">Pending Balance</h5>
                                <h2 class="mb-0">$<?php echo number_format($wallet['pending_balance'] ?? 0, 2); ?></h2>
                                <small>In withdrawal processing</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Withdrawal Request Form -->
                <div style="background: white; padding: 1.5rem; border-radius: 1rem; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); margin-bottom: 1.5rem;">
                    <h3 style="margin-bottom: 1rem; font-weight: 600; color: var(--dark);">Request Withdrawal</h3>
                    <div id="withdrawal-alert" style="display: none;"></div>

                    <form id="withdrawal-form">
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                            <div>
                                <label for="amount" class="form-label">Amount (USD)</label>
                                <div class="input-group">
                                    <span class="input-group-text">$</span>
                                    <input type="number" class="form-control" id="amount" name="amount" min="10" max="<?php echo $wallet['balance'] ?? 0; ?>" step="0.01" required placeholder="Minimum $10.00">
                                </div>
                                <small class="text-muted">Minimum withdrawal: $10.00 | Available: $<?php echo number_format($wallet['balance'] ?? 0, 2); ?></small>
                            </div>
                            <div style="display: flex; align-items: flex-end;">
                                <button type="submit" class="btn btn-primary w-100" id="withdraw-btn">Request Withdrawal</button>
                            </div>
                        </div>
                    </form>

                    <div class="alert alert-info mt-3 mb-0">
                        <strong>Note:</strong> Withdrawals are processed within 1-3 business days. Funds will be transferred to your connected Stripe account.
                    </div>
                </div>

                <!-- Pending Withdrawals -->
                <?php if (!empty($withdrawals)): ?>
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="mb-0">Withdrawal Requests</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Amount</th>
                                            <th>Status</th>
                                            <th>Processed</th>
                                            <th>Details</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($withdrawals as $withdrawal): ?>
                                            <tr>
                                                <td><?php echo date('M d, Y', strtotime($withdrawal['requested_at'])); ?></td>
                                                <td>$<?php echo number_format($withdrawal['amount'], 2); ?></td>
                                                <td>
                                                    <?php
                                                    $statusClass = [
                                                        'pending' => 'warning',
                                                        'processing' => 'info',
                                                        'completed' => 'success',
                                                        'failed' => 'danger'
                                                    ];
                                                    $class = $statusClass[$withdrawal['status']] ?? 'secondary';
                                                    ?>
                                                    <span class="badge bg-<?php echo $class; ?>">
                                                        <?php echo ucfirst($withdrawal['status']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php
                                                    echo $withdrawal['processed_at']
                                                        ? date('M d, Y', strtotime($withdrawal['processed_at']))
                                                        : '-';
                                                    ?>
                                                </td>
                                                <td>
                                                    <?php if ($withdrawal['stripe_payout_id']): ?>
                                                        <small class="text-muted"><?php echo $withdrawal['stripe_payout_id']; ?></small>
                                                    <?php elseif ($withdrawal['error_message']): ?>
                                                        <small class="text-danger"><?php echo htmlspecialchars($withdrawal['error_message']); ?></small>
                                                    <?php else: ?>
                                                        -
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Transaction History -->
                <div style="background: white; padding: 1.5rem; border-radius: 1rem; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);">
                    <h3 style="margin-bottom: 1rem; font-weight: 600; color: var(--dark);">Transaction History</h3>
                    <?php if (empty($transactions)): ?>
                        <p class="text-muted mb-0">No transactions yet. Complete projects to start earning!</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Type</th>
                                        <th>Description</th>
                                        <th>Amount</th>
                                        <th>Balance After</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($transactions as $tx): ?>
                                        <tr>
                                            <td><?php echo date('M d, Y H:i', strtotime($tx['created_at'])); ?></td>
                                            <td>
                                                <?php
                                                $typeIcons = [
                                                    'credit' => '➕',
                                                    'debit' => '➖',
                                                    'withdrawal' => '💸',
                                                    'refund' => '🔄'
                                                ];
                                                echo $typeIcons[$tx['type']] ?? '•';
                                                ?>
                                                <span class="badge bg-<?php echo $tx['type'] === 'credit' ? 'success' : 'info'; ?>">
                                                    <?php echo ucfirst($tx['type']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php echo htmlspecialchars($tx['description'] ?? 'Transaction'); ?>
                                                <?php if ($tx['project_id']): ?>
                                                    <br><small class="text-muted">Project #<?php echo $tx['project_id']; ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td class="<?php echo $tx['type'] === 'credit' ? 'text-success' : 'text-danger'; ?>">
                                                <?php echo $tx['type'] === 'credit' ? '+' : '-'; ?>$<?php echo number_format($tx['amount'], 2); ?>
                                            </td>
                                            <td>$<?php echo number_format($tx['balance_after'], 2); ?></td>
                                            <td>
                                                <span class="badge bg-<?php echo $tx['status'] === 'completed' ? 'success' : 'warning'; ?>">
                                                    <?php echo ucfirst($tx['status']); ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        </main>
    </div>

    <script>
        document.getElementById('withdrawal-form').addEventListener('submit', async function(e) {
            e.preventDefault();

            const alertDiv = document.getElementById('withdrawal-alert');
            const submitBtn = document.getElementById('withdraw-btn');
            const amountInput = document.getElementById('amount');
            const amount = parseFloat(amountInput.value);

            // Client-side validation
            if (amount < 10) {
                alertDiv.className = 'alert alert-danger';
                alertDiv.textContent = 'Minimum withdrawal amount is $10.00';
                alertDiv.style.display = 'block';
                return;
            }

            if (amount > <?php echo $wallet['balance'] ?? 0; ?>) {
                alertDiv.className = 'alert alert-danger';
                alertDiv.textContent = 'Insufficient balance';
                alertDiv.style.display = 'block';
                return;
            }

            submitBtn.disabled = true;
            submitBtn.textContent = 'Processing...';

            try {
                const response = await fetch('request_withdrawal.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'amount=' + amount
                });

                const contentType = response.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) {
                    throw new Error('Invalid response format');
                }

                const data = await response.json();

                if (data.status === 'success') {
                    alertDiv.className = 'alert alert-success';
                    alertDiv.textContent = data.message;
                    alertDiv.style.display = 'block';
                    amountInput.value = '';

                    // Reload page after 2 seconds to show updated balance
                    setTimeout(() => window.location.reload(), 2000);
                } else {
                    alertDiv.className = 'alert alert-danger';
                    alertDiv.textContent = data.message || 'Withdrawal request failed';
                    alertDiv.style.display = 'block';
                }
            } catch (error) {
                console.error('Withdrawal error:', error);
                alertDiv.className = 'alert alert-danger';
                alertDiv.textContent = 'Error processing withdrawal: ' + error.message;
                alertDiv.style.display = 'block';
            } finally {
                submitBtn.disabled = false;
                submitBtn.textContent = 'Request Withdrawal';
            }
        });
    </script>

    <!-- Sidebar Navigation Script -->
    <script src="/assets/js/sidebar.js"></script>

</body>

</html>