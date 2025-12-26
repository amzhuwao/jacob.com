-- ============================================
-- JACOB MARKETPLACE - CREATE MISSING TABLES
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
    ),
    review_text TEXT,
    reply_text TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    replied_at DATETIME,
    FOREIGN KEY (seller_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (buyer_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    INDEX idx_seller (seller_id),
    INDEX idx_buyer (buyer_id),
    INDEX idx_project (project_id),
    INDEX idx_created (created_at)
);
-- Create profile_views table
CREATE TABLE IF NOT EXISTS profile_views (
    id INT PRIMARY KEY AUTO_INCREMENT,
    profile_user_id INT NOT NULL,
    viewer_user_id INT NOT NULL,
    viewed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (profile_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (viewer_user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_profile (profile_user_id),
    INDEX idx_viewer (viewer_user_id),
    INDEX idx_viewed_at (viewed_at)
);
-- Create user_statistics table
CREATE TABLE IF NOT EXISTS user_statistics (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL UNIQUE,
    total_projects_completed INT DEFAULT 0,
    total_earnings DECIMAL(12, 2) DEFAULT 0,
    average_rating DECIMAL(3, 2) DEFAULT 0,
    total_reviews INT DEFAULT 0,
    response_rate INT DEFAULT 0,
    average_response_time_minutes INT DEFAULT 0,
    profile_views INT DEFAULT 0,
    last_updated DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user (user_id),
    INDEX idx_updated (last_updated)
);
-- Create seller_services table
CREATE TABLE IF NOT EXISTS seller_services (
    id INT PRIMARY KEY AUTO_INCREMENT,
    seller_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    base_price DECIMAL(10, 2),
    category VARCHAR(100),
    image_url VARCHAR(500),
    rating DECIMAL(3, 2) DEFAULT 0,
    num_orders INT DEFAULT 0,
    status ENUM('active', 'draft', 'paused') DEFAULT 'active',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (seller_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_seller (seller_id),
    INDEX idx_status (status),
    INDEX idx_category (category),
    INDEX idx_created (created_at)
);
-- Create seller_performance view
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
SELECT '✓ Migration complete: All tables created' as status;