<?php
require_once '../includes/auth.php';
require_once '../config/database.php';

requireRole('admin');

$pageTitle = 'Admin Dashboard';
include '../includes/header.php';

// Get dispute statistics
$disputeStmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) as open_count
    FROM disputes
");
$disputeStmt->execute();
$disputeStats = $disputeStmt->fetch(PDO::FETCH_ASSOC);

// Get recent open disputes
$recentDisputesStmt = $pdo->prepare("
    SELECT d.*, e.amount, p.title as project_title
    FROM disputes d
    JOIN escrow e ON d.escrow_id = e.id
    JOIN projects p ON e.project_id = p.id
    WHERE d.status = 'open'
    ORDER BY d.opened_at DESC
    LIMIT 5
");
$recentDisputesStmt->execute();
$recentDisputes = $recentDisputesStmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="dashboard-container">
    <h1>Admin Dashboard</h1>
    <p>Welcome, <?php echo htmlspecialchars($_SESSION['name'] ?? 'Admin'); ?>!</p>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin: 20px 0;">
        <div style="padding: 20px; background: #f8f9fa; border-radius: 8px; border-left: 4px solid #007bff;">
            <h3>📋 Manage Escrows</h3>
            <p><a href="admin_escrows.php" style="color: #007bff; text-decoration: none;">View all escrows</a></p>
        </div>

        <div style="padding: 20px; background: #fff3cd; border-radius: 8px; border-left: 4px solid #ffc107;">
            <h3>⚖️ Active Disputes</h3>
            <p style="font-size: 2em; font-weight: bold; margin: 10px 0; color: #ff6b6b;"><?php echo $disputeStats['open_count']; ?></p>
            <p>
                <a href="/admin/disputes_list.php" style="color: #ffc107; text-decoration: none; margin-right: 10px;">📋 Full List</a><br>
                <a href="/disputes/index.php" style="color: #ffc107; text-decoration: none; font-size: 0.9em;">📊 Dashboard</a>
            </p>
        </div>

        <div style="padding: 20px; background: #d4edda; border-radius: 8px; border-left: 4px solid #28a745;">
            <h3>💰 Total Disputes</h3>
            <p style="font-size: 2em; font-weight: bold; margin: 10px 0; color: #28a745;"><?php echo $disputeStats['total']; ?></p>
            <p><a href="/disputes/index.php?status=all" style="color: #28a745; text-decoration: none;">View dashboard</a></p>
        </div>
    </div>

    <?php if (!empty($recentDisputes)): ?>
        <div style="margin-top: 30px; padding: 20px; background: #f8f9fa; border-radius: 8px;">
            <h2>⚠️ Recent Open Disputes</h2>
            <table style="width: 100%; border-collapse: collapse; margin-top: 15px;">
                <thead style="background-color: #e9ecef;">
                    <tr>
                        <th style="padding: 10px; text-align: left; border-bottom: 1px solid #dee2e6;">Dispute ID</th>
                        <th style="padding: 10px; text-align: left; border-bottom: 1px solid #dee2e6;">Project</th>
                        <th style="padding: 10px; text-align: left; border-bottom: 1px solid #dee2e6;">Amount</th>
                        <th style="padding: 10px; text-align: left; border-bottom: 1px solid #dee2e6;">Opened</th>
                        <th style="padding: 10px; text-align: left; border-bottom: 1px solid #dee2e6;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentDisputes as $dispute): ?>
                        <tr style="border-bottom: 1px solid #dee2e6;">
                            <td style="padding: 10px;">#<?php echo $dispute['id']; ?></td>
                            <td style="padding: 10px;"><?php echo htmlspecialchars($dispute['project_title']); ?></td>
                            <td style="padding: 10px;"><strong>$<?php echo number_format($dispute['amount'], 2); ?></strong></td>
                            <td style="padding: 10px;"><?php echo date('M d, Y', strtotime($dispute['opened_at'])); ?></td>
                            <td style="padding: 10px;">
                                <a href="/admin/dispute_review.php?id=<?php echo $dispute['id']; ?>" style="background: #007bff; color: white; padding: 5px 10px; border-radius: 4px; text-decoration: none; font-size: 0.9rem;">
                                    <i class="fas fa-eye"></i> Review
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php include '../includes/footer.php'; ?>