-- ============================================
-- JACOB MARKETPLACE DATABASE MIGRATION - PHASE 2 & 3
-- Date: December 17, 2025
-- Purpose: Add missing tables and columns
-- Note: Phase 1 (user table enhancements) already applied
-- ============================================
-- ============================================
-- Check if tables exist and create them
-- ============================================
-- Create seller_reviews table for client reviews and ratings
CREATE TABLE IF NOT EXISTS seller_reviews (
    id INT PRIMARY KEY AUTO_INCREMENT,
    seller_id INT NOT NULL,
    buyer_id INT NOT NULL,
    project_id INT NOT NULL,
    rating INT NOT NULL CHECK (
        rating >= 1
        AND rating <= 5
    ) COMMENT '1-5 star rating',
    review_text TEXT COMMENT 'Review from buyer',
    reply_text TEXT COMMENT 'Reply from seller',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    replied_at DATETIME,
    FOREIGN KEY (seller_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (buyer_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    INDEX idx_seller (seller_id),
    INDEX idx_buyer (buyer_id),
    INDEX idx_project (project_id),
    INDEX idx_created (created_at)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = 'Client reviews for sellers';
-- Create profile_views table to track who viewed whose profile
CREATE TABLE IF NOT EXISTS profile_views (
    id INT PRIMARY KEY AUTO_INCREMENT,
    profile_user_id INT NOT NULL COMMENT 'Seller whose profile was viewed',
    viewer_user_id INT NOT NULL COMMENT 'Buyer who viewed the profile',
    viewed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (profile_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (viewer_user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_profile (profile_user_id),
    INDEX idx_viewer (viewer_user_id),
    INDEX idx_viewed_at (viewed_at)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = 'Track profile view analytics';
-- ============================================
-- Add columns to projects table (if not exists)
-- ============================================
-- Check if columns exist before adding (using a helper approach)
SET @dbname = DATABASE();
SET @tablename = "projects";
SET @col_exists = 0;
SELECT COUNT(*) INTO @col_exists
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = @dbname
    AND TABLE_NAME = @tablename
    AND COLUMN_NAME = 'category';
SET @sql = IF(
        @col_exists = 0,
        'ALTER TABLE projects ADD COLUMN category VARCHAR(100) COMMENT "Project category (web-development, mobile-development, ui-ux, etc)"',
        'SELECT "Column category already exists"'
    );
PREPARE stmt
FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @col_exists = 0;
SELECT COUNT(*) INTO @col_exists
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = @dbname
    AND TABLE_NAME = @tablename
    AND COLUMN_NAME = 'timeline';
SET @sql = IF(
        @col_exists = 0,
        'ALTER TABLE projects ADD COLUMN timeline ENUM("urgent", "short", "medium", "flexible") DEFAULT "flexible" COMMENT "Project deadline"',
        'SELECT "Column timeline already exists"'
    );
PREPARE stmt
FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @col_exists = 0;
SELECT COUNT(*) INTO @col_exists
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = @dbname
    AND TABLE_NAME = @tablename
    AND COLUMN_NAME = 'funded_at';
SET @sql = IF(
        @col_exists = 0,
        'ALTER TABLE projects ADD COLUMN funded_at DATETIME COMMENT "When escrow was funded"',
        'SELECT "Column funded_at already exists"'
    );
PREPARE stmt
FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @col_exists = 0;
SELECT COUNT(*) INTO @col_exists
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = @dbname
    AND TABLE_NAME = @tablename
    AND COLUMN_NAME = 'completed_at';
SET @sql = IF(
        @col_exists = 0,
        'ALTER TABLE projects ADD COLUMN completed_at DATETIME COMMENT "When project was marked completed"',
        'SELECT "Column completed_at already exists"'
    );
PREPARE stmt
FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
-- ============================================
-- Add columns to bids table (if not exists)
-- ============================================
SET @tablename = "bids";
SET @col_exists = 0;
SELECT COUNT(*) INTO @col_exists
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = @dbname
    AND TABLE_NAME = @tablename
    AND COLUMN_NAME = 'responded_at';
SET @sql = IF(
        @col_exists = 0,
        'ALTER TABLE bids ADD COLUMN responded_at DATETIME COMMENT "When seller responded to project"',
        'SELECT "Column responded_at already exists"'
    );
PREPARE stmt
FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
-- ============================================
-- Create user_statistics table for cached metrics
-- ============================================
CREATE TABLE IF NOT EXISTS user_statistics (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL UNIQUE,
    total_projects_completed INT DEFAULT 0 COMMENT 'Total completed projects',
    total_earnings DECIMAL(12, 2) DEFAULT 0 COMMENT 'Total money earned',
    average_rating DECIMAL(3, 2) DEFAULT 0 COMMENT 'Average rating from reviews',
    total_reviews INT DEFAULT 0 COMMENT 'Total number of reviews',
    response_rate INT DEFAULT 0 COMMENT 'Percentage: responded bids / total bids',
    average_response_time_minutes INT DEFAULT 0 COMMENT 'Average minutes to respond to bid',
    profile_views INT DEFAULT 0 COMMENT 'Total profile views',
    last_updated DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user (user_id),
    INDEX idx_updated (last_updated)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = 'Cached user performance metrics';
-- ============================================
-- Create seller_services table for managing gigs/services
-- ============================================
CREATE TABLE IF NOT EXISTS seller_services (
    id INT PRIMARY KEY AUTO_INCREMENT,
    seller_id INT NOT NULL,
    title VARCHAR(255) NOT NULL COMMENT 'Service title',
    description TEXT COMMENT 'Service description',
    base_price DECIMAL(10, 2) COMMENT 'Starting price',
    category VARCHAR(100) COMMENT 'Service category',
    image_url VARCHAR(500) COMMENT 'Service thumbnail image',
    rating DECIMAL(3, 2) DEFAULT 0 COMMENT 'Average service rating',
    num_orders INT DEFAULT 0 COMMENT 'Number of completed orders',
    status ENUM('active', 'draft', 'paused') DEFAULT 'active' COMMENT 'Service status',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (seller_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_seller (seller_id),
    INDEX idx_status (status),
    INDEX idx_category (category),
    INDEX idx_created (created_at)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci COMMENT = 'Seller services/gigs offerings';
-- ============================================
-- Add indexes for common queries
-- ============================================
ALTER TABLE projects
ADD INDEX IF NOT EXISTS idx_buyer_status (buyer_id, status),
    ADD INDEX IF NOT EXISTS idx_category (category),
    ADD INDEX IF NOT EXISTS idx_created_at (created_at);
ALTER TABLE bids
ADD INDEX IF NOT EXISTS idx_seller_created (seller_id, created_at),
    ADD INDEX IF NOT EXISTS idx_seller_responded (seller_id, responded_at),
    ADD INDEX IF NOT EXISTS idx_project_id (project_id);
-- ============================================
-- Create helper views
-- ============================================
CREATE OR REPLACE VIEW seller_performance AS
SELECT u.id,
    u.full_name,
    COUNT(DISTINCT p.id) as completed_projects,
    COALESCE(SUM(e.amount), 0) as total_earnings,
    COALESCE(AVG(sr.rating), 0) as average_rating,
    COUNT(sr.id) as total_reviews,
    ROUND(
        COUNT(
            CASE
                WHEN b.responded_at IS NOT NULL THEN 1
            END
        ) / NULLIF(COUNT(b.id), 0) * 100,
        2
    ) as response_rate_percent,
    COALESCE(
        AVG(
            TIMESTAMPDIFF(MINUTE, b.created_at, b.responded_at)
        ),
        0
    ) as avg_response_time_minutes,
    u.profile_views
FROM users u
    LEFT JOIN bids b ON u.id = b.seller_id
    LEFT JOIN projects p ON b.project_id = p.id
    AND p.status = 'completed'
    LEFT JOIN escrow e ON p.id = e.project_id
    AND e.seller_id = u.id
    AND e.status = 'released'
    LEFT JOIN seller_reviews sr ON u.id = sr.seller_id
WHERE u.role = 'seller'
GROUP BY u.id,
    u.full_name,
    u.profile_views;
-- ============================================
-- Migration Summary
-- ============================================
SELECT '✓ seller_reviews table created' as migration_status;
SELECT '✓ profile_views table created' as migration_status;
SELECT '✓ projects table enhanced with category/timeline/funding fields' as migration_status;
SELECT '✓ bids table enhanced with responded_at field' as migration_status;
SELECT '✓ user_statistics table created' as migration_status;
SELECT '✓ seller_services table created' as migration_status;
SELECT '✓ Performance indexes added' as migration_status;
SELECT '✓ seller_performance view created' as migration_status;