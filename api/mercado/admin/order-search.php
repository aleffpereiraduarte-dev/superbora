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

    $q = trim($_GET['q'] ?? '');
    if (strlen($q) < 1) response(false, null, "Termo de busca obrigatorio", 400);

    // Escape LIKE special characters to prevent wildcard injection
    $q_escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q);
    $search = "%{$q_escaped}%";

    $stmt = $db->prepare("
        SELECT o.order_id, o.status, o.total, o.created_at,
               c.name as customer_name,
               p.name as partner_name
        FROM om_market_orders o
        LEFT JOIN om_customers c ON o.customer_id = c.customer_id
        LEFT JOIN om_market_partners p ON o.partner_id = p.partner_id
        WHERE CAST(o.order_id AS CHAR) LIKE ?
           OR c.name LIKE ?
           OR p.name LIKE ?
        ORDER BY o.created_at DESC
        LIMIT 20
    ");
    $stmt->execute([$search, $search, $search]);
    $results = $stmt->fetchAll();

    response(true, ['results' => $results, 'query' => $q, 'count' => count($results)], "Busca realizada");
} catch (Exception $e) {
    error_log("[admin/order-search] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
