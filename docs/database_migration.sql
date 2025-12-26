-- ============================================
-- JACOB MARKETPLACE DATABASE MIGRATION
-- Date: December 17, 2025
-- Purpose: Add columns and tables to support new UI features
-- ============================================
-- ============================================
-- PHASE 1: USER PROFILE ENHANCEMENTS
-- ============================================
-- Add seller profile columns to users table
ALTER TABLE users
ADD COLUMN tagline VARCHAR(200) COMMENT 'Professional tagline/summary',
    ADD COLUMN bio TEXT COMMENT 'Detailed biography',
    ADD COLUMN skills VARCHAR(500) COMMENT 'Comma-separated list of skills',
    ADD COLUMN profile_picture_url VARCHAR(500) COMMENT 'URL to profile picture',
    ADD COLUMN cover_photo_url VARCHAR(500) COMMENT 'URL to cover photo',
    ADD COLUMN availability ENUM('available', 'busy', 'away') DEFAULT 'available' COMMENT 'Current availability status',
    ADD COLUMN profile_views INT DEFAULT 0 COMMENT 'Number of profile views';
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
) COMMENT = 'Client reviews for sellers';
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
    INDEX idx_viewed_at (viewed_at),
    UNIQUE KEY unique_view (profile_user_id, viewer_user_id, viewed_at)
) COMMENT = 'Track profile view analytics';
-- ============================================
-- PHASE 2: PROJECT TIMELINE & CATEGORY TRACKING
-- ============================================
-- Add columns to projects table for category and timeline
ALTER TABLE projects
ADD COLUMN category VARCHAR(100) COMMENT 'Project category (web-development, mobile-development, ui-ux, etc)',
    ADD COLUMN timeline ENUM('urgent', 'short', 'medium', 'flexible') DEFAULT 'flexible' COMMENT 'Project deadline: urgent (1-7d), short (1-4w), medium (1-3m), flexible',
    ADD COLUMN funded_at DATETIME COMMENT 'Timestamp when escrow was funded',
    ADD COLUMN completed_at DATETIME COMMENT 'Timestamp when project was marked completed';
-- Add response tracking to bids table
ALTER TABLE bids
ADD COLUMN responded_at DATETIME COMMENT 'When seller responded to project';
-- ============================================
-- PHASE 2: USER STATISTICS CACHE TABLE
-- ============================================
-- Create user_statistics table for cached metrics
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
) COMMENT = 'Cached user performance metrics';
-- ============================================
-- PHASE 3: SELLER SERVICES/GIGS TABLE
-- ============================================
-- Create seller_services table for managing gigs/services
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
) COMMENT = 'Seller services/gigs offerings';
-- ============================================
-- HELPER VIEWS (Optional but useful)
-- ============================================
-- Create view for seller performance metrics
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
        ) / COUNT(b.id) * 100,
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
-- INDEX OPTIMIZATION
-- ============================================
-- Add indexes for common queries
ALTER TABLE projects
ADD INDEX idx_buyer_status (buyer_id, status),
    ADD INDEX idx_seller_status (buyer_id, status),
    ADD INDEX idx_category (category),
    ADD INDEX idx_created_at (created_at);
ALTER TABLE bids
ADD INDEX idx_seller_created (seller_id, created_at),
    ADD INDEX idx_seller_responded (seller_id, responded_at),
    ADD INDEX idx_project_id (project_id);
ALTER TABLE escrow
ADD INDEX idx_seller_status (seller_id, status),
    ADD INDEX idx_project_id (project_id),
    ADD INDEX idx_funded_at (funded_at);
-- ============================================
-- MIGRATION COMPLETE
-- ============================================
-- Verify the migration
SELECT '✓ Users table enhanced' as status;
SELECT '✓ seller_reviews table created' as status;
SELECT '✓ profile_views table created' as status;
SELECT '✓ projects table enhanced' as status;
SELECT '✓ bids table enhanced' as status;
SELECT '✓ user_statistics table created' as status;
SELECT '✓ seller_services table created' as status;
SELECT '✓ Indexes optimized' as status;