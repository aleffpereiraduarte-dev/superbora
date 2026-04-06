<?php
/**
 * Admin Audit Log Helper
 *
 * Logs admin actions to sb_admin_audit_log for security auditing.
 * Table created by: sql/058_security_totp_audit.sql
 *
 * Usage:
 *   require_once __DIR__ . '/audit-log.php';
 *   logAdminAction($db, $adminId, 'update_order', 'order', $orderId, ['status' => 'cancelled']);
 */

/**
 * Log an admin action to the audit trail.
 *
 * @param PDO    $db           Database connection
 * @param int    $adminId      Admin user ID performing the action
 * @param string $action       Action name (e.g. 'create_product', 'update_order', 'delete_user')
 * @param string $resourceType Resource type (e.g. 'order', 'product', 'customer', 'partner')
 * @param mixed  $resourceId   Resource ID (string or int, nullable)
 * @param array  $details      Additional context as associative array (stored as JSONB)
 * @return bool  True if logged successfully
 */
function logAdminAction(PDO $db, int $adminId, string $action, string $resourceType, $resourceId = null, array $details = []): bool {
    try {
        // Sanitize inputs
        $action = substr(trim($action), 0, 100);
        $resourceType = substr(trim($resourceType), 0, 100);
        $resourceId = $resourceId !== null ? substr(trim((string)$resourceId), 0, 100) : null;

        // Capture request context
        $ipAddress = $_SERVER['HTTP_X_FORWARDED_FOR']
            ?? $_SERVER['HTTP_X_REAL_IP']
            ?? $_SERVER['REMOTE_ADDR']
            ?? null;

        // If X-Forwarded-For has multiple IPs, take the first (client IP)
        if ($ipAddress && strpos($ipAddress, ',') !== false) {
            $ipAddress = trim(explode(',', $ipAddress)[0]);
        }

        // Validate IP format
        if ($ipAddress && !filter_var($ipAddress, FILTER_VALIDATE_IP)) {
            $ipAddress = null;
        }

        $userAgent = isset($_SERVER['HTTP_USER_AGENT'])
            ? substr($_SERVER['HTTP_USER_AGENT'], 0, 512)
            : null;

        $stmt = $db->prepare("
            INSERT INTO sb_admin_audit_log
                (admin_id, action, resource_type, resource_id, details, ip_address, user_agent, created_at)
            VALUES
                (?, ?, ?, ?, ?::jsonb, ?, ?, NOW())
        ");

        $stmt->execute([
            $adminId,
            $action,
            $resourceType,
            $resourceId,
            json_encode($details, JSON_UNESCAPED_UNICODE),
            $ipAddress,
            $userAgent,
        ]);

        return true;

    } catch (Exception $e) {
        // Audit logging must never break the main operation
        error_log("[AuditLog] Failed to log action '{$action}' by admin {$adminId}: " . $e->getMessage());
        return false;
    }
}
