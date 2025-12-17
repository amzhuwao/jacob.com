<?php
require_once "../includes/auth.php";
require_once "../config/database.php";
include "../includes/header.php";

if ($_SESSION['role'] !== 'buyer') {
    die("Access denied");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = $pdo->prepare(
        "INSERT INTO projects (buyer_id, title, description, budget)
     VALUES (?, ?, ?, ?)"
    );

    $stmt->execute([
        $_SESSION['user_id'],
        $_POST['title'],
        $_POST['description'],
        $_POST['budget']
    ]);

    header("Location: buyer.php");
    exit;
}
?>


<form method="POST">
    <input type="text" name="title" placeholder="Project title" required>
    <textarea name="description" placeholder="Project description" required></textarea>
    <input type="number" name="budget" step="0.01" placeholder="Budget">
    <button type="submit">Post Project</button>
</form>