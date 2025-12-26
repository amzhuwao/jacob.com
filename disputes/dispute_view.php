<?php

/**
 * Dispute View Page
 * 
 * Display dispute details, messages, evidence, and allow participants to add messages
 */

require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/header.php';

$userId = $_SESSION['user_id'] ?? null;
if (!$userId) {
    header('Location: /auth/login.php');
    exit;
}

$disputeId = (int)($_GET['id'] ?? 0);
if (!$disputeId) {
    header('Location: /disputes/open_dispute.php');
    exit;
}

// Get dispute details
$stmt = $pdo->prepare("
    SELECT d.*, e.*, p.title as project_title,
           u_opener.username as opener_name,
           u_buyer.username as buyer_name,
           u_seller.username as seller_name
    FROM disputes d
    JOIN escrow e ON d.escrow_id = e.id
    JOIN projects p ON e.project_id = p.id
    JOIN users u_opener ON d.opened_by = u_opener.id
    JOIN users u_buyer ON e.buyer_id = u_buyer.id
    JOIN users u_seller ON e.seller_id = u_seller.id
    WHERE d.id = ?
");
$stmt->execute([$disputeId]);
$dispute = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$dispute) {
    header('Location: /disputes/open_dispute.php');
    exit;
}

// Check if user is authorized to view this dispute (participant only)
$isParticipant = ($userId === $dispute['buyer_id'] || $userId === $dispute['seller_id']);
if (!$isParticipant && !($_SESSION['role'] === 'admin')) {
    http_response_code(403);
    echo 'Access denied';
    exit;
}

// Get all messages for this dispute
$stmt = $pdo->prepare("
    SELECT dm.*, u.username, u.id as user_id
    FROM dispute_messages dm
    JOIN users u ON dm.user_id = u.id
    WHERE dm.dispute_id = ?
    ORDER BY dm.created_at ASC
");
$stmt->execute([$disputeId]);
$messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get evidence files for this dispute
$stmt = $pdo->prepare("
    SELECT de.*, u.username
    FROM dispute_evidence de
    JOIN users u ON de.uploaded_by = u.id
    WHERE de.dispute_id = ?
    ORDER BY de.uploaded_at DESC
");
$stmt->execute([$disputeId]);
$evidence = $stmt->fetchAll(PDO::FETCH_ASSOC);

// SECURITY: Resolution is handled ONLY in /admin/dispute_review.php
// Buyers and sellers can ONLY view, message, and upload evidence
?>

<div class="container mt-5">
    <div class="row">
        <div class="col-md-9">
            <!-- Dispute Header -->
            <div class="card mb-4">
                <div class="card-header bg-warning text-dark">
                    <h4 class="mb-0">
                        <i class="fas fa-exclamation-circle"></i>
                        Dispute #<?php echo $disputeId; ?> -
                        <span class="badge 
                            <?php echo ($dispute['status'] === 'open') ? 'bg-danger' : 'bg-success'; ?>">
                            <?php echo ucfirst($dispute['status']); ?>
                        </span>
                    </h4>
                </div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <h6>Project</h6>
                            <p><?php echo htmlspecialchars($dispute['project_title']); ?></p>
                        </div>
                        <div class="col-md-6">
                            <h6>Amount</h6>
                            <p class="text-danger">$<?php echo number_format($dispute['amount'], 2); ?></p>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <h6>Opened By</h6>
                            <p><?php echo htmlspecialchars($dispute['opener_name']); ?> on
                                <?php echo date('M d, Y H:i', strtotime($dispute['opened_at'])); ?></p>
                        </div>
                        <div class="col-md-6">
                            <h6>Participants</h6>
                            <p>
                                <strong>Buyer:</strong> <?php echo htmlspecialchars($dispute['buyer_name']); ?><br>
                                <strong>Seller:</strong> <?php echo htmlspecialchars($dispute['seller_name']); ?>
                            </p>
                        </div>
                    </div>
                    <div class="mb-3">
                        <h6>Initial Reason</h6>
                        <p class="bg-light p-3 rounded"><?php echo htmlspecialchars($dispute['reason']); ?></p>
                    </div>
                </div>
            </div>

            <!-- Messages Section -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-comments"></i> Dispute Messages</h5>
                </div>
                <div class="card-body" style="max-height: 400px; overflow-y: auto;">
                    <?php foreach ($messages as $msg): ?>
                        <div class="mb-3 p-3 bg-light rounded border-left border-primary">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <strong><?php echo htmlspecialchars($msg['username']); ?></strong>
                                <small class="text-muted">
                                    <?php echo date('M d, Y H:i', strtotime($msg['created_at'])); ?>
                                </small>
                            </div>
                            <p class="mb-0"><?php echo nl2br(htmlspecialchars($msg['message'])); ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Add Message Form -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Add Message</h5>
                </div>
                <div class="card-body">
                    <form action="/disputes/add_message.php" method="POST">
                        <input type="hidden" name="dispute_id" value="<?php echo $disputeId; ?>">
                        <div class="mb-3">
                            <textarea name="message" class="form-control" rows="4" required
                                placeholder="Add your message..."></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary">Post Message</button>
                    </form>
                </div>
            </div>

            <!-- Evidence Section -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-paperclip"></i> Evidence</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($evidence)): ?>
                        <p class="text-muted">No evidence uploaded yet.</p>
                    <?php else: ?>
                        <div class="list-group">
                            <?php foreach ($evidence as $file): ?>
                                <div class="list-group-item">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="mb-1">
                                                <i class="fas fa-file"></i>
                                                <?php echo htmlspecialchars($file['filename']); ?>
                                            </h6>
                                            <small class="text-muted">
                                                Uploaded by <?php echo htmlspecialchars($file['username']); ?> on
                                                <?php echo date('M d, Y H:i', strtotime($file['uploaded_at'])); ?>
                                            </small>
                                        </div>
                                        <a href="<?php echo htmlspecialchars($file['file_path']); ?>"
                                            class="btn btn-sm btn-outline-primary" download>
                                            <i class="fas fa-download"></i> Download
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <hr>
                    <h6>Upload Evidence</h6>
                    <form action="/disputes/upload_evidence.php" method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="dispute_id" value="<?php echo $disputeId; ?>">
                        <div class="mb-3">
                            <label for="file" class="form-label">Select File</label>
                            <input type="file" name="file" id="file" class="form-control" required>
                            <small class="text-muted">Max 10MB. Allowed: PDF, images, documents</small>
                        </div>
                        <button type="submit" class="btn btn-sm btn-success">
                            <i class="fas fa-upload"></i> Upload
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Sidebar: Dispute Status Info -->
        <div class="col-md-3">
            <div class="card border-<?php echo ($dispute['status'] === 'open') ? 'warning' : 'success'; ?>">
                <div class="card-header bg-<?php echo ($dispute['status'] === 'open') ? 'warning' : 'success'; ?> text-white">
                    <h5 class="mb-0"><i class="fas fa-info-circle"></i> Status</h5>
                </div>
                <div class="card-body">
                    <p><strong>Current Status:</strong><br>
                        <span class="badge bg-<?php echo ($dispute['status'] === 'open') ? 'danger' : 'success'; ?> fs-6">
                            <?php echo ucfirst($dispute['status']); ?>
                        </span>
                    </p>

                    <?php if ($dispute['status'] === 'open'): ?>
                        <div class="alert alert-warning">
                            <i class="fas fa-clock"></i> This dispute is under review by administrators.
                        </div>
                        <p class="small text-muted">
                            You can continue to add messages and upload evidence while waiting for admin resolution.
                        </p>
                    <?php else: ?>
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle"></i> This dispute has been resolved.
                        </div>
                        <?php if (!empty($dispute['resolution'])): ?>
                            <p class="small"><strong>Resolution:</strong><br>
                                <?php echo htmlspecialchars($dispute['resolution']); ?>
                            </p>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($_SESSION['role'] === 'admin'): ?>
                <div class="card border-danger mt-3">
                    <div class="card-header bg-danger text-white">
                        <h5 class="mb-0"><i class="fas fa-shield-alt"></i> Admin</h5>
                    </div>
                    <div class="card-body">
                        <p class="small">To resolve this dispute, use the admin panel:</p>
                        <a href="/admin/dispute_review.php?id=<?php echo $disputeId; ?>" class="btn btn-danger w-100">
                            <i class="fas fa-gavel"></i> Admin Review
                        </a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>