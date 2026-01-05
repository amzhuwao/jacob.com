<?php
require_once "../includes/auth.php";
require_once "../config/database.php";
require_once "../services/EmailService.php";

if ($_SESSION['role'] !== 'admin') {
    die("Access denied");
}

$id = $_GET['id'];

try {
    $pdo->beginTransaction();

    // Get escrow details before updating
    $escrowStmt = $pdo->prepare("SELECT * FROM escrow WHERE id = ?");
    $escrowStmt->execute([$id]);
    $escrow = $escrowStmt->fetch(PDO::FETCH_ASSOC);

    if (!$escrow) {
        throw new Exception("Escrow not found");
    }

    // Update escrow
    $pdo->prepare(
        "UPDATE escrow SET status = 'released' WHERE id = ?"
    )->execute([$id]);

    // Update project
    $pdo->prepare(
        "UPDATE projects SET status = 'completed'
        WHERE id = ?"
    )->execute([$escrow['project_id']]);

    $pdo->commit();

    // Send emails
    try {
        $emailService = new EmailService($pdo);

        // Get project and user info
        $projectStmt = $pdo->prepare("SELECT title, buyer_id FROM projects WHERE id = ?");
        $projectStmt->execute([$escrow['project_id']]);
        $project = $projectStmt->fetch(PDO::FETCH_ASSOC);

        $sellerStmt = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
        $sellerStmt->execute([$escrow['seller_id']]);
        $seller = $sellerStmt->fetch(PDO::FETCH_ASSOC);

        $buyerStmt = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
        $buyerStmt->execute([$escrow['buyer_id']]);
        $buyer = $buyerStmt->fetch(PDO::FETCH_ASSOC);

        // Send email to seller about payment
        $emailService->escrowReleased($escrow['seller_id'], $escrow['project_id'], $project['title'], $escrow['amount']);

        // Send email to buyer about completion
        $emailService->escrowReleasedBuyer($escrow['buyer_id'], $escrow['project_id'], $project['title'], $seller['full_name']);
    } catch (Exception $e) {
        error_log("Email send failed in release_escrow: " . $e->getMessage());
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    die("Error releasing escrow: " . $e->getMessage());
}

header("Location: admin_escrow.php");
exit;
