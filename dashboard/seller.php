<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
require_once "../includes/auth.php";
require_once "../config/database.php";
require_once "../services/WalletService.php";

if ($_SESSION['role'] !== 'seller') {
    die("Access denied");
}

// Get seller's KPI data
$userId = $_SESSION['user_id'];
$success = '';
$errors = [];

// Get wallet balance
try {
    $walletService = new WalletService($pdo);
    $wallet = $walletService->getWallet($userId);
    $walletBalance = $wallet['balance'] ?? 0;
    $pendingBalance = $wallet['pending_balance'] ?? 0;
} catch (Exception $e) {
    error_log("Wallet error: " . $e->getMessage());
    $walletBalance = 0;
    $pendingBalance = 0;
}

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $fullName = $_POST['full_name'] ?? '';
    $tagline = $_POST['tagline'] ?? '';
    $bio = $_POST['bio'] ?? '';
    $skills = $_POST['skills'] ?? '';
    $availability = $_POST['availability'] ?? 'available';

    if (!empty($fullName)) {
        $updateStmt = $pdo->prepare(
            "UPDATE users SET 
                full_name = ?,
                tagline = ?,
                bio = ?,
                skills = ?,
                availability = ?
             WHERE id = ?"
        );
        $updateStmt->execute([$fullName, $tagline, $bio, $skills, $availability, $userId]);
        $_SESSION['name'] = $fullName;
        $success = "Profile updated successfully!";
    }
}

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (empty($currentPassword)) {
        $errors[] = 'Current password is required';
    }
    if (empty($newPassword)) {
        $errors[] = 'New password is required';
    } elseif (strlen($newPassword) < 6) {
        $errors[] = 'New password must be at least 6 characters';
    }
    if ($newPassword !== $confirmPassword) {
        $errors[] = 'Passwords do not match';
    }

    if (empty($errors)) {
        $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if (password_verify($currentPassword, $user['password'])) {
            $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);
            $updateStmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            $updateStmt->execute([$hashedPassword, $userId]);
            $success = "Password changed successfully!";
        } else {
            $errors[] = 'Current password is incorrect';
        }
    }
}

// Active projects
$activeStmt = $pdo->prepare(
    "SELECT COUNT(*) as count FROM bids WHERE seller_id = ? AND status = 'accepted'"
);
$activeStmt->execute([$userId]);
$activeProjects = $activeStmt->fetch()['count'] ?? 0;

// Total earnings (estimate from completed projects)
$earningsStmt = $pdo->prepare(
    "SELECT COALESCE(SUM(amount), 0) as total FROM escrow WHERE seller_id = ? AND status = 'released'"
);
$earningsStmt->execute([$userId]);
$totalEarnings = $earningsStmt->fetch()['total'] ?? 0;

// Response Rate - Calculate from bids (last 30 days)
$responseStmt = $pdo->prepare(
    "SELECT 
        ROUND(COALESCE(COUNT(CASE WHEN responded_at IS NOT NULL THEN 1 END), 0) / 
              NULLIF(COUNT(*), 0) * 100, 0) as response_rate
     FROM bids 
     WHERE seller_id = ? 
     AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
);
$responseStmt->execute([$userId]);
$responseRate = (int)($responseStmt->fetch()['response_rate'] ?? 0);

// Open bids (opportunities)
$opportunitiesStmt = $pdo->prepare(
    "SELECT p.*, u.full_name
     FROM projects p
     JOIN users u ON p.buyer_id = u.id
     WHERE p.status = 'open'
     ORDER BY p.created_at DESC
     LIMIT 6"
);
$opportunitiesStmt->execute([]);
$opportunities = $opportunitiesStmt->fetchAll();

// Active orders
$ordersStmt = $pdo->prepare(
    "SELECT p.*, b.amount, u.full_name, e.status as escrow_status, e.work_delivered_at, e.buyer_approved_at
     FROM projects p
     JOIN bids b ON p.id = b.project_id
     JOIN users u ON p.buyer_id = u.id
     LEFT JOIN escrow e ON p.id = e.project_id
     WHERE b.seller_id = ? AND p.status != 'completed'
     ORDER BY p.created_at DESC
     LIMIT 8"
);
$ordersStmt->execute([$userId]);
$activeOrders = $ordersStmt->fetchAll();

// Get seller profile data
$profileStmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$profileStmt->execute([$userId]);
$profile = $profileStmt->fetch();

// Get seller statistics for profile
$statsStmt = $pdo->prepare(
    "SELECT 
        COUNT(DISTINCT b.id) as total_projects,
        COALESCE(SUM(e.amount), 0) as total_earnings,
        AVG(CASE WHEN e.status = 'released' THEN e.amount END) as avg_project_value
     FROM bids b
     LEFT JOIN escrow e ON b.project_id = e.project_id AND e.seller_id = b.seller_id
     WHERE b.seller_id = ? AND b.status = 'accepted'"
);
$statsStmt->execute([$userId]);
$stats = $statsStmt->fetch();

// Get real reviews from database
$reviewsStmt = $pdo->prepare(
    "SELECT sr.*, u.full_name 
     FROM seller_reviews sr
     JOIN users u ON sr.buyer_id = u.id
     WHERE sr.seller_id = ?
     ORDER BY sr.created_at DESC
     LIMIT 10"
);
$reviewsStmt->execute([$userId]);
$reviews = $reviewsStmt->fetchAll();

// Calculate profile completion
$profileFields = [
    'full_name' => !empty($profile['full_name']),
    'email' => !empty($profile['email']),
    'bio' => !empty($profile['bio']),
    'skills' => !empty($profile['skills']),
    'portfolio' => false
];
$completedFields = count(array_filter($profileFields));
$totalFields = count($profileFields);
$profileCompletion = round(($completedFields / $totalFields) * 100);

// Get real profile views from users table
$profileViewsStmt = $pdo->prepare("SELECT profile_views FROM users WHERE id = ?");
$profileViewsStmt->execute([$userId]);
$profileViews = $profileViewsStmt->fetch()['profile_views'] ?? 0;

// Get real average response time
$avgTimeStmt = $pdo->prepare(
    "SELECT 
        ROUND(AVG(TIMESTAMPDIFF(MINUTE, created_at, responded_at)), 0) as avg_minutes
     FROM bids 
     WHERE seller_id = ? AND responded_at IS NOT NULL"
);
$avgTimeStmt->execute([$userId]);
$avgMinutes = $avgTimeStmt->fetch()['avg_minutes'] ?? 0;

// Convert minutes to readable format
if ($avgMinutes == 0) {
    $avgResponseTime = "No data yet";
} elseif ($avgMinutes < 60) {
    $avgResponseTime = round($avgMinutes) . " minutes";
} elseif ($avgMinutes < 1440) {
    $avgResponseTime = round($avgMinutes / 60, 1) . " hours";
} else {
    $avgResponseTime = round($avgMinutes / 1440, 1) . " days";
}

// Get real average rating and total reviews
$ratingStmt = $pdo->prepare(
    "SELECT 
        ROUND(AVG(rating), 1) as avg_rating, 
        COUNT(*) as total_reviews
     FROM seller_reviews 
     WHERE seller_id = ?"
);
$ratingStmt->execute([$userId]);
$ratingData = $ratingStmt->fetch();
$avgRating = $ratingData['avg_rating'] ?? 0;
$totalReviews = $ratingData['total_reviews'] ?? 0;
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Seller Dashboard - Jacob</title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="stylesheet" href="/assets/css/dashboard.css">
</head>

<body>

    <div class="dashboard-wrapper">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="sidebar-brand">
                <span>✨</span>
                <span>Jacob</span>
            </div>
            <ul class="sidebar-nav">
                <li><a href="#dashboard" onclick="showDashboardView()" class="active"><span>📊</span> <span>Dashboard</span></a></li>
                <li><a href="#opportunities" onclick="showDashboardView()"><span>💼</span> <span>Opportunities</span></a></li>
                <li><a href="#active" onclick="showDashboardView()"><span>⚡</span> <span>Active Orders</span></a></li>
                <li><a href="seller_wallet.php"><span>💰</span> <span>My Wallet</span></a></li>
                <li><a href="#earnings" onclick="showDashboardView()"><span>💸</span> <span>Earnings</span></a></li>
                <li><a href="/disputes/open_dispute.php"><span>⚖️</span> <span>Disputes</span></a></li>
                <li><a href="#profile" onclick="showProfileView()"><span>⭐</span> <span>Profile</span></a></li>
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
                    <button class="toggle-sidebar" onclick="toggleSidebar()">☰</button>
                    <div class="search-bar">
                        <input type="text" placeholder="Search projects, clients, or help... (Ctrl+K)">
                    </div>
                </div>
                <div class="header-right">
                    <div style="position: relative;">
                        <button style="background: none; border: none; font-size: 1.5rem; cursor: pointer; position: relative;">🔔
                            <span class="notification-badge">2</span>
                        </button>
                    </div>
                    <div class="user-profile" onclick="toggleUserMenu(event)">
                        <div class="avatar"><?php echo strtoupper(substr($_SESSION['name'], 0, 1)); ?></div>
                        <span><?php echo htmlspecialchars($_SESSION['name']); ?></span>
                    </div>
                </div>
            </div>

            <!-- KPI Section -->
            <section class="kpi-section dashboard-content">
                <div class="kpi-grid">
                    <div class="kpi-card success">
                        <div class="kpi-label">💼 Active Orders</div>
                        <div class="kpi-value"><?php echo $activeProjects; ?></div>
                        <div class="kpi-subtext">Projects in progress</div>
                    </div>

                    <div class="kpi-card primary" style="cursor: pointer;" onclick="window.location.href='seller_wallet.php'">
                        <div class="kpi-label">💰 Wallet Balance</div>
                        <div class="kpi-value">$<?php echo number_format($walletBalance, 2); ?></div>
                        <div class="kpi-subtext">
                            <?php if ($pendingBalance > 0): ?>
                                $<?php echo number_format($pendingBalance, 2); ?> pending
                            <?php else: ?>
                                Click to withdraw
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="kpi-card info">
                        <div class="kpi-label">💸 Total Earnings</div>
                        <div class="kpi-value">$<?php echo number_format($totalEarnings, 0); ?></div>
                        <div class="kpi-subtext">All time revenue</div>
                    </div>

                    <div class="kpi-card">
                        <div class="kpi-label">⭐ Response Rate</div>
                        <div class="kpi-value"><?php echo $responseRate; ?>%</div>
                        <div class="kpi-subtext">Last 30 days</div>
                    </div>
                </div>

                <!-- Earnings Chart -->
                <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 1.5rem; margin-top: 2rem;">
                    <div style="background: white; padding: 1.5rem; border-radius: 1rem; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);">
                        <h3 style="margin-bottom: 1rem; font-weight: 600; color: var(--dark);">📈 30-Day Earnings Trend</h3>
                        <div style="height: 200px; background: linear-gradient(to right, rgba(99, 102, 241, 0.1) 0%, rgba(99, 102, 241, 0.05) 100%); border-radius: 0.5rem; position: relative;">
                            <svg width="100%" height="100%" style="position: absolute; top: 0; left: 0;">
                                <polyline points="5,180 15,160 25,140 35,130 45,120 55,100 65,90 75,85 85,80 95,70" style="fill: none; stroke: rgb(99, 102, 241); stroke-width: 2;"></polyline>
                            </svg>
                        </div>
                    </div>

                    <div style="background: white; padding: 1.5rem; border-radius: 1rem; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);">
                        <h3 style="margin-bottom: 1.5rem; font-weight: 600; color: var(--dark);">🎯 Monthly Goal</h3>
                        <div class="goal-progress">
                            <svg class="progress-ring active" width="100" height="100">
                                <circle class="progress-ring-circle" stroke-width="8" fill="transparent" r="50" cx="50" cy="50" />
                            </svg>
                            <div class="progress-label">
                                <div class="progress-percent">75%</div>
                                <div style="font-size: 0.85rem; color: var(--gray);">$3750 / $5000</div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Opportunities Section -->
            <section class="section dashboard-content" id="opportunities">
                <h2 class="section-title">💡 Jobs Recommended For You</h2>
                <?php if (empty($opportunities)): ?>
                    <div class="empty-state">
                        <div class="empty-icon">🎯</div>
                        <div class="empty-title">No matching opportunities right now</div>
                        <div class="empty-description">New projects matching your skills will appear here</div>
                    </div>
                <?php else: ?>
                    <div class="cards-grid">
                        <?php foreach ($opportunities as $project): ?>
                            <div class="project-card">
                                <div class="project-header">
                                    <div>
                                        <div class="project-title"><?php echo htmlspecialchars(substr($project['title'], 0, 30)) . '...'; ?></div>
                                        <div class="project-client">👤 <?php echo htmlspecialchars($project['full_name']); ?></div>
                                    </div>
                                    <span class="project-status">NEW</span>
                                </div>

                                <p style="color: var(--gray); font-size: 0.95rem; margin-bottom: 1rem;">
                                    <?php echo htmlspecialchars(substr($project['description'], 0, 80)) . '...'; ?>
                                </p>

                                <div class="project-meta">
                                    <div class="project-meta-item">
                                        <span class="meta-label">Budget</span>
                                        <span class="meta-value">$<?php echo number_format($project['budget'], 0); ?></span>
                                    </div>
                                    <div class="project-meta-item">
                                        <span class="meta-label">Status</span>
                                        <span class="meta-value">Open</span>
                                    </div>
                                </div>

                                <div class="project-actions">
                                    <a href="/dashboard/project_view.php?id=<?php echo $project['id']; ?>" class="action-btn primary">View Details</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <!-- Active Orders Section -->
            <section class="section dashboard-content" id="active">
                <h2 class="section-title">⚡ Active Orders</h2>
                <?php if (empty($activeOrders)): ?>
                    <div class="empty-state">
                        <div class="empty-icon">📭</div>
                        <div class="empty-title">No active orders yet</div>
                        <div class="empty-description">Start bidding on projects to see them here</div>
                        <div class="checklist">
                            <div class="checklist-item">
                                <input type="checkbox" class="checklist-checkbox">
                                <span class="checklist-text">Browse available projects</span>
                            </div>
                            <div class="checklist-item">
                                <input type="checkbox" class="checklist-checkbox">
                                <span class="checklist-text">Submit your first bid</span>
                            </div>
                            <div class="checklist-item">
                                <input type="checkbox" class="checklist-checkbox">
                                <span class="checklist-text">Get accepted by a client</span>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="cards-grid">
                        <?php foreach ($activeOrders as $order): ?>
                            <div class="project-card">
                                <div class="project-header">
                                    <div>
                                        <div class="project-title"><?php echo htmlspecialchars(substr($order['title'], 0, 30)); ?></div>
                                        <div class="project-client">👤 <?php echo htmlspecialchars($order['full_name']); ?></div>
                                    </div>
                                    <span class="project-status in-progress"><?php echo ucfirst($order['status']); ?></span>
                                </div>

                                <div class="project-progress">
                                    <div class="progress-bar">
                                        <div class="progress-fill" style="width: 65%;"></div>
                                    </div>
                                    <div class="progress-text">65% Complete</div>
                                </div>

                                <div class="project-meta">
                                    <div class="project-meta-item">
                                        <span class="meta-label">Amount</span>
                                        <span class="meta-value">$<?php echo number_format($order['amount'], 0); ?></span>
                                    </div>
                                    <div class="project-meta-item">
                                        <span class="meta-label">Status</span>
                                        <span class="meta-value"><?php echo ucfirst($order['escrow_status'] ?? 'pending'); ?></span>
                                    </div>
                                </div>

                                <!-- Delivery Status for Seller -->
                                <?php if ($order['escrow_status'] === 'funded'): ?>
                                    <?php if ($order['buyer_approved_at']): ?>
                                        <div style="margin: 0.75rem 0; padding: 0.75rem; background: rgba(16, 185, 129, 0.15); border-left: 3px solid #10b981; border-radius: 0.5rem; font-size: 0.85rem; color: #059669;">
                                            ✓ Work Approved - Funds Releasing
                                        </div>
                                    <?php elseif ($order['work_delivered_at']): ?>
                                        <div style="margin: 0.75rem 0; padding: 0.75rem; background: rgba(245, 158, 11, 0.15); border-left: 3px solid #f59e0b; border-radius: 0.5rem; font-size: 0.85rem; color: #d97706;">
                                            ⏳ Awaiting Buyer Approval
                                        </div>
                                    <?php else: ?>
                                        <div style="margin: 0.75rem 0; padding: 0.75rem; background: rgba(59, 130, 246, 0.1); border-left: 3px solid #3b82f6; border-radius: 0.5rem; font-size: 0.85rem; color: #1e40af;">
                                            📦 Ready to Mark as Delivered
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>

                                <div class="project-actions">
                                    <button class="action-btn">💬 Message</button>
                                    <a href="/dashboard/project_view.php?id=<?php echo $order['id']; ?>" class="action-btn primary">View Order</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <!-- Profile Section -->
            <section class="section profile-content" id="profile" style="display: none;">
                <?php if (!empty($success)): ?>
                    <div style="background: var(--success); color: white; padding: 1rem; border-radius: 0.75rem; margin-bottom: 1.5rem;">
                        ✓ <?php echo htmlspecialchars($success); ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($errors)): ?>
                    <div style="background: var(--danger); color: white; padding: 1rem; border-radius: 0.75rem; margin-bottom: 1.5rem;">
                        <?php foreach ($errors as $error): ?>
                            <div>✗ <?php echo htmlspecialchars($error); ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <!-- Profile Header -->
                <div class="profile-header-card">
                    <div class="profile-cover">
                        <div class="cover-photo-overlay">📷 Change Cover Photo</div>
                    </div>

                    <div class="profile-identity-section">
                        <div class="profile-avatar-wrapper">
                            <div class="profile-avatar-large">
                                <?php echo strtoupper(substr($profile['full_name'], 0, 1)); ?>
                            </div>
                            <div class="avatar-camera-icon">📷</div>
                        </div>

                        <div class="profile-info-section">
                            <div class="profile-name-display">
                                <?php echo htmlspecialchars($profile['full_name']); ?>
                                <span class="edit-icon-inline" onclick="scrollToSettings()">✏️</span>
                            </div>
                            <div class="profile-tagline-display">
                                Professional Freelancer • Expert in Quality Delivery
                                <span class="edit-icon-inline" onclick="scrollToSettings()">✏️</span>
                            </div>
                            <div class="profile-badges-display">
                                <span class="badge badge-top-rated">⭐ Top Rated</span>
                                <span class="badge badge-available">🟢 Available Now</span>
                                <span class="badge badge-level">Level 2 Seller</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Profile Content Grid -->
                <div class="profile-content-grid">
                    <!-- Sidebar -->
                    <div class="profile-sidebar">
                        <!-- Profile Strength (Owner Only) -->
                        <div class="profile-sidebar-card">
                            <div class="profile-section-heading">📊 Profile Strength</div>
                            <div class="profile-strength-meter">
                                <div class="strength-bar-container">
                                    <div class="strength-bar-fill" style="width: <?php echo $profileCompletion; ?>%;"></div>
                                </div>
                                <div class="strength-text-display"><?php echo $profileCompletion; ?>% Complete</div>
                            </div>
                            <ul class="suggestions-list-display">
                                <li>📸 Add a professional profile photo</li>
                                <li>📝 Complete your bio section</li>
                                <li>🎨 Upload portfolio samples</li>
                                <li>🎓 Add certifications</li>
                            </ul>
                        </div>

                        <!-- Private Stats (Owner Only) -->
                        <div class="profile-sidebar-card">
                            <div class="profile-section-heading">💰 Your Performance</div>
                            <div class="stat-item-display">
                                <span class="stat-label-text">Total Earnings</span>
                                <span class="stat-value-text">$<?php echo number_format($stats['total_earnings'], 0); ?></span>
                            </div>
                            <div class="stat-item-display">
                                <span class="stat-label-text">Profile Views (30d)</span>
                                <span class="stat-value-text"><?php echo $profileViews; ?></span>
                            </div>
                            <div class="stat-item-display">
                                <span class="stat-label-text">Response Rate</span>
                                <span class="stat-value-text"><?php echo $responseRate; ?>%</span>
                            </div>
                            <div class="stat-item-display">
                                <span class="stat-label-text">Avg Response Time</span>
                                <span class="stat-value-text"><?php echo $avgResponseTime; ?></span>
                            </div>
                        </div>

                        <!-- Quick Actions -->
                        <div class="profile-sidebar-card">
                            <div class="profile-section-heading">⚡ Quick Actions</div>
                            <a href="#dashboard" class="action-btn primary" style="width: 100%; margin-bottom: 0.75rem; display: block; text-align: center;">
                                📊 Back to Dashboard
                            </a>
                            <button class="action-btn" style="width: 100%;" onclick="showProfileTab('services')">
                                ➕ Add New Service
                            </button>
                        </div>
                    </div>

                    <!-- Main Profile Content -->
                    <div class="profile-main-content">
                        <!-- Tabs -->
                        <div class="profile-tabs-container">
                            <button class="profile-tab profile-tab-active" onclick="showProfileTab('overview')">Overview</button>
                            <button class="profile-tab" onclick="showProfileTab('services')">Services (Gigs)</button>
                            <button class="profile-tab" onclick="showProfileTab('portfolio')">Portfolio</button>
                            <button class="profile-tab" onclick="showProfileTab('reviews')">Reviews</button>
                            <button class="profile-tab" onclick="showProfileTab('settings')">Settings</button>
                        </div>

                        <!-- Overview Tab -->
                        <div id="profile-tab-overview" class="profile-tab-content">
                            <h2 class="profile-section-heading">About Me</h2>
                            <p style="color: var(--gray); line-height: 1.7; margin-bottom: 2rem;">
                                <?php echo htmlspecialchars($profile['email']); ?> • Joined <?php echo date('F Y', strtotime($profile['created_at'])); ?>
                            </p>

                            <h3 style="margin-bottom: 1rem;">Skills & Expertise</h3>
                            <div class="skills-cloud-display">
                                <span class="skill-tag-display">Web Development <span class="skill-remove-btn">×</span></span>
                                <span class="skill-tag-display">UI/UX Design <span class="skill-remove-btn">×</span></span>
                                <span class="skill-tag-display">JavaScript <span class="skill-remove-btn">×</span></span>
                                <span class="skill-tag-display">React <span class="skill-remove-btn">×</span></span>
                                <span class="skill-tag-display">PHP <span class="skill-remove-btn">×</span></span>
                            </div>
                            <button class="action-btn" style="margin-top: 1rem;">➕ Add Skill</button>

                            <h3 style="margin: 2rem 0 1rem;">Public Stats (Visible to Buyers)</h3>
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                                <div class="public-stat-card">
                                    <div class="public-stat-value"><?php echo $stats['total_projects']; ?></div>
                                    <div class="public-stat-label">Projects Completed</div>
                                </div>
                                <div class="public-stat-card">
                                    <div class="public-stat-value"><?php echo $responseRate; ?>%</div>
                                    <div class="public-stat-label">Response Rate</div>
                                </div>
                                <div class="public-stat-card">
                                    <div class="public-stat-value">⭐⭐⭐⭐⭐</div>
                                    <div class="public-stat-label">5.0 Rating</div>
                                </div>
                            </div>
                        </div>

                        <!-- Services Tab -->
                        <div id="profile-tab-services" class="profile-tab-content" style="display: none;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
                                <h2 class="profile-section-heading" style="margin: 0;">My Services</h2>
                                <button class="action-btn primary">➕ Create New Service</button>
                            </div>

                            <div class="portfolio-grid-display">
                                <div class="portfolio-item-card">
                                    <div class="portfolio-overlay-display">
                                        <div style="color: white; text-align: center;">
                                            <div style="font-size: 1.5rem; margin-bottom: 0.5rem;">🎨</div>
                                            <div style="font-weight: 600;">Logo Design</div>
                                            <div style="font-size: 0.9rem; margin: 0.5rem 0;">Starting at $150</div>
                                            <button class="action-btn" style="background: white; color: var(--primary);">Edit Service</button>
                                        </div>
                                    </div>
                                </div>
                                <div class="portfolio-item-card portfolio-add-new">
                                    <div style="text-align: center; color: var(--gray);">
                                        <div style="font-size: 3rem;">➕</div>
                                        <div>Add New Service</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Portfolio Tab -->
                        <div id="profile-tab-portfolio" class="profile-tab-content" style="display: none;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
                                <h2 class="profile-section-heading" style="margin: 0;">My Portfolio</h2>
                                <button class="action-btn primary">📤 Upload Work</button>
                            </div>

                            <div class="portfolio-grid-display">
                                <div class="portfolio-item-card">
                                    <div class="portfolio-overlay-display">
                                        <button class="action-btn" style="background: white; color: var(--primary);">Manage</button>
                                    </div>
                                </div>
                                <div class="portfolio-item-card">
                                    <div class="portfolio-overlay-display">
                                        <button class="action-btn" style="background: white; color: var(--primary);">Manage</button>
                                    </div>
                                </div>
                                <div class="portfolio-item-card portfolio-add-new">
                                    <div style="text-align: center; color: var(--gray);">
                                        <div style="font-size: 3rem;">📤</div>
                                        <div>Upload New Work</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Reviews Tab -->
                        <div id="profile-tab-reviews" class="profile-tab-content" style="display: none;">
                            <h2 class="profile-section-heading">Client Reviews</h2>

                            <?php if (empty($reviews)): ?>
                                <div style="text-align: center; padding: 2rem; color: var(--gray);">
                                    <div style="font-size: 3rem; margin-bottom: 1rem;">📭</div>
                                    <p>No reviews yet. Complete projects to get your first review!</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($reviews as $review): ?>
                                    <div class="review-card-display">
                                        <div class="review-header-section">
                                            <div>
                                                <div class="review-author-name">
                                                    <?php echo htmlspecialchars($review['full_name']); ?>
                                                </div>
                                                <div class="review-rating-stars">
                                                    <?php echo str_repeat('⭐', $review['rating']); ?>
                                                </div>
                                            </div>
                                            <small style="color: var(--gray);">
                                                <?php echo date('M j, Y', strtotime($review['created_at'])); ?>
                                            </small>
                                        </div>
                                        <div class="review-text-content">
                                            <?php echo htmlspecialchars($review['review_text']); ?>
                                        </div>

                                        <?php if (!empty($review['reply_text'])): ?>
                                            <div style="background: var(--light); padding: 1rem; border-radius: 0.5rem; margin-top: 1rem; border-left: 3px solid var(--primary);">
                                                <div style="font-weight: 600; margin-bottom: 0.5rem;">Your Reply:</div>
                                                <div style="color: var(--gray);">
                                                    <?php echo htmlspecialchars($review['reply_text']); ?>
                                                </div>
                                                <small style="color: #999;">
                                                    <?php echo date('M j, Y', strtotime($review['replied_at'])); ?>
                                                </small>
                                            </div>
                                        <?php else: ?>
                                            <button class="action-btn primary" onclick="alert('Reply functionality coming soon')">
                                                💬 Reply to Review
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                        <!-- Settings Tab -->
                        <div id="profile-tab-settings" class="profile-tab-content" style="display: none;">
                            <h2 class="profile-section-heading">Account Settings</h2>

                            <!-- Profile Details Form -->
                            <form method="POST" style="margin-bottom: 3rem;">
                                <h3 style="margin-bottom: 1.5rem;">Personal Details</h3>

                                <div class="form-group-profile">
                                    <label class="form-label-profile">Full Name</label>
                                    <input type="text" name="full_name" class="form-input-profile" value="<?php echo htmlspecialchars($profile['full_name']); ?>" required>
                                </div>

                                <div class="form-group-profile">
                                    <label class="form-label-profile">Professional Tagline</label>
                                    <input type="text" name="tagline" class="form-input-profile" placeholder="e.g., Award-winning Designer with 8+ years experience" value="<?php echo htmlspecialchars($profile['tagline'] ?? ''); ?>">
                                </div>

                                <div class="form-group-profile">
                                    <label class="form-label-profile">Bio</label>
                                    <textarea name="bio" class="form-input-profile form-textarea-profile" placeholder="Tell buyers about your experience and expertise..."><?php echo htmlspecialchars($profile['bio'] ?? ''); ?></textarea>
                                </div>

                                <div class="form-group-profile">
                                    <label class="form-label-profile">Skills (comma-separated)</label>
                                    <input type="text" name="skills" class="form-input-profile" placeholder="e.g., PHP, JavaScript, React, Node.js" value="<?php echo htmlspecialchars($profile['skills'] ?? ''); ?>">
                                </div>

                                <div class="form-group-profile">
                                    <label class="form-label-profile">Availability Status</label>
                                    <select name="availability" class="form-input-profile">
                                        <option value="available" <?php echo ($profile['availability'] ?? '') === 'available' ? 'selected' : ''; ?>>🟢 Available Now</option>
                                        <option value="busy" <?php echo ($profile['availability'] ?? '') === 'busy' ? 'selected' : ''; ?>>🟡 Busy (Limited Availability)</option>
                                        <option value="away" <?php echo ($profile['availability'] ?? '') === 'away' ? 'selected' : ''; ?>>🔴 Out of Office</option>
                                    </select>
                                </div>

                                <button type="submit" name="update_profile" class="action-btn primary">
                                    💾 Save Profile Changes
                                </button>
                            </form>

                            <!-- Password Change Form -->
                            <form method="POST" style="border-top: 2px solid var(--gray-light); padding-top: 2rem;">
                                <h3 style="margin-bottom: 1.5rem;">Change Password</h3>

                                <div class="form-group-profile">
                                    <label class="form-label-profile">Current Password</label>
                                    <input type="password" name="current_password" class="form-input-profile" required>
                                </div>

                                <div class="form-group-profile">
                                    <label class="form-label-profile">New Password</label>
                                    <input type="password" name="new_password" class="form-input-profile" required>
                                </div>

                                <div class="form-group-profile">
                                    <label class="form-label-profile">Confirm New Password</label>
                                    <input type="password" name="confirm_password" class="form-input-profile" required>
                                </div>

                                <button type="submit" name="change_password" class="action-btn primary">
                                    🔒 Update Password
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </section>
        </main>
    </div>

    <style>
        /* Profile Section Styles */
        .profile-header-card {
            background: white;
            border-radius: 1.5rem;
            overflow: hidden;
            box-shadow: var(--shadow-md);
            margin-bottom: 2rem;
        }

        .profile-cover {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            height: 200px;
            position: relative;
        }

        .cover-photo-overlay {
            position: absolute;
            top: 1rem;
            right: 1rem;
            background: rgba(0, 0, 0, 0.5);
            color: white;
            padding: 0.75rem 1.5rem;
            border-radius: 50px;
            cursor: pointer;
            transition: var(--transition);
            font-size: 0.9rem;
        }

        .cover-photo-overlay:hover {
            background: rgba(0, 0, 0, 0.7);
        }

        .profile-identity-section {
            padding: 0 2rem 2rem 2rem;
            margin-top: -60px;
            display: flex;
            gap: 2rem;
            align-items: flex-end;
        }

        .profile-avatar-wrapper {
            position: relative;
            cursor: pointer;
        }

        .profile-avatar-large {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 3rem;
            font-weight: 700;
            border: 5px solid white;
            box-shadow: var(--shadow-xl);
        }

        .avatar-camera-icon {
            position: absolute;
            bottom: 5px;
            right: 5px;
            width: 35px;
            height: 35px;
            background: var(--primary);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1rem;
            border: 3px solid white;
            cursor: pointer;
            transition: var(--transition);
        }

        .avatar-camera-icon:hover {
            background: var(--primary-dark);
            transform: scale(1.1);
        }

        .profile-info-section {
            flex: 1;
            padding-bottom: 0.5rem;
        }

        .profile-name-display {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .edit-icon-inline {
            cursor: pointer;
            opacity: 0.6;
            transition: var(--transition);
            font-size: 1rem;
        }

        .edit-icon-inline:hover {
            opacity: 1;
        }

        .profile-tagline-display {
            font-size: 1.1rem;
            color: var(--gray);
            margin-bottom: 0.75rem;
        }

        .profile-badges-display {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
        }

        .badge-top-rated {
            background: #ffd700;
            color: #000;
        }

        .badge-available {
            background: var(--success);
            color: white;
        }

        .badge-level {
            background: rgba(99, 102, 241, 0.1);
            color: var(--primary);
            border: 2px solid var(--primary);
        }

        .profile-content-grid {
            display: grid;
            grid-template-columns: 320px 1fr;
            gap: 2rem;
        }

        .profile-sidebar {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .profile-sidebar-card {
            background: white;
            padding: 1.5rem;
            border-radius: 1rem;
            box-shadow: var(--shadow-md);
        }

        .profile-section-heading {
            font-size: 1.15rem;
            font-weight: 700;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            color: var(--dark);
        }

        .profile-strength-meter {
            margin-bottom: 1rem;
        }

        .strength-bar-container {
            width: 100%;
            height: 10px;
            background: var(--gray-light);
            border-radius: 50px;
            overflow: hidden;
            margin-bottom: 0.5rem;
        }

        .strength-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--success), var(--primary));
            border-radius: 50px;
            transition: var(--transition);
        }

        .strength-text-display {
            font-size: 0.9rem;
            color: var(--gray);
            font-weight: 600;
        }

        .suggestions-list-display {
            list-style: none;
            margin-top: 1rem;
            padding: 0;
        }

        .suggestions-list-display li {
            padding: 0.75rem;
            background: var(--light);
            border-radius: 0.5rem;
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
            color: var(--dark);
        }

        .stat-item-display {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--gray-light);
        }

        .stat-item-display:last-child {
            border-bottom: none;
        }

        .stat-label-text {
            color: var(--gray);
            font-size: 0.9rem;
        }

        .stat-value-text {
            font-weight: 700;
            font-size: 1rem;
            color: var(--dark);
        }

        .profile-main-content {
            background: white;
            padding: 2rem;
            border-radius: 1rem;
            box-shadow: var(--shadow-md);
        }

        .profile-tabs-container {
            display: flex;
            gap: 1rem;
            border-bottom: 2px solid var(--gray-light);
            margin-bottom: 2rem;
        }

        .profile-tab {
            padding: 0.75rem 1.5rem;
            border: none;
            background: none;
            cursor: pointer;
            font-weight: 600;
            color: var(--gray);
            border-bottom: 3px solid transparent;
            transition: var(--transition);
        }

        .profile-tab-active {
            color: var(--primary);
            border-bottom-color: var(--primary);
        }

        .profile-tab:hover {
            color: var(--primary);
        }

        .profile-tab-content {
            animation: fadeIn 0.3s ease;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .form-group-profile {
            margin-bottom: 1.5rem;
        }

        .form-label-profile {
            display: block;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--dark);
        }

        .form-input-profile {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 2px solid var(--gray-light);
            border-radius: 0.5rem;
            font-size: 1rem;
            transition: var(--transition);
        }

        .form-input-profile:focus {
            outline: none;
            border-color: var(--primary);
        }

        .form-textarea-profile {
            min-height: 120px;
            resize: vertical;
        }

        .skills-cloud-display {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
        }

        .skill-tag-display {
            padding: 0.5rem 1rem;
            background: var(--light);
            border: 2px solid var(--gray-light);
            border-radius: 50px;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: var(--transition);
        }

        .skill-tag-display:hover {
            border-color: var(--primary);
            background: rgba(99, 102, 241, 0.1);
        }

        .skill-remove-btn {
            cursor: pointer;
            color: var(--danger);
            font-weight: 700;
        }

        .portfolio-grid-display {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 1.5rem;
        }

        .portfolio-item-card {
            aspect-ratio: 16/10;
            background: var(--light);
            border-radius: 0.75rem;
            overflow: hidden;
            position: relative;
            cursor: pointer;
            transition: var(--transition);
        }

        .portfolio-item-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
        }

        .portfolio-add-new {
            border: 3px dashed var(--gray-light);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .portfolio-overlay-display {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.8);
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: var(--transition);
        }

        .portfolio-item-card:hover .portfolio-overlay-display {
            opacity: 1;
        }

        .review-card-display {
            padding: 1.5rem;
            background: var(--light);
            border-radius: 0.75rem;
            margin-bottom: 1rem;
        }

        .review-header-section {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }

        .review-author-name {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.25rem;
        }

        .review-rating-stars {
            color: #ffd700;
        }

        .review-text-content {
            color: var(--gray);
            line-height: 1.6;
            margin-bottom: 1rem;
        }

        .public-stat-card {
            padding: 1.5rem;
            background: var(--light);
            border-radius: 0.75rem;
            text-align: center;
        }

        .public-stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 0.5rem;
        }

        .public-stat-label {
            color: var(--gray);
            font-size: 0.9rem;
        }

        @media (max-width: 1024px) {
            .profile-content-grid {
                grid-template-columns: 1fr;
            }

            .profile-identity-section {
                flex-direction: column;
                align-items: center;
                text-align: center;
            }
        }
    </style>

    <script>
        function toggleSidebar() {
            document.querySelector('.sidebar').classList.toggle('collapsed');
            document.querySelector('.main-content').classList.toggle('expanded');
        }

        function toggleUserMenu(e) {
            e.stopPropagation();
            alert('User menu - can add dropdown here');
        }

        // Keyboard shortcut for search (Cmd+K / Ctrl+K)
        document.addEventListener('keydown', (e) => {
            if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
                e.preventDefault();
                document.querySelector('.search-bar input').focus();
            }
        });

        // Show dashboard view
        function showDashboardView() {
            document.querySelectorAll('.dashboard-content').forEach(el => {
                el.style.display = 'block';
            });
            document.querySelectorAll('.profile-content').forEach(el => {
                el.style.display = 'none';
            });

            // Update active nav
            document.querySelectorAll('.sidebar-nav a').forEach(link => {
                link.classList.remove('active');
            });
        }

        // Show profile view
        function showProfileView() {
            event.preventDefault();
            document.querySelectorAll('.dashboard-content').forEach(el => {
                el.style.display = 'none';
            });
            document.querySelectorAll('.profile-content').forEach(el => {
                el.style.display = 'block';
            });

            // Update active nav
            document.querySelectorAll('.sidebar-nav a').forEach(link => {
                link.classList.remove('active');
            });
            document.querySelector('.sidebar-nav a[href="#profile"]').classList.add('active');

            // Scroll to top
            window.scrollTo({
                top: 0,
                behavior: 'smooth'
            });
        }

        // Profile tab switching
        function showProfileTab(tabName) {
            // Hide all tabs
            document.querySelectorAll('.profile-tab-content').forEach(tab => {
                tab.style.display = 'none';
            });

            // Remove active class from all tab buttons
            document.querySelectorAll('.profile-tab').forEach(btn => {
                btn.classList.remove('profile-tab-active');
            });

            // Show selected tab
            const selectedTab = document.getElementById('profile-tab-' + tabName);
            if (selectedTab) {
                selectedTab.style.display = 'block';
            }

            // Add active class to clicked button
            event.target.classList.add('profile-tab-active');
        }

        // Scroll to settings tab
        function scrollToSettings() {
            showProfileView();
            setTimeout(() => {
                showProfileTab('settings');
                // Manually activate the settings tab button
                document.querySelectorAll('.profile-tab').forEach(btn => {
                    btn.classList.remove('profile-tab-active');
                });
                document.querySelectorAll('.profile-tab')[4].classList.add('profile-tab-active');
            }, 300);
        }

        // Smooth scroll for anchor links within dashboard
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function(e) {
                const href = this.getAttribute('href');
                if (href !== '#profile' && href !== '#dashboard') {
                    const target = document.querySelector(href);
                    if (target && target.style.display !== 'none') {
                        e.preventDefault();
                        target.scrollIntoView({
                            behavior: 'smooth',
                            block: 'start'
                        });
                    }
                }
            });
        });
    </script>

</body>

</html>