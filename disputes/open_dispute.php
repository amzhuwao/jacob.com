<?php

/**
 * Open Dispute Page - Production Ready
 *
 * Allows buyers or sellers to open a dispute for a disputed escrow transaction.
 */

require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/header.php';
require_once '../includes/EscrowStateMachine.php';
require_once '../services/EmailService.php';

session_start();
$userId = $_SESSION['user_id'] ?? null;

if (!$userId) {
    header('Location: /auth/login.php');
    exit;
}

// Generate CSRF token if not exists
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $escrowId = (int)($_POST['escrow_id'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');
    $csrfToken = $_POST['csrf_token'] ?? '';

    if (!$escrowId || !$reason) {
        $error = 'Please select an escrow and provide a reason.';
    } elseif ($csrfToken !== $_SESSION['csrf_token']) {
        $error = 'Invalid form submission.';
    } else {
        try {
            $pdo->beginTransaction();

            // Lock escrow row for update
            $stmt = $pdo->prepare("
                SELECT e.*, p.title
                FROM escrow e
                JOIN projects p ON e.project_id = p.id
                WHERE e.id = ? AND e.status = 'disputed' 
                  AND (e.buyer_id = ? OR e.seller_id = ?)
                FOR UPDATE
            ");
            $stmt->execute([$escrowId, $userId, $userId]);
            $escrow = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$escrow) {
                throw new RuntimeException('Escrow not found or not eligible for dispute.');
            }

            // Prevent duplicate disputes by same user
            $checkStmt = $pdo->prepare("
                SELECT id FROM disputes
                WHERE escrow_id = ? AND opened_by = ? AND status = 'open'
            ");
            $checkStmt->execute([$escrowId, $userId]);
            if ($checkStmt->fetch()) {
                throw new RuntimeException('You already have an open dispute for this escrow.');
            }

            // Insert dispute record
            $stmt = $pdo->prepare("
                INSERT INTO disputes (escrow_id, opened_by, opened_at, status, reason)
                VALUES (?, ?, NOW(), 'open', ?)
            ");
            $stmt->execute([$escrowId, $userId, $reason]);
            $disputeId = $pdo->lastInsertId();

            // Insert initial dispute message
            $stmt = $pdo->prepare("
                INSERT INTO dispute_messages (dispute_id, user_id, message, created_at)
                VALUES (?, ?, ?, NOW())
            ");
            $stmt->execute([$disputeId, $userId, $reason]);

            // Handle evidence file uploads if present
            if (!empty($_FILES['evidence']['name'][0])) {
                $uploadDir = '../uploads/dispute_evidence/';

                // Create directory if not exists
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }

                $maxFileSize = 5 * 1024 * 1024; // 5MB per file
                $allowedMimes = [
                    'image/jpeg',
                    'image/png',
                    'image/gif',
                    'application/pdf',
                    'application/msword',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'text/plain'
                ];

                for ($i = 0; $i < count($_FILES['evidence']['name']); $i++) {
                    if (empty($_FILES['evidence']['name'][$i])) {
                        continue; // Skip empty entries
                    }

                    if ($_FILES['evidence']['error'][$i] !== UPLOAD_ERR_OK) {
                        throw new RuntimeException('File upload error on ' . $_FILES['evidence']['name'][$i]);
                    }

                    // Validate file size
                    if ($_FILES['evidence']['size'][$i] > $maxFileSize) {
                        throw new RuntimeException('File ' . $_FILES['evidence']['name'][$i] . ' exceeds 5MB limit');
                    }

                    // Validate MIME type
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    $mimeType = finfo_file($finfo, $_FILES['evidence']['tmp_name'][$i]);
                    finfo_close($finfo);

                    if (!in_array($mimeType, $allowedMimes)) {
                        throw new RuntimeException('File type not allowed for ' . $_FILES['evidence']['name'][$i] . ' (MIME: ' . $mimeType . ')');
                    }

                    // Generate secure filename
                    $originalName = basename($_FILES['evidence']['name'][$i]);
                    $fileExt = pathinfo($originalName, PATHINFO_EXTENSION);
                    $secureFilename = 'dispute_' . $disputeId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $fileExt;
                    $filePath = $uploadDir . $secureFilename;

                    // Move uploaded file
                    if (!move_uploaded_file($_FILES['evidence']['tmp_name'][$i], $filePath)) {
                        throw new RuntimeException('Failed to save file ' . $originalName);
                    }

                    // Insert evidence record
                    $stmt = $pdo->prepare("
                        INSERT INTO dispute_evidence (dispute_id, uploaded_by, filename, file_path, file_size, mime_type, uploaded_at)
                        VALUES (?, ?, ?, ?, ?, ?, NOW())
                    ");
                    $stmt->execute([
                        $disputeId,
                        $userId,
                        $originalName,
                        '/uploads/dispute_evidence/' . $secureFilename,
                        $_FILES['evidence']['size'][$i],
                        $mimeType
                    ]);
                }
            }

            $pdo->commit();

            // Send dispute notification emails
            try {
                $emailService = new EmailService($pdo);

                // Get escrow details
                $escrowStmt = $pdo->prepare("SELECT buyer_id, seller_id, project_id FROM escrow WHERE id = ?");
                $escrowStmt->execute([$escrowId]);
                $escrowInfo = $escrowStmt->fetch(PDO::FETCH_ASSOC);

                // Get project info
                $projStmt = $pdo->prepare("SELECT title FROM projects WHERE id = ?");
                $projStmt->execute([$escrowInfo['project_id']]);
                $projInfo = $projStmt->fetch(PDO::FETCH_ASSOC);

                // Determine who opened dispute and who should be notified
                $otherPartyId = ($userId === $escrowInfo['buyer_id']) ? $escrowInfo['seller_id'] : $escrowInfo['buyer_id'];

                $otherPartyStmt = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
                $otherPartyStmt->execute([$otherPartyId]);
                $otherParty = $otherPartyStmt->fetch(PDO::FETCH_ASSOC);

                // Send to both parties
                $emailService->disputeOpened($userId, $disputeId, $projInfo['title'], $otherParty['full_name']);
                $emailService->disputeOpened($otherPartyId, $disputeId, $projInfo['title'], '(User)');
            } catch (Exception $e) {
                error_log("Email send failed in open_dispute: " . $e->getMessage());
            }

            // Redirect to dispute view
            header("Location: /disputes/dispute_view.php?id={$disputeId}", true, 303);
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Failed to open dispute: ' . $e->getMessage();
        }
    }
}

// Fetch disputed escrows for user
$stmt = $pdo->prepare("
    SELECT e.*, p.title, p.id AS project_id,
           u_buyer.username AS buyer_name,
           u_seller.username AS seller_name
    FROM escrow e
    JOIN projects p ON e.project_id = p.id
    JOIN users u_buyer ON e.buyer_id = u_buyer.id
    JOIN users u_seller ON e.seller_id = u_seller.id
    WHERE e.status = 'disputed' 
      AND (e.buyer_id = ? OR e.seller_id = ?)
    ORDER BY e.created_at DESC
");
$stmt->execute([$userId, $userId]);
$disputedEscrows = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>

<div class="container mt-5">
    <div class="row">
        <div class="col-md-8">
            <h2>Open Dispute</h2>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <?php if (empty($disputedEscrows)): ?>
                <div class="alert alert-info">
                    No disputed escrows available. Disputes can only be opened for transactions in disputed state.
                </div>
            <?php else: ?>
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">
                            <i class="fas fa-gavel"></i> Open a Dispute
                        </h5>
                        <p class="text-muted">Fill in the details below to formally open a dispute. Provide as much detail as possible to help us resolve this quickly.</p>

                        <form method="POST" class="needs-validation" id="disputeForm" novalidate enctype="multipart/form-data">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

                            <!-- Step 1: Select Escrow -->
                            <div class="mb-4">
                                <h6 class="text-secondary">
                                    <span class="badge bg-primary">Step 1</span> Select Transaction
                                </h6>
                                <label for="escrow_id" class="form-label mt-3">Which escrow are you disputing?</label>
                                <select name="escrow_id" id="escrow_id" class="form-select" required onchange="updateEscrowSummary()">
                                    <option value="">-- Select an escrow --</option>
                                    <?php foreach ($disputedEscrows as $escrow): ?>
                                        <option value="<?php echo $escrow['id']; ?>"
                                            data-amount="<?php echo $escrow['amount']; ?>"
                                            data-title="<?php echo htmlspecialchars($escrow['title']); ?>"
                                            data-buyer="<?php echo htmlspecialchars($escrow['buyer_name']); ?>"
                                            data-seller="<?php echo htmlspecialchars($escrow['seller_name']); ?>"
                                            data-role="<?php echo $escrow['buyer_id'] === $userId ? 'buyer' : 'seller'; ?>">
                                            Project: <?php echo htmlspecialchars($escrow['title']); ?>
                                            (Amount: $<?php echo number_format($escrow['amount'], 2); ?>)
                                            [<?php echo $escrow['buyer_id'] === $userId ? 'You are Buyer' : 'You are Seller'; ?>]
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted d-block mt-2">
                                    <i class="fas fa-info-circle"></i> Only disputed escrows are shown here.
                                </small>
                            </div>

                            <!-- Escrow Summary Card -->
                            <div id="escrowSummary" class="card mb-4 border-info d-none">
                                <div class="card-header bg-info text-white">
                                    <h6 class="mb-0"><i class="fas fa-box"></i> Transaction Summary</h6>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <p class="small mb-2"><strong>Project:</strong></p>
                                            <p id="summaryTitle" class="mb-3">-</p>
                                            <p class="small mb-2"><strong>Amount:</strong></p>
                                            <p id="summaryAmount" class="mb-3 text-danger fs-5">-</p>
                                        </div>
                                        <div class="col-md-6">
                                            <p class="small mb-2"><strong>Buyer:</strong></p>
                                            <p id="summaryBuyer" class="mb-3">-</p>
                                            <p class="small mb-2"><strong>Seller:</strong></p>
                                            <p id="summarySeller" class="mb-3">-</p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Step 2: Dispute Reason -->
                            <div class="mb-4">
                                <h6 class="text-secondary">
                                    <span class="badge bg-primary">Step 2</span> Describe the Issue
                                </h6>

                                <label for="reason_template" class="form-label mt-3">Quick Template (Optional)</label>
                                <select id="reason_template" class="form-select mb-3" onchange="applyTemplate()">
                                    <option value="">-- Select a common reason or write your own --</option>
                                    <option value="Partial refund not received">💰 Partial refund not received</option>
                                    <option value="Work not delivered as agreed">📦 Work not delivered as agreed</option>
                                    <option value="Delivered work has quality issues">⚠️ Delivered work has quality issues</option>
                                    <option value="Project deliverables incomplete">❌ Project deliverables incomplete</option>
                                    <option value="Communication issues with counterparty">📧 Communication issues with counterparty</option>
                                    <option value="Payment not released as promised">💳 Payment not released as promised</option>
                                    <option value="Custom reason">✏️ Custom reason (type below)</option>
                                </select>

                                <label for="reason" class="form-label">Detailed Explanation</label>
                                <textarea name="reason" id="reason" class="form-control" rows="6" required
                                    placeholder="Provide detailed information about the dispute. Include:&#10;• What happened&#10;• When it happened&#10;• What you expected vs. what occurred&#10;• Any relevant dates or communications"></textarea>
                                <small class="text-muted d-block mt-2">
                                    <i class="fas fa-lightbulb"></i> Be specific and detailed - this helps admins resolve disputes faster.
                                </small>
                                <div id="reasonCounter" class="small text-muted mt-1"></div>
                            </div>

                            <!-- Step 3: Evidence Upload -->
                            <div class="mb-4">
                                <h6 class="text-secondary">
                                    <span class="badge bg-primary">Step 3</span> Upload Evidence (Optional)
                                </h6>

                                <label for="evidence" class="form-label mt-3">Supporting Files</label>
                                <div class="input-group mb-2">
                                    <input type="file" name="evidence[]" id="evidence" class="form-control" multiple
                                        accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.txt">
                                    <label class="input-group-text">Choose Files</label>
                                </div>
                                <small class="text-muted d-block mb-3">
                                    <i class="fas fa-paperclip"></i>
                                    Accepted: Images (JPG, PNG, GIF), Documents (PDF, Word, TXT) - Max 5MB per file, up to 5 files
                                </small>

                                <!-- File Preview -->
                                <div id="filePreview" class="mb-3"></div>

                                <!-- File Validation Feedback -->
                                <div id="fileFeedback"></div>
                            </div>

                            <!-- Form Validation Status -->
                            <div id="validationFeedback" class="mb-3"></div>

                            <!-- Action Buttons -->
                            <div class="d-flex gap-2 justify-content-between">
                                <a href="/dashboard/buyer.php" class="btn btn-secondary">
                                    <i class="fas fa-times"></i> Cancel
                                </a>
                                <button type="button" class="btn btn-warning" id="previewBtn">
                                    <i class="fas fa-eye"></i> Preview & Confirm
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Confirmation Modal -->
                <div class="modal fade" id="confirmModal" tabindex="-1">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content">
                            <div class="modal-header bg-warning text-dark">
                                <h5 class="modal-title">
                                    <i class="fas fa-check-circle"></i> Confirm Dispute Submission
                                </h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <p class="mb-3">Please review your dispute details before submitting:</p>

                                <div class="card mb-3">
                                    <div class="card-header bg-light">
                                        <strong>Transaction</strong>
                                    </div>
                                    <div class="card-body small">
                                        <p class="mb-2"><strong>Project:</strong> <span id="confirmTitle">-</span></p>
                                        <p class="mb-2"><strong>Amount:</strong> <span id="confirmAmount" class="text-danger">-</span></p>
                                        <p class="mb-0"><strong>Your Role:</strong> <span id="confirmRole" class="badge bg-info">-</span></p>
                                    </div>
                                </div>

                                <div class="card mb-3">
                                    <div class="card-header bg-light">
                                        <strong>Dispute Details</strong>
                                    </div>
                                    <div class="card-body small">
                                        <p><strong>Reason:</strong></p>
                                        <p id="confirmReason" style="white-space: pre-wrap; background: #f8f9fa; padding: 10px; border-radius: 4px; max-height: 150px; overflow-y: auto;">-</p>
                                    </div>
                                </div>

                                <div id="confirmEvidence" class="card mb-3 d-none">
                                    <div class="card-header bg-light">
                                        <strong>Evidence Files</strong>
                                    </div>
                                    <div class="card-body small">
                                        <ul id="confirmFileList" class="list-unstyled"></ul>
                                    </div>
                                </div>

                                <div class="alert alert-info">
                                    <i class="fas fa-info-circle"></i>
                                    Once submitted, an admin will review your dispute and contact you within 24-48 hours.
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                    <i class="fas fa-edit"></i> Edit
                                </button>
                                <button type="button" class="btn btn-danger" id="submitBtn">
                                    <i class="fas fa-check"></i> Submit Dispute
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <script>
                    // Escrow summary update
                    function updateEscrowSummary() {
                        const select = document.getElementById('escrow_id');
                        const option = select.options[select.selectedIndex];
                        const summary = document.getElementById('escrowSummary');

                        if (option.value) {
                            document.getElementById('summaryTitle').textContent = option.dataset.title;
                            document.getElementById('summaryAmount').textContent = '$' + parseFloat(option.dataset.amount).toFixed(2);
                            document.getElementById('summaryBuyer').textContent = option.dataset.buyer;
                            document.getElementById('summarySeller').textContent = option.dataset.seller;
                            summary.classList.remove('d-none');
                        } else {
                            summary.classList.add('d-none');
                        }
                    }

                    // Apply dispute template
                    function applyTemplate() {
                        const template = document.getElementById('reason_template');
                        if (template.value && template.value !== 'Custom reason') {
                            document.getElementById('reason').value = template.value;
                        }
                    }

                    // File handling
                    document.getElementById('evidence').addEventListener('change', function(e) {
                        const files = e.target.files;
                        const preview = document.getElementById('filePreview');
                        const feedback = document.getElementById('fileFeedback');
                        preview.innerHTML = '';
                        feedback.innerHTML = '';

                        if (files.length === 0) return;

                        if (files.length > 5) {
                            feedback.innerHTML = '<div class="alert alert-warning"><i class="fas fa-exclamation-triangle"></i> Maximum 5 files allowed. Only first 5 will be uploaded.</div>';
                        }

                        let fileList = '<p class="small mb-2"><strong>Files to upload:</strong></p>';
                        let totalSize = 0;
                        let errors = [];

                        for (let i = 0; i < Math.min(files.length, 5); i++) {
                            const file = files[i];
                            const sizeMB = (file.size / (1024 * 1024)).toFixed(2);
                            totalSize += file.size;

                            if (file.size > 5 * 1024 * 1024) {
                                errors.push(`<i class="fas fa-times text-danger"></i> ${file.name} is too large (${sizeMB}MB > 5MB)`);
                            } else {
                                fileList += `<div class="small mb-2"><i class="fas fa-check text-success"></i> ${file.name} (${sizeMB}MB)</div>`;
                            }
                        }

                        if (errors.length > 0) {
                            feedback.innerHTML = '<div class="alert alert-danger">' + errors.join('<br>') + '</div>';
                        }

                        preview.innerHTML = fileList;
                    });

                    // Form validation
                    document.getElementById('disputeForm').addEventListener('input', function() {
                        const escrowId = document.getElementById('escrow_id').value;
                        const reason = document.getElementById('reason').value.trim();
                        const feedback = document.getElementById('validationFeedback');

                        if (reason.length > 0) {
                            const charCount = reason.length;
                            document.getElementById('reasonCounter').textContent = `${charCount} characters (recommended: 50+ characters)`;
                        }

                        let isValid = escrowId && reason.length >= 20;
                        document.getElementById('previewBtn').disabled = !isValid;

                        if (!isValid && (escrowId || reason)) {
                            const missing = [];
                            if (!escrowId) missing.push('Select a transaction');
                            if (reason.length < 20) missing.push('Provide at least 20 characters in description');
                            feedback.innerHTML = '<div class="alert alert-warning small"><i class="fas fa-info-circle"></i> ' + missing.join(' • ') + '</div>';
                        } else {
                            feedback.innerHTML = '';
                        }
                    });

                    // Preview button
                    document.getElementById('previewBtn').addEventListener('click', function() {
                        const escrowId = document.getElementById('escrow_id').value;
                        const reason = document.getElementById('reason').value.trim();

                        if (!escrowId || reason.length < 20) {
                            alert('Please fill in all required fields');
                            return;
                        }

                        // Populate modal
                        const select = document.getElementById('escrow_id');
                        const option = select.options[select.selectedIndex];

                        document.getElementById('confirmTitle').textContent = option.dataset.title;
                        document.getElementById('confirmAmount').textContent = '$' + parseFloat(option.dataset.amount).toFixed(2);
                        document.getElementById('confirmRole').textContent = option.dataset.role === 'buyer' ? 'Buyer' : 'Seller';
                        document.getElementById('confirmReason').textContent = reason;

                        // Show evidence if present
                        const files = document.getElementById('evidence').files;
                        if (files.length > 0) {
                            const fileList = document.getElementById('confirmFileList');
                            fileList.innerHTML = '';
                            for (let i = 0; i < Math.min(files.length, 5); i++) {
                                const file = files[i];
                                const sizeMB = (file.size / (1024 * 1024)).toFixed(2);
                                fileList.innerHTML += `<li><i class="fas fa-file"></i> ${file.name} (${sizeMB}MB)</li>`;
                            }
                            document.getElementById('confirmEvidence').classList.remove('d-none');
                        } else {
                            document.getElementById('confirmEvidence').classList.add('d-none');
                        }

                        // Show modal
                        const modal = new bootstrap.Modal(document.getElementById('confirmModal'));
                        modal.show();
                    });

                    // Submit button in modal
                    document.getElementById('submitBtn').addEventListener('click', function() {
                        document.getElementById('disputeForm').submit();
                    });

                    // Initialize on load
                    document.addEventListener('DOMContentLoaded', function() {
                        document.getElementById('previewBtn').disabled = true;
                    });
                </script>
            <?php endif; ?>
        </div>

        <div class="col-md-4">
            <div class="card bg-light">
                <div class="card-body">
                    <h5 class="card-title">About Disputes</h5>
                    <p class="card-text">
                        Disputes help resolve issues with escrow transactions when problems arise.
                    </p>
                    <ul class="small">
                        <li><strong>How escrows become disputed:</strong>
                            <ul>
                                <li>Automatically when partial refunds are processed</li>
                                <li>Manually by admin when issues are reported</li>
                            </ul>
                        </li>
                        <li><strong>Once disputed:</strong> Both buyer and seller can open a formal dispute case with details.</li>
                        <li><strong>Resolution:</strong> Admin reviews evidence and makes the final decision.</li>
                        <li><strong>Evidence:</strong> You can upload documents, screenshots, and messages to support your case.</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>