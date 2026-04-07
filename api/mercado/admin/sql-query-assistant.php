<?php
/**
 * POST /api/mercado/admin/sql-query-assistant.php
 * Body: { "query":"vendas de SP nos ultimos 7 dias" }
 *
 * Translates natural language to read-only SQL, executes against PostgreSQL,
 * and returns the result. Auth: admin only.
 *
 * Safety: only SELECT statements allowed, single statement, hard timeout.
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
    $userQuery = trim($input['query'] ?? '');
    if ($userQuery === '') response(false, null, 'query obrigatoria', 400);

    // Schema hint for the AI
    $schemaHint = "Tabelas principais (PostgreSQL):\n" .
                  "- om_market_orders(order_id, customer_id, partner_id, total, status, created_at, payment_method)\n" .
                  "- om_market_partners(partner_id, name, categoria, address_city, address_state, status)\n" .
                  "- om_customers(customer_id, name, email, phone, created_at, birth_date)\n" .
                  "- om_market_products(product_id, partner_id, name, price, status)\n" .
                  "- om_market_order_items(order_id, product_id, quantity, price)\n" .
                  "- om_market_reviews(id, partner_id, customer_id, rating, comment, created_at)\n" .
                  "Status validos: pendente, confirmado, preparando, pronto, em_entrega, entregue, cancelado, recusado";

    $prompt = "Pergunta do admin: \"{$userQuery}\"\n\n{$schemaHint}\n\n" .
              "Gere UMA query SELECT (PostgreSQL). " .
              "PROIBIDO: INSERT, UPDATE, DELETE, ALTER, DROP, GRANT, qualquer DDL. " .
              "Use LIMIT 100 sempre. Responda APENAS JSON: " .
              '{"sql":"SELECT ...","explanation":"o que a query faz","columns":["col1","col2"]}';

    $reply = ClaudeClient::text($prompt, 'Voce eh especialista em SQL PostgreSQL. Apenas SELECT, sempre com LIMIT.', 600);
    $parsed = ClaudeClient::parseJson($reply ?: '');
    if (!$parsed || empty($parsed['sql'])) response(false, null, 'falha gerar SQL', 502);

    $sql = trim($parsed['sql']);
    // Hard safety
    if (!preg_match('/^\s*SELECT\b/i', $sql)) {
        response(false, null, 'apenas SELECT permitido', 400);
    }
    if (preg_match('/\b(INSERT|UPDATE|DELETE|DROP|ALTER|GRANT|TRUNCATE|CREATE|REPLACE)\b/i', $sql)) {
        response(false, null, 'comando bloqueado', 400);
    }
    if (substr_count($sql, ';') > 1) {
        response(false, null, 'apenas 1 statement', 400);
    }
    if (!preg_match('/\bLIMIT\b/i', $sql)) {
        $sql = rtrim($sql, "; \t\n") . ' LIMIT 100';
    }

    // Execute with read-only role if available
    try {
        $db->exec("SET LOCAL statement_timeout = '10s'");
        $stmt = $db->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        response(true, [
            'natural_query' => $userQuery,
            'sql' => $sql,
            'explanation' => $parsed['explanation'] ?? '',
            'rows' => $rows,
            'count' => count($rows),
        ]);
    } catch (Exception $e) {
        response(false, null, 'erro SQL: ' . $e->getMessage(), 400);
    }
} catch (Exception $e) {
    error_log('[sql-assistant] ' . $e->getMessage());
    response(false, null, 'erro', 500);
}
