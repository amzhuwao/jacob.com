<?php
require_once '../includes/auth.php';
require_once '../config/database.php';
requireRole('admin');

$pageTitle = 'Escrow Management';
include '../includes/header.php';

$stmt = $pdo->query(
    "SELECT e.*, p.title, u1.full_name AS buyer_name, u2.full_name AS seller_name
     FROM escrow e
     JOIN projects p ON e.project_id = p.id
     JOIN users u1 ON e.buyer_id = u1.id
     JOIN users u2 ON e.seller_id = u2.id
     ORDER BY e.created_at DESC"
);
$escrows = $stmt->fetchAll();
?>

<div class="dashboard-container">
    <h1>Escrow Management</h1>

    <?php
    // Display success messages
    if (isset($_GET['success'])) {
        if ($_GET['success'] === 'marked_disputed') {
            $escrowId = (int)($_GET['escrow_id'] ?? 0);
            echo '<div class="alert alert-success alert-dismissible fade show" role="alert">
                <strong>Success!</strong> Escrow #' . $escrowId . ' has been marked as disputed. 
                Buyer or seller can now open a formal dispute case.
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>';
        }
    }

    // Display error messages
    if (isset($_GET['error'])) {
        $errorMsg = '';
        switch ($_GET['error']) {
            case 'already_disputed':
                $errorMsg = 'This escrow is already in disputed status.';
                break;
            case 'invalid_status':
                $errorMsg = 'Escrow must be in "funded" status to mark as disputed.';
                break;
            case 'transition_failed':
                $errorMsg = 'Failed to mark as disputed: ' . htmlspecialchars($_GET['message'] ?? 'Unknown error');
                break;
            case 'invalid_request':
                $errorMsg = 'Invalid request parameters.';
                break;
            default:
                $errorMsg = 'An error occurred.';
        }
        if ($errorMsg) {
            echo '<div class="alert alert-danger alert-dismissible fade show" role="alert">
                <strong>Error!</strong> ' . $errorMsg . '
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>';
        }
    }
    ?>

    <?php if (empty($escrows)): ?>
        <p>No escrows yet.</p>
    <?php endif; ?>

    <?php foreach ($escrows as $escrow): ?>
        <div style="border:1px solid #ccc; padding:10px; margin-bottom:10px;">
            <strong>Project:</strong> <?= htmlspecialchars($escrow['title']) ?><br>
            <strong>Buyer:</strong> <?= htmlspecialchars($escrow['buyer_name']) ?><br>
            <strong>Seller:</strong> <?= htmlspecialchars($escrow['seller_name']) ?><br>
            <strong>Amount:</strong> $<?= $escrow['amount'] ?><br>
            <strong>Status:</strong> <span class="badge bg-<?php
                                                            $statusColors = [
                                                                'pending' => 'secondary',
                                                                'funded' => 'success',
                                                                'released' => 'primary',
                                                                'refunded' => 'info',
                                                                'disputed' => 'danger',
                                                                'held' => 'warning',
                                                                'canceled' => 'dark'
                                                            ];
                                                            echo $statusColors[$escrow['status']] ?? 'secondary';
                                                            ?>"><?= ucfirst($escrow['status']) ?></span><br>

            <div style="margin-top: 10px;">
                <?php if ($escrow['status'] === 'funded'): ?>
                    <a href="admin_escrow_action.php?escrow_id=<?= $escrow['id'] ?>&action=release" class="btn btn-sm btn-success">
                        <i class="fas fa-check"></i> Release
                    </a>
                    <a href="admin_escrow_action.php?escrow_id=<?= $escrow['id'] ?>&action=hold" class="btn btn-sm btn-warning">
                        <i class="fas fa-pause"></i> Hold
                    </a>
                    <a href="admin_mark_disputed.php?escrow_id=<?= $escrow['id'] ?>&action=mark_disputed" class="btn btn-sm btn-danger">
                        <i class="fas fa-exclamation-triangle"></i> Mark as Disputed
                    </a>
                <?php elseif ($escrow['status'] === 'held'): ?>
                    <a href="admin_escrow_action.php?escrow_id=<?= $escrow['id'] ?>&action=release" class="btn btn-sm btn-success">
                        <i class="fas fa-check"></i> Release
                    </a>
                <?php elseif ($escrow['status'] === 'disputed'): ?>
                    <a href="/disputes/index.php" class="btn btn-sm btn-info">
                        <i class="fas fa-gavel"></i> View Disputes
                    </a>
                    <a href="/admin/disputes_list.php" class="btn btn-sm btn-primary">
                        <i class="fas fa-shield-alt"></i> Admin Disputes Panel
                    </a>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<?php include '../includes/footer.php'; ?>