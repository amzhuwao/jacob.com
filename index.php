<?php
require_once 'includes/auth.php';

$pageTitle = 'Home';
include 'includes/header.php';
?>

<div class="home-container">
    <h1>Welcome to Our Platform</h1>
    
    <?php if (isLoggedIn()): ?>
        <p>Hello, <?php echo htmlspecialchars($_SESSION['name'] ?? 'User'); ?>!</p>
        <a href="/dashboard/<?php echo getUserRole(); ?>.php">Go to Dashboard</a>
        <a href="/auth/logout.php">Logout</a>
    <?php else: ?>
        <p>Please login or register to continue.</p>
        <a href="/auth/login.php">Login</a>
        <a href="/auth/register.php">Register</a>
    <?php endif; ?>
</div>

<?php include 'includes/footer.php'; ?>
