<?php
// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

// -----------------------------
// 1. AUTH + DB
// -----------------------------
require_once "../includes/auth.php";
require_once "../config/database.php";

// -----------------------------
// 2. LOAD PROJECT
// -----------------------------
$project_id = $_GET['id'] ?? null;

if (!$project_id) {
    die("Invalid project");
}

$stmt = $pdo->prepare(
    "SELECT projects.*, users.full_name AS buyer_name
     FROM projects
     JOIN users ON projects.buyer_id = users.id
     WHERE projects.id = ?"
);
$stmt->execute([$project_id]);
$project = $stmt->fetch();

if (!$project) {
    die("Project not found");
}

// -----------------------------
// 3. HANDLE BID SUBMISSION (SELLER)
// -----------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_SESSION['role'] === 'seller') {

    // Prevent duplicate bids by same seller
    $check = $pdo->prepare(
        "SELECT id FROM bids WHERE project_id = ? AND seller_id = ?"
    );
    $check->execute([$project_id, $_SESSION['user_id']]);

    if ($check->rowCount() === 0) {
        $stmt = $pdo->prepare(
            "INSERT INTO bids (project_id, seller_id, amount, message)
             VALUES (?, ?, ?, ?)"
        );
        $stmt->execute([
            $project_id,
            $_SESSION['user_id'],
            $_POST['amount'],
            $_POST['message']
        ]);
    }

    header("Location: project_view.php?id=" . $project_id);
    exit;
}

// -----------------------------
// 4. LOAD BIDS (FOR BUYER VIEW)
// -----------------------------
$stmt = $pdo->prepare(
    "SELECT bids.*, users.full_name
     FROM bids
     JOIN users ON bids.seller_id = users.id
     WHERE bids.project_id = ?
     ORDER BY bids.created_at ASC"
);
$stmt->execute([$project_id]);
$bids = $stmt->fetchAll();

// -----------------------------
// 5. ACCEPTED BID + ESCROW STATUS
// -----------------------------
$acceptedStmt = $pdo->prepare(
    "SELECT bids.*, users.full_name AS seller_name
     FROM bids
     JOIN users ON bids.seller_id = users.id
     WHERE bids.project_id = ? AND bids.status = 'accepted'
     LIMIT 1"
);
$acceptedStmt->execute([$project_id]);
$acceptedBid = $acceptedStmt->fetch();

$escrowStmt = $pdo->prepare(
    "SELECT * FROM escrow WHERE project_id = ? LIMIT 1"
);
$escrowStmt->execute([$project_id]);
$escrow = $escrowStmt->fetch();
?>

<!DOCTYPE html>
<html>

<head>
    <title>Project View</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            padding: 20px;
        }

        .box {
            border: 1px solid #ccc;
            padding: 15px;
            margin-bottom: 20px;
        }

        .bid {
            background: #f9f9f9;
            padding: 10px;
            margin-bottom: 10px;
        }

        .accepted {
            background: #d4edda;
        }

        .rejected {
            background: #f8d7da;
        }
    </style>
</head>

<body>

    <!-- -----------------------------
     PROJECT DETAILS
-------------------------------->
    <div class="box">
        <h2><?= htmlspecialchars($project['title']) ?></h2>
        <p><strong>Client:</strong> <?= htmlspecialchars($project['buyer_name']) ?></p>
        <p><?= nl2br(htmlspecialchars($project['description'])) ?></p>
        <p><strong>Budget:</strong> $<?= $project['budget'] ?></p>
        <p><strong>Status:</strong> <?= $project['status'] ?></p>
    </div>

    <!-- -----------------------------
     SELLER BID FORM
-------------------------------->
    <?php if ($_SESSION['role'] === 'seller' && $project['status'] === 'open'): ?>
        <div class="box">
            <h3>Submit a Bid</h3>
            <form method="POST">
                <input type="number" name="amount" step="0.01" placeholder="Bid amount" required><br><br>
                <textarea name="message" placeholder="Optional message"></textarea><br><br>
                <button type="submit">Submit Bid</button>
            </form>
        </div>
    <?php endif; ?>

    <!-- -----------------------------
     BUYER VIEW BIDS
-------------------------------->
    <?php if ($_SESSION['role'] === 'buyer'): ?>
        <div class="box">
            <h3>Bids Received</h3>

            <?php if (empty($bids)): ?>
                <p>No bids yet.</p>
            <?php endif; ?>

            <?php foreach ($bids as $bid): ?>
                <div class="bid <?= $bid['status'] ?>">
                    <strong><?= htmlspecialchars($bid['full_name']) ?></strong><br>
                    Amount: $<?= $bid['amount'] ?><br>
                    <?= nl2br(htmlspecialchars($bid['message'])) ?><br><br>

                    <?php if ($bid['status'] === 'pending' && $project['status'] === 'open'): ?>
                        <a href="accept_bid.php?bid_id=<?= $bid['id'] ?>">Accept Bid</a>
                    <?php else: ?>
                        <em>Status: <?= ucfirst($bid['status']) ?></em>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif;


    $stmt = $pdo->prepare(
        "SELECT * FROM escrow WHERE project_id = ?"
    );
    $stmt->execute([$project_id]);
    $escrow = $stmt->fetch();
    ?>

    <?php if ($_SESSION['role'] === 'buyer' && $escrow && $escrow['status'] === 'pending'): ?>
        <div class="box">
            <h3>Fund Escrow</h3>
            <p>Amount: $<?= $escrow['amount'] ?></p>
            <form method="POST" action="fund_escrow.php">
                <input type="hidden" name="escrow_id" value="<?= $escrow['id'] ?>">
                <button type="submit">Fund Escrow (Prototype)</button>
            </form>
        </div>
    <?php endif; ?>
    <?php if ($acceptedBid): ?>
        <div class="box">
            <h3>Accepted Bid</h3>
            <p><strong>Seller:</strong> <?= htmlspecialchars($acceptedBid['seller_name']) ?></p>
            <p><strong>Amount:</strong> $<?= $acceptedBid['amount'] ?></p>
            <p><strong>Status:</strong> <?= $acceptedBid['status'] ?></p>
        </div>
    <?php endif; ?>
    <?php if ($_SESSION['role'] === 'seller' && $escrow): ?>
        <div class="box">
            <h3>Escrow Status</h3>
            <p>Status: <?= ucfirst($escrow['status']) ?></p>
            <p>Amount: $<?= $escrow['amount'] ?></p>
        </div>
    <?php endif; ?>

</body>

</html>