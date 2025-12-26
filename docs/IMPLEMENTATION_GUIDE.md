# Quick Implementation Guide: Using Real Database Data

## Status Check Commands

```bash
# Check all new tables exist
mysql -u root -p'@Fl011326' jacob_db -e "DESCRIBE seller_reviews;"
mysql -u root -p'@Fl011326' jacob_db -e "DESCRIBE profile_views;"
mysql -u root -p'@Fl011326' jacob_db -e "DESCRIBE user_statistics;"
mysql -u root -p'@Fl011326' jacob_db -e "DESCRIBE seller_services;"
```

## PRIORITY 1: Replace Hardcoded Metrics in seller.php

### Current Code (Lines 82-144):

```php
// HARDCODED - NEEDS REPLACEMENT
$responseRate = 95; // Would be calculated from actual response data
$profileViews = 247;
$avgResponseTime = "2 hours";
```

### Replace With:

```php
// Real Response Rate from database
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

// Real Profile Views from users table
$profileViewsStmt = $pdo->prepare("SELECT profile_views FROM users WHERE id = ?");
$profileViewsStmt->execute([$userId]);
$profileViews = $profileViewsStmt->fetch()['profile_views'] ?? 0;

// Real Average Response Time
$avgTimeStmt = $pdo->prepare(
    "SELECT ROUND(AVG(TIMESTAMPDIFF(MINUTE, created_at, responded_at)), 0) as avg_minutes
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

// Real Average Rating & Total Reviews
$ratingStmt = $pdo->prepare(
    "SELECT ROUND(AVG(rating), 1) as avg_rating, COUNT(*) as total_reviews
     FROM seller_reviews
     WHERE seller_id = ?"
);
$ratingStmt->execute([$userId]);
$ratingData = $ratingStmt->fetch();
$avgRating = $ratingData['avg_rating'] ?? 0;
$totalReviews = $ratingData['total_reviews'] ?? 0;
```

## PRIORITY 2: Implement Review Display in seller.php Profile Section

### Current Code (Around line 523):

```php
// HARDCODED REVIEWS - REPLACE WITH REAL DATA
<div class="review-card-display">
    <div class="review-header-section">
        <div>
            <div class="review-author-name">John Smith</div>
            <div class="review-rating-stars">⭐⭐⭐⭐⭐</div>
        </div>
        <small style="color: var(--gray);">2 days ago</small>
    </div>
    ...
</div>
```

### Replace With:

```php
<?php
// Fetch real reviews from database
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
?>

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
                <button class="action-btn primary"
                        onclick="showReplyForm(<?php echo $review['id']; ?>)">
                    💬 Reply to Review
                </button>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>
```

## PRIORITY 3: Update Profile Details Form Handling

### Current Code (Lines 17-30):

```php
// Only updates full_name, tagline, bio, etc in POST handler
$tagline = $_POST['tagline'] ?? '';
$bio = $_POST['bio'] ?? '';
$skills = $_POST['skills'] ?? '';
$availability = $_POST['availability'] ?? 'available';

if (!empty($fullName)) {
    $updateStmt = $pdo->prepare(
        "UPDATE users SET full_name = ? WHERE id = ?"
    );
    // ...
}
```

### Update to Save All Fields:

```php
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
        $updateStmt->execute([
            $fullName,
            $tagline,
            $bio,
            $skills,
            $availability,
            $userId
        ]);
        $_SESSION['name'] = $fullName;
        $success = "Profile updated successfully!";
    }
}
```

## PRIORITY 4: Display Real Profile Data

### In Profile Header (Around line 399):

```php
<?php
// Fetch current profile data
$currentProfile = $profile; // Already fetched at top
?>

<div class="profile-tagline-display">
    <?php echo !empty($currentProfile['tagline'])
        ? htmlspecialchars($currentProfile['tagline'])
        : 'Professional Freelancer • Expert in Quality Delivery';
    ?>
</div>
```

## PRIORITY 5: Implement Profile View Tracking

When someone views a seller profile (create a public seller profile page):

```php
// On page that displays seller's public profile
if (isset($_GET['seller_id']) && $_SESSION['role'] === 'buyer') {
    // Track the view
    $viewStmt = $pdo->prepare(
        "INSERT INTO profile_views (profile_user_id, viewer_user_id)
         VALUES (?, ?)"
    );
    $viewStmt->execute([$_GET['seller_id'], $_SESSION['user_id']]);

    // Increment view counter on users table
    $incrementStmt = $pdo->prepare(
        "UPDATE users SET profile_views = profile_views + 1
         WHERE id = ?"
    );
    $incrementStmt->execute([$_GET['seller_id']]);
}
```

## Testing Queries

### View seller_performance view

```sql
SELECT * FROM seller_performance WHERE id = 1;
```

### Check reviews for a seller

```sql
SELECT * FROM seller_reviews WHERE seller_id = 1;
```

### Check profile views

```sql
SELECT COUNT(*) as total_views FROM profile_views WHERE profile_user_id = 1;
```

### Check services

```sql
SELECT * FROM seller_services WHERE seller_id = 1 AND status = 'active';
```

---

## Implementation Checklist

- [ ] Update seller.php with real response_rate query (CRITICAL)
- [ ] Update seller.php with real profileViews from users table (CRITICAL)
- [ ] Update seller.php with real avgResponseTime calculation (CRITICAL)
- [ ] Add real reviews display in profile section (CRITICAL)
- [ ] Update profile update form to save all fields (IMPORTANT)
- [ ] Display current profile data from database (IMPORTANT)
- [ ] Implement profile view tracking (MEDIUM)
- [ ] Add review reply functionality (MEDIUM)
- [ ] Update buyer.php with real spending data (MEDIUM)
- [ ] Add file upload for profile/cover photos (LOW - Enhancement)

---

## Files to Modify

1. `/var/www/jacob.com/dashboard/seller.php` - PRIMARY
2. `/var/www/jacob.com/dashboard/seller_profile.php` - Reviews section
3. `/var/www/jacob.com/dashboard/buyer.php` - Spending breakdown
4. Create `/var/www/jacob.com/dashboard/seller_public_profile.php` - NEW

---

This guide provides exact code snippets to replace hardcoded values with real database queries.
