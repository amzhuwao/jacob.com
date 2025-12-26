<?php

/**
 * Upload Evidence File to Dispute
 * 
 * Handle file uploads for dispute evidence
 * Validates file types and size, stores in dispute_evidence table
 */

require_once '../config/database.php';
require_once '../includes/auth.php';

$userId = $_SESSION['user_id'] ?? null;
if (!$userId) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$disputeId = (int)($_POST['dispute_id'] ?? 0);
if (!$disputeId) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing dispute ID']);
    exit;
}

// Verify user is participant
$stmt = $pdo->prepare("
    SELECT e.buyer_id, e.seller_id, d.id
    FROM disputes d
    JOIN escrow e ON d.escrow_id = e.id
    WHERE d.id = ?
");
$stmt->execute([$disputeId]);
$dispute = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$dispute) {
    http_response_code(404);
    echo json_encode(['error' => 'Dispute not found']);
    exit;
}

$isParticipant = ($userId === $dispute['buyer_id'] || $userId === $dispute['seller_id']);
if (!$isParticipant && ($_SESSION['role'] ?? null) !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

// File upload validation
if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => 'File upload error']);
    exit;
}

$file = $_FILES['file'];
$maxSize = 10 * 1024 * 1024; // 10MB
$allowedMimes = [
    'application/pdf',
    'image/jpeg',
    'image/png',
    'image/gif',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'text/plain'
];

// Validate file size
if ($file['size'] > $maxSize) {
    http_response_code(413);
    echo json_encode(['error' => 'File too large (max 10MB)']);
    exit;
}

// Validate MIME type
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if (!in_array($mimeType, $allowedMimes)) {
    http_response_code(400);
    echo json_encode(['error' => 'File type not allowed']);
    exit;
}

// Create upload directory if needed
$uploadDir = dirname(__DIR__) . '/uploads/disputes/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// Generate unique filename
$originalFilename = $file['name'];
$fileExtension = pathinfo($originalFilename, PATHINFO_EXTENSION);
$uniqueFilename = 'dispute_' . $disputeId . '_' . time() . '_' . uniqid() . '.' . $fileExtension;
$filePath = $uploadDir . $uniqueFilename;

// Move uploaded file
if (!move_uploaded_file($file['tmp_name'], $filePath)) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to save file']);
    exit;
}

try {
    // Record in database
    $stmt = $pdo->prepare("
        INSERT INTO dispute_evidence (dispute_id, uploaded_by, filename, file_path, file_size, mime_type, uploaded_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $disputeId,
        $userId,
        $originalFilename,
        '/uploads/disputes/' . $uniqueFilename,
        $file['size'],
        $mimeType
    ]);

    // Redirect back
    header("Location: /disputes/dispute_view.php?id={$disputeId}#evidence", true, 303);
    exit;
} catch (Exception $e) {
    // Clean up uploaded file on database error
    unlink($filePath);
    error_log("Error saving evidence: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Failed to save evidence record']);
    exit;
}
