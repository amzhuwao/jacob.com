<?php
require_once '../includes/auth.php';
require_once '../config/database.php';
requireRole('admin');

$escrow_id = isset($_GET['escrow_id']) ? (int) $_GET['escrow_id'] : 0;
$action = $_GET['action'] ?? '';

if ($escrow_id <= 0 || !in_array($action, ['release', 'hold'], true)) {
    die('Invalid request');
}

$stmt = $pdo->prepare("SELECT * FROM escrow WHERE id = ?");
$stmt->execute([$escrow_id]);
$escrow = $stmt->fetch();

if (!$escrow) {
    die('Escrow not found');
}

$newStatus = $action === 'release' ? 'released' : 'held';
$column = $action === 'release' ? 'released_at' : 'held_at';

$update = $pdo->prepare("UPDATE escrow SET status = ?, {$column} = NOW() WHERE id = ?");
$update->execute([$newStatus, $escrow_id]);

header('Location: admin_escrows.php');
exit;
