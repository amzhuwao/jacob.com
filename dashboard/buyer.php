<?php
require_once "../includes/auth.php";
require_once "../config/database.php";

if ($_SESSION['role'] !== 'buyer') {
    die("Access denied");
}

$userId = $_SESSION['user_id'];

// Get buyer's KPI data
$projectsStmt = $pdo->prepare("SELECT COUNT(*) as count FROM projects WHERE buyer_id = ?");
$projectsStmt->execute([$userId]);
$totalProjects = $projectsStmt->fetch()['count'] ?? 0;

$spendStmt = $pdo->prepare("SELECT COALESCE(SUM(b.amount), 0) as total FROM bids b JOIN projects p ON b.project_id = p.id WHERE p.buyer_id = ? AND b.status = 'accepted'");
$spendStmt->execute([$userId]);
$totalSpend = $spendStmt->fetch()['total'] ?? 0;

// Get all projects grouped by status
$projectsByStatus = [
    'open' => [],
    'in_progress' => [],
    'funded' => [],
    'completed' => []
];

$allProjectsStmt = $pdo->prepare(
    "SELECT * FROM projects WHERE buyer_id = ? ORDER BY created_at DESC"
);
$allProjectsStmt->execute([$userId]);
$allProjects = $allProjectsStmt->fetchAll();

foreach ($allProjects as $project) {
    $projectsByStatus[$project['status']][] = $project;
}

// Get action required projects (pending approvals)
$actionStmt = $pdo->prepare(
    "SELECT p.id, p.title, p.status
     FROM projects p
     WHERE p.buyer_id = ? AND p.status = 'open'
     LIMIT 3"
);
$actionStmt->execute([$userId]);
$actionRequired = $actionStmt->fetchAll();

// Get favorite freelancers (highly rated)
$favoriteStmt = $pdo->prepare(
    "SELECT DISTINCT u.id, u.full_name, COUNT(b.id) as completed_projects
     FROM users u
     JOIN bids b ON u.id = b.seller_id
     JOIN projects p ON b.project_id = p.id
     WHERE p.buyer_id = ? AND b.status = 'accepted'
     GROUP BY u.id
     ORDER BY completed_projects DESC
     LIMIT 5"
);
$favoriteStmt->execute([$userId]);
$favorites = $favoriteStmt->fetchAll();

// Get spending by category (real data)
$spendingByCategory = [];
$categorySpendStmt = $pdo->prepare(
    "SELECT 
        p.category,
        COALESCE(SUM(b.amount), 0) as total_spent
     FROM projects p
     JOIN bids b ON p.id = b.project_id
     WHERE p.buyer_id = ? AND b.status = 'accepted'
     GROUP BY p.category
     ORDER BY total_spent DESC"
);
$categorySpendStmt->execute([$userId]);
$spendingByCategory = $categorySpendStmt->fetchAll();

// Calculate percentages
$categoryTotalSpend = array_sum(array_column($spendingByCategory, 'total_spent'));
foreach ($spendingByCategory as &$cat) {
    $cat['percentage'] = $categoryTotalSpend > 0 ? round(($cat['total_spent'] / $categoryTotalSpend) * 100) : 0;
}
unset($cat);

// Get favorite freelancers with real ratings
$favoritesWithRatings = [];
foreach ($favorites as $freelancer) {
    $ratingStmt = $pdo->prepare(
        "SELECT ROUND(AVG(rating), 1) as avg_rating, COUNT(*) as review_count
         FROM seller_reviews
         WHERE seller_id = ?"
    );
    $ratingStmt->execute([$freelancer['id']]);
    $ratingData = $ratingStmt->fetch();

    $freelancer['avg_rating'] = $ratingData['avg_rating'] ?? 0;
    $freelancer['review_count'] = $ratingData['review_count'] ?? 0;
    $favoritesWithRatings[] = $freelancer;
}
$favorites = $favoritesWithRatings;
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Buyer Dashboard - Jacob</title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="stylesheet" href="/assets/css/dashboard.css">
</head>

<body>

    <!-- Sidebar Overlay for Mobile -->
    <div id="sidebar-overlay" class="sidebar-overlay"></div>

    <div class="dashboard-wrapper">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="sidebar-brand">
                <span>✨</span>
                <span>Jacob</span>
            </div>
            <ul class="sidebar-nav">
                <li><a href="/dashboard/buyer.php" class="active"><span>📊</span> <span>Dashboard</span></a></li>
                <li><a href="/dashboard/buyer_post_project.php"><span>➕</span> <span>New Project</span></a></li>
                <li><a href="#pipeline"><span>🔄</span> <span>Pipeline</span></a></li>
                <li><a href="#spending"><span>💸</span> <span>Spending</span></a></li>
                <li><a href="#favorites"><span>⭐</span> <span>Favorites</span></a></li>
                <li><a href="/disputes/open_dispute.php"><span>⚖️</span> <span>Disputes</span></a></li>
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
                    <button class="toggle-sidebar">☰</button>
                    <div class="search-bar">
                        <input type="text" placeholder="Search projects, freelancers... (Ctrl+K)">
                    </div>
                </div>
                <div class="header-right">
                    <div style="position: relative;">
                        <button style="background: none; border: none; font-size: 1.5rem; cursor: pointer; position: relative;">🔔
                            <span class="notification-badge" id="notif-count">3</span>
                        </button>
                    </div>
                    <div class="user-profile">
                        <div class="avatar"><?php echo strtoupper(substr($_SESSION['name'], 0, 1)); ?></div>
                        <span><?php echo htmlspecialchars($_SESSION['name']); ?></span>
                    </div>
                </div>
            </div>

            <!-- KPI Section -->
            <section class="kpi-section">
                <div class="kpi-grid">
                    <div class="kpi-card">
                        <div class="kpi-label">📊 Total Projects</div>
                        <div class="kpi-value"><?php echo $totalProjects; ?></div>
                        <div class="kpi-subtext">Across all statuses</div>
                    </div>

                    <div class="kpi-card success">
                        <div class="kpi-label">💸 Total Spend</div>
                        <div class="kpi-value">$<?php echo number_format($totalSpend, 0); ?></div>
                        <div class="kpi-subtext">Committed budget</div>
                    </div>

                    <div class="kpi-card warning">
                        <div class="kpi-label">⏳ In Progress</div>
                        <div class="kpi-value"><?php echo count($projectsByStatus['in_progress']); ?></div>
                        <div class="kpi-subtext">Actively working</div>
                    </div>

                    <div class="kpi-card success">
                        <div class="kpi-label">✅ Completed</div>
                        <div class="kpi-value"><?php echo count($projectsByStatus['completed']); ?></div>
                        <div class="kpi-subtext">Projects finished</div>
                    </div>
                </div>
            </section>

            <!-- Action Required Hub -->
            <?php if (!empty($actionRequired)): ?>
                <div class="section">
                    <div class="notification-hub">
                        <div class="notification-hub-title">⚠️ Actions Waiting For You</div>
                        <div class="notification-items">
                            <?php foreach ($actionRequired as $action): ?>
                                <div class="notification-item">
                                    <div>
                                        <strong><?php echo htmlspecialchars($action['title']); ?></strong><br>
                                        <small>Waiting for your action</small>
                                    </div>
                                    <a href="/dashboard/project_view.php?id=<?php echo $action['id']; ?>" class="notification-action">Review</a>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Project Pipeline (Kanban) -->
            <section id="pipeline">
                <div style="padding: 2rem; padding-top: 0;">
                    <h2 class="section-title">🔄 Project Pipeline</h2>
                </div>
                <div class="kanban-board">
                    <!-- Open Column -->
                    <div class="kanban-column">
                        <div class="kanban-header">
                            <div class="kanban-title">📝 Open</div>
                            <div class="kanban-count"><?php echo count($projectsByStatus['open']); ?></div>
                        </div>
                        <div class="kanban-items">
                            <?php if (empty($projectsByStatus['open'])): ?>
                                <div style="text-align: center; padding: 2rem; color: var(--gray); font-size: 0.9rem;">
                                    No open projects
                                </div>
                            <?php else: ?>
                                <?php foreach ($projectsByStatus['open'] as $project): ?>
                                    <div class="project-card" style="margin-bottom: 1rem;">
                                        <div class="project-title"><?php echo htmlspecialchars($project['title']); ?></div>
                                        <div class="project-meta" style="border: none; padding: 0.75rem 0;">
                                            <div class="project-meta-item">
                                                <span class="meta-label">Budget</span>
                                                <span class="meta-value">$<?php echo number_format($project['budget'], 0); ?></span>
                                            </div>
                                        </div>
                                        <a href="/dashboard/project_view.php?id=<?php echo $project['id']; ?>" class="action-btn primary" style="width: 100%;">View Bids</a>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- In Progress Column -->
                    <div class="kanban-column">
                        <div class="kanban-header">
                            <div class="kanban-title">⚡ In Progress</div>
                            <div class="kanban-count"><?php echo count($projectsByStatus['in_progress']); ?></div>
                        </div>
                        <div class="kanban-items">
                            <?php if (empty($projectsByStatus['in_progress'])): ?>
                                <div style="text-align: center; padding: 2rem; color: var(--gray); font-size: 0.9rem;">
                                    No in-progress projects
                                </div>
                            <?php else: ?>
                                <?php foreach ($projectsByStatus['in_progress'] as $project): ?>
                                    <div class="project-card" style="margin-bottom: 1rem;">
                                        <div class="project-title"><?php echo htmlspecialchars($project['title']); ?></div>
                                        <div style="margin: 0.75rem 0; padding: 0.75rem; background: rgba(245, 158, 11, 0.1); border-radius: 0.5rem; font-size: 0.9rem; color: var(--warning);">
                                            Work in progress...
                                        </div>
                                        <a href="/dashboard/project_view.php?id=<?php echo $project['id']; ?>" class="action-btn primary" style="width: 100%;">Manage</a>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Funded Column -->
                    <div class="kanban-column">
                        <div class="kanban-header">
                            <div class="kanban-title">💰 Funded</div>
                            <div class="kanban-count"><?php echo count($projectsByStatus['funded']); ?></div>
                        </div>
                        <div class="kanban-items">
                            <?php if (empty($projectsByStatus['funded'])): ?>
                                <div style="text-align: center; padding: 2rem; color: var(--gray); font-size: 0.9rem;">
                                    No funded projects
                                </div>
                            <?php else: ?>
                                <?php foreach ($projectsByStatus['funded'] as $project): ?>
                                    <?php
                                    // Get escrow delivery/approval status
                                    $escrowStmt = $pdo->prepare("SELECT work_delivered_at, buyer_approved_at FROM escrow WHERE project_id = ? LIMIT 1");
                                    $escrowStmt->execute([$project['id']]);
                                    $escrowStatus = $escrowStmt->fetch(PDO::FETCH_ASSOC);
                                    ?>
                                    <div class="project-card" style="margin-bottom: 1rem;">
                                        <div class="project-title"><?php echo htmlspecialchars($project['title']); ?></div>
                                        <div style="margin: 0.75rem 0; padding: 0.75rem; background: rgba(16, 185, 129, 0.1); border-radius: 0.5rem; font-size: 0.9rem; color: var(--success);">
                                            ✓ Payment Secured
                                        </div>

                                        <!-- Delivery/Approval Status -->
                                        <?php if ($escrowStatus): ?>
                                            <?php if ($escrowStatus['buyer_approved_at']): ?>
                                                <div style="margin: 0.5rem 0; padding: 0.75rem; background: rgba(16, 185, 129, 0.15); border-left: 3px solid #10b981; border-radius: 0.5rem; font-size: 0.85rem; color: #059669;">
                                                    ✓ Work Approved
                                                </div>
                                            <?php elseif ($escrowStatus['work_delivered_at']): ?>
                                                <div style="margin: 0.5rem 0; padding: 0.75rem; background: rgba(245, 158, 11, 0.15); border-left: 3px solid #f59e0b; border-radius: 0.5rem; font-size: 0.85rem; color: #d97706;">
                                                    ⏳ Awaiting Your Approval
                                                </div>
                                            <?php else: ?>
                                                <div style="margin: 0.5rem 0; padding: 0.75rem; background: rgba(59, 130, 246, 0.1); border-left: 3px solid #3b82f6; border-radius: 0.5rem; font-size: 0.85rem; color: #1e40af;">
                                                    📦 Waiting for Delivery
                                                </div>
                                            <?php endif; ?>
                                        <?php endif; ?>

                                        <a href="/dashboard/project_view.php?id=<?php echo $project['id']; ?>" class="action-btn primary" style="width: 100%;">Monitor</a>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Completed Column -->
                    <div class="kanban-column">
                        <div class="kanban-header">
                            <div class="kanban-title">✅ Completed</div>
                            <div class="kanban-count"><?php echo count($projectsByStatus['completed']); ?></div>
                        </div>
                        <div class="kanban-items">
                            <?php if (empty($projectsByStatus['completed'])): ?>
                                <div style="text-align: center; padding: 2rem; color: var(--gray); font-size: 0.9rem;">
                                    No completed projects
                                </div>
                            <?php else: ?>
                                <?php foreach ($projectsByStatus['completed'] as $project): ?>
                                    <?php
                                    // Check if buyer has already reviewed this project
                                    $reviewCheckStmt = $pdo->prepare("SELECT id FROM seller_reviews WHERE project_id = ? AND buyer_id = ?");
                                    $reviewCheckStmt->execute([$project['id'], $userId]);
                                    $hasReviewed = $reviewCheckStmt->fetch();

                                    // Get seller info for this project
                                    $sellerStmt = $pdo->prepare("SELECT u.id, u.full_name FROM users u JOIN escrow e ON u.id = e.seller_id WHERE e.project_id = ? LIMIT 1");
                                    $sellerStmt->execute([$project['id']]);
                                    $seller = $sellerStmt->fetch();
                                    ?>
                                    <div class="project-card" style="margin-bottom: 1rem;">
                                        <div class="project-title"><?php echo htmlspecialchars($project['title']); ?></div>
                                        <div style="margin: 0.75rem 0; padding: 0.75rem; background: rgba(16, 185, 129, 0.1); border-radius: 0.5rem; font-size: 0.9rem; color: var(--success);">
                                            ✓ Delivered & Paid
                                        </div>

                                        <?php if (!$hasReviewed && $seller): ?>
                                            <button onclick="openReviewModal(<?php echo $project['id']; ?>, '<?php echo htmlspecialchars($project['title'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($seller['full_name'], ENT_QUOTES); ?>')"
                                                class="action-btn" style="width: 100%; background: #fbbf24; color: white; margin-top: 0.5rem;">
                                                ⭐ Leave Review
                                            </button>
                                        <?php elseif ($hasReviewed): ?>
                                            <div style="margin-top: 0.5rem; padding: 0.5rem; background: rgba(16, 185, 129, 0.1); border-radius: 0.5rem; font-size: 0.85rem; color: var(--success); text-align: center;">
                                                ✓ Reviewed
                                            </div>
                                        <?php endif; ?>

                                        <a href="/dashboard/project_view.php?id=<?php echo $project['id']; ?>" class="action-btn primary" style="width: 100%; margin-top: 0.5rem;">View Details</a>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Spending Analytics -->
            <section id="spending" class="section">
                <h2 class="section-title">💸 Spending by Category</h2>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
                    <div style="background: white; padding: 1.5rem; border-radius: 1rem; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);">
                        <h3 style="margin-bottom: 1rem;">Budget Breakdown</h3>
                        <?php if (empty($spendingByCategory)): ?>
                            <div style="text-align: center; padding: 2rem; color: var(--gray);">
                                <div style="font-size: 2rem; margin-bottom: 0.5rem;">📊</div>
                                <p>No spending data yet</p>
                                <p style="font-size: 0.85rem; margin-top: 0.5rem;">Start hiring freelancers to see your spending breakdown</p>
                            </div>
                        <?php else: ?>
                            <div style="display: flex; flex-direction: column; gap: 1rem;">
                                <?php
                                $categoryLabels = [
                                    'web-development' => 'Web Development',
                                    'mobile-development' => 'Mobile Development',
                                    'ui-ux' => 'UI/UX Design',
                                    'graphic-design' => 'Graphic Design',
                                    'copywriting' => 'Copywriting',
                                    'marketing' => 'Marketing',
                                    'data-entry' => 'Data Entry',
                                    'other' => 'Other'
                                ];
                                $topCategories = array_slice($spendingByCategory, 0, 5);
                                foreach ($topCategories as $category):
                                ?>
                                    <div>
                                        <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                                            <span><?php echo $categoryLabels[$category['category']] ?? ucfirst(str_replace('-', ' ', $category['category'])); ?></span>
                                            <span style="font-weight: 600;">$<?php echo number_format($category['total_spent'], 0); ?> (<?php echo $category['percentage']; ?>%)</span>
                                        </div>
                                        <div class="progress-bar">
                                            <div class="progress-fill" style="width: <?php echo $category['percentage']; ?>%;"></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div style="background: white; padding: 1.5rem; border-radius: 1rem; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);">
                        <h3 style="margin-bottom: 1rem;">Monthly Trend</h3>
                        <div style="height: 150px; background: linear-gradient(to right, rgba(99, 102, 241, 0.1) 0%, rgba(99, 102, 241, 0.05) 100%); border-radius: 0.5rem; position: relative; display: flex; align-items: flex-end; justify-content: space-around; padding: 1rem;">
                            <div style="width: 8%; height: 30%; background: var(--primary); border-radius: 0.25rem;"></div>
                            <div style="width: 8%; height: 50%; background: var(--primary); border-radius: 0.25rem;"></div>
                            <div style="width: 8%; height: 40%; background: var(--primary); border-radius: 0.25rem;"></div>
                            <div style="width: 8%; height: 60%; background: var(--primary); border-radius: 0.25rem;"></div>
                            <div style="width: 8%; height: 45%; background: var(--primary); border-radius: 0.25rem;"></div>
                            <div style="width: 8%; height: 70%; background: var(--primary); border-radius: 0.25rem;"></div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Favorite Freelancers -->
            <section id="favorites" class="section">
                <h2 class="section-title">⭐ Quick Hire - Your Favorite Freelancers</h2>
                <?php if (empty($favorites)): ?>
                    <div class="empty-state">
                        <div class="empty-icon">👥</div>
                        <div class="empty-title">No favorite freelancers yet</div>
                        <div class="empty-description">As you work with freelancers, your top performers will appear here for quick rehire</div>
                    </div>
                <?php else: ?>
                    <div class="cards-grid">
                        <?php foreach ($favorites as $freelancer): ?>
                            <div style="background: white; padding: 1.5rem; border-radius: 1rem; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); text-align: center;">
                                <div class="avatar" style="width: 60px; height: 60px; margin: 0 auto 1rem; font-size: 1.5rem;">
                                    <?php echo strtoupper(substr($freelancer['full_name'], 0, 1)); ?>
                                </div>
                                <div class="project-title"><?php echo htmlspecialchars($freelancer['full_name']); ?></div>
                                <div style="color: var(--gray); margin-bottom: 1rem; font-size: 0.9rem;">
                                    <?php if ($freelancer['avg_rating'] > 0): ?>
                                        <?php echo str_repeat('⭐', round($freelancer['avg_rating'])); ?>
                                        <?php echo number_format($freelancer['avg_rating'], 1); ?>
                                        (<?php echo $freelancer['review_count']; ?> reviews)
                                    <?php else: ?>
                                        No reviews yet
                                    <?php endif; ?>
                                    • <?php echo $freelancer['completed_projects']; ?> projects
                                </div>
                                <a href="view_seller.php?id=<?php echo $freelancer['id']; ?>" class="action-btn" style="width: 100%; margin-bottom: 0.5rem;">View Profile</a>
                                <a href="buyer_post_project.php" class="action-btn primary" style="width: 100%;">Hire Again</a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </main>
    </div>

    <!-- Review Modal -->
    <div id="reviewModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.7); z-index: 1000; align-items: center; justify-content: center;">
        <div style="background: white; padding: 2rem; border-radius: 1rem; max-width: 500px; width: 90%; box-shadow: var(--shadow-xl);">
            <h3 style="margin-bottom: 1.5rem; color: var(--dark);">⭐ Leave a Review</h3>

            <form id="reviewForm">
                <input type="hidden" id="reviewProjectId" name="project_id">

                <div style="margin-bottom: 1.5rem;">
                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: var(--dark);">Project</label>
                    <div id="reviewProjectTitle" style="color: var(--gray); font-size: 0.95rem;"></div>
                </div>

                <div style="margin-bottom: 1.5rem;">
                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: var(--dark);">Seller</label>
                    <div id="reviewSellerName" style="color: var(--gray); font-size: 0.95rem;"></div>
                </div>

                <div style="margin-bottom: 1.5rem;">
                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: var(--dark);">Rating *</label>
                    <div style="display: flex; gap: 0.5rem; font-size: 2rem;">
                        <span class="star-rating" data-rating="1" onclick="setRating(1)" style="cursor: pointer; color: #d1d5db;">⭐</span>
                        <span class="star-rating" data-rating="2" onclick="setRating(2)" style="cursor: pointer; color: #d1d5db;">⭐</span>
                        <span class="star-rating" data-rating="3" onclick="setRating(3)" style="cursor: pointer; color: #d1d5db;">⭐</span>
                        <span class="star-rating" data-rating="4" onclick="setRating(4)" style="cursor: pointer; color: #d1d5db;">⭐</span>
                        <span class="star-rating" data-rating="5" onclick="setRating(5)" style="cursor: pointer; color: #d1d5db;">⭐</span>
                    </div>
                    <input type="hidden" id="reviewRating" name="rating" required>
                </div>

                <div style="margin-bottom: 1.5rem;">
                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: var(--dark);">Your Review *</label>
                    <textarea name="review_text" id="reviewText" rows="5"
                        style="width: 100%; padding: 0.75rem; border: 1px solid var(--border); border-radius: 0.5rem; font-family: inherit; resize: vertical;"
                        placeholder="Share your experience working with this seller..."
                        required minlength="10" maxlength="1000"></textarea>
                    <small style="color: var(--gray); font-size: 0.85rem;">Minimum 10 characters, maximum 1000</small>
                </div>

                <div id="reviewMessage" style="margin-bottom: 1rem; padding: 0.75rem; border-radius: 0.5rem; display: none;"></div>

                <div style="display: flex; gap: 1rem;">
                    <button type="button" onclick="closeReviewModal()" class="action-btn" style="flex: 1;">
                        Cancel
                    </button>
                    <button type="submit" class="action-btn primary" style="flex: 1;">
                        Submit Review
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        let selectedRating = 0;

        function openReviewModal(projectId, projectTitle, sellerName) {
            document.getElementById('reviewProjectId').value = projectId;
            document.getElementById('reviewProjectTitle').textContent = projectTitle;
            document.getElementById('reviewSellerName').textContent = sellerName;
            document.getElementById('reviewModal').style.display = 'flex';
            document.getElementById('reviewText').value = '';
            document.getElementById('reviewMessage').style.display = 'none';
            setRating(0); // Reset rating
        }

        function closeReviewModal() {
            document.getElementById('reviewModal').style.display = 'none';
        }

        function setRating(rating) {
            selectedRating = rating;
            document.getElementById('reviewRating').value = rating;

            // Update star colors
            document.querySelectorAll('.star-rating').forEach((star, index) => {
                if (index < rating) {
                    star.style.color = '#fbbf24'; // Gold
                } else {
                    star.style.color = '#d1d5db'; // Gray
                }
            });
        }

        document.getElementById('reviewForm').addEventListener('submit', async function(e) {
            e.preventDefault();

            if (selectedRating === 0) {
                showReviewMessage('Please select a rating', 'error');
                return;
            }

            const formData = new FormData(this);
            const submitBtn = this.querySelector('button[type="submit"]');
            submitBtn.disabled = true;
            submitBtn.textContent = 'Submitting...';

            try {
                const response = await fetch('/dashboard/submit_review.php', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();

                if (result.success) {
                    showReviewMessage(result.message, 'success');
                    setTimeout(() => {
                        closeReviewModal();
                        location.reload(); // Reload to update UI
                    }, 1500);
                } else {
                    showReviewMessage(result.message, 'error');
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Submit Review';
                }
            } catch (error) {
                showReviewMessage('An error occurred. Please try again.', 'error');
                submitBtn.disabled = false;
                submitBtn.textContent = 'Submit Review';
            }
        });

        function showReviewMessage(message, type) {
            const msgDiv = document.getElementById('reviewMessage');
            msgDiv.textContent = message;
            msgDiv.style.display = 'block';
            msgDiv.style.background = type === 'success' ? 'rgba(16, 185, 129, 0.1)' : 'rgba(239, 68, 68, 0.1)';
            msgDiv.style.color = type === 'success' ? '#059669' : '#dc2626';
        }

        function toggleSidebar() {
            document.querySelector('.sidebar').classList.toggle('collapsed');
            document.querySelector('.main-content').classList.toggle('expanded');
        }

        // Keyboard shortcut for search (Cmd+K / Ctrl+K)
        document.addEventListener('keydown', (e) => {
            if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
                e.preventDefault();
                document.querySelector('.search-bar input').focus();
            }

            // Close modal on Escape
            if (e.key === 'Escape') {
                closeReviewModal();
            }
        });

        // Close modal when clicking outside
        document.getElementById('reviewModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeReviewModal();
            }
        });
    </script>

    <!-- Sidebar Navigation Script -->
    <script src="/assets/js/sidebar.js"></script>

</body>

</html>