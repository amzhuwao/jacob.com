<?php
// -----------------------------
// AUTH + DB
// -----------------------------
require_once "../includes/auth.php";
require_once "../config/database.php";

// Only sellers allowed
if ($_SESSION['role'] !== 'seller') {
    die("Access denied");
}

// -----------------------------
// LOAD OPEN PROJECTS
// -----------------------------
$stmt = $pdo->query(
    "SELECT projects.*, users.full_name
     FROM projects
     JOIN users ON projects.buyer_id = users.id
     WHERE projects.status = 'open'
     ORDER BY projects.created_at DESC"
);
$projects = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html>

<head>
    <title>Seller Dashboard</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            padding: 20px;
        }

        .box {
            border: 1px solid #ccc;
            padding: 15px;
            margin-bottom: 15px;
        }

        a {
            text-decoration: none;
            color: #28a745;
        }

        a:hover {
            text-decoration: underline;
        }
    </style>
</head>

<body>

    <h1>Seller Dashboard</h1>

    <p>
        <a href="../auth/logout.php">Logout</a>
    </p>

    <hr>

    <h2>Available Projects</h2>

    <?php if (empty($projects)): ?>
        <p>No open projects at the moment.</p>
    <?php endif; ?>

    <?php foreach ($projects as $project): ?>
        <div class="box">
            <h3>
                <a href="project_view.php?id=<?= $project['id'] ?>">
                    <?= htmlspecialchars($project['title']) ?>
                </a>
            </h3>
            <p><strong>Client:</strong> <?= htmlspecialchars($project['full_name']) ?></p>
            <p><?= nl2br(htmlspecialchars($project['description'])) ?></p>
            <p><strong>Budget:</strong> $<?= $project['budget'] ?></p>
        </div>
    <?php endforeach; ?>

</body>

</html>