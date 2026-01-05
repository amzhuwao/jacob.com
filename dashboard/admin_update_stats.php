<?php

/**
 * Manual Statistics Update Endpoint
 * Admin-only endpoint to manually trigger statistics aggregation
 */

require_once __DIR__ . "/../includes/auth.php";
require_once __DIR__ . "/../config/database.php";

// Verify admin role
if ($_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Admin access required']);
    exit;
}

// Allow GET or POST
if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    $start_time = microtime(true);
    $updated_count = 0;

    // Get all sellers
    $sellersStmt = $pdo->prepare("SELECT id FROM users WHERE role = 'seller'");
    $sellersStmt->execute();
    $sellers = $sellersStmt->fetchAll();

    foreach ($sellers as $seller) {
        $seller_id = $seller['id'];

        // 1. Total completed projects
        $projectsStmt = $pdo->prepare(
            "SELECT COUNT(DISTINCT b.id) as total
             FROM bids b
             JOIN escrow e ON b.project_id = e.project_id
             WHERE b.seller_id = ? AND b.status = 'accepted' AND e.status IN ('released', 'completed')"
        );
        $projectsStmt->execute([$seller_id]);
        $total_projects = $projectsStmt->fetch()['total'] ?? 0;

        // 2. Total earnings
        $earningsStmt = $pdo->prepare(
            "SELECT COALESCE(SUM(e.amount), 0) as total
             FROM escrow e
             WHERE e.seller_id = ? AND e.status IN ('released', 'completed')"
        );
        $earningsStmt->execute([$seller_id]);
        $total_earnings = $earningsStmt->fetch()['total'] ?? 0;

        // 3. Average rating and total reviews
        $ratingStmt = $pdo->prepare(
            "SELECT ROUND(AVG(rating), 2) as avg_rating, COUNT(*) as total_reviews
             FROM seller_reviews
             WHERE seller_id = ?"
        );
        $ratingStmt->execute([$seller_id]);
        $rating_data = $ratingStmt->fetch();
        $average_rating = $rating_data['avg_rating'] ?? 0;
        $total_reviews = $rating_data['total_reviews'] ?? 0;

        // 4. Response rate (last 30 days)
        $responseStmt = $pdo->prepare(
            "SELECT 
                ROUND(COALESCE(COUNT(CASE WHEN responded_at IS NOT NULL THEN 1 END), 0) / 
                      NULLIF(COUNT(*), 0) * 100, 0) as response_rate
             FROM bids
             WHERE seller_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );
        $responseStmt->execute([$seller_id]);
        $response_rate = (int)($responseStmt->fetch()['response_rate'] ?? 0);

        // 5. Average response time in minutes
        $timeStmt = $pdo->prepare(
            "SELECT ROUND(AVG(TIMESTAMPDIFF(MINUTE, created_at, responded_at)), 0) as avg_minutes
             FROM bids
             WHERE seller_id = ? AND responded_at IS NOT NULL"
        );
        $timeStmt->execute([$seller_id]);
        $avg_response_time = (int)($timeStmt->fetch()['avg_minutes'] ?? 0);

        // 6. Profile views
        $viewsStmt = $pdo->prepare("SELECT profile_views FROM users WHERE id = ?");
        $viewsStmt->execute([$seller_id]);
        $profile_views = $viewsStmt->fetch()['profile_views'] ?? 0;

        // Update or insert
        $upsertStmt = $pdo->prepare(
            "INSERT INTO user_statistics 
                (user_id, total_projects_completed, total_earnings, average_rating, 
                 total_reviews, response_rate, average_response_time_minutes, profile_views, last_updated)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                total_projects_completed = ?,
                total_earnings = ?,
                average_rating = ?,
                total_reviews = ?,
                response_rate = ?,
                average_response_time_minutes = ?,
                profile_views = ?,
                last_updated = NOW()"
        );

        $upsertStmt->execute([
            $seller_id,
            $total_projects,
            $total_earnings,
            $average_rating,
            $total_reviews,
            $response_rate,
            $avg_response_time,
            $profile_views,
            $total_projects,
            $total_earnings,
            $average_rating,
            $total_reviews,
            $response_rate,
            $avg_response_time,
            $profile_views
        ]);

        $updated_count++;
    }

    $elapsed_time = microtime(true) - $start_time;

    echo json_encode([
        'success' => true,
        'message' => "Successfully updated statistics for $updated_count sellers",
        'sellers_processed' => $updated_count,
        'execution_time_seconds' => round($elapsed_time, 2)
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error updating statistics: ' . $e->getMessage()
    ]);
}
