<?php

/**
 * User Statistics Aggregation Cron Job
 * 
 * This script runs periodically (recommended: daily via cron) to update the user_statistics
 * table with current performance metrics. This caches frequently accessed data for better
 * dashboard performance.
 * 
 * Setup cron job:
 * 0 0 * * * /usr/bin/php /var/www/jacob.com/scripts/update_user_statistics.php >> /var/log/user_stats_cron.log 2>&1
 * 
 * This will run at 00:00 (midnight) every day
 */

require_once __DIR__ . "/../config/database.php";

// Logging function
function log_message($message)
{
    $timestamp = date('Y-m-d H:i:s');
    $log_message = "[$timestamp] $message\n";
    echo $log_message;
    error_log($log_message, 3, '/var/log/user_stats_cron.log');
}

try {
    log_message("Starting user statistics aggregation...");

    // Get all sellers
    $sellersStmt = $pdo->prepare("SELECT id FROM users WHERE role = 'seller'");
    $sellersStmt->execute();
    $sellers = $sellersStmt->fetchAll();

    log_message("Found " . count($sellers) . " sellers to process");

    $updated_count = 0;
    $errors = [];

    foreach ($sellers as $seller) {
        $seller_id = $seller['id'];

        try {
            // 1. Total completed projects
            $projectsStmt = $pdo->prepare(
                "SELECT COUNT(DISTINCT b.id) as total
                 FROM bids b
                 JOIN escrow e ON b.project_id = e.project_id
                 WHERE b.seller_id = ? AND b.status = 'accepted' AND e.status IN ('released', 'completed')"
            );
            $projectsStmt->execute([$seller_id]);
            $total_projects = $projectsStmt->fetch()['total'] ?? 0;

            // 2. Total earnings (from released escrows)
            $earningsStmt = $pdo->prepare(
                "SELECT COALESCE(SUM(e.amount), 0) as total
                 FROM escrow e
                 WHERE e.seller_id = ? AND e.status IN ('released', 'completed')"
            );
            $earningsStmt->execute([$seller_id]);
            $total_earnings = $earningsStmt->fetch()['total'] ?? 0;

            // 3. Average rating from seller reviews
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

            // 5. Average response time (in minutes)
            $timeStmt = $pdo->prepare(
                "SELECT ROUND(AVG(TIMESTAMPDIFF(MINUTE, created_at, responded_at)), 0) as avg_minutes
                 FROM bids
                 WHERE seller_id = ? AND responded_at IS NOT NULL"
            );
            $timeStmt->execute([$seller_id]);
            $avg_response_time = (int)($timeStmt->fetch()['avg_minutes'] ?? 0);

            // 6. Profile views (already stored in users table, just fetch it)
            $viewsStmt = $pdo->prepare("SELECT profile_views FROM users WHERE id = ?");
            $viewsStmt->execute([$seller_id]);
            $profile_views = $viewsStmt->fetch()['profile_views'] ?? 0;

            // Update or insert into user_statistics
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
                // Duplicate key update values
                $total_projects,
                $total_earnings,
                $average_rating,
                $total_reviews,
                $response_rate,
                $avg_response_time,
                $profile_views
            ]);

            $updated_count++;
        } catch (Exception $e) {
            $error_msg = "Error processing seller $seller_id: " . $e->getMessage();
            log_message("ERROR: $error_msg");
            $errors[] = $error_msg;
        }
    }

    log_message("Successfully updated $updated_count seller statistics");

    if (!empty($errors)) {
        log_message("Completed with " . count($errors) . " errors");
    }

    log_message("User statistics aggregation completed successfully");
} catch (Exception $e) {
    $error_msg = "Critical error in user statistics aggregation: " . $e->getMessage();
    log_message("CRITICAL ERROR: $error_msg");
    exit(1);
}

exit(0);
