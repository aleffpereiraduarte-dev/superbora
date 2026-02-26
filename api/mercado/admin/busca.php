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
    if (strlen($q) < 2) response(false, null, "Termo de busca muito curto (min 2 caracteres)", 400);

    // Escape LIKE special characters to prevent wildcard injection
    $q_escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q);
    $search = "%{$q_escaped}%";
    $results = [];

    // Orders
    $stmt = $db->prepare("
        SELECT order_id as id, CONCAT('Pedido #', order_id) as label, status, total, 'order' as type
        FROM om_market_orders
        WHERE CAST(order_id AS CHAR) LIKE ? OR delivery_address LIKE ?
        ORDER BY created_at DESC LIMIT 5
    ");
    $stmt->execute([$search, $search]);
    $results['orders'] = $stmt->fetchAll();

    // Customers
    $stmt = $db->prepare("
        SELECT customer_id as id, CONCAT(firstname, ' ', lastname) as label, email, 'customer' as type
        FROM oc_customer
        WHERE firstname LIKE ? OR lastname LIKE ? OR email LIKE ? OR telephone LIKE ?
        ORDER BY firstname ASC LIMIT 5
    ");
    $stmt->execute([$search, $search, $search, $search]);
    $results['customers'] = $stmt->fetchAll();

    // Shoppers
    $stmt = $db->prepare("
        SELECT shopper_id as id, name as label, email, phone, 'shopper' as type
        FROM om_market_shoppers
        WHERE name LIKE ? OR email LIKE ? OR phone LIKE ?
        ORDER BY name ASC LIMIT 5
    ");
    $stmt->execute([$search, $search, $search]);
    $results['shoppers'] = $stmt->fetchAll();

    // Partners
    $stmt = $db->prepare("
        SELECT partner_id as id, name as label, email, 'partner' as type
        FROM om_market_partners
        WHERE name LIKE ? OR email LIKE ? OR cnpj LIKE ?
        ORDER BY name ASC LIMIT 5
    ");
    $stmt->execute([$search, $search, $search]);
    $results['partners'] = $stmt->fetchAll();

    // Tickets
    $stmt = $db->prepare("
        SELECT id, assunto as label, status, prioridade as priority, 'ticket' as type
        FROM om_support_tickets
        WHERE assunto LIKE ?
        ORDER BY created_at DESC LIMIT 5
    ");
    $stmt->execute([$search]);
    $results['tickets'] = $stmt->fetchAll();

    $total_results = array_sum(array_map('count', $results));

    response(true, ['query' => $q, 'total' => $total_results, 'results' => $results], "Busca global");
} catch (Exception $e) {
    error_log("[admin/busca] Erro: " . $e->getMessage());
    response(false, null, "Erro interno", 500);
}
