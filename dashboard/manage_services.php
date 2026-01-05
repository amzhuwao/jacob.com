<?php
require_once "../includes/auth.php";
require_once "../config/database.php";

if ($_SESSION['role'] !== 'seller') {
    die(json_encode(['success' => false, 'message' => 'Access denied']));
}

$action = $_POST['action'] ?? $_GET['action'] ?? null;
$userId = $_SESSION['user_id'];

try {
    switch ($action) {
        case 'create':
            createService();
            break;

        case 'update':
            updateService();
            break;

        case 'delete':
            deleteService();
            break;

        case 'get':
            getService();
            break;

        default:
            die(json_encode(['success' => false, 'message' => 'Invalid action']));
    }
} catch (Exception $e) {
    error_log("Service management error: " . $e->getMessage());
    die(json_encode(['success' => false, 'message' => 'An error occurred']));
}

function createService()
{
    global $pdo, $userId;

    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $basePrice = $_POST['base_price'] ?? null;
    $category = $_POST['category'] ?? '';
    $status = $_POST['status'] ?? 'active';

    // Validation
    if (empty($title) || strlen($title) < 3) {
        die(json_encode(['success' => false, 'message' => 'Service title must be at least 3 characters']));
    }

    if (empty($description) || strlen($description) < 10) {
        die(json_encode(['success' => false, 'message' => 'Description must be at least 10 characters']));
    }

    if (!$basePrice || !is_numeric($basePrice) || $basePrice <= 0) {
        die(json_encode(['success' => false, 'message' => 'Please enter a valid price']));
    }

    if (empty($category)) {
        die(json_encode(['success' => false, 'message' => 'Please select a category']));
    }

    $stmt = $pdo->prepare(
        "INSERT INTO seller_services (seller_id, title, description, base_price, category, status)
         VALUES (?, ?, ?, ?, ?, ?)"
    );

    $stmt->execute([$userId, $title, $description, $basePrice, $category, $status]);

    echo json_encode([
        'success' => true,
        'message' => 'Service created successfully',
        'service_id' => $pdo->lastInsertId()
    ]);
}

function updateService()
{
    global $pdo, $userId;

    $serviceId = $_POST['service_id'] ?? null;

    if (!$serviceId || !is_numeric($serviceId)) {
        die(json_encode(['success' => false, 'message' => 'Invalid service ID']));
    }

    // Verify ownership
    $checkStmt = $pdo->prepare("SELECT id FROM seller_services WHERE id = ? AND seller_id = ?");
    $checkStmt->execute([$serviceId, $userId]);

    if (!$checkStmt->fetch()) {
        die(json_encode(['success' => false, 'message' => 'Service not found or access denied']));
    }

    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $basePrice = $_POST['base_price'] ?? null;
    $category = $_POST['category'] ?? '';
    $status = $_POST['status'] ?? 'active';

    // Validation
    if (empty($title) || strlen($title) < 3) {
        die(json_encode(['success' => false, 'message' => 'Service title must be at least 3 characters']));
    }

    if (empty($description) || strlen($description) < 10) {
        die(json_encode(['success' => false, 'message' => 'Description must be at least 10 characters']));
    }

    if (!$basePrice || !is_numeric($basePrice) || $basePrice <= 0) {
        die(json_encode(['success' => false, 'message' => 'Please enter a valid price']));
    }

    if (empty($category)) {
        die(json_encode(['success' => false, 'message' => 'Please select a category']));
    }

    $stmt = $pdo->prepare(
        "UPDATE seller_services 
         SET title = ?, description = ?, base_price = ?, category = ?, status = ?, updated_at = NOW()
         WHERE id = ? AND seller_id = ?"
    );

    $stmt->execute([$title, $description, $basePrice, $category, $status, $serviceId, $userId]);

    echo json_encode([
        'success' => true,
        'message' => 'Service updated successfully'
    ]);
}

function deleteService()
{
    global $pdo, $userId;

    $serviceId = $_POST['service_id'] ?? null;

    if (!$serviceId || !is_numeric($serviceId)) {
        die(json_encode(['success' => false, 'message' => 'Invalid service ID']));
    }

    // Verify ownership
    $checkStmt = $pdo->prepare("SELECT id FROM seller_services WHERE id = ? AND seller_id = ?");
    $checkStmt->execute([$serviceId, $userId]);

    if (!$checkStmt->fetch()) {
        die(json_encode(['success' => false, 'message' => 'Service not found or access denied']));
    }

    $stmt = $pdo->prepare("DELETE FROM seller_services WHERE id = ? AND seller_id = ?");
    $stmt->execute([$serviceId, $userId]);

    echo json_encode([
        'success' => true,
        'message' => 'Service deleted successfully'
    ]);
}

function getService()
{
    global $pdo, $userId;

    $serviceId = $_GET['service_id'] ?? null;

    if (!$serviceId || !is_numeric($serviceId)) {
        die(json_encode(['success' => false, 'message' => 'Invalid service ID']));
    }

    $stmt = $pdo->prepare("SELECT * FROM seller_services WHERE id = ? AND seller_id = ?");
    $stmt->execute([$serviceId, $userId]);

    $service = $stmt->fetch();

    if (!$service) {
        die(json_encode(['success' => false, 'message' => 'Service not found']));
    }

    echo json_encode([
        'success' => true,
        'service' => $service
    ]);
}
