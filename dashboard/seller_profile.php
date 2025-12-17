<?php
require_once "../includes/auth.php";
require_once "../config/database.php";

if ($_SESSION['role'] !== 'seller') {
    die("Access denied");
}

$userId = $_SESSION['user_id'];
$success = '';
$errors = [];

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $fullName = $_POST['full_name'] ?? '';
    $tagline = $_POST['tagline'] ?? '';
    $bio = $_POST['bio'] ?? '';
    $skills = $_POST['skills'] ?? '';
    $availability = $_POST['availability'] ?? 'available';

    if (!empty($fullName)) {
        $updateStmt = $pdo->prepare(
            "UPDATE users SET full_name = ? WHERE id = ?"
        );
        $updateStmt->execute([$fullName, $userId]);
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

// Get seller profile data
$profileStmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$profileStmt->execute([$userId]);
$profile = $profileStmt->fetch();

// Get seller statistics
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

// Calculate profile completion
$profileFields = [
    'full_name' => !empty($profile['full_name']),
    'email' => !empty($profile['email']),
    'bio' => false, // Would check profile_bio column if it existed
    'skills' => false,
    'portfolio' => false
];
$completedFields = count(array_filter($profileFields));
$totalFields = count($profileFields);
$profileCompletion = round(($completedFields / $totalFields) * 100);

// Mock data for demonstration
$profileViews = 247;
$responseRate = 95;
$avgResponseTime = "2 hours";
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - Jacob</title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="stylesheet" href="/assets/css/dashboard.css">
    <style>
        .profile-wrapper {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem;
        }

        .profile-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            height: 280px;
            border-radius: 1.5rem;
            position: relative;
            overflow: hidden;
            margin-bottom: 5rem;
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
        }

        .cover-photo-overlay:hover {
            background: rgba(0, 0, 0, 0.7);
        }

        .profile-identity {
            position: absolute;
            bottom: -80px;
            left: 2rem;
            display: flex;
            align-items: flex-end;
            gap: 2rem;
        }

        .profile-avatar-wrapper {
            position: relative;
            cursor: pointer;
        }

        .profile-avatar {
            width: 160px;
            height: 160px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 4rem;
            font-weight: 700;
            border: 6px solid white;
            box-shadow: var(--shadow-xl);
        }

        .avatar-camera-icon {
            position: absolute;
            bottom: 10px;
            right: 10px;
            width: 40px;
            height: 40px;
            background: var(--primary);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.2rem;
            border: 3px solid white;
            cursor: pointer;
            transition: var(--transition);
        }

        .avatar-camera-icon:hover {
            background: var(--primary-dark);
            transform: scale(1.1);
        }

        .profile-info {
            padding-bottom: 1rem;
        }

        .profile-name-editable {
            font-size: 2rem;
            font-weight: 700;
            color: white;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .edit-icon {
            cursor: pointer;
            opacity: 0.7;
            transition: var(--transition);
            font-size: 1.2rem;
        }

        .edit-icon:hover {
            opacity: 1;
        }

        .profile-tagline {
            font-size: 1.2rem;
            color: rgba(255, 255, 255, 0.95);
            margin-bottom: 0.75rem;
        }

        .profile-badges {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
        }

        .badge {
            padding: 0.5rem 1rem;
            border-radius: 50px;
            font-size: 0.85rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
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
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: 2px solid white;
        }

        .profile-content {
            display: grid;
            grid-template-columns: 350px 1fr;
            gap: 2rem;
            margin-top: 2rem;
        }

        .sidebar-section {
            background: white;
            padding: 1.5rem;
            border-radius: 1rem;
            box-shadow: var(--shadow-md);
            margin-bottom: 1.5rem;
        }

        .section-heading {
            font-size: 1.25rem;
            font-weight: 700;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .profile-strength {
            margin-bottom: 1rem;
        }

        .strength-bar {
            width: 100%;
            height: 12px;
            background: var(--gray-light);
            border-radius: 50px;
            overflow: hidden;
            margin-bottom: 0.5rem;
        }

        .strength-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--success), var(--primary));
            border-radius: 50px;
            transition: var(--transition);
        }

        .strength-text {
            font-size: 0.9rem;
            color: var(--gray);
        }

        .suggestions-list {
            list-style: none;
            margin-top: 1rem;
        }

        .suggestions-list li {
            padding: 0.75rem;
            background: var(--light);
            border-radius: 0.5rem;
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
            color: var(--dark);
        }

        .stat-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem 0;
            border-bottom: 1px solid var(--gray-light);
        }

        .stat-item:last-child {
            border-bottom: none;
        }

        .stat-label {
            color: var(--gray);
            font-size: 0.95rem;
        }

        .stat-value {
            font-weight: 700;
            font-size: 1.1rem;
            color: var(--dark);
        }

        .main-content-area {
            display: flex;
            flex-direction: column;
            gap: 2rem;
        }

        .content-card {
            background: white;
            padding: 2rem;
            border-radius: 1rem;
            box-shadow: var(--shadow-md);
        }

        .tabs {
            display: flex;
            gap: 1rem;
            border-bottom: 2px solid var(--gray-light);
            margin-bottom: 2rem;
        }

        .tab {
            padding: 0.75rem 1.5rem;
            border: none;
            background: none;
            cursor: pointer;
            font-weight: 600;
            color: var(--gray);
            border-bottom: 3px solid transparent;
            transition: var(--transition);
        }

        .tab.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-label {
            display: block;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--dark);
        }

        .form-input {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 2px solid var(--gray-light);
            border-radius: 0.5rem;
            font-size: 1rem;
            transition: var(--transition);
        }

        .form-input:focus {
            outline: none;
            border-color: var(--primary);
        }

        .form-textarea {
            min-height: 120px;
            resize: vertical;
        }

        .skills-cloud {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            margin-top: 1rem;
        }

        .skill-tag {
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

        .skill-tag:hover {
            border-color: var(--primary);
            background: rgba(99, 102, 241, 0.1);
        }

        .skill-remove {
            cursor: pointer;
            color: var(--danger);
            font-weight: 700;
        }

        .portfolio-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-top: 1.5rem;
        }

        .portfolio-item {
            aspect-ratio: 16/10;
            background: var(--light);
            border-radius: 0.75rem;
            overflow: hidden;
            position: relative;
            cursor: pointer;
            transition: var(--transition);
        }

        .portfolio-item:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
        }

        .portfolio-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.7);
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: var(--transition);
        }

        .portfolio-item:hover .portfolio-overlay {
            opacity: 1;
        }

        .review-card {
            padding: 1.5rem;
            background: var(--light);
            border-radius: 0.75rem;
            margin-bottom: 1rem;
        }

        .review-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }

        .review-author {
            font-weight: 600;
            color: var(--dark);
        }

        .review-rating {
            color: #ffd700;
        }

        .review-text {
            color: var(--gray);
            line-height: 1.6;
            margin-bottom: 1rem;
        }

        .review-reply-btn {
            padding: 0.5rem 1rem;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 0.5rem;
            cursor: pointer;
            font-size: 0.9rem;
            transition: var(--transition);
        }

        .review-reply-btn:hover {
            background: var(--primary-dark);
        }

        @media (max-width: 1024px) {
            .profile-content {
                grid-template-columns: 1fr;
            }

            .profile-identity {
                flex-direction: column;
                align-items: center;
                left: 50%;
                transform: translateX(-50%);
                text-align: center;
            }
        }
    </style>
</head>

<body>

    <div class="profile-wrapper">
        <!-- Cover Photo Header -->
        <div class="profile-header">
            <div class="cover-photo-overlay">
                📷 Change Cover Photo
            </div>

            <div class="profile-identity">
                <div class="profile-avatar-wrapper">
                    <div class="profile-avatar">
                        <?php echo strtoupper(substr($profile['full_name'], 0, 1)); ?>
                    </div>
                    <div class="avatar-camera-icon">📷</div>
                </div>

                <div class="profile-info">
                    <div class="profile-name-editable">
                        <?php echo htmlspecialchars($profile['full_name']); ?>
                        <span class="edit-icon" onclick="editField('name')">✏️</span>
                    </div>
                    <div class="profile-tagline">
                        Professional Freelancer • Expert in Quality Delivery
                        <span class="edit-icon" onclick="editField('tagline')">✏️</span>
                    </div>
                    <div class="profile-badges">
                        <span class="badge badge-top-rated">⭐ Top Rated</span>
                        <span class="badge badge-available">🟢 Available Now</span>
                        <span class="badge badge-level">Level 2 Seller</span>
                    </div>
                </div>
            </div>
        </div>

        <?php if (!empty($success)): ?>
            <div class="success-message" style="margin-bottom: 1.5rem;">
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="error-messages" style="margin-bottom: 1.5rem;">
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <!-- Main Content -->
        <div class="profile-content">
            <!-- Sidebar -->
            <div>
                <!-- Profile Strength (Owner Only) -->
                <div class="sidebar-section">
                    <div class="section-heading">📊 Profile Strength</div>
                    <div class="profile-strength">
                        <div class="strength-bar">
                            <div class="strength-fill" style="width: <?php echo $profileCompletion; ?>%;"></div>
                        </div>
                        <div class="strength-text"><?php echo $profileCompletion; ?>% Complete</div>
                    </div>
                    <ul class="suggestions-list">
                        <li>📸 Add a professional profile photo</li>
                        <li>📝 Complete your bio section</li>
                        <li>🎨 Upload portfolio samples</li>
                        <li>🎓 Add certifications</li>
                    </ul>
                </div>

                <!-- Private Stats (Owner Only) -->
                <div class="sidebar-section">
                    <div class="section-heading">💰 Your Performance</div>
                    <div class="stat-item">
                        <span class="stat-label">Total Earnings</span>
                        <span class="stat-value">$<?php echo number_format($stats['total_earnings'], 0); ?></span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label">Profile Views (30d)</span>
                        <span class="stat-value"><?php echo $profileViews; ?></span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label">Response Rate</span>
                        <span class="stat-value"><?php echo $responseRate; ?>%</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label">Avg Response Time</span>
                        <span class="stat-value"><?php echo $avgResponseTime; ?></span>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="sidebar-section">
                    <div class="section-heading">⚡ Quick Actions</div>
                    <a href="/dashboard/seller.php" class="action-btn primary" style="width: 100%; margin-bottom: 0.75rem;">
                        📊 Back to Dashboard
                    </a>
                    <button class="action-btn" style="width: 100%;" onclick="showTab('services')">
                        ➕ Add New Service
                    </button>
                </div>
            </div>

            <!-- Main Content Area -->
            <div class="main-content-area">
                <!-- Tabs -->
                <div class="content-card">
                    <div class="tabs">
                        <button class="tab active" onclick="showTab('overview')">Overview</button>
                        <button class="tab" onclick="showTab('services')">Services (Gigs)</button>
                        <button class="tab" onclick="showTab('portfolio')">Portfolio</button>
                        <button class="tab" onclick="showTab('reviews')">Reviews</button>
                        <button class="tab" onclick="showTab('settings')">Settings</button>
                    </div>

                    <!-- Overview Tab -->
                    <div id="tab-overview" class="tab-content">
                        <h2 class="section-heading">About Me</h2>
                        <p style="color: var(--gray); line-height: 1.7; margin-bottom: 2rem;">
                            <?php echo htmlspecialchars($profile['email']); ?> • Joined <?php echo date('F Y', strtotime($profile['created_at'])); ?>
                        </p>

                        <h3 style="margin-bottom: 1rem;">Skills & Expertise</h3>
                        <div class="skills-cloud">
                            <span class="skill-tag">Web Development <span class="skill-remove">×</span></span>
                            <span class="skill-tag">UI/UX Design <span class="skill-remove">×</span></span>
                            <span class="skill-tag">JavaScript <span class="skill-remove">×</span></span>
                            <span class="skill-tag">React <span class="skill-remove">×</span></span>
                            <span class="skill-tag">PHP <span class="skill-remove">×</span></span>
                        </div>
                        <button class="action-btn" style="margin-top: 1rem;">➕ Add Skill</button>

                        <h3 style="margin: 2rem 0 1rem;">Public Stats (Visible to Buyers)</h3>
                        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem;">
                            <div style="padding: 1.5rem; background: var(--light); border-radius: 0.75rem; text-align: center;">
                                <div style="font-size: 2rem; font-weight: 700; color: var(--primary);"><?php echo $stats['total_projects']; ?></div>
                                <div style="color: var(--gray); font-size: 0.9rem;">Projects Completed</div>
                            </div>
                            <div style="padding: 1.5rem; background: var(--light); border-radius: 0.75rem; text-align: center;">
                                <div style="font-size: 2rem; font-weight: 700; color: var(--success);"><?php echo $responseRate; ?>%</div>
                                <div style="color: var(--gray); font-size: 0.9rem;">Response Rate</div>
                            </div>
                            <div style="padding: 1.5rem; background: var(--light); border-radius: 0.75rem; text-align: center;">
                                <div style="font-size: 2rem; font-weight: 700; color: var(--warning);">⭐⭐⭐⭐⭐</div>
                                <div style="color: var(--gray); font-size: 0.9rem;">5.0 Rating</div>
                            </div>
                        </div>
                    </div>

                    <!-- Services Tab -->
                    <div id="tab-services" class="tab-content" style="display: none;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
                            <h2 class="section-heading" style="margin: 0;">My Services</h2>
                            <button class="action-btn primary">➕ Create New Service</button>
                        </div>

                        <div class="portfolio-grid">
                            <div class="portfolio-item">
                                <div class="portfolio-overlay">
                                    <div style="color: white; text-align: center;">
                                        <div style="font-size: 1.5rem; margin-bottom: 0.5rem;">🎨</div>
                                        <div style="font-weight: 600;">Logo Design</div>
                                        <div style="font-size: 0.9rem; margin: 0.5rem 0;">Starting at $150</div>
                                        <button class="review-reply-btn">Edit Service</button>
                                    </div>
                                </div>
                            </div>
                            <div class="portfolio-item" style="border: 3px dashed var(--gray-light); display: flex; align-items: center; justify-content: center;">
                                <div style="text-align: center; color: var(--gray);">
                                    <div style="font-size: 3rem;">➕</div>
                                    <div>Add New Service</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Portfolio Tab -->
                    <div id="tab-portfolio" class="tab-content" style="display: none;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
                            <h2 class="section-heading" style="margin: 0;">My Portfolio</h2>
                            <button class="action-btn primary">📤 Upload Work</button>
                        </div>

                        <div class="portfolio-grid">
                            <div class="portfolio-item">
                                <div class="portfolio-overlay">
                                    <button class="review-reply-btn">Manage</button>
                                </div>
                            </div>
                            <div class="portfolio-item">
                                <div class="portfolio-overlay">
                                    <button class="review-reply-btn">Manage</button>
                                </div>
                            </div>
                            <div class="portfolio-item" style="border: 3px dashed var(--gray-light); display: flex; align-items: center; justify-content: center;">
                                <div style="text-align: center; color: var(--gray);">
                                    <div style="font-size: 3rem;">📤</div>
                                    <div>Upload New Work</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Reviews Tab -->
                    <div id="tab-reviews" class="tab-content" style="display: none;">
                        <h2 class="section-heading">Client Reviews</h2>

                        <div class="review-card">
                            <div class="review-header">
                                <div>
                                    <div class="review-author">John Smith</div>
                                    <div class="review-rating">⭐⭐⭐⭐⭐</div>
                                </div>
                                <small style="color: var(--gray);">2 days ago</small>
                            </div>
                            <div class="review-text">
                                Excellent work! Very professional and delivered on time. Will definitely hire again.
                            </div>
                            <button class="review-reply-btn">💬 Reply to Review</button>
                        </div>

                        <div class="review-card">
                            <div class="review-header">
                                <div>
                                    <div class="review-author">Sarah Johnson</div>
                                    <div class="review-rating">⭐⭐⭐⭐⭐</div>
                                </div>
                                <small style="color: var(--gray);">1 week ago</small>
                            </div>
                            <div class="review-text">
                                Amazing communication and quality work. Highly recommended!
                            </div>
                            <button class="review-reply-btn">💬 Reply to Review</button>
                        </div>
                    </div>

                    <!-- Settings Tab -->
                    <div id="tab-settings" class="tab-content" style="display: none;">
                        <h2 class="section-heading">Account Settings</h2>

                        <!-- Profile Details Form -->
                        <form method="POST" style="margin-bottom: 3rem;">
                            <h3 style="margin-bottom: 1.5rem;">Personal Details</h3>

                            <div class="form-group">
                                <label class="form-label">Full Name</label>
                                <input type="text" name="full_name" class="form-input" value="<?php echo htmlspecialchars($profile['full_name']); ?>" required>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Professional Tagline</label>
                                <input type="text" name="tagline" class="form-input" placeholder="e.g., Award-winning Designer with 8+ years experience">
                            </div>

                            <div class="form-group">
                                <label class="form-label">Bio</label>
                                <textarea name="bio" class="form-input form-textarea" placeholder="Tell buyers about your experience and expertise..."></textarea>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Skills (comma-separated)</label>
                                <input type="text" name="skills" class="form-input" placeholder="e.g., PHP, JavaScript, React, Node.js">
                            </div>

                            <div class="form-group">
                                <label class="form-label">Availability Status</label>
                                <select name="availability" class="form-input">
                                    <option value="available">🟢 Available Now</option>
                                    <option value="busy">🟡 Busy (Limited Availability)</option>
                                    <option value="away">🔴 Out of Office</option>
                                </select>
                            </div>

                            <button type="submit" name="update_profile" class="action-btn primary">
                                💾 Save Profile Changes
                            </button>
                        </form>

                        <!-- Password Change Form -->
                        <form method="POST" style="border-top: 2px solid var(--gray-light); padding-top: 2rem;">
                            <h3 style="margin-bottom: 1.5rem;">Change Password</h3>

                            <div class="form-group">
                                <label class="form-label">Current Password</label>
                                <input type="password" name="current_password" class="form-input" required>
                            </div>

                            <div class="form-group">
                                <label class="form-label">New Password</label>
                                <input type="password" name="new_password" class="form-input" required>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Confirm New Password</label>
                                <input type="password" name="confirm_password" class="form-input" required>
                            </div>

                            <button type="submit" name="change_password" class="action-btn primary">
                                🔒 Update Password
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function showTab(tabName) {
            // Hide all tabs
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.style.display = 'none';
            });

            // Remove active class from all tab buttons
            document.querySelectorAll('.tab').forEach(btn => {
                btn.classList.remove('active');
            });

            // Show selected tab
            document.getElementById('tab-' + tabName).style.display = 'block';

            // Add active class to clicked button
            event.target.classList.add('active');
        }

        function editField(field) {
            alert('Edit ' + field + ' - This would open an inline editor');
        }
    </script>

</body>

</html>