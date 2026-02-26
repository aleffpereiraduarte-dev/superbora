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

    $status = $_GET['status'] ?? null;
    $type = $_GET['type'] ?? null;
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = 20;
    $offset = ($page - 1) * $limit;

    // Cancelled orders — parameterized LIMIT/OFFSET, CAST AS TEXT for PostgreSQL
    $stmt = $db->prepare("
        SELECT 'cancelled_order' as problem_type,
               o.order_id as reference_id,
               CONCAT('Pedido #', CAST(o.order_id AS TEXT), ' cancelado') as title,
               'cancelled' as status,
               o.created_at, o.updated_at,
               c.firstname as customer_name,
               p.name as partner_name
        FROM om_market_orders o
        LEFT JOIN oc_customer c ON o.customer_id = c.customer_id
        LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
        WHERE o.status = 'cancelled'
        ORDER BY o.updated_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([(int)$limit, (int)$offset]);
    $cancelled = $stmt->fetchAll();

    // Open support tickets — replace FIELD() with CASE for PostgreSQL compatibility
    $stmt = $db->query("
        SELECT 'support_ticket' as problem_type,
               id as reference_id,
               assunto as title,
               prioridade AS priority, status,
               created_at, updated_at
        FROM om_support_tickets
        WHERE status IN ('open', 'in_progress')
        ORDER BY CASE prioridade
            WHEN 'urgente' THEN 1
            WHEN 'alta' THEN 2
            WHEN 'normal' THEN 3
            WHEN 'baixa' THEN 4
            ELSE 5
        END, created_at DESC
        LIMIT 20
    ");
    $tickets = $stmt->fetchAll();

    // Unresolved alerts — replace FIELD() with CASE for PostgreSQL compatibility
    $stmt = $db->query("
        SELECT 'alert' as problem_type,
               id as reference_id,
               title, severity,
               description,
               created_at
        FROM om_alerts
        WHERE resolved = 0
        ORDER BY CASE severity
            WHEN 'critical' THEN 1
            WHEN 'warning' THEN 2
            WHEN 'info' THEN 3
            ELSE 4
        END, created_at DESC
        LIMIT 20
    ");
    $alerts = $stmt->fetchAll();

    // Filter by type if requested
    if ($type === 'cancelled') {
        $problems = $cancelled;
    } elseif ($type === 'tickets') {
        $problems = $tickets;
    } elseif ($type === 'alerts') {
        $problems = $alerts;
    } else {
        $problems = array_merge($cancelled, $tickets, $alerts);
        usort($problems, function($a, $b) {
            $da = $a['created_at'] ?? $a['updated_at'] ?? '2000-01-01';
            $db_date = $b['created_at'] ?? $b['updated_at'] ?? '2000-01-01';
            return strtotime($db_date) - strtotime($da);
        });
        $problems = array_slice($problems, 0, $limit);
    }

    response(true, [
        'problems' => $problems,
        'counts' => [
            'cancelled' => count($cancelled),
            'tickets' => count($tickets),
            'alerts' => count($alerts)
        ]
    ], "Problemas listados");
} catch (Exception $e) {
    error_log("[admin/problemas] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
