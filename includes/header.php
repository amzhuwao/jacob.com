<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="description" content="Connect with skilled freelancers and find quality projects. Secure escrow payments and transparent dispute resolution.">
    <meta name="theme-color" content="#667eea">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="Jacob">

    <title><?php echo $pageTitle ?? 'Welcome'; ?> - Jacob Marketplace</title>

    <!-- PWA Manifest -->
    <link rel="manifest" href="/manifest.json">

    <!-- Icons for iOS -->
    <link rel="apple-touch-icon" href="/assets/images/icons/icon-152x152.png">
    <link rel="apple-touch-icon" sizes="72x72" href="/assets/images/icons/icon-72x72.png">
    <link rel="apple-touch-icon" sizes="96x96" href="/assets/images/icons/icon-96x96.png">
    <link rel="apple-touch-icon" sizes="128x128" href="/assets/images/icons/icon-128x128.png">
    <link rel="apple-touch-icon" sizes="144x144" href="/assets/images/icons/icon-144x144.png">
    <link rel="apple-touch-icon" sizes="152x152" href="/assets/images/icons/icon-152x152.png">
    <link rel="apple-touch-icon" sizes="192x192" href="/assets/images/icons/icon-192x192.png">
    <link rel="apple-touch-icon" sizes="384x384" href="/assets/images/icons/icon-384x384.png">
    <link rel="apple-touch-icon" sizes="512x512" href="/assets/images/icons/icon-512x512.png">

    <!-- Favicon -->
    <link rel="icon" type="image/png" sizes="192x192" href="/assets/images/icons/icon-192x192.png">
    <link rel="icon" type="image/png" sizes="512x512" href="/assets/images/icons/icon-512x512.png">

    <!-- Stylesheet -->
    <link rel="stylesheet" href="/assets/css/style.css">

    <!-- PWA JavaScript -->
    <script src="/assets/js/app.js" defer></script>
</head>

<body>
    <header>
        <nav>
            <div style="font-size: 1.5rem; font-weight: 700; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;">
                <a href="/index.php" style="text-decoration: none; color: inherit;">✨ Jacob</a>
            </div>
            <div style="display: flex; gap: 1rem; align-items: center; flex-wrap: wrap;">
                <?php if (isset($_SESSION['user_id'])): ?>
                    <a href="/dashboard/<?php echo $_SESSION['role']; ?>.php">Dashboard</a>

                    <?php if ($_SESSION['role'] === 'buyer'): ?>
                        <a href="/dashboard/buyer_post_project.php">Post Project</a>
                    <?php endif; ?>

                    <?php if ($_SESSION['role'] === 'seller'): ?>
                        <a href="/dashboard/seller.php">Browse Projects</a>
                    <?php endif; ?>

                    <span style="color: var(--gray);">Welcome, <?php echo htmlspecialchars($_SESSION['name']); ?>!</span>
                    <a href="/auth/logout.php" class="btn btn-primary" style="padding: 0.5rem 1.5rem; font-size: 0.95rem;">Logout</a>
                <?php else: ?>
                    <a href="/index.php">Home</a>
                    <a href="/auth/login.php">Login</a>
                    <a href="/auth/register.php" class="btn btn-primary" style="padding: 0.5rem 1.5rem; font-size: 0.95rem;">Get Started</a>
                <?php endif; ?>
            </div>
        </nav>
    </header>
    <main>