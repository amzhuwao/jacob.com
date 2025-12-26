<?php
require_once '../../includes/auth.php';
require_once '../../config/database.php';

requireRole('admin');

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    http_response_code(400);
    exit('Invalid log ID');
}

$stmt = $pdo->prepare("SELECT a.*, u.full_name FROM admin_activity_logs a JOIN users u ON a.admin_user_id = u.id WHERE a.id = ?");
$stmt->execute([$id]);
$log = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$log) {
    http_response_code(404);
    exit('Log not found');
}

?>
<h3><?php echo htmlspecialchars($log['action']); ?></h3>
<div style="margin-bottom: 1rem;">
    <strong>Admin:</strong> <?php echo htmlspecialchars($log['full_name']); ?><br>
    <strong>Timestamp:</strong> <?php echo date('M d, Y H:i:s', strtotime($log['created_at'])); ?><br>
    <strong>Status:</strong> <span style="background: <?php echo $log['status'] === 'success' ? '#d1e7dd' : '#f8d7da'; ?>; color: <?php echo $log['status'] === 'success' ? '#0f5132' : '#721c24'; ?>; padding: 2px 6px; border-radius: 3px; font-size: 0.85rem;"><?php echo ucfirst($log['status']); ?></span><br>
    <strong>Entity:</strong> <?php echo htmlspecialchars($log['entity_type']); ?> #<?php echo $log['entity_id']; ?><br>
    <strong>IP Address:</strong> <?php echo htmlspecialchars($log['ip_address']); ?>
</div>

<div style="background: #f5f5f5; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1rem;">
    <strong>Description:</strong>
    <p style="margin: 0.5rem 0 0 0; word-break: break-word;"><?php echo htmlspecialchars($log['description']); ?></p>
</div>

<?php if ($log['old_values']): ?>
    <div style="background: #f5f5f5; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1rem;">
        <strong>Before:</strong>
        <pre style="background: white; padding: 0.5rem; border-radius: 3px; overflow-x: auto; margin: 0.5rem 0 0 0; font-size: 0.85rem;"><?php echo htmlspecialchars(json_encode(json_decode($log['old_values'], true), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></pre>
    </div>
<?php endif; ?>

<?php if ($log['new_values']): ?>
    <div style="background: #f5f5f5; padding: 1rem; border-radius: 0.5rem;">
        <strong>After:</strong>
        <pre style="background: white; padding: 0.5rem; border-radius: 3px; overflow-x: auto; margin: 0.5rem 0 0 0; font-size: 0.85rem;"><?php echo htmlspecialchars(json_encode(json_decode($log['new_values'], true), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></pre>
    </div>
<?php endif; ?>

<?php if ($log['error_message']): ?>
    <div style="background: #f8d7da; color: #721c24; padding: 1rem; border-radius: 0.5rem; margin-top: 1rem;">
        <strong>Error:</strong>
        <p style="margin: 0.5rem 0 0 0; word-break: break-word;"><?php echo htmlspecialchars($log['error_message']); ?></p>
    </div>
<?php endif; ?>