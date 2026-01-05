<?php
require_once "../includes/auth.php";
require_once "../config/database.php";
require_once "../services/EmailService.php";

if ($_SESSION['role'] !== 'buyer') {
    die(json_encode(['success' => false, 'message' => 'Access denied']));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die(json_encode(['success' => false, 'message' => 'Invalid request method']));
}

$projectId = $_POST['project_id'] ?? null;
$rating = $_POST['rating'] ?? null;
$reviewText = trim($_POST['review_text'] ?? '');

// Validate inputs
if (!$projectId || !is_numeric($projectId)) {
    die(json_encode(['success' => false, 'message' => 'Invalid project ID']));
}

if (!$rating || !is_numeric($rating) || $rating < 1 || $rating > 5) {
    die(json_encode(['success' => false, 'message' => 'Rating must be between 1 and 5 stars']));
}

if (empty($reviewText) || strlen($reviewText) < 10) {
    die(json_encode(['success' => false, 'message' => 'Review must be at least 10 characters long']));
}

if (strlen($reviewText) > 1000) {
    die(json_encode(['success' => false, 'message' => 'Review must be less than 1000 characters']));
}

try {
    // Verify the buyer owns this project
    $verifyStmt = $pdo->prepare(
        "SELECT p.id, p.buyer_id, p.status, e.seller_id, e.status as escrow_status
         FROM projects p
         LEFT JOIN escrow e ON p.id = e.project_id
         WHERE p.id = ? AND p.buyer_id = ?"
    );
    $verifyStmt->execute([$projectId, $_SESSION['user_id']]);
    $project = $verifyStmt->fetch();

    if (!$project) {
        die(json_encode(['success' => false, 'message' => 'Project not found or access denied']));
    }

    if ($project['status'] !== 'completed') {
        die(json_encode(['success' => false, 'message' => 'Can only review completed projects']));
    }

    if (!$project['seller_id']) {
        die(json_encode(['success' => false, 'message' => 'No seller found for this project']));
    }

    // Check if review already exists
    $existingStmt = $pdo->prepare(
        "SELECT id FROM seller_reviews WHERE project_id = ? AND buyer_id = ?"
    );
    $existingStmt->execute([$projectId, $_SESSION['user_id']]);

    if ($existingStmt->fetch()) {
        die(json_encode(['success' => false, 'message' => 'You have already reviewed this project']));
    }

    // Insert the review
    $insertStmt = $pdo->prepare(
        "INSERT INTO seller_reviews (seller_id, buyer_id, project_id, rating, review_text, created_at)
         VALUES (?, ?, ?, ?, ?, NOW())"
    );

    $insertStmt->execute([
        $project['seller_id'],
        $_SESSION['user_id'],
        $projectId,
        $rating,
        $reviewText
    ]);

    // Update project to mark as reviewed
    $updateStmt = $pdo->prepare(
        "UPDATE projects SET reviewed_at = NOW() WHERE id = ?"
    );
    $updateStmt->execute([$projectId]);

    // Send review notification email to seller
    try {
        $emailService = new EmailService($pdo);

        // Get buyer and seller info
        $buyerStmt = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
        $buyerStmt->execute([$_SESSION['user_id']]);
        $buyer = $buyerStmt->fetch(PDO::FETCH_ASSOC);

        $emailService->reviewSubmitted($project['seller_id'], $buyer['full_name'], $rating, $reviewText);
    } catch (Exception $e) {
        error_log("Email send failed in submit_review: " . $e->getMessage());
    }

    echo json_encode([
        'success' => true,
        'message' => 'Review submitted successfully! Thank you for your feedback.'
    ]);
} catch (Exception $e) {
    error_log("Review submission error: " . $e->getMessage());
    die(json_encode([
        'success' => false,
        'message' => 'An error occurred while submitting your review. Please try again.'
    ]));
}
