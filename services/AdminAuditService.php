<?php

/**
 * AdminAuditService
 * Centralized audit logging for all admin actions
 * Tracks who did what, when, and captures before/after state
 */
class AdminAuditService
{
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Log an admin action
     * 
     * @param int $adminUserId ID of admin performing the action
     * @param string $action Action name (e.g., 'suspend_user', 'release_escrow')
     * @param string $entityType Type of entity affected (user, project, escrow, dispute, payment, system)
     * @param int|null $entityId ID of the affected entity
     * @param string|null $description Human-readable description of the action
     * @param array|null $oldValues Previous state (before-change snapshot)
     * @param array|null $newValues New state (after-change snapshot)
     * @param bool $success Whether the action succeeded
     * @param string|null $errorMessage Error details if failed
     * @return int Log entry ID
     */
    public function logAction(
        int $adminUserId,
        string $action,
        string $entityType,
        ?int $entityId = null,
        ?string $description = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        bool $success = true,
        ?string $errorMessage = null
    ): int {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO admin_activity_logs
                (admin_user_id, action, entity_type, entity_id, description, old_values, new_values, ip_address, user_agent, status, error_message)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $ipAddress = $this->getClientIP();
            $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
            $status = $success ? 'success' : 'failed';
            $oldValuesJson = $oldValues ? json_encode($oldValues) : null;
            $newValuesJson = $newValues ? json_encode($newValues) : null;

            $stmt->execute([
                $adminUserId,
                $action,
                $entityType,
                $entityId,
                $description,
                $oldValuesJson,
                $newValuesJson,
                $ipAddress,
                $userAgent,
                $status,
                $errorMessage
            ]);

            return (int)$this->pdo->lastInsertId();
        } catch (Exception $e) {
            error_log("AdminAuditService::logAction failed: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get admin activity logs with filtering
     */
    public function getActivityLogs(
        ?string $action = null,
        ?string $entityType = null,
        ?int $adminUserId = null,
        ?int $limit = 100,
        ?int $offset = 0,
        ?string $dateFrom = null,
        ?string $dateTo = null
    ): array {
        $query = "SELECT aal.*, u.full_name, u.email FROM admin_activity_logs aal LEFT JOIN users u ON aal.admin_user_id = u.id WHERE 1=1";
        $params = [];

        if ($action) {
            $query .= " AND aal.action = :action";
            $params[':action'] = $action;
        }
        if ($entityType) {
            $query .= " AND aal.entity_type = :entity_type";
            $params[':entity_type'] = $entityType;
        }
        if ($adminUserId) {
            $query .= " AND aal.admin_user_id = :admin_user_id";
            $params[':admin_user_id'] = $adminUserId;
        }
        if ($dateFrom) {
            $query .= " AND aal.created_at >= :date_from";
            $params[':date_from'] = $dateFrom . ' 00:00:00';
        }
        if ($dateTo) {
            $query .= " AND aal.created_at <= :date_to";
            $params[':date_to'] = $dateTo . ' 23:59:59';
        }

        $query .= " ORDER BY aal.created_at DESC";
        if ($limit) {
            $query .= " LIMIT :limit";
        }
        if ($offset) {
            $query .= " OFFSET :offset";
        }

        $stmt = $this->pdo->prepare($query);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        if ($limit) {
            $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        }
        if ($offset) {
            $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
        }
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Count total activity logs with filters
     */
    public function countActivityLogs(
        ?string $action = null,
        ?string $entityType = null,
        ?int $adminUserId = null,
        ?string $dateFrom = null,
        ?string $dateTo = null
    ): int {
        $query = "SELECT COUNT(*) FROM admin_activity_logs WHERE 1=1";
        $params = [];

        if ($action) {
            $query .= " AND action = ?";
            $params[] = $action;
        }
        if ($entityType) {
            $query .= " AND entity_type = ?";
            $params[] = $entityType;
        }
        if ($adminUserId) {
            $query .= " AND admin_user_id = ?";
            $params[] = $adminUserId;
        }
        if ($dateFrom) {
            $query .= " AND created_at >= ?";
            $params[] = $dateFrom . ' 00:00:00';
        }
        if ($dateTo) {
            $query .= " AND created_at <= ?";
            $params[] = $dateTo . ' 23:59:59';
        }

        $stmt = $this->pdo->prepare($query);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Get a specific log entry
     */
    public function getLogEntry(int $logId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT aal.*, u.full_name, u.email
            FROM admin_activity_logs aal
            LEFT JOIN users u ON aal.admin_user_id = u.id
            WHERE aal.id = ?
        ");
        $stmt->execute([$logId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Get activity summary for dashboard
     */
    public function getSummary(): array
    {
        $stmt = $this->pdo->query("
            SELECT
                COUNT(*) as total_actions,
                COUNT(CASE WHEN status = 'success' THEN 1 END) as successful_actions,
                COUNT(CASE WHEN status = 'failed' THEN 1 END) as failed_actions,
                COUNT(DISTINCT admin_user_id) as active_admins
            FROM admin_activity_logs
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Get IP address of client
     */
    private function getClientIP(): string
    {
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            return $_SERVER['HTTP_CF_CONNECTING_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
        } else {
            return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        }
    }
}
