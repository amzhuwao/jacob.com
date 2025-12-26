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
                    <button class="toggle-sidebar" onclick="toggleSidebar()">☰</button>
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
                                    <div class="project-card" style="margin-bottom: 1rem;">
                                        <div class="project-title"><?php echo htmlspecialchars($project['title']); ?></div>
                                        <div style="margin: 0.75rem 0; padding: 0.75rem; background: rgba(16, 185, 129, 0.1); border-radius: 0.5rem; font-size: 0.9rem; color: var(--success);">
                                            ✓ Payment Secured
                                        </div>
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
                                    <div class="project-card" style="margin-bottom: 1rem; opacity: 0.8;">
                                        <div class="project-title"><?php echo htmlspecialchars($project['title']); ?></div>
                                        <div style="margin: 0.75rem 0; padding: 0.75rem; background: rgba(16, 185, 129, 0.1); border-radius: 0.5rem; font-size: 0.9rem; color: var(--success);">
                                            ✓ Delivered & Paid
                                        </div>
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
                        <div style="display: flex; flex-direction: column; gap: 1rem;">
                            <div>
                                <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                                    <span>Graphic Design</span>
                                    <span style="font-weight: 600;">$4,500 (45%)</span>
                                </div>
                                <div class="progress-bar">
                                    <div class="progress-fill" style="width: 45%;"></div>
                                </div>
                            </div>
                            <div>
                                <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                                    <span>Copywriting</span>
                                    <span style="font-weight: 600;">$3,200 (32%)</span>
                                </div>
                                <div class="progress-bar">
                                    <div class="progress-fill" style="width: 32%;"></div>
                                </div>
                            </div>
                            <div>
                                <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                                    <span>Development</span>
                                    <span style="font-weight: 600;">$2,300 (23%)</span>
                                </div>
                                <div class="progress-bar">
                                    <div class="progress-fill" style="width: 23%;"></div>
                                </div>
                            </div>
                        </div>
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
                                    ⭐⭐⭐⭐⭐ • <?php echo $freelancer['completed_projects']; ?> projects
                                </div>
                                <a href="/dashboard/project_view.php" class="action-btn primary" style="width: 100%;">Hire Again</a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </main>
    </div>

    <script>
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
        });
    </script>

</body>

</html>