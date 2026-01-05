<?php
require_once 'includes/auth.php';

$pageTitle = 'Home';

// Don't include header for landing page, we'll create custom layout
?>
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

    <title>Jacob - Freelance Marketplace with Secure Escrow</title>

    <!-- PWA Manifest -->
    <link rel="manifest" href="/manifest.json">

    <!-- Icons for iOS -->
    <link rel="apple-touch-icon" href="/assets/images/icons/icon-152x152.png">
    <link rel="apple-touch-icon" sizes="192x192" href="/assets/images/icons/icon-192x192.png">
    <link rel="apple-touch-icon" sizes="512x512" href="/assets/images/icons/icon-512x512.png">

    <!-- Favicon -->
    <link rel="icon" type="image/png" sizes="192x192" href="/assets/images/icons/icon-192x192.png">

    <!-- Stylesheet -->
    <link rel="stylesheet" href="/assets/css/style.css">

    <!-- PWA JavaScript -->
    <script src="/assets/js/app.js" defer></script>
</head>

<body>

    <!-- Header -->
    <header>
        <nav>
            <div style="font-size: 1.5rem; font-weight: 700; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;">
                ✨ Jacob
            </div>
            <div style="display: flex; gap: 1rem; align-items: center; flex-wrap: wrap;">
                <?php if (isLoggedIn()): ?>
                    <a href="/dashboard/<?php echo getUserRole(); ?>.php">Dashboard</a>
                    <span style="color: var(--gray);">Welcome, <?php echo htmlspecialchars($_SESSION['name'] ?? 'User'); ?>!</span>
                    <a href="/auth/logout.php" class="btn btn-primary" style="padding: 0.5rem 1.5rem; font-size: 0.95rem;">Logout</a>
                <?php else: ?>
                    <a href="#features">Features</a>
                    <a href="#how-it-works">How it Works</a>
                    <a href="/auth/login.php">Login</a>
                    <a href="/auth/register.php" class="btn btn-primary" style="padding: 0.5rem 1.5rem; font-size: 0.95rem;">Get Started</a>
                <?php endif; ?>
            </div>
        </nav>
    </header>

    <?php if (isLoggedIn()): ?>
        <!-- Logged In User View -->
        <section class="hero">
            <div class="hero-content">
                <h1>Welcome Back, <?php echo htmlspecialchars($_SESSION['name'] ?? 'User'); ?>! 👋</h1>
                <p class="subtitle">Ready to continue your freelance journey?</p>
                <div class="cta-buttons">
                    <a href="/dashboard/<?php echo getUserRole(); ?>.php" class="btn btn-primary">Go to Dashboard</a>
                    <?php if (getUserRole() === 'buyer'): ?>
                        <a href="/dashboard/buyer_post_project.php" class="btn btn-secondary">Post a Project</a>
                    <?php elseif (getUserRole() === 'seller'): ?>
                        <a href="/dashboard/seller.php" class="btn btn-secondary">Browse Projects</a>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    <?php else: ?>
        <!-- Hero Section -->
        <section class="hero">
            <div class="hero-content">
                <h1>Find Top Freelancers<br>With Secure Escrow</h1>
                <p class="subtitle">The trusted marketplace where clients and freelancers connect with complete payment protection</p>
                <div class="cta-buttons">
                    <a href="/auth/register.php" class="btn btn-primary">Get Started Free</a>
                    <a href="#features" class="btn btn-secondary">Learn More</a>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <!-- Features Section -->
    <section class="features" id="features">
        <h2 class="section-title">Why Choose Jacob?</h2>
        <p class="section-subtitle">Everything you need for secure and successful project collaboration</p>

        <div class="features-grid">
            <div class="feature-card">
                <div class="feature-icon">🔒</div>
                <h3>Secure Escrow</h3>
                <p>Your money is protected with our Stripe-powered escrow system. Funds are only released when work is completed and verified.</p>
            </div>

            <div class="feature-card">
                <div class="feature-icon">💼</div>
                <h3>Smart Bidding</h3>
                <p>Post projects and receive competitive bids from talented freelancers. Choose the best fit for your budget and timeline.</p>
            </div>

            <div class="feature-card">
                <div class="feature-icon">⚡</div>
                <h3>Fast Payments</h3>
                <p>Quick and secure payment processing through Stripe. Get paid immediately when projects are completed.</p>
            </div>
        </div>
    </section>

    <!-- How It Works Section -->
    <section class="how-it-works" id="how-it-works">
        <h2 class="section-title">How It Works</h2>
        <p class="section-subtitle">Simple steps to success</p>

        <div class="steps">
            <div class="step">
                <div class="step-number">1</div>
                <div class="step-content">
                    <h3>Post Your Project</h3>
                    <p>Clients describe their project needs, set a budget, and publish it to our marketplace for freelancers to see.</p>
                </div>
            </div>

            <div class="step">
                <div class="step-number">2</div>
                <div class="step-content">
                    <h3>Receive Bids</h3>
                    <p>Talented freelancers review your project and submit competitive proposals with their pricing and approach.</p>
                </div>
            </div>

            <div class="step">
                <div class="step-number">3</div>
                <div class="step-content">
                    <h3>Fund Escrow</h3>
                    <p>Accept the best bid and securely fund the escrow through Stripe. Your money is protected until work is delivered.</p>
                </div>
            </div>

            <div class="step">
                <div class="step-number">4</div>
                <div class="step-content">
                    <h3>Get Results</h3>
                    <p>Freelancer delivers the work, admin verifies completion, and funds are released. Everyone's protected!</p>
                </div>
            </div>
        </div>
    </section>

    <!-- CTA Section -->
    <section class="cta-section">
        <h2>Ready to Start Your Next Project?</h2>
        <p>Join thousands of clients and freelancers working together securely</p>
        <div class="cta-buttons">
            <?php if (isLoggedIn()): ?>
                <a href="/dashboard/<?php echo getUserRole(); ?>.php" class="btn btn-primary">Go to Dashboard</a>
            <?php else: ?>
                <a href="/auth/register.php" class="btn btn-primary">Sign Up Now</a>
                <a href="/auth/login.php" class="btn btn-secondary">Login</a>
            <?php endif; ?>
        </div>
    </section>

    <!-- Footer -->
    <footer>
        <p>&copy; <?php echo date('Y'); ?> Jacob. All rights reserved. Built with ❤️ for freelancers.</p>
    </footer>

</body>

</html>