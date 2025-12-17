<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle ?? 'Welcome'; ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>

<body>
    <header>
        <nav style="padding: 10px; background: #f4f4f4; margin-bottom: 20px;">
            <?php if (isset($_SESSION['user_id'])): ?>
                <a href="/dashboard/<?php echo $_SESSION['role']; ?>.php">Dashboard</a> |

                <?php if ($_SESSION['role'] === 'buyer'): ?>
                    <a href="/dashboard/buyer_post_project.php">Post Project</a> |
                <?php endif; ?>

                <?php if ($_SESSION['role'] === 'seller'): ?>
                    <a href="/dashboard/seller.php">Browse Projects</a> |
                <?php endif; ?>

                <span>Welcome, <?php echo htmlspecialchars($_SESSION['name']); ?></span> |
                <a href="/auth/logout.php">Logout</a>
            <?php else: ?>
                <a href="/index.php">Home</a> |
                <a href="/auth/login.php">Login</a> |
                <a href="/auth/register.php">Register</a>
            <?php endif; ?>
        </nav>
    </header>
    <main>