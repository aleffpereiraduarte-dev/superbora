<?php
/**
 * POST /api/mercado/admin/nl-search.php
 * Body: { "query":"clientes que reclamaram este mes" }
 *
 * Natural language admin search across customers/orders/partners/reviews.
 * Llama decides which entity types to search and returns enriched results.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/claude-client.php';
require_once dirname(__DIR__, 3) . '/includes/classes/OmAuth.php';
setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    response(false, null, 'Metodo nao permitido', 405);
}

try {
    $db = getDB();
    OmAuth::getInstance()->setDb($db);
    $token = om_auth()->getTokenFromRequest();
    if (!$token) response(false, null, 'Token ausente', 401);
    $payload = om_auth()->validateToken($token);
    if (!$payload || !in_array($payload['type'] ?? '', ['admin', 'staff'], true)) {
        response(false, null, 'Acesso restrito', 403);
    }

    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $query = trim($input['query'] ?? '');
    if ($query === '') response(false, null, 'query obrigatoria', 400);

    $prompt = "Admin do SuperBora pediu: \"{$query}\"\n\n" .
              "Decida o que buscar. Entidades: customers, partners, orders, reviews. " .
              "Para cada uma, escreva uma WHERE clause SQL valida (PostgreSQL). " .
              "Tabelas: om_customers (customer_id,name,email,phone,created_at), om_market_partners (partner_id,name,categoria,address_city,status), " .
              "om_market_orders (order_id,customer_id,partner_id,total,status,created_at), om_market_reviews (id,partner_id,customer_id,rating,comment,created_at). " .
              "Status validos: pendente,confirmado,preparando,pronto,em_entrega,entregue,cancelado,recusado. " .
              "Responda APENAS JSON: " .
              '{"intent":"resumo da intencao","entities":[{"type":"customers|partners|orders|reviews","where":"clausula sem prefixo WHERE","order_by":"col DESC","limit":20}]}';

    $reply = ClaudeClient::text($prompt, 'Voce traduz pedidos do admin em queries seguras. Apenas WHERE clauses.', 700);
    $parsed = ClaudeClient::parseJson($reply ?: '');
    if (!$parsed || empty($parsed['entities'])) response(false, null, 'sem entidades', 502);

    $tableMap = [
        'customers' => ['table' => 'om_customers', 'cols' => 'customer_id, name, email, phone, created_at'],
        'partners'  => ['table' => 'om_market_partners', 'cols' => 'partner_id, name, categoria, address_city, status'],
        'orders'    => ['table' => 'om_market_orders', 'cols' => 'order_id, customer_id, partner_id, total, status, created_at'],
        'reviews'   => ['table' => 'om_market_reviews', 'cols' => 'id, partner_id, customer_id, rating, comment, created_at'],
    ];

    $results = [];
    foreach ($parsed['entities'] as $e) {
        $type = $e['type'] ?? '';
        if (!isset($tableMap[$type])) continue;
        $where = $e['where'] ?? '1=1';
        // SAFETY: block dangerous keywords in the WHERE clause
        if (preg_match('/\b(INSERT|UPDATE|DELETE|DROP|ALTER|GRANT|TRUNCATE|CREATE|REPLACE|;|--)\b/i', $where)) {
            continue;
        }
        $orderBy = preg_match('/^[a-zA-Z0-9_,\s]+(asc|desc)?$/i', $e['order_by'] ?? '') ? $e['order_by'] : 'created_at DESC';
        $limit = max(1, min(100, (int)($e['limit'] ?? 20)));

        $sql = "SELECT {$tableMap[$type]['cols']} FROM {$tableMap[$type]['table']} WHERE {$where} ORDER BY {$orderBy} LIMIT {$limit}";
        try {
            $db->exec("SET LOCAL statement_timeout = '5s'");
            $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
            $results[$type] = $rows;
        } catch (Exception $ex) {
            $results[$type] = ['error' => $ex->getMessage()];
        }
    }

    response(true, [
        'query' => $query,
        'intent' => $parsed['intent'] ?? '',
        'results' => $results,
    ]);
} catch (Exception $e) {
    error_log('[nl-search] ' . $e->getMessage());
    response(false, null, 'erro', 500);
}
