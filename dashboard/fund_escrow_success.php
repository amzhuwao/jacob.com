<?php
require_once "../includes/auth.php";
require_once "../config/database.php";
require_once "../config/stripe.php";
require_once "../includes/EscrowStateMachine.php";

if ($_SESSION['role'] !== 'buyer') {
    die("Access denied");
}

$escrow_id = isset($_GET['escrow_id']) ? (int) $_GET['escrow_id'] : 0;
$session_id = $_GET['session_id'] ?? '';

if ($escrow_id <= 0 || empty($session_id)) {
    die('Missing parameters');
}

// Fetch escrow to confirm ownership
$stmt = $pdo->prepare("SELECT * FROM escrow WHERE id = ?");
$stmt->execute([$escrow_id]);
$escrow = $stmt->fetch();

if (!$escrow || (int) $escrow['buyer_id'] !== (int) $_SESSION['user_id']) {
    die('Unauthorized');
}

try {
    $session = stripe_request('GET', '/v1/checkout/sessions/' . urlencode($session_id));
} catch (Exception $e) {
    die('Stripe error: ' . $e->getMessage());
}

// Check payment status
$paymentStatus = $session['payment_status'] ?? '';
$stateMachine = new EscrowStateMachine($pdo);

if ($paymentStatus === 'paid') {
    // Payment succeeded
    // Note: Webhook will handle the actual state transition to 'funded'
    // This page just shows success message
    $message = "Payment successful! Your escrow is being processed. The seller will be notified once funds are confirmed.";
    $messageType = "success";
} elseif ($paymentStatus === 'unpaid') {
    $message = "Payment is still processing. Please wait while we confirm your payment.";
    $messageType = "info";
} else {
    $message = "Payment status unclear. Please contact support if funds were debited.";
    $messageType = "warning";
}

// Get current escrow state
$escrowState = $stateMachine->getEscrowState($escrow_id);
?>

<!DOCTYPE html>
<html>

<head>
    <title>Payment Status</title>
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
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .container {
            max-width: 600px;
            width: 100%;
        }

        .card {
            background: white;
            border-radius: 16px;
            padding: 40px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.2);
            text-align: center;
        }

        .icon {
            font-size: 64px;
            margin-bottom: 20px;
        }

        .icon.success {
            color: #48bb78;
        }

        .icon.info {
            color: #4299e1;
        }

        .icon.warning {
            color: #ed8936;
        }

        h1 {
            font-size: 2em;
            color: #2d3748;
            margin-bottom: 15px;
        }

        .message {
            font-size: 1.1em;
            color: #4a5568;
            line-height: 1.6;
            margin-bottom: 30px;
        }

        .status-box {
            background: #f7fafc;
            border-radius: 8px;
            padding: 20px;
            margin: 20px 0;
            border-left: 4px solid #667eea;
        }

        .status-item {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #e2e8f0;
        }

        .status-item:last-child {
            border-bottom: none;
        }

        .status-label {
            font-weight: 600;
            color: #718096;
        }

        .status-value {
            color: #2d3748;
            font-weight: 600;
        }

        .btn {
            display: inline-block;
            padding: 14px 30px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 1.1em;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(102, 126, 234, 0.4);
        }

        .note {
            margin-top: 25px;
            font-size: 0.9em;
            color: #718096;
            font-style: italic;
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="card">
            <div class="icon <?= $messageType ?>">
                <?php if ($messageType === 'success'): ?>
                    ✓
                <?php elseif ($messageType === 'info'): ?>
                    ⏳
                <?php else: ?>
                    ⚠
                <?php endif; ?>
            </div>

            <h1>
                <?php if ($messageType === 'success'): ?>
                    Payment Successful!
                <?php elseif ($messageType === 'info'): ?>
                    Processing Payment...
                <?php else: ?>
                    Payment Status Unknown
                <?php endif; ?>
            </h1>

            <p class="message"><?= htmlspecialchars($message) ?></p>

            <div class="status-box">
                <div class="status-item">
                    <span class="status-label">Escrow Status:</span>
                    <span class="status-value"><?= ucfirst($escrowState['status']) ?></span>
                </div>
                <div class="status-item">
                    <span class="status-label">Payment Status:</span>
                    <span class="status-value"><?= ucfirst($escrowState['payment_status']) ?></span>
                </div>
                <div class="status-item">
                    <span class="status-label">Amount:</span>
                    <span class="status-value">$<?= number_format($escrow['amount'], 2) ?></span>
                </div>
                <div class="status-item">
                    <span class="status-label">Project:</span>
                    <span class="status-value">#<?= $escrow['project_id'] ?></span>
                </div>
            </div>

            <a href="project_view.php?id=<?= $escrow['project_id'] ?>" class="btn">
                View Project Details
            </a>

            <?php if ($paymentStatus === 'paid' && $escrowState['status'] !== 'funded'): ?>
                <p class="note">
                    Note: The escrow status will update to "Funded" once our payment processor confirms the transaction. This usually takes a few seconds.
                </p>
            <?php endif; ?>
        </div>
    </div>
</body>

</html>