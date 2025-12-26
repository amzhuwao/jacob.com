<?php
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../services/AdminAuditService.php';
require_once '../services/PlatformSettingsService.php';

requireRole('admin');

$auditService = new AdminAuditService($pdo);
$settingsService = new PlatformSettingsService($pdo);

$message = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $key = $_POST['key'] ?? '';
    $value = $_POST['value'] ?? '';

    if ($key && $value !== '') {
        $oldValue = $settingsService->get($key);
        $settingsService->set($key, $value, $_SESSION['user_id']);

        $auditService->logAction(
            $_SESSION['user_id'],
            'update_platform_setting',
            'platform_settings',
            0,
            "Updated {$key}: {$oldValue} → {$value}",
            ['value' => $oldValue],
            ['value' => $value]
        );

        $message = "✅ Setting updated successfully";
    }
}

$allSettings = $settingsService->getAll();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - Jacob Admin</title>
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
                <li><a href="/admin/audit_logs.php"><span>📝</span> <span>Audit Logs</span></a></li>
                <li><a href="/admin/settings.php" class="active"><span>⚙️</span> <span>Settings</span></a></li>
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
                <h2 class="section-title">⚙️ Platform Settings</h2>

                <?php if ($message): ?>
                    <div style="background: #d4edda; color: #155724; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1rem;"><?php echo $message; ?></div>
                <?php endif; ?>

                <!-- Settings Form -->
                <div style="background: white; padding: 1.5rem; border-radius: 1rem; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);">
                    <h3 style="margin-top: 0; margin-bottom: 1.5rem;">💾 Configuration</h3>

                    <?php
                    // Group settings by category
                    $categories = [
                        'Commission & Fees' => ['commission_percentage', 'refund_fee_percentage'],
                        'Escrow Settings' => ['min_escrow_amount', 'max_transaction_amount', 'dispute_resolution_days', 'auto_release_days'],
                        'Seller Requirements' => ['kyc_required_for_seller', 'stripe_payout_threshold'],
                        'Platform' => ['maintenance_mode', 'supported_currencies'],
                        'External Services' => ['webhook_secret_stripe'],
                        'Legal & Policies' => ['tos_text', 'privacy_policy_text']
                    ];

                    foreach ($categories as $category => $keys):
                        if (empty(array_filter($keys, fn($k) => isset($allSettings[$k])))) {
                            continue;
                        }
                    ?>
                        <div style="margin-bottom: 2rem;">
                            <h4 style="color: #666; font-size: 0.95rem; margin-bottom: 1rem; text-transform: uppercase; letter-spacing: 0.5px;"><?php echo $category; ?></h4>

                            <?php foreach ($keys as $key): ?>
                                <?php if (isset($allSettings[$key])):
                                    $setting = $allSettings[$key];
                                ?>
                                    <form method="POST" style="margin-bottom: 1.5rem; padding-bottom: 1.5rem; border-bottom: 1px solid #eee;">
                                        <div class="row">
                                            <div class="col-md-6">
                                                <label class="form-label"><strong><?php echo htmlspecialchars($setting['description'] ?? $key); ?></strong></label>
                                                <small style="display: block; color: #999; margin-bottom: 0.5rem;">Key: <code><?php echo $key; ?></code> (<?php echo $setting['data_type']; ?>)</small>

                                                <?php if ($setting['data_type'] === 'boolean'): ?>
                                                    <select name="value" class="form-select">
                                                        <option value="0" <?php echo !$setting['value'] ? 'selected' : ''; ?>>No / Disabled</option>
                                                        <option value="1" <?php echo $setting['value'] ? 'selected' : ''; ?>>Yes / Enabled</option>
                                                    </select>
                                                <?php elseif ($setting['data_type'] === 'json'): ?>
                                                    <textarea name="value" class="form-control" style="font-family: monospace; height: 100px;"><?php echo htmlspecialchars(json_encode($setting['value'], JSON_PRETTY_PRINT)); ?></textarea>
                                                <?php elseif (in_array($key, ['tos_text', 'privacy_policy_text'])): ?>
                                                    <textarea name="value" class="form-control" style="height: 150px;"><?php echo htmlspecialchars($setting['value']); ?></textarea>
                                                <?php else: ?>
                                                    <input type="text" name="value" class="form-control" value="<?php echo htmlspecialchars($setting['value']); ?>">
                                                <?php endif; ?>
                                            </div>
                                            <div class="col-md-6" style="padding-top: 2rem; display: flex; flex-direction: column; justify-content: flex-end;">
                                                <small style="color: #999; margin-bottom: 0.5rem;">
                                                    Last updated: <?php echo date('M d, Y H:i', strtotime($setting['updated_at'])); ?>
                                                </small>
                                                <input type="hidden" name="key" value="<?php echo $key; ?>">
                                                <button type="submit" class="btn btn-primary">Save</button>
                                            </div>
                                        </div>
                                    </form>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        </main>
    </div>

</body>

</html>