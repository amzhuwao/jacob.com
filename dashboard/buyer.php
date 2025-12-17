<?php
// -----------------------------
// AUTH + DB
// -----------------------------
require_once "../includes/auth.php";
require_once "../config/database.php";

// Only buyers allowed
if ($_SESSION['role'] !== 'buyer') {
    die("Access denied");
}

// -----------------------------
// LOAD BUYER PROJECTS
// -----------------------------
$stmt = $pdo->prepare(
    "SELECT * FROM projects
     WHERE buyer_id = ?
     ORDER BY created_at DESC"
);
$stmt->execute([$_SESSION['user_id']]);
$projects = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html>

<head>
    <title>Buyer Dashboard</title>
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
            color: #007bff;
        }

        a:hover {
            text-decoration: underline;
        }
    </style>
</head>

<body>

    <h1>Buyer Dashboard</h1>

    <p>
        <a href="buyer_post_project.php">➕ Post New Project</a> |
        <a href="../auth/logout.php">Logout</a>
    </p>

    <hr>

    <h2>My Projects</h2>

    <?php if (empty($projects)): ?>
        <p>You have not posted any projects yet.</p>
    <?php endif; ?>

    <?php foreach ($projects as $project): ?>
        <div class="box">
            <h3>
                <a href="project_view.php?id=<?= $project['id'] ?>">
                    <?= htmlspecialchars($project['title']) ?>
                </a>
            </h3>
            <p><?= nl2br(htmlspecialchars($project['description'])) ?></p>
            <p><strong>Budget:</strong> $<?= $project['budget'] ?></p>
            <p><strong>Status:</strong> <?= ucfirst($project['status']) ?></p>
        </div>
    <?php endforeach; ?>

</body>

</html>