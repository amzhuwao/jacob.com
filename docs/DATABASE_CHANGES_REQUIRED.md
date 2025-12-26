# Database Schema Changes Required

## Summary

Based on the new interface redesigns (Seller Dashboard with Profile, Buyer Dashboard, Post Project page), the following database changes are needed to replace hardcoded placeholder data with real metrics.

---

## 1. USERS TABLE - Additional Columns for Seller Profiles

### Current Columns

- id, full_name, email, password, role, created_at

### New Columns to Add

```sql
ALTER TABLE users ADD COLUMN tagline VARCHAR(200) AFTER full_name;
ALTER TABLE users ADD COLUMN bio TEXT AFTER tagline;
ALTER TABLE users ADD COLUMN skills VARCHAR(500) AFTER bio;
ALTER TABLE users ADD COLUMN profile_picture_url VARCHAR(500) AFTER skills;
ALTER TABLE users ADD COLUMN cover_photo_url VARCHAR(500) AFTER profile_picture_url;
ALTER TABLE users ADD COLUMN availability ENUM('available', 'busy', 'away') DEFAULT 'available' AFTER cover_photo_url;
ALTER TABLE users ADD COLUMN profile_views INT DEFAULT 0 AFTER availability;
```

### Purpose

- **tagline**: Short professional summary (e.g., "Award-winning Designer with 8+ years experience")
- **bio**: Detailed biography/about section
- **skills**: Comma-separated list of skills
- **profile_picture_url**: URL to user's avatar image
- **cover_photo_url**: URL to user's cover/banner image
- **availability**: Current availability status (Available, Busy, Away)
- **profile_views**: Track number of times profile is viewed (incremented when buyers view seller profiles)

---

## 2. PROJECTS TABLE - Additional Columns

### Current Columns

- id, buyer_id, title, description, budget, status, created_at

### New Columns to Add

```sql
ALTER TABLE projects ADD COLUMN category VARCHAR(100) AFTER budget;
ALTER TABLE projects ADD COLUMN timeline ENUM('urgent', 'short', 'medium', 'flexible') AFTER category;
ALTER TABLE projects ADD COLUMN funded_at DATETIME AFTER timeline;
ALTER TABLE projects ADD COLUMN completed_at DATETIME AFTER funded_at;
```

### Purpose

- **category**: Project category (web-development, mobile-development, ui-ux, graphic-design, copywriting, marketing, data-entry, other)
- **timeline**: Project deadline urgency (urgent: 1-7 days, short: 1-4 weeks, medium: 1-3 months, flexible: no deadline)
- **funded_at**: Timestamp when escrow was funded
- **completed_at**: Timestamp when project was marked completed

---

## 3. BIDS TABLE - Additional Columns (if not exists)

### New Columns to Add

```sql
ALTER TABLE bids ADD COLUMN created_at DATETIME DEFAULT CURRENT_TIMESTAMP AFTER seller_id;
ALTER TABLE bids ADD COLUMN responded_at DATETIME AFTER created_at;
```

### Purpose

- **created_at**: When the bid was placed (needed for response rate calculation)
- **responded_at**: When the seller responded to the project (needed for response time metrics)

---

## 4. SELLER_REVIEWS TABLE - New Table for Reviews & Ratings

```sql
CREATE TABLE IF NOT EXISTS seller_reviews (
    id INT PRIMARY KEY AUTO_INCREMENT,
    seller_id INT NOT NULL,
    buyer_id INT NOT NULL,
    project_id INT NOT NULL,
    rating INT NOT NULL CHECK (rating >= 1 AND rating <= 5),
    review_text TEXT,
    reply_text TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    replied_at DATETIME,
    FOREIGN KEY (seller_id) REFERENCES users(id),
    FOREIGN KEY (buyer_id) REFERENCES users(id),
    FOREIGN KEY (project_id) REFERENCES projects(id),
    INDEX idx_seller (seller_id),
    INDEX idx_buyer (buyer_id)
);
```

### Purpose

- Store client reviews and seller replies
- Track ratings for averaging
- Enable filtering/searching by date, rating

---

## 5. USER_STATISTICS TABLE - Aggregated Metrics (Optional but Recommended)

```sql
CREATE TABLE IF NOT EXISTS user_statistics (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL UNIQUE,
    total_projects_completed INT DEFAULT 0,
    total_earnings DECIMAL(12,2) DEFAULT 0,
    average_rating DECIMAL(3,2) DEFAULT 0,
    total_reviews INT DEFAULT 0,
    response_rate INT DEFAULT 0,
    average_response_time_minutes INT DEFAULT 0,
    profile_views INT DEFAULT 0,
    last_updated DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    INDEX idx_user (user_id)
);
```

### Purpose

- Cache calculated metrics for performance
- Eliminates need for complex queries on every page load
- Easy to update via triggers or batch jobs

---

## 6. PROFILE_VIEWS TABLE - Track Profile Views (Optional)

```sql
CREATE TABLE IF NOT EXISTS profile_views (
    id INT PRIMARY KEY AUTO_INCREMENT,
    profile_user_id INT NOT NULL,
    viewer_user_id INT NOT NULL,
    viewed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (profile_user_id) REFERENCES users(id),
    FOREIGN KEY (viewer_user_id) REFERENCES users(id),
    INDEX idx_profile (profile_user_id),
    INDEX idx_viewer (viewer_user_id)
);
```

### Purpose

- Track who viewed which seller profiles
- Calculate profile_views metric
- Optional: show "X people viewed your profile" insights

---

## Mapping: Hardcoded → Real Data

### Seller Dashboard (seller.php)

| Hardcoded Value                | Source Field         | SQL Query                                                                                   |
| ------------------------------ | -------------------- | ------------------------------------------------------------------------------------------- |
| `$responseRate = 95`           | Calculated from bids | `SELECT COUNT(responded_at) / COUNT(*) * 100 FROM bids WHERE seller_id = ?`                 |
| `$profileViews = 247`          | users.profile_views  | `SELECT profile_views FROM users WHERE id = ?`                                              |
| `$avgResponseTime = "2 hours"` | Calculated from bids | `SELECT AVG(TIMESTAMPDIFF(MINUTE, created_at, responded_at)) FROM bids WHERE seller_id = ?` |
| Profile Tagline                | users.tagline        | `SELECT tagline FROM users WHERE id = ?`                                                    |
| Profile Bio                    | users.bio            | `SELECT bio FROM users WHERE id = ?`                                                        |
| Skills Cloud                   | users.skills         | `SELECT skills FROM users WHERE id = ?` (split by comma)                                    |
| Availability Badge             | users.availability   | `SELECT availability FROM users WHERE id = ?`                                               |
| Profile Strength               | Calculated           | Count non-null profile fields / total fields \* 100                                         |

### Seller Profile Tab

| Hardcoded Value | Source Field              | SQL Query                                                                   |
| --------------- | ------------------------- | --------------------------------------------------------------------------- |
| Profile Picture | users.profile_picture_url | `SELECT profile_picture_url FROM users WHERE id = ?`                        |
| Cover Photo     | users.cover_photo_url     | `SELECT cover_photo_url FROM users WHERE id = ?`                            |
| Client Reviews  | seller_reviews table      | `SELECT * FROM seller_reviews WHERE seller_id = ? ORDER BY created_at DESC` |
| Review Replies  | seller_reviews.reply_text | Fetch from reviews table                                                    |
| Services/Gigs   | Need new services table   | (See below)                                                                 |

### Buyer Dashboard (buyer.php)

| Hardcoded Value      | Source Field         | SQL Query                                                                         |
| -------------------- | -------------------- | --------------------------------------------------------------------------------- |
| Spending Breakdown   | categories + amounts | `SELECT category, SUM(budget) FROM projects WHERE buyer_id = ? GROUP BY category` |
| Favorite Freelancers | seller stats         | Already implemented in current code                                               |

---

## 7. SERVICES/GIGS TABLE (Recommended for Future)

```sql
CREATE TABLE IF NOT EXISTS seller_services (
    id INT PRIMARY KEY AUTO_INCREMENT,
    seller_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    base_price DECIMAL(10,2),
    category VARCHAR(100),
    image_url VARCHAR(500),
    rating DECIMAL(3,2) DEFAULT 0,
    num_orders INT DEFAULT 0,
    status ENUM('active', 'draft', 'paused') DEFAULT 'active',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (seller_id) REFERENCES users(id),
    INDEX idx_seller (seller_id),
    INDEX idx_status (status)
);
```

---

## Implementation Priority

### Phase 1 (Critical) - Enable Profile Updates

1. Add columns to users table (tagline, bio, skills, availability)
2. Create seller_reviews table
3. Create profile_views table (or use profile_views column)

### Phase 2 (Important) - Enable Real Metrics

1. Add columns to projects table (category, timeline, funded_at, completed_at)
2. Add columns to bids table (created_at, responded_at)
3. Create user_statistics table for caching

### Phase 3 (Enhancement)

1. Create seller_services table
2. Implement profile picture/cover photo storage (use AWS S3 or local storage)
3. Add triggers to auto-update user_statistics

---

## SQL Migration Script

```sql
-- Phase 1: User Profile Enhancements
ALTER TABLE users
ADD COLUMN tagline VARCHAR(200),
ADD COLUMN bio TEXT,
ADD COLUMN skills VARCHAR(500),
ADD COLUMN profile_picture_url VARCHAR(500),
ADD COLUMN cover_photo_url VARCHAR(500),
ADD COLUMN availability ENUM('available', 'busy', 'away') DEFAULT 'available',
ADD COLUMN profile_views INT DEFAULT 0;

-- Phase 1: Create Reviews Table
CREATE TABLE seller_reviews (
    id INT PRIMARY KEY AUTO_INCREMENT,
    seller_id INT NOT NULL,
    buyer_id INT NOT NULL,
    project_id INT NOT NULL,
    rating INT NOT NULL CHECK (rating >= 1 AND rating <= 5),
    review_text TEXT,
    reply_text TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    replied_at DATETIME,
    FOREIGN KEY (seller_id) REFERENCES users(id),
    FOREIGN KEY (buyer_id) REFERENCES users(id),
    FOREIGN KEY (project_id) REFERENCES projects(id),
    INDEX idx_seller (seller_id),
    INDEX idx_buyer (buyer_id)
);

-- Phase 1: Create Profile Views Table (Alternative: just use users.profile_views column)
CREATE TABLE profile_views (
    id INT PRIMARY KEY AUTO_INCREMENT,
    profile_user_id INT NOT NULL,
    viewer_user_id INT NOT NULL,
    viewed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (profile_user_id) REFERENCES users(id),
    FOREIGN KEY (viewer_user_id) REFERENCES users(id),
    INDEX idx_profile (profile_user_id),
    INDEX idx_viewer (viewer_user_id)
);

-- Phase 2: Project Timeline Tracking
ALTER TABLE projects
ADD COLUMN category VARCHAR(100),
ADD COLUMN timeline ENUM('urgent', 'short', 'medium', 'flexible'),
ADD COLUMN funded_at DATETIME,
ADD COLUMN completed_at DATETIME;

-- Phase 2: Bid Response Tracking
ALTER TABLE bids
ADD COLUMN created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
ADD COLUMN responded_at DATETIME;

-- Phase 2: User Statistics Cache Table
CREATE TABLE user_statistics (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL UNIQUE,
    total_projects_completed INT DEFAULT 0,
    total_earnings DECIMAL(12,2) DEFAULT 0,
    average_rating DECIMAL(3,2) DEFAULT 0,
    total_reviews INT DEFAULT 0,
    response_rate INT DEFAULT 0,
    average_response_time_minutes INT DEFAULT 0,
    profile_views INT DEFAULT 0,
    last_updated DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    INDEX idx_user (user_id)
);

-- Phase 3: Seller Services/Gigs
CREATE TABLE seller_services (
    id INT PRIMARY KEY AUTO_INCREMENT,
    seller_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    base_price DECIMAL(10,2),
    category VARCHAR(100),
    image_url VARCHAR(500),
    rating DECIMAL(3,2) DEFAULT 0,
    num_orders INT DEFAULT 0,
    status ENUM('active', 'draft', 'paused') DEFAULT 'active',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (seller_id) REFERENCES users(id),
    INDEX idx_seller (seller_id),
    INDEX idx_status (status)
);
```

---

## Updated PHP Queries After Schema Changes

### Response Rate Calculation

```php
$responseStmt = $pdo->prepare(
    "SELECT
        (COUNT(CASE WHEN responded_at IS NOT NULL THEN 1 END) / COUNT(*) * 100) as response_rate
     FROM bids
     WHERE seller_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
);
$responseStmt->execute([$userId]);
$responseRate = round($responseStmt->fetch()['response_rate'] ?? 0);
```

### Average Response Time

```php
$responseTimeStmt = $pdo->prepare(
    "SELECT
        SEC_TO_TIME(AVG(TIMESTAMPDIFF(SECOND, created_at, responded_at))) as avg_time
     FROM bids
     WHERE seller_id = ? AND responded_at IS NOT NULL"
);
$responseTimeStmt->execute([$userId]);
$avgResponseTime = $responseTimeStmt->fetch()['avg_time'] ?? "N/A";
```

### Average Rating

```php
$ratingStmt = $pdo->prepare(
    "SELECT
        ROUND(AVG(rating), 1) as avg_rating,
        COUNT(*) as total_reviews
     FROM seller_reviews
     WHERE seller_id = ?"
);
$ratingStmt->execute([$userId]);
$ratingData = $ratingStmt->fetch();
```

### Profile Views Increment (on profile visit)

```php
$viewStmt = $pdo->prepare(
    "UPDATE users SET profile_views = profile_views + 1 WHERE id = ?"
);
$viewStmt->execute([$viewedUserId]);
```

---

## Notes

1. **Image Storage**: For profile pictures and cover photos, implement cloud storage (AWS S3, Cloudinary) or local file storage with proper security
2. **Triggers**: Consider creating MySQL triggers to auto-update user_statistics when reviews or projects change
3. **Performance**: Use indexes on frequently queried columns (seller_id, buyer_id, status, created_at)
4. **Data Migration**: Once new tables are created, run batch jobs to populate initial values from existing data
