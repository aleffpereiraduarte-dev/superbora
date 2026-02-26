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
    $admin_id = $payload['uid'];

    if ($_SERVER["REQUEST_METHOD"] === "GET") {
        $severity = $_GET['severity'] ?? null;
        $resolved = $_GET['resolved'] ?? null;
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = 20;
        $offset = ($page - 1) * $limit;

        $where = ["1=1"];
        $params = [];
        if ($severity) {
            $where[] = "severity = ?";
            $params[] = $severity;
        }
        if ($resolved !== null) {
            $where[] = "resolved = ?";
            $params[] = (int)$resolved;
        }
        $where_sql = implode(' AND ', $where);

        $stmt = $db->prepare("SELECT COUNT(*) as total FROM om_alerts WHERE {$where_sql}");
        $stmt->execute($params);
        $total = (int)$stmt->fetch()['total'];

        $stmt = $db->prepare("
            SELECT * FROM om_alerts
            WHERE {$where_sql}
            ORDER BY CASE severity
                WHEN 'critical' THEN 1
                WHEN 'warning' THEN 2
                WHEN 'info' THEN 3
                ELSE 4
            END, created_at DESC
            LIMIT ? OFFSET ?
        ");
        $params[] = (int)$limit;
        $params[] = (int)$offset;
        $stmt->execute($params);
        $alertas = $stmt->fetchAll();

        // Decode JSON data
        foreach ($alertas as &$a) {
            if (!empty($a['data'])) $a['data'] = json_decode($a['data'], true);
        }

        response(true, [
            'alertas' => $alertas,
            'pagination' => ['page' => $page, 'limit' => $limit, 'total' => $total, 'pages' => ceil($total / $limit)]
        ], "Alertas listados");

    } elseif ($_SERVER["REQUEST_METHOD"] === "POST") {
        $input = getInput();
        $id = (int)($input['id'] ?? 0);
        $resolved = (int)($input['resolved'] ?? 1);

        if (!$id) response(false, null, "ID obrigatorio", 400);

        $stmt = $db->prepare("UPDATE om_alerts SET resolved = ? WHERE id = ?");
        $stmt->execute([$resolved, $id]);

        om_audit()->log('update', 'alert', $id, null, ['resolved' => $resolved]);
        response(true, ['id' => $id, 'resolved' => $resolved], $resolved ? "Alerta resolvido" : "Alerta reaberto");
    } else {
        response(false, null, "Metodo nao permitido", 405);
    }
} catch (Exception $e) {
    error_log("[admin/alertas] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
