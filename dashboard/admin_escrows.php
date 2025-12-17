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

    <?php if (empty($escrows)): ?>
        <p>No escrows yet.</p>
    <?php endif; ?>

    <?php foreach ($escrows as $escrow): ?>
        <div style="border:1px solid #ccc; padding:10px; margin-bottom:10px;">
            <strong>Project:</strong> <?= htmlspecialchars($escrow['title']) ?><br>
            <strong>Buyer:</strong> <?= htmlspecialchars($escrow['buyer_name']) ?><br>
            <strong>Seller:</strong> <?= htmlspecialchars($escrow['seller_name']) ?><br>
            <strong>Amount:</strong> $<?= $escrow['amount'] ?><br>
            <strong>Status:</strong> <?= $escrow['status'] ?><br>

            <?php if ($escrow['status'] === 'funded'): ?>
                <a href="admin_escrow_action.php?escrow_id=<?= $escrow['id'] ?>&action=release">Release</a> |
                <a href="admin_escrow_action.php?escrow_id=<?= $escrow['id'] ?>&action=hold">Hold</a>
            <?php elseif ($escrow['status'] === 'held'): ?>
                <a href="admin_escrow_action.php?escrow_id=<?= $escrow['id'] ?>&action=release">Release</a>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>

<?php include '../includes/footer.php'; ?>