<?php
require_once "../includes/auth.php";
require_once "../config/database.php";

if ($_SESSION['role'] !== 'admin') {
    die("Access denied");
}

$id = $_GET['id'];

$pdo->prepare(
    "UPDATE escrow SET status = 'released' WHERE id = ?"
)->execute([$id]);

$pdo->prepare(
    "UPDATE projects SET status = 'completed'
   WHERE id = (SELECT project_id FROM escrow WHERE id = ?)"
)->execute([$id]);

header("Location: admin_escrow.php");
exit;
