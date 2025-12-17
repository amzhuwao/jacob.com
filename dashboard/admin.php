<?php
require_once '../includes/auth.php';
require_once '../config/database.php';

requireRole('admin');

$pageTitle = 'Admin Dashboard';
include '../includes/header.php';
?>

<div class="dashboard-container">
    <h1>Admin Dashboard</h1>
    <p>Welcome, <?php echo htmlspecialchars($_SESSION['name'] ?? 'Admin'); ?>!</p>

    <p><a href="admin_escrows.php">Manage Escrows</a></p>
</div>

<?php include '../includes/footer.php'; ?>