<?php
require_once __DIR__ . "/../config/database.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAuth.php";
require_once dirname(__DIR__, 3) . "/includes/classes/OmAudit.php";

setCorsHeaders();

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    OmAudit::getInstance()->setDb($db);

    $payload = om_auth()->requireAdmin();

    $action = $_GET['action'] ?? null;
    $entity_type = $_GET['entity_type'] ?? null;
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 50)));
    $offset = ($page - 1) * $limit;

    $where = ["1=1"];
    $params = [];
    if ($action) { $where[] = "action = ?"; $params[] = $action; }
    if ($entity_type) { $where[] = "entity_type = ?"; $params[] = $entity_type; }
    $where_sql = implode(' AND ', $where);

    $stmt = $db->prepare("SELECT COUNT(*) as total FROM om_audit_log WHERE {$where_sql}");
    $stmt->execute($params);
    $total = (int)$stmt->fetch()['total'];

    $stmt = $db->prepare("
        SELECT * FROM om_audit_log
        WHERE {$where_sql}
        ORDER BY created_at DESC
        LIMIT ? OFFSET ?
    ");
    $params[] = (int)$limit;
    $params[] = (int)$offset;
    $stmt->execute($params);
    $logs = $stmt->fetchAll();

    // Decode JSON fields
    foreach ($logs as &$log) {
        if (!empty($log['old_data'])) $log['old_data'] = json_decode($log['old_data'], true);
        if (!empty($log['new_data'])) $log['new_data'] = json_decode($log['new_data'], true);
    }

    response(true, [
        'logs' => $logs,
        'pagination' => ['page' => $page, 'limit' => $limit, 'total' => $total, 'pages' => ceil($total / $limit)]
    ], "Logs de auditoria");
} catch (Exception $e) {
    error_log("[admin/logs] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
