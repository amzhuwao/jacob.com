<?php
// Quick runtime check for users listing query with LIMIT/OFFSET and filters
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/database.php';

$search = '';
$role = '';
$status = '';
$page = 1;
$perPage = 5;

$where = 'WHERE 1=1';
$params = [];
if ($search) {
    $where .= ' AND (u.full_name LIKE ? OR u.email LIKE ?)';
    $like = "%{$search}%";
    $params[] = $like;
    $params[] = $like;
}
if ($role) {
    $where .= ' AND u.role = ?';
    $params[] = $role;
}
if ($status) {
    $where .= ' AND u.status = ?';
    $params[] = $status;
}

$offset = ($page - 1) * $perPage;
$sql = "SELECT u.id, u.full_name, u.email, u.role, u.status, u.kyc_verified,
           (SELECT COUNT(*) FROM projects WHERE buyer_id = u.id) as projects_posted,
           (SELECT COUNT(*) FROM escrow WHERE seller_id = u.id) as projects_completed,
           (SELECT COUNT(*) FROM escrow WHERE buyer_id = u.id OR seller_id = u.id) as escrows_involved,
           (SELECT COUNT(*) FROM disputes WHERE opened_by = u.id) as disputes_opened
        FROM users u
        $where
        ORDER BY u.created_at DESC
        LIMIT :limit OFFSET :offset";

$stmt = $pdo->prepare($sql);
// Bind positional filter params
$pos = 1;
foreach ($params as $p) {
    $stmt->bindValue($pos++, $p);
}
$stmt->bindValue(':limit', (int)$perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Rows: " . count($rows) . "\n";
foreach ($rows as $r) {
    echo sprintf(
        "#%d %s | %s | role=%s status=%s kyc=%s\n",
        $r['id'],
        $r['full_name'],
        $r['email'],
        $r['role'],
        $r['status'],
        $r['kyc_verified']
    );
}
