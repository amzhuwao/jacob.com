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
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 40px 20px;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        .project-header {
            background: white;
            border-radius: 12px;
            padding: 40px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        }

        .project-header h1 {
            font-size: 2.5em;
            color: #2d3748;
            margin-bottom: 15px;
            word-break: break-word;
        }

        .project-meta {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }

        .meta-item {
            padding: 15px;
            background: #f7fafc;
            border-left: 4px solid #667eea;
            border-radius: 6px;
        }

        .meta-item label {
            display: block;
            font-size: 0.9em;
            color: #718096;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }

        .meta-item .value {
            font-size: 1.3em;
            font-weight: 600;
            color: #2d3748;
        }

        .budget-tag {
            display: inline-block;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 8px 16px;
            border-radius: 20px;
            font-weight: 600;
        }

        .status-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.9em;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-open {
            background: #c6f6d5;
            color: #22543d;
        }

        .status-in-progress {
            background: #bee3f8;
            color: #2c5282;
        }

        .status-completed {
            background: #d4edda;
            color: #155724;
        }

        .description-section {
            background: white;
            border-radius: 12px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        }

        .description-section h2 {
            font-size: 1.5em;
            color: #2d3748;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e2e8f0;
        }

        .description-text {
            color: #4a5568;
            line-height: 1.8;
            font-size: 1.05em;
        }

        .grid-2col {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
        }

        @media (max-width: 1024px) {
            .grid-2col {
                grid-template-columns: 1fr;
            }
        }

        .sidebar-cards-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 20px;
        }

        @media (max-width: 768px) {
            .sidebar-cards-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Bid Form Styles */
        .bid-form-section {
            background: white;
            border-radius: 12px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        }

        .bid-form-section h3 {
            font-size: 1.5em;
            color: #2d3748;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e2e8f0;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 8px;
        }

        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-family: inherit;
            font-size: 1em;
            transition: all 0.3s ease;
        }

        .form-group input:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .form-group textarea {
            min-height: 120px;
            resize: vertical;
        }

        .btn {
            padding: 12px 30px;
            border: none;
            border-radius: 8px;
            font-size: 1em;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
            text-align: center;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(102, 126, 234, 0.4);
        }

        .btn-secondary {
            background: #e2e8f0;
            color: #2d3748;
        }

        .btn-secondary:hover {
            background: #cbd5e0;
            transform: translateY(-2px);
        }

        /* Bids Section */
        .bids-section {
            background: white;
            border-radius: 12px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        }

        .bids-section h3 {
            font-size: 1.5em;
            color: #2d3748;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e2e8f0;
        }

        .bid-card {
            padding: 20px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            margin-bottom: 15px;
            transition: all 0.3s ease;
        }

        .bid-card:hover {
            border-color: #667eea;
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.1);
        }

        .bid-card.pending {
            border-left: 5px solid #f6ad55;
        }

        .bid-card.accepted {
            border-left: 5px solid #48bb78;
            background: #f0fff4;
        }

        .bid-card.rejected {
            border-left: 5px solid #f56565;
            background: #fff5f5;
        }

        .bid-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .bid-seller-name {
            font-size: 1.2em;
            font-weight: 700;
            color: #2d3748;
        }

        .bid-amount {
            font-size: 1.4em;
            font-weight: 700;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .bid-message {
            color: #4a5568;
            margin-bottom: 15px;
            line-height: 1.6;
            padding: 15px;
            background: rgba(226, 232, 240, 0.3);
            border-radius: 6px;
        }

        .bid-actions {
            display: flex;
            gap: 10px;
        }

        .bid-actions a {
            padding: 8px 16px;
            border-radius: 6px;
            font-weight: 600;
            text-decoration: none;
            font-size: 0.95em;
            transition: all 0.3s ease;
        }

        .bid-accept {
            background: #c6f6d5;
            color: #22543d;
        }

        .bid-accept:hover {
            background: #9ae6b4;
        }

        .bid-status {
            color: #718096;
            font-size: 0.95em;
            font-style: italic;
        }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #718096;
        }

        .empty-state-icon {
            font-size: 3em;
            margin-bottom: 15px;
        }

        /* Escrow Section */
        .escrow-section {
            background: white;
            border-radius: 12px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        }

        .escrow-section h3 {
            font-size: 1.5em;
            color: #2d3748;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e2e8f0;
        }

        .escrow-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }

        .escrow-item {
            padding: 15px;
            background: #f7fafc;
            border-radius: 8px;
            border-left: 4px solid #667eea;
        }

        .escrow-item label {
            display: block;
            font-size: 0.85em;
            color: #718096;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }

        .escrow-item .value {
            font-size: 1.3em;
            font-weight: 700;
            color: #2d3748;
        }

        .no-bids {
            grid-column: 1 / -1;
            text-align: center;
            padding: 40px 20px;
            color: #718096;
            font-style: italic;
        }

        .back-button {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            background: rgba(255, 255, 255, 0.9);
            color: #2d3748;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            margin-bottom: 20px;
            transition: all 0.3s ease;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .back-button:hover {
            background: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        .back-button::before {
            content: '←';
            font-size: 1.2em;
        }
    </style>
</head>

<body>
    <div class="container">
        <!-- BACK BUTTON -->
        <a href="<?= $_SESSION['role'] === 'buyer' ? 'buyer.php' : 'seller.php' ?>" class="back-button">Back to Dashboard</a>

        <!-- PROJECT HEADER -->
        <div class="project-header">
            <h1><?= htmlspecialchars($project['title']) ?></h1>

            <div class="project-meta">
                <div class="meta-item">
                    <label>Client</label>
                    <div class="value"><?= htmlspecialchars($project['buyer_name']) ?></div>
                </div>
                <div class="meta-item">
                    <label>Budget</label>
                    <div class="value"><span class="budget-tag">$<?= number_format($project['budget'], 2) ?></span></div>
                </div>
                <div class="meta-item">
                    <label>Status</label>
                    <div class="value"><span class="status-badge status-<?= str_replace(' ', '-', strtolower($project['status'])) ?>"><?= ucfirst($project['status']) ?></span></div>
                </div>
            </div>
        </div>

        <!-- PROJECT DESCRIPTION -->
        <div class="description-section">
            <h2>📋 Project Details</h2>
            <div class="description-text">
                <?= nl2br(htmlspecialchars($project['description'])) ?>
            </div>
        </div>

        <!-- MAIN CONTENT GRID -->
        <div class="grid-2col">
            <div>
                <!-- SELLER BID FORM -->
                <?php if ($_SESSION['role'] === 'seller' && $project['status'] === 'open'): ?>
                    <div class="bid-form-section">
                        <h3>💼 Submit Your Bid</h3>
                        <form method="POST">
                            <div class="form-group">
                                <label for="amount">Bid Amount (USD)</label>
                                <input type="number" id="amount" name="amount" step="0.01" placeholder="Enter your bid amount" required>
                            </div>

                            <div class="form-group">
                                <label for="message">Cover Letter (Optional)</label>
                                <textarea id="message" name="message" placeholder="Tell the client why you're the best fit for this project..."></textarea>
                            </div>

                            <button type="submit" class="btn btn-primary">Submit Bid</button>
                        </form>
                    </div>
                <?php endif; ?>

                <!-- BUYER VIEW BIDS -->
                <?php if ($_SESSION['role'] === 'buyer'): ?>
                    <div class="bids-section">
                        <h3>💰 Bids Received (<?= count($bids) ?>)</h3>

                        <?php if (empty($bids)): ?>
                            <div class="empty-state">
                                <div class="empty-state-icon">📭</div>
                                <p>No bids yet. Check back later or share your project for more visibility.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($bids as $bid): ?>
                                <div class="bid-card <?= $bid['status'] ?>">
                                    <div class="bid-header">
                                        <div class="bid-seller-name">👤 <?= htmlspecialchars($bid['full_name']) ?></div>
                                        <div class="bid-amount">$<?= number_format($bid['amount'], 2) ?></div>
                                    </div>

                                    <?php if (!empty($bid['message'])): ?>
                                        <div class="bid-message">
                                            <?= nl2br(htmlspecialchars($bid['message'])) ?>
                                        </div>
                                    <?php endif; ?>

                                    <div class="bid-actions">
                                        <?php if ($bid['status'] === 'pending' && $project['status'] === 'open'): ?>
                                            <a href="accept_bid.php?bid_id=<?= $bid['id'] ?>" class="btn bid-accept">✓ Accept Bid</a>
                                        <?php else: ?>
                                            <span class="bid-status">Status: <?= ucfirst($bid['status']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <!-- ACCEPTED BID CARD -->
                <?php if ($acceptedBid): ?>
                    <div class="escrow-section">
                        <h3>✅ Accepted Bid</h3>
                        <div class="escrow-info">
                            <div class="escrow-item" style="grid-column: 1 / -1;">
                                <label>Seller</label>
                                <div class="value"><?= htmlspecialchars($acceptedBid['seller_name']) ?></div>
                            </div>
                            <div class="escrow-item" style="grid-column: 1 / -1;">
                                <label>Amount</label>
                                <div class="value">$<?= number_format($acceptedBid['amount'], 2) ?></div>
                            </div>
                            <div class="escrow-item" style="grid-column: 1 / -1;">
                                <label>Status</label>
                                <div class="value status-badge status-<?= str_replace(' ', '-', strtolower($acceptedBid['status'])) ?>"><?= ucfirst($acceptedBid['status']) ?></div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- RIGHT COLUMN: ESCROW STATUS -->
            <div>
                <?php if ($_SESSION['role'] === 'seller' && $escrow): ?>
                    <div class="escrow-section">
                        <h3>🔒 Escrow Status</h3>
                        <div class="escrow-info">
                            <div class="escrow-item" style="grid-column: 1 / -1;">
                                <label>Status</label>
                                <div class="value status-badge status-<?= str_replace(' ', '-', strtolower($escrow['status'])) ?>" style="<?= $escrow['status'] === 'disputed' ? 'background-color: #ff6b6b;' : '' ?>"><?= ucfirst($escrow['status']) ?></div>
                            </div>
                            <div class="escrow-item" style="grid-column: 1 / -1;">
                                <label>Amount</label>
                                <div class="value">$<?= number_format($escrow['amount'], 2) ?></div>
                            </div>
                        </div>
                        <?php if ($escrow['status'] === 'disputed'): ?>
                            <p style="margin-top: 15px; padding: 10px; background-color: #ffe0e0; border-radius: 5px; font-size: 0.9rem; color: #c92a2a;">
                                <i class="fas fa-exclamation-triangle"></i> This transaction is in dispute. Check the dispute details for resolution.
                            </p>
                            <a href="/disputes/open_dispute.php" class="btn btn-warning" style="width: 100%; margin-top: 10px;">
                                <i class="fas fa-folder-open"></i> View/Manage Dispute
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if ($_SESSION['role'] === 'buyer' && $escrow && $escrow['status'] === 'pending'): ?>
                    <div class="escrow-section" style="margin-top: 30px;">
                        <h3>🔒 Fund Escrow</h3>
                        <div class="escrow-info">
                            <div class="escrow-item" style="grid-column: 1 / -1;">
                                <label>Escrow Amount</label>
                                <div class="value">$<?= number_format($escrow['amount'], 2) ?></div>
                            </div>
                        </div>
                        <form method="POST" action="fund_escrow.php">
                            <input type="hidden" name="escrow_id" value="<?= $escrow['id'] ?>">
                            <button type="submit" class="btn btn-primary" style="width: 100%;">Fund Escrow Now</button>
                        </form>
                    </div>
                <?php endif; ?>

                <!-- Workflow: Work Delivery & Approval -->
                <?php if ($escrow && $escrow['status'] === 'funded' && !in_array($escrow['status'], ['released', 'refunded'])): ?>
                    <div class="escrow-section" style="margin-top: 30px; border: 2px solid #667eea;">
                        <h3>📦 Work Delivery & Approval</h3>
                        <div class="escrow-info">
                            <div class="escrow-item" style="grid-column: 1 / -1;">
                                <label>Status</label>
                                <div class="value">
                                    <?php if ($escrow['work_delivered_at']): ?>
                                        <span style="color: #48bb78;">✓ Work Delivered</span><br>
                                        <small style="color: #718096;">on <?= date('M d, Y H:i', strtotime($escrow['work_delivered_at'])) ?></small>
                                    <?php else: ?>
                                        <span style="color: #718096;">Awaiting delivery...</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Seller: Mark Work Delivered -->
                        <?php if ($_SESSION['role'] === 'seller' && !$escrow['work_delivered_at']): ?>
                            <button type="button" class="btn btn-primary" style="width: 100%; margin-top: 15px;" onclick="markWorkDelivered(<?= $escrow['id'] ?>)">
                                <i class="fas fa-check-circle"></i> Mark Work as Delivered
                            </button>
                        <?php endif; ?>

                        <!-- Buyer: Approve Work -->
                        <?php if ($_SESSION['role'] === 'buyer' && $escrow['work_delivered_at'] && !$escrow['buyer_approved_at']): ?>
                            <div style="margin-top: 15px; padding: 12px; background: #e3f2fd; border-radius: 6px; border-left: 4px solid #667eea;">
                                <p style="margin: 0 0 10px 0; color: #2c5282; font-weight: 600;">
                                    Seller has delivered the work. Review it and approve to release payment.
                                </p>
                                <button type="button" class="btn btn-success" style="width: 100%;" onclick="approveWork(<?= $escrow['id'] ?>)">
                                    <i class="fas fa-thumbs-up"></i> Approve Work & Release Payment
                                </button>
                            </div>
                        <?php elseif ($escrow['buyer_approved_at']): ?>
                            <div style="margin-top: 15px; padding: 12px; background: #c6f6d5; border-radius: 6px; border-left: 4px solid #48bb78;">
                                <p style="margin: 0; color: #22543d; font-weight: 600;">
                                    ✓ Work approved on <?= date('M d, Y H:i', strtotime($escrow['buyer_approved_at'])) ?><br>
                                    <small>Payment is being released to seller...</small>
                                </p>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <!-- Show Approval Status in Other States -->
                <?php if ($escrow && ($escrow['status'] === 'released' || $escrow['status'] === 'refunded')): ?>
                    <div class="escrow-section" style="margin-top: 30px; border: 2px solid #48bb78;">
                        <h3>✓ Project Workflow Complete</h3>
                        <div class="escrow-info">
                            <?php if ($escrow['work_delivered_at']): ?>
                                <div class="escrow-item" style="grid-column: 1 / -1;">
                                    <label>Work Delivered</label>
                                    <div class="value" style="color: #48bb78;">✓ <?= date('M d, Y H:i', strtotime($escrow['work_delivered_at'])) ?></div>
                                </div>
                            <?php endif; ?>
                            <?php if ($escrow['buyer_approved_at']): ?>
                                <div class="escrow-item" style="grid-column: 1 / -1;">
                                    <label>Buyer Approved</label>
                                    <div class="value" style="color: #48bb78;">✓ <?= date('M d, Y H:i', strtotime($escrow['buyer_approved_at'])) ?></div>
                                </div>
                            <?php endif; ?>
                            <div class="escrow-item" style="grid-column: 1 / -1;">
                                <label>Final Status</label>
                                <div class="value" style="color: #667eea; font-weight: bold;"><?= ucfirst($escrow['status']) ?></div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <script>
            function markWorkDelivered(escrowId) {
                if (!confirm('Mark work as delivered and notify the buyer?')) return;

                fetch('/dashboard/mark_work_delivered.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded'
                        },
                        body: 'escrow_id=' + escrowId
                    })
                    .then(r => {
                        const contentType = r.headers.get('content-type');
                        if (!contentType || !contentType.includes('application/json')) {
                            throw new Error('Invalid response format (expected JSON)');
                        }
                        return r.text().then(text => {
                            try {
                                return JSON.parse(text);
                            } catch (e) {
                                console.error('JSON parse error. Response text:', text);
                                throw new Error('Failed to parse JSON response: ' + text.substring(0, 100));
                            }
                        });
                    })
                    .then(data => {
                        if (data.status === 'success') {
                            alert('✓ Work marked as delivered! Buyer will be notified.');
                            location.reload();
                        } else {
                            alert('Error: ' + data.message);
                        }
                    })
                    .catch(e => {
                        console.error('Delivery error:', e);
                        alert('Error: ' + e.message);
                    });
            }

            function approveWork(escrowId) {
                if (!confirm('Approve this work and release payment to the seller?')) return;

                fetch('/dashboard/approve_work.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded'
                        },
                        body: 'escrow_id=' + escrowId
                    })
                    .then(r => {
                        // Check if response is JSON
                        const contentType = r.headers.get('content-type');
                        if (!contentType || !contentType.includes('application/json')) {
                            throw new Error('Invalid response format (expected JSON)');
                        }
                        return r.text().then(text => {
                            try {
                                return JSON.parse(text);
                            } catch (e) {
                                console.error('JSON parse error. Response text:', text);
                                throw new Error('Failed to parse JSON response: ' + text.substring(0, 100));
                            }
                        });
                    })
                    .then(data => {
                        if (data.status === 'success') {
                            alert('✓ ' + data.message);
                            if (data.redirect) setTimeout(() => location.href = data.redirect, 1500);
                            else location.reload();
                        } else {
                            alert('Error: ' + data.message);
                        }
                    })
                    .catch(e => {
                        console.error('Approval error:', e);
                        alert('Error: ' + e.message);
                    });
            }
        </script>

</body>

</html>

<?php if ($escrow && $escrow['status'] === 'disputed'): ?>
    <div class="escrow-section" style="margin-top: 30px; border: 2px solid #ffc107;">
        <h3 style="color: #ff6b6b;">⚠️ Dispute Open</h3>
        <div class="escrow-info">
            <div class="escrow-item" style="grid-column: 1 / -1;">
                <label>Status</label>
                <div class="value" style="color: #ff6b6b; font-weight: bold;">Disputed - Awaiting Resolution</div>
            </div>
            <div class="escrow-item" style="grid-column: 1 / -1;">
                <label>Amount</label>
                <div class="value">$<?= number_format($escrow['amount'], 2) ?></div>
            </div>
        </div>
        <?php
        // Get any open disputes for this escrow
        $disputeStmt = $pdo->prepare("SELECT id FROM disputes WHERE escrow_id = ? AND status = 'open' LIMIT 1");
        $disputeStmt->execute([$escrow['id']]);
        $dispute = $disputeStmt->fetch();
        ?>
        <div style="display: flex; gap: 10px; margin-top: 15px;">
            <?php if ($dispute): ?>
                <a href="/disputes/dispute_view.php?id=<?= $dispute['id'] ?>" class="btn btn-warning" style="flex: 1;">
                    <i class="fas fa-folder-open"></i> View Dispute
                </a>
            <?php else: ?>
                <a href="/disputes/open_dispute.php" class="btn btn-danger" style="flex: 1;">
                    <i class="fas fa-exclamation-circle"></i> Open Dispute
                </a>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>
</div>
</div>

</body>

</html>