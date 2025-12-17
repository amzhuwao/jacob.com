<?php
require_once "../includes/auth.php";
require_once "../config/database.php";

if ($_SESSION['role'] !== 'admin') {
    die("Access denied");
}

$stmt = $pdo->query(
    "SELECT escrow.*, projects.title
   FROM escrow
   JOIN projects ON escrow.project_id = projects.id
   WHERE escrow.status = 'funded'"
);
$rows = $stmt->fetchAll();
?>

<h2>Escrow Management</h2>

<?php foreach ($rows as $row): ?>
    <div class="box">
        <p>Project: <?= $row['title'] ?></p>
        <p>Amount: $<?= $row['amount'] ?></p>
        <a href="release_escrow.php?id=<?= $row['id'] ?>">Release</a> |
        <a href="refund_escrow.php?id=<?= $row['id'] ?>">Refund</a>
    </div>
<?php endforeach; ?>