<?php
require_once "../includes/auth.php";
require_once "../config/database.php";

// Get seller ID from URL
$sellerId = $_GET['id'] ?? null;

if (!$sellerId || !is_numeric($sellerId)) {
    header("Location: /dashboard/" . ($_SESSION['role'] === 'buyer' ? 'buyer.php' : 'seller.php'));
    exit;
}

$viewerId = $_SESSION['user_id'] ?? null;

// Get seller profile data
$profileStmt = $pdo->prepare(
    "SELECT id, full_name, email, bio, tagline, skills, availability, created_at, profile_views
     FROM users 
     WHERE id = ? AND role = 'seller'"
);
$profileStmt->execute([$sellerId]);
$seller = $profileStmt->fetch();

if (!$seller) {
    header("Location: /dashboard/" . ($_SESSION['role'] === 'buyer' ? 'buyer.php' : 'seller.php'));
    exit;
}

// Track profile view (only if viewer is logged in and not viewing their own profile)
if ($viewerId && $viewerId != $sellerId) {
    // Insert into profile_views table (or update if already exists today)
    try {
        $viewStmt = $pdo->prepare(
            "INSERT INTO profile_views (profile_user_id, viewer_user_id, viewed_at)
             VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE viewed_at = NOW()"
        );
        $viewStmt->execute([$sellerId, $viewerId]);

        // Update profile views counter (increment only once per day per viewer)
        // We'll increment based on whether the insert actually happened
        if ($viewStmt->rowCount() > 0) {
            $updateStmt = $pdo->prepare(
                "UPDATE users SET profile_views = profile_views + 1 WHERE id = ?"
            );
            $updateStmt->execute([$sellerId]);
        }
    } catch (Exception $e) {
        // Silently fail - don't block page load
        error_log("Profile view tracking error: " . $e->getMessage());
    }
}

// Get seller statistics
$statsStmt = $pdo->prepare(
    "SELECT 
        COUNT(DISTINCT b.id) as total_projects,
        COALESCE(SUM(CASE WHEN e.status = 'released' THEN e.amount ELSE 0 END), 0) as total_earnings,
        AVG(CASE WHEN e.status = 'released' THEN e.amount END) as avg_project_value
     FROM bids b
     LEFT JOIN escrow e ON b.project_id = e.project_id AND e.seller_id = b.seller_id
     WHERE b.seller_id = ? AND b.status = 'accepted'"
);
$statsStmt->execute([$sellerId]);
$stats = $statsStmt->fetch();

// Response Rate - Calculate from bids (last 30 days)
$responseStmt = $pdo->prepare(
    "SELECT 
        ROUND(COALESCE(COUNT(CASE WHEN responded_at IS NOT NULL THEN 1 END), 0) / 
              NULLIF(COUNT(*), 0) * 100, 0) as response_rate
     FROM bids 
     WHERE seller_id = ? 
     AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
);
$responseStmt->execute([$sellerId]);
$responseRate = (int)($responseStmt->fetch()['response_rate'] ?? 0);

// Get real average response time
$avgTimeStmt = $pdo->prepare(
    "SELECT 
        ROUND(AVG(TIMESTAMPDIFF(MINUTE, created_at, responded_at)), 0) as avg_minutes
     FROM bids 
     WHERE seller_id = ? AND responded_at IS NOT NULL"
);
$avgTimeStmt->execute([$sellerId]);
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

// Get average rating and total reviews
$ratingStmt = $pdo->prepare(
    "SELECT 
        ROUND(AVG(rating), 1) as avg_rating, 
        COUNT(*) as total_reviews
     FROM seller_reviews 
     WHERE seller_id = ?"
);
$ratingStmt->execute([$sellerId]);
$ratingData = $ratingStmt->fetch();
$avgRating = $ratingData['avg_rating'] ?? 0;
$totalReviews = $ratingData['total_reviews'] ?? 0;

// Get reviews for display
$reviewsStmt = $pdo->prepare(
    "SELECT sr.*, u.full_name, p.title as project_title
     FROM seller_reviews sr
     JOIN users u ON sr.buyer_id = u.id
     LEFT JOIN projects p ON sr.project_id = p.id
     WHERE sr.seller_id = ?
     ORDER BY sr.created_at DESC
     LIMIT 10"
);
$reviewsStmt->execute([$sellerId]);
$reviews = $reviewsStmt->fetchAll();

// Get seller services
$servicesStmt = $pdo->prepare(
    "SELECT * FROM seller_services 
     WHERE seller_id = ? AND status = 'active'
     ORDER BY created_at DESC
     LIMIT 6"
);
$servicesStmt->execute([$sellerId]);
$services = $servicesStmt->fetchAll();

// Parse skills
$skills = !empty($seller['skills']) ? explode(',', $seller['skills']) : [];
$skills = array_map('trim', $skills);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($seller['full_name']); ?> - Seller Profile</title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="stylesheet" href="/assets/css/dashboard.css">
    <style>
        body {
            background: var(--light);
        }

        .profile-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--primary);
            text-decoration: none;
            margin-bottom: 1.5rem;
            font-weight: 500;
        }

        .back-link:hover {
            text-decoration: underline;
        }

        .profile-header-card {
            background: white;
            border-radius: 1rem;
            padding: 2rem;
            box-shadow: var(--shadow-md);
            margin-bottom: 2rem;
        }

        .profile-header-content {
            display: grid;
            grid-template-columns: auto 1fr auto;
            gap: 2rem;
            align-items: start;
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
        }

        .profile-info {
            flex: 1;
        }

        .profile-name {
            font-size: 2rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 0.5rem;
        }

        .profile-tagline {
            color: var(--gray);
            font-size: 1.1rem;
            margin-bottom: 1rem;
        }

        .profile-meta {
            display: flex;
            gap: 2rem;
            color: var(--gray);
            font-size: 0.95rem;
        }

        .profile-meta-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .availability-badge {
            padding: 0.5rem 1rem;
            border-radius: 2rem;
            font-weight: 500;
            font-size: 0.9rem;
        }

        .availability-available {
            background: #d1fae5;
            color: #065f46;
        }

        .availability-busy {
            background: #fef3c7;
            color: #92400e;
        }

        .availability-away {
            background: #fee2e2;
            color: #991b1b;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            border-radius: 1rem;
            padding: 1.5rem;
            box-shadow: var(--shadow-sm);
            text-align: center;
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 0.5rem;
        }

        .stat-label {
            color: var(--gray);
            font-size: 0.9rem;
        }

        .content-section {
            background: white;
            border-radius: 1rem;
            padding: 2rem;
            box-shadow: var(--shadow-md);
            margin-bottom: 2rem;
        }

        .section-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 1.5rem;
        }

        .skills-list {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
        }

        .skill-badge {
            padding: 0.5rem 1rem;
            background: var(--light);
            color: var(--dark);
            border-radius: 2rem;
            font-size: 0.9rem;
            font-weight: 500;
        }

        .review-item {
            padding: 1.5rem;
            background: var(--light);
            border-radius: 0.75rem;
            margin-bottom: 1rem;
        }

        .review-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 1rem;
        }

        .review-author {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.25rem;
        }

        .review-rating {
            color: #ffd700;
            font-size: 1.1rem;
        }

        .review-text {
            color: var(--gray);
            line-height: 1.6;
        }

        .review-project {
            font-size: 0.85rem;
            color: var(--gray);
            margin-top: 0.75rem;
            font-style: italic;
        }

        .empty-state {
            text-align: center;
            padding: 3rem;
            color: var(--gray);
        }
    </style>
</head>

<body>
    <div class="profile-container">
        <a href="javascript:history.back()" class="back-link">
            ← Back
        </a>

        <div class="profile-header-card">
            <div class="profile-header-content">
                <div class="profile-avatar-large">
                    <?php echo strtoupper(substr($seller['full_name'], 0, 1)); ?>
                </div>

                <div class="profile-info">
                    <h1 class="profile-name"><?php echo htmlspecialchars($seller['full_name']); ?></h1>
                    <?php if (!empty($seller['tagline'])): ?>
                        <div class="profile-tagline"><?php echo htmlspecialchars($seller['tagline']); ?></div>
                    <?php endif; ?>
                    <div class="profile-meta">
                        <div class="profile-meta-item">
                            📅 Member since <?php echo date('M Y', strtotime($seller['created_at'])); ?>
                        </div>
                        <div class="profile-meta-item">
                            👁️ <?php echo number_format($seller['profile_views']); ?> profile views
                        </div>
                    </div>
                </div>

                <div>
                    <?php
                    $availability = $seller['availability'] ?? 'available';
                    $availabilityClass = 'availability-' . $availability;
                    $availabilityText = [
                        'available' => '🟢 Available Now',
                        'busy' => '🟡 Limited Availability',
                        'away' => '🔴 Out of Office'
                    ];
                    ?>
                    <div class="availability-badge <?php echo $availabilityClass; ?>">
                        <?php echo $availabilityText[$availability]; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['total_projects'] ?? 0; ?></div>
                <div class="stat-label">Projects Completed</div>
            </div>

            <div class="stat-card">
                <div class="stat-value">
                    <?php if ($avgRating > 0): ?>
                        ⭐ <?php echo number_format($avgRating, 1); ?>
                    <?php else: ?>
                        —
                    <?php endif; ?>
                </div>
                <div class="stat-label"><?php echo $totalReviews; ?> Reviews</div>
            </div>

            <div class="stat-card">
                <div class="stat-value"><?php echo $responseRate; ?>%</div>
                <div class="stat-label">Response Rate (30d)</div>
            </div>

            <div class="stat-card">
                <div class="stat-value" style="font-size: 1.3rem;"><?php echo $avgResponseTime; ?></div>
                <div class="stat-label">Avg Response Time</div>
            </div>
        </div>

        <?php if (!empty($seller['bio'])): ?>
            <div class="content-section">
                <h2 class="section-title">About</h2>
                <p style="color: var(--gray); line-height: 1.7;">
                    <?php echo nl2br(htmlspecialchars($seller['bio'])); ?>
                </p>
            </div>
        <?php endif; ?>

        <?php if (!empty($skills)): ?>
            <div class="content-section">
                <h2 class="section-title">Skills & Expertise</h2>
                <div class="skills-list">
                    <?php foreach ($skills as $skill): ?>
                        <?php if (!empty($skill)): ?>
                            <span class="skill-badge"><?php echo htmlspecialchars($skill); ?></span>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($services)): ?>
            <div class="content-section">
                <h2 class="section-title">Services</h2>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1.5rem;">
                    <?php foreach ($services as $service): ?>
                        <div style="background: var(--light); border-radius: 0.75rem; padding: 1.5rem; border-left: 4px solid var(--primary);">
                            <div style="font-weight: 600; color: var(--dark); margin-bottom: 0.5rem; font-size: 1.05rem;">
                                <?php echo htmlspecialchars($service['title']); ?>
                            </div>
                            <div style="color: var(--gray); font-size: 0.9rem; margin-bottom: 1rem; line-height: 1.5;">
                                <?php echo htmlspecialchars(substr($service['description'], 0, 100)); ?>
                                <?php if (strlen($service['description']) > 100): ?>...<?php endif; ?>
                            </div>
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <div style="font-weight: 700; color: var(--primary); font-size: 1.1rem;">
                                    $<?php echo number_format($service['base_price'], 2); ?>
                                </div>
                                <div style="font-size: 0.85rem; color: var(--gray);">
                                    <?php
                                    if ($service['num_orders'] > 0) {
                                        echo $service['num_orders'] . ' order' . ($service['num_orders'] != 1 ? 's' : '');
                                    } else {
                                        echo 'New service';
                                    }
                                    ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="content-section">
            <h2 class="section-title">Client Reviews (<?php echo $totalReviews; ?>)</h2>

            <?php if (empty($reviews)): ?>
                <div class="empty-state">
                    <div style="font-size: 3rem; margin-bottom: 1rem;">📭</div>
                    <p>No reviews yet</p>
                </div>
            <?php else: ?>
                <?php foreach ($reviews as $review): ?>
                    <div class="review-item">
                        <div class="review-header">
                            <div>
                                <div class="review-author"><?php echo htmlspecialchars($review['full_name']); ?></div>
                                <div class="review-rating"><?php echo str_repeat('⭐', $review['rating']); ?></div>
                            </div>
                            <small style="color: var(--gray);">
                                <?php echo date('M j, Y', strtotime($review['created_at'])); ?>
                            </small>
                        </div>
                        <div class="review-text">
                            <?php echo htmlspecialchars($review['review_text']); ?>
                        </div>
                        <?php if (!empty($review['project_title'])): ?>
                            <div class="review-project">
                                Project: <?php echo htmlspecialchars($review['project_title']); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</body>

</html>